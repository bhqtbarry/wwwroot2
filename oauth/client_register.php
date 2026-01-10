<?php
require '../db_connect.php'; // 复用现有数据库连接
session_start();

// 仅允许syphotos管理员访问（复用现有管理员权限）
if (!isset($_SESSION['user_id']) || !$_SESSION['is_admin']) {
    header('Location: ../login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$error = '';
$success = '';
$clientData = [];

// 处理客户端注册提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientName = trim($_POST['client_name'] ?? '');
    $redirectUri = trim($_POST['redirect_uri'] ?? '');

    // 表单验证
    if (empty($clientName)) {
        $error = '请填写第三方网站名称';
    } elseif (empty($redirectUri) || !filter_var($redirectUri, FILTER_VALIDATE_URL)) {
        $error = '请填写有效的回调地址（必须是完整URL）';
    } else {
        try {
            // 1. 生成随机Client ID和Client Secret（高安全性）
            $clientId = bin2hex(random_bytes(16)); // 32位随机字符串
            $clientSecret = bin2hex(random_bytes(32)); // 64位随机字符串
            $hashedSecret = password_hash($clientSecret, PASSWORD_DEFAULT); // 加密存储密钥

            // 2. 插入客户端数据
            $stmt = $pdo->prepare("INSERT INTO oauth_clients 
                (client_id, client_secret, client_name, redirect_uri) 
                VALUES (:client_id, :client_secret, :client_name, :redirect_uri)");
            $stmt->execute([
                ':client_id' => $clientId,
                ':client_secret' => $hashedSecret,
                ':client_name' => $clientName,
                ':redirect_uri' => $redirectUri
            ]);

            // 3. 返回客户端信息（仅显示一次Secret，需客户端自行保存）
            $success = '客户端注册成功！请妥善保存以下信息（Secret仅显示一次）';
            $clientData = [
                'client_id' => $clientId,
                'client_secret' => $clientSecret, // 原始Secret（仅此时显示）
                'client_name' => $clientName,
                'redirect_uri' => $redirectUri
            ];
        } catch (PDOException $e) {
            $error = '注册失败：' . $e->getMessage();
        }
    }
}

// 加载客户端列表（供管理员查看）
$clients = [];
try {
    $stmt = $pdo->query("SELECT id, client_id, client_name, redirect_uri, status, created_at 
                        FROM oauth_clients ORDER BY created_at DESC");
    $clients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = '加载客户端列表失败：' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>syphotos - 第三方客户端注册</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* 复用现有平台样式风格 */
        :root {
            --primary: #165DFF;
            --danger: #dc3545;
            --success: #28a745;
            --light-bg: #f0f7ff;
            --card-shadow: 0 4px 12px rgba(0,0,0,0.08);
            --border-radius: 8px;
        }
        body { font-family: 'Segoe UI', Roboto, sans-serif; background: var(--light-bg); margin: 0; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; }
        .card { background: white; border-radius: var(--border-radius); padding: 25px; box-shadow: var(--card-shadow); margin-bottom: 30px; }
        .title { color: var(--primary); font-size: 1.5rem; margin-bottom: 20px; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; }
        input { width: 100%; padding: 10px 15px; border: 1px solid #ddd; border-radius: 4px; font-size: 1rem; }
        .btn { background: var(--primary); color: white; border: none; padding: 10px 20px; border-radius: 4px; cursor: pointer; font-size: 1rem; }
        .btn:hover { background: #0E42D2; }
        .alert { padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .alert-error { background: #f8d7da; color: #721c24; }
        .alert-success { background: #d4edda; color: #155724; }
        .code-box { background: #f8f9fa; padding: 15px; border-radius: 4px; border-left: 3px solid var(--success); margin: 15px 0; font-family: monospace; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px 15px; border-bottom: 1px solid #eee; text-align: left; }
        th { background: #f8f9fa; font-weight: 500; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <h2 class="title"><i class="fas fa-handshake"></i> 第三方客户端注册</h2>
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <div class="code-box">
                    <p><strong>Client ID：</strong><?php echo $clientData['client_id']; ?></p>
                    <p><strong>Client Secret：</strong><?php echo $clientData['client_secret']; ?> <span style="color: var(--danger);">(仅显示一次，务必保存)</span></p>
                    <p><strong>回调地址：</strong><?php echo $clientData['redirect_uri']; ?></p>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="form-group">
                    <label for="client_name">第三方网站名称</label>
                    <input type="text" id="client_name" name="client_name" required 
                           placeholder="如：XX航空摄影社区" value="<?php echo $_POST['client_name'] ?? ''; ?>">
                </div>
                <div class="form-group">
                    <label for="redirect_uri">授权回调地址（必须是完整URL）</label>
                    <input type="url" id="redirect_uri" name="redirect_uri" required 
                           placeholder="如：https://www.xxx.com/oauth/callback" value="<?php echo $_POST['redirect_uri'] ?? ''; ?>">
                    <small style="color: #666; margin-top: 5px; display: block;">
                        说明：授权成功后，会跳转到该地址并携带授权码（code参数）
                    </small>
                </div>
                <button type="submit" class="btn"><i class="fas fa-save"></i> 提交注册</button>
            </form>
        </div>

        <!-- 客户端列表 -->
        <div class="card">
            <h2 class="title"><i class="fas fa-list"></i> 已注册客户端</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Client ID</th>
                        <th>网站名称</th>
                        <th>回调地址</th>
                        <th>状态</th>
                        <th>创建时间</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($clients)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #666;">暂无已注册客户端</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($clients as $client): ?>
                            <tr>
                                <td><?php echo $client['id']; ?></td>
                                <td><?php echo $client['client_id']; ?></td>
                                <td><?php echo $client['client_name']; ?></td>
                                <td style="max-width: 300px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                    <?php echo $client['redirect_uri']; ?>
                                </td>
                                <td><?php echo $client['status'] == 1 ? '<span style="color: var(--success);">启用</span>' : '<span style="color: var(--danger);">禁用</span>'; ?></td>
                                <td><?php echo $client['created_at']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>