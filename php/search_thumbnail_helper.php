<?php

declare(strict_types=1);

require_once __DIR__ . '/project_path_helper.php';

function createSearchCardThumbnail(string $sourceFile, string $relativeSource, int $maxWidth = 800): ?string
{
    if (!is_file($sourceFile) || !function_exists('imagecreatetruecolor')) {
        return null;
    }

    $imageType = @exif_imagetype($sourceFile);
    $source = match ($imageType) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($sourceFile),
        IMAGETYPE_PNG => @imagecreatefrompng($sourceFile),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($sourceFile) : false,
        default => false,
    };
    if (!$source) {
        return null;
    }

    try {
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        if ($sourceWidth < 1 || $sourceHeight < 1) {
            return null;
        }

        $targetWidth = min($maxWidth, $sourceWidth);
        $targetHeight = max(1, (int)round($sourceHeight * ($targetWidth / $sourceWidth)));
        $thumbnail = imagecreatetruecolor($targetWidth, $targetHeight);
        if (!$thumbnail) {
            return null;
        }

        try {
            $background = imagecolorallocate($thumbnail, 245, 248, 246);
            imagefill($thumbnail, 0, 0, $background);
            imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                0,
                0,
                $targetWidth,
                $targetHeight,
                $sourceWidth,
                $sourceHeight
            );

            $relativeSource = ltrim(str_replace('\\', '/', $relativeSource), '/');
            $relativeDirectory = trim(str_replace('\\', '/', dirname($relativeSource)), '.');
            $thumbnailRelativeDirectory = ($relativeDirectory !== '' ? $relativeDirectory . '/' : '') . 'search_thumbnails';
            try {
                $thumbnailDirectory = ItourEnsureProjectDirectory($thumbnailRelativeDirectory);
            } catch (Throwable $exception) {
                return null;
            }

            $filename = pathinfo($relativeSource, PATHINFO_FILENAME) . '.jpg';
            $targetFile = $thumbnailDirectory . DIRECTORY_SEPARATOR . $filename;
            if (!imagejpeg($thumbnail, $targetFile, 78)) {
                return null;
            }

            return ($relativeDirectory !== '' ? $relativeDirectory . '/' : '') . 'search_thumbnails/' . $filename;
        } finally {
            imagedestroy($thumbnail);
        }
    } finally {
        imagedestroy($source);
    }
}
