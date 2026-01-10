<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function register_user(string $username, string $email, string $password): array
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $token = bin2hex(random_bytes(16));
    $stmt = db()->prepare('INSERT INTO users (username, email, password_hash, verification_token) VALUES (:username, :email, :password_hash, :token)');
    $stmt->execute([
        'username' => $username,
        'email' => $email,
        'password_hash' => $hash,
        'token' => $token,
    ]);
    return ['user_id' => (int)db()->lastInsertId(), 'token' => $token];
}

function authenticate(string $username, string $password): ?array
{
    $stmt = db()->prepare('SELECT * FROM users WHERE username = :username');
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, $user['password_hash'])) {
        return null;
    }
    return $user;
}

function send_verification_email(string $email, string $token): void
{
    $config = require __DIR__ . '/../config/config.php';
    $link = $config['base_url'] . '/verify.php?token=' . urlencode($token);
    $subject = 'Verify your SyPhotos account';
    $message = "Click to verify your account: {$link}";
    $headers = 'From: ' . $config['mail_from_name'] . ' <' . $config['mail_from'] . '>';
    @mail($email, $subject, $message, $headers);
}

function send_reset_email(string $email, string $token): void
{
    $config = require __DIR__ . '/../config/config.php';
    $link = $config['base_url'] . '/reset.php?token=' . urlencode($token);
    $subject = 'Reset your SyPhotos password';
    $message = "Reset your password: {$link}";
    $headers = 'From: ' . $config['mail_from_name'] . ' <' . $config['mail_from'] . '>';
    @mail($email, $subject, $message, $headers);
}
