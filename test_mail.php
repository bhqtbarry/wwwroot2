<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/src/mail.php';

$result = send_verification_email('shuibie@163.com', 'test123');

echo $result ? 'OK，已发送' : '发送失败';
