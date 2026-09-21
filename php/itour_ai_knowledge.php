<?php
declare(strict_types=1);

function itourAiText(string $value, int $limit = 280): string
{
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    return mb_substr($value, 0, $limit);
}

function itourAiDestinationKey(string $value): string
{
    $value = html_entity_decode(trim($value), ENT_QUOTES, 'UTF-8');
    $value = str_replace(['ñ', 'Ñ', 'Ã±', 'Ã‘'], 'n', $value);
    $value = mb_strtolower($value, 'UTF-8');
    $value = preg_replace('/\bisland\b/u', '', $value) ?? $value;
    return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function itourAiRows(PDO $pdo, string $sql): array
{
    try {
        $statement = $pdo->query($sql);
        return $statement ? ($statement->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $error) {
        error_log('iTour AI knowledge query failed: ' . $error->getMessage());
        return [];
    }
}

function itourAiActivities(mixed $value): array
{
    if (is_array($value)) return array_values(array_filter(array_map('strval', $value)));
    $decoded = json_decode((string)$value, true);
    if (is_array($decoded)) return array_values(array_filter(array_map('strval', $decoded)));
    return array_values(array_filter(array_map('trim', explode(',', (string)$value))));
}

function itourAiWebsiteKnowledge(PDO $pdo): array
{
    $knowledge = [
        'developers' => [
            'John Jaspher O. Dela Pacion',
            'Jacqueline Alyzza G. Asis',
            'Mark Oliver Coronel',
        ],
        'destinations' => [],
        'hotels' => [],
        'packages' => [],
        'guides' => [],
        'boats' => [],
    ];

    $destinations = itourAiRows($pdo, "
        SELECT destination_id, title, tagline, description, destination_type,
               location, activities, sort_order
        FROM destinations
        WHERE status = 'published'
        ORDER BY sort_order ASC, destination_id ASC
    ");
    $bookingCounts = [];
    foreach (itourAiRows($pdo, "
        SELECT location
        FROM bookings
        WHERE TRIM(COALESCE(location, '')) <> ''
          AND LOWER(COALESCE(status, '')) NOT IN ('cancelled', 'declined', 'rejected')
          AND LOWER(COALESCE(is_complete, '')) NOT IN ('cancelled', 'declined', 'rejected')
    ") as $booking) {
        $uniqueDestinations = [];
        foreach (explode(',', (string)($booking['location'] ?? '')) as $location) {
            $key = itourAiDestinationKey($location);
            if ($key !== '') $uniqueDestinations[$key] = true;
        }
        foreach (array_keys($uniqueDestinations) as $key) {
            $bookingCounts[$key] = ($bookingCounts[$key] ?? 0) + 1;
        }
    }

    foreach ($destinations as $destination) {
        $knowledge['destinations'][] = [
            'title' => itourAiText((string)($destination['title'] ?? ''), 180),
            'tagline' => itourAiText((string)($destination['tagline'] ?? ''), 220),
            'description' => itourAiText((string)($destination['description'] ?? ''), 360),
            'type' => itourAiText((string)($destination['destination_type'] ?? ''), 80),
            'location' => itourAiText((string)($destination['location'] ?? ''), 180),
            'activities' => array_slice(itourAiActivities($destination['activities'] ?? ''), 0, 10),
            'booking_count' => (int)($bookingCounts[itourAiDestinationKey((string)($destination['title'] ?? ''))] ?? 0),
            'sort_order' => (int)($destination['sort_order'] ?? 0),
        ];
    }
    usort($knowledge['destinations'], static fn(array $left, array $right): int =>
        $right['booking_count'] <=> $left['booking_count']
        ?: $left['sort_order'] <=> $right['sort_order']
    );

    $knowledge['hotels'] = array_slice(itourAiRows($pdo, "
        SELECT h.name, h.island, h.type, h.description_text,
               COUNT(b.hotel_booking_id) AS booking_count
        FROM hotel_resorts h
        LEFT JOIN hotel_room_bookings b
          ON b.hotel_resort_id = h.hotel_resort_id
         AND LOWER(COALESCE(b.booking_status, '')) NOT IN ('cancelled', 'declined', 'rejected', 'no-show')
        WHERE h.status = 'active'
        GROUP BY h.hotel_resort_id, h.name, h.island, h.type, h.description_text
        ORDER BY booking_count DESC, h.popular DESC, h.name ASC
    "), 0, 12);

    $knowledge['packages'] = array_slice(itourAiRows($pdo, "
        SELECT p.package_title, p.package_type, p.package_range,
               COUNT(b.booking_id) AS booking_count
        FROM tour_packages p
        LEFT JOIN operators o ON o.operator_id = p.operator_id
        LEFT JOIN bookings b
          ON b.operator_id = p.operator_id
         AND LOWER(TRIM(b.package_name)) = LOWER(TRIM(p.package_title))
         AND LOWER(COALESCE(b.booking_type, '')) = 'package'
         AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'declined', 'rejected')
         AND LOWER(COALESCE(b.is_complete, '')) NOT IN ('cancelled', 'declined', 'rejected')
        WHERE o.operator_id IS NULL OR o.status = 'active'
        GROUP BY p.package_id, p.package_title, p.package_type, p.package_range
        ORDER BY booking_count DESC, p.package_id DESC
    "), 0, 12);

    $knowledge['guides'] = array_slice(itourAiRows($pdo, "
        SELECT g.fullname, g.short_description, g.experience,
               COUNT(b.booking_id) AS booking_count
        FROM tour_guides g
        LEFT JOIN bookings b
          ON b.guide_id = g.guide_id
         AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'declined', 'rejected')
         AND LOWER(COALESCE(b.is_complete, '')) NOT IN ('cancelled', 'declined', 'rejected')
        GROUP BY g.guide_id, g.fullname, g.short_description, g.experience
        ORDER BY booking_count DESC, g.guide_id DESC
    "), 0, 12);

    $knowledge['boats'] = array_slice(itourAiRows($pdo, "
        SELECT bo.name, bo.total_pax, bo.size, bo.short_description,
               COUNT(b.booking_id) AS booking_count
        FROM boats bo
        LEFT JOIN bookings b
          ON b.boat_id = bo.boat_id
         AND LOWER(COALESCE(b.status, '')) NOT IN ('cancelled', 'declined', 'rejected')
         AND LOWER(COALESCE(b.is_complete, '')) NOT IN ('cancelled', 'declined', 'rejected')
        GROUP BY bo.boat_id, bo.name, bo.total_pax, bo.size, bo.short_description
        ORDER BY booking_count DESC, bo.boat_id DESC
    "), 0, 12);

    return $knowledge;
}

function itourAiRankedNames(array $rows, string $nameKey, int $limit = 3): array
{
    $ranked = array_values(array_filter($rows, static fn(array $row): bool => trim((string)($row[$nameKey] ?? '')) !== ''));
    usort($ranked, static fn(array $left, array $right): int =>
        (int)($right['booking_count'] ?? 0) <=> (int)($left['booking_count'] ?? 0)
    );
    return array_slice($ranked, 0, $limit);
}

function itourAiDirectAnswer(string $message, array $knowledge): ?string
{
    $question = mb_strtolower(trim($message), 'UTF-8');

    if (preg_match('/\b(developer|developers|development team|who (?:made|built|created|developed)|who are behind)\b/u', $question)) {
        return 'The iTour Mercedes platform developers are John Jaspher O. Dela Pacion, Jacqueline Alyzza G. Asis, and Mark Oliver Coronel. They are BS Information Systems students from the University of Camarines Norte.';
    }

    $asksPopularity = preg_match('/\b(most popular|popular|top|best|most visited)\b/u', $question) === 1;
    if ($asksPopularity && preg_match('/\b(hotel|resort|stay|accommodation)\b/u', $question)) {
        $top = itourAiRankedNames($knowledge['hotels'] ?? [], 'name');
        if ($top) {
            $first = $top[0];
            if ((int)($first['booking_count'] ?? 0) === 0) {
                return 'The website does not yet have recorded non-cancelled hotel bookings to rank one stay as most popular. Active options include ' . implode(', ', array_column($top, 'name')) . '.';
            }
            $others = array_slice(array_column($top, 'name'), 1);
            $answer = (string)$first['name'] . ' is currently the most-booked hotel/resort in iTour Mercedes';
            if ((int)($first['booking_count'] ?? 0) > 0) $answer .= ' with ' . (int)$first['booking_count'] . ' recorded booking' . ((int)$first['booking_count'] === 1 ? '' : 's');
            if ($others) $answer .= '. Other popular choices are ' . implode(' and ', $others);
            return $answer . '. Availability and current prices still need to be checked on its listing.';
        }
    }

    if ($asksPopularity && preg_match('/\b(package|tour package)\b/u', $question)) {
        $top = itourAiRankedNames($knowledge['packages'] ?? [], 'package_title');
        if ($top) {
            $first = $top[0];
            if ((int)($first['booking_count'] ?? 0) === 0) {
                return 'The website does not yet have recorded non-cancelled package bookings to rank one package as most popular. Current packages include ' . implode(', ', array_column($top, 'package_title')) . '.';
            }
            return (string)$first['package_title'] . ' is currently the most-booked tour package in the website data, with ' . (int)($first['booking_count'] ?? 0) . ' recorded booking' . ((int)($first['booking_count'] ?? 0) === 1 ? '' : 's') . '.';
        }
    }

    if ($asksPopularity && preg_match('/\b(guide|tour guide)\b/u', $question)) {
        $top = itourAiRankedNames($knowledge['guides'] ?? [], 'fullname', 1);
        if ($top && (int)($top[0]['booking_count'] ?? 0) > 0) return (string)$top[0]['fullname'] . ' is currently the most-booked tour guide in the website data.';
        if ($top) return 'The website does not yet have recorded non-cancelled guide bookings to rank one guide as most popular.';
    }

    if ($asksPopularity && preg_match('/\b(boat|tour boat)\b/u', $question)) {
        $top = itourAiRankedNames($knowledge['boats'] ?? [], 'name', 1);
        if ($top && (int)($top[0]['booking_count'] ?? 0) > 0) return (string)$top[0]['name'] . ' is currently the most-booked tour boat in the website data.';
        if ($top) return 'The website does not yet have recorded non-cancelled boat bookings to rank one boat as most popular.';
    }

    $mentionsOtherPopularCategory = preg_match('/\b(hotel|resort|stay|accommodation|package|guide|boat)\b/u', $question) === 1;
    if ($asksPopularity && (preg_match('/\b(destination|place|island|visit|attraction)\b/u', $question) || !$mentionsOtherPopularCategory)) {
        $top = itourAiRankedNames($knowledge['destinations'] ?? [], 'title');
        if ($top) {
            $first = $top[0];
            $answer = 'Based on recorded non-cancelled bookings, ' . $first['title'] . ' is currently the most popular destination';
            if ((int)$first['booking_count'] > 0) $answer .= ' with ' . (int)$first['booking_count'] . ' booking' . ((int)$first['booking_count'] === 1 ? '' : 's');
            $next = array_slice(array_column($top, 'title'), 1);
            if ($next) $answer .= '. Other popular places are ' . implode(' and ', $next);
            return $answer . '.';
        }
    }

    foreach ($knowledge['destinations'] ?? [] as $destination) {
        $title = (string)($destination['title'] ?? '');
        $needle = mb_strtolower(preg_replace('/\s+island$/iu', '', $title) ?? $title, 'UTF-8');
        if (mb_strlen($needle) < 5 || !str_contains($question, $needle)) continue;
        $answer = $title . ': ' . (string)($destination['description'] ?: $destination['tagline']);
        $activities = array_slice((array)($destination['activities'] ?? []), 0, 6);
        if ($activities) $answer .= ' Activities include ' . implode(', ', $activities) . '.';
        return $answer;
    }

    if (preg_match('/\b(what|which|list|show|name)\b.*\b(destinations?|places?|islands?|attractions?)\b/u', $question)) {
        $names = array_column($knowledge['destinations'] ?? [], 'title');
        if ($names) return 'iTour Mercedes currently lists: ' . implode(', ', $names) . '.';
    }

    if (preg_match('/\b(what is|about)\s+i?tour mercedes\b/u', $question)) {
        return 'iTour Mercedes is the local tourism platform for Mercedes, Camarines Norte. It helps visitors explore destinations and find hotels/resorts, tour packages, tour guides, and tour boats, then complete bookings through a tourist account.';
    }

    if (preg_match('/\b(book|booking|reserve)\b/u', $question)) {
        if (preg_match('/\b(hotel|resort|room|stay)\b/u', $question)) {
            return 'Open **Tours → Hotel/Resort**, enter the destination, stay dates, guests, and rooms, then press **Search**. Open a hotel or resort, choose an available room, and continue to the booking form. Log in or create a tourist account when prompted.';
        }
        if (preg_match('/\b(package|tour package)\b/u', $question)) {
            return 'Open **Tours → Tour Packages**, choose the destination, trip type, dates, and guests, then press **Search**. Open a package to review its details and itinerary, select **Book now**, and log in or create a tourist account to complete the booking.';
        }
        if (preg_match('/\b(guide|tour guide)\b/u', $question)) {
            return 'Open **Tours → Tour Guide**, complete the search, open the guide you want, and select **Book now**. You must log in or create a tourist account to finish the booking.';
        }
        if (preg_match('/\b(boat|tour boat)\b/u', $question)) {
            return 'Open **Tours → Tour Boat**, complete the search, open the boat you want, and select **Book now**. You must log in or create a tourist account to finish the booking.';
        }
    }

    if (preg_match('/\b(contact|tourism office|where is the office|office address)\b/u', $question)) {
        return 'The Municipal Tourism Office is at the Municipal Hall in Mercedes, Camarines Norte, near Mercedes-Manguisoc Port. The website footer lists baliksiglamercedes@gmail.com and the Municipal Tourism Office - LGU Mercedes Facebook page.';
    }

    return null;
}

function itourAiKnowledgePrompt(array $knowledge): string
{
    $lines = [
        'LIVE PUBLIC WEBSITE DATA (authoritative for this answer):',
        'Developers: ' . implode(', ', $knowledge['developers'] ?? []) . '. All are BS Information Systems students from the University of Camarines Norte.',
        'Tourism Office: Municipal Tourism Office, Mercedes, Camarines Norte; near Mercedes-Manguisoc Port.',
    ];

    foreach ($knowledge['destinations'] ?? [] as $item) {
        $lines[] = sprintf(
            'Destination: %s | bookings=%d | %s | %s | activities: %s',
            $item['title'],
            $item['booking_count'],
            $item['tagline'],
            $item['description'],
            implode(', ', $item['activities'])
        );
    }
    foreach (['hotels' => 'name', 'packages' => 'package_title', 'guides' => 'fullname', 'boats' => 'name'] as $group => $nameKey) {
        $entries = [];
        foreach ($knowledge[$group] ?? [] as $item) {
            $entries[] = (string)($item[$nameKey] ?? '') . ' (bookings=' . (int)($item['booking_count'] ?? 0) . ')';
        }
        if ($entries) $lines[] = ucfirst($group) . ': ' . implode('; ', $entries) . '.';
    }

    return implode("\n", $lines);
}

function itourAiFallbackAnswer(string $message, array $knowledge): string
{
    $direct = itourAiDirectAnswer($message, $knowledge);
    if ($direct !== null) return $direct;

    return 'I can still help with iTour Mercedes website information, but the AI service is temporarily busy. Ask me for the most popular destination, a specific island, the developers, hotels/resorts, tour packages, guides, boats, or booking steps.';
}
