<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$folder = __DIR__ . '/uploads/hotel_rooms/';

$files = glob($folder . '*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG}', GLOB_BRACE);

foreach ($files as $file) {

    $mime = mime_content_type($file);

    switch ($mime) {

        case 'image/jpeg':
            $img = imagecreatefromjpeg($file);
            break;

        case 'image/png':
            $img = imagecreatefrompng($file);
            break;

        case 'image/webp':
            $img = imagecreatefromwebp($file);
            break;

        case 'image/gif':
            $img = imagecreatefromgif($file);
            break;

        default:
            continue;
    }

    $width = imagesx($img);
    $height = imagesy($img);

    $maxWidth = 1600;

    if ($width > $maxWidth) {

        $newWidth = $maxWidth;
        $newHeight = intval($height * $newWidth / $width);

        $tmp = imagecreatetruecolor($newWidth, $newHeight);

        imagealphablending($tmp, false);
        imagesavealpha($tmp, true);

        imagecopyresampled(
            $tmp,
            $img,
            0,
            0,
            0,
            0,
            $newWidth,
            $newHeight,
            $width,
            $height
        );

        imagedestroy($img);
        $img = $tmp;
    }

    imagewebp($img, preg_replace('/\.(jpg|jpeg|png|gif|webp)$/i', '.webp', $file), 80);

    imagedestroy($img);

    if (!str_ends_with(strtolower($file), '.webp')) {
        unlink($file);
    }

    echo basename($file) . " compressed<br>";
}

echo "<h2>Done!</h2>";
