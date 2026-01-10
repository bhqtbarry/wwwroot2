<?php
// 1. 全局引入JWT命名空间
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\SignatureInvalidException;
use Firebase\JWT\InvalidTokenException;

// 2. 基础配置与错误日志
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
$errorLogPath = dirname(__FILE__) . '/userinfo_errors.log';
ini_set('error_log', $errorLogPath);

// 3. 输出缓冲控制
ob_start();
ob_implicit_flush(false);

// 4. 数据库连接（开启异常模式）
try {
    require '../db_connect.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception('未获取到有效PDO实例');
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); // 强制抛出SQL异常
} catch (Exception $e) {
    http_response_code(500);
    error_log("[USERINFO DB CONN] " . date('Y-m-d H:i:s') . " - 连接失败：{$e->getMessage()}");
    echo json_encode([
        'error' => 'server_error',
        'error_description' => '服务端数据库连接失败'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. 响应头配置
header_remove();
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: https://cx.flyhs.top");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: Authorization, Content-Type");
header("Access-Control-Max-Age: 86400");
header("X-Content-Type-Options: nosniff");

// 6. 处理OPTIONS请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_clean();
    exit;
}

// 7. 验证请求方法
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    error_log("[USERINFO METHOD] " . date('Y-m-d H:i:s') . " - 非法方法：{$_SERVER['REQUEST_METHOD']}");
    echo json_encode([
        'error' => 'method_not_allowed',
        'error_description' => '仅支持GET请求'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 8. 验证令牌存在
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$authHeaderLower = strtolower($authHeader);
if (empty($authHeader) || substr($authHeaderLower, 0, 7) !== 'bearer ') {
    http_response_code(401);
    error_log("[USERINFO TOKEN] " . date('Y-m-d H:i:s') . " - 缺失令牌：{$authHeader}");
    echo json_encode([
        'error' => 'invalid_token',
        'error_description' => '需在Authorization头传递：Bearer {token}'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
$accessToken = trim(substr($authHeader, 7));
if (empty($accessToken)) {
    http_response_code(401);
    error_log("[USERINFO TOKEN] " . date('Y-m-d H:i:s') . " - 令牌为空");
    echo json_encode([
        'error' => 'invalid_token',
        'error_description' => '令牌为空'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 9. 验证令牌有效性
try {
    // 检查JWT依赖
    $autoloadPath = '../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("JWT依赖缺失：执行 composer require firebase/php-jwt");
    }
    require $autoloadPath;
    if (!class_exists('Firebase\JWT\JWT') || !class_exists('Firebase\JWT\Key')) {
        throw new Exception("JWT类未找到：依赖安装失败");
    }

    // JWT密钥
    $jwtSecret = getenv('SYPHOTOS_JWT_SECRET') ?: 'syphotos_oauth_jwt_secret_2024';
    if (strlen($jwtSecret) < 16) {
        throw new Exception("JWT密钥需≥16位（当前：" . strlen($jwtSecret) . "位）");
    }

    // 解码令牌
    $decoded = JWT::decode($accessToken, new Key($jwtSecret, 'HS256'));
    $decodedArr = (array)$decoded;

    // 验证必要字段
    $requiredClaims = ['iss', 'aud', 'sub', 'client_id', 'iat', 'exp'];
    foreach ($requiredClaims as $claim) {
        if (!isset($decodedArr[$claim]) || empty($decodedArr[$claim])) {
            throw new Exception("缺失令牌字段：{$claim}");
        }
    }

    // 验证签发者
    $validIssuers = ['https://www.syphotos.cn'];
    if (!in_array($decodedArr['iss'], $validIssuers)) {
        throw new Exception("签发者无效（允许：" . implode(', ', $validIssuers) . "）");
    }

    // 验证客户端
    $clientId = $decodedArr['client_id'];
    $clientStmt = $pdo->prepare("SELECT status FROM oauth_clients WHERE client_id = :client_id LIMIT 1");
    $clientStmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
    $clientStmt->execute();
    $client = $clientStmt->fetch(PDO::FETCH_ASSOC);
    if (!$client) throw new Exception("客户端不存在（ID：{$clientId}）");
    if ((int)$client['status'] !== 1) throw new Exception("客户端已禁用（ID：{$clientId}）");

    // 提取用户ID（假设为整数类型）
    $userId = $decodedArr['sub'];
    if (!is_numeric($userId) || $userId <= 0) {
        throw new Exception("用户ID无效（需为正整数，实际：{$userId}）");
    }
    $userId = (int)$userId;

} catch (ExpiredException $e) {
    throw new Exception("令牌已过期");
} catch (SignatureInvalidException $e) {
    throw new Exception("令牌签名无效");
} catch (InvalidTokenException $e) {
    throw new Exception("令牌格式无效");
} catch (Exception $e) {
    http_response_code(401);
    error_log("[USERINFO TOKEN] " . date('Y-m-d H:i:s') . " - 令牌错误：{$e->getMessage()}");
    echo json_encode([
        'error' => 'invalid_token',
        'error_description' => '令牌无效或已过期：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 10. 【核心修复】获取用户信息（移除不存在的`status`条件）
try {
    // ###########################
    // 适配实际表结构：users表含id、username字段，无status字段
    $userTable = 'users';          
    $userPrimaryKey = 'id';        
    $userSelectFields = 'id, username'; 
    // ###########################

    // 构建SQL（无status条件，避免字段不存在报错）
    $sql = "SELECT {$userSelectFields} FROM {$userTable} WHERE {$userPrimaryKey} = :userId LIMIT 1";
    $userStmt = $pdo->prepare($sql);
    $userStmt->bindValue(':userId', $userId, PDO::PARAM_INT); // 用户ID为整数
    $userStmt->execute();
    $user = $userStmt->fetch(PDO::FETCH_ASSOC);

    // 记录SQL执行详情（便于排查）
    error_log("[USERINFO SQL] " . date('Y-m-d H:i:s') . " - 执行SQL：{$sql}，用户ID：{$userId}");

    // 检查用户是否存在
    if (!$user) {
        http_response_code(404);
        error_log("[USERINFO USER] " . date('Y-m-d H:i:s') . " - 用户不存在：{$userId}");
        echo json_encode([
            'error' => 'user_not_found',
            'error_description' => '用户不存在或已禁用'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

} catch (PDOException $e) {
    // 记录完整SQL错误（含错误码、SQL语句）
    $errorDetail = "SQL错误码：{$e->getCode()}，错误描述：{$e->getMessage()}，执行SQL：{$sql}";
    error_log("[USERINFO DB ERROR] " . date('Y-m-d H:i:s') . " - {$errorDetail}");
    
    http_response_code(500);
    echo json_encode([
        'error' => 'server_error',
        'error_description' => '获取用户信息失败（数据库错误）'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 11. 输出用户信息（兼容字段）
ob_end_clean();
echo json_encode([
    'sub' => $user[$userPrimaryKey] ?? '', // 标准OAuth用户标识
    'user_id' => $user[$userPrimaryKey] ?? '', // 兼容用户ID字段
    'username' => $user['username'] ?? '', // 用户名
    'avatar' => '' // 兼容前端：无头像时返回空字符串
], JSON_UNESCAPED_UNICODE);

exit;
?>