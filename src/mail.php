<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../lib/PHPMailer/src/Exception.php';
require_once __DIR__ . '/../lib/PHPMailer/src/PHPMailer.php';
require_once __DIR__ . '/../lib/PHPMailer/src/SMTP.php';

function send_verification_email(string $email, string $token): bool
{
    $config = require __DIR__ . '/../config/config.php';

    $mail = new PHPMailer(true);

    try {
        // SMTP 配置（Gmail）
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_user'];
        $mail->Password   = $config['smtp_pass'];
        $mail->Port       = 587;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        // 基本信息
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(
            $config['mail_from'],
            $config['mail_from_name']
        );

        //设定为html格式
        $mail->isHTML(true);

        // 收件人
        $mail->addAddress($email);

        // 内容
        $link = $config['base_url'] . '/verify.php?token=' . urlencode($token);
        $mail->Subject = 'Verify your SyPhotos account';
        $mail->Body    = "请点击下面链接完成注册：\n\n <a href=\"{$link}\">{$link}</a>";

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}
function send_reset_email(string $email, string $token): bool
{
    $config = require __DIR__ . '/../config/config.php';

    $mail = new PHPMailer(true);

    try {
        // SMTP 配置（Gmail）
        $mail->isSMTP();
        $mail->Host       = 'smtp.gmail.com';
        $mail->SMTPAuth   = true;
        $mail->Username   = $config['smtp_user'];
        $mail->Password   = $config['smtp_pass'];
        $mail->Port       = 587;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;

        // 基本信息
        $mail->CharSet = 'UTF-8';
        $mail->setFrom(
            $config['mail_from'],
            $config['mail_from_name']
        );

        // 收件人
        $mail->addAddress($email);

        // 内容
        $link = $config['base_url'] . '/reset.php?token=' . urlencode($token);
        $mail->Subject = 'Reset your SyPhotos password';
        $mail->Body    = "请点击下面链接重置密码：\n\n{$link}";

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mail error: ' . $mail->ErrorInfo);
        return false;
    }
}