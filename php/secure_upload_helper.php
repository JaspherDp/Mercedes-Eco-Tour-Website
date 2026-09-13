<?php
declare(strict_types=1);

require_once __DIR__ . '/project_path_helper.php';

/**
 * Small, authorization-agnostic helpers for validating and re-encoding images.
 * Callers must perform their own role, ownership, and CSRF checks first.
 */

function ItourSecureImageMimeMap(): array
{
    return [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
}

function ItourSecureValidateDimensions(array $imageInfo, int $maxPixels, int $maxWidth, int $maxHeight): void
{
    $width = (int)($imageInfo[0] ?? 0);
    $height = (int)($imageInfo[1] ?? 0);
    if ($width < 1 || $height < 1 || $width > $maxWidth || $height > $maxHeight
        || $height > intdiv($maxPixels, max(1, $width))) {
        throw new InvalidArgumentException('The image dimensions are too large.');
    }
}

function ItourSecureValidateUploadedImage(
    array $file,
    int $maxBytes,
    int $maxPixels = 25000000,
    int $maxWidth = 10000,
    int $maxHeight = 10000
): array {
    if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('The selected image could not be uploaded.');
    }

    $temporaryPath = (string)($file['tmp_name'] ?? '');
    if ($temporaryPath === '' || !is_uploaded_file($temporaryPath)) {
        throw new InvalidArgumentException('The selected image upload is invalid.');
    }

    $declaredSize = (int)($file['size'] ?? 0);
    $actualSize = @filesize($temporaryPath);
    if ($actualSize === false || $actualSize < 1 || $actualSize > $maxBytes
        || $declaredSize < 1 || $declaredSize > $maxBytes) {
        throw new InvalidArgumentException('The selected image is too large or empty.');
    }

    $mimeMap = ItourSecureImageMimeMap();
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($temporaryPath);
    $imageInfo = @getimagesize($temporaryPath);
    if (!is_string($mime) || !isset($mimeMap[$mime]) || !$imageInfo
        || (string)($imageInfo['mime'] ?? '') !== $mime) {
        throw new InvalidArgumentException('Only genuine JPEG, PNG, and WebP images are accepted.');
    }
    ItourSecureValidateDimensions($imageInfo, $maxPixels, $maxWidth, $maxHeight);

    return [
        'temporary_path' => $temporaryPath,
        'mime' => $mime,
        'extension' => $mimeMap[$mime],
        'width' => (int)$imageInfo[0],
        'height' => (int)$imageInfo[1],
        'size' => (int)$actualSize,
    ];
}

function ItourSecureValidateImageBytes(
    string $bytes,
    int $maxBytes,
    int $maxPixels = 25000000,
    int $maxWidth = 10000,
    int $maxHeight = 10000
): array {
    $length = strlen($bytes);
    if ($length < 1 || $length > $maxBytes) {
        throw new InvalidArgumentException('The decoded image is too large or empty.');
    }

    $mimeMap = ItourSecureImageMimeMap();
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->buffer($bytes);
    $imageInfo = @getimagesizefromstring($bytes);
    if (!is_string($mime) || !isset($mimeMap[$mime]) || !$imageInfo
        || (string)($imageInfo['mime'] ?? '') !== $mime) {
        throw new InvalidArgumentException('The decoded data is not a supported image.');
    }
    ItourSecureValidateDimensions($imageInfo, $maxPixels, $maxWidth, $maxHeight);

    return [
        'bytes' => $bytes,
        'mime' => $mime,
        'extension' => $mimeMap[$mime],
        'width' => (int)$imageInfo[0],
        'height' => (int)$imageInfo[1],
        'size' => $length,
    ];
}

function ItourSecureDecodeImage(string $bytes): GdImage
{
    $image = @imagecreatefromstring($bytes);
    if (!$image instanceof GdImage) {
        throw new InvalidArgumentException('The image could not be decoded.');
    }
    return $image;
}

function ItourSecureWriteImage(GdImage $image, string $targetPath, string $mime): void
{
    $written = false;
    if ($mime === 'image/jpeg') {
        $written = imagejpeg($image, $targetPath, 88);
    } elseif ($mime === 'image/png') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $written = imagepng($image, $targetPath, 6);
    } elseif ($mime === 'image/webp') {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $written = imagewebp($image, $targetPath, 86);
    }
    if (!$written) {
        throw new RuntimeException('The validated image could not be saved.');
    }
}

function ItourSecureApplyJpegOrientation(GdImage $image, string $sourcePath): GdImage
{
    if (!function_exists('exif_read_data')) return $image;
    $metadata = @exif_read_data($sourcePath);
    $orientation = is_array($metadata) ? (int)($metadata['Orientation'] ?? 1) : 1;

    if ($orientation === 2) imageflip($image, IMG_FLIP_HORIZONTAL);
    elseif ($orientation === 3) {
        $rotated = imagerotate($image, 180, 0);
        if ($rotated instanceof GdImage) { imagedestroy($image); $image = $rotated; }
    } elseif ($orientation === 4) imageflip($image, IMG_FLIP_VERTICAL);
    elseif ($orientation === 5) {
        imageflip($image, IMG_FLIP_VERTICAL);
        $rotated = imagerotate($image, -90, 0);
        if ($rotated instanceof GdImage) { imagedestroy($image); $image = $rotated; }
    } elseif ($orientation === 6) {
        $rotated = imagerotate($image, -90, 0);
        if ($rotated instanceof GdImage) { imagedestroy($image); $image = $rotated; }
    } elseif ($orientation === 7) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
        $rotated = imagerotate($image, -90, 0);
        if ($rotated instanceof GdImage) { imagedestroy($image); $image = $rotated; }
    } elseif ($orientation === 8) {
        $rotated = imagerotate($image, 90, 0);
        if ($rotated instanceof GdImage) { imagedestroy($image); $image = $rotated; }
    }
    return $image;
}

function ItourSecureReencodeImageFile(array $validated, string $targetPath, ?string $outputMime = null): void
{
    $bytes = @file_get_contents((string)$validated['temporary_path']);
    if (!is_string($bytes)) {
        throw new RuntimeException('The validated image could not be read.');
    }
    $image = ItourSecureDecodeImage($bytes);
    if ((string)$validated['mime'] === 'image/jpeg') {
        $image = ItourSecureApplyJpegOrientation($image, (string)$validated['temporary_path']);
    }
    try {
        ItourSecureWriteImage($image, $targetPath, $outputMime ?? (string)$validated['mime']);
    } finally {
        imagedestroy($image);
    }
}

function ItourSecureReencodeImageBytes(array $validated, string $targetPath, ?string $outputMime = null): void
{
    $image = ItourSecureDecodeImage((string)$validated['bytes']);
    try {
        ItourSecureWriteImage($image, $targetPath, $outputMime ?? (string)$validated['mime']);
    } finally {
        imagedestroy($image);
    }
}

function ItourSecureRandomFilename(string $prefix, string $extension): string
{
    $safePrefix = preg_replace('/[^A-Za-z0-9_-]/', '_', $prefix) ?: 'media';
    return $safePrefix . '_' . bin2hex(random_bytes(16)) . '.' . $extension;
}
