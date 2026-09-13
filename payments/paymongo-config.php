<?php
declare(strict_types=1);

require_once __DIR__ . '/PaymentHelper.php';

function paymongo_env_path(): ?string
{
    return PaymentHelper::environmentPath();
}

function paymongo_load_env(): array
{
    return PaymentHelper::loadEnvironment(paymongo_env_path());
}

function paymongo_env(string $name, string $default = ''): string
{
    return PaymentHelper::env($name, $default, paymongo_env_path());
}

function paymongo_signature_parts(string $header): array
{
    return PaymentHelper::signatureParts($header);
}

function paymongo_verify_test_signature(string $rawBody, string $signatureHeader, string $webhookSecret, int $toleranceSeconds = 300): bool
{
    return PaymentHelper::verifyPayMongoTestSignature(
        $rawBody,
        $signatureHeader,
        $webhookSecret,
        $toleranceSeconds
    );
}

function paymongo_request_header(string $name): string
{
    $serverName = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return trim((string)($_SERVER[$serverName] ?? ''));
}

function paymongo_json_response(int $status, array $body): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($body, JSON_UNESCAPED_SLASHES);
    exit;
}
