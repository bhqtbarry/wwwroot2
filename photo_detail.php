<?php
require 'db_connect.php';
session_start();

// 初始化变量
$photo_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$photo = null;
$is_liked = false;
$reviewer_name = '未审核'; // 默认未审核
// 确定用户权限状态
$is_admin = isset($_SESSION['is_admin']) && $_SESSION['is_admin'] ? true : false;
$current_user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 0;

// 处理点赞请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['like']) && $photo_id > 0) {
    // 检查用户是否登录
    if (!isset($_SESSION['user_id'])) {
        $_SESSION['like_error'] = "请先登录再点赞";
    } else {
        try {
            // 检查是否已点赞
            $stmt = $pdo->prepare("SELECT id FROM photo_likes WHERE user_id = :user_id AND photo_id = :photo_id");
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->bindParam(':photo_id', $photo_id);
            $stmt->execute();
            
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                // 取消点赞
                $delete_stmt = $pdo->prepare("DELETE FROM photo_likes WHERE user_id = :user_id AND photo_id = :photo_id");
                $delete_stmt->bindParam(':user_id', $_SESSION['user_id']);
                $delete_stmt->bindParam(':photo_id', $photo_id);
                $delete_stmt->execute();
                
                // 减少点赞数
                $update_stmt = $pdo->prepare("UPDATE photos SET likes = likes - 1 WHERE id = :id");
                $update_stmt->bindParam(':id', $photo_id);
                $update_stmt->execute();
                
                $_SESSION['like_success'] = "已取消点赞";
            } else {
                // 执行点赞
                $insert_stmt = $pdo->prepare("INSERT INTO photo_likes (user_id, photo_id, created_at) VALUES (:user_id, :photo_id, NOW())");
                $insert_stmt->bindParam(':user_id', $_SESSION['user_id']);
                $insert_stmt->bindParam(':photo_id', $photo_id);
                $insert_stmt->execute();
                
                // 增加点赞数
                $update_stmt = $pdo->prepare("UPDATE photos SET likes = likes + 1 WHERE id = :id");
                $update_stmt->bindParam(':id', $photo_id);
                $update_stmt->execute();
                
                $_SESSION['like_success'] = "点赞成功！";
            }
        } catch (PDOException $e) {
            $_SESSION['like_error'] = "操作失败：" . $e->getMessage();
        }
    }
    
    // 重定向防止表单重复提交
    header("Location: photo_detail.php?id=" . $photo_id);
    exit;
}

// 获取图片详情并更新浏览量（限制同一用户1小时内只增一次）
if ($photo_id > 0) {
    try {
        // 检查是否已浏览过（1小时内）
        $view_key = "viewed_photo_" . $photo_id;
        if (!isset($_COOKIE[$view_key])) {
            // 根据用户权限更新浏览量
            if ($is_admin) {
                // 管理员：更新所有图片浏览量
                $update_views_sql = "UPDATE photos SET views = views + 1 WHERE id = :id";
            } elseif ($current_user_id > 0) {
                // 登录用户：更新自己的图片或已通过的图片
                $update_views_sql = "UPDATE photos SET views = views + 1 WHERE id = :id AND (approved = 1 OR user_id = :user_id)";
            } else {
                // 未登录：只更新已通过的图片
                $update_views_sql = "UPDATE photos SET views = views + 1 WHERE id = :id AND approved = 1";
            }
            
            $stmt = $pdo->prepare($update_views_sql);
            $stmt->bindParam(':id', $photo_id);
            if (!$is_admin && $current_user_id > 0) {
                $stmt->bindParam(':user_id', $current_user_id);
            }
            $stmt->execute();
            
            // 设置Cookie（1小时内不再计数）
            setcookie($view_key, '1', time() + 3600, '/');
        }
        
        // 根据用户权限获取图片详情（包含审核员信息）
        if ($is_admin) {
            // 管理员：查看所有图片，包含审核员信息
            $sql = "SELECT p.*, u.username as author_name, r.username as reviewer_name 
                    FROM photos p 
                    JOIN users u ON p.user_id = u.id 
                    LEFT JOIN users r ON p.reviewer_id = r.id 
                    WHERE p.id = :id";
        } elseif ($current_user_id > 0) {
            // 登录用户：查看自己的图片或已通过的图片
            $sql = "SELECT p.*, u.username as author_name, r.username as reviewer_name 
                    FROM photos p 
                    JOIN users u ON p.user_id = u.id 
                    LEFT JOIN users r ON p.reviewer_id = r.id 
                    WHERE p.id = :id AND (p.approved = 1 OR p.user_id = :user_id)";
        } else {
            // 未登录：只看已通过的图片
            $sql = "SELECT p.*, u.username as author_name, r.username as reviewer_name 
                    FROM photos p 
                    JOIN users u ON p.user_id = u.id 
                    LEFT JOIN users r ON p.reviewer_id = r.id 
                    WHERE p.id = :id AND p.approved = 1";
        }
        
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':id', $photo_id);
        if (!$is_admin && $current_user_id > 0) {
            $stmt->bindParam(':user_id', $current_user_id);
        }
        $stmt->execute();
        
        $photo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // 获取审核员名称
        if ($photo && !empty($photo['reviewer_name'])) {
            $reviewer_name = $photo['reviewer_name'];
        } elseif ($photo && $photo['approved'] == 1) {
            $reviewer_name = '系统自动审核'; // 如无审核员ID但已通过，可显示此提示
        }
        
        // 检查是否已点赞
        if (isset($_SESSION['user_id']) && $photo) {
            $stmt = $pdo->prepare("SELECT id FROM photo_likes WHERE user_id = :user_id AND photo_id = :photo_id");
            $stmt->bindParam(':user_id', $_SESSION['user_id']);
            $stmt->bindParam(':photo_id', $photo_id);
            $stmt->execute();
            
            $is_liked = $stmt->fetch(PDO::FETCH_ASSOC) ? true : false;
        }
    } catch (PDOException $e) {
        echo "<div class='error-message'>获取图片详情失败: " . $e->getMessage() . "</div>";
    }
}

// 处理图片不存在或无权查看的情况
if (!$photo) {
    echo "<div class='error-container'>";
    echo "<div class='error-icon'><i class='fas fa-exclamation-triangle'></i></div>";
    echo "<h2>图片不存在或您没有查看权限</h2>";
    echo "<a href='index.php' class='back-btn'>返回首页</a>";
    echo "</div>";
    exit;
}

// 获取当前页面URL（用于分享）
$current_url = "http://" . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta property="og:title" content="<?php echo htmlspecialchars($photo['title']); ?> - Horizon Photos">
    <meta property="og:image" content="http://<?php echo $_SERVER['HTTP_HOST']; ?>/uploads/<?php echo htmlspecialchars($photo['filename']); ?>">
    <meta property="og:type" content="article">
    <meta property="og:url" content="<?php echo htmlspecialchars($current_url); ?>">
    <title><?php echo htmlspecialchars($photo['title']); ?> - Horizon Photos</title>
    <style>
        :root {
            --primary: #165DFF;
            --primary-light: #4080FF;
            --primary-dark: #0E42D2;
            --accent: #FF7D00;
            --light-bg: #f0f7ff;
            --text-dark: #1d2129;
            --text-medium: #4e5969;
            --text-light: #86909c;
            --card-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
            --hover-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
            --border-radius: 12px;
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
            padding-bottom: 50px;
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
        
        .nav.scrolled {
            padding: 10px 0;
            background-color: rgba(22, 93, 255, 0.95);
        }
        
        .nav-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
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
        
        .nav a:hover {
            background-color: var(--primary-dark);
        }
        
        .nav a:hover::after {
            width: 100%;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 20px;
        }
        
        .page-title {
            margin: 30px 0 20px;
            font-size: 1.8rem;
            color: var(--primary-dark);
            position: relative;
            padding-left: 15px;
        }
        
        .page-title::before {
            content: '';
            position: absolute;
            left: 0;
            top: 5px;
            bottom: 5px;
            width: 4px;
            background-color: var(--accent);
            border-radius: 2px;
        }
        
        .photo-detail {
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
            overflow: hidden;
            margin: 0 auto 30px;
            max-width: 1200px;
            transition: var(--transition);
        }
        
        .photo-detail:hover {
            box-shadow: var(--hover-shadow);
        }
        
        .photo-header {
            padding: 30px;
            border-bottom: 1px solid #f0f2f5;
            background-color: white;
            position: relative;
        }
        
        .photo-title {
            font-size: 2rem;
            color: var(--primary-dark);
            margin-bottom: 15px;
            line-height: 1.3;
            position: relative;
            padding-right: 120px;
        }
        
        .status-tag {
            position: absolute;
            top: 50%;
            right: 0;
            transform: translateY(-50%);
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .status-approved {
            background-color: #f6ffed;
            color: #007e33;
            border: 1px solid #b7eb8f;
        }
        
        .status-unapproved {
            background-color: #fff2f2;
            color: #cc0000;
            border: 1px solid #ffccc7;
        }
        
        .photo-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 25px;
            color: var(--text-medium);
            font-size: 0.95rem;
            padding: 10px 0;
            border-bottom: 1px dashed #eee;
            margin-bottom: 15px;
        }
        
        .photo-meta span {
            display: flex;
            align-items: center;
            gap: 8px;
            transition: var(--transition);
        }
        
        .photo-meta span:hover {
            color: var(--primary);
        }
        
        .photo-meta i {
            color: var(--primary-light);
            width: 18px;
            text-align: center;
        }
        
        .photo-description {
            padding: 10px 0;
            color: var(--text-dark);
            line-height: 1.8;
            font-size: 1.05rem;
            border-left: 3px solid var(--primary-light);
            padding-left: 15px;
            margin-top: 10px;
        }
        
        .photo-content {
            padding: 30px;
            display: grid;
            grid-template-columns: 1fr 350px;
            gap: 30px;
        }
        
        @media (max-width: 900px) {
            .photo-content {
                grid-template-columns: 1fr;
            }
        }
        
        .photo-image-container {
            border-radius: var(--border-radius);
            overflow: hidden;
            background-color: #f8f9fa;
            position: relative;
            box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            transition: var(--transition);
        }
        
        .photo-image-container:hover {
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .photo-image {
            width: 100%;
            height: auto;
            display: block;
            transition: transform 0.6s ease;
        }
        
        .photo-image-container:hover .photo-image {
            transform: scale(1.03);
        }
        
        .image-zoom-indicator {
            position: absolute;
            bottom: 15px;
            right: 15px;
            background-color: rgba(0,0,0,0.5);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            display: flex;
            align-items: center;
            gap: 5px;
            opacity: 0;
            transition: var(--transition);
        }
        
        .photo-image-container:hover .image-zoom-indicator {
            opacity: 1;
        }
        
        .photo-sidebar {
            display: flex;
            flex-direction: column;
            gap: 25px;
        }
        
        .info-card {
            background-color: var(--light-bg);
            border-radius: var(--border-radius);
            padding: 25px;
            transition: var(--transition);
            border: 1px solid #e6edff;
        }
        
        .info-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 5px 15px rgba(22, 93, 255, 0.08);
        }
        
        .info-title {
            font-size: 1.2rem;
            color: var(--primary-dark);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary-light);
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-title i {
            color: var(--primary);
        }
        
        .info-list {
            list-style: none;
        }
        
        .info-list li {
            margin-bottom: 15px;
            padding: 8px 0;
            border-bottom: 1px dashed #e0e6ff;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .info-list li:last-child {
            border-bottom: none;
        }
        
        .info-label {
            font-weight: 500;
            color: var(--text-medium);
            flex: 0 0 120px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .info-label i {
            color: var(--primary-light);
            font-size: 0.9rem;
        }
        
        .info-value {
            flex: 1;
            word-break: break-word;
            padding-left: 10px;
            position: relative;
        }
        
        .info-value::before {
            content: '';
            position: absolute;
            left: 0;
            top: 50%;
            transform: translateY(-50%);
            width: 3px;
            height: 3px;
            border-radius: 50%;
            background-color: var(--primary-light);
        }
        
        .action-buttons {
            display: flex;
            flex-direction: column;
            gap: 15px;
        }
        
        .like-btn {
            background-color: white;
            border: 2px solid var(--primary);
            color: var(--primary);
            padding: 14px;
            border-radius: var(--border-radius);
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }
        
        .like-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(22, 93, 255, 0.1), transparent);
            transition: 0.5s;
        }
        
        .like-btn:hover::before {
            left: 100%;
        }
        
        .like-btn:hover {
            background-color: var(--primary);
            color: white;
            transform: translateY(-3px);
            box-shadow: 0 4px 15px rgba(22, 93, 255, 0.2);
        }
        
        .like-btn.liked {
            background-color: var(--primary);
            color: white;
        }
        
        .like-btn.liked:hover {
            background-color: #0a47cc;
        }
        
        .like-btn i {
            font-size: 1.2rem;
            transition: transform 0.3s ease;
        }
        
        .like-btn:hover i {
            transform: scale(1.2);
        }
        
        .share-title {
            font-size: 1rem;
            color: var(--text-medium);
            margin-bottom: 15px;
            text-align: center;
            padding: 0 10px;
            position: relative;
        }
        
        .share-title::before,
        .share-title::after {
            content: '';
            position: absolute;
            top: 50%;
            width: 30%;
            height: 1px;
            background-color: #e0e6ff;
        }
        
        .share-title::before {
            left: 0;
        }
        
        .share-title::after {
            right: 0;
        }
        
        .share-buttons {
            display: flex;
            gap: 12px;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .share-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-size: 1.2rem;
            text-decoration: none;
            transition: var(--transition);
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }
        
        .share-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.2);
            transition: 0.3s;
        }
        
        .share-btn:hover::before {
            left: 100%;
        }
        
        .share-btn:hover {
            transform: translateY(-5px) scale(1.05);
            box-shadow: 0 5px 12px rgba(0, 0, 0, 0.15);
        }
        
        .share-weixin { background-color: #07C160; }
        .share-weibo { background-color: #E6162D; }
        .share-qq { background-color: #12B7F5; }
        .share-link { background-color: var(--primary); }
        
        .operation-buttons {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .edit-btn, .delete-btn {
            flex: 1;
            padding: 12px;
            border-radius: var(--border-radius);
            border: none;
            font-weight: 500;
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        
        .edit-btn {
            background-color: #f0f7ff;
            color: var(--primary);
        }
        
        .edit-btn:hover {
            background-color: #e6f7ff;
            transform: translateY(-2px);
        }
        
        .delete-btn {
            background-color: #fff1f0;
            color: #f5222d;
        }
        
        .delete-btn:hover {
            background-color: #fff2f0;
            transform: translateY(-2px);
        }
        
        .related-section {
            margin: 60px 0 40px;
            padding-top: 30px;
            border-top: 1px solid #f0f2f5;
        }
        
        .section-title {
            font-size: 1.6rem;
            color: var(--primary-dark);
            margin-bottom: 25px;
            padding-bottom: 10px;
            position: relative;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .section-title i {
            color: var(--accent);
        }
        
        .section-title::after {
            content: '';
            position: absolute;
            left: 0;
            bottom: 0;
            width: 60px;
            height: 3px;
            background-color: var(--primary);
            border-radius: 2px;
        }
        
        .related-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 25px;
        }
        
        .related-item {
            border-radius: var(--border-radius);
            overflow: hidden;
            box-shadow: var(--card-shadow);
            transition: var(--transition);
            background-color: white;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        
        .related-item:hover {
            transform: translateY(-8px);
            box-shadow: var(--hover-shadow);
        }
        
        .related-img-container {
            height: 180px;
            overflow: hidden;
            position: relative;
        }
        
        .related-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .related-item:hover .related-img {
            transform: scale(1.1);
        }
        
        .related-category {
            position: absolute;
            top: 10px;
            left: 10px;
            background-color: rgba(22, 93, 255, 0.8);
            color: white;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 500;
        }
        
        .related-info {
            padding: 15px;
            flex: 1;
            display: flex;
            flex-direction: column;
        }
        
        .related-title {
            font-size: 1.05rem;
            color: var(--text-dark);
            text-decoration: none;
            margin-bottom: 10px;
            line-height: 1.4;
            flex: 1;
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            transition: var(--transition);
        }
        
        .related-item:hover .related-title {
            color: var(--primary);
        }
        
        .related-meta {
            display: flex;
            justify-content: space-between;
            font-size: 0.85rem;
            color: var(--text-light);
        }
        
        .related-meta span {
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .alert-message {
            padding: 15px 20px;
            border-radius: var(--border-radius);
            margin: 0 30px 15px;
            font-weight: 500;
            text-align: center;
            transition: all 0.3s ease;
            transform: translateY(0);
            opacity: 1;
        }
        
        .alert-message.hide {
            transform: translateY(-20px);
            opacity: 0;
            height: 0;
            padding: 0;
            margin: 0;
            overflow: hidden;
        }
        
        .alert-success {
            background-color: #f0fff4;
            color: #007e33;
            border: 1px solid #c8e6c9;
        }
        
        .alert-error {
            background-color: #fff4f4;
            color: #cc0000;
            border: 1px solid #ffdddd;
        }
        
        .error-container {
            max-width: 600px;
            margin: 100px auto;
            text-align: center;
            padding: 40px;
            background-color: white;
            border-radius: var(--border-radius);
            box-shadow: var(--card-shadow);
        }
        
        .error-icon {
            font-size: 4rem;
            color: var(--accent);
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.1); }
            100% { transform: scale(1); }
        }
        
        .back-btn {
            display: inline-block;
            margin-top: 20px;
            background-color: var(--primary);
            color: white;
            padding: 10px 20px;
            border-radius: var(--border-radius);
            text-decoration: none;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .back-btn:hover {
            background-color: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: 0 4px 10px rgba(22, 93, 255, 0.2);
        }
        
        footer {
            background-color: var(--primary-dark);
            color: white;
            padding: 40px 0 20px;
            margin-top: 60px;
        }
        
        .copyright {
            text-align: center;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: rgba(255, 255, 255, 0.7);
            font-size: 0.9rem;
        }
        
        /* 微信分享弹窗样式 */
        #weixinModal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            z-index: 1000;
            align-items: center;
            justify-content: center;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        
        #weixinModal.active {
            opacity: 1;
        }
        
        #weixinModal .modal-content {
            background: white;
            padding: 25px;
            border-radius: var(--border-radius);
            text-align: center;
            width: 90%;
            max-width: 300px;
            transform: scale(0.9);
            transition: transform 0.3s ease;
        }
        
        #weixinModal.active .modal-content {
            transform: scale(1);
        }
        
        #weixinModal h3 {
            margin-bottom: 15px;
            color: var(--primary-dark);
            font-size: 1.2rem;
        }
        
        #weixinModal .qrcode-container {
            margin: 0 auto;
            width: 200px;
            height: 200px;
            background: white;
            padding: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-radius: 8px;
        }
        
        #weixinModal img {
            width: 100%;
            height: 100%;
        }
        
        #weixinModal p {
            margin-top: 15px;
            color: var(--text-medium);
            font-size: 0.9rem;
            line-height: 1.6;
        }
        
        #weixinModal .close-btn {
            margin-top: 20px;
            padding: 8px 25px;
            background: var(--primary);
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            transition: var(--transition);
            font-weight: 500;
        }
        
        #weixinModal .close-btn:hover {
            background-color: var(--primary-dark);
        }
    </style>
    <!-- 引入Font Awesome图标库 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body>
    <div class="nav" id="mainNav">
        <div class="nav-container">
            <a href="index.php" class="logo">
                <i class="fas fa-plane logo-icon"></i>
                Horizon Photos
            </a>
            <div class="nav-links">
                <a href="index.php"><i class="fas fa-home"></i> 首页</a>
                <a href="all_photos.php"><i class="fas fa-images"></i> 全部图片</a>
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

    <div class="container">
        <h1 class="page-title">图片详情</h1>
        
        <div class="photo-detail">
            <!-- 提示信息 -->
            <?php if(isset($_SESSION['like_success'])): ?>
                <div class="alert-message alert-success" id="alertMessage">
                    <?php echo $_SESSION['like_success']; unset($_SESSION['like_success']); ?>
                </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['like_error'])): ?>
                <div class="alert-message alert-error" id="alertMessage">
                    <?php echo $_SESSION['like_error']; unset($_SESSION['like_error']); ?>
                </div>
            <?php endif; ?>
            
            <!-- 图片标题和基本信息 -->
            <div class="photo-header">
                <h1 class="photo-title">
                    <?php echo htmlspecialchars($photo['title']); ?>
                    <span class="status-tag <?php echo $photo['approved'] == 1 ? 'status-approved' : 'status-unapproved'; ?>">
                        <?php echo $photo['approved'] == 1 ? '已通过审核' : '未通过审核'; ?>
                    </span>
                </h1>
                
                <div class="photo-meta">
                    <span><i class="fas fa-user"></i> 作者: <?php echo htmlspecialchars($photo['author_name']); ?></span>
                    <span><i class="fas fa-user-check"></i> 审核员: <?php echo htmlspecialchars($reviewer_name); ?></span>
                    <span><i class="fas fa-eye"></i> 浏览: <?php echo $photo['views'] ?? 0; ?></span>
                    <span><i class="fas fa-heart"></i> 点赞: <?php echo $photo['likes'] ?? 0; ?></span>
                    <span><i class="fas fa-calendar"></i> 上传时间: <?php echo date('Y-m-d H:i', strtotime($photo['created_at'])); ?></span>
                </div>
                
                <!-- 图片描述 -->
                <?php if(!empty($photo['description'])): ?>
                <div class="photo-description">
                    <strong>图片描述：</strong><?php echo nl2br(htmlspecialchars($photo['description'])); ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- 图片内容和详情 -->
            <div class="photo-content">
                <div class="photo-image-container">
                    <img src="uploads/<?php echo htmlspecialchars($photo['filename']); ?>" 
                         alt="<?php echo htmlspecialchars($photo['title']); ?>" 
                         class="photo-image">
                    <div class="image-zoom-indicator">
                        <i class="fas fa-search-plus"></i>  hover放大
                    </div>
                </div>
                
                <div class="photo-sidebar">
                    <!-- 图片详细信息 -->
                    <div class="info-card">
                        <h3 class="info-title"><i class="fas fa-info-circle"></i> 详细信息</h3>
                        <ul class="info-list">
                            <li>
                                <span class="info-label"><i class="fas fa-folder"></i> 分类</span>
                                <span class="info-value"><?php echo htmlspecialchars($photo['category']); ?></span>
                            </li>
                            <li>
                                <span class="info-label"><i class="fas fa-plane"></i> 飞机型号</span>
                                <span class="info-value"><?php echo htmlspecialchars($photo['aircraft_model']); ?></span>
                            </li>
                            <li>
                                <span class="info-label"><i class="fas fa-hashtag"></i> 注册号</span>
                                <span class="info-value"><?php echo htmlspecialchars($photo['registration_number']); ?></span>
                            </li>
                            <li>
                                <span class="info-label"><i class="fas fa-clock"></i> 拍摄时间</span>
                                <span class="info-value"><?php echo htmlspecialchars($photo['拍摄时间']); ?></span>
                            </li>
                            <li>
                                <span class="info-label"><i class="fas fa-map-marker-alt"></i> 拍摄地点</span>
                                <span class="info-value"><?php echo htmlspecialchars($photo['拍摄地点']); ?></span>
                            </li>
                            <li>
                                <span class="info-label"><i class="fas fa-camera"></i> 拍摄设备</span>
                                <span class="info-value"><?php echo !empty($photo['camera_model']) ? htmlspecialchars($photo['camera_model']) : '未填写'; ?></span>
                            </li>
                        </ul>
                    </div>
                    
                    <!-- 交互按钮 -->
                    <div class="action-buttons">
                        <!-- 点赞按钮 -->
                        <form method="post">
                            <button type="submit" name="like" class="like-btn <?php echo $is_liked ? 'liked' : ''; ?>">
                                <i class="fas fa-heart"></i>
                                <?php echo $is_liked ? '已点赞' : '点赞'; ?>
                                (<?php echo $photo['likes'] ?? 0; ?>)
                            </button>
                        </form>
                        
                        <!-- 作者操作按钮 -->
                        <?php if(isset($_SESSION['user_id']) && $photo['user_id'] == $_SESSION['user_id']): ?>
                        <div class="operation-buttons">
                            <a href="edit_photo.php?id=<?php echo $photo_id; ?>" class="edit-btn">
                                <i class="fas fa-edit"></i> 编辑
                            </a>
                            <a href="delete_photo.php?id=<?php echo $photo_id; ?>" class="delete-btn" onclick="return confirm('确定要删除这张图片吗？')">
                                <i class="fas fa-trash"></i> 删除
                            </a>
                        </div>
                        <?php endif; ?>
                        
                        <!-- 分享按钮 -->
                        <div>
                            <div class="share-title">分享到</div>
                            <div class="share-buttons">
                                <!-- 微信分享 -->
                                <a href="javascript:;" class="share-btn share-weixin" 
                                   title="微信分享" onclick="showWeixinQrcode()">
                                    <i class="fab fa-weixin"></i>
                                </a>
                                
                                <!-- 微博分享 -->
                                <a href="http://service.weibo.com/share/share.php?url=<?php echo urlencode($current_url); ?>&title=<?php echo urlencode($photo['title']); ?>" 
                                   class="share-btn share-weibo" 
                                   title="微博分享" target="_blank" rel="noopener">
                                    <i class="fab fa-weibo"></i>
                                </a>
                                
                                <!-- QQ分享 -->
                                <a href="https://connect.qq.com/widget/shareqq/index.html?url=<?php echo urlencode($current_url); ?>&title=<?php echo urlencode($photo['title']); ?>" 
                                   class="share-btn share-qq" 
                                   title="QQ分享" target="_blank" rel="noopener">
                                    <i class="fab fa-qq"></i>
                                </a>
                                
                                <!-- 复制链接 -->
                                <a href="javascript:;" class="share-btn share-link" 
                                   title="复制链接" onclick="copyLink()">
                                    <i class="fas fa-link"></i>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 相关推荐 -->
        <div class="related-section">
            <h2 class="section-title"><i class="fas fa-thumbs-up"></i> 相关推荐</h2>
            <div class="related-grid">
                <?php
                try {
                    // 获取同分类的其他图片（排除当前图片）
                    $stmt = $pdo->prepare("SELECT id, title, filename, category, created_at, views 
                                       FROM photos 
                                       WHERE category = :category AND id != :current_id AND approved = 1
                                       ORDER BY created_at DESC LIMIT 4");
                    $stmt->bindParam(':category', $photo['category']);
                    $stmt->bindParam(':current_id', $photo_id);
                    $stmt->execute();
                    
                    $related_photos = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    if (count($related_photos) > 0) {
                        foreach ($related_photos as $rp) {
                            echo '<div class="related-item">';
                            echo '<div class="related-img-container">';
                            echo '<img src="uploads/' . htmlspecialchars($rp['filename']) . '" alt="' . htmlspecialchars($rp['title']) . '" class="related-img">';
                            echo '<span class="related-category">' . htmlspecialchars($rp['category']) . '</span>';
                            echo '</div>';
                            echo '<div class="related-info">';
                            echo '<a href="photo_detail.php?id=' . $rp['id'] . '" class="related-title">' . htmlspecialchars($rp['title']) . '</a>';
                            echo '<div class="related-meta">';
                            echo '<span><i class="far fa-eye"></i> ' . ($rp['views'] ?? 0) . '</span>';
                            echo '<span><i class="far fa-calendar"></i> ' . date('m-d', strtotime($rp['created_at'])) . '</span>';
                            echo '</div>';
                            echo '</div>';
                            echo '</div>';
                        }
                    } else {
                        echo '<div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-medium);">';
                        echo '<i class="fas fa-images" style="font-size: 2rem; margin-bottom: 15px; color: var(--text-light);"></i>';
                        echo '<p>暂无相关推荐图片</p>';
                        echo '</div>';
                    }
                } catch (PDOException $e) {
                    // 推荐功能失败不影响主页面
                    echo '<div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-medium);">';
                    echo '<p>推荐加载失败</p>';
                    echo '</div>';
                }
                ?>
            </div>
        </div>
    </div>
    
    <footer>
        <div class="container">
            <div class="copyright">
                &copy; <?php echo date('Y'); ?> Horizon Photos - 保留所有权利
            </div>
        </div>
    </footer>

    <!-- 微信分享二维码弹窗 -->
    <div id="weixinModal">
        <div class="modal-content">
            <h3>微信扫码分享</h3>
            <div class="qrcode-container">
                <!-- 动态生成当前页面的二维码 -->
                <img src="https://api.qrserver.com/v1/create-qr-code/?data=<?php echo urlencode($current_url); ?>&size=180x180" 
                     alt="微信分享二维码">
            </div>
            <p>请使用微信扫描二维码<br>分享到朋友圈或好友</p>
            <button class="close-btn" onclick="hideWeixinQrcode()">关闭</button>
        </div>
    </div>

    <script>
        // 导航栏滚动效果
        window.addEventListener('scroll', function() {
            const nav = document.getElementById('mainNav');
            if (window.scrollY > 50) {
                nav.classList.add('scrolled');
            } else {
                nav.classList.remove('scrolled');
            }
        });
        
        // 自动隐藏提示信息
        setTimeout(() => {
            const alert = document.getElementById('alertMessage');
            if (alert) {
                alert.classList.add('hide');
                // 300ms后完全移除元素
                setTimeout(() => alert.remove(), 300);
            }
        }, 3000);
        
        // 复制链接功能
        function copyLink() {
            const link = "<?php echo htmlspecialchars($current_url); ?>";
            navigator.clipboard.writeText(link).then(() => {
                // 显示临时提示
                const tempAlert = document.createElement('div');
                tempAlert.className = 'alert-message alert-success';
                tempAlert.textContent = '链接已复制到剪贴板！';
                document.querySelector('.photo-detail').prepend(tempAlert);
                
                // 自动移除
                setTimeout(() => {
                    tempAlert.classList.add('hide');
                    setTimeout(() => tempAlert.remove(), 300);
                }, 2000);
            }).catch(err => {
                alert("复制失败，请手动复制：" + link);
            });
        }
        
        // 显示微信分享二维码
        function showWeixinQrcode() {
            const modal = document.getElementById('weixinModal');
            modal.classList.add('active');
            // 阻止页面滚动
            document.body.style.overflow = 'hidden';
        }
        
        // 隐藏微信分享二维码
        function hideWeixinQrcode() {
            const modal = document.getElementById('weixinModal');
            modal.classList.remove('active');
            // 恢复页面滚动
            document.body.style.overflow = '';
        }
        
        // 点击二维码外部关闭弹窗
        document.getElementById('weixinModal').addEventListener('click', function(e) {
            if (e.target === this) {
                hideWeixinQrcode();
            }
        });
    </script>
</body>
</html>