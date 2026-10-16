<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$checked = 0;
foreach (['public/homepage.php', 'footer.php'] as $template) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $template);
    preg_match_all('/<img\b[^>]*>/i', $source, $matches);
    foreach ($matches[0] as $tag) {
        if (!preg_match('/\bsrc="([^"<]+)"/', $tag, $src) || !preg_match('/\bwidth="(\d+)"/', $tag, $width) || !preg_match('/\bheight="(\d+)"/', $tag, $height)) continue;
        $file = dirname(__DIR__) . '/' . $src[1];
        $size = getimagesize($file);
        if (!$size || (int)$width[1] !== $size[0] || (int)$height[1] !== $size[1]) throw new RuntimeException('Static image dimensions changed: ' . $src[1]);
        $checked++;
    }
    if (str_contains($source, 'ItourImageDimensionAttributes') || str_contains($source, 'image_dimensions_helper')) throw new RuntimeException('Runtime metadata lookup remains.');
}
if ($checked !== 18) throw new RuntimeException('Unexpected static image dimension coverage: ' . $checked);
echo "PASS: 18 static image tags match source dimensions; no runtime metadata lookups.\n";
