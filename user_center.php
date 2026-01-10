<?php
require 'db_connect.php';
session_start();

// 检查登录状态
if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$error = '';
$success = '';

// 处理修改密码逻辑
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_password'])) {
    $old_password = $_POST['old_password'];
    $new_password = $_POST['new_password'];
    $confirm_password = $_POST['confirm_password'];
    
    if(empty($old_password) || empty($new_password) || empty($confirm_password)) {
        $error = "所有密码字段不能为空";
    } elseif($new_password !== $confirm_password) {
        $error = "两次输入的新密码不一致";
    } elseif(strlen($new_password) < 6) {
        $error = "新密码长度不能少于6个字符";
    } else {
        try {
            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if(!password_verify($old_password, $user['password'])) {
                $error = "旧密码输入错误";
            } else {
                $hashed_new_password = password_hash($new_password, PASSWORD_DEFAULT);
                $update_stmt = $pdo->prepare("UPDATE users SET password = :new_password, updated_at = NOW() WHERE id = :user_id");
                $update_stmt->bindParam(':new_password', $hashed_new_password);
                $update_stmt->bindParam(':user_id', $user_id);
                $update_stmt->execute();
                
                $success = "密码修改成功！下次登录请使用新密码";
                if(isset($_COOKIE['remember_me'])) {
                    setcookie('remember_me', '', time() - 3600, '/', '', isset($_SERVER['HTTPS']), true);
                }
            }
        } catch(PDOException $e) {
            $error = "修改密码失败: " . $e->getMessage();
        }
    }
}

// 处理图片删除（仅允许删除未审核的图片）
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_photo'])) {
    $photo_id = intval($_POST['photo_id']);
    
    try {
        $stmt = $pdo->prepare("SELECT id, filename FROM photos WHERE id = :photo_id AND user_id = :user_id AND approved = 0");
        $stmt->bindParam(':photo_id', $photo_id);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if($photo) {
            $file_path = 'uploads/' . $photo['filename'];
            if(file_exists($file_path)) {
                unlink($file_path);
            }
            
            $stmt = $pdo->prepare("DELETE FROM photos WHERE id = :photo_id");
            $stmt->bindParam(':photo_id', $photo_id);
            $stmt->execute();
            
            $success = '图片已成功删除';
        } else {
            $error = '无法删除图片（照片状态不符或不属于你）';
        }
    } catch(PDOException $e) {
        $error = "删除图片失败: " . $e->getMessage();
    }
}

// 处理申诉提交
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['appeal'])) {
    $photo_id = intval($_POST['photo_id']);
    $appeal_content = trim($_POST['appeal_content']);
    
    try {
        $stmt = $pdo->prepare("SELECT id FROM photos WHERE id = :photo_id AND user_id = :user_id AND approved = 2");
        $stmt->bindParam(':photo_id', $photo_id);
        $stmt->bindParam(':user_id', $user_id);
        $stmt->execute();
        
        if($stmt->fetch(PDO::FETCH_ASSOC)) {
            $stmt = $pdo->prepare("INSERT INTO appeals (photo_id, user_id, content, created_at, status)
                                 VALUES (:photo_id, :user_id, :content, NOW(), 0)");
            $stmt->bindParam(':photo_id', $photo_id);
            $stmt->bindParam(':user_id', $user_id);
            $stmt->bindParam(':content', $appeal_content);
            $stmt->execute();
            
            $stmt = $pdo->prepare("UPDATE photos SET approved = 3 WHERE id = :photo_id");
            $stmt->bindParam(':photo_id', $photo_id);
            $stmt->execute();
            
            $success = '申诉提交成功，等待二次审核';
        } else {
            $error = '无法提交申诉（照片状态不符或不属于你）';
        }
    } catch(PDOException $e) {
        $error = "提交申诉失败: " . $e->getMessage();
    }
}

// 获取过图率数据
$passRateData = [
    'total' => 0,
    'passed' => 0,
    'rate' => 0
];
try {
    $stmt = $pdo->prepare("SELECT approved FROM photos WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 100");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $recentPhotos = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
    
    if(!empty($recentPhotos)) {
        $passRateData['total'] = count($recentPhotos);
        $passRateData['passed'] = count(array_filter($recentPhotos, function($status) {
            return $status == 1;
        }));
        $passRateData['rate'] = round(($passRateData['passed'] / $passRateData['total']) * 100);
    }
} catch(PDOException $e) {}

// 获取所有待审核图片用于计算队列位置
$pending_photos = [];
try {
    $stmt = $pdo->query("SELECT id FROM photos WHERE approved = 0 ORDER BY created_at ASC");
    $pending_photos = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
} catch(PDOException $e) {}

// 获取用户信息 + 上传的照片（含拒绝理由、管理员留言、审核员信息） + 申诉记录（含回复、管理员留言）
try {
    // 用户信息
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :user_id");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 上传的照片（新增：关联 photos.admin_comment 管理员留言字段）
    $stmt = $pdo->prepare("SELECT p.*, p.rejection_reason, p.admin_comment, r.username as reviewer_name 
                         FROM photos p
                         LEFT JOIN users r ON p.reviewer_id = r.id
                         WHERE p.user_id = :user_id 
                         ORDER BY p.created_at DESC");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user_photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 计算每张审核中照片的前方等待数量
    foreach($user_photos as &$photo) {
        if($photo['approved'] == 0) {
            $position = array_search($photo['id'], $pending_photos);
            $photo['queue_position'] = $position !== false ? $position : 0;
        } else {
            $photo['queue_position'] = -1;
        }
    }
    unset($photo); 
    
    // 申诉记录（新增：关联 appeals.admin_comment 管理员留言字段）
    $stmt = $pdo->prepare("SELECT a.*, a.admin_comment, p.title, p.rejection_reason, r.username as reviewer_name 
                         FROM appeals a
                         JOIN photos p ON a.photo_id = p.id
                         LEFT JOIN users r ON p.reviewer_id = r.id
                         WHERE a.user_id = :user_id
                         ORDER BY a.created_at DESC");
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();
    $user_appeals = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "获取数据失败: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horizon Photos - 用户中心</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #165DFF;
            --primary-light: #4080FF;
            --primary-lighter: #E8F3FF;
            --success: #00B42A;
            --success-light: #E6FFED;
            --danger: #F53F3F;
            --danger-light: #FFECE8;
            --warning: #FF7D00;
            --warning-light: #FFF7E6;
            --info: #722ED1; /* 新增：管理员留言主题色（柔和紫色） */
            --info-light: #F9F0FF; /* 新增：管理员留言背景色 */
            --gray-100: #F2F3F5;
            --gray-200: #E5E6EB;
            --gray-300: #C9CDD4;
            --gray-500: #4E5969;
            --white: #FFFFFF;
            --radius-lg: 8px;
            --radius-full: 999px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-md: 0 4px 16px rgba(0, 0, 0, 0.1);
            --transition: all 0.25s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Roboto, sans-serif;
            background-color: #F7F8FA;
            color: #1D2129;
            line-height: 1.5;
        }

        .nav {
            background-color: var(--primary);
            padding: 14px 20px;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo {
            color: white;
            font-size: 1.2rem;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .logo i {
            font-size: 1.4rem;
        }
        .nav-links {
            display: flex;
            gap: 4px;
        }
        .nav a {
            color: white;
            text-decoration: none;
            padding: 8px 14px;
            border-radius: var(--radius-lg);
            font-size: 14px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .nav a:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
        .nav a.active {
            background-color: rgba(255, 255, 255, 0.2);
            font-weight: 500;
        }

        .user-center {
            max-width: 1400px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            margin-bottom: 30px;
        }
        .page-title {
            font-size: 1.8rem;
            color: var(--primary);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .page-title i {
            color: var(--primary-light);
        }
        .page-desc {
            color: var(--gray-500);
            font-size: 14px;
        }

        .alert {
            padding: 14px 16px;
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .alert-error {
            background-color: var(--danger-light);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        .alert-success {
            background-color: var(--success-light);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .section {
            background-color: var(--white);
            padding: 24px;
            margin-bottom: 30px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
        }
        .section:hover {
            box-shadow: var(--shadow-md);
        }
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .section h2 {
            font-size: 1.3rem;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
        }
        .section h2 i {
            color: var(--primary-light);
        }

        .profile-card {
            display: flex;
            align-items: center;
            gap: 20px;
            padding: 20px;
            background-color: var(--primary-lighter);
            border-radius: var(--radius-lg);
        }
        .profile-avatar {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            font-weight: bold;
        }
        .profile-info {
            flex: 1;
        }
        .profile-name {
            font-size: 1.4rem;
            font-weight: 600;
            margin-bottom: 8px;
        }
        .profile-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            color: var(--gray-500);
        }
        .profile-meta-item {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .pass-rate-section {
            margin-top: 16px;
            padding: 16px;
            background-color: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }
        .pass-rate-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 12px;
        }
        .pass-rate-title {
            font-weight: 600;
            color: var(--gray-500);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .pass-rate-value {
            font-size: 1.2rem;
            font-weight: 700;
            color: var(--success);
        }
        .progress-container {
            width: 100%;
            height: 10px;
            background-color: var(--gray-200);
            border-radius: var(--radius-full);
            overflow: hidden;
        }
        .progress-bar {
            height: 100%;
            background-color: var(--success);
            border-radius: var(--radius-full);
            transition: width 0.6s ease;
        }
        .pass-rate-detail {
            margin-top: 8px;
            font-size: 13px;
            color: var(--gray-500);
            text-align: right;
        }

        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 24px;
        }
        .photo-item {
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 16px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        .photo-item:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary-light);
        }
        .photo-item img {
            width: 100%;
            height: 180px;
            object-fit: cover;
            border-radius: var(--radius-lg);
            margin-bottom: 16px;
            transition: transform 0.3s ease;
        }
        .photo-item:hover img {
            transform: scale(1.03);
        }
        .photo-title {
            font-weight: 600;
            margin-bottom: 8px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .photo-category {
            color: var(--gray-500);
            font-size: 13px;
            margin-bottom: 12px;
        }

        .status {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 500;
            margin-right: 8px;
        }
        .status-pending { 
            background-color: var(--warning-light); 
            color: var(--warning); 
        }
        .status-approved { 
            background-color: var(--success-light); 
            color: var(--success); 
        }
        .status-rejected { 
            background-color: var(--danger-light); 
            color: var(--danger); 
        }
        .status-appealing { 
            background-color: var(--primary-lighter); 
            color: var(--primary); 
        }
        
        .queue-position {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background-color: var(--gray-100);
            border-radius: var(--radius-full);
            font-size: 12px;
            color: var(--gray-500);
            margin-top: 8px;
        }

        .reviewer-info {
            display: flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            background-color: var(--primary-lighter);
            border-radius: var(--radius-full);
            font-size: 12px;
            color: var(--primary);
            margin-top: 8px;
        }

        /* ------------- 优化1：统一内容区域样式（拒绝理由/申诉回复/管理员留言）------------- */
        .content-section {
            margin-top: 12px;
            padding: 16px;
            border-radius: var(--radius-lg);
            font-size: 14px;
            animation: slideDown 0.3s ease;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05); /* 新增：轻微阴影增强层次感 */
        }
        @keyframes slideDown {
            from { opacity: 0; max-height: 0; padding: 0; }
            to { opacity: 1; max-height: 500px; }
        }
        /* 拒绝理由样式 */
        .reason-section {
            background-color: var(--danger-light);
            border-left: 4px solid var(--danger);
        }
        /* 申诉回复样式 */
        .response-section {
            background-color: var(--primary-lighter);
            border-left: 4px solid var(--primary);
        }
        /* ------------- 新增2：管理员留言样式（柔和紫色主题，区分其他内容）------------- */
        .admin-comment-section {
            background-color: var(--info-light); /* 浅紫背景，柔和不刺眼 */
            border-left: 4px solid var(--info); /* 深紫边框，明确识别 */
            margin-top: 8px; /* 与上一个内容区保持小间距，避免拥挤 */
        }
        /* 统一标题样式：图标+文字，增强视觉引导 */
        .content-section h4 {
            font-size: 14px;
            margin-bottom: 8px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        /* 给不同内容区标题加专属图标 */
        .reason-section h4 i { color: var(--danger); }
        .response-section h4 i { color: var(--primary); }
        .admin-comment-section h4 i { color: var(--info); }
        /* 内容文本样式：增加行高，提升可读性 */
        .content-section .content-text {
            line-height: 1.7;
            color: #333;
            white-space: pre-wrap; /* 支持换行符，保留格式 */
        }

        textarea, input[type="password"] {
            width: 100%;
            min-height: 44px;
            padding: 12px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            font-family: inherit;
            font-size: 14px;
            resize: vertical;
            transition: var(--transition);
        }
        textarea:focus, input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(22, 93, 255, 0.1);
        }
        .btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: var(--transition);
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn:hover {
            background-color: var(--primary-light);
            transform: translateY(-2px);
            box-shadow: 0 2px 8px rgba(22, 93, 255, 0.3);
        }
        .btn-outline {
            background-color: transparent;
            color: var(--primary);
            border: 1px solid var(--primary);
        }
        .btn-outline:hover {
            background-color: var(--primary-lighter);
        }
        .btn-danger {
            background-color: var(--danger);
        }
        .btn-danger:hover {
            background-color: #e03636;
            box-shadow: 0 2px 8px rgba(245, 63, 63, 0.3);
        }

        .action-buttons {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            align-items: center;
        }

        .table-container {
            overflow-x: auto;
            border-radius: var(--radius-lg);
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        }
        .table {
            width: 100%;
            border-collapse: collapse;
            min-width: 600px;
        }
        .table th, .table td {
            padding: 14px 16px;
            text-align: left;
            border-bottom: 1px solid var(--gray-200);
            font-size: 14px;
        }
        .table th {
            background-color: var(--gray-100);
            color: var(--gray-500);
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        .table tr:hover {
            background-color: rgba(22, 93, 255, 0.02);
        }
        .table tr:last-child td {
            border-bottom: none;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--gray-400);
            background-color: var(--gray-100);
            border-radius: var(--radius-lg);
        }
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 16px;
            color: var(--gray-300);
        }
        .empty-state p {
            max-width: 400px;
            margin: 0 auto;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            opacity: 0;
            visibility: hidden;
            transition: all 0.3s ease;
        }
        .modal-overlay.active {
            opacity: 1;
            visibility: visible;
        }
        .modal {
            background-color: var(--white);
            border-radius: var(--radius-lg);
            width: 90%;
            max-width: 500px;
            padding: 24px;
            transform: translateY(20px);
            transition: all 0.3s ease;
        }
        .modal-overlay.active .modal {
            transform: translateY(0);
        }
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        .modal-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--primary);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-close {
            background: none;
            border: none;
            font-size: 1.2rem;
            cursor: pointer;
            color: var(--gray-500);
            transition: color 0.2s ease;
        }
        .modal-close:hover {
            color: var(--danger);
        }
        .modal-body {
            margin-bottom: 24px;
            line-height: 1.6;
        }
        .modal-footer {
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .password-form-group {
            margin-bottom: 16px;
        }
        .password-label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--gray-500);
            font-size: 14px;
        }
        .password-hint {
            margin-top: 4px;
            font-size: 12px;
            color: var(--gray-500);
        }
        .password-error {
            margin-top: 4px;
            font-size: 12px;
            color: var(--danger);
            display: none;
        }
        .password-error.active {
            display: block;
        }

        @media (max-width: 768px) {
            .photo-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            }
            .profile-card {
                flex-direction: column;
                text-align: center;
            }
            .profile-meta {
                justify-content: center;
            }
            .nav-links {
                gap: 2px;
            }
            .nav a {
                padding: 6px 10px;
                font-size: 13px;
            }
            .nav a span:nth-child(2) {
                display: none;
            }
            .nav a i {
                margin-right: 0;
            }
            .action-buttons {
                flex-direction: column;
                align-items: stretch;
            }
            .btn {
                width: 100%;
                justify-content: center;
            }
            /* 移动端内容区适配：减少内边距，避免溢出 */
            .content-section {
                padding: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="nav">
        <div class="nav-container">
            <a href="index.php" class="logo">
                <i class="fas fa-plane"></i>
                <span>Horizon Photos</span>
            </a>
            <div class="nav-links">
                <a href="index.php">
                    <i class="fas fa-home"></i>
                    <span>首页</span>
                </a>
                <a href="all_photos.php">
                    <i class="fas fa-images"></i>
                    <span>全部图片</span>
                </a>
                <a href="user_center.php" class="active">
                    <i class="fas fa-user"></i>
                    <span>用户中心</span>
                </a>
                <a href="upload.php">
                    <i class="fas fa-upload"></i>
                    <span>上传图片</span>
                </a>
                <?php if($_SESSION['is_admin']): ?>
                    <a href="admin_review.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>管理后台</span>
                    </a>
                <?php endif; ?>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>退出</span>
                </a>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title" style="color: var(--danger);">
                    <i class="fas fa-exclamation-triangle"></i>
                    确认删除
                </h3>
                <button class="modal-close" id="closeModal">&times;</button>
            </div>
            <div class="modal-body">
                <p>您确定要删除这张图片吗？</p>
                <p style="margin-top: 10px; color: var(--gray-500);">此操作不可逆，删除后将无法恢复。只有未审核的图片可以删除。</p>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline" id="cancelDelete">取消</button>
                <form method="post" action="user_center.php" id="deleteForm">
                    <input type="hidden" name="photo_id" id="deletePhotoId" value="">
                    <button type="submit" name="delete_photo" class="btn btn-danger">
                        <i class="fas fa-trash"></i> 确认删除
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="modal-overlay" id="changePasswordModal">
        <div class="modal">
            <div class="modal-header">
                <h3 class="modal-title">
                    <i class="fas fa-key"></i>
                    修改密码
                </h3>
                <button class="modal-close" id="closePasswordModal">&times;</button>
            </div>
            <div class="modal-body">
                <form method="post" action="user_center.php" id="changePasswordForm">
                    <div class="password-form-group">
                        <label class="password-label" for="old_password">旧密码</label>
                        <input type="password" id="old_password" name="old_password" required>
                        <div class="password-error" id="oldPasswordError">请输入正确的旧密码</div>
                    </div>
                    
                    <div class="password-form-group">
                        <label class="password-label" for="new_password">新密码</label>
                        <input type="password" id="new_password" name="new_password" required>
                        <div class="password-hint">密码长度至少6个字符，建议包含字母和数字</div>
                        <div class="password-error" id="newPasswordError">新密码长度不能少于6个字符</div>
                    </div>
                    
                    <div class="password-form-group">
                        <label class="password-label" for="confirm_password">确认新密码</label>
                        <input type="password" id="confirm_password" name="confirm_password" required>
                        <div class="password-error" id="confirmPasswordError">两次输入的密码不一致</div>
                    </div>
                    
                    <div class="modal-footer" style="margin-top: 8px; margin-bottom: 0;">
                        <button type="button" class="btn btn-outline" id="cancelChangePassword">取消</button>
                        <button type="submit" name="change_password" class="btn">
                            <i class="fas fa-save"></i> 保存修改
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="user-center">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-user-circle"></i>
                用户中心
            </h1>
            <p class="page-desc">管理您的上传内容和申诉记录</p>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-id-card"></i>
                    个人信息
                </h2>
                <button class="btn" id="openChangePasswordModal">
                    <i class="fas fa-key"></i> 修改密码
                </button>
            </div>
            <div class="profile-card">
                <div class="profile-avatar">
                    <?php echo strtoupper(substr($user['username'], 0, 1)); ?>
                </div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($user['username']); ?></div>
                    <div class="profile-meta">
                        <div class="profile-meta-item">
                            <i class="fas fa-envelope"></i>
                            <span><?php echo htmlspecialchars($user['email']); ?></span>
                        </div>
                        <div class="profile-meta-item">
                            <i class="fas fa-image"></i>
                            <span>上传总数: <?php echo count($user_photos); ?></span>
                        </div>
                        <div class="profile-meta-item">
                            <i class="fas fa-check-circle"></i>
                            <span>已通过: <?php echo count(array_filter($user_photos, function($p) { return $p['approved'] == 1; })); ?></span>
                        </div>
                        <div class="profile-meta-item">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>申诉中: <?php echo count(array_filter($user_photos, function($p) { return $p['approved'] == 3; })); ?></span>
                        </div>
                    </div>

                    <div class="pass-rate-section">
                        <div class="pass-rate-header">
                            <div class="pass-rate-title">
                                <i class="fas fa-chart-line"></i>
                                最近100张图片过图率
                            </div>
                            <div class="pass-rate-value"><?php echo $passRateData['rate']; ?>%</div>
                        </div>
                        <div class="progress-container">
                            <div class="progress-bar" style="width: <?php echo $passRateData['rate']; ?>%"></div>
                        </div>
                        <div class="pass-rate-detail">
                            <?php if($passRateData['total'] > 0): ?>
                                最近<?php echo $passRateData['total']; ?>张图片中，有<?php echo $passRateData['passed']; ?>张通过审核
                            <?php else: ?>
                                暂无上传记录，上传图片后将显示过图率
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-upload"></i>
                    我的上传
                </h2>
                <span class="section-count"><?php echo count($user_photos); ?> 张图片</span>
            </div>
            
            <?php if(empty($user_photos)): ?>
                <div class="empty-state">
                    <i class="fas fa-cloud-upload-alt"></i>
                    <p>您还没有上传任何图片</p>
                    <a href="upload.php" class="btn" style="margin-top: 16px;">
                        <i class="fas fa-plus"></i> 上传第一张图片
                    </a>
                </div>
            <?php else: ?>
                <div class="photo-grid">
                    <?php foreach($user_photos as $photo): ?>
                        <div class="photo-item">
                            <img src="uploads/<?php echo htmlspecialchars($photo['filename']); ?>" 
                                 alt="<?php echo htmlspecialchars($photo['title']); ?>">
                            <h3 class="photo-title"><?php echo htmlspecialchars($photo['title']); ?></h3>
                            <div class="photo-category"><?php echo htmlspecialchars($photo['category']); ?></div>
                            <div>
                                <?php if($photo['approved'] == 0): ?>
                                    <span class="status status-pending">
                                        <i class="fas fa-clock"></i> 审核中
                                    </span>
                                    
                                    <?php if($photo['queue_position'] >= 0): ?>
                                        <div class="queue-position">
                                            <i class="fas fa-people-arrows"></i>
                                            前方还有 <?php echo $photo['queue_position']; ?> 张待审核
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php if(!empty($photo['reviewer_name'])): ?>
                                        <div class="reviewer-info">
                                            <i class="fas fa-user-check"></i>
                                            审核员: <?php echo htmlspecialchars($photo['reviewer_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="action-buttons">
                                        <button type="button" class="btn btn-danger delete-btn" 
                                                data-photo-id="<?php echo $photo['id']; ?>">
                                            <i class="fas fa-trash"></i> 删除图片
                                        </button>
                                    </div>
                                    
                                <?php elseif($photo['approved'] == 1): ?>
                                    <span class="status status-approved">
                                        <i class="fas fa-check"></i> 已通过
                                    </span>
                                    
                                    <?php if(!empty($photo['reviewer_name'])): ?>
                                        <div class="reviewer-info">
                                            <i class="fas fa-user-check"></i>
                                            审核员: <?php echo htmlspecialchars($photo['reviewer_name']); ?>
                                        </div>
                                    <?php endif; ?>

                                    <!-- ------------- 新增3：已通过图片的管理员留言（若有）------------- -->
                                    <?php if(!empty(trim($photo['admin_comment']))): ?>
                                        <div class="content-section admin-comment-section">
                                            <h4>
                                                <i class="fas fa-comment-dots"></i> 管理员留言
                                            </h4>
                                            <div class="content-text">
                                                <?php echo htmlspecialchars($photo['admin_comment']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="action-buttons">
                                        <a href="photo_detail.php?id=<?php echo $photo['id']; ?>" class="btn btn-outline">
                                            <i class="fas fa-eye"></i> 查看详情
                                        </a>
                                    </div>
                                    
                                <?php elseif($photo['approved'] == 2): ?>
                                    <span class="status status-rejected">
                                        <i class="fas fa-times"></i> 已拒绝
                                    </span>
                                    
                                    <?php if(!empty($photo['reviewer_name'])): ?>
                                        <div class="reviewer-info">
                                            <i class="fas fa-user-check"></i>
                                            审核员: <?php echo htmlspecialchars($photo['reviewer_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- 优化4：拒绝理由区域（使用统一content-section样式） -->
                                    <?php if(!empty($photo['rejection_reason'])): ?>
                                        <div class="content-section reason-section">
                                            <h4>
                                                <i class="fas fa-exclamation-circle"></i> 拒绝理由
                                            </h4>
                                            <div class="content-text">
                                                <?php echo htmlspecialchars($photo['rejection_reason']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- 新增5：拒绝图片的管理员留言（若有） -->
                                    <?php if(!empty(trim($photo['admin_comment']))): ?>
                                        <div class="content-section admin-comment-section">
                                            <h4>
                                                <i class="fas fa-comment-dots"></i> 管理员留言
                                            </h4>
                                            <div class="content-text">
                                                <?php echo htmlspecialchars($photo['admin_comment']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <?php 
                                    $has_appeal = false;
                                    foreach($user_appeals as $appeal) {
                                        if($appeal['photo_id'] == $photo['id']) {
                                            $has_appeal = true;
                                            break;
                                        }
                                    }
                                    if(!$has_appeal): ?>
                                        <div class="appeal-form" style="margin-top:12px;">
                                            <form method="post" action="user_center.php">
                                                <input type="hidden" name="photo_id" value="<?php echo $photo['id']; ?>">
                                                <textarea name="appeal_content" placeholder="请输入申诉理由..." required></textarea>
                                                <div class="action-buttons">
                                                    <button type="submit" name="appeal" class="btn">
                                                        <i class="fas fa-balance-scale"></i> 提交申诉
                                                    </button>
                                                </div>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                    
                                <?php elseif($photo['approved'] == 3): ?>
                                    <span class="status status-appealing">
                                        <i class="fas fa-redo"></i> 申诉处理中
                                    </span>
                                    
                                    <?php if(!empty($photo['reviewer_name'])): ?>
                                        <div class="reviewer-info">
                                            <i class="fas fa-user-check"></i>
                                            原审核员: <?php echo htmlspecialchars($photo['reviewer_name']); ?>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- 优化6：申诉中图片的拒绝理由（若有） -->
                                    <?php if(!empty($photo['rejection_reason'])): ?>
                                        <div class="content-section reason-section">
                                            <h4>
                                                <i class="fas fa-exclamation-circle"></i> 原拒绝理由
                                            </h4>
                                            <div class="content-text">
                                                <?php echo htmlspecialchars($photo['rejection_reason']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- 新增7：申诉中图片的管理员留言（若有） -->
                                    <?php if(!empty(trim($photo['admin_comment']))): ?>
                                        <div class="content-section admin-comment-section">
                                            <h4>
                                                <i class="fas fa-comment-dots"></i> 管理员留言
                                            </h4>
                                            <div class="content-text">
                                                <?php echo htmlspecialchars($photo['admin_comment']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="section">
            <div class="section-header">
                <h2>
                    <i class="fas fa-balance-scale"></i>
                    我的申诉
                </h2>
            </div>
            
            <?php if(empty($user_appeals)): ?>
                <div class="empty-state">
                    <i class="fas fa-gavel"></i>
                    <p>您还没有提交任何申诉</p>
                    <p style="margin-top: 8px;">当您的图片被拒绝时，可以在这里提交申诉</p>
                </div>
            <?php else: ?>
                <div class="table-container">
                    <table class="table">
                        <tr>
                            <th>图片标题</th>
                            <th>申诉内容</th>
                            <th>提交时间</th>
                            <th>审核员</th>
                            <th>状态</th>
                            <th>处理结果</th>
                        </tr>
                        <?php foreach($user_appeals as $appeal): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($appeal['title']); ?></td>
                                <td><?php echo htmlspecialchars($appeal['content']); ?></td>
                                <td><?php echo $appeal['created_at']; ?></td>
                                <td>
                                    <?php echo !empty($appeal['reviewer_name']) ? 
                                          htmlspecialchars($appeal['reviewer_name']) : '待分配'; ?>
                                </td>
                                <td>
                                    <?php if($appeal['status'] == 0): ?>
                                        <span class="status status-pending">
                                            <i class="fas fa-clock"></i> 处理中
                                        </span>
                                    <?php elseif($appeal['status'] == 1): ?>
                                        <span class="status status-approved">
                                            <i class="fas fa-check"></i> 已通过
                                        </span>
                                    <?php else: ?>
                                        <span class="status status-rejected">
                                            <i class="fas fa-times"></i> 维持原决定
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <!-- 优化8：申诉回复区域（使用统一content-section样式） -->
                                    <?php if(!empty($appeal['response'])): ?>
                                        <div class="content-section response-section">
                                            <h4>
                                                <i class="fas fa-reply"></i> 管理员回复
                                            </h4>
                                            <div class="content-text">
                                                <?php echo htmlspecialchars($appeal['response']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <!-- 新增9：申诉处理的管理员留言（若有） -->
                                    <?php if(!empty(trim($appeal['admin_comment']))): ?>
                                        <div class="content-section admin-comment-section">
                                            <h4>
                                                <i class="fas fa-comment-dots"></i> 管理员留言
                                            </h4>
                                            <div class="content-text">
                                                <?php echo htmlspecialchars($appeal['admin_comment']); ?>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if(empty($appeal['response'])): ?>
                                        <span style="color: var(--gray-400); font-size: 13px;">暂无回复</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // 删除确认弹窗控制
        const deleteModal = document.getElementById('deleteModal');
        const deleteForm = document.getElementById('deleteForm');
        const deletePhotoId = document.getElementById('deletePhotoId');
        const closeModal = document.getElementById('closeModal');
        const cancelDelete = document.getElementById('cancelDelete');
        const deleteButtons = document.querySelectorAll('.delete-btn');
        
        deleteButtons.forEach(button => {
            button.addEventListener('click', function() {
                const photoId = this.getAttribute('data-photo-id');
                deletePhotoId.value = photoId;
                deleteModal.classList.add('active');
                document.body.style.overflow = 'hidden';
            });
        });
        
        function closeDeleteModal() {
            deleteModal.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        closeModal.addEventListener('click', closeDeleteModal);
        cancelDelete.addEventListener('click', closeDeleteModal);
        
        deleteModal.addEventListener('click', function(e) {
            if(e.target === this) {
                closeDeleteModal();
            }
        });

        // 修改密码弹窗控制
        const changePasswordModal = document.getElementById('changePasswordModal');
        const openChangePasswordModal = document.getElementById('openChangePasswordModal');
        const closePasswordModal = document.getElementById('closePasswordModal');
        const cancelChangePassword = document.getElementById('cancelChangePassword');
        const changePasswordForm = document.getElementById('changePasswordForm');
        
        openChangePasswordModal.addEventListener('click', function() {
            changePasswordModal.classList.add('active');
            document.body.style.overflow = 'hidden';
            changePasswordForm.reset();
            document.querySelectorAll('.password-error').forEach(el => el.classList.remove('active'));
        });
        
        function closePasswordModalFunc() {
            changePasswordModal.classList.remove('active');
            document.body.style.overflow = '';
        }
        
        closePasswordModal.addEventListener('click', closePasswordModalFunc);
        cancelChangePassword.addEventListener('click', closePasswordModalFunc);
        
        changePasswordModal.addEventListener('click', function(e) {
            if(e.target === this) {
                closePasswordModalFunc();
            }
        });

        // 密码表单前端校验
        const oldPassword = document.getElementById('old_password');
        const newPassword = document.getElementById('new_password');
        const confirmPassword = document.getElementById('confirm_password');
        const oldPasswordError = document.getElementById('oldPasswordError');
        const newPasswordError = document.getElementById('newPasswordError');
        const confirmPasswordError = document.getElementById('confirmPasswordError');
        
        newPassword.addEventListener('input', function() {
            if(this.value.length > 0 && this.value.length < 6) {
                newPasswordError.classList.add('active');
            } else {
                newPasswordError.classList.remove('active');
            }
        });
        
        confirmPassword.addEventListener('input', function() {
            if(this.value !== newPassword.value) {
                confirmPasswordError.classList.add('active');
            } else {
                confirmPasswordError.classList.remove('active');
            }
        });
        
        changePasswordForm.addEventListener('submit', function(e) {
            let hasError = false;
            
            if(newPassword.value.length < 6) {
                newPasswordError.classList.add('active');
                hasError = true;
            }
            
            if(confirmPassword.value !== newPassword.value) {
                confirmPasswordError.classList.add('active');
                hasError = true;
            }
            
            if(hasError) {
                e.preventDefault();
                const firstError = document.querySelector('.password-error.active');
                if(firstError) {
                    firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }
        });
        
        // 成功消息自动消失
        setTimeout(() => {
            const successAlert = document.querySelector('.alert-success');
            if(successAlert) {
                successAlert.style.opacity = '0';
                successAlert.style.transform = 'translateY(-10px)';
                successAlert.style.transition = 'all 0.5s ease';
                setTimeout(() => successAlert.remove(), 500);
            }
        }, 5000);

        // 过图率进度条动画
        document.addEventListener('DOMContentLoaded', function() {
            const progressBar = document.querySelector('.progress-bar');
            if(progressBar) {
                const width = progressBar.style.width;
                progressBar.style.width = '0';
                setTimeout(() => {
                    progressBar.style.width = width;
                }, 300);
            }
        });
    </script>
</body>
</html>