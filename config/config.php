<?php
return [
    'db' => [
        'dsn' => getenv('DB_DSN') ?: 'mysql:host=127.0.0.1;dbname=syphotos;charset=utf8mb4',
        'user' => getenv('DB_USER') ?: 'sa',
        'password' => getenv('DB_PASSWORD') ?: 'Shuibie.0105',
    ],
    'base_url' => getenv('BASE_URL') ?: 'https://www.syphotos.cn',
    'mail_from' => getenv('MAIL_FROM') ?: 'no-reply@syphotos.cn',
    'mail_from_name' => getenv('MAIL_FROM_NAME') ?: 'SyPhotos',
    'upload' => [
        'max_bytes' => 21 * 1024 * 1024,
        'min_long_edge' => 1600,
    ],
        // Gmail 登录
    'smtp_user' => 'zhongjieyouyichang@gmail.com',
    'smtp_pass' => 'ubgg wbvb npit slel',
];
