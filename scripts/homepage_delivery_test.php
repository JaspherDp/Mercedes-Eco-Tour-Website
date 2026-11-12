<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../php/public_image_helper.php';
chdir(dirname(__DIR__));
$url = $argv[1] ?? 'http://localhost/Mercedes%20Eco%20Tour%20Website/';
$html = file_get_contents($url);
if ($html === false || str_contains($html, 'Fatal error') || str_contains($html, 'Warning:')) throw new RuntimeException('Homepage did not render cleanly.');
$dom = new DOMDocument(); @$dom->loadHTML($html);
$xpath = new DOMXPath($dom);
foreach ($dom->getElementsByTagName('img') as $image) {
    if (!$image->hasAttribute('alt')) throw new RuntimeException('Missing image alt attribute.');
    $path = $image->getAttribute('src');
    if ($path !== '' && !preg_match('~^(?:https?:|data:)~', $path) && !is_file($path)) throw new RuntimeException('Missing image: ' . $path);
}
if ($xpath->query('//main//button[@aria-selected] | //main//*[@role="tablist"]')->length) throw new RuntimeException('Carousel controls still use incomplete tab semantics.');
if ($xpath->query('//video[@src]')->length) throw new RuntimeException('Homepage video downloads before entering view.');
$blocking = $xpath->query('//link[@rel="stylesheet" and not(@media="print") and not(ancestor::noscript)]');
foreach ($blocking as $link) if (str_starts_with($link->getAttribute('href'), 'https://')) throw new RuntimeException('Blocking third-party stylesheet.');
foreach ($xpath->query('//script[@src]') as $script) if (!$script->hasAttribute('defer') && !$script->hasAttribute('async')) throw new RuntimeException('Blocking external script.');
$checked = $before = $after = 0;
foreach (['img', 'imagess', 'uploads/destinations', 'uploads/featured', 'uploads/hotel_contents', 'php/upload'] as $directory) {
    foreach (glob($directory . '/*.home.webp') ?: [] as $variant) {
        $base = substr($variant, 0, -strlen('.home.webp'));
        foreach (['png', 'jpg', 'jpeg', 'PNG', 'JPG', 'JPEG'] as $ext) {
            $original = $base . '.' . $ext;
            if (!is_file($original)) continue;
            $selected = PublicImageUrl($original);
            $a = getimagesize($original); $b = getimagesize($selected);
            if (!$a || !$b || abs(($a[0]/$a[1])/($b[0]/$b[1])-1) > .01) throw new RuntimeException('Image aspect ratio changed: ' . $variant);
            if (!is_file($selected) || filesize($selected) > filesize($original)) throw new RuntimeException('Optimized image selection regressed.');
            $before += filesize($original); $after += filesize($selected); $checked++;
            break;
        }
    }
}
if ($checked < 1) throw new RuntimeException('No generated image variants deployed.');
echo "PASS: rendered homepage assets, alt text, carousel controls, deferred scripts/styles and video loading.\n";
echo "PASS: $checked image variants preserve aspect ratios; source $before bytes -> selected $after bytes.\n";
echo 'Blocking stylesheets: ' . $blocking->length . " (all local).\n";
