<?php
declare(strict_types=1);
require_once __DIR__ . '/booking_tourists_helper.php';

/** Called only after AdminRequireLogin and role-scoped CSRF verification. */
function AdminWalkinManifestRequest(PDO $pdo, array $input, int $adminId): array
{
    if ($adminId < 1) throw new RuntimeException('An authenticated administrator is required.');
    $id = ItourValidationInt($input['booking_id'] ?? null, 'Booking ID', 1, PHP_INT_MAX);
    $action = (string)($input['action'] ?? '');
    if (!in_array($action, ['fetch_walkin_manifest','save_walkin_manifest'], true)) throw new InvalidArgumentException('Invalid manifest action.');
    if ($action === 'save_walkin_manifest') {
        $mode = (string)($input['manifest_mode'] ?? '');
        if (!in_array($mode, ['submit','edit'], true)) throw new InvalidArgumentException('Invalid tourist list mode.');
        $rows = bookingTouristManifestRows($input);
        bookingTouristSaveAdminWalkinManifest($pdo, $id, $adminId, $rows, (array)($input['boat_number'] ?? []), $mode === 'edit');
        return ['success'=>true, 'updated'=>$mode === 'edit', 'message'=>$mode === 'edit' ? 'The tourist list changes were saved.' : 'The tourist list was uploaded.'];
    }
    $query = $pdo->prepare("SELECT b.*, COALESCE(NULLIF(b.pax,0),b.num_adults+b.num_children) AS allowed_pax,
        EXISTS(SELECT 1 FROM booking_cancellation_requests cr WHERE cr.booking_domain='tour' AND cr.booking_id=b.booking_id
            AND cr.request_status IN ('pending','approved','decision_required','completed')) AS cancellation_active
        FROM bookings b WHERE b.booking_id=? AND b.tourist_id IS NULL LIMIT 1");
    $query->execute([$id]);
    $booking = $query->fetch(PDO::FETCH_ASSOC);
    if (!$booking || !bookingTouristAdminWalkinCanManage($booking) || (bool)$booking['cancellation_active']) {
        throw new RuntimeException('The tourist list can only be managed for paid, active no-account walk-ins without a cancellation request.');
    }
    $query = $pdo->prepare('SELECT * FROM booking_tourists WHERE booking_id=? ORDER BY id ASC');
    $query->execute([$id]);
    return ['success'=>true, 'can_edit'=>true, 'pax'=>(int)$booking['allowed_pax'],
        'booking_type'=>$booking['booking_type'], 'tourists'=>$query->fetchAll(PDO::FETCH_ASSOC)];
}
