<?php
declare(strict_types=1);

// Public compatibility entry point. The maintained implementation lives in
// admin/; explicit no-cache headers prevent an old inline upload workflow from
// surviving a deployment in browser, CDN, or LiteSpeed caches.
header('Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Itour-Boats-Version: large-image-v2');

require __DIR__ . '/admin/adboats.php';

