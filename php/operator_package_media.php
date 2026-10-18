<?php
declare(strict_types=1);
require_once __DIR__ . '/secure_upload_helper.php';

/** The caller must verify operator authentication, CSRF and package ownership first. */
function OperatorPackageImageFields(): array
{
    return ['package_image', 'package_image2', 'package_image3', 'package_image4', 'location_image', 'route_image'];
}

function OperatorPackageValidateBase64Image(mixed $data): ?array
{
    if ($data === '' || $data === null) return null;
    $maxBytes = 40 * 1024 * 1024;
    if (!is_string($data) || strlen($data) > 4 * (int)ceil($maxBytes / 3) + 32
        || !preg_match('#^data:image/(png|jpeg|webp);base64,([A-Za-z0-9+/]*={0,2})$#D', $data, $matches)) {
        throw new InvalidArgumentException('Choose a genuine JPEG, PNG, or WebP package image up to 40 MB.');
    }
    $bytes = base64_decode($matches[2], true);
    if ($bytes === false) throw new InvalidArgumentException('The package image could not be read.');
    $validated = ItourSecureValidateImageBytes($bytes, $maxBytes, 40000000, 12000, 12000);
    $declaredMime = $matches[1] === 'jpeg' ? 'image/jpeg' : 'image/' . $matches[1];
    if ($validated['mime'] !== $declaredMime) throw new InvalidArgumentException('The package image type does not match its contents.');
    return $validated;
}

function OperatorPackageValidateImages(array $uploads, array $post): array
{
    $images = [];
    foreach (OperatorPackageImageFields() as $field) {
        $file = $uploads[$field] ?? null;
        if ($file !== null && !is_array($file)) throw new InvalidArgumentException('A package image upload is invalid.');
        if ($file !== null && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            // Same byte/dimension validation as the administrator package editor.
            $images[$field] = ItourSecureValidateUploadedImage($file, 40 * 1024 * 1024, 40000000, 12000, 12000);
        } else {
            // Retain genuine base64 support for older clients; never fetch submitted URLs.
            $images[$field] = OperatorPackageValidateBase64Image($post[$field . '_data'] ?? '');
        }
    }
    return $images;
}

function OperatorPackageSaveImage(?array $image, array &$createdPaths): ?string
{
    if ($image === null) return null;
    $directory = ItourEnsureProjectDirectory('php/upload');
    $basename = ItourSecureRandomFilename('pkg', $image['extension']);
    $path = $directory . DIRECTORY_SEPARATOR . $basename;
    $temporary = null;
    try {
        if (isset($image['bytes'])) {
            $temporary = tempnam(sys_get_temp_dir(), 'pkg');
            if ($temporary === false || file_put_contents($temporary, $image['bytes']) !== strlen($image['bytes'])) {
                throw new RuntimeException('The package image could not be prepared.');
            }
            $image['temporary_path'] = $temporary;
            unset($image['bytes']);
        }
        // Reuse the administrator's server-side resize/compression, including EXIF handling.
        ItourSecureOptimizeUploadedImage($image, $path, 1920);
        ItourAssertPublicMediaFile($path);
        $createdPaths[] = $path;
        return 'php/upload/' . $basename;
    } catch (Throwable $error) {
        if (is_file($path)) @unlink($path);
        throw $error;
    } finally {
        if (is_string($temporary) && is_file($temporary)) @unlink($temporary);
    }
}
