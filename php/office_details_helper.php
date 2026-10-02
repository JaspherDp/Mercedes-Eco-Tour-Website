<?php

function officeDetailsDefaults(): array
{
    return [
        'office_address' => 'Municipal Tourism Office, Mercedes, Camarines Norte',
        'office_email' => 'tourism@mercedes.gov.ph',
        'office_phone' => '+63 912 345 6789',
        'office_landmark' => 'Near Mercedes-Manguisoc Port',
        'office_hours' => 'Monday–Friday, 8:00 AM–5:00 PM',
        'office_facebook_url' => 'https://www.facebook.com/mercedes.tourism.2024',
        'office_map_url' => 'https://www.google.com/maps?cid=17291153808497984925',
        'office_map_embed_url' => 'https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d3869.45960970015!2d123.01004931083179!3d14.10904708886486!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x3398ad004f448161%3A0xeff684c6c02a459d!2sMercedes%20Tourism%20Office!5e1!3m2!1sen!2sph!4v1790931049463!5m2!1sen!2sph',
    ];
}

function officeDetailsFromSettings(array $settings): array
{
    $details = officeDetailsDefaults();
    foreach ($details as $key => $fallback) {
        $value = $settings[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            $details[$key] = trim($value);
        }
    }
    // Older settings used this generic placeholder before the public details were editable.
    if ($details['office_address'] === 'Mercedes, Camarines Norte') {
        $details['office_address'] = officeDetailsDefaults()['office_address'];
    }
    return $details;
}

function loadPublicOfficeDetails(PDO $pdo): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $json = $pdo->query('SELECT settings_json FROM admin_system_settings WHERE settings_id = 1 LIMIT 1')->fetchColumn();
        $saved = is_string($json) ? json_decode($json, true) : null;
        $cached = officeDetailsFromSettings(is_array($saved) ? $saved : []);
    } catch (Throwable $error) {
        error_log('Office details could not be loaded: ' . $error->getMessage());
        $cached = officeDetailsDefaults();
    }
    return $cached;
}

function officeDetailsEmbedUrl(string $input): string
{
    $input = trim($input);
    if (preg_match('/<iframe\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $input, $match)) {
        $input = $match[1];
    }
    $url = html_entity_decode($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, ['www.google.com', 'google.com', 'maps.google.com'], true)
        || ($parts['path'] ?? '') !== '/maps/embed' || empty($parts['query'])) {
        throw new InvalidArgumentException('Enter a Google Maps embed URL or its iframe code.');
    }
    // Keep the public map in satellite view when Google supplies its default road-map embed.
    return preg_replace('/!5e[01]/', '!5e1', $url, 1);
}

function officeDetailsExternalUrl(string $input, string $type): string
{
    $url = trim($input);
    $parts = parse_url($url);
    $host = strtolower((string)($parts['host'] ?? ''));
    $allowed = $type === 'facebook'
        ? ['facebook.com', 'www.facebook.com', 'm.facebook.com']
        : ['google.com', 'www.google.com', 'maps.google.com', 'maps.app.goo.gl'];
    if (($parts['scheme'] ?? '') !== 'https' || !in_array($host, $allowed, true)) {
        throw new InvalidArgumentException($type === 'facebook' ? 'Enter a valid Facebook page link.' : 'Enter a valid Google Maps location link.');
    }
    return $url;
}
