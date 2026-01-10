<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

function save_photo(array $file, array $aircraft, array $airport, int $user_id): int
{
    $config = require __DIR__ . '/../config/config.php';
    if ($file['type'] !== 'image/jpeg') {
        throw new RuntimeException('Only JPEG files are allowed.');
    }
    if ($file['size'] > $config['upload']['max_bytes']) {
        throw new RuntimeException('File too large.');
    }
    [$width, $height] = getimagesize($file['tmp_name']);
    $long_edge = max($width, $height);
    if ($long_edge < $config['upload']['min_long_edge']) {
        throw new RuntimeException('Image too small.');
    }

    $aircraft_id = ensure_aircraft($aircraft);
    $airport_id = ensure_airport($airport);

    $exif = read_exif($file['tmp_name']);

    $stmt = db()->prepare('INSERT INTO photos (user_id, aircraft_id, airport_id, image_url, thumbnail_url, width, height, camera_model, lens_model, focal_length, aperture, shutter_speed, iso, taken_at) VALUES (:user_id, :aircraft_id, :airport_id, :image_url, :thumbnail_url, :width, :height, :camera_model, :lens_model, :focal_length, :aperture, :shutter_speed, :iso, :taken_at)');
    $stmt->execute([
        'user_id' => $user_id,
        'aircraft_id' => $aircraft_id,
        'airport_id' => $airport_id,
        'image_url' => '',
        'thumbnail_url' => '',
        'width' => $width,
        'height' => $height,
        'camera_model' => $exif['camera_model'],
        'lens_model' => $exif['lens_model'],
        'focal_length' => $exif['focal_length'],
        'aperture' => $exif['aperture'],
        'shutter_speed' => $exif['shutter_speed'],
        'iso' => $exif['iso'],
        'taken_at' => $exif['taken_at'],
    ]);
    $photo_id = (int)db()->lastInsertId();

    $original_path = __DIR__ . '/../storage/photos/original/' . $photo_id . '.jpg';
    $thumb_path = __DIR__ . '/../storage/photos/thumbs/' . $photo_id . '.jpg';
    $watermark_path = __DIR__ . '/../storage/photos/watermarked/' . $photo_id . '.jpg';

    move_uploaded_file($file['tmp_name'], $original_path);
    create_thumbnail($original_path, $thumb_path, 600);
    add_watermark($original_path, $watermark_path, 'SyPhotos.cn');

    $stmt = db()->prepare('UPDATE photos SET image_url = :image_url, thumbnail_url = :thumb WHERE photo_id = :photo_id');
    $stmt->execute([
        'image_url' => '/storage/photos/watermarked/' . $photo_id . '.jpg',
        'thumb' => '/storage/photos/thumbs/' . $photo_id . '.jpg',
        'photo_id' => $photo_id,
    ]);

    return $photo_id;
}

function ensure_aircraft(array $aircraft): int
{
    $stmt = db()->prepare('SELECT aircraft_id FROM aircraft WHERE registration = :registration AND aircraft_type = :aircraft_type AND airline = :airline');
    $stmt->execute($aircraft);
    $existing = $stmt->fetch();
    if ($existing) {
        return (int)$existing['aircraft_id'];
    }
    $stmt = db()->prepare('INSERT INTO aircraft (registration, aircraft_type, airline, msn, special_livery) VALUES (:registration, :aircraft_type, :airline, :msn, :special_livery)');
    $stmt->execute([
        'registration' => $aircraft['registration'],
        'aircraft_type' => $aircraft['aircraft_type'],
        'airline' => $aircraft['airline'],
        'msn' => $aircraft['msn'] ?? null,
        'special_livery' => $aircraft['special_livery'] ?? 0,
    ]);
    return (int)db()->lastInsertId();
}

function ensure_airport(array $airport): int
{
    $stmt = db()->prepare('SELECT airport_id FROM airports WHERE airport_name = :airport_name AND country = :country');
    $stmt->execute([
        'airport_name' => $airport['airport_name'],
        'country' => $airport['country'],
    ]);
    $existing = $stmt->fetch();
    if ($existing) {
        return (int)$existing['airport_id'];
    }
    $stmt = db()->prepare('INSERT INTO airports (airport_name, iata_code, icao_code, country) VALUES (:airport_name, :iata_code, :icao_code, :country)');
    $stmt->execute([
        'airport_name' => $airport['airport_name'],
        'iata_code' => $airport['iata_code'] ?? null,
        'icao_code' => $airport['icao_code'] ?? null,
        'country' => $airport['country'],
    ]);
    return (int)db()->lastInsertId();
}

function read_exif(string $path): array
{
    $defaults = [
        'camera_model' => null,
        'lens_model' => null,
        'focal_length' => null,
        'aperture' => null,
        'shutter_speed' => null,
        'iso' => null,
        'taken_at' => null,
    ];
    if (!function_exists('exif_read_data')) {
        return $defaults;
    }
    try {
        $data = @exif_read_data($path);
    } catch (Throwable $e) {
        return $defaults;
    }
    if (!$data) {
        return $defaults;
    }
    return [
        'camera_model' => $data['Model'] ?? null,
        'lens_model' => $data['LensModel'] ?? null,
        'focal_length' => isset($data['FocalLength']) ? (string)$data['FocalLength'] : null,
        'aperture' => isset($data['COMPUTED']['ApertureFNumber']) ? (string)$data['COMPUTED']['ApertureFNumber'] : null,
        'shutter_speed' => $data['ExposureTime'] ?? null,
        'iso' => isset($data['ISOSpeedRatings']) ? (string)$data['ISOSpeedRatings'] : null,
        'taken_at' => isset($data['DateTimeOriginal']) ? date('Y-m-d H:i:s', strtotime($data['DateTimeOriginal'])) : null,
    ];
}

function create_thumbnail(string $src, string $dest, int $maxWidth): void
{
    $img = imagecreatefromjpeg($src);
    $width = imagesx($img);
    $height = imagesy($img);
    $scale = $maxWidth / $width;
    $newWidth = $maxWidth;
    $newHeight = (int)($height * $scale);
    $thumb = imagecreatetruecolor($newWidth, $newHeight);
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    imagejpeg($thumb, $dest, 85);
    imagedestroy($img);
    imagedestroy($thumb);
}

function add_watermark(string $src, string $dest, string $text): void
{
    $img = imagecreatefromjpeg($src);
    $width = imagesx($img);
    $height = imagesy($img);
    $watermarkHeight = 60;
    $canvas = imagecreatetruecolor($width, $height + $watermarkHeight);
    $black = imagecolorallocate($canvas, 15, 15, 15);
    $white = imagecolorallocate($canvas, 240, 240, 240);
    imagefill($canvas, 0, 0, $black);
    imagecopy($canvas, $img, 0, 0, 0, 0, $width, $height);
    imagestring($canvas, 5, 20, $height + 20, $text, $white);
    imagejpeg($canvas, $dest, 90);
    imagedestroy($img);
    imagedestroy($canvas);
}
