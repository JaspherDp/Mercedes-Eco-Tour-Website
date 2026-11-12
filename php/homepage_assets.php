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
