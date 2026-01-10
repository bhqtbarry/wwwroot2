<?php
require __DIR__ . '/config.php';

$token = trim($_GET['token'] ?? '');
$message = '无效的验证链接。';

if ($token !== '') {
    $stmt = $pdo->prepare('SELECT id, verification_token_created_at, email_verified_at FROM users WHERE verification_token = :token LIMIT 1');
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['email_verified_at']) {
            $message = '邮箱已验证，无需重复操作。';
        } else {
            $createdAt = new DateTime($user['verification_token_created_at'], new DateTimeZone('UTC'));
            $expiresAt = (clone $createdAt)->modify('+24 hours');
            $now = new DateTime('now', new DateTimeZone('UTC'));

            if ($now > $expiresAt) {
                $message = '验证链接已过期，请重新发送验证邮件。';
            } else {
                $update = $pdo->prepare('UPDATE users SET email_verified_at = :verified_at, verification_token = NULL, verification_token_created_at = NULL WHERE id = :id');
                $update->execute([
                    'verified_at' => $now->format('Y-m-d H:i:s'),
                    'id' => $user['id'],
                ]);
                $message = '邮箱验证成功，请返回登录。';
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>邮箱验证结果</title>
</head>
<body>
<h1>邮箱验证</h1>
<p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
</body>
</html>
