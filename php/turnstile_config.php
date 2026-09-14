<?php
declare(strict_types=1);

require_once __DIR__ . '/turnstile.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$configuration = ItourTurnstileConfiguration();
echo json_encode([
    'enabled' => $configuration['configured'],
    'development_bypass' => $configuration['development_bypass'],
    'site_key' => $configuration['configured'] ? $configuration['site_key'] : '',
], JSON_UNESCAPED_SLASHES);

