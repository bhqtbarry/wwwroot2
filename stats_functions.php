<?php
require 'db_connect.php';

/**
 * 获取已通过审核的图片总数
 * syphotos 
 */
function getApprovedPhotosCount() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM photos WHERE approved = 1");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        // 使用null合并运算符确保即使结果为空也不会报错
        return $result['total'] ?? 0;
    } catch(PDOException $e) {
        error_log("获取通过审核图片数量失败: " . $e->getMessage());
        return 0;
    }
}

/**
 * 获取注册用户总数
 */
function getTotalUsers() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        // 增加null合并运算符增强健壮性
        return $result['total'] ?? 0;
    } catch(PDOException $e) {
        error_log("获取用户总数失败: " . $e->getMessage());
        return 0;
    }
}

/**
 * 获取待审核图片数量
 */
function getPendingReviews() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM photos WHERE approved = 0");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        // 增加null合并运算符增强健壮性
        return $result['total'] ?? 0;
    } catch(PDOException $e) {
        error_log("获取待审核图片数量失败: " . $e->getMessage());
        return 0;
    }
}

/**
 * 获取在线管理员数量（5分钟内有活动）
 */
function getOnlineAdmins() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users 
                           WHERE is_admin = 1 
                           AND last_active >= NOW() - INTERVAL 5 MINUTE");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        // 增加null合并运算符增强健壮性
        return $result['total'] ?? 0;
    } catch(PDOException $e) {
        error_log("获取在线管理员数量失败: " . $e->getMessage());
        return 0;
    }
}

/**
 * 获取在线管理员的用户名（5分钟内有活动）
 */
function getOnlineAdminNames() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT username FROM users 
                           WHERE is_admin = 1 
                           AND last_active >= NOW() - INTERVAL 5 MINUTE
                           ORDER BY username");
        // 获取用户名数组，使用FETCH_COLUMN确保只返回用户名列
        $adminNames = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);
        // 如果没有结果，返回空数组
        return $adminNames ? $adminNames : [];
    } catch(PDOException $e) {
        error_log("获取在线管理员名字失败: " . $e->getMessage());
        return [];
    }
}

/**
 * 获取在线用户数量（15分钟内有活动）
 */
function getOnlineUsers() {
    global $pdo;
    try {
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM users 
                           WHERE last_active >= NOW() - INTERVAL 15 MINUTE");
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        // 增加null合并运算符增强健壮性
        return $result['total'] ?? 0;
    } catch(PDOException $e) {
        error_log("获取在线用户数量失败: " . $e->getMessage());
        return 0;
    }
}

/**
 * 更新用户最后活动时间
 */
function updateUserActivity($userId) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE users SET last_active = NOW() WHERE id = :id");
        $stmt->bindParam(':id', $userId, PDO::PARAM_INT); // 明确参数类型
        $stmt->execute();
        return true;
    } catch(PDOException $e) {
        error_log("更新用户活动时间失败: " . $e->getMessage());
        return false;
    }
}
?>
    