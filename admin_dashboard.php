<?php
require 'db_connect.php';
session_start();

// 检查是否登录且是管理员
if(!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

// 处理用户角色变更
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['change_role'])) {
    $user_id = intval($_POST['user_id']);
    $is_admin = intval($_POST['is_admin']);
    
    // 防止取消当前登录管理员的权限
    if($user_id == $_SESSION['user_id'] && $is_admin == 0) {
        $error = "不能取消当前登录管理员的权限";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_admin = :is_admin WHERE id = :id");
            $stmt->bindParam(':is_admin', $is_admin);
            $stmt->bindParam(':id', $user_id);
            $stmt->execute();
            
            $success = $is_admin ? '用户已设为管理员' : '用户已取消管理员权限';
        } catch(PDOException $e) {
            $error = "操作失败: " . $e->getMessage();
        }
    }
}

// 处理用户封禁/解封
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['toggle_ban'])) {
    $user_id = intval($_POST['user_id']);
    $is_banned = intval($_POST['is_banned']);
    
    // 防止封禁当前登录管理员
    if($user_id == $_SESSION['user_id']) {
        $error = "不能封禁当前登录的管理员账户";
    } else {
        try {
            $stmt = $pdo->prepare("UPDATE users SET is_banned = :is_banned WHERE id = :id");
            $stmt->bindParam(':is_banned', $is_banned);
            $stmt->bindParam(':id', $user_id);
            $stmt->execute();
            
            $success = $is_banned ? '用户已成功封禁' : '用户已解除封禁';
        } catch(PDOException $e) {
            $error = "操作失败: " . $e->getMessage();
        }
    }
}

// 处理删除用户
if($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_user'])) {
    $user_id = intval($_POST['user_id']);
    
    // 防止删除当前登录用户
    if($user_id == $_SESSION['user_id']) {
        $error = "不能删除当前登录的管理员账户";
    } else {
        try {
            // 开始事务
            $pdo->beginTransaction();
            
            // 删除用户的申诉记录
            $stmt = $pdo->prepare("DELETE FROM appeals WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            // 删除用户的点赞记录
            $stmt = $pdo->prepare("DELETE FROM photo_likes WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            // 获取用户的图片
            $stmt = $pdo->prepare("SELECT filename FROM photos WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 删除用户的图片记录
            $stmt = $pdo->prepare("DELETE FROM photos WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            // 删除图片文件
            foreach($photos as $photo) {
                $file_path = 'uploads/' . $photo['filename'];
                if(file_exists($file_path)) {
                    unlink($file_path);
                }
            }
            
            // 删除用户
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            
            // 提交事务
            $pdo->commit();
            
            $success = '用户已成功删除';
        } catch(PDOException $e) {
            // 回滚事务
            $pdo->rollBack();
            $error = "删除失败: " . $e->getMessage();
        }
    }
}

// 处理公告操作（新增、编辑、删除、切换状态）
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    // 新增公告
    if(isset($_POST['add_announcement'])) {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        
        if(empty($title) || empty($content)) {
            $error = "公告标题和内容不能为空";
        } else {
            try {
                $stmt = $pdo->prepare("INSERT INTO announcements (title, content) VALUES (:title, :content)");
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':content', $content);
                $stmt->execute();
                $success = "公告添加成功";
            } catch(PDOException $e) {
                $error = "添加公告失败: " . $e->getMessage();
            }
        }
    }
    
    // 编辑公告
    if(isset($_POST['edit_announcement'])) {
        $id = intval($_POST['id']);
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        
        if(empty($title) || empty($content) || $id <= 0) {
            $error = "无效的公告数据";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE announcements SET title = :title, content = :content WHERE id = :id");
                $stmt->bindParam(':title', $title);
                $stmt->bindParam(':content', $content);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                $success = "公告更新成功";
            } catch(PDOException $e) {
                $error = "更新公告失败: " . $e->getMessage();
            }
        }
    }
    
    // 删除公告
    if(isset($_POST['delete_announcement'])) {
        $id = intval($_POST['id']);
        if($id <= 0) {
            $error = "无效的公告ID";
        } else {
            try {
                $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = :id");
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                $success = "公告已删除";
            } catch(PDOException $e) {
                $error = "删除公告失败: " . $e->getMessage();
            }
        }
    }
    
    // 切换公告状态（启用/禁用）
    if(isset($_POST['toggle_announcement'])) {
        $id = intval($_POST['id']);
        $is_active = intval($_POST['is_active']);
        if($id <= 0) {
            $error = "无效的公告ID";
        } else {
            try {
                $stmt = $pdo->prepare("UPDATE announcements SET is_active = :is_active WHERE id = :id");
                $stmt->bindParam(':is_active', $is_active);
                $stmt->bindParam(':id', $id);
                $stmt->execute();
                $success = $is_active ? "公告已启用" : "公告已禁用";
            } catch(PDOException $e) {
                $error = "切换状态失败: " . $e->getMessage();
            }
        }
    }
}

// 获取系统统计数据
try {
    // 用户总数
    $user_count_stmt = $pdo->query("SELECT COUNT(*) as count FROM users");
    $user_count = $user_count_stmt->fetchColumn();
    
    // 封禁用户数
    $banned_count_stmt = $pdo->query("SELECT COUNT(*) as count FROM users WHERE is_banned = 1");
    $banned_count = $banned_count_stmt->fetchColumn();
    
    // 图片总数
    $photo_count_stmt = $pdo->query("SELECT COUNT(*) as count FROM photos");
    $photo_count = $photo_count_stmt->fetchColumn();
    
    // 已审核图片数
    $approved_count_stmt = $pdo->query("SELECT COUNT(*) as count FROM photos WHERE approved = 1");
    $approved_count = $approved_count_stmt->fetchColumn();
    
    // 待审核图片数
    $pending_count_stmt = $pdo->query("SELECT COUNT(*) as count FROM photos WHERE approved = 0");
    $pending_count = $pending_count_stmt->fetchColumn();
    
    // 申诉总数
    $appeal_count_stmt = $pdo->query("SELECT COUNT(*) as count FROM appeals");
    $appeal_count = $appeal_count_stmt->fetchColumn();
    
    // 获取所有用户
    $users_stmt = $pdo->query("SELECT * FROM users ORDER BY id DESC");
    $users = $users_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 获取所有公告
    $announcements_stmt = $pdo->query("SELECT * FROM announcements ORDER BY created_at DESC");
    $announcements = $announcements_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error = "获取数据失败: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horizon Photos - 管理员仪表盘</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #165DFF;
            --primary-light: #4080FF;
            --primary-dark: #0E42D2;
            --success: #28a745;
            --danger: #dc3545;
            --warning: #ffc107;
            --light-bg: #f0f7ff;
            --white: #ffffff;
            --gray-light: #f8f9fa;
            --gray: #e9ecef;
            --gray-dark: #343a40;
            --text-dark: #212529;
            --text-muted: #6c757d;
            --border-radius: 6px;
            --box-shadow: 0 2px 10px rgba(0, 0, 0, 0.08);
            --transition: all 0.3s ease;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body { 
            font-family: 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif; 
            background-color: var(--light-bg); 
            color: var(--text-dark);
            line-height: 1.6;
            padding: 0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .nav { 
            background-color: var(--primary); 
            padding: 15px 0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
            margin-bottom: 30px;
        }
        
        .nav-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            color: white;
            font-size: 1.4rem;
            font-weight: bold;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .logo i {
            font-size: 1.6rem;
        }
        
        .nav-links {
            display: flex;
            gap: 20px;
        }
        
        .nav a { 
            color: white; 
            text-decoration: none; 
            padding: 6px 10px;
            border-radius: 4px;
            transition: var(--transition);
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .nav a:hover, .nav a.active {
            background-color: var(--primary-dark);
        }
        
        .admin-panel {
            padding: 20px 0;
        }
        
        .page-title {
            font-size: 1.8rem;
            margin-bottom: 25px;
            color: var(--primary-dark);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .page-title i {
            color: var(--primary);
        }
        
        .alert {
            padding: 12px 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-weight: 500;
        }
        
        .alert-error {
            background-color: #fff5f5;
            color: var(--danger);
            border: 1px solid #ffe3e3;
        }
        
        .alert-success {
            background-color: #f0fff4;
            color: var(--success);
            border: 1px solid #c6f6d5;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background-color: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            border-left: 4px solid var(--primary);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 8px 15px rgba(0, 0, 0, 0.1);
        }
        
        .stat-card.users {
            border-left-color: var(--primary);
        }
        
        .stat-card.banned {
            border-left-color: var(--danger);
        }
        
        .stat-card.photos {
            border-left-color: var(--success);
        }
        
        .stat-card.approved {
            border-left-color: #17a2b8;
        }
        
        .stat-card.pending {
            border-left-color: var(--warning);
        }
        
        .stat-card.appeals {
            border-left-color: var(--danger);
        }
        
        .stat-title {
            font-size: 1rem;
            color: var(--text-muted);
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .stat-title i {
            font-size: 1.2rem;
        }
        
        .stat-value {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--primary-dark);
        }
        
        .section {
            background-color: var(--white);
            padding: 25px;
            margin-bottom: 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .section-title {
            font-size: 1.4rem;
            color: var(--primary-dark);
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid var(--gray);
        }
        
        th {
            background-color: var(--gray-light);
            font-weight: 600;
            color: var(--primary-dark);
        }
        
        tr:hover {
            background-color: rgba(240, 247, 255, 0.5);
        }
        
        .btn {
            padding: 6px 12px;
            cursor: pointer;
            border-radius: var(--border-radius);
            border: none;
            font-weight: 500;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
        }
        
        .btn-approve { 
            background-color: var(--success); 
            color: white; 
        }
        
        .btn-approve:hover {
            background-color: #218838;
        }
        
        .btn-danger { 
            background-color: var(--danger); 
            color: white; 
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .btn-outline {
            background-color: transparent;
            border: 1px solid var(--primary);
            color: var(--primary);
        }
        
        .btn-outline:hover {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-warning {
            background-color: var(--warning);
            color: #212529;
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .badge-admin {
            background-color: #d4edda;
            color: #155724;
        }
        
        .badge-user {
            background-color: #e3f2fd;
            color: #0d6efd;
        }
        
        .badge-active {
            background-color: #d4edda;
            color: #155724;
        }
        
        .badge-inactive {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .badge-banned {
            background-color: #f8d7da;
            color: #721c24;
        }
        
        .badge-normal {
            background-color: #d4edda;
            color: #155724;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
            color: var(--gray);
        }
        
        .action-buttons {
            display: flex;
            gap: 8px;
        }
        
        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            visibility: hidden;
            opacity: 0;
            transition: all 0.3s ease;
        }
        
        .modal-overlay.active {
            visibility: visible;
            opacity: 1;
        }
        
        .modal {
            background: white;
            padding: 25px;
            border-radius: var(--border-radius);
            width: 90%;
            max-width: 600px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
        }
        
        .modal h3 {
            margin-bottom: 15px;
            color: var(--primary-dark);
        }
        
        .form-group {
            margin-bottom: 18px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 500;
            color: var(--text-dark);
        }
        
        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--gray);
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: var(--transition);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(22, 93, 255, 0.1);
        }
        
        textarea.form-control {
            min-height: 150px;
            resize: vertical;
        }
        
        .modal-buttons {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
        }
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr 1fr;
            }
            
            .nav-links {
                flex-wrap: wrap;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            th, td {
                padding: 8px 10px;
                font-size: 14px;
            }
            
            .hidden-sm {
                display: none;
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
                <a href="all_photos.php"><i class="fas fa-images"></i> 全部图片</a>
                <a href="user_center.php"><i class="fas fa-user"></i> 用户中心</a>
                <a href="upload.php"><i class="fas fa-upload"></i> 上传图片</a>
                <a href="admin_dashboard.php" class="active"><i class="fas fa-tachometer-alt"></i> 管理员仪表盘</a>
                <a href="admin_review.php"><i class="fas fa-check-circle"></i> 内容审核</a>
                <a href="logout.php"><i class="fas fa-sign-out-alt"></i> 退出登录</a>
            </div>
        </div>
    </div>

    <div class="container admin-panel">
        <h1 class="page-title"><i class="fas fa-tachometer-alt"></i> 管理员仪表盘</h1>
        
        <?php if($error): ?>
            <div class="alert alert-error"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success"><?php echo $success; ?></div>
        <?php endif; ?>
        
        <!-- 系统统计 -->
        <div class="stats-grid">
            <div class="stat-card users">
                <div class="stat-title">
                    <i class="fas fa-users"></i> 总用户数
                </div>
                <div class="stat-value"><?php echo $user_count; ?></div>
            </div>
            
            <div class="stat-card banned">
                <div class="stat-title">
                    <i class="fas fa-user-lock"></i> 封禁用户数
                </div>
                <div class="stat-value"><?php echo $banned_count; ?></div>
            </div>
            
            <div class="stat-card photos">
                <div class="stat-title">
                    <i class="fas fa-images"></i> 总图片数
                </div>
                <div class="stat-value"><?php echo $photo_count; ?></div>
            </div>
            
            <div class="stat-card approved">
                <div class="stat-title">
                    <i class="fas fa-check-circle"></i> 已审核图片
                </div>
                <div class="stat-value"><?php echo $approved_count; ?></div>
            </div>
            
            <div class="stat-card pending">
                <div class="stat-title">
                    <i class="fas fa-clock"></i> 待审核图片
                </div>
                <div class="stat-value"><?php echo $pending_count; ?></div>
            </div>
            
            <div class="stat-card appeals">
                <div class="stat-title">
                    <i class="fas fa-balance-scale"></i> 申诉总数
                </div>
                <div class="stat-value"><?php echo $appeal_count; ?></div>
            </div>
        </div>
        
        <!-- 公告管理 -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-bullhorn"></i> 公告管理</h2>
                <button type="button" class="btn btn-primary" onclick="showAnnouncementModal('add')">
                    <i class="fas fa-plus"></i> 添加公告
                </button>
            </div>
            
            <table>
                <tr>
                    <th>ID</th>
                    <th>标题</th>
                    <th class="hidden-sm">创建时间</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
                <?php if(empty($announcements)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 30px;">暂无公告记录</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($announcements as $announcement): ?>
                        <tr>
                            <td><?php echo $announcement['id']; ?></td>
                            <td><?php echo htmlspecialchars($announcement['title']); ?></td>
                            <td class="hidden-sm"><?php echo $announcement['created_at']; ?></td>
                            <td>
                                <?php echo $announcement['is_active'] ? 
                                    '<span class="badge badge-active">已启用</span>' : 
                                    '<span class="badge badge-inactive">已禁用</span>'; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <!-- 切换状态 -->
                                    <form method="post" action="admin_dashboard.php" style="display: inline;">
                                        <input type="hidden" name="id" value="<?php echo $announcement['id']; ?>">
                                        <input type="hidden" name="is_active" value="<?php echo $announcement['is_active'] ? 0 : 1; ?>">
                                        <button type="submit" name="toggle_announcement" class="btn btn-outline">
                                            <?php echo $announcement['is_active'] ? 
                                                '<i class="fas fa-power-off"></i> 禁用' : 
                                                '<i class="fas fa-power-off"></i> 启用'; ?>
                                        </button>
                                    </form>
                                    
                                    <!-- 编辑公告 -->
                                    <button type="button" class="btn btn-primary" 
                                            onclick="showAnnouncementModal('edit', <?php echo $announcement['id']; ?>, 
                                            '<?php echo addslashes($announcement['title']); ?>', 
                                            '<?php echo addslashes($announcement['content']); ?>')">
                                        <i class="fas fa-edit"></i> 编辑
                                    </button>
                                    
                                    <!-- 删除公告 -->
                                    <button type="button" class="btn btn-danger" 
                                            onclick="showDeleteAnnouncementModal(<?php echo $announcement['id']; ?>, 
                                            '<?php echo htmlspecialchars($announcement['title']); ?>')">
                                        <i class="fas fa-trash"></i> 删除
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
        
        <!-- 用户管理 -->
        <div class="section">
            <div class="section-header">
                <h2 class="section-title"><i class="fas fa-users-cog"></i> 用户管理</h2>
            </div>
            
            <table>
                <tr>
                    <th>ID</th>
                    <th>用户名</th>
                    <th>邮箱</th>
                    <th class="hidden-sm">注册时间</th>
                    <th>角色</th>
                    <th>状态</th>
                    <th>操作</th>
                </tr>
                <?php if(empty($users)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px;">暂无用户记录</td>
                    </tr>
                <?php else: ?>
                    <?php foreach($users as $user): ?>
                        <tr>
                            <td><?php echo $user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email']); ?></td>
                            <td class="hidden-sm"><?php echo $user['created_at']; ?></td>
                            <td>
                                <?php echo $user['is_admin'] ? 
                                    '<span class="badge badge-admin">管理员</span>' : 
                                    '<span class="badge badge-user">普通用户</span>'; ?>
                            </td>
                            <td>
                                <?php echo $user['is_banned'] ? 
                                    '<span class="badge badge-banned">已封禁</span>' : 
                                    '<span class="badge badge-normal">正常</span>'; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <!-- 角色变更表单 -->
                                    <form method="post" action="admin_dashboard.php" style="display: inline;">
                                        <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                        <input type="hidden" name="is_admin" value="<?php echo $user['is_admin'] ? 0 : 1; ?>">
                                        <button type="submit" name="change_role" class="btn btn-outline">
                                            <?php echo $user['is_admin'] ? 
                                                '<i class="fas fa-user"></i> 取消管理员' : 
                                                '<i class="fas fa-user-shield"></i> 设为管理员'; ?>
                                        </button>
                                    </form>
                                    
                                    <!-- 封禁/解封表单 -->
                                    <?php if($user['id'] != $_SESSION['user_id']): ?>
                                        <form method="post" action="admin_dashboard.php" style="display: inline;">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <input type="hidden" name="is_banned" value="<?php echo $user['is_banned'] ? 0 : 1; ?>">
                                            <button type="submit" name="toggle_ban" class="btn btn-warning">
                                                <?php echo $user['is_banned'] ? 
                                                    '<i class="fas fa-unlock"></i> 解封' : 
                                                    '<i class="fas fa-lock"></i> 封禁'; ?>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    
                                    <!-- 删除用户按钮 -->
                                    <?php if($user['id'] != $_SESSION['user_id']): ?>
                                        <button type="button" class="btn btn-danger" 
                                                onclick="showDeleteModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['username']); ?>')">
                                            <i class="fas fa-trash"></i> 删除
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>
    </div>
    
    <!-- 公告编辑/添加模态框 -->
    <div class="modal-overlay" id="announcementModal">
        <div class="modal">
            <h3 id="announcementModalTitle">添加公告</h3>
            <form method="post" action="admin_dashboard.php" id="announcementForm">
                <input type="hidden" name="id" id="announcementId">
                <div class="form-group">
                    <label for="title">公告标题</label>
                    <input type="text" class="form-control" id="title" name="title" placeholder="请输入公告标题" required>
                </div>
                <div class="form-group">
                    <label for="content">公告内容</label>
                    <textarea class="form-control" id="content" name="content" placeholder="请输入公告内容" required></textarea>
                </div>
                <div class="modal-buttons">
                    <button type="button" class="btn btn-outline" onclick="hideAnnouncementModal()">取消</button>
                    <button type="submit" id="announcementSubmitBtn" class="btn btn-primary">保存公告</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 删除用户模态框 -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal">
            <h3>确认删除用户</h3>
            <p>您确定要删除用户 <strong id="deleteUsername"></strong> 吗？此操作将删除该用户的所有图片和数据，且无法恢复。</p>
            
            <form method="post" action="admin_dashboard.php" id="deleteForm">
                <input type="hidden" name="user_id" id="deleteUserId" value="">
                <div class="modal-buttons">
                    <button type="button" class="btn btn-outline" onclick="hideDeleteModal()">取消</button>
                    <button type="submit" name="delete_user" class="btn btn-danger">确认删除</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- 删除公告模态框 -->
    <div class="modal-overlay" id="deleteAnnouncementModal">
        <div class="modal">
            <h3>确认删除公告</h3>
            <p>您确定要删除公告 <strong id="deleteAnnouncementTitle"></strong> 吗？此操作无法恢复。</p>
            
            <form method="post" action="admin_dashboard.php" id="deleteAnnouncementForm">
                <input type="hidden" name="id" id="deleteAnnouncementId" value="">
                <div class="modal-buttons">
                    <button type="button" class="btn btn-outline" onclick="hideDeleteAnnouncementModal()">取消</button>
                    <button type="submit" name="delete_announcement" class="btn btn-danger">确认删除</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 公告模态框控制
        function showAnnouncementModal(type, id = '', title = '', content = '') {
            const modal = document.getElementById('announcementModal');
            const modalTitle = document.getElementById('announcementModalTitle');
            const idInput = document.getElementById('announcementId');
            const titleInput = document.getElementById('title');
            const contentInput = document.getElementById('content');
            const submitBtn = document.getElementById('announcementSubmitBtn');
            
            if(type === 'add') {
                modalTitle.textContent = '添加公告';
                idInput.value = '';
                titleInput.value = '';
                contentInput.value = '';
                submitBtn.name = 'add_announcement';
            } else {
                modalTitle.textContent = '编辑公告';
                idInput.value = id;
                titleInput.value = title;
                contentInput.value = content;
                submitBtn.name = 'edit_announcement';
            }
            
            modal.classList.add('active');
        }
        
        function hideAnnouncementModal() {
            document.getElementById('announcementModal').classList.remove('active');
        }
        
        // 删除用户模态框控制
        function showDeleteModal(userId, username) {
            document.getElementById('deleteUserId').value = userId;
            document.getElementById('deleteUsername').textContent = username;
            document.getElementById('deleteModal').classList.add('active');
        }
        
        function hideDeleteModal() {
            document.getElementById('deleteModal').classList.remove('active');
        }
        
        // 删除公告模态框控制
        function showDeleteAnnouncementModal(id, title) {
            document.getElementById('deleteAnnouncementId').value = id;
            document.getElementById('deleteAnnouncementTitle').textContent = title;
            document.getElementById('deleteAnnouncementModal').classList.add('active');
        }
        
        function hideDeleteAnnouncementModal() {
            document.getElementById('deleteAnnouncementModal').classList.remove('active');
        }
        
        // 点击模态框外部关闭
        document.querySelectorAll('.modal-overlay').forEach(overlay => {
            overlay.addEventListener('click', function(e) {
                if (e.target === this) {
                    this.classList.remove('active');
                }
            });
        });
    </script>
</body>
</html>