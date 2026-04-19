<?php

function google_oauth_read_env_file(string $envPath): array
{
    if (!is_readable($envPath)) {
        return [];
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return [];
    }

    $envValues = [];

    foreach ($lines as $rawLine) {
        $line = trim((string)$rawLine);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, ';') === 0) {
            continue;
        }

        if (strpos($line, 'export ') === 0) {
            $line = trim(substr($line, 7));
        }

        $separatorPos = strpos($line, '=');
        if ($separatorPos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $separatorPos));
        if (strpos($key, "\xEF\xBB\xBF") === 0) {
            $key = substr($key, 3);
        }
        $value = trim(substr($line, $separatorPos + 1));

        if ($key === '') {
            continue;
        }

        if ($value !== '') {
            $firstChar = $value[0];
            $lastChar = $value[strlen($value) - 1];
            if (($firstChar === '"' && $lastChar === '"') || ($firstChar === "'" && $lastChar === "'")) {
                $value = substr($value, 1, -1);
            }
        }

        $envValues[$key] = $value;
    }

    return $envValues;
}

function load_google_oauth_config(): array
{
    static $config = null;

    if ($config !== null) {
        return $config;
    }

    $rootEnvPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env';
    $envValues = [];

    if (is_file($rootEnvPath)) {
        $envValues = google_oauth_read_env_file($rootEnvPath);
    }

    $clientId = trim((string)(
        getenv('GOOGLE_CLIENT_ID')
        ?: ($_ENV['GOOGLE_CLIENT_ID'] ?? ($_SERVER['GOOGLE_CLIENT_ID'] ?? ($envValues['GOOGLE_CLIENT_ID'] ?? '')))
    ));
    $clientSecret = trim((string)(
        getenv('GOOGLE_CLIENT_SECRET')
        ?: ($_ENV['GOOGLE_CLIENT_SECRET'] ?? ($_SERVER['GOOGLE_CLIENT_SECRET'] ?? ($envValues['GOOGLE_CLIENT_SECRET'] ?? '')))
    ));
    $redirectUri = trim((string)(
        getenv('GOOGLE_REDIRECT_URI')
        ?: ($_ENV['GOOGLE_REDIRECT_URI'] ?? ($_SERVER['GOOGLE_REDIRECT_URI'] ?? ($envValues['GOOGLE_REDIRECT_URI'] ?? 'http://localhost/Mercedes%20Eco%20Tour%20Website/google_callback.php')))
    ));
    $redirectUri = str_replace(' ', '%20', $redirectUri);

    if ($clientId === '' || $clientSecret === '') {
        throw new RuntimeException('Google sign-in is not configured. Add GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET to the project .env file.');
    }
    if (strpos($clientId, '.apps.googleusercontent.com') === false) {
        throw new RuntimeException('Google sign-in is misconfigured. GOOGLE_CLIENT_ID must be a valid Google OAuth Web Client ID.');
    }

    $config = [
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
    ];

    return $config;
}
