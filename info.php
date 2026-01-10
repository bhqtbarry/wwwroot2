<?php

    $watermarkUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/6.png'; // 使用当前域名的HTTPS路径
    $watermarkPath = $_SERVER['DOCUMENT_ROOT'] . '/6.png'; // 服务器本地路径
    
    echo "Watermark URL: " . $watermarkUrl . "\n";
    echo "Watermark Path: " . $watermarkPath . "\n";    

#phpinfo();
