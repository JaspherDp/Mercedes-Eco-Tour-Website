<?php
declare(strict_types=1);

function HomepageRuntimeSources(): array
{
    return ['js/complaint-modal.js', 'js/legal-policy-modal.js', 'js/cookie-consent-banner.js',
        'js/mobile-scroll.js', 'js/header.js', 'js/recently_viewed.js',
        'js/featured-viewport-video.js', 'js/homepage.js', 'js/back-to-top.js'];
}

/** Fall back to individual scripts if an edit or partial deployment makes the bundle stale. */
function HomepageRuntimeBundle(): ?string
{
    $root = dirname(__DIR__) . '/';
    $bundle = 'js/homepage-runtime.bundle.js';
    $manifestPath = $root . 'js/homepage-runtime.manifest.json';
    if (!is_file($manifestPath) || !is_file($root . $bundle)) return null;
    $manifest = json_decode((string)file_get_contents($manifestPath), true);
    if (!is_array($manifest) || array_keys($manifest['sources'] ?? []) !== HomepageRuntimeSources()) return null;
    foreach ($manifest['sources'] as $source => $hash) {
        if (!is_file($root . $source)) return null;
        $text = str_replace("\r\n", "\n", (string)file_get_contents($root . $source));
        if (!hash_equals((string)$hash, hash('sha256', $text))) return null;
    }
    if (!hash_equals((string)($manifest['bundle'] ?? ''), hash_file('sha256', $root . $bundle))) return null;
    return $bundle . '?v=' . filemtime($root . $bundle);
}

function HomepageStyleSources(): array
{
    return [
        'head' => ['styles/homepage.css', 'styles/back-to-top.css', 'styles/image-viewer.css', 'styles/page-loader.css'],
        'footer' => ['styles/complaint-modal.css', 'styles/legal-policy-modal.css', 'styles/cookie-consent-banner.css'],
    ];
}

function HomepageStyleBundle(string $group): ?string
{
    $groups = HomepageStyleSources();
    if (!isset($groups[$group])) return null;
    $root = dirname(__DIR__) . '/';
    $bundle = 'styles/homepage-' . $group . '.bundle.css';
    $manifestPath = $root . 'styles/homepage-styles.manifest.json';
    if (!is_file($manifestPath) || !is_file($root . $bundle)) return null;
    $manifest = json_decode((string)file_get_contents($manifestPath), true)[$group] ?? null;
    if (!is_array($manifest) || array_keys($manifest['sources'] ?? []) !== $groups[$group]) return null;
    foreach ($manifest['sources'] as $source => $hash) {
        if (!is_file($root . $source)) return null;
        $text = str_replace("\r\n", "\n", (string)file_get_contents($root . $source));
        if (!hash_equals((string)$hash, hash('sha256', $text))) return null;
    }
    if (!hash_equals((string)($manifest['bundle'] ?? ''), hash_file('sha256', $root . $bundle))) return null;
    return $bundle . '?v=' . filemtime($root . $bundle);
}

/** Embed only the existing tiny service icons; leave photos and larger logos alone. */
function HomepageIconUrl(string $path): string
{
    static $cache = [];
    if (isset($cache[$path])) return $cache[$path];
    $selected = PublicImageUrl($path);
    $icons = ['img/hotelshome.png', 'img/packageshome.png', 'img/tourguidehome.png',
        'img/boathome.png', 'img/locationicon.png', 'img/bookingicon.png'];
    if (!in_array($path, $icons, true)) return $selected;
    $absolute = dirname(__DIR__) . '/' . $selected;
    $mime = str_ends_with($selected, '.webp') ? 'image/webp' : 'image/png';
    if (!is_file($absolute) || filesize($absolute) > 4096) return $selected;
    return $cache[$path] = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($absolute));
}
