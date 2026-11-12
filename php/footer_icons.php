<?php
declare(strict_types=1);

function FooterIcon(string $name, string $classes = ''): string
{
    $paths = [
        'facebook-f' => '<path d="M14 21v-8h3l.5-4H14V7c0-1 .3-2 2-2h2V1h-3c-4 0-6 2-6 6v2H6v4h3v8Z" fill="currentColor" stroke="none"/>',
        'phone' => '<path d="m7 3 3 5-3 2c1 3 4 6 7 7l2-3 5 3c0 3-2 5-5 4C9 19 5 15 3 8 2 5 4 3 7 3Z"/>',
        'envelope' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
        'location-dot' => '<path d="M19 10c0 5-7 11-7 11S5 15 5 10a7 7 0 1 1 14 0Z"/><circle cx="12" cy="10" r="2"/>',
        'users' => '<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6M18 15a5 5 0 0 1 3 5"/>',
        'code' => '<path d="m8 6-6 6 6 6M16 6l6 6-6 6M14 3l-4 18"/>',
        'graduation-cap' => '<path d="m2 9 10-5 10 5-10 5ZM6 11v6c4 3 8 3 12 0v-6M22 9v8"/>',
        'building-columns' => '<path d="m2 7 10-5 10 5ZM3 21h18M5 9v9M12 9v9M19 9v9"/>',
    ];
    return '<svg class="footer-svg-icon ' . htmlspecialchars($classes, ENT_QUOTES, 'UTF-8') . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
}
