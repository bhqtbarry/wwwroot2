<?php
require __DIR__ . '/config.php';
require __DIR__ . '/mail.php';

$message = '已发送验证邮件，请点击邮件中的链接完成注册。';
$email = trim($_GET['email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');

    if ($email !== '') {
        $token = bin2hex(random_bytes(32));
        $tokenCreatedAt = gmdate('Y-m-d H:i:s');

        $stmt = $pdo->prepare('UPDATE users SET verification_token = :token, verification_token_created_at = :token_created_at WHERE email = :email AND email_verified_at IS NULL');
        $stmt->execute([
            'token' => $token,
            'token_created_at' => $tokenCreatedAt,
            'email' => $email,
        ]);

        if ($stmt->rowCount() > 0) {
            send_verification_email($email, $token);
            $message = '验证邮件已重新发送，请查收。';
        } else {
            $message = '无法重新发送，请确认邮箱地址或账号状态。';
        }
    } else {
        $message = '请输入邮箱地址以重新发送验证邮件。';
    }
}
?>
<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>邮箱验证</title>
</head>
<body>
<h1>邮箱验证</h1>
<p><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></p>
<form method="post">
    <input type="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" placeholder="邮箱地址" required>
    <button type="submit">重新发送验证邮件</button>
</form>
</body>
</html>
