<?php
require 'db_connect.php';
session_start();

// 检查是否为管理员登录
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    die("权限不足，请以管理员身份登录");
}

$current_admin_id = $_SESSION['user_id'];
$deleted_count = 0;
$error_info = [];

try {
    // 1. 获取最近5000个用户（排除当前管理员）
    $stmt = $pdo->prepare("
        SELECT id FROM users 
        WHERE id != :current_admin 
        ORDER BY created_at DESC 
        LIMIT 5000
    ");
    $stmt->bindParam(':current_admin', $current_admin_id);
    $stmt->execute();
    $user_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($user_ids)) {
        die("没有符合条件的用户可删除");
    }

    // 2. 循环删除每个用户（复用原页面的删除逻辑）
    foreach ($user_ids as $user_id) {
        $pdo->beginTransaction();

        try {
            // 删除用户的申诉记录
            $stmt = $pdo->prepare("DELETE FROM appeals WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            // 删除用户的点赞记录
            $stmt = $pdo->prepare("DELETE FROM photo_likes WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            // 获取并删除用户的图片文件
            $stmt = $pdo->prepare("SELECT filename FROM photos WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();
            $photos = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($photos as $photo) {
                $file_path = 'uploads/' . $photo['filename'];
                if (file_exists($file_path)) {
                    unlink($file_path);
                }
            }

            // 删除用户的图片记录
            $stmt = $pdo->prepare("DELETE FROM photos WHERE user_id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            // 删除用户
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = :user_id");
            $stmt->bindParam(':user_id', $user_id);
            $stmt->execute();

            $pdo->commit();
            $deleted_count++;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $error_info[] = "用户ID: {$user_id} 删除失败: " . $e->getMessage();
        }
    }

    echo "操作完成：成功删除 {$deleted_count} 个用户<br>";
    if (!empty($error_info)) {
        echo "错误记录：<br>" . implode("<br>", $error_info);
    }

} catch (PDOException $e) {
    die("数据库错误：" . $e->getMessage());
}
?>