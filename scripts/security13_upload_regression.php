<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../php/secure_upload_helper.php';

$failures = [];
$expectRejected = static function (string $label, callable $callback) use (&$failures): void {
    try {
        $callback();
        $failures[] = $label . ' was accepted';
    } catch (InvalidArgumentException) {
        // Expected.
    }
};

$validBytes = file_get_contents(__DIR__ . '/../img/profileicon.png');
if (!is_string($validBytes)) {
    $failures[] = 'valid PNG fixture could not be read';
} else {
    $validated = ItourSecureValidateImageBytes($validBytes, 8 * 1024 * 1024);
    $temporary = tempnam(sys_get_temp_dir(), 's13_');
    if ($temporary === false) {
        $failures[] = 'temporary output could not be created';
    } else {
        try {
            ItourSecureReencodeImageBytes($validated, $temporary, 'image/png');
            if (!is_file($temporary) || filesize($temporary) < 1) $failures[] = 'valid PNG was not re-encoded';
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}

$expectRejected('plain-text fake image', static fn() => ItourSecureValidateImageBytes('plain text', 1024));
$expectRejected('SVG image', static fn() => ItourSecureValidateImageBytes('<svg xmlns="http://www.w3.org/2000/svg"></svg>', 1024));
$expectRejected('oversized payload', static fn() => ItourSecureValidateImageBytes(str_repeat('A', 1025), 1024));

// A PNG header is enough for metadata inspection; its declared 400 MP size
// must be rejected before any image decoder is invoked.
$ihdr = pack('NNCCCCC', 20000, 20000, 8, 6, 0, 0, 0);
$chunk = 'IHDR' . $ihdr;
$extremePng = "\x89PNG\r\n\x1a\n" . pack('N', strlen($ihdr)) . $chunk . pack('N', crc32($chunk));
$expectRejected('extreme-dimension PNG', static fn() => ItourSecureValidateImageBytes($extremePng, 1024));

if ($failures) {
    fwrite(STDERR, "Security #13 upload regression: FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "Security #13 upload regression: PASS\n";
