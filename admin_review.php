<?php
require 'db_connect.php';
session_start();

// 检查是否登录且是管理员（保留原权限逻辑）
if(!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';
$current_admin_id = $_SESSION['user_id'];
$current_photo = null; // 当前选中待审核的图片
$active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'pending'; // 活跃标签：pending(待抢单)/my-grabbed(我的抢单)/appeal(申诉)

// 处理图片操作（抢图、审核、拒绝、申诉、删除）- 保留原逻辑
if($_SERVER['REQUEST_METHOD'] == 'POST') {
    if(!isset($_POST['photo_id']) || intval($_POST['photo_id']) <= 0) {
        $error = "无效的图片ID";
    } else {
        $photo_id = intval($_POST['photo_id']);
        // 抢单审核
        if(isset($_POST['grab_review'])) {
            try {
                $check_stmt = $pdo->prepare("SELECT reviewer_id FROM photos WHERE id = :id AND approved = 0");
                $check_stmt->bindParam(':id', $photo_id);
                $check_stmt->execute();
                $photo = $check_stmt->fetch(PDO::FETCH_ASSOC);

                if(!$photo) $error = "图片不存在或已处理";
                elseif($photo['reviewer_id'] !== null) $error = "该图片已被其他管理员抢单审核";
                else {
                    $stmt = $pdo->prepare("UPDATE photos SET reviewer_id = :reviewer_id WHERE id = :id");
                    $stmt->bindParam(':reviewer_id', $current_admin_id);
                    $stmt->bindParam(':id', $photo_id);
                    $stmt->execute();
                    $success = '成功抢单，该图片已分配给您审核';
                }
            } catch(PDOException $e) {
                $error = "抢单失败: " . $e->getMessage();
            }
        }
        // 通过审核
        elseif(isset($_POST['approve'])) {
            try {
                $stmt = $pdo->prepare("UPDATE photos SET 
                                    approved = 1, 
                                    rejection_reason = NULL,
                                    reviewer_id = IFNULL(reviewer_id, :reviewer_id)
                                    WHERE id = :id");
                $stmt->bindParam(':reviewer_id', $current_admin_id);
                $stmt->bindParam(':id', $photo_id);
                $stmt->execute();
                $success = '图片已通过审核';
            } catch(PDOException $e) {
                $error = "审核失败: " . $e->getMessage();
            }
        }
        // 拒绝审核（含理由和留言）
        elseif(isset($_POST['reject'])) {
            $reason = trim($_POST['reason'] ?? '');
            $admin_comment = trim($_POST['admin_comment'] ?? '');
            if(empty($reason)) $error = "拒绝图片必须填写理由";
            else {
                try {
                    $stmt = $pdo->prepare("UPDATE photos SET 
                                        approved = 2, 
                                        rejection_reason = :reason,
                                        admin_comment = :admin_comment,
                                        reviewer_id = IFNULL(reviewer_id, :reviewer_id)
                                        WHERE id = :id");
                    $stmt->bindParam(':reason', $reason);
                    $stmt->bindParam(':admin_comment', $admin_comment);
                    $stmt->bindParam(':reviewer_id', $current_admin_id);
                    $stmt->bindParam(':id', $photo_id);
                    $stmt->execute();
                    $success = '图片已拒绝并记录理由和留言';
                } catch(PDOException $e) {
                    $error = "拒绝失败: " . $e->getMessage();
                }
            }
        }
        // 申诉处理
        elseif(isset($_POST['appeal_review'])) {
            $appeal_id = intval($_POST['appeal_id']);
            $action = $_POST['appeal_review'];
            $admin_comment = trim($_POST['admin_comment'] ?? '');
            if($action == 'reject') {
                $appeal_reason = trim($_POST['appeal_reason'] ?? '');
                if(empty($appeal_reason)) $error = "拒绝申诉必须填写理由";
            }
            
            if(empty($error)) {
                try {
                    $appeal_status = ($action == 'approve') ? 1 : 2;
                    $photo_status = ($action == 'approve') ? 1 : 2;
                    $response_text = ($action == 'reject') ? $appeal_reason : '申诉通过';
                    
                    // 更新申诉记录
                    $stmt = $pdo->prepare("UPDATE appeals SET 
                                        status = :status, 
                                        response = :response,
                                        admin_comment = :admin_comment
                                        WHERE id = :id");
                    $stmt->bindParam(':status', $appeal_status);
                    $stmt->bindParam(':response', $response_text);
                    $stmt->bindParam(':admin_comment', $admin_comment);
                    $stmt->bindParam(':id', $appeal_id);
                    $stmt->execute();
                    
                    // 更新图片状态
                    $photo_sql = "UPDATE photos SET approved = :status, reviewer_id = IFNULL(reviewer_id, :reviewer_id)";
                    if($action == 'reject') $photo_sql .= ", rejection_reason = :reason, admin_comment = :admin_comment";
                    $photo_sql .= " WHERE id = :id";
                    
                    $stmt = $pdo->prepare($photo_sql);
                    $stmt->bindParam(':status', $photo_status);
                    $stmt->bindParam(':reviewer_id', $current_admin_id);
                    $stmt->bindParam(':id', $photo_id);
                    if($action == 'reject') {
                        $stmt->bindParam(':reason', $appeal_reason);
                        $stmt->bindParam(':admin_comment', $admin_comment);
                    }
                    $stmt->execute();
                    $success = '申诉处理已完成';
                } catch(PDOException $e) {
                    $error = "处理申诉失败: " . $e->getMessage();
                }
            }
        }
        // 删除图片
        elseif(isset($_POST['delete_photo'])) {
            try {
                $stmt = $pdo->prepare("SELECT filename FROM photos WHERE id = :id");
                $stmt->bindParam(':id', $photo_id);
                $stmt->execute();
                $photo = $stmt->fetch(PDO::FETCH_ASSOC);

                if(!$photo) $error = "图片不存在";
                else {
                    // 删除数据库记录
                    $delete_stmt = $pdo->prepare("DELETE FROM photos WHERE id = :id");
                    $delete_stmt->bindParam(':id', $photo_id);
                    $delete_stmt->execute();
                    // 删除关联申诉
                    $delete_appeal = $pdo->prepare("DELETE FROM appeals WHERE photo_id = :id");
                    $delete_appeal->bindParam(':id', $photo_id);
                    $delete_appeal->execute();
                    // 删除文件
                    $file_path = "uploads/" . $photo['filename'];
                    if(file_exists($file_path)) unlink($file_path);
                    $success = '图片已永久删除';
                }
            } catch(PDOException $e) {
                $error = "删除失败: " . $e->getMessage();
            }
        }
        // 操作后刷新页面，保持当前标签
        header("Location: admin_review.php?tab=$active_tab&" . ($error ? "error=$error" : "success=$success"));
        exit;
    }
}

// 分页配置（参考铁路页面，每页5条）
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = 5;
$offset = ($page - 1) * $per_page;

// 1. 获取待审核图片（未抢单）- 带分页
$pending_count_stmt = $pdo->query("SELECT COUNT(*) as count FROM photos WHERE approved = 0 AND reviewer_id IS NULL");
$total_pending = $pending_count_stmt->fetch()['count'];
$total_pending_pages = ceil($total_pending / $per_page);

$pending_photos_stmt = $pdo->prepare("
    SELECT p.*, u.username as uploader_username 
    FROM photos p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.approved = 0 AND p.reviewer_id IS NULL
    ORDER BY p.created_at ASC 
    LIMIT :per_page OFFSET :offset
");
$pending_photos_stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$pending_photos_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$pending_photos_stmt->execute();
$pending_photos = $pending_photos_stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. 获取我抢单的图片 - 带分页
$my_grabbed_count_stmt = $pdo->prepare("SELECT COUNT(*) as count FROM photos WHERE approved = 0 AND reviewer_id = :admin_id");
$my_grabbed_count_stmt->bindParam(':admin_id', $current_admin_id);
$my_grabbed_count_stmt->execute();
$total_my_grabbed = $my_grabbed_count_stmt->fetch()['count'];
$total_my_grabbed_pages = ceil($total_my_grabbed / $per_page);

$my_grabbed_stmt = $pdo->prepare("
    SELECT p.*, u.username as uploader_username 
    FROM photos p 
    JOIN users u ON p.user_id = u.id 
    WHERE p.approved = 0 AND p.reviewer_id = :admin_id
    ORDER BY p.created_at ASC 
    LIMIT :per_page OFFSET :offset
");
$my_grabbed_stmt->bindParam(':admin_id', $current_admin_id);
$my_grabbed_stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$my_grabbed_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$my_grabbed_stmt->execute();
$my_grabbed_photos = $my_grabbed_stmt->fetchAll(PDO::FETCH_ASSOC);

// 3. 获取申诉中的图片 - 带分页
$appeal_count_stmt = $pdo->query("
    SELECT COUNT(*) as count FROM photos p 
    JOIN appeals a ON p.id = a.photo_id 
    WHERE p.approved = 3 AND a.status = 0
");
$total_appeal = $appeal_count_stmt->fetch()['count'];
$total_appeal_pages = ceil($total_appeal / $per_page);

$appealing_photos_stmt = $pdo->prepare("
    SELECT p.*, u.username as uploader_username, a.id as appeal_id, a.content as appeal_content, a.status as appeal_status
    FROM photos p 
    JOIN users u ON p.user_id = u.id 
    JOIN appeals a ON p.id = a.photo_id 
    WHERE p.approved = 3 AND a.status = 0
    ORDER BY a.created_at ASC 
    LIMIT :per_page OFFSET :offset
");
$appealing_photos_stmt->bindValue(':per_page', $per_page, PDO::PARAM_INT);
$appealing_photos_stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$appealing_photos_stmt->execute();
$appealing_photos = $appealing_photos_stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. 获取当前选中的图片详情（用于右侧预览）
if(isset($_GET['photo_id'])) {
    $photo_id = intval($_GET['photo_id']);
    $stmt = $pdo->prepare("
        SELECT p.*, u.username as uploader_username, r.username as reviewer_username,
               a.id as appeal_id, a.content as appeal_content, a.response as appeal_response
        FROM photos p 
        JOIN users u ON p.user_id = u.id 
        LEFT JOIN users r ON p.reviewer_id = r.id
        LEFT JOIN appeals a ON p.id = a.photo_id 
        WHERE p.id = :id
    ");
    $stmt->bindParam(':id', $photo_id);
    $stmt->execute();
    $current_photo = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 读取URL参数中的提示信息
if(isset($_GET['error'])) $error = $_GET['error'];
if(isset($_GET['success'])) $success = $_GET['success'];
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horizon Photos - 管理员后台</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdn.jsdelivr.net/npm/font-awesome@4.7.0/css/font-awesome.min.css" rel="stylesheet">
    <!-- Tailwind配置：保留航空摄影主题色 -->
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: '#165DFF',    // 航空蓝（原主题色）
                        success: '#00B42A',    // 通过绿
                        danger: '#F53F3F',     // 拒绝红
                        warning: '#FF7D00',    // 抢单橙
                        gray: {
                            100: '#F2F3F5',
                            200: '#E5E6EB',
                            300: '#C9CDD4',
                            400: '#86909C',
                            500: '#4E5969',
                        }
                    }
                }
            }
        }
    </script>
    <style type="text/tailwindcss">
        @layer utilities {
            .content-auto { content-visibility: auto; }
            .photo-frame {
                @apply border-4 border-gray-200 rounded-lg overflow-hidden shadow-md transition-all duration-300 hover:border-primary/30;
            }
            .review-tool {
                @apply bg-white rounded-xl shadow-lg p-5 sticky top-24;
            }
            .btn-action {
                @apply px-4 py-2 rounded-lg font-medium transition-all duration-300 transform hover:-translate-y-0.5 focus:outline-none focus:ring-2 focus:ring-offset-2;
            }
            .info-label {
                @apply text-gray-500 w-28; /* 固定标签宽度，对齐更整齐 */
            }
            .tab-active {
                @apply border-b-2 border-primary text-primary font-medium;
            }
        }
    </style>
</head>
<body class="bg-gray-50 font-sans">
    <!-- 导航栏：保留原航空摄影平台导航，适配Tailwind样式 -->
    <nav class="bg-white shadow-sm sticky top-0 z-40">
        <div class="container mx-auto px-4 py-4 flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <!-- Logo区 -->
            <a href="index.php" class="flex items-center space-x-2">
                <i class="fa fa-plane text-2xl text-primary"></i>
                <span class="font-bold text-xl text-primary">Horizon Photos</span>
                <span class="text-xs px-2 py-0.5 bg-primary/10 text-primary rounded-full ml-2">审核中心</span>
            </a>

            <!-- 导航链接区 -->
            <div class="flex flex-wrap items-center gap-3 md:gap-6">
                <a href="index.php" class="text-gray-600 hover:text-primary transition-colors text-sm">
                    <i class="fa fa-home mr-1"></i> 首页
                </a>
                <a href="all_photos.php" class="text-gray-600 hover:text-primary transition-colors text-sm">
                    <i class="fa fa-images mr-1"></i> 全部图片
                </a>
                <a href="user_center.php" class="text-gray-600 hover:text-primary transition-colors text-sm">
                    <i class="fa fa-user mr-1"></i> 用户中心
                </a>
                <a href="upload.php" class="text-gray-600 hover:text-primary transition-colors text-sm">
                    <i class="fa fa-upload mr-1"></i> 上传图片
                </a>
                <a href="admin_dashboard.php" class="text-gray-600 hover:text-primary transition-colors text-sm">
                    <i class="fa fa-tachometer mr-1"></i> 仪表盘
                </a>
                <a href="admin_review.php" class="text-primary font-medium transition-colors text-sm">
                    <i class="fa fa-check-circle mr-1"></i> 内容审核
                </a>
                <a href="admin_allphotos.php" class="text-gray-600 hover:text-primary transition-colors text-sm">
                    <i class="fa fa-images mr-1"></i> 所有图片
                </a>
                <a href="logout.php" class="text-gray-500 hover:text-danger transition-colors text-sm">
                    <i class="fa fa-sign-out mr-1"></i> 退出
                </a>
            </div>
        </div>
    </nav>

    <!-- 主内容区：左右分栏布局（参考铁路页面） -->
    <main class="container mx-auto px-4 py-8">
        <!-- 页面标题和提示信息 -->
        <div class="mb-8">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-800">内容审核管理</h1>
                    <p class="text-gray-500 mt-1">审核用户上传的航空图片，处理申诉请求</p>
                </div>
                <!-- 提示信息 -->
                <?php if($error): ?>
                    <div class="bg-danger/10 text-danger px-4 py-2 rounded-lg flex items-center text-sm">
                        <i class="fa fa-exclamation-circle mr-2"></i>
                        <span><?php echo $error; ?></span>
                    </div>
                <?php endif; ?>
                <?php if($success): ?>
                    <div class="bg-success/10 text-success px-4 py-2 rounded-lg flex items-center text-sm">
                        <i class="fa fa-check-circle mr-2"></i>
                        <span><?php echo $success; ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 左右分栏：左侧列表 + 右侧详情/审核 -->
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <!-- 左侧：待审核列表（带标签切换和分页） -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm p-5 h-full">
                    <!-- 标签切换：待抢单/我的抢单/申诉处理 -->
                    <div class="flex border-b border-gray-200 mb-4">
                        <a href="admin_review.php?tab=pending&page=1" 
                           class="py-2 px-4 text-sm transition-colors <?php echo $active_tab == 'pending' ? 'tab-active' : 'text-gray-500 hover:text-gray-700'; ?>">
                            <i class="fa fa-clock-o mr-1"></i> 待抢单
                            <span class="ml-1 bg-primary/10 text-primary text-xs px-1.5 py-0.5 rounded-full">
                                <?php echo $total_pending; ?>
                            </span>
                        </a>
                        <a href="admin_review.php?tab=my-grabbed&page=1" 
                           class="py-2 px-4 text-sm transition-colors <?php echo $active_tab == 'my-grabbed' ? 'tab-active' : 'text-gray-500 hover:text-gray-700'; ?>">
                            <i class="fa fa-hand-paper-o mr-1"></i> 我的抢单
                            <span class="ml-1 bg-warning/10 text-warning text-xs px-1.5 py-0.5 rounded-full">
                                <?php echo $total_my_grabbed; ?>
                            </span>
                        </a>
                        <a href="admin_review.php?tab=appeal&page=1" 
                           class="py-2 px-4 text-sm transition-colors <?php echo $active_tab == 'appeal' ? 'tab-active' : 'text-gray-500 hover:text-gray-700'; ?>">
                            <i class="fa fa-redo mr-1"></i> 申诉处理
                            <span class="ml-1 bg-danger/10 text-danger text-xs px-1.5 py-0.5 rounded-full">
                                <?php echo $total_appeal; ?>
                            </span>
                        </a>
                    </div>

                    <!-- 列表内容：根据当前标签显示对应数据 -->
                    <div class="space-y-3 max-h-[calc(100vh-280px)] overflow-y-auto pr-2">
                        <?php 
                        // 待抢单列表
                        if($active_tab == 'pending' && empty($pending_photos)): ?>
                            <div class="text-center py-8 text-gray-500 text-sm">
                                <i class="fa fa-clipboard-check text-3xl text-success/30 mb-2"></i>
                                <p>暂无待抢单的图片</p>
                            </div>
                        <?php elseif($active_tab == 'pending'): ?>
                            <?php foreach($pending_photos as $photo): ?>
                                <a href="admin_review.php?tab=pending&page=<?php echo $page; ?>&photo_id=<?php echo $photo['id']; ?>" 
                                   class="block p-3 rounded-lg border <?php echo (isset($_GET['photo_id']) && $_GET['photo_id'] == $photo['id']) ? 'border-primary bg-primary/5' : 'border-gray-200 hover:border-primary/30 hover:bg-gray-50'; ?> transition-all">
                                    <div class="flex items-center">
                                        <img src="uploads/<?php echo htmlspecialchars($photo['filename']); ?>" 
                                             alt="<?php echo htmlspecialchars($photo['title']); ?>" 
                                             class="w-16 h-12 object-cover rounded-md">
                                        <div class="ml-3 flex-1 min-w-0">
                                            <h3 class="text-sm font-medium text-gray-800 truncate"><?php echo htmlspecialchars($photo['title']); ?></h3>
                                            <p class="text-xs text-gray-500 mt-0.5">
                                                机型: <?php echo htmlspecialchars($photo['aircraft_model'] ?? '未知'); ?> · 
                                                上传者: <?php echo htmlspecialchars($photo['uploader_username']); ?>
                                            </p>
                                        </div>
                                        <?php if(isset($_GET['photo_id']) && $_GET['photo_id'] == $photo['id']): ?>
                                            <i class="fa fa-check-circle text-primary ml-2"></i>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>

                        <?php 
                        // 我的抢单列表
                        elseif($active_tab == 'my-grabbed' && empty($my_grabbed_photos)): ?>
                            <div class="text-center py-8 text-gray-500 text-sm">
                                <i class="fa fa-hand-sparkles text-3xl text-warning/30 mb-2"></i>
                                <p>您暂无抢单的图片</p>
                            </div>
                        <?php elseif($active_tab == 'my-grabbed'): ?>
                            <?php foreach($my_grabbed_photos as $photo): ?>
                                <a href="admin_review.php?tab=my-grabbed&page=<?php echo $page; ?>&photo_id=<?php echo $photo['id']; ?>" 
                                   class="block p-3 rounded-lg border <?php echo (isset($_GET['photo_id']) && $_GET['photo_id'] == $photo['id']) ? 'border-primary bg-primary/5' : 'border-gray-200 hover:border-primary/30 hover:bg-gray-50'; ?> transition-all">
                                    <div class="flex items-center">
                                        <img src="uploads/<?php echo htmlspecialchars($photo['filename']); ?>" 
                                             alt="<?php echo htmlspecialchars($photo['title']); ?>" 
                                             class="w-16 h-12 object-cover rounded-md">
                                        <div class="ml-3 flex-1 min-w-0">
                                            <h3 class="text-sm font-medium text-gray-800 truncate"><?php echo htmlspecialchars($photo['title']); ?></h3>
                                            <p class="text-xs text-gray-500 mt-0.5">
                                                机型: <?php echo htmlspecialchars($photo['aircraft_model'] ?? '未知'); ?> · 
                                                抢单时间: <?php echo substr($photo['created_at'], 0, 16); ?>
                                            </p>
                                        </div>
                                        <?php if(isset($_GET['photo_id']) && $_GET['photo_id'] == $photo['id']): ?>
                                            <i class="fa fa-check-circle text-primary ml-2"></i>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>

                        <?php 
                        // 申诉处理列表
                        elseif($active_tab == 'appeal' && empty($appealing_photos)): ?>
                            <div class="text-center py-8 text-gray-500 text-sm">
                                <i class="fa fa-balance-scale text-3xl text-danger/30 mb-2"></i>
                                <p>暂无需要处理的申诉</p>
                            </div>
                        <?php elseif($active_tab == 'appeal'): ?>
                            <?php foreach($appealing_photos as $photo): ?>
                                <a href="admin_review.php?tab=appeal&page=<?php echo $page; ?>&photo_id=<?php echo $photo['id']; ?>" 
                                   class="block p-3 rounded-lg border <?php echo (isset($_GET['photo_id']) && $_GET['photo_id'] == $photo['id']) ? 'border-primary bg-primary/5' : 'border-gray-200 hover:border-primary/30 hover:bg-gray-50'; ?> transition-all">
                                    <div class="flex items-center">
                                        <img src="uploads/<?php echo htmlspecialchars($photo['filename']); ?>" 
                                             alt="<?php echo htmlspecialchars($photo['title']); ?>" 
                                             class="w-16 h-12 object-cover rounded-md">
                                        <div class="ml-3 flex-1 min-w-0">
                                            <h3 class="text-sm font-medium text-gray-800 truncate"><?php echo htmlspecialchars($photo['title']); ?></h3>
                                            <p class="text-xs text-gray-500 mt-0.5">
                                                申诉人: <?php echo htmlspecialchars($photo['uploader_username']); ?> · 
                                                申诉时间: <?php echo substr($photo['created_at'], 0, 16); ?>
                                            </p>
                                        </div>
                                        <?php if(isset($_GET['photo_id']) && $_GET['photo_id'] == $photo['id']): ?>
                                            <i class="fa fa-check-circle text-primary ml-2"></i>
                                        <?php endif; ?>
                                    </div>
                                </a>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>

                    <!-- 分页控件：根据当前标签显示对应分页 -->
                    <?php 
                    $total_pages = $active_tab == 'pending' ? $total_pending_pages : 
                                   ($active_tab == 'my-grabbed' ? $total_my_grabbed_pages : $total_appeal_pages);
                    if($total_pages > 1): ?>
                        <div class="mt-6 flex justify-center">
                            <nav class="flex items-center space-x-1">
                                <a href="admin_review.php?tab=<?php echo $active_tab; ?>&page=<?php echo max(1, $page - 1); ?>" 
                                   class="px-3 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm <?php echo $page == 1 ? 'opacity-50 cursor-not-allowed' : ''; ?>">
                                    <i class="fa fa-chevron-left text-xs"></i>
                                </a>
                                
                                <?php for($i = 1; $i <= $total_pages; $i++): ?>
                                    <a href="admin_review.php?tab=<?php echo $active_tab; ?>&page=<?php echo $i; ?>" 
                                       class="px-3 py-1 rounded text-sm <?php echo $page == $i ? 'bg-primary text-white' : 'border border-gray-300 text-gray-600 hover:bg-gray-50'; ?>">
                                        <?php echo $i; ?>
                                    </a>
                                <?php endfor; ?>
                                
                                <a href="admin_review.php?tab=<?php echo $active_tab; ?>&page=<?php echo min($total_pages, $page + 1); ?>" 
                                   class="px-3 py-1 rounded border border-gray-300 text-gray-600 hover:bg-gray-50 text-sm <?php echo $page == $total_pages ? 'opacity-50 cursor-not-allowed' : ''; ?>">
                                    <i class="fa fa-chevron-right text-xs"></i>
                                </a>
                            </nav>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 右侧：图片详情 + 审核工具（参考铁路页面） -->
            <div class="lg:col-span-2">
                <?php if($current_photo): ?>
                    <!-- 1. 图片详情区 -->
                    <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                        <div class="flex flex-col md:flex-row md:items-start md:space-x-6">
                            <!-- 图片预览（带放大/缩小/旋转功能） -->
                            <div class="w-full md:w-2/3 mb-6 md:mb-0">
                                <div class="photo-frame">
                                    <img id="previewImg" src="uploads/<?php echo htmlspecialchars($current_photo['filename']); ?>" 
                                         alt="<?php echo htmlspecialchars($current_photo['title']); ?>" 
                                         class="w-full h-auto object-contain transition-transform duration-300" style="transform: scale(1) rotate(0deg);">
                                </div>
                                
                                <!-- 图片操作工具（参考铁路页面） -->
                                <div class="mt-4 flex flex-wrap gap-3">
                                    <button id="zoomIn" class="px-3 py-1 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 flex items-center text-sm">
                                        <i class="fa fa-search-plus mr-1"></i> 放大
                                    </button>
                                    <button id="zoomOut" class="px-3 py-1 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 flex items-center text-sm">
                                        <i class="fa fa-search-minus mr-1"></i> 缩小
                                    </button>
                                    <button id="rotate" class="px-3 py-1 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 flex items-center text-sm">
                                        <i class="fa fa-rotate-right mr-1"></i> 旋转
                                    </button>
                                    <a href="uploads/<?php echo htmlspecialchars($current_photo['filename']); ?>" target="_blank" class="px-3 py-1 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 flex items-center text-sm">
                                        <i class="fa fa-download mr-1"></i> 下载原图
                                    </a>
                                    <!-- 删除按钮 -->
                                    <form method="post" action="admin_review.php" style="display: inline;" 
                                          onsubmit="return confirm('确定要永久删除这张图片吗？此操作不可恢复！');">
                                        <input type="hidden" name="photo_id" value="<?php echo $current_photo['id']; ?>">
                                        <button type="submit" name="delete_photo" class="px-3 py-1 border border-danger text-danger rounded-md hover:bg-danger/5 flex items-center text-sm">
                                            <i class="fa fa-trash mr-1"></i> 删除图片
                                        </button>
                                    </form>
                                </div>
                            </div>
                            
                            <!-- 图片信息（保留航空摄影字段：机型、注册号等） -->
                            <div class="w-full md:w-1/3">
                                <h2 class="text-xl font-bold text-gray-800 mb-2"><?php echo htmlspecialchars($current_photo['title']); ?></h2>
                                
                                <!-- 上传者信息 -->
                                <div class="flex items-center mb-4">
                                    <img src="https://picsum.photos/seed/<?php echo $current_photo['user_id']; ?>/100" 
                                         alt="<?php echo htmlspecialchars($current_photo['uploader_username']); ?>" 
                                         class="w-8 h-8 rounded-full border border-gray-200">
                                    <span class="ml-2 text-sm text-gray-600">
                                        上传者: <?php echo htmlspecialchars($current_photo['uploader_username']); ?>
                                    </span>
                                </div>
                                
                                <!-- 核心信息列表 -->
                                <div class="space-y-3 text-sm">
                                    <div class="flex">
                                        <span class="info-label">上传时间:</span>
                                        <span class="text-gray-800"><?php echo substr($current_photo['created_at'], 0, 16); ?></span>
                                    </div>
                                    <div class="flex">
                                        <span class="info-label">文件格式:</span>
                                        <span class="text-gray-800"><?php echo strtoupper(pathinfo($current_photo['filename'], PATHINFO_EXTENSION)); ?></span>
                                    </div>
                                    <div class="flex">
                                        <span class="info-label">文件大小:</span>
                                        <span class="text-gray-800"><?php echo number_format(filesize('uploads/'.$current_photo['filename']) / 1024 / 1024, 2); ?> MB</span>
                                    </div>
                                    <div class="flex">
                                        <span class="info-label">航空机型:</span>
                                        <span class="text-gray-800"><?php echo htmlspecialchars($current_photo['aircraft_model'] ?? '未填写'); ?></span>
                                    </div>
                                    <div class="flex">
                                        <span class="info-label">注册号:</span>
                                        <span class="text-gray-800"><?php echo htmlspecialchars($current_photo['registration_number'] ?? '未填写'); ?></span>
                                    </div>
                                    <div class="flex">
                                        <span class="info-label">拍摄地点:</span>
                                        <span class="text-gray-800"><?php echo htmlspecialchars($current_photo['拍摄地点'] ?? '未填写'); ?></span>
                                    </div>
                                    <div class="flex">
                                        <span class="info-label">图片分类:</span>
                                        <span class="text-gray-800"><?php echo htmlspecialchars($current_photo['category'] ?? '未分类'); ?></span>
                                    </div>
                                    <div class="flex">
                                        <span class="info-label">审核员:</span>
                                        <span class="text-gray-800"><?php echo htmlspecialchars($current_photo['reviewer_username'] ?? '未分配'); ?></span>
                                    </div>
                                </div>
                                
                                <!-- 图片描述 -->
                                <div class="mt-6">
                                    <h3 class="text-sm font-semibold text-gray-800 mb-2">图片描述</h3>
                                    <p class="text-sm text-gray-600 bg-gray-50 p-3 rounded-lg min-h-[80px]">
                                        <?php echo htmlspecialchars($current_photo['description'] ?? '无描述信息'); ?>
                                    </p>
                                </div>

                                <!-- 申诉相关信息（仅申诉标签显示） -->
                                <?php if($active_tab == 'appeal' && $current_photo['appeal_id']): ?>
                                    <div class="mt-6 space-y-4">
                                        <!-- 原拒绝理由 -->
                                        <div class="bg-danger/5 p-3 rounded-lg border border-danger/20">
                                            <h4 class="text-sm font-semibold text-danger mb-1">原拒绝理由</h4>
                                            <p class="text-sm text-gray-700">
                                                <?php echo htmlspecialchars($current_photo['rejection_reason'] ?? '无'); ?>
                                            </p>
                                        </div>
                                        <!-- 申诉内容 -->
                                        <div class="bg-primary/5 p-3 rounded-lg border border-primary/20">
                                            <h4 class="text-sm font-semibold text-primary mb-1">用户申诉内容</h4>
                                            <p class="text-sm text-gray-700">
                                                <?php echo htmlspecialchars($current_photo['appeal_content'] ?? '无'); ?>
                                            </p>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 2. 审核工具区（根据标签显示不同操作） -->
                    <div class="review-tool">
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">
                            <?php 
                            if($active_tab == 'pending') echo '抢单与审核操作';
                            elseif($active_tab == 'my-grabbed') echo '我的抢单审核';
                            else echo '申诉处理操作';
                            ?>
                        </h3>
                        
                        <!-- 待抢单标签：显示抢单按钮 + 直接审核 -->
                        <?php if($active_tab == 'pending'): ?>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-5">
                                <!-- 抢单表单 -->
                                <form method="post" action="admin_review.php">
                                    <input type="hidden" name="photo_id" value="<?php echo $current_photo['id']; ?>">
                                    <button type="submit" name="grab_review" class="btn-action bg-warning text-white shadow-md hover:bg-warning/90 focus:ring-warning w-full">
                                        <i class="fa fa-hand-paper-o mr-2"></i> 抢单审核
                                    </button>
                                </form>
                                <!-- 直接通过（无需抢单） -->
                                <form method="post" action="admin_review.php">
                                    <input type="hidden" name="photo_id" value="<?php echo $current_photo['id']; ?>">
                                    <button type="submit" name="approve" class="btn-action bg-success text-white shadow-md hover:bg-success/90 focus:ring-success w-full">
                                        <i class="fa fa-check-circle mr-2"></i> 直接通过
                                    </button>
                                </form>
                            </div>
                        <?php endif; ?>
                        
                        <!-- 拒绝/申诉处理表单（带快速理由选择） -->
                        <form method="post" action="admin_review.php" id="reviewForm">
                            <input type="hidden" name="photo_id" value="<?php echo $current_photo['id']; ?>">
                            <?php if($active_tab == 'appeal' && $current_photo['appeal_id']): ?>
                                <input type="hidden" name="appeal_id" value="<?php echo $current_photo['appeal_id']; ?>">
                            <?php endif; ?>
                            
                            <!-- 快速理由选择（保留原航空相关理由） -->
                            <div class="mb-4">
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    <?php 
                                    if($active_tab == 'appeal') echo '快速选择申诉处理理由（可多选）';
                                    else echo '快速选择拒绝理由（可多选）';
                                    ?>
                                </label>
                                <select id="quickReason" multiple 
                                        class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50 text-sm"
                                        onchange="fillReason()">
                                    <option value="">请选择（可选，可多选）</option>
                                    <option value="水印遮挡">水印遮挡</option>
                                    <option value="热变形">热变形</option>
                                    <option value="脏点">脏点</option>
                                    <option value="偏上/偏下/偏左/偏右">构图偏移（偏上/偏下/偏左/偏右）</option>
                                    <option value="对比度不适宜">对比度不适宜</option>
                                    <option value="锐度不佳">锐度不佳</option>
                                    <option value="尺寸问题">尺寸问题</option>
                                    <option value="噪点过多">噪点过多</option>
                                    <option value="过度处理">过度处理</option>
                                    <option value="遮挡">遮挡</option>
                                    <option value="暗角">暗角</option>
                                    <option value="注册号错误">注册号错误</option>
                                    <option value="机型信息错误">机型信息错误</option>
                                    <option value="标题不符合要求">标题不符合航空主题</option>
                                    <option value="图片不符合要求">图片不符合航空主题</option>
                                    <option value="机型无后缀">机型无后缀（如Boeing 737-800）</option>
                                    <option value="缺少制造商">缺少制造商（如Airbus、Boeing）</option>
                                    <option value="太模糊">太模糊</option>
                                    <option value="逆光/过曝">逆光/过曝</option>
                                    <option value="地平线不正">地平线不正</option>
                                    <option value="颜色/亮度不佳">颜色/亮度不佳</option>
                                    <option value="重复上传">重复上传</option>
                                </select>
                            </div>
                            
                            <!-- 详细理由输入框 -->
                            <div class="mb-5">
                                <label for="reason" class="block text-sm font-medium text-gray-700 mb-2">
                                    <?php 
                                    if($active_tab == 'appeal') echo '申诉处理理由（拒绝时必填）';
                                    else echo '拒绝理由（必填）';
                                    ?>
                                </label>
                                <textarea id="reason" name="<?php echo $active_tab == 'appeal' ? 'appeal_reason' : 'reason'; ?>" rows="3" 
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50 text-sm"
                                          placeholder="<?php 
                                          if($active_tab == 'appeal') echo '拒绝申诉请说明理由，通过则可不填...';
                                          else echo '请输入拒绝理由，快速选择后可补充...';
                                          ?>"></textarea>
                            </div>
                            
                            <!-- 管理员留言（可选） -->
                            <div class="mb-6">
                                <label for="admin_comment" class="block text-sm font-medium text-gray-700 mb-2">管理员留言（可选，给用户的建议）</label>
                                <textarea id="admin_comment" name="admin_comment" rows="2" 
                                          class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-primary/50 text-sm"
                                          placeholder="例如：建议调整图片亮度，补充完整机型信息（如Airbus A320neo）后重新上传..."></textarea>
                            </div>
                            
                            <!-- 操作按钮（根据标签显示不同按钮） -->
                            <div class="flex flex-wrap gap-4 justify-between">
                                <?php if($active_tab == 'appeal'): ?>
                                    <!-- 申诉处理按钮 -->
                                    <div class="flex gap-4">
                                        <button type="submit" name="appeal_review" value="approve" 
                                                class="btn-action bg-success text-white shadow-md hover:bg-success/90 focus:ring-success">
                                            <i class="fa fa-check-circle mr-2"></i> 通过申诉
                                        </button>
                                        <button type="submit" name="appeal_review" value="reject" 
                                                class="btn-action bg-danger text-white shadow-md hover:bg-danger/90 focus:ring-danger">
                                            <i class="fa fa-times-circle mr-2"></i> 拒绝申诉
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <!-- 普通审核按钮 -->
                                    <button type="submit" name="approve" 
                                            class="btn-action bg-success text-white shadow-md hover:bg-success/90 focus:ring-success">
                                        <i class="fa fa-check-circle mr-2"></i> 通过审核
                                    </button>
                                    <button type="submit" name="reject" 
                                            class="btn-action bg-danger text-white shadow-md hover:bg-danger/90 focus:ring-danger">
                                        <i class="fa fa-times-circle mr-2"></i> 拒绝审核
                                    </button>
                                <?php endif; ?>
                                
                                <!-- 跳过按钮 -->
                                <button type="button" 
                                        class="btn-action border border-gray-300 text-gray-700 hover:bg-gray-50"
                                        onclick="window.location='admin_review.php?tab=<?php echo $active_tab; ?>&page=<?php echo $page; ?>'">
                                    <i class="fa fa-arrow-right mr-2"></i> 跳过
                                </button>
                            </div>
                        </form>
                        
                        <!-- 快捷备注按钮（参考铁路页面） -->
                        <div class="mt-6 pt-6 border-t border-gray-100">
                            <h4 class="text-sm font-semibold text-gray-700 mb-3">快捷备注</h4>
                            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                                <button class="comment-btn px-3 py-2 border border-gray-200 rounded-md text-sm text-gray-700 hover:bg-gray-50"
                                        data-text="图片清晰，机型/注册号信息完整，符合航空主题">主题合格</button>
                                <button class="comment-btn px-3 py-2 border border-gray-200 rounded-md text-sm text-gray-700 hover:bg-gray-50"
                                        data-text="图片模糊，细节不清晰，不符合发布标准">图片模糊</button>
                                <button class="comment-btn px-3 py-2 border border-gray-200 rounded-md text-sm text-gray-700 hover:bg-gray-50"
                                        data-text="机型/注册号信息缺失，需补充后重新上传">信息缺失</button>
                                <button class="comment-btn px-3 py-2 border border-gray-200 rounded-md text-sm text-gray-700 hover:bg-gray-50"
                                        data-text="内容与航空无关，不符合平台主题要求">无关内容</button>
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <!-- 未选择图片时显示 -->
                    <div class="bg-white rounded-xl shadow-sm p-10 text-center h-[calc(100vh-280px)] flex flex-col items-center justify-center">
                        <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-gray-100 text-gray-400 mb-4">
                            <i class="fa fa-picture-o text-2xl"></i>
                        </div>
                        <h3 class="text-lg font-medium text-gray-900 mb-2">请选择一张图片进行审核</h3>
                        <p class="text-gray-500 max-w-md mx-auto text-sm">
                            从左侧列表中选择待审核的航空图片（待抢单/我的抢单/申诉），右侧将显示完整图片详情与审核工具
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <!-- 页脚：保留航空摄影平台信息 -->
    <footer class="bg-gray-800 text-white mt-16 py-8">
        <div class="container mx-auto px-4">
            <div class="text-center text-gray-400 text-sm">
                <p>© 2025 syphotos 航空摄影图库 - 管理员审核系统</p>
            </div>
        </div>
    </footer>

    <!-- 功能脚本：图片操作 + 快速理由填充 + 快捷备注 -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 1. 图片放大/缩小/旋转功能（参考铁路页面）
            const previewImg = document.getElementById('previewImg');
            const zoomIn = document.getElementById('zoomIn');
            const zoomOut = document.getElementById('zoomOut');
            const rotateBtn = document.getElementById('rotate');
            let scale = 1;
            let rotation = 0;

            if (previewImg && zoomIn && zoomOut && rotateBtn) {
                zoomIn.addEventListener('click', function() {
                    scale += 0.1;
                    updateImgTransform();
                });

                zoomOut.addEventListener('click', function() {
                    if (scale > 0.5) {
                        scale -= 0.1;
                        updateImgTransform();
                    }
                });

                rotateBtn.addEventListener('click', function() {
                    rotation += 90;
                    updateImgTransform();
                });

                function updateImgTransform() {
                    previewImg.style.transform = `scale(${scale}) rotate(${rotation}deg)`;
                }
            }

            // 2. 快速理由选择填充到文本框
            function fillReason() {
                const select = document.getElementById('quickReason');
                const reasonTextarea = document.getElementById('reason');
                if (!select || !reasonTextarea) return;

                // 获取选中的理由（去重、过滤空值）
                const selectedReasons = Array.from(select.selectedOptions)
                    .map(option => option.value.trim())
                    .filter(reason => reason);
                
                // 拼接理由（用“、”分隔）
                if (selectedReasons.length > 0) {
                    const reasonStr = selectedReasons.join('、');
                    // 若文本框为空，则直接填充；否则保留用户输入
                    if (!reasonTextarea.value.trim()) {
                        reasonTextarea.value = reasonStr;
                    }
                }
            }

            // 3. 快捷备注按钮填充
            document.querySelectorAll('.comment-btn').forEach(btn => {
                btn.addEventListener('click', function() {
                    const commentInput = document.getElementById('admin_comment');
                    if (commentInput) {
                        commentInput.value = this.getAttribute('data-text');
                        commentInput.focus();
                    }
                });
            });

            // 4. 表单提交验证（申诉拒绝时理由必填）
            const reviewForm = document.getElementById('reviewForm');
            if (reviewForm) {
                reviewForm.addEventListener('submit', function(e) {
                    const isAppealReject = e.submitter && e.submitter.value === 'reject';
                    const reasonInput = document.getElementById('reason');
                    
                    // 申诉拒绝时验证理由
                    if (isAppealReject && !reasonInput.value.trim()) {
                        alert('拒绝申诉必须填写理由');
                        e.preventDefault();
                        reasonInput.focus();
                    }
                });
            }
        });
    </script>
</body>
</html>