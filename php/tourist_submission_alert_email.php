<?php
declare(strict_types=1);

require_once __DIR__ . '/provider_cancellation_email.php';
require_once __DIR__ . '/booking_reference_helper.php';
require_once __DIR__ . '/tour_resource_availability_helper.php';

function touristSubmissionAlertEmailData(array $booking): array
{
    $type = strtolower((string)($booking['booking_type'] ?? ''));
    $service = match ($type) {
        'package' => (string)($booking['package_name'] ?? ''),
        'boat' => (string)($booking['boat_name'] ?? ''),
        'tourguide' => (string)($booking['guide_name'] ?? ''),
        default => '',
    };
    $start = (string)($booking['booking_date'] ?? '');
    $end = tourResourceEndDate($booking);
    $schedule = BookingConfirmationEmailDate($start);
    if ($end !== '' && $end !== $start) $schedule .= ' – ' . BookingConfirmationEmailDate($end);

    return [
        'purpose' => 'tourist_submission_alert',
        'guest_name' => trim((string)($booking['full_name'] ?? '')) ?: 'Guest',
        'service_label' => 'Tour Booking',
        'booking_reference' => BookingReferenceDisplay($booking),
        'tourist_submission_booking_id' => (int)$booking['booking_id'],
        'intro' => 'Your tourist list is required for this accepted booking. Please complete the submission before your scheduled trip.',
        'details' => [
            'Booking reference' => BookingReferenceDisplay($booking),
            'Booking status' => 'Accepted',
            'Booking type' => ucfirst($type),
            'Selected service' => $service,
            'Destination(s)' => (string)($booking['location'] ?? ''),
            'Tour schedule' => $schedule,
            'Tour arrangement' => ucwords(str_replace('_', ' ', (string)($booking['tour_type'] ?? ''))),
            'Jump-off point' => (string)($booking['jump_off_port'] ?? ''),
            'Expected tourists' => (string)((int)($booking['num_adults'] ?? 0) + (int)($booking['num_children'] ?? 0)),
        ],
        'important_note' => 'Sign in using the tourist account that made this booking. Go to Profile > Bookings, find booking ' . BookingReferenceDisplay($booking) . ', and select Add Tourist. Include yourself and every guest. The Submit Tourist Details button opens the form for this specific booking. You may receive another reminder while no submission has been recorded.',
    ];
}

function sendTouristSubmissionAlertEmail(array $booking): bool
{
    $data = touristSubmissionAlertEmailData($booking);
    $url = itourEmailProfileUrl(['section' => 'bookings', 'submit_tourists' => (int)$booking['booking_id']]);
    $plain = 'Dear ' . $data['guest_name'] . ",\n\nWe have not yet received your tourist list. Please submit every tourist, including yourself, before your trip.\n\n";
    foreach ($data['details'] as $label => $value) {
        if ($value !== '') $plain .= $label . ': ' . $value . "\n";
    }
    $plain .= "\n" . $data['important_note'] . "\n\nSubmit Tourist Details: " . $url
        . "\nMy Bookings: " . itourEmailProfileUrl(['section' => 'bookings']);

    return sendBookingWorkflowEmail(
        trim((string)($booking['email'] ?? '')),
        $data['guest_name'],
        'iTour Mercedes | Tourist List Required (' . BookingReferenceDisplay($booking) . ')',
        BookingConfirmationEmailTemplate($data),
        $plain
    );
}
