<?php

function load_google_oauth_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $rootEnvPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    $envValues = [];

    if (is_file($rootEnvPath)) {
        $parsedValues = parse_ini_file($rootEnvPath, false, INI_SCANNER_TYPED);
        if (is_array($parsedValues)) {
            $envValues = $parsedValues;
        }
    }

    $clientId = trim((string)($envValues['GOOGLE_CLIENT_ID'] ?? getenv('GOOGLE_CLIENT_ID') ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? '')));
    $clientSecret = trim((string)($envValues['GOOGLE_CLIENT_SECRET'] ?? getenv('GOOGLE_CLIENT_SECRET') ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? '')));

    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Google sign-in is not configured. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to the project .env file.');
    }

    $config = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => 'http://localhost/Mercedes%20Eco%20Tour%20Website/google_callback.php',
    ];

    return $config;
}
