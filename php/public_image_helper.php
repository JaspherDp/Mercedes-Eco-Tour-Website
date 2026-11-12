<?php
declare(strict_types=1);

/** Use prebuilt web images; never decode or resize uploads during a page request. */
function PublicImageUrl(string $path): string
{
    $path = trim($path);
    if (!preg_match('~^(?:img|imagess|uploads|php/upload)/[^?#]+\.(?:png|jpe?g)$~i', $path)
        || in_array('..', explode('/', $path), true)) return $path;
    $root = dirname(__DIR__) . '/';
    $variant = preg_replace('/\.(?:png|jpe?g)$/i', '.home.webp', $path);
    if (str_starts_with($path, 'uploads/') || str_starts_with($path, 'php/upload/')) {
        $variant = 'img/homepage-cache/' . hash('sha256', $path) . '.webp';
    }
    if (is_file($root . $path) && is_file($root . $variant)
        && filemtime($root . $variant) >= filemtime($root . $path)
        && filesize($root . $variant) < filesize($root . $path)) return $variant;
    return $path;
}
