<?php

declare(strict_types=1);

/** @return array{type:string,days:int,nights:int,range:string} */
function PackageCheckoutDuration(string $packageType, string $packageRange): array
{
    $normalizedType = strtolower(trim(str_replace('_', '-', $packageType)));
    $normalizedType = preg_replace('/\s+/', ' ', $normalizedType) ?: '';
    $type = match ($normalizedType) {
        'same-day', 'same day', 'day-tour', 'day tour' => 'same-day',
        'overnight', 'overnight-tour', 'overnight tour', 'multi-day', 'multi day' => 'overnight',
        default => '',
    };
    if ($type === '') {
        throw new DomainException('The selected package has an unsupported package type.');
    }

    $range = trim($packageRange);
    if ($type === 'same-day') {
        if (!preg_match('/^1\s*(?:d|day|days)$/i', $range)) {
            throw new DomainException('The selected same-day package has an unsupported duration.');
        }
        return ['type' => $type, 'days' => 1, 'nights' => 0, 'range' => $range];
    }

    if (!preg_match('/^(\d+)\s*(?:d|day|days)\s*(?:(?:&|and|\/|-)\s*)?(\d+)\s*(?:n|night|nights)$/i', $range, $match)) {
        throw new DomainException('The selected overnight package has an unsupported duration.');
    }
    $days = (int)$match[1];
    $nights = (int)$match[2];
    if ($days < 2 || $nights < 1 || $days !== $nights + 1) {
        throw new DomainException('The selected package duration is inconsistent.');
    }
    return ['type' => $type, 'days' => $days, 'nights' => $nights, 'range' => $range];
}
