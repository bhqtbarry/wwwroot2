<?php
require 'db_connect.php';
session_start();

// 检查是否登录且是管理员
if(!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: login.php');
    exit;
}

// 初始化变量
$error = '';
$success = '';
$current_page = 1;
$photos_per_page = 12; // 每页显示12张图片
$selected_status = '';
$selected_category = '';
$selected_featured = '';
$status_options = [
    '' => '全部状态',
    '0' => '待审核',
    '1' => '已通过',
    '2' => '已拒绝',
    '3' => '申诉中'
];

// 处理筛选条件
if(isset($_GET['status']) && in_array($_GET['status'], array_keys($status_options))) {
    $selected_status = $_GET['status'];
}
if(isset($_GET['category'])) {
    $selected_category = trim($_GET['category']);
}
if(isset($_GET['featured']) && in_array($_GET['featured'], ['0','1'])) {
    $selected_featured = $_GET['featured'];
}

// 处理分页
if(isset($_GET['page']) && is_numeric($_GET['page']) && $_GET['page'] > 0) {
    $current_page = intval($_GET['page']);
}

// 处理图片操作（核心修复部分）
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 验证图片ID
    if(!isset($_POST['photo_id']) || intval($_POST['photo_id']) <= 0) {
        $error = "无效的图片ID";
    } else {
        $photo_id = intval($_POST['photo_id']);
        $redirect_url = 'admin_allphotos.php' . (empty($_GET) ? '' : '?' . http_build_query($_GET));
        
        // 重新通过操作
        if(isset($_POST['approve'])) {
            try {
                $stmt = $pdo->prepare("UPDATE photos SET approved = 1, rejection_reason = NULL WHERE id = :id");
                $stmt->bindParam(':id', $photo_id, PDO::PARAM_INT); // 明确整数类型
                $stmt->execute();
                
                // 操作成功后重定向，避免表单重复提交
                $_SESSION['success_msg'] = '图片已重新设为通过状态';
                header('Location: ' . $redirect_url);
                exit;
            } catch(PDOException $e) {
                $error = "重新通过失败: " . $e->getMessage();
            }
        }
        
        // 重新拒绝操作
        elseif(isset($_POST['reject'])) {
            $reason = trim($_POST['reason'] ?? '');
            if(empty($reason)) {
                $error = "拒绝图片必须填写理由";
            } else {
                try {
                    $stmt = $pdo->prepare("UPDATE photos SET approved = 2, rejection_reason = :reason WHERE id = :id");
                    $stmt->bindParam(':reason', $reason);
                    $stmt->bindParam(':id', $photo_id, PDO::PARAM_INT);
                    $stmt->execute();
                    
                    $_SESSION['success_msg'] = '图片已重新拒绝并更新理由';
                    header('Location: ' . $redirect_url);
                    exit;
                } catch(PDOException $e) {
                    $error = "重新拒绝失败: " . $e->getMessage();
                }
            }
        }
        
        // 删除照片操作
        elseif(isset($_POST['delete_photo'])) {
            try {
                // 先查询照片信息
                $stmt = $pdo->prepare("SELECT filename FROM photos WHERE id = :id");
                $stmt->bindParam(':id', $photo_id, PDO::PARAM_INT);
                $stmt->execute();
                $photo = $stmt->fetch(PDO::FETCH_ASSOC);

                if(!$photo) {
                    $error = "图片不存在";
                } else {
                    // 检查文件是否存在
                    $file_path = "uploads/" . $photo['filename'];
                    $file_exists = file_exists($file_path);
                    
                    // 先删除数据库记录（避免文件删除失败但记录已删除）
                    $delete_stmt = $pdo->prepare("DELETE FROM photos WHERE id = :id");
                    $delete_stmt->bindParam(':id', $photo_id, PDO::PARAM_INT);
                    $delete_stmt->execute();

                    // 删除关联的申诉记录
                    $delete_appeal = $pdo->prepare("DELETE FROM appeals WHERE photo_id = :id");
                    $delete_appeal->bindParam(':id', $photo_id, PDO::PARAM_INT);
                    $delete_appeal->execute();

                    // 删除服务器上的图片文件
                    if($file_exists && !unlink($file_path)) {
                        throw new Exception("文件删除失败，请手动检查uploads目录权限");
                    }

                    $_SESSION['success_msg'] = '图片已永久删除';
                    header('Location: ' . $redirect_url);
                    exit;
                }
            } catch(PDOException $e) {
                $error = "数据库操作失败: " . $e->getMessage();
            } catch(Exception $e) {
                $error = $e->getMessage();
            }
        }
        
        // 设置为精选
        elseif(isset($_POST['set_featured'])) {
            try {
                $stmt = $pdo->prepare("UPDATE photos SET is_featured = 1 WHERE id = :id");
                $stmt->bindParam(':id', $photo_id, PDO::PARAM_INT);
                $stmt->execute();
                
                $_SESSION['success_msg'] = '图片已设为精选';
                header('Location: ' . $redirect_url);
                exit;
            } catch(PDOException $e) {
                $error = "设置精选失败: " . $e->getMessage();
            }
        }
        
        // 取消精选
        elseif(isset($_POST['unset_featured'])) {
            try {
                $stmt = $pdo->prepare("UPDATE photos SET is_featured = 0 WHERE id = :id");
                $stmt->bindParam(':id', $photo_id, PDO::PARAM_INT);
                $stmt->execute();
                
                $_SESSION['success_msg'] = '已取消图片精选状态';
                header('Location: ' . $redirect_url);
                exit;
            } catch(PDOException $e) {
                $error = "取消精选失败: " . $e->getMessage();
            }
        }
    }
}

// 从session获取重定向带来的消息
if(isset($_SESSION['success_msg'])) {
    $success = $_SESSION['success_msg'];
    unset($_SESSION['success_msg']); // 显示后清除
}

// 构建查询条件
$where_clause = [];
$params = [];

if(!empty($selected_status)) {
    $where_clause[] = "p.approved = :status";
    $params[':status'] = $selected_status;
}
if(!empty($selected_category)) {
    $where_clause[] = "p.category = :category";
    $params[':category'] = $selected_category;
}
if($selected_featured !== '') {
    $where_clause[] = "p.is_featured = :featured";
    $params[':featured'] = $selected_featured;
}

$where_sql = empty($where_clause) ? "" : "WHERE " . implode(" AND ", $where_clause);

// 获取图片数据
try {
    // 获取总数
    $total_photos_stmt = $pdo->prepare("SELECT COUNT(*) as total FROM photos p JOIN users u ON p.user_id = u.id " . $where_sql);
    $total_photos_stmt->execute($params);
    $total_photos = $total_photos_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_photos / $photos_per_page));
    
    // 调整当前页
    if($current_page > $total_pages) {
        $current_page = $total_pages;
    }
    
    // 计算偏移量
    $offset = ($current_page - 1) * $photos_per_page;
    
    // 获取当前页图片
    $stmt = $pdo->prepare("SELECT p.*, u.username 
                       FROM photos p 
                       JOIN users u ON p.user_id = u.id 
                       " . $where_sql . "
                       ORDER BY p.created_at DESC 
                       LIMIT :limit OFFSET :offset");
    
    // 绑定条件参数
    foreach($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    // 绑定分页参数（整数类型）
    $stmt->bindValue(':limit', $photos_per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "获取图片失败: " . $e->getMessage();
    $photos = [];
    $total_pages = 1;
}

// 获取所有分类
$categories = [];
try {
    $cat_stmt = $pdo->query("SELECT DISTINCT category FROM photos ORDER BY category");
    $categories = $cat_stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    $error .= "<br>获取分类失败: " . $e->getMessage();
}

// 状态标签生成函数
function getStatusBadge($status, $is_featured) {
    $badges = [];
    
    switch($status) {
        case 0:
            $badges[] = '<span class="badge badge-pending">待审核</span>';
            break;
        case 1:
            $badges[] = '<span class="badge badge-approved">已通过</span>';
            break;
        case 2:
            $badges[] = '<span class="badge badge-rejected">已拒绝</span>';
            break;
        case 3:
            $badges[] = '<span class="badge badge-appealing">申诉中</span>';
            break;
        default:
            $badges[] = '<span class="badge badge-neutral">未知</span>';
    }
    
    if($is_featured == 1) {
        $badges[] = '<span class="badge badge-featured"><i class="fas fa-star"></i> 精选</span>';
    }
    
    return implode(' ', $badges);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horizon Photos - 管理员图片管理</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #165DFF;
            --primary-light: #4080FF;
            --primary-dark: #0E42D2;
            --success: #00B42A;
            --danger: #F53F3F;
            --warning: #FF7D00;
            --appeal: #722ED1;
            --featured: #FF007A;
            --gray-50: #F7F8FA;
            --gray-100: #F2F3F5;
            --gray-200: #E5E6EB;
            --gray-400: #86909C;
            --gray-500: #4E5969;
            --gray-600: #272E3B;
            --white: #FFFFFF;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.08);
            --radius-lg: 8px;
            --radius-full: 999px;
            --transition: all 0.25s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; 
            background-color: var(--gray-50); 
            color: var(--gray-600);
            line-height: 1.5;
            min-height: 100vh;
        }
        
        .container {
            max-width: 1440px;
            margin: 0 auto;
            padding: 0 24px;
        }
        
        .nav { 
            background-color: var(--primary); 
            padding: 16px 0;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        
        .nav-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            color: white;
            font-size: 1.5rem;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .nav-links {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
        }
        
        .nav a { 
            color: rgba(255, 255, 255, 0.9); 
            text-decoration: none; 
            padding: 8px 16px;
            border-radius: 4px;
            transition: var(--transition);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav a.active {
            background-color: rgba(255, 255, 255, 0.2);
            color: white;
            font-weight: 600;
        }
        
        .page-header {
            padding: 36px 0 24px;
            border-bottom: 1px solid var(--gray-200);
            margin-bottom: 28px;
        }
        
        .page-title {
            font-size: 1.9rem;
            color: var(--primary-dark);
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius-lg);
            margin-bottom: 28px;
            font-weight: 500;
            font-size: 15px;
            display: flex;
            align-items: center;
            gap: 12px;
            animation: fadeIn 0.3s ease forwards;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(-8px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .alert-error {
            background-color: rgba(245, 63, 63, 0.08);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        
        .alert-success {
            background-color: rgba(0, 180, 42, 0.08);
            color: var(--success);
            border-left: 4px solid var(--success);
        }
        
        .filters {
            display: flex;
            flex-wrap: wrap;
            gap: 24px;
            margin-bottom: 32px;
            padding: 26px;
            background-color: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 8px;
            flex: 1;
            min-width: 220px;
        }
        
        .filter-label {
            font-size: 14px;
            color: var(--gray-500);
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .filter-options {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .filter-btn {
            background-color: var(--white);
            border: 1px solid var(--gray-200);
            padding: 9px 20px;
            border-radius: var(--radius-full);
            cursor: pointer;
            transition: var(--transition);
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 500;
        }
        
        .filter-btn.active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
            box-shadow: 0 2px 8px rgba(22, 93, 255, 0.2);
        }
        
        .photo-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 28px;
            margin-bottom: 48px;
        }
        
        .photo-item {
            background-color: var(--white);
            border-radius: var(--radius-lg);
            overflow: hidden;
            box-shadow: var(--shadow-sm);
            transition: var(--transition);
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--gray-200);
        }
        
        .photo-item:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-hover);
            border-color: var(--primary-light);
        }
        
        .photo-status {
            position: absolute;
            top: 14px;
            right: 14px;
            z-index: 10;
            display: flex;
            gap: 8px;
        }
        
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 12px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 600;
            backdrop-filter: blur(4px);
        }
        
        .badge-pending {
            color: var(--warning);
            background-color: rgba(255, 125, 0, 0.15);
        }
        
        .badge-approved {
            color: var(--success);
            background-color: rgba(0, 180, 42, 0.15);
        }
        
        .badge-rejected {
            color: var(--danger);
            background-color: rgba(245, 63, 63, 0.15);
        }
        
        .badge-appealing {
            color: var(--appeal);
            background-color: rgba(114, 46, 209, 0.15);
        }
        
        .badge-featured {
            color: var(--featured);
            background-color: rgba(255, 0, 122, 0.15);
        }
        
        .photo-img-container {
            height: 210px;
            overflow: hidden;
            position: relative;
            background-color: var(--gray-100);
        }
        
        .photo-img-container::before {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='24' height='24' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='3' width='18' height='18' rx='2' ry='2'%3E%3C/rect%3E%3Ccircle cx='8.5' cy='8.5' r='1.5'%3E%3C/circle%3E%3Cpolyline points='21 15 16 10 5 21'%3E%3C/polyline%3E%3C/svg%3E") center no-repeat;
            background-size: 44px;
            z-index: 1;
        }
        
        .photo-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
            opacity: 0;
        }
        
        .photo-item img.loaded {
            opacity: 1;
        }
        
        .photo-item img.loaded + .photo-img-container::before {
            display: none;
        }
        
        .photo-category {
            position: absolute;
            top: 14px;
            left: 14px;
            background-color: rgba(22, 93, 255, 0.95);
            color: white;
            padding: 5px 14px;
            border-radius: var(--radius-full);
            font-size: 12px;
            font-weight: 500;
            z-index: 10;
        }
        
        .photo-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(to top, rgba(0,0,0,0.7) 0%, transparent 100%);
            opacity: 0;
            transition: opacity 0.3s ease;
            display: flex;
            align-items: flex-end;
            padding: 18px;
            z-index: 5;
        }
        
        .photo-item:hover .photo-overlay {
            opacity: 1;
        }
        
        .photo-actions {
            display: flex;
            gap: 10px;
            width: 100%;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 9px 16px;
            cursor: pointer;
            border-radius: 6px;
            border: none;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 13px;
            flex: 1;
            min-width: 45%;
        }
        
        .btn-view {
            background-color: var(--white);
            color: var(--primary-dark);
            border: 1px solid var(--gray-200);
        }
        
        .btn-approve {
            background-color: var(--success);
            color: white;
        }
        
        .btn-reject {
            background-color: var(--danger);
            color: white;
        }
        
        .btn-delete {
            background-color: #8B0000;
            color: white;
        }
        
        .btn-feature {
            background-color: var(--featured);
            color: white;
        }
        
        .btn-unfeature {
            background-color: #666;
            color: white;
        }
        
        .photo-info {
            padding: 22px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        
        .photo-title {
            font-size: 1.15rem;
            margin-bottom: 12px;
            color: var(--primary-dark);
            font-weight: 600;
            line-height: 1.5;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .photo-meta {
            display: flex;
            justify-content: space-between;
            margin-top: auto;
            font-size: 13px;
            color: var(--gray-500);
            padding-top: 8px;
            border-top: 1px solid var(--gray-100);
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 12px;
            margin: 48px 0 64px;
        }
        
        .pagination-btn {
            width: 46px;
            height: 46px;
            border-radius: 50%;
            border: 1px solid var(--gray-200);
            background-color: var(--white);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: var(--transition);
            color: var(--gray-500);
            text-decoration: none;
            font-weight: 500;
        }
        
        .pagination-btn.active {
            background-color: var(--primary);
            color: white;
            border-color: var(--primary);
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .empty-state {
            text-align: center;
            padding: 80px 24px;
            color: var(--gray-400);
            background-color: var(--white);
            border-radius: var(--radius-lg);
            margin-bottom: 48px;
            border: 1px dashed var(--gray-200);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 24px;
            color: var(--gray-300);
        }
        
        .reject-form-container {
            display: none;
            margin-top: 18px;
            padding-top: 18px;
            border-top: 1px dashed var(--gray-200);
            animation: slideDown 0.3s ease forwards;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .reject-form-container.active {
            display: block;
        }
        
        textarea {
            width: 100%;
            min-height: 90px;
            margin-bottom: 12px;
            padding: 14px 16px;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
            resize: vertical;
            font-family: inherit;
            font-size: 14px;
        }
        
        textarea:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(22, 93, 255, 0.1);
        }
        
        .form-actions {
            display: flex;
            gap: 12px;
        }
        
        @media (max-width: 768px) {
            .photo-grid {
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            }
            
            .photo-actions .btn {
                flex: 1 0 calc(50% - 8px);
                font-size: 12px;
            }
            
            .filter-group {
                min-width: 100%;
            }
        }
        
        @media (max-width: 576px) {
            .photo-grid {
                grid-template-columns: 1fr;
            }
            
            .photo-actions .btn {
                flex: 1 0 100%;
            }
        }
    </style>
</head>
<body>
    <div class="nav">
        <div class="container nav-container">
            <a href="index.php" class="logo">
                <i class="fas fa-plane"></i>
                syphotos航空摄影
            </a>
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-home"></i> 首页</a>
                <a href="admin_dashboard.php"><i class="fas fa-tachometer-alt"></i> 仪表盘</a>
                <a href="admin_review.php"><i class="fas fa-check-circle"></i> 内容审核</a>
                <a href="admin_allphotos.php" class="active"><i class="fas fa-images"></i> 所有图片</a>
                <a href="admin_users.php"><i class="fas fa-users"></i> 用户管理</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> 退出</a>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title"><i class="fas fa-images"></i> 所有图片管理</h1>
            <p class="page-desc">管理平台上的所有图片，支持重新审核、精选设置、永久删除等操作</p>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <!-- 筛选器 -->
        <div class="filters">
            <div class="filter-group">
                <div class="filter-label"><i class="fas fa-filter"></i> 审核状态</div>
                <div class="filter-options">
                    <?php foreach($status_options as $value => $label): ?>
                        <a href="admin_allphotos.php?status=<?php echo urlencode($value); ?><?php 
                            echo !empty($selected_category) ? '&category=' . urlencode($selected_category) : '';
                            echo $selected_featured !== '' ? '&featured=' . $selected_featured : '';
                        ?>" class="filter-btn <?php echo $selected_status == $value ? 'active' : ''; ?>">
                            <?php echo $label; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="filter-group">
                <div class="filter-label"><i class="fas fa-folder"></i> 图片分类</div>
                <div class="filter-options">
                    <a href="admin_allphotos.php?<?php 
                        echo !empty($selected_status) ? 'status=' . urlencode($selected_status) : '';
                        echo $selected_featured !== '' ? (empty($selected_status) ? '' : '&') . 'featured=' . $selected_featured : '';
                    ?>" class="filter-btn <?php echo empty($selected_category) ? 'active' : ''; ?>">
                        <i class="fas fa-th"></i> 全部分类
                    </a>
                    <?php foreach($categories as $category): ?>
                        <a href="admin_allphotos.php?category=<?php echo urlencode($category); ?><?php 
                            echo !empty($selected_status) ? '&status=' . urlencode($selected_status) : '';
                            echo $selected_featured !== '' ? '&featured=' . $selected_featured : '';
                        ?>" class="filter-btn <?php echo $selected_category == $category ? 'active' : ''; ?>">
                            <i class="fas fa-folder-open"></i> <?php echo htmlspecialchars($category); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="filter-group">
                <div class="filter-label"><i class="fas fa-star"></i> 精选状态</div>
                <div class="filter-options">
                    <a href="admin_allphotos.php?<?php 
                        echo !empty($selected_status) ? 'status=' . urlencode($selected_status) : '';
                        echo !empty($selected_category) ? (empty($selected_status) ? '' : '&') . 'category=' . urlencode($selected_category) : '';
                    ?>" class="filter-btn <?php echo $selected_featured === '' ? 'active' : ''; ?>">
                        <i class="fas fa-star-half-alt"></i> 全部
                    </a>
                    <a href="admin_allphotos.php?featured=1<?php 
                        echo !empty($selected_status) ? '&status=' . urlencode($selected_status) : '';
                        echo !empty($selected_category) ? '&category=' . urlencode($selected_category) : '';
                    ?>" class="filter-btn <?php echo $selected_featured == 1 ? 'active' : ''; ?>">
                        <i class="fas fa-star"></i> 仅精选
                    </a>
                    <a href="admin_allphotos.php?featured=0<?php 
                        echo !empty($selected_status) ? '&status=' . urlencode($selected_status) : '';
                        echo !empty($selected_category) ? '&category=' . urlencode($selected_category) : '';
                    ?>" class="filter-btn <?php echo $selected_featured == 0 ? 'active' : ''; ?>">
                        <i class="fas fa-star-o"></i> 非精选
                    </a>
                </div>
            </div>
        </div>
        
        <!-- 图片网格 -->
        <?php if(empty($photos)): ?>
            <div class="empty-state">
                <i class="fas fa-image"></i>
                <p>没有找到符合筛选条件的图片</p>
                <?php if(!empty($selected_status) || !empty($selected_category) || $selected_featured !== ''): ?>
                    <p>
                        <a href="admin_allphotos.php">清除所有筛选条件，查看全部图片</a>
                    </p>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="photo-grid">
                <?php foreach($photos as $photo): ?>
                    <div class="photo-item">
                        <span class="photo-category"><?php echo htmlspecialchars($photo['category']); ?></span>
                        <div class="photo-status">
                            <?php echo getStatusBadge($photo['approved'], $photo['is_featured']); ?>
                        </div>
                        <div class="photo-img-container">
                            <img src="uploads/<?php echo htmlspecialchars($photo['filename']); ?>" 
                                 alt="<?php echo htmlspecialchars($photo['title']); ?>" 
                                 loading="lazy"
                                 onload="this.classList.add('loaded')"
                                 onerror="this.src='data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'200\' height=\'200\' viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'%23F53F3F\' stroke-width=\'2\' stroke-linecap=\'round\' stroke-linejoin=\'round\'%3E%3Crect x=\'3\' y=\'3\' width=\'18\' height=\'18\' rx=\'2\' ry=\'2\'%3E%3C/rect%3E%3Ccircle cx=\'8.5\' cy=\'8.5\' r=\'1.5\'%3E%3C/circle%3E%3Cpolyline points=\'21 15 16 10 5 21\'%3E%3C/polyline%3E%3C/svg%3E'; this.classList.add('loaded')">
                        </div>
                        <div class="photo-info">
                            <h3 class="photo-title"><?php echo htmlspecialchars($photo['title']); ?></h3>
                            <div class="photo-meta">
                                <div class="photo-author">
                                    <i class="fas fa-user"></i>
                                    <span><?php echo htmlspecialchars($photo['username']); ?></span>
                                </div>
                                <div class="photo-date">
                                    <i class="fas fa-calendar"></i>
                                    <span><?php echo date('Y-m-d', strtotime($photo['created_at'])); ?></span>
                                </div>
                            </div>
                            <!-- 图片操作层 -->
                            <div class="photo-overlay">
                                <div class="photo-actions">
                                    <!-- 查看详情 -->
                                    <a href="photo_detail.php?id=<?php echo $photo['id']; ?>" 
                                       class="btn btn-view" target="_blank" rel="noopener">
                                        <i class="fas fa-eye"></i> 查看
                                    </a>
                                    
                                    <!-- 重新通过 -->
                                    <form method="post" action="admin_allphotos.php?<?php echo http_build_query($_GET); ?>" 
                                          style="flex: 1;" onsubmit="return confirm('确定要将此图片重新设为通过状态吗？');">
                                        <input type="hidden" name="photo_id" value="<?php echo $photo['id']; ?>">
                                        <button type="submit" name="approve" class="btn btn-approve">
                                            <i class="fas fa-check"></i> 重新通过
                                        </button>
                                    </form>
                                    
                                    <!-- 重新拒绝 -->
                                    <button type="button" class="btn btn-reject" 
                                            onclick="toggleRejectForm(<?php echo $photo['id']; ?>)">
                                        <i class="fas fa-times"></i> 重新拒绝
                                    </button>
                                    
                                    <!-- 精选/取消精选 -->
                                    <form method="post" action="admin_allphotos.php?<?php echo http_build_query($_GET); ?>" 
                                          style="flex: 1;" onsubmit="return confirm('确定要<?php echo $photo['is_featured'] == 1 ? '取消' : '设为'; ?>此图片的精选状态吗？');">
                                        <input type="hidden" name="photo_id" value="<?php echo $photo['id']; ?>">
                                        <?php if($photo['is_featured'] == 1): ?>
                                            <button type="submit" name="unset_featured" class="btn btn-unfeature">
                                                <i class="fas fa-star-slash"></i> 取消精选
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" name="set_featured" class="btn btn-feature">
                                                <i class="fas fa-star"></i> 设为精选
                                            </button>
                                        <?php endif; ?>
                                    </form>

                                    <!-- 永久删除 -->
                                    <form method="post" action="admin_allphotos.php?<?php echo http_build_query($_GET); ?>" 
                                          style="flex: 1;" onsubmit="return confirm('确定要永久删除这张图片吗？删除后无法恢复！');">
                                        <input type="hidden" name="photo_id" value="<?php echo $photo['id']; ?>">
                                        <button type="submit" name="delete_photo" class="btn btn-delete">
                                            <i class="fas fa-trash"></i> 删除
                                        </button>
                                    </form>
                                </div>
                                
                                <!-- 拒绝理由表单 -->
                                <div class="reject-form-container" id="rejectForm_<?php echo $photo['id']; ?>">
                                    <form method="post" action="admin_allphotos.php?<?php echo http_build_query($_GET); ?>">
                                        <input type="hidden" name="photo_id" value="<?php echo $photo['id']; ?>">
                                        <textarea name="reason" placeholder="请输入拒绝理由（将覆盖原理由）..." required><?php
                                            if($photo['rejection_reason']) echo htmlspecialchars($photo['rejection_reason']);
                                        ?></textarea>
                                        <div class="form-actions">
                                            <button type="submit" name="reject" class="btn btn-reject">
                                                <i class="fas fa-times"></i> 确认拒绝
                                            </button>
                                            <button type="button" class="btn btn-view" 
                                                    onclick="toggleRejectForm(<?php echo $photo['id']; ?>)">
                                                <i class="fas fa-ban"></i> 取消
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        
        <!-- 分页 -->
        <?php if($total_pages > 1): ?>
            <div class="pagination">
                <!-- 上一页 -->
                <a href="<?php 
                    $params = $_GET;
                    $params['page'] = $current_page - 1;
                    echo empty($params['page']) || $params['page'] < 1 ? '#' : 'admin_allphotos.php?' . http_build_query($params);
                ?>" class="pagination-btn <?php echo $current_page <= 1 ? 'disabled' : ''; ?>">
                    <i class="fas fa-chevron-left"></i>
                </a>
                
                <!-- 页码 -->
                <?php 
                $start_page = max(1, $current_page - 2);
                $end_page = min($total_pages, $current_page + 2);
                
                if($start_page > 1) {
                    $params = $_GET;
                    $params['page'] = 1;
                    echo '<a href="admin_allphotos.php?' . http_build_query($params) . '" class="pagination-btn">1</a>';
                    if($start_page > 2) echo '<span class="pagination-ellipsis">...</span>';
                }
                
                for($i = $start_page; $i <= $end_page; $i++) {
                    $params = $_GET;
                    $params['page'] = $i;
                    echo '<a href="admin_allphotos.php?' . http_build_query($params) . '" class="pagination-btn ' . ($i == $current_page ? 'active' : '') . '">' . $i . '</a>';
                }
                
                if($end_page < $total_pages) {
                    if($end_page < $total_pages - 1) echo '<span class="pagination-ellipsis">...</span>';
                    $params = $_GET;
                    $params['page'] = $total_pages;
                    echo '<a href="admin_allphotos.php?' . http_build_query($params) . '" class="pagination-btn">' . $total_pages . '</a>';
                }
                ?>
                
                <!-- 下一页 -->
                <a href="<?php 
                    $params = $_GET;
                    $params['page'] = $current_page + 1;
                    echo $params['page'] > $total_pages ? '#' : 'admin_allphotos.php?' . http_build_query($params);
                ?>" class="pagination-btn <?php echo $current_page >= $total_pages ? 'disabled' : ''; ?>">
                    <i class="fas fa-chevron-right"></i>
                </a>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // 切换拒绝理由表单显示/隐藏
        function toggleRejectForm(photoId) {
            const formId = 'rejectForm_' + photoId;
            const form = document.getElementById(formId);
            
            document.querySelectorAll('.reject-form-container').forEach(el => {
                if(el.id !== formId) el.classList.remove('active');
            });
            
            if(form) {
                form.classList.toggle('active');
                if(form.classList.contains('active')) {
                    const textarea = form.querySelector('textarea');
                    if(textarea) textarea.focus();
                }
            }
        }
        
        // 点击其他区域关闭拒绝表单
        document.addEventListener('click', function(event) {
            if(!event.target.closest('.btn-reject') && !event.target.closest('.reject-form-container')) {
                document.querySelectorAll('.reject-form-container').forEach(el => {
                    el.classList.remove('active');
                });
            }
        });
        
        // 筛选器保留分页状态
        document.querySelectorAll('.filter-btn').forEach(btn => {
            btn.addEventListener('click', function(e) {
                const currentPage = new URLSearchParams(window.location.search).get('page');
                if(currentPage && this.href.indexOf('page=') === -1) {
                    e.preventDefault();
                    const url = new URL(this.href);
                    url.searchParams.set('page', currentPage);
                    window.location.href = url.toString();
                }
            });
        });
    </script>
</body>
</html>
    