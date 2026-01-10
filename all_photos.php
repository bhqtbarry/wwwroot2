<?php
require 'db_connect.php';
session_start();

// 如果用户已登录，更新活动时间
if(isset($_SESSION['user_id'])) {
    // 假设这个函数在stats_functions.php中定义，如果没有可以注释掉
    // updateUserActivity($_SESSION['user_id']);
}

// 获取所有可用分类
$categories = [];
try {
    $stmt = $pdo->query("SELECT DISTINCT category FROM photos WHERE approved = 1 ORDER BY category");
    $categories = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch(PDOException $e) {
    // 分类获取失败不影响页面主体功能
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horizon Photos - 全部图片</title>
    <style>
        :root {
            --primary: #165DFF;
            --primary-light: #4080FF;
            --primary-dark: #0E42D2;
            --secondary: #69b1ff;
            --accent: #FF7D00;
            --light-bg: #f0f7ff;
            --light-gray: #f5f7fa;
            --medium-gray: #e5e9f2;
            --text-dark: #1d2129;
            --text-medium: #4e5969;
            --text-light: #86909c;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --hover-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            --border-radius: 8px;
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
            overflow-x: hidden;
        }
        
        /* 滚动行为平滑 */
        html {
            scroll-behavior: smooth;
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
            position: sticky;
            top: 0;
            z-index: 100;
            transition: var(--transition);
        }
        
        /* 滚动时导航栏变化 */
        .nav.scrolled {
            padding: 10px 0;
            background-color: rgba(22, 93, 255, 0.95);
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.15);
        }
        
        .nav-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .logo {
            color: white;
            font-size: 1.5rem;
            font-weight: bold;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }
        
        .logo:hover {
            transform: scale(1.05);
        }
        
        .logo-icon {
            font-size: 1.8rem;
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
            position: relative;
        }
        
        .nav a:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
        }
        
        /* 导航链接下划线动画 */
        .nav a::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background-color: white;
            transition: var(--transition);
        }
        
        .nav a:hover::after {
            width: 100%;
        }
        
        .page-header {
            padding: 40px 0;
            background: linear-gradient(135deg, var(--primary-light), var(--primary-dark));
            color: white;
            margin-bottom: 40px;
            position: relative;
            overflow: hidden;
        }
        
        .page-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image: url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='none' fill-rule='evenodd'%3E%3Cg fill='%23ffffff' fill-opacity='0.1'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/g%3E%3C/svg%3E");
            opacity: 0.5;
        }
        
        .header-content {
            position: relative;
            z-index: 1;
        }
        
        .page-title {
            font-size: 2.2rem;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0, 0, 0, 0.15);
        }
        
        .page-description {
            font-size: 1.1rem;
            opacity: 0.9;
            max-width: 800px;
        }
        
        .filter-container {
            background-color: white;
            padding: 25px;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            margin-bottom: 40px;
            transition: var(--transition);
        }
        
        .filter-container:hover {
            box-shadow: var(--hover-shadow);
        }
        
        .filter-header {
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .filter-title {
            font-size: 1.2rem;
            color: var(--primary-dark);
            font-weight: 600;
        }
        
        .filter-form {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            align-items: center;
        }
        
        .form-group {
            flex: 1;
            min-width: 200px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-medium);
        }
        
        .form-control {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid var(--medium-gray);
            border-radius: 6px;
            font-size: 1rem;
            transition: var(--transition);
            background-color: white;
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(22, 93, 255, 0.1);
        }
        
        .btn { 
            background-color: var(--primary); 
            color: white; 
            border: none; 
            padding: 10px 20px; 
            cursor: pointer; 
            border-radius: 50px;
            transition: var(--transition);
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            box-shadow: 0 2px 8px rgba(22, 93, 255, 0.3);
            white-space: nowrap;
        }
        
        .btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-3px);
            box-shadow: 0 4px 12px rgba(22, 93, 255, 0.4);
        }
        
        .btn-reset {
            background-color: var(--light-gray);
            color: var(--text-medium);
            box-shadow: none;
        }
        
        .btn-reset:hover {
            background-color: var(--medium-gray);
            color: var(--text-dark);
        }
        
        .photo-grid { 
            display: grid; 
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); 
            gap: 30px; 
            margin-bottom: 60px;
        }
        
        .photo-item { 
            background-color: white; 
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            position: relative;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .photo-item:hover {
            transform: translateY(-10px);
            box-shadow: var(--hover-shadow);
        }
        
        .photo-category {
            position: absolute;
            top: 15px;
            right: 15px;
            background-color: rgba(22, 93, 255, 0.9);
            color: white;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
            z-index: 10;
            transition: var(--transition);
        }
        
        .photo-item:hover .photo-category {
            background-color: var(--accent);
            transform: scale(1.1);
        }
        
        .photo-img-container {
            height: 220px;
            overflow: hidden;
            position: relative;
        }
        
        .photo-item img { 
            width: 100%; 
            height: 100%; 
            object-fit: cover;
            transition: transform 0.5s ease, filter 0.5s ease;
        }
        
        .photo-item:hover img {
            transform: scale(1.1);
            filter: brightness(1.05);
        }
        
        .photo-info {
            padding: 20px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        
        .photo-title {
            font-size: 1.2rem;
            margin-bottom: 10px;
            color: var(--primary-dark);
            transition: var(--transition);
            line-height: 1.4;
        }
        
        .photo-item:hover .photo-title {
            color: var(--primary);
        }
        
        .photo-meta {
            display: flex;
            flex-direction: column;
            gap: 6px;
            font-size: 0.9rem;
            color: var(--text-medium);
            margin-top: auto;
        }
        
        .photo-meta span {
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .photo-meta i {
            color: var(--primary-light);
            width: 16px;
            text-align: center;
        }
        
        .no-results {
            grid-column: 1 / -1;
            text-align: center;
            padding: 80px 20px;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
        }
        
        .no-results i {
            font-size: 4rem;
            color: var(--text-light);
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .no-results h3 {
            font-size: 1.5rem;
            color: var(--text-medium);
            margin-bottom: 15px;
        }
        
        .no-results p {
            color: var(--text-light);
            max-width: 600px;
            margin: 0 auto;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin: 50px 0;
        }
        
        .pagination-link {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: white;
            color: var(--text-medium);
            text-decoration: none;
            transition: var(--transition);
            box-shadow: var(--card-shadow);
        }
        
        .pagination-link:hover {
            background-color: var(--primary);
            color: white;
            transform: translateY(-3px);
        }
        
        .pagination-link.active {
            background-color: var(--primary);
            color: white;
            font-weight: bold;
        }
        
        .footer {
            background-color: var(--primary-dark);
            color: white;
            padding: 60px 0 30px;
            margin-top: 50px;
        }
        
        .footer-content {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 40px;
            margin-bottom: 40px;
        }
        
        .footer-logo {
            font-size: 1.8rem;
            font-weight: bold;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .footer-logo i {
            font-size: 2rem;
        }
        
        .footer-desc {
            color: rgba(255, 255, 255, 0.8);
            margin-bottom: 20px;
            line-height: 1.7;
        }
        
        .footer-title {
            font-size: 1.2rem;
            margin-bottom: 20px;
            position: relative;
            padding-bottom: 10px;
        }
        
        .footer-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            width: 40px;
            height: 3px;
            background-color: var(--accent);
            border-radius: 2px;
        }
        
        .footer-links {
            list-style: none;
        }
        
        .footer-links li {
            margin-bottom: 12px;
        }
        
        .footer-links a {
            color: rgba(255, 255, 255, 0.8);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .footer-links a:hover {
            color: white;
            padding-left: 5px;
        }
        
        .social-links {
            display: flex;
            gap: 15px;
            margin-top: 20px;
        }
        
        .social-links a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background-color: rgba(255, 255, 255, 0.1);
            color: white;
            transition: var(--transition);
        }
        
        .social-links a:hover {
            background-color: var(--accent);
            transform: translateY(-3px) rotate(10deg);
        }
        
        .copyright {
            text-align: center;
            padding-top: 30px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }
        
        /* 返回顶部按钮 */
        .back-to-top {
            position: fixed;
            bottom: 30px;
            right: 30px;
            width: 50px;
            height: 50px;
            border-radius: 50%;
            background-color: var(--primary);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
            cursor: pointer;
            transition: var(--transition);
            opacity: 0;
            visibility: hidden;
            z-index: 99;
        }
        
        .back-to-top.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }
        
        .back-to-top {
            opacity: 0;
            visibility: hidden;
            transform: translateY(20px);
        }
        
        .results-count {
            margin-bottom: 20px;
            color: var(--text-medium);
            font-size: 0.95rem;
        }
        
        @media (max-width: 768px) {
            .nav-container {
                flex-direction: column;
                gap: 15px;
            }
            
            .nav-links {
                flex-wrap: wrap;
                justify-content: center;
            }
            
            .page-title {
                font-size: 1.8rem;
            }
            
            .filter-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-group {
                width: 100%;
            }
            
            .photo-grid {
                grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
            }
            
            .footer-content {
                grid-template-columns: 1fr;
                text-align: center;
            }
            
            .footer-title::after {
                left: 50%;
                transform: translateX(-50%);
            }
            
            .social-links {
                justify-content: center;
            }
        }
        
        @media (max-width: 480px) {
            .page-header {
                padding: 30px 0;
            }
            
            .page-title {
                font-size: 1.5rem;
            }
            
            .page-description {
                font-size: 1rem;
            }
            
            .filter-container {
                padding: 15px;
            }
            
            .photo-grid {
                grid-template-columns: 1fr;
            }
            
            .pagination-link {
                width: 35px;
                height: 35px;
                font-size: 0.9rem;
            }
        }
        
        /* 动画效果 */
        .fade-in {
            opacity: 0;
            transform: translateY(20px);
            transition: opacity 0.5s ease, transform 0.5s ease;
        }
        
        .fade-in.visible {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
    <!-- 引入Font Awesome图标库 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="nav" id="mainNav">
        <div class="container nav-container">
            <a href="index.php" class="logo">
                <i class="fas fa-plane logo-icon"></i>
                Horizon Photos
            </a>
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-home"></i> 首页</a>
                <a href="all_photos.php" style="background-color: var(--primary-dark);"><i class="fas fa-images"></i> 全部图片</a>
                <?php if(isset($_SESSION['user_id'])): ?>
                    <a href="user_center.php"><i class="fas fa-user"></i> 用户中心</a>
                    <a href="upload.php"><i class="fas fa-upload"></i> 上传图片</a>
                    <?php if($_SESSION['is_admin']): ?>
                        <a href="admin_review.php"><i class="fas fa-tachometer-alt"></i> 管理员后台</a>
                    <?php endif; ?>
                    <a href="logout.php"><i class="fas fa-sign-out-alt"></i> 退出登录</a>
                <?php else: ?>
                    <a href="login.php"><i class="fas fa-sign-in-alt"></i> 登录</a>
                    <a href="register.php"><i class="fas fa-user-plus"></i> 注册</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="page-header">
        <div class="container header-content">
            <h1 class="page-title">全部航空摄影作品</h1>
            <p class="page-description">浏览和探索所有精选的航空摄影作品，发现天空中的精彩瞬间</p>
        </div>
    </div>

    <div class="container">
        <div class="filter-container fade-in">
            <div class="filter-header">
                <h2 class="filter-title">筛选作品</h2>
            </div>
            <form method="get" action="all_photos.php" class="filter-form">
                <div class="form-group">
                    <label for="category" class="form-label">分类</label>
                    <select name="category" id="category" class="form-control">
                        <option value="">全部</option>
                        <?php foreach($categories as $category): ?>
                            <option value="<?php echo htmlspecialchars($category); ?>" 
                                <?php echo isset($_GET['category']) && $_GET['category'] == $category ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($category); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="sort" class="form-label">排序方式</label>
                    <select name="sort" id="sort" class="form-control">
                        <option value="newest" <?php echo (!isset($_GET['sort']) || $_GET['sort'] == 'newest') ? 'selected' : ''; ?>>最新上传</option>
                        <option value="oldest" <?php echo isset($_GET['sort']) && $_GET['sort'] == 'oldest' ? 'selected' : ''; ?>>最早上传</option>
                    </select>
                </div>
                
                <div class="form-group" style="display: flex; align-items: flex-end; gap: 10px;">
                    <button type="submit" class="btn"><i class="fas fa-filter"></i> 筛选</button>
                    <?php if(!empty($_GET)): ?>
                        <a href="all_photos.php" class="btn btn-reset"><i class="fas fa-times"></i> 重置</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <?php
        try {
            // 构建查询语句
            $sql = "SELECT p.*, u.username FROM photos p 
                   JOIN users u ON p.user_id = u.id 
                   WHERE p.approved = 1";
                   
            $params = [];
            // 分类筛选
            if(isset($_GET['category']) && !empty($_GET['category'])) {
                $sql .= " AND p.category = :category";
                $params[':category'] = $_GET['category'];
            }
            
            // 排序方式
            $sort = isset($_GET['sort']) ? $_GET['sort'] : 'newest';
            if($sort == 'oldest') {
                $sql .= " ORDER BY p.created_at ASC";
            } else {
                $sql .= " ORDER BY p.created_at DESC";
            }
            
            // 先获取总数用于显示
            $countSql = "SELECT COUNT(*) FROM (" . $sql . ") as count_query";
            $countStmt = $pdo->prepare($countSql);
            $countStmt->execute($params);
            $totalPhotos = $countStmt->fetchColumn();
            
            echo '<div class="results-count fade-in">找到 ' . $totalPhotos . ' 张符合条件的照片</div>';
            
            // 执行查询获取图片
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            
            $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            if(count($photos) > 0) {
                echo '<div class="photo-grid">';
                
                $counter = 0;
                foreach($photos as $photo) {
                    $counter++;
                    // 为每个图片项添加延迟出现的效果
                    $delay = ($counter % 6) * 0.1;
                    echo '<div class="photo-item fade-in" style="transition-delay: ' . $delay . 's">';
                    echo '<span class="photo-category">' . htmlspecialchars($photo['category']) . '</span>';
                    echo '<a href="photo_detail.php?id=' . $photo['id'] . '">';
                    echo '<div class="photo-img-container">';
                    echo '<img src="uploads/' . htmlspecialchars($photo['filename']) . '" alt="' . htmlspecialchars($photo['title']) . '" loading="lazy">';
                    echo '</div>';
                    echo '</a>';
                    echo '<div class="photo-info">';
                    echo '<h3 class="photo-title">' . htmlspecialchars($photo['title']) . '</h3>';
                    echo '<div class="photo-meta">';
                    echo '<span><i class="fas fa-user"></i> 作者: ' . htmlspecialchars($photo['username']) . '</span>';
                    echo '<span><i class="fas fa-plane"></i> 型号: ' . htmlspecialchars($photo['aircraft_model']) . '</span>';
                    echo '<span><i class="fas fa-calendar-alt"></i> 日期: ' . date('Y-m-d', strtotime($photo['created_at'])) . '</span>';
                    echo '</div>';
                    echo '</div>';
                    echo '</div>';
                }
                
                echo '</div>';
            } else {
                // 没有找到结果
                echo '<div class="no-results fade-in">';
                echo '<i class="fas fa-search"></i>';
                echo '<h3>未找到符合条件的图片</h3>';
                echo '<p>尝试调整筛选条件，或者浏览其他类别的航空摄影作品。</p>';
                echo '<a href="all_photos.php" class="btn" style="margin-top: 20px;"><i class="fas fa-th"></i> 查看全部图片</a>';
                echo '</div>';
            }
        } catch(PDOException $e) {
            echo '<div style="background-color: #fff3cd; color: #856404; padding: 15px; border-radius: var(--border-radius); margin-bottom: 30px;">';
            echo '获取图片失败: ' . $e->getMessage();
            echo '</div>';
        }
        ?>
    </div>
    
    <!-- 页脚 -->
    <footer class="footer">
        <div class="container">
            <div class="footer-content">
                <div class="footer-about">
                    <div class="footer-logo">
                        <i class="fas fa-plane"></i>
                        Horizon Photos
                    </div>
                    <p class="footer-desc">
                        专注于航空摄影作品的分享与交流平台，连接全球航空摄影爱好者，
                        记录每一个精彩的飞行瞬间，探索天空中的无限可能。
                    </p>
                    <div class="social-links">
                        <a href="#"><i class="fab fa-weibo"></i></a>
                        <a href="#"><i class="fab fa-wechat"></i></a>
                        <a href="#"><i class="fab fa-instagram"></i></a>
                        <a href="#"><i class="fab fa-twitter"></i></a>
                    </div>
                </div>
                
                <div class="footer-links-container">
                    <h3 class="footer-title">快速链接</h3>
                    <ul class="footer-links">
                        <li><a href="index.php">首页</a></li>
                        <li><a href="all_photos.php">全部作品</a></li>
                        <li><a href="#">摄影技巧</a></li>
                        <li><a href="#">关于我们</a></li>
                        <li><a href="#">联系我们</a></li>
                    </ul>
                </div>
                
                <div class="footer-links-container">
                    <h3 class="footer-title">帮助中心</h3>
                    <ul class="footer-links">
                        <li><a href="#">使用指南</a></li>
                        <li><a href="#">常见问题</a></li>
                        <li><a href="#">用户协议</a></li>
                        <li><a href="#">隐私政策</a></li>
                        <li><a href="#">版权说明</a></li>
                    </ul>
                </div>
            </div>
            
            <div class="copyright">
                &copy; <?php echo date('Y'); ?> Horizon Photos - 保留所有权利
            </div>
        </div>
    </footer>
    
    <!-- 返回顶部按钮 -->
    <div class="back-to-top" id="backToTop">
        <i class="fas fa-arrow-up"></i>
    </div>

    <script>
        // 导航栏滚动效果
        window.addEventListener('scroll', function() {
            const nav = document.getElementById('mainNav');
            const backToTop = document.getElementById('backToTop');
            
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
                backToTop.classList.add('show');
            } else {
                nav.classList.remove('scrolled');
                backToTop.classList.remove('show');
            }
            
            // 滚动时触发元素淡入效果
            const fadeElements = document.querySelectorAll('.fade-in');
            fadeElements.forEach(element => {
                const elementTop = element.getBoundingClientRect().top;
                const elementVisible = 150;
                if (elementTop < window.innerHeight - elementVisible) {
                    element.classList.add('visible');
                }
            });
        });
        
        // 返回顶部功能
        document.getElementById('backToTop').addEventListener('click', function() {
            window.scrollTo({
                top: 0,
                behavior: 'smooth'
            });
        });
        
        // 页面加载完成后初始化动画
        window.addEventListener('load', function() {
            // 触发初始滚动检查以显示可见元素
            window.dispatchEvent(new Event('scroll'));
        });
    </script>
</body>
</html>