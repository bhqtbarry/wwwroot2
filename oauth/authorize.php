<?php
require '../db_connect.php';
session_start();

// 1. 验证授权请求参数（客户端必须传递client_id、redirect_uri、state）
$requiredParams = ['client_id', 'redirect_uri', 'state'];
foreach ($requiredParams as $param) {
    if (empty($_GET[$param])) {
        die(json_encode([
            'error' => 'invalid_request',
            'error_description' => "缺少必要参数：$param"
        ], JSON_UNESCAPED_UNICODE));
    }
}

$clientId = $_GET['client_id'];
$redirectUri = $_GET['redirect_uri'];
$state = $_GET['state']; // 防CSRF攻击，客户端需验证此参数

// 2. 验证客户端合法性（是否存在、是否启用、回调地址是否匹配）
$client = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM oauth_clients 
                        WHERE client_id = :client_id AND status = 1");
    $stmt->execute([':client_id' => $clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die(json_encode([
        'error' => 'server_error',
        'error_description' => '服务端错误：' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE));
}

// 客户端不存在或回调地址不匹配
if (!$client || $client['redirect_uri'] !== $redirectUri) {
    die(json_encode([
        'error' => 'invalid_client',
        'error_description' => '客户端不存在或回调地址不匹配'
    ], JSON_UNESCAPED_UNICODE));
}

// 3. 检查用户是否已登录syphotos（未登录则跳转至登录页）
if (!isset($_SESSION['user_id'])) {
    $loginRedirect = '../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']);
    header("Location: $loginRedirect");
    exit;
}
$userId = $_SESSION['user_id'];
$username = $_SESSION['username'] ?? '未知用户'; // 假设用户会话中存储了username


// 4. 处理用户授权提交（用户点击“允许”或“拒绝”）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST['action'] === 'deny') {
        // 用户拒绝授权：跳转回客户端回调地址，携带error参数
        $redirectUrl = $redirectUri . '?error=access_denied&error_description=用户拒绝授权&state=' . $state;
        header("Location: $redirectUrl");
        exit;
    } elseif ($_POST['action'] === 'allow') {
        // 用户允许授权：生成临时授权码（5分钟过期）
        try {
            $code = bin2hex(random_bytes(20)); // 40位随机授权码
            $expiredAt = date('Y-m-d H:i:s', strtotime('+5 minutes'));

            // 插入授权码到数据库（临时存储，使用后失效）
            $stmt = $pdo->prepare("INSERT INTO oauth_codes 
                (code, client_id, user_id, expired_at) 
                VALUES (:code, :client_id, :user_id, :expired_at)");
            $stmt->execute([
                ':code' => $code,
                ':client_id' => $clientId,
                ':user_id' => $userId,
                ':expired_at' => $expiredAt
            ]);

            // 跳转回客户端回调地址，携带授权码和state
            $redirectUrl = $redirectUri . '?code=' . $code . '&state=' . $state;
            header("Location: $redirectUrl");
            exit;
        } catch (PDOException $e) {
            die(json_encode([
                'error' => 'server_error',
                'error_description' => '生成授权码失败：' . $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>syphotos - 授权确认</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #165DFF;
            --light-bg: #f0f7ff;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.1);
            --border-radius: 8px;
        }
        body { font-family: 'Segoe UI', Roboto, sans-serif; background: var(--light-bg); display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; padding: 20px; }
        .card { background: white; border-radius: var(--border-radius); padding: 30px; box-shadow: var(--card-shadow); max-width: 500px; width: 100%; }
        .title { color: var(--primary); font-size: 1.5rem; margin-bottom: 20px; text-align: center; }
        .client-info { border: 1px solid #eee; border-radius: 4px; padding: 15px; margin-bottom: 25px; }
        .client-info p { margin: 8px 0; }
        .client-info strong { color: #333; }
        .permissions { margin: 20px 0; }
        .permissions h4 { margin-bottom: 10px; color: #555; }
        .permissions ul { list-style: none; padding: 0; margin: 0; }
        .permissions li { padding: 5px 0; display: flex; align-items: center; }
        .permissions li i { color: var(--primary); margin-right: 8px; }
        .btn-group { display: flex; gap: 15px; justify-content: center; margin-top: 30px; }
        .btn { padding: 10px 25px; border-radius: 4px; cursor: pointer; font-size: 1rem; border: none; }
        .btn-deny { background: #dc3545; color: white; }
        .btn-deny:hover { background: #c82333; }
        .btn-allow { background: var(--primary); color: white; }
        .btn-allow:hover { background: #0E42D2; }
    </style>
</head>
<body>
    <div class="card">
        <h2 class="title"><i class="fas fa-shield-alt"></i> 授权确认</h2>
        <p style="text-align: center; color: #666; margin-bottom: 20px;">
            您正在使用 <strong><?php echo $username; ?></strong> 账号登录第三方应用
        </p>

        <!-- 第三方客户端信息 -->
        <div class="client-info">
            <p><strong>应用名称：</strong><?php echo $client['client_name']; ?></p>
            <p><strong>回调地址：</strong><?php echo $client['redirect_uri']; ?></p>
        </div>

        <!-- 申请的权限 -->
        <div class="permissions">
            <h4>该应用将获取您的以下信息：</h4>
            <ul>
                <li><i class="fas fa-check-circle"></i> 您的syphotos用户ID（用于唯一标识）</li>
                <li><i class="fas fa-check-circle"></i> 您的syphotos用户名（用于显示）</li>
                <li><i class="fas fa-check-circle"></i> 不获取您的密码、邮箱等敏感信息</li>
            </ul>
        </div>

        <!-- 授权操作按钮 -->
        <form method="POST" action="">
            <div class="btn-group">
                <button type="submit" name="action" value="deny" class="btn btn-deny">
                    <i class="fas fa-times"></i> 拒绝授权
                </button>
                <button type="submit" name="action" value="allow" class="btn btn-allow">
                    <i class="fas fa-check"></i> 允许授权
                </button>
            </div>
        </form>
    </div>
</body>
</html>