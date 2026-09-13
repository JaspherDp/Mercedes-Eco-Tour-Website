<?php

declare(strict_types=1);

function ItourValidationText(mixed $value, string $label, int $maxLength, bool $required = false): string
{
    if (is_array($value) || is_object($value)) {
        throw new InvalidArgumentException("{$label} must be text.");
    }
    $text = trim((string)$value);
    if ($required && $text === '') {
        throw new InvalidArgumentException("{$label} is required.");
    }
    if (mb_strlen($text) > $maxLength) {
        throw new InvalidArgumentException("{$label} must not exceed {$maxLength} characters.");
    }
    return $text;
}

function ItourValidationInt(mixed $value, string $label, int $minimum, int $maximum): int
{
    if (is_int($value)) {
        $number = $value;
    } elseif (is_string($value) && preg_match('/^-?(?:0|[1-9]\d*)$/D', trim($value))) {
        $number = filter_var(trim($value), FILTER_VALIDATE_INT);
        if ($number === false) {
            throw new InvalidArgumentException("{$label} must be a whole number.");
        }
    } else {
        throw new InvalidArgumentException("{$label} must be a whole number.");
    }
    if ($number < $minimum || $number > $maximum) {
        throw new InvalidArgumentException("{$label} must be between {$minimum} and {$maximum}.");
    }
    return $number;
}

function ItourValidationMoney(mixed $value, string $label, float $maximum = 10000000.00, bool $allowZero = true): float
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException("{$label} must be a valid amount.");
    }
    $raw = trim((string)$value);
    if (!preg_match('/^(?:0|[1-9]\d{0,9})(?:\.\d{1,2})?$/D', $raw)) {
        throw new InvalidArgumentException("{$label} must be a non-negative amount with no more than two decimal places.");
    }
    $amount = (float)$raw;
    if (!is_finite($amount) || (!$allowZero && $amount <= 0) || $amount < 0 || $amount > $maximum) {
        $minimumText = $allowZero ? 'zero or greater' : 'greater than zero';
        throw new InvalidArgumentException("{$label} must be {$minimumText} and no more than " . number_format($maximum, 2, '.', '') . '.');
    }
    return round($amount, 2);
}

function ItourValidationDecimal(mixed $value, string $label, float $minimum, float $maximum, int $decimalPlaces = 7): float
{
    if (!is_string($value) && !is_int($value) && !is_float($value)) {
        throw new InvalidArgumentException("{$label} must be a number.");
    }
    $raw = trim((string)$value);
    $pattern = '/^-?(?:0|[1-9]\\d*)(?:\\.\\d{1,' . $decimalPlaces . '})?$/D';
    if (!preg_match($pattern, $raw)) throw new InvalidArgumentException("{$label} has an invalid number format.");
    $number = (float)$raw;
    if (!is_finite($number) || $number < $minimum || $number > $maximum) {
        throw new InvalidArgumentException("{$label} must be between {$minimum} and {$maximum}.");
    }
    return $number;
}

function ItourValidationDate(mixed $value, string $label): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException("{$label} must use YYYY-MM-DD format.");
    }
    $raw = trim($value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$date || $date->format('Y-m-d') !== $raw
        || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
        throw new InvalidArgumentException("{$label} must be a real date in YYYY-MM-DD format.");
    }
    return $raw;
}

function ItourValidationDateTime(mixed $value, string $label, array $formats = ['Y-m-d\\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i']): string
{
    if (!is_string($value)) {
        throw new InvalidArgumentException("{$label} has an invalid date and time.");
    }
    $raw = trim($value);
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $raw);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date && $date->format($format) === $raw
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    throw new InvalidArgumentException("{$label} has an invalid date and time.");
}

function ItourValidationClock(mixed $value, string $label, bool $required = false): ?string
{
    if ($value === null || $value === '') {
        if ($required) {
            throw new InvalidArgumentException("{$label} is required.");
        }
        return null;
    }
    if (!is_string($value) || !preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d$/D', trim($value))) {
        throw new InvalidArgumentException("{$label} must use a valid 24-hour HH:MM time.");
    }
    return trim($value);
}

function ItourValidationList(mixed $value, string $label, int $maximumItems, int $maximumItemLength): array
{
    if (!is_array($value)) {
        throw new InvalidArgumentException("{$label} must be a list.");
    }
    if (count($value) > $maximumItems) {
        throw new InvalidArgumentException("{$label} may contain at most {$maximumItems} items.");
    }
    $result = [];
    foreach ($value as $item) {
        $result[] = ItourValidationText($item, $label . ' item', $maximumItemLength, true);
    }
    return $result;
}

function ItourValidationPackageRequest(array $source): array
{
    $title = ItourValidationText($source['package_title'] ?? null, 'Package title', 180, true);
    $price = ItourValidationMoney($source['price'] ?? null, 'Package price');
    $type = strtolower(ItourValidationText($source['package_type'] ?? null, 'Package type', 20, true));
    if (!in_array($type, ['same-day', 'overnight'], true)) {
        throw new InvalidArgumentException('Invalid package type.');
    }
    $range = ItourValidationText($source['package_range'] ?? '', 'Package duration', 80);
    if ($range !== '' && !preg_match('/^[\pL\pN .,()&+\/-]+$/uD', $range)) {
        throw new InvalidArgumentException('Package duration contains unsupported characters.');
    }

    $keys = ['itinerary_id', 'step_title', 'start_time', 'end_time', 'description'];
    $presentKeys = array_filter($keys, static fn(string $key): bool => array_key_exists($key, $source));
    if (!$presentKeys) {
        foreach ($keys as $key) $source[$key] = [];
    }
    foreach ($keys as $key) {
        if (!array_key_exists($key, $source) || !is_array($source[$key])) {
            throw new InvalidArgumentException('Itinerary fields must be submitted as aligned lists.');
        }
    }
    $rowCount = count($source['step_title']);
    if ($rowCount > 30) {
        throw new InvalidArgumentException('An itinerary may contain at most 30 steps.');
    }
    foreach ($keys as $key) {
        if (count($source[$key]) !== $rowCount) {
            throw new InvalidArgumentException('Itinerary fields must have matching row counts.');
        }
    }
    $hasOrders = array_key_exists('display_order', $source);
    if ($hasOrders && (!is_array($source['display_order']) || count($source['display_order']) !== $rowCount)) {
        throw new InvalidArgumentException('Itinerary display order must be an aligned list.');
    }

    $steps = [];
    $orders = [];
    for ($index = 0; $index < $rowCount; $index++) {
        $stepTitle = ItourValidationText($source['step_title'][$index], 'Itinerary step title', 180);
        $description = ItourValidationText($source['description'][$index], 'Itinerary description', 5000);
        $start = ItourValidationClock($source['start_time'][$index], 'Itinerary start time');
        $end = ItourValidationClock($source['end_time'][$index], 'Itinerary end time');
        $rawId = $source['itinerary_id'][$index];
        $id = ($rawId === '' || $rawId === null) ? 0 : ItourValidationInt($rawId, 'Itinerary ID', 1, PHP_INT_MAX);
        if ($stepTitle === '') {
            if ($description !== '' || $start !== null || $end !== null || $id > 0) {
                throw new InvalidArgumentException('Each populated itinerary row requires a step title.');
            }
            continue;
        }
        $order = $hasOrders
            ? ItourValidationInt($source['display_order'][$index], 'Itinerary display order', 1, 30)
            : $index + 1;
        if (isset($orders[$order])) {
            throw new InvalidArgumentException('Itinerary display orders must be unique.');
        }
        $orders[$order] = true;
        $steps[] = ['id' => $id, 'title' => $stepTitle, 'start' => $start, 'end' => $end,
            'description' => $description, 'order' => $order];
    }

    return ['title' => $title, 'price' => $price, 'type' => $type, 'range' => $range, 'steps' => $steps];
}

function ItourValidationMediaPath(mixed $value, string $label, bool $required = false): string
{
    $path = str_replace('\\', '/', ItourValidationText($value, $label, 255, $required));
    if ($path === '') return '';
    if (!preg_match('#^(?:uploads|img|php/upload)/[A-Za-z0-9._-]+$#D', $path)
        || str_contains($path, '..')) {
        throw new InvalidArgumentException("{$label} must reference an application-managed image.");
    }
    return $path;
}
