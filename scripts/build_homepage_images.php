<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
// Usage: php scripts/build_homepage_images.php path/to/rendered-homepage.html
// Rebuild after replacing photos. Missing/stale variants fall back to originals.
ini_set('memory_limit', '768M');
$root = dirname(__DIR__);
if (empty($argv[1]) || !is_file($argv[1])) throw new RuntimeException('Pass a rendered homepage HTML file.');
$html = file_get_contents($argv[1]);
if ($html === false) throw new RuntimeException('Pass a rendered homepage HTML file.');
preg_match_all('~(?:src|data-image|data-gallery-image)="([^"<>]+)"~', $html, $matches);
$paths = array_merge($matches[1], ['img/herohomepage2.png', 'img/HomeBG.png']);
$before = $after = $created = 0;
foreach (array_unique($paths) as $raw) {
    $path = html_entity_decode($raw, ENT_QUOTES, 'UTF-8');
    if (!preg_match('~^(?:img|imagess|uploads|php/upload)/[^?#]+\.(png|jpe?g)$~i', $path)) continue;
    $source = realpath($root . '/' . $path);
    if (!$source || !str_starts_with($source, $root . DIRECTORY_SEPARATOR)) continue;
    $destination = preg_replace('/\.(png|jpe?g)$/i', '.home.webp', $source);
    if (str_starts_with($path, 'uploads/') || str_starts_with($path, 'php/upload/')) {
        $cache = $root . '/img/homepage-cache';
        if (!is_dir($cache) && !mkdir($cache, 0755, true)) throw new RuntimeException('Could not create image cache.');
        $destination = $cache . '/' . hash('sha256', $path) . '.webp';
    }
    $size = getimagesize($source);
    if (!$size) continue;
    $limit = preg_match('~(?:logo|jaspher|jacqueline|oliver)~i', $path) ? 320 : 1400;
    if (!is_file($destination) || filemtime($destination) < filemtime($source)) {
        $image = $size['mime'] === 'image/png' ? imagecreatefrompng($source) : imagecreatefromjpeg($source);
        $scale = min(1, $limit / max($size[0], $size[1]));
        $resized = imagecreatetruecolor(max(1, (int)round($size[0] * $scale)), max(1, (int)round($size[1] * $scale)));
        imagealphablending($resized, false); imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, imagesx($resized), imagesy($resized), $size[0], $size[1]);
        if (!imagewebp($resized, $destination, 80)) throw new RuntimeException('Could not encode ' . $path);
        imagedestroy($resized); imagedestroy($image); $created++;
    }
    $before += filesize($source); $after += min(filesize($source), filesize($destination));
}
echo "Created $created variants; source bytes $before; optimized bytes $after.\n";
