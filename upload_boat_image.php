<?php
declare(strict_types=1);

// Compatibility endpoint for previously cached Boats pages and older
// deployments. Keep all validation and processing in the maintained handler.
header('X-Itour-Boats-Version: large-image-v2');
require __DIR__ . '/admin/upload_boat_image.php';
