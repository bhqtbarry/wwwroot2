<?php
// 1. 全局引入JWT命名空间（必须在全局作用域）
use Firebase\JWT\JWT;

// 2. HTTPS检测函数（兼容反向代理/CDN）
function isHttpsRequest() {
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') return true;
    if (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') return true;
    if (!empty($_SERVER['HTTP_CF_VISITOR']) && strpos(strtolower($_SERVER['HTTP_CF_VISITOR']), 'https') !== false) return true;
    return false;
}

// 3. 基础配置与错误处理
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', dirname(__FILE__) . '/token_errors.log'); // 确保服务器可写

// 4. 输出缓冲控制（防止意外输出污染JSON）
ob_start();
ob_implicit_flush(false);

// 5. 数据库连接
try {
    require '../db_connect.php';
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception('未获取到有效PDO数据库实例');
    }
} catch (Exception $e) {
    http_response_code(500);
    $errorMsg = '服务端数据库连接失败';
    error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}：{$e->getMessage()}");
    echo json_encode([
        'error' => 'server_error',
        'error_description' => $errorMsg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 6. 响应头配置（跨域+安全）
header_remove();
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Origin: https://cx.flyhs.top");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Access-Control-Max-Age: 86400");
header("X-Content-Type-Options: nosniff");

// 7. 处理OPTIONS预检请求
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    ob_end_clean();
    exit;
}

// 8. 强制HTTPS检测
if (!isHttpsRequest()) {
    http_response_code(403);
    $errorMsg = '仅支持HTTPS请求';
    $actualProto = isHttpsRequest() ? 'HTTPS' : 'HTTP';
    $forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '未传递';
    error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}（检测结果: {$actualProto}, X-Forwarded-Proto: {$forwardedProto}）");
    echo json_encode([
        'error' => 'invalid_request',
        'error_description' => $errorMsg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 9. 验证请求方法（仅允许POST）
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $errorMsg = '仅支持POST请求';
    error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - 非法方法：{$_SERVER['REQUEST_METHOD']}");
    echo json_encode([
        'error' => 'method_not_allowed',
        'error_description' => $errorMsg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 10. 解析并验证参数（适配32位client_id）
$input = file_get_contents('php://input');
$params = json_decode($input, true) ?: $_POST;

$requiredParams = [
    'grant_type' => ['type' => 'string', 'pattern' => '/^[a-z_]+$/'],
    'client_id' => ['type' => 'string', 'pattern' => '/^[a-f0-9]{32}$/i'], // 32位十六进制（允许大小写）
    'client_secret' => ['type' => 'string', 'pattern' => '/^[a-f0-9]{64}$/i'], // 64位十六进制（允许大小写）
    'code' => ['type' => 'string', 'pattern' => '/^[a-f0-9]{40}$/i'] // 40位授权码（允许大小写）
];

foreach ($requiredParams as $param => $rules) {
    // 检查参数存在性
    if (!isset($params[$param]) || trim($params[$param]) === '') {
        http_response_code(400);
        $errorMsg = "缺少必要参数：$param";
        error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}");
        echo json_encode([
            'error' => 'invalid_request',
            'error_description' => $errorMsg
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查参数类型
    if (gettype($params[$param]) !== $rules['type']) {
        http_response_code(400);
        $errorMsg = "参数格式错误：$param 应为{$rules['type']}类型";
        error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}");
        echo json_encode([
            'error' => 'invalid_request',
            'error_description' => $errorMsg
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 检查参数格式（正则匹配）
    if (!preg_match($rules['pattern'], $params[$param])) {
        http_response_code(400);
        $actualValue = $params[$param];
        $patternDesc = str_replace(['/i', '/'], '', $rules['pattern']);
        $errorMsg = "参数格式错误：$param（要求：{$patternDesc}，实际值：{$actualValue}）";
        error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}");
        echo json_encode([
            'error' => 'invalid_request',
            'error_description' => "参数格式错误：$param 不符合规范"
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 11. 验证授权类型
if ($params['grant_type'] !== 'authorization_code') {
    http_response_code(400);
    $errorMsg = '仅支持authorization_code授权类型';
    error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - 不支持的类型：{$params['grant_type']}");
    echo json_encode([
        'error' => 'unsupported_grant_type',
        'error_description' => $errorMsg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$clientId = $params['client_id']; // 已验证的合法客户端ID
$clientSecret = $params['client_secret'];
$code = $params['code'];

// 12. 验证客户端合法性
$client = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM oauth_clients WHERE client_id = :client_id AND status = 1 LIMIT 1");
    $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
    $stmt->execute();
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$client) throw new Exception("客户端不存在：{$clientId}");
    if (!password_verify($clientSecret, $client['client_secret'])) throw new Exception("客户端密钥不匹配：{$clientId}");
} catch (Exception $e) {
    http_response_code(401);
    $errorMsg = 'Client ID或Client Secret错误';
    error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}：{$e->getMessage()}");
    echo json_encode([
        'error' => 'invalid_client',
        'error_description' => $errorMsg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 13. 验证授权码合法性（带行锁防并发）
$authCode = null;
try {
    $pdo->beginTransaction();
    // 加行锁查询，防止并发修改
    $stmt = $pdo->prepare("SELECT id, code, used, user_id FROM oauth_codes 
                        WHERE code = :code AND client_id = :client_id AND used = 0 AND expired_at > NOW() 
                        FOR UPDATE");
    $stmt->bindValue(':code', $code, PDO::PARAM_STR);
    $stmt->bindValue(':client_id', $clientId, PDO::PARAM_STR);
    $stmt->execute();
    $authCode = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$authCode) {
        $pdo->rollBack();
        throw new Exception("授权码无效、已过期或已使用：{$code}");
    }

    $pdo->commit();
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(401);
    $errorMsg = '授权码无效、已过期或已使用';
    error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}：{$e->getMessage()}");
    echo json_encode([
        'error' => 'invalid_grant',
        'error_description' => $errorMsg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = $authCode['user_id'] ?? 0; // 兜底避免未定义（已通过授权码验证，理论上不会为空）
$authCodeId = $authCode['id'];

// 14. 标记授权码为已使用（适配表结构：仅更新used字段）
try {
    $pdo->beginTransaction();

    // 双重校验：再次确认授权码未被使用
    $checkStmt = $pdo->prepare("SELECT used FROM oauth_codes WHERE id = :id AND used = 0");
    $checkStmt->bindValue(':id', $authCodeId, PDO::PARAM_INT);
    $checkStmt->execute();
    $checkResult = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if (!$checkResult) {
        $pdo->rollBack();
        throw new Exception("授权码已被标记为已使用：ID={$authCodeId}");
    }

    // 更新used字段为1（表中无used_at，故删除该字段操作）
    $updateStmt = $pdo->prepare("UPDATE oauth_codes SET used = 1 WHERE id = :id AND used = 0");
    $updateStmt->bindValue(':id', $authCodeId, PDO::PARAM_INT);
    $updateStmt->execute();

    if ($updateStmt->rowCount() !== 1) {
        $pdo->rollBack();
        throw new Exception("授权码更新失败（影响行数异常）：ID={$authCodeId}，影响行数={$updateStmt->rowCount()}");
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    http_response_code(500);
    $errorMsg = '数据库更新授权码失败（SQL错误）';
    // 记录详细SQL错误（含错误码、SQL语句）
    error_log("[TOKEN DB ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}：{$e->getMessage()}，SQLSTATE: {$e->getCode()}");
    echo json_encode([
        'error' => 'server_error',
        'error_description' => '服务端数据更新失败（数据库错误）'
    ], JSON_UNESCAPED_UNICODE);
    exit;
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    $errorMsg = '服务端数据更新失败';
    error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}：{$e->getMessage()}");
    echo json_encode([
        'error' => 'server_error',
        'error_description' => $errorMsg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 15. 生成JWT令牌（核心修复：补充client_id字段）
$accessToken = '';
try {
    // 检查JWT依赖
    $autoloadPath = '../vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception("JWT依赖缺失，请执行：composer require firebase/php-jwt");
    }
    require $autoloadPath;

    if (!class_exists('Firebase\JWT\JWT')) {
        throw new Exception("未找到JWT类，请检查依赖安装");
    }

    // 生产环境从环境变量取密钥，测试环境用默认
    $jwtSecret = getenv('SYPHOTOS_JWT_SECRET');
    if (empty($jwtSecret)) {
        $jwtSecret = 'syphotos_oauth_jwt_secret_2024'; // 测试用临时密钥
        error_log("[WARNING] " . date('Y-m-d H:i:s') . " - 使用默认JWT密钥，生产环境请配置SYPHOTOS_JWT_SECRET");
    }

    // 令牌有效时间与载荷（核心：添加client_id字段）
    $expiresIn = 3600 * 2; // 2小时有效期
    $issuedAt = time();
    $expiresAt = $issuedAt + $expiresIn;

    $payload = [
        'iss' => 'https://www.syphotos.cn',  // 签发者（与userinfo.php的validIssuers一致）
        'aud' => $clientId,                  // 受众（客户端ID）
        'sub' => $userId,                    // 用户ID
        'iat' => $issuedAt,                  // 签发时间
        'exp' => $expiresAt,                 // 过期时间
        'jti' => bin2hex(random_bytes(16)),  // 令牌唯一ID（防重放）
        'client_id' => $clientId             // 【核心补充】添加client_id字段，适配userinfo.php验证
    ];

    // 【新增】验证payload必要字段，提前发现缺失（避免生成无效令牌）
    $requiredPayloadFields = ['iss', 'aud', 'sub', 'client_id', 'iat', 'exp', 'jti'];
    foreach ($requiredPayloadFields as $field) {
        if (empty($payload[$field])) {
            throw new Exception("JWT payload缺少必要字段：{$field}（当前值：" . print_r($payload[$field], true) . "）");
        }
    }

    // 生成JWT令牌（HS256算法）
    $accessToken = JWT::encode($payload, $jwtSecret, 'HS256');

    // 验证令牌生成结果
    if (empty($accessToken)) {
        throw new Exception("JWT令牌生成为空（可能是密钥无效或载荷格式错误）");
    }
} catch (Exception $e) {
    http_response_code(500);
    $errorMsg = '令牌生成失败';
    error_log("[TOKEN ERROR] " . date('Y-m-d H:i:s') . " - {$errorMsg}：{$e->getMessage()}");
    echo json_encode([
        'error' => 'server_error',
        'error_description' => $errorMsg
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 16. 输出纯净JSON响应（清空缓冲，确保无额外输出）
ob_end_clean();
echo json_encode([
    'access_token' => $accessToken,
    'token_type' => 'Bearer',
    'expires_in' => $expiresIn,
    'user_id' => $userId
], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

// 强制终止，避免后续意外输出（如include文件末尾的空格）
exit;
?>