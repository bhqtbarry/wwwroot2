<?php
require 'db_connect.php';
session_start();

// 检查GD库是否可用
if(!extension_loaded('gd') || !function_exists('imagecreatefrompng')) {
    die('错误：服务器未安装或未启用GD库，无法处理图片水印功能。请联系管理员解决。');
}

// 检查是否登录
if(!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$error = '';
$success = '';

// 确保uploads目录可写
$upload_dir = 'uploads/';
if(!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}
if(!is_writable($upload_dir)) {
    $error = '上传目录不可写，请检查权限设置';
}

/**
 * 计算图片压缩后的尺寸（前后端共用算法）
 */
function calculateCompressedSize($originalWidth, $originalHeight, $originalSize, $maxSize = 5242880) {
    // 如果原始大小符合要求，不改变尺寸
    if($originalSize <= $maxSize) {
        return [
            'width' => $originalWidth,
            'height' => $originalHeight,
            'scale' => 1.0
        ];
    }
    
    // 计算需要压缩的比例（前后端必须使用相同算法）
    $scale = sqrt($maxSize / $originalSize) * 0.9;
    $newWidth = max(400, intval(round($originalWidth * $scale)));
    $newHeight = max(300, intval(round($originalHeight * $scale)));
    
    // 确保宽高比例不变
    $aspectRatio = $originalWidth / $originalHeight;
    $newHeight = intval(round($newWidth / $aspectRatio));
    
    return [
        'width' => $newWidth,
        'height' => $newHeight,
        'scale' => $newWidth / $originalWidth // 精确计算实际缩放比例
    ];
}

/**
 * 图片压缩函数
 */
function compressImage($sourcePath, $destPath, &$compressedInfo, $maxSize = 5242880) {
    $imageInfo = getimagesize($sourcePath);
    if(!$imageInfo) return false;
    
    list($width, $height, $type) = $imageInfo;
    $originalSize = filesize($sourcePath);

    // 计算压缩尺寸（使用共用算法）
    $compressedInfo = calculateCompressedSize($width, $height, $originalSize, $maxSize);
    
    // 存储原始尺寸用于水印计算
    $result = [
        'success' => false,
        'path' => $destPath,
        'original_width' => $width,
        'original_height' => $height,
        'final_width' => $compressedInfo['width'],
        'final_height' => $compressedInfo['height'],
        'scale' => $compressedInfo['scale']
    ];

    // 原始大小符合要求直接复制
    if($originalSize <= $maxSize) {
        $copyResult = copy($sourcePath, $destPath);
        $result['success'] = $copyResult;
        return $result;
    }

    // 创建图像资源
    switch($type) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($sourcePath);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($sourcePath);
            imagesavealpha($image, true);
            break;
        case IMAGETYPE_GIF:
            $image = imagecreatefromgif($sourcePath);
            break;
        default:
            return $result;
    }

    // 创建调整大小后的图像
    $newImage = imagecreatetruecolor($compressedInfo['width'], $compressedInfo['height']);
    
    if($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagecolortransparent($newImage, imagecolorallocatealpha($newImage, 0, 0, 0, 127));
        imagesavealpha($newImage, true);
    }

    // 高质量缩放
    imagecopyresampled($newImage, $image, 0, 0, 0, 0, 
                      $compressedInfo['width'], $compressedInfo['height'], 
                      $width, $height);
    imagedestroy($image);

    // 根据格式保存图像
    $saveResult = false;
    switch($type) {
        case IMAGETYPE_JPEG:
            $saveResult = imagejpeg($newImage, $destPath, 85);
            break;
        case IMAGETYPE_PNG:
            $saveResult = imagepng($newImage, $destPath, 6);
            break;
        case IMAGETYPE_GIF:
            $saveResult = imagegif($newImage, $destPath);
            break;
    }

    imagedestroy($newImage);
    
    // 二次检查文件大小，如果仍然过大则降低质量
    if($saveResult && filesize($destPath) > $maxSize) {
        // 重新创建图像资源进行二次压缩
        switch($type) {
            case IMAGETYPE_JPEG:
                $newImage2 = imagecreatefromjpeg($destPath);
                $saveResult = imagejpeg($newImage2, $destPath, 70);
                imagedestroy($newImage2);
                break;
            case IMAGETYPE_PNG:
                $newImage2 = imagecreatefrompng($destPath);
                imagesavealpha($newImage2, true);
                $saveResult = imagepng($newImage2, $destPath, 9);
                imagedestroy($newImage2);
                break;
        }
    }

    $result['success'] = $saveResult;
    return $result;
}

/**
 * 添加图片水印函数（使用6.png作为水印）
 */
function addWatermark($sourcePath, $destPath, $originalWidth, $finalWidth, $watermarkSize = 15, $opacity = 80, $position = 'bottom-right') {
    // 调试：记录水印处理参数
    error_log("开始添加水印 - 源路径: $sourcePath, 目标路径: $destPath, 大小: $watermarkSize%, 透明度: $opacity%, 位置: $position");
    
    // 计算缩放比例
    $scale = $finalWidth / $originalWidth;
    error_log("图片缩放比例: $scale (原始宽度: $originalWidth, 最终宽度: $finalWidth)");
    
    // 水印图片路径 - 使用HTTPS绝对路径确保安全
    $watermarkUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/6.png'; // 使用当前域名的HTTPS路径
    $watermarkPath = $_SERVER['DOCUMENT_ROOT'] . '/6.png'; // 服务器本地路径
    
    // 检查水印图片是否存在
    if(!file_exists($watermarkPath)) {
        error_log("水印图片不存在于基础路径: " . $watermarkPath);
        // 尝试其他可能的路径
        $alternativePaths = [
            $_SERVER['DOCUMENT_ROOT'] . '/uploads/6.png',
            $_SERVER['DOCUMENT_ROOT'] . '/images/6.png',
            __DIR__ . '/6.png',
            __DIR__ . '/uploads/6.png'
        ];
        
        foreach($alternativePaths as $altPath) {
            if(file_exists($altPath)) {
                $watermarkPath = $altPath;
                $watermarkUrl = 'https://' . $_SERVER['HTTP_HOST'] . str_replace($_SERVER['DOCUMENT_ROOT'], '', $altPath);
                error_log("在替代路径找到水印图片: $watermarkPath");
                break;
            }
        }
        
        if(!file_exists($watermarkPath)) {
            error_log("所有路径均未找到水印图片6.png");
            return ['status' => false, 'error' => '水印图片6.png未找到，请检查文件是否存在', 'watermark_size_used' => 0];
        }
    }
    
    // 获取水印图片信息
    $watermarkInfo = getimagesize($watermarkPath);
    if(!$watermarkInfo) {
        error_log("无法获取水印图片信息: " . $watermarkPath);
        return ['status' => false, 'error' => '无法解析水印图片信息', 'watermark_size_used' => 0];
    }
    list($wmWidth, $wmHeight, $wmType) = $watermarkInfo;
    error_log("水印原始尺寸: $wmWidth x $wmHeight, 类型: $wmType");
    
    // 计算水印大小（按百分比计算，1-50）
    $watermarkScale = $watermarkSize / 100;
    $adjustedWidth = max(20, min(intval(round($finalWidth * $watermarkScale)), intval(round($finalWidth * 0.5))));
    $adjustedHeight = intval(round($wmHeight * ($adjustedWidth / $wmWidth)));
    error_log("水印调整后尺寸: $adjustedWidth x $adjustedHeight (基于原图的 $watermarkSize%)");
    
    // 检查源文件
    if(!file_exists($sourcePath)) {
        error_log("源图片不存在: " . $sourcePath);
        return ['status' => false, 'error' => '源图片文件未找到', 'watermark_size_used' => 0];
    }
    
    $imageInfo = getimagesize($sourcePath);
    if(!$imageInfo) {
        error_log("无法获取图片信息: " . $sourcePath);
        return ['status' => false, 'error' => '无法解析图片信息', 'watermark_size_used' => 0];
    }
    
    list($width, $height, $type) = $imageInfo;
    error_log("源图片尺寸: $width x $height, 类型: $type");
    
    // 创建图像资源
    try {
        switch($type) {
            case IMAGETYPE_JPEG:
                $image = imagecreatefromjpeg($sourcePath);
                break;
            case IMAGETYPE_PNG:
                $image = imagecreatefrompng($sourcePath);
                imagesavealpha($image, true);
                break;
            case IMAGETYPE_GIF:
                $image = imagecreatefromgif($sourcePath);
                break;
            default:
                return ['status' => false, 'error' => '不支持的图片格式', 'watermark_size_used' => 0];
        }
    } catch(Exception $e) {
        error_log("创建图像资源失败: " . $e->getMessage());
        return ['status' => false, 'error' => '图片处理失败', 'watermark_size_used' => 0];
    }
    
    // 创建水印图像资源，特别处理透明通道
    try {
        switch($wmType) {
            case IMAGETYPE_JPEG:
                $watermark = imagecreatefromjpeg($watermarkPath);
                break;
            case IMAGETYPE_PNG:
                $watermark = imagecreatefrompng($watermarkPath);
                imagesavealpha($watermark, true); // 保留PNG透明通道
                break;
            case IMAGETYPE_GIF:
                $watermark = imagecreatefromgif($watermarkPath);
                break;
            default:
                return ['status' => false, 'error' => '不支持的水印图片格式', 'watermark_size_used' => 0];
        }
    } catch(Exception $e) {
        error_log("创建水印图像资源失败: " . $e->getMessage());
        return ['status' => false, 'error' => '水印处理失败', 'watermark_size_used' => 0];
    }
    
    // 调整水印大小，保持透明通道
    $resizedWatermark = imagecreatetruecolor($adjustedWidth, $adjustedHeight);
    
    // 为调整后的水印设置透明背景
    if($wmType == IMAGETYPE_PNG || $wmType == IMAGETYPE_GIF) {
        $transparent = imagecolorallocatealpha($resizedWatermark, 0, 0, 0, 127);
        imagefill($resizedWatermark, 0, 0, $transparent);
        imagesavealpha($resizedWatermark, true); // 关键：保留透明通道
    }
    
    // 调整水印大小
    $resizeSuccess = imagecopyresampled(
        $resizedWatermark, 
        $watermark, 
        0, 0, 0, 0, 
        $adjustedWidth, $adjustedHeight, 
        $wmWidth, $wmHeight
    );
    
    if(!$resizeSuccess) {
        error_log("水印大小调整失败");
        imagedestroy($watermark);
        imagedestroy($resizedWatermark);
        return ['status' => false, 'error' => '水印大小调整失败', 'watermark_size_used' => 0];
    }
    
    imagedestroy($watermark);
    
    // 设置水印透明度（0-100转0-127）
    $alpha = (127 - round(127 * $opacity / 100));
    imagefilter($resizedWatermark, IMG_FILTER_COLORIZE, 0, 0, 0, $alpha);
    
    // 根据选择的位置计算水印坐标（确保与前端一致）
    $margin = 20; // 边距
    switch($position) {
        case 'top-left':
            $x = $margin;
            $y = $margin;
            break;
        case 'top-center':
            $x = ($width - $adjustedWidth) / 2;
            $y = $margin;
            break;
        case 'top-right':
            $x = $width - $adjustedWidth - $margin;
            $y = $margin;
            break;
        case 'middle-left':
            $x = $margin;
            $y = ($height - $adjustedHeight) / 2;
            break;
        case 'middle-center':
            $x = ($width - $adjustedWidth) / 2;
            $y = ($height - $adjustedHeight) / 2;
            break;
        case 'middle-right':
            $x = $width - $adjustedWidth - $margin;
            $y = ($height - $adjustedHeight) / 2;
            break;
        case 'bottom-left':
            $x = $margin;
            $y = $height - $adjustedHeight - $margin;
            break;
        case 'bottom-center':
            $x = ($width - $adjustedWidth) / 2;
            $y = $height - $adjustedHeight - $margin;
            break;
        case 'bottom-right': // 默认右下角
        default:
            $x = $width - $adjustedWidth - $margin;
            $y = $height - $adjustedHeight - $margin;
            break;
    }
    
    // 确保水印在图片范围内
    $x = max(0, min(round($x), $width - $adjustedWidth));
    $y = max(0, min(round($y), $height - $adjustedHeight));
    error_log("水印位置坐标: x=$x, y=$y");
    
    // 添加水印到图片
    $watermarkAdded = imagecopy(
        $image, 
        $resizedWatermark, 
        $x, $y, 
        0, 0, 
        $adjustedWidth, $adjustedHeight
    );
    
    if(!$watermarkAdded) {
        error_log("添加水印到图片失败");
        imagedestroy($image);
        imagedestroy($resizedWatermark);
        return ['status' => false, 'error' => '添加水印到图片失败', 'watermark_size_used' => 0];
    }
    
    imagedestroy($resizedWatermark);
    
    // 保存图片
    $result = false;
    switch($type) {
        case IMAGETYPE_JPEG:
            $result = imagejpeg($image, $destPath, 90);
            break;
        case IMAGETYPE_PNG:
            $result = imagepng($image, $destPath);
            break;
        case IMAGETYPE_GIF:
            $result = imagegif($image, $destPath);
            break;
    }
    
    imagedestroy($image);
    
    if(!$result || !file_exists($destPath)) {
        error_log("保存水印图片失败: " . $destPath);
        return ['status' => false, 'error' => '保存水印图片失败', 'watermark_size_used' => 0];
    }
    
    error_log("水印添加成功，保存路径: $destPath");
    
    return [
        'status' => true, 
        'watermark_size_used' => $watermarkSize,
        'watermark_position_used' => $position,
        'adjusted_width' => $adjustedWidth,
        'adjusted_height' => $adjustedHeight,
        'scale_used' => $scale
    ];
}

if($_SERVER['REQUEST_METHOD'] == 'POST' && empty($error)) {
    if(isset($_FILES['photo']) && $_FILES['photo']['error'] == 0) {
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif'];
        $file_type = $_FILES['photo']['type'];
        
        if(!in_array($file_type, $allowed_types)) {
            $error = '不支持的文件类型，仅支持JPEG、PNG和GIF';
        } elseif(!isset($_POST['allow_use'])) {
            $error = '请同意平台使用条款才能上传图片';
        } else {
            // 水印参数处理
            $watermarkSize = max(5, min(50, intval($_POST['watermark_size'] ?? 15))); // 5-50%
            $watermarkOpacity = max(10, min(100, intval($_POST['watermark_opacity'] ?? 80)));
            $watermarkPosition = in_array($_POST['watermark_position'] ?? '', 
                ['top-left', 'top-center', 'top-right', 'middle-left', 'middle-center', 
                 'middle-right', 'bottom-left', 'bottom-center', 'bottom-right']) 
                ? $_POST['watermark_position'] : 'bottom-right';
            
            $filename = uniqid() . '_' . basename($_FILES['photo']['name']);
            $target_path = $upload_dir . $filename;
            $temp_path = $upload_dir . 'temp_' . $filename;

            // 1. 先压缩图片（获取原始和最终尺寸）
            $compressedInfo = [];
            $compressResult = compressImage($_FILES['photo']['tmp_name'], $temp_path, $compressedInfo);
            if(!$compressResult['success']) {
                $error = '图片压缩失败，请尝试更换图片';
                @unlink($temp_path);
            } 
            // 检查临时文件是否可读取
            elseif(!is_readable($temp_path)) {
                $error = '临时文件无法读取，可能是权限问题';
                @unlink($temp_path);
            }
            // 2. 再添加水印
            else {
                $watermarkResult = addWatermark(
                    $temp_path, 
                    $target_path,
                    $compressResult['original_width'],
                    $compressResult['final_width'],
                    $watermarkSize, 
                    $watermarkOpacity,
                    $watermarkPosition
                );
                
                if(!$watermarkResult['status']) {
                    $error = '添加水印失败: ' . $watermarkResult['error'];
                    @unlink($temp_path);
                    @unlink($target_path);
                } 
                // 3. 验证最终文件
                elseif(!file_exists($target_path)) {
                    $error = '文件保存失败，请重试';
                    @unlink($temp_path);
                } else {
                    // 清理临时文件
                    @unlink($temp_path);
                    
                    // 保存到数据库 - 已修正字段名以匹配数据库
                    try {
                        $stmt = $pdo->prepare("INSERT INTO photos (
                            user_id, title, category, aircraft_model, 
                            registration_number, `拍摄时间`, `拍摄地点`, 
                            filename, approved, allow_use, created_at,
                            watermark_size, watermark_opacity, watermark_position,
                            original_width, original_height, final_width, final_height
                        ) VALUES (
                            :user_id, :title, :category, :aircraft_model, 
                            :registration_number, :shooting_time, :shooting_location, 
                            :filename, 0, :allow_use, NOW(),
                            :watermark_size, :watermark_opacity, :watermark_position,
                            :original_width, :original_height, :final_width, :final_height
                        )");
                        
                        $stmt->execute([
                            ':user_id' => $_SESSION['user_id'],
                            ':title' => $_POST['title'],
                            ':category' => $_POST['category'],
                            ':aircraft_model' => $_POST['aircraft_model'],
                            ':registration_number' => $_POST['registration_number'],
                            ':shooting_time' => $_POST['shooting_time'],
                            ':shooting_location' => $_POST['shooting_location'],
                            ':filename' => $filename,
                            ':allow_use' => $_POST['allow_use'],
                            ':watermark_size' => $watermarkSize,
                            ':watermark_opacity' => $watermarkOpacity,
                            ':watermark_position' => $watermarkPosition,
                            ':original_width' => $compressResult['original_width'],
                            ':original_height' => $compressResult['original_height'],
                            ':final_width' => $compressResult['final_width'],
                            ':final_height' => $compressResult['final_height']
                        ]);
                        
                        $success = '图片上传成功，已添加水印（大小: ' . $watermarkSize . '%, 位置: ' . 
                                  getPositionText($watermarkPosition) . '），等待审核';
                        $_POST = [];
                    } catch(PDOException $e) {
                        $error = "数据库保存失败: " . $e->getMessage();
                        @unlink($target_path);
                        error_log("数据库错误: " . $e->getMessage());
                    }
                }
            }
        }
    } else {
        $error = '请选择要上传的图片（错误码：' . ($_FILES['photo']['error'] ?? '未知') . '）';
    }
}

/**
 * 将位置代码转换为中文显示
 */
function getPositionText($positionCode) {
    $positions = [
        'top-left' => '左上角',
        'top-center' => '上中',
        'top-right' => '右上角',
        'middle-left' => '左中',
        'middle-center' => '居中',
        'middle-right' => '右中',
        'bottom-left' => '左下角',
        'bottom-center' => '下中',
        'bottom-right' => '右下角'
    ];
    return $positions[$positionCode] ?? '右下角';
}

/**
 * 防抖函数，避免频繁API请求
 */
function debounce($func, $wait = 500) {
    $timeout = null;
    return function() use ($func, $wait, &$timeout) {
        $context = $this;
        $args = func_get_args();
        clearTimeout($timeout);
        $timeout = setTimeout(function() use ($func, $context, $args) {
            call_user_func_array([$func, $context], $args);
        }, $wait);
    };
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horizon Photos - 上传图片</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <meta http-equiv="Content-Security-Policy" content="upgrade-insecure-requests">
    <style>
        :root {
            --primary: #165DFF;
            --primary-light: #4080FF;
            --primary-lighter: #E8F3FF;
            --success: #00B42A;
            --success-light: #E6FFED;
            --danger: #F53F3F;
            --danger-light: #FFECE8;
            --gray-100: #F2F3F5;
            --gray-200: #E5E6EB;
            --gray-400: #86909C;
            --gray-500: #4E5969;
            --white: #FFFFFF;
            --radius-lg: 8px;
            --shadow-sm: 0 2px 8px rgba(0, 0, 0, 0.08);
            --transition: all 0.25s ease;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', 'Segoe UI', Roboto, sans-serif;
            background-color: #F7F8FA;
            color: #1D2129;
            line-height: 1.5;
        }

        .nav {
            background-color: var(--primary);
            padding: 14px 20px;
            box-shadow: var(--shadow-sm);
            position: sticky;
            top: 0;
            z-index: 100;
        }
        .nav-container {
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .logo {
            color: white;
            font-size: 1.2rem;
            font-weight: 600;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .nav-links {
            display: flex;
            gap: 4px;
        }
        .nav a {
            color: white;
            text-decoration: none;
            padding: 8px 14px;
            border-radius: var(--radius-lg);
            font-size: 14px;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 6px;
        }
        .nav a:hover {
            background-color: rgba(255, 255, 255, 0.15);
        }
        .nav a.active {
            background-color: rgba(255, 255, 255, 0.2);
            font-weight: 500;
        }

        .container {
            max-width: 800px;
            margin: 30px auto;
            padding: 0 20px;
        }

        .page-header {
            margin-bottom: 30px;
            text-align: center;
        }
        .page-title {
            font-size: 1.8rem;
            color: var(--primary);
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        .page-desc {
            color: var(--gray-500);
            font-size: 14px;
            max-width: 600px;
            margin: 0 auto;
        }

        .alert {
            padding: 14px 16px;
            border-radius: var(--radius-lg);
            margin-bottom: 24px;
            font-weight: 500;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .alert-error {
            background-color: var(--danger-light);
            color: var(--danger);
            border-left: 4px solid var(--danger);
        }
        .alert-success {
            background-color: var(--success-light);
            color: var(--success);
            border-left: 4px solid var(--success);
        }

        .upload-form {
            background-color: var(--white);
            padding: 30px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-sm);
        }

        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group.full-width {
            grid-column: 1 / -1;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #1D2129;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        label i {
            color: var(--primary-light);
        }
        
        .form-hint {
            font-size: 12px;
            color: var(--gray-400);
            margin-top: 4px;
        }
        
        input, select {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            font-family: inherit;
            font-size: 14px;
            transition: var(--transition);
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(22, 93, 255, 0.1);
        }

        /* 水印设置样式 */
        .watermark-settings {
            background-color: var(--primary-lighter);
            padding: 20px;
            border-radius: var(--radius-lg);
            margin: 20px 0;
        }
        
        .watermark-settings h3 {
            margin-top: 0;
            margin-bottom: 16px;
            color: var(--primary);
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .slider-group {
            margin-bottom: 16px;
        }
        
        .slider-container {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        input[type="range"] {
            flex: 1;
            padding: 0;
            height: 6px;
            appearance: none;
            background: var(--gray-200);
            border-radius: 3px;
        }
        
        input[type="range"]::-webkit-slider-thumb {
            appearance: none;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--primary);
            cursor: pointer;
        }
        
        .slider-value {
            width: 50px;
            text-align: center;
            font-weight: 500;
            padding: 6px;
            background-color: var(--white);
            border-radius: var(--radius-lg);
            border: 1px solid var(--gray-200);
        }

        /* 水印位置选择 */
        .position-selector {
            margin-top: 20px;
        }
        
        .position-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-top: 10px;
        }
        
        .position-option {
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 10px;
            text-align: center;
            cursor: pointer;
            transition: var(--transition);
            position: relative;
        }
        
        .position-option.selected {
            border-color: var(--primary);
            background-color: rgba(22, 93, 255, 0.05);
        }
        
        .position-option input {
            display: none;
        }
        
        .position-option span {
            font-size: 13px;
        }
        
        .position-option::before {
            content: '';
            position: absolute;
            width: 100%;
            height: 100%;
            top: 0;
            left: 0;
            pointer-events: none;
        }
        
        .position-top-left::before {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='3' width='7' height='7'/%3E%3C/svg%3E") no-repeat top 5px left 5px;
        }
        
        .position-top-center::before {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='8.5' y='3' width='7' height='7'/%3E%3C/svg%3E") no-repeat top 5px center;
        }
        
        .position-top-right::before {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='14' y='3' width='7' height='7'/%3E%3C/svg%3E") no-repeat top 5px right 5px;
        }
        
        .position-middle-left::before {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='8.5' width='7' height='7'/%3E%3C/svg%3E") no-repeat center left 5px;
        }
        
        .position-middle-center::before {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='8.5' y='8.5' width='7' height='7'/%3E%3C/svg%3E") no-repeat center center;
        }
        
        .position-middle-right::before {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='14' y='8.5' width='7' height='7'/%3E%3C/svg%3E") no-repeat center right 5px;
        }
        
        .position-bottom-left::before {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='3' y='14' width='7' height='7'/%3E%3C/svg%3E") no-repeat bottom 5px left 5px;
        }
        
        .position-bottom-center::before {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='8.5' y='14' width='7' height='7'/%3E%3C/svg%3E") no-repeat bottom 5px center;
        }
        
        .position-bottom-right::before {
            background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='none' stroke='%2386909C' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3E%3Crect x='14' y='14' width='7' height='7'/%3E%3C/svg%3E") no-repeat bottom 5px right 5px;
        }

        /* 图片预览和水印 */
        .image-preview {
            margin-top: 20px;
            border-radius: var(--radius-lg);
            overflow: hidden;
            display: none;
        }
        
        .preview-wrapper {
            position: relative;
            display: inline-block;
            border: 1px solid var(--gray-200);
            border-radius: var(--radius-lg);
            overflow: hidden;
            background-color: white;
        }
        
        .preview-image {
            max-width: 100%;
            height: auto;
            display: block;
        }
        
        /* 预览图上的水印 */
        .preview-watermark {
            position: absolute;
            pointer-events: none;
        }

        .image-upload {
            border: 2px dashed var(--gray-200);
            border-radius: var(--radius-lg);
            padding: 30px;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
            position: relative;
            overflow: hidden;
        }
        
        .image-upload:hover {
            border-color: var(--primary-light);
            background-color: var(--primary-lighter);
        }
        
        .image-upload input {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            opacity: 0;
            cursor: pointer;
        }
        
        .upload-icon {
            font-size: 3rem;
            color: var(--primary-light);
            margin-bottom: 16px;
        }

        .checkbox-group {
            display: flex;
            align-items: flex-start;
            gap: 10px;
            padding: 16px;
            background-color: var(--gray-100);
            border-radius: var(--radius-lg);
            margin-top: 16px;
        }
        
        .checkbox-group input {
            width: auto;
            margin-top: 3px;
        }
        
        .form-actions {
            text-align: center;
            margin-top: 30px;
        }
        
        .btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 10px 24px;
            border-radius: var(--radius-lg);
            cursor: pointer;
            transition: var(--transition);
            font-size: 14px;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn:hover {
            background-color: var(--primary-light);
        }
        
        .btn-secondary {
            background-color: var(--gray-100);
            color: var(--gray-500);
        }
        
        .preview-info {
            font-size: 12px;
            color: var(--gray-400);
            margin-top: 8px;
            text-align: right;
        }
        
        /* 图片处理信息显示 */
        .processing-info {
            font-size: 12px;
            color: var(--primary);
            margin-top: 8px;
            text-align: left;
        }
        
        /* 加载指示器 */
        .loading-indicator {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* API请求状态指示器 */
        .api-status {
            margin-left: 8px;
            font-size: 12px;
            color: var(--gray-400);
        }
    </style>
</head>
<body>
    <div class="nav">
        <div class="nav-container">
            <a href="index.php" class="logo">
                <i class="fas fa-plane"></i>
                <span>Horizon Photos</span>
            </a>
            <div class="nav-links">
                <a href="index.php">
                    <i class="fas fa-home"></i>
                    <span>首页</span>
                </a>
                <a href="all_photos.php">
                    <i class="fas fa-images"></i>
                    <span>全部图片</span>
                </a>
                <a href="user_center.php">
                    <i class="fas fa-user"></i>
                    <span>用户中心</span>
                </a>
                <a href="upload.php" class="active">
                    <i class="fas fa-upload"></i>
                    <span>上传图片</span>
                </a>
                <?php if($_SESSION['is_admin']): ?>
                    <a href="admin_review.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>管理后台</span>
                    </a>
                <?php endif; ?>
                <a href="logout.php">
                    <i class="fas fa-sign-out-alt"></i>
                    <span>退出</span>
                </a>
            </div>
        </div>
    </div>

    <div class="container">
        <div class="page-header">
            <h1 class="page-title">
                <i class="fas fa-cloud-upload-alt"></i>
                上传航空摄影作品
            </h1>
            <p class="page-desc">请填写图片信息并上传，所有带 <span style="color:var(--danger)">*</span> 的字段为必填项</p>
        </div>
        
        <?php if($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <?php if($success): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <?php echo $success; ?>
            </div>
        <?php endif; ?>
        
        <div class="upload-form">
            <form method="post" action="upload.php" enctype="multipart/form-data" id="uploadForm">
                <div class="form-grid">
                    <div class="form-group">
                        <label for="title">
                            <i class="fas fa-heading"></i>
                            图片标题 <span style="color:var(--danger)">*</span>
                        </label>
                        <input type="text" id="title" name="title" required 
                               value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>"
                               placeholder="请输入图片标题">
                    </div>
                    
                    <div class="form-group">
                        <label for="category">
                            <i class="fas fa-building"></i>
                            航空公司 <span style="color:var(--danger)">*</span>
                        </label>
                        <input type="text" id="category" name="category" required
                               value="<?php echo isset($_POST['category']) ? htmlspecialchars($_POST['category']) : ''; ?>"
                               placeholder="例如：中国国际航空">
                    </div>
                    
                    <div class="form-group">
                        <label for="aircraft_model">
                            <i class="fas fa-plane-departure"></i>
                            飞机型号 <span style="color:var(--danger)">*</span>
                        </label>
                        <input type="text" id="aircraft_model" name="aircraft_model" required
                               value="<?php echo isset($_POST['aircraft_model']) ? htmlspecialchars($_POST['aircraft_model']) : ''; ?>"
                               placeholder="例如：Boeing 737-800">
                    </div>
                    
                    <div class="form-group">
                        <label for="registration_number">
                            <i class="fas fa-id-card-alt"></i>
                            飞机注册号 <span style="color:var(--danger)">*</span>
                            <span class="api-status" id="regApiStatus"></span>
                        </label>
                        <input type="text" id="registration_number" name="registration_number" required
                               value="<?php echo isset($_POST['registration_number']) ? htmlspecialchars($_POST['registration_number']) : ''; ?>"
                               placeholder="例如：B-1234">
                        <span class="form-hint">输入后将自动获取并填充飞机信息</span>
                    </div>
                    
                    <div class="form-group">
                        <label for="shooting_time">
                            <i class="fas fa-clock"></i>
                            拍摄时间 <span style="color:var(--danger)">*</span>
                        </label>
                        <input type="datetime-local" id="shooting_time" name="shooting_time" required
                               value="<?php echo isset($_POST['shooting_time']) ? htmlspecialchars($_POST['shooting_time']) : ''; ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="shooting_location">
                            <i class="fas fa-map-marker-alt"></i>
                            拍摄地点 <span style="color:var(--danger)">*</span>
                        </label>
                        <input type="text" id="shooting_location" name="shooting_location" required
                               value="<?php echo isset($_POST['shooting_location']) ? htmlspecialchars($_POST['shooting_location']) : ''; ?>"
                               placeholder="例如：北京首都国际机场">
                    </div>
                </div>
                
                <!-- 水印设置 -->
                <div class="watermark-settings form-group full-width">
                    <h3>
                        <i class="fas fa-images"></i>
                        图片水印设置
                    </h3>
                    
                    <div class="form-grid">
                        <div class="slider-group">
                            <label for="watermark_size">水印大小（占原图比例）</label>
                            <div class="slider-container">
                                <input type="range" id="watermark_size" name="watermark_size" 
                                       min="5" max="50" step="1" value="<?php echo isset($_POST['watermark_size']) ? intval($_POST['watermark_size']) : 15; ?>">
                                <span class="slider-value" id="watermark_size_value">
                                    <?php echo isset($_POST['watermark_size']) ? intval($_POST['watermark_size']) : 15; ?>%
                                </span>
                            </div>
                            <span class="form-hint">调整水印图片的大小（原图的5-50%）</span>
                        </div>
                        
                        <div class="slider-group">
                            <label for="watermark_opacity">水印透明度</label>
                            <div class="slider-container">
                                <input type="range" id="watermark_opacity" name="watermark_opacity" 
                                       min="10" max="100" step="5" value="<?php echo isset($_POST['watermark_opacity']) ? intval($_POST['watermark_opacity']) : 80; ?>">
                                <span class="slider-value" id="watermark_opacity_value">
                                    <?php echo isset($_POST['watermark_opacity']) ? intval($_POST['watermark_opacity']) : 80; ?>%
                                </span>
                            </div>
                            <span class="form-hint">调整水印的透明度（10-100%）</span>
                        </div>
                    </div>
                    
                    <!-- 水印位置选择 -->
                    <div class="position-selector">
                        <label>水印位置</label>
                        <div class="position-grid">
                            <label class="position-option position-top-left <?php echo (!isset($_POST['watermark_position']) || $_POST['watermark_position'] == 'top-left') ? 'selected' : ''; ?>">
                                <input type="radio" name="watermark_position" value="top-left" <?php echo (!isset($_POST['watermark_position']) || $_POST['watermark_position'] == 'top-left') ? 'checked' : ''; ?>>
                                <span>左上角</span>
                            </label>
                            <label class="position-option position-top-center <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'top-center' ? 'selected' : ''; ?>">
                                <input type="radio" name="watermark_position" value="top-center" <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'top-center' ? 'checked' : ''; ?>>
                                <span>上中</span>
                            </label>
                            <label class="position-option position-top-right <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'top-right' ? 'selected' : ''; ?>">
                                <input type="radio" name="watermark_position" value="top-right" <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'top-right' ? 'checked' : ''; ?>>
                                <span>右上角</span>
                            </label>
                            
                            <label class="position-option position-middle-left <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'middle-left' ? 'selected' : ''; ?>">
                                <input type="radio" name="watermark_position" value="middle-left" <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'middle-left' ? 'checked' : ''; ?>>
                                <span>左中</span>
                            </label>
                            <label class="position-option position-middle-center <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'middle-center' ? 'selected' : ''; ?>">
                                <input type="radio" name="watermark_position" value="middle-center" <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'middle-center' ? 'checked' : ''; ?>>
                                <span>居中</span>
                            </label>
                            <label class="position-option position-middle-right <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'middle-right' ? 'selected' : ''; ?>">
                                <input type="radio" name="watermark_position" value="middle-right" <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'middle-right' ? 'checked' : ''; ?>>
                                <span>右中</span>
                            </label>
                            
                            <label class="position-option position-bottom-left <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'bottom-left' ? 'selected' : ''; ?>">
                                <input type="radio" name="watermark_position" value="bottom-left" <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'bottom-left' ? 'checked' : ''; ?>>
                                <span>左下角</span>
                            </label>
                            <label class="position-option position-bottom-center <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'bottom-center' ? 'selected' : ''; ?>">
                                <input type="radio" name="watermark_position" value="bottom-center" <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'bottom-center' ? 'checked' : ''; ?>>
                                <span>下中</span>
                            </label>
                            <label class="position-option position-bottom-right <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'bottom-right' ? 'selected' : ''; ?>">
                                <input type="radio" name="watermark_position" value="bottom-right" <?php echo isset($_POST['watermark_position']) && $_POST['watermark_position'] == 'bottom-right' ? 'checked' : ''; ?>>
                                <span>右下角</span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group full-width">
                    <label for="photo">
                        <i class="fas fa-image"></i>
                        选择图片 <span style="color:var(--danger)">*</span>
                    </label>
                    <div class="image-upload" id="imageUploadArea">
                        <div class="upload-icon">
                            <i class="fas fa-cloud-upload-alt"></i>
                        
                        </div>
                        <div>点击或拖拽图片到此处上传</div>
                        <div class="form-hint">支持 JPG、PNG、GIF 格式，超过5MB将自动压缩</div>
                        <input type="file" id="photo" name="photo" accept="image/*" required>
                    </div>
                    
                    <!-- 带水印的预览区域 -->
                    <div class="image-preview" id="imagePreview">
                        <div class="preview-wrapper">
                            <img id="previewImage" class="preview-image" src="" alt="图片预览">
                            <img id="previewWatermark" class="preview-watermark" src="" alt="水印预览">
                        </div>
                        <div class="processing-info" id="processingInfo">
                            原始尺寸: <span id="originalDimensions">-- x --</span> | 
                            处理后尺寸: <span id="processedDimensions">-- x --</span>
                        </div>
                        <div class="preview-info" id="previewInfo">
                            水印大小: <span id="displayedWatermarkSize">15%</span> | 
                            位置: <span id="displayedWatermarkPosition">左上角</span>
                        </div>
                        <div style="margin-top:10px; text-align:right;">
                            <button type="button" class="btn btn-secondary" id="removePreview">
                                <i class="fas fa-times"></i> 移除图片
                            </button>
                        </div>
                    </div>

                    <div class="checkbox-group">
                        <input type="checkbox" id="allow_use" name="allow_use" value="1" 
                               <?php echo isset($_POST['allow_use']) ? 'checked' : ''; ?>>
                        <div>
                            <p><strong>允许平台在不另行通知的情况下使用此图片</strong></p>
                            <small>用途包括但不限于：网站首页展示、专题合集、社交媒体宣传等（将保留图片作者信息）。不同意此条款将无法完成上传。</small>
                        </div>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="submit" class="btn">
                        <i class="fas fa-paper-plane"></i> 提交上传
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        // 防抖函数实现（避免频繁API请求）
        function debounce(func, wait = 500) {
            let timeout;
            return function(...args) {
                clearTimeout(timeout);
                timeout = setTimeout(() => func.apply(this, args), wait);
            };
        }

        // 水印预览控制
        const sizeSlider = document.getElementById('watermark_size');
        const sizeValue = document.getElementById('watermark_size_value');
        const opacitySlider = document.getElementById('watermark_opacity');
        const opacityValue = document.getElementById('watermark_opacity_value');
        const positionRadios = document.querySelectorAll('input[name="watermark_position"]');
        const previewWatermark = document.getElementById('previewWatermark');
        const previewImage = document.getElementById('previewImage');
        const displayedWatermarkSize = document.getElementById('displayedWatermarkSize');
        const displayedWatermarkPosition = document.getElementById('displayedWatermarkPosition');
        
        let originalImageWidth = 0;
        let originalImageHeight = 0;
        let originalFileSize = 0;
        let originalWatermarkWidth = 0;
        let originalWatermarkHeight = 0;
        let predictedWidth = 0;
        let predictedHeight = 0;
        let scaleRatio = 1.0;
        let watermarkUrl = 'https://' + window.location.hostname + '/6.png'; // 使用当前域名的HTTPS路径
        
        const positionTextMap = {
            'top-left': '左上角',
            'top-center': '上中',
            'top-right': '右上角',
            'middle-left': '左中',
            'middle-center': '居中',
            'middle-right': '右中',
            'bottom-left': '左下角',
            'bottom-center': '下中',
            'bottom-right': '右下角'
        };
        
        function calculateCompressedSize(originalWidth, originalHeight, originalSize, maxSize = 5242880) {
            if(originalSize <= maxSize) {
                return {
                    width: originalWidth,
                    height: originalHeight,
                    scale: 1.0
                };
            }
            const scale = Math.sqrt(maxSize / originalSize) * 0.9;
            let newWidth = Math.max(400, Math.round(originalWidth * scale));
            const aspectRatio = originalWidth / originalHeight;
            let newHeight = Math.round(newWidth / aspectRatio);
            if(newHeight < 300) {
                newHeight = 300;
                newWidth = Math.round(newHeight * aspectRatio);
            }
            return {
                width: newWidth,
                height: newHeight,
                scale: newWidth / originalWidth
            };
        }
        
        function loadWatermarkDimensions() {
            const img = new Image();
            img.crossOrigin = "anonymous";
            img.onload = function() {
                originalWatermarkWidth = this.width;
                originalWatermarkHeight = this.height;
                updatePreviewWatermark(sizeSlider.value, opacitySlider.value, getSelectedPosition());
            };
            img.onerror = function() {
                console.error("无法加载水印图片，请检查6.png是否存在");
                alert("水印预览图片加载失败，请确保6.png文件存在");
            };
            img.src = watermarkUrl + '?' + new Date().getTime();
            previewWatermark.src = watermarkUrl;
        }
        
        function getSelectedPosition() {
            for(const radio of positionRadios) {
                if(radio.checked) {
                    return radio.value;
                }
            }
            return 'top-left';
        }
        
        function updateWatermarkSettings() {
            const size = parseInt(sizeSlider.value, 10);
            const opacity = parseInt(opacitySlider.value, 10);
            const position = getSelectedPosition();
            
            sizeValue.textContent = `${size}%`;
            opacityValue.textContent = `${opacity}%`;
            displayedWatermarkSize.textContent = `${size}%`;
            displayedWatermarkPosition.textContent = positionTextMap[position] || position;
            
            document.querySelectorAll('.position-option').forEach(option => {
                option.classList.remove('selected');
            });
            document.querySelector(`.position-option:has(input[value="${position}"])`)?.classList.add('selected');
            
            updatePreviewWatermark(size, opacity, position);
        }
        
        function updatePreviewWatermark(size, opacity, position) {
            if(previewWatermark && originalImageWidth > 0 && originalWatermarkWidth > 0 && predictedWidth > 0) {
                const watermarkScale = size / 100;
                let adjustedWidth = Math.round(predictedWidth * watermarkScale);
                adjustedWidth = Math.max(20, Math.min(adjustedWidth, Math.round(predictedWidth * 0.5)));
                const adjustedHeight = Math.round(originalWatermarkHeight * (adjustedWidth / originalWatermarkWidth));
                
                previewWatermark.style.width = `${adjustedWidth}px`;
                previewWatermark.style.height = `${adjustedHeight}px`;
                previewWatermark.style.opacity = opacity / 100;
                
                const margin = 20;
                previewWatermark.style.left = 'auto';
                previewWatermark.style.right = 'auto';
                previewWatermark.style.top = 'auto';
                previewWatermark.style.bottom = 'auto';
                previewWatermark.style.transform = 'none';
                
                let x, y;
                switch(position) {
                    case 'top-left':
                        x = margin;
                        y = margin;
                        break;
                    case 'top-center':
                        x = (predictedWidth - adjustedWidth) / 2;
                        y = margin;
                        previewWatermark.style.transform = 'translateX(-50%)';
                        break;
                    case 'top-right':
                        x = predictedWidth - adjustedWidth - margin;
                        y = margin;
                        break;
                    case 'middle-left':
                        x = margin;
                        y = (predictedHeight - adjustedHeight) / 2;
                        previewWatermark.style.transform = 'translateY(-50%)';
                        break;
                    case 'middle-center':
                        x = (predictedWidth - adjustedWidth) / 2;
                        y = (predictedHeight - adjustedHeight) / 2;
                        previewWatermark.style.transform = 'translate(-50%, -50%)';
                        break;
                    case 'middle-right':
                        x = predictedWidth - adjustedWidth - margin;
                        y = (predictedHeight - adjustedHeight) / 2;
                        previewWatermark.style.transform = 'translateY(-50%)';
                        break;
                    case 'bottom-left':
                        x = margin;
                        y = predictedHeight - adjustedHeight - margin;
                        break;
                    case 'bottom-center':
                        x = (predictedWidth - adjustedWidth) / 2;
                        y = predictedHeight - adjustedHeight - margin;
                        previewWatermark.style.transform = 'translateX(-50%)';
                        break;
                    case 'bottom-right':
                    default:
                        x = predictedWidth - adjustedWidth - margin;
                        y = predictedHeight - adjustedHeight - margin;
                        break;
                }
                
                x = Math.round(x);
                y = Math.round(y);
                x = Math.max(0, Math.min(x, predictedWidth - adjustedWidth));
                y = Math.max(0, Math.min(y, predictedHeight - adjustedHeight));
                
                if(position.includes('center')) {
                    previewWatermark.style.left = `${x}px`;
                } else if(position.includes('right')) {
                    previewWatermark.style.right = `${predictedWidth - x - adjustedWidth}px`;
                } else {
                    previewWatermark.style.left = `${x}px`;
                }
                
                if(position.includes('middle')) {
                    previewWatermark.style.top = `${y}px`;
                } else if(position.includes('bottom')) {
                    previewWatermark.style.bottom = `${predictedHeight - y - adjustedHeight}px`;
                } else {
                    previewWatermark.style.top = `${y}px`;
                }
            }
        }
        
        // 图片预览功能
        const photoInput = document.getElementById('photo');
        const imagePreview = document.getElementById('imagePreview');
        const removePreview = document.getElementById('removePreview');
        const originalDimensions = document.getElementById('originalDimensions');
        const processedDimensions = document.getElementById('processedDimensions');
        
        function handleImageUpload(files) {
            if (files && files[0]) {
                const reader = new FileReader();
                const file = files[0];
                originalFileSize = file.size;
                
                const img = new Image();
                img.onload = function() {
                    originalImageWidth = this.width;
                    originalImageHeight = this.height;
                    const compressedInfo = calculateCompressedSize(
                        originalImageWidth, 
                        originalImageHeight, 
                        originalFileSize
                    );
                    predictedWidth = compressedInfo.width;
                    predictedHeight = compressedInfo.height;
                    scaleRatio = compressedInfo.scale;
                    
                    originalDimensions.textContent = `${originalImageWidth} x ${originalImageHeight}`;
                    processedDimensions.textContent = `${predictedWidth} x ${predictedHeight}`;
                    
                    previewImage.onload = function() {
                        updatePreviewWatermark(sizeSlider.value, opacitySlider.value, getSelectedPosition());
                    };
                };
                img.src = URL.createObjectURL(file);
                
                reader.onload = function(e) {
                    previewImage.src = e.target.result;
                    imagePreview.style.display = 'block';
                };
                
                reader.readAsDataURL(file);
            }
        }
        
        photoInput.addEventListener('change', function(e) {
            handleImageUpload(e.target.files);
        });
        
        removePreview.addEventListener('click', function() {
            previewImage.src = '';
            imagePreview.style.display = 'none';
            photoInput.value = '';
            originalImageWidth = 0;
            originalImageHeight = 0;
            originalFileSize = 0;
            predictedWidth = 0;
            predictedHeight = 0;
            scaleRatio = 1.0;
        });
        
        // 拖拽上传
        const imageUploadArea = document.getElementById('imageUploadArea');
        imageUploadArea.addEventListener('dragover', function(e) {
            e.preventDefault();
            imageUploadArea.style.borderColor = 'var(--primary)';
        });
        
        imageUploadArea.addEventListener('dragleave', function() {
            imageUploadArea.style.borderColor = 'var(--gray-200)';
        });
        
        imageUploadArea.addEventListener('drop', function(e) {
            e.preventDefault();
            imageUploadArea.style.borderColor = 'var(--gray-200)';
            handleImageUpload(e.dataTransfer.files);
        });
        
        // 监听水印设置变化
        sizeSlider.addEventListener('input', updateWatermarkSettings);
        opacitySlider.addEventListener('input', updateWatermarkSettings);
        positionRadios.forEach(radio => {
            radio.addEventListener('change', updateWatermarkSettings);
        });
        
        // 窗口大小变化时重新计算水印
        window.addEventListener('resize', function() {
            if(previewImage.complete && originalImageWidth > 0) {
                updatePreviewWatermark(sizeSlider.value, opacitySlider.value, getSelectedPosition());
            }
        });
        
        // 飞机信息自动填充功能
        const registrationInput = document.getElementById('registration_number');
        const aircraftModelInput = document.getElementById('aircraft_model');
        const airlineInput = document.getElementById('category');
        const regApiStatus = document.getElementById('regApiStatus');
        
        // 防抖处理的API请求函数
        const debounceRegCheck = debounce(async function(registration) {
            regApiStatus.textContent = '';
            
            // 输入长度至少3位才发起请求
            if (registration.length < 3) {
                return;
            }
            
            try {
                // 显示加载状态
                regApiStatus.innerHTML = '<i class="fas fa-circle-notch fa-spin"></i> 加载中...';
                
                // 调用飞机信息API - 使用HTTPS且不带端口
                const apiUrl = `https://sj.flyhs.top/api/plane-info?registration=${encodeURIComponent(registration)}`;
                const response = await fetch(apiUrl, {
                    method: 'GET',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json'
                    },
                    mode: 'cors' // 确保跨域请求正常
                });
                
                if (!response.ok) {
                    throw new Error(`HTTP错误: ${response.status}`);
                }
                
                const result = await response.json();
                
                // 处理API返回结果
                if (result.status === 'success' && result.data) {
                    // 自动填充表单字段
                    if (result.data.运营机构) {
                        airlineInput.value = result.data.运营机构;
                    }
                    if (result.data.机型) {
                        aircraftModelInput.value = result.data.机型;
                    }
                    
                    regApiStatus.innerHTML = '<i class="fas fa-check" style="color: var(--success);"></i> 已自动填充';
                } else {
                    regApiStatus.innerHTML = '<i class="fas fa-info-circle" style="color: #FF7D00;"></i> 未找到该飞机信息';
                }
                
            } catch (error) {
                console.error('API请求失败:', error);
                regApiStatus.innerHTML = '<i class="fas fa-exclamation-triangle" style="color: var(--danger);"></i> 获取信息失败';
            }
        }, 600);
        
        // 监听注册号输入事件
        registrationInput.addEventListener('input', function() {
            const registration = this.value.trim();
            debounceRegCheck(registration);
        });
        
        // 失去焦点时再次触发检查
        registrationInput.addEventListener('blur', function() {
            const registration = this.value.trim();
            if (registration.length >= 3) {
                debounceRegCheck(registration);
            }
        });
        
        // 初始化水印尺寸
        loadWatermarkDimensions();
        
        // 表单提交处理
        document.getElementById('uploadForm').addEventListener('submit', function(e) {
            // 检查是否同意使用条款
            if (!document.getElementById('allow_use').checked) {
                e.preventDefault();
                alert('请同意平台使用条款才能上传图片');
                return;
            }
            
            // 检查是否选择了图片
            if (!photoInput.value) {
                e.preventDefault();
                alert('请选择要上传的图片');
                return;
            }
            
            // 显示提交中状态
            const submitBtn = this.querySelector('button[type="submit"]');
            const originalText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> 上传中...';
            
            // 防止重复提交
            this.addEventListener('submit', function(e) {
                e.preventDefault();
            }, { once: true });
        });
    </script>
</body>
</html>
