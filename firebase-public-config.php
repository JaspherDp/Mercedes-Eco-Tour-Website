<?php
declare(strict_types=1);

require_once __DIR__ . '/php/firebase_config.php';

header('Cache-Control: no-store, max-age=0');
header('X-Content-Type-Options: nosniff');

$configuration = firebase_public_configuration();

if (($_GET['format'] ?? '') === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($configuration, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    exit;
}

header('Content-Type: application/javascript; charset=utf-8');
echo 'self.__ITOUR_FIREBASE_PUBLIC_CONFIG__ = Object.freeze('
    . json_encode($configuration['firebase'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    . ');' . "\n";
echo 'self.__ITOUR_FIREBASE_CONFIGURED__ = ' . ($configuration['configured'] ? 'true' : 'false') . ';' . "\n";
echo 'self.__ITOUR_APP_URL__ = '
    . json_encode($configuration['app_url'], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
    . ';' . "\n";

