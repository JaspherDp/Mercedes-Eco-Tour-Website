<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

ini_set('memory_limit', '512M');
set_time_limit(0);

$projectDirectory = dirname(__DIR__);
$sourcePatterns = [
    $projectDirectory . '/uploads/boat*.png',
    $projectDirectory . '/uploads/boat*.jpg',
    $projectDirectory . '/uploads/boat*.jpeg',
    $projectDirectory . '/php/upload/package_image*.png',
    $projectDirectory . '/php/upload/package_image*.jpg',
    $projectDirectory . '/php/upload/package_image*.jpeg',
    $projectDirectory . '/uploads/hotel_contents/*.png',
    $projectDirectory . '/uploads/hotel_contents/*.jpg',
    $projectDirectory . '/uploads/hotel_contents/*.jpeg',
];
$sources = [];
foreach ($sourcePatterns as $pattern) {
    $sources = array_merge($sources, glob($pattern) ?: []);
}

$created = 0;
$skipped = 0;
$failed = 0;

foreach (array_unique($sources) as $source) {
    $destination = preg_replace('/\.(png|jpe?g)$/i', '.optimized.webp', $source);
    if (!$destination) {
        $failed++;
        continue;
    }
    if (is_file($destination) && filemtime($destination) >= filemtime($source)) {
        $skipped++;
        continue;
    }

    $details = @getimagesize($source);
    if (!$details) {
        $failed++;
        continue;
    }
    $image = $details['mime'] === 'image/png'
        ? @imagecreatefrompng($source)
        : @imagecreatefromjpeg($source);
    if (!$image) {
        $failed++;
        continue;
    }

    $width = imagesx($image);
    $height = imagesy($image);
    $targetWidth = min(1400, $width);
    $targetHeight = max(1, (int)round($height * ($targetWidth / $width)));
    $resized = imagecreatetruecolor($targetWidth, $targetHeight);
    imagealphablending($resized, false);
    imagesavealpha($resized, true);
    imagecopyresampled($resized, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

    if (@imagewebp($resized, $destination, 76)) {
        $created++;
        echo 'Optimized: ' . basename($source) . PHP_EOL;
    } else {
        $failed++;
    }
    imagedestroy($resized);
    imagedestroy($image);
}

echo "Created {$created}; skipped {$skipped}; failed {$failed}." . PHP_EOL;
