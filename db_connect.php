<?php
// 数据库连接配置
$host = '127.0.0.1';
$dbname = 'www_syphotos_cn';
$username = 'www_syphotos_cn';
$password = 'Q84f3AtcbBzYJWPD';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}
?>
