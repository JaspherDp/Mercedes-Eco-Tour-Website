<?php
declare(strict_types=1);

require_once __DIR__ . '/payment_receipt_breakdown.php';

/** Read-only receipt for the reservation authorized by the success-page session. */
function BookingSuccessReceipt(PDO $pdo, array $success): array
{
    $domain = (string)$success['booking_domain'];
    $hotel = $domain === 'hotel';
    $params = [(string)$success['booking_reference'], (int)$success['tourist_id']];
    if ($hotel) {
        $query = $pdo->prepare("SELECT hotel_booking_id, booking_reference, room_type,
            first_name, last_name, rooms_booked, nights, unit_price, checkin_date, checkout_date,
            checkout_total_nights, total_amount, amount_paid, remaining_balance,
            checkout_additional_charges, booking_status, hotel_resort_id
            FROM hotel_room_bookings WHERE booking_reference = ? AND tourist_id = ? LIMIT 1");
    } else {
        $query = $pdo->prepare("SELECT b.booking_id, b.booking_reference, b.booking_type,
            b.package_name, b.location, b.preferred_resource, b.operator_id, b.tour_type,
            b.num_adults, b.num_children, b.grand_total, b.payment_amount,
            b.remaining_balance, b.status, b.is_complete, t.full_name AS t_full_name
            FROM bookings b INNER JOIN tourist t ON t.tourist_id = b.tourist_id
            WHERE b.booking_reference = ? AND b.tourist_id = ? AND b.booking_type = ? LIMIT 1");
        $params[] = $domain;
    }
    $query->execute($params);
    $booking = $query->fetch(PDO::FETCH_ASSOC);
    if (!$booking) throw new RuntimeException('Receipt unavailable.');

    if ($hotel) {
        $booking['guest_name'] = trim($booking['first_name'] . ' ' . $booking['last_name']);
        $expensesQuery = $pdo->prepare('SELECT expense_type, expense_name, quantity, unit_price, total_amount, notes FROM hotel_booking_expenses WHERE hotel_booking_id = ? AND hotel_resort_id = ? ORDER BY created_at ASC');
        $expensesQuery->execute([(int)$booking['hotel_booking_id'], (int)$booking['hotel_resort_id']]);
    } else {
        $expensesQuery = $pdo->prepare('SELECT * FROM booking_expenses WHERE booking_id = ? ORDER BY id ASC');
        $expensesQuery->execute([(int)$booking['booking_id']]);
    }
    $expenses = $expensesQuery->fetchAll(PDO::FETCH_ASSOC);
    $paid = (float)($hotel ? $booking['amount_paid'] : $booking['payment_amount']);
    $balance = max(0.0, (float)$booking['remaining_balance']);
    $total = $hotel ? (float)$booking['total_amount'] : max((float)$booking['grand_total'], $paid + $balance);
    $checkoutCharges = $hotel ? (float)($booking['checkout_additional_charges'] ?? 0) : 0;
    $expenseTotal = array_sum(array_column($expenses, $hotel ? 'total_amount' : 'amount'));
    $service = max(0, round($total - $expenseTotal - $checkoutCharges, 2));
    return [
        'kind' => $hotel ? 'hotel' : 'tour', 'booking' => $booking,
        'expenses' => $expenses, 'serviceAmount' => $service,
        'priceBreakdown' => $hotel ? null : PaymentReceiptTourBreakdown($pdo, $booking, $service),
        'checkoutCharges' => $checkoutCharges, 'billingTotal' => $total,
        'amountPaid' => $paid, 'balance' => $balance,
        'paymentStatus' => $balance <= 0 ? 'Paid' : ($paid > 0 ? 'Partial' : 'Unpaid'),
    ];
}
