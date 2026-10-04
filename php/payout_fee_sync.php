<?php
declare(strict_types=1);
require_once __DIR__ . '/payout_accounting.php';
require_once __DIR__ . '/../payments/PayMongoService.php';

/** Small authenticated background batches, independent of payment confirmation. */
final class PayoutFeeSync
{
    public static function scope(string $role, int $id): array
    {
        return match ($role) {
            'admin' => ['1=1', []],
            'operator' => ["pt.booking_domain='package' AND EXISTS (SELECT 1 FROM bookings b WHERE b.booking_id=pt.booking_id AND b.operator_id=?)", [$id]],
            'hotel' => ["pt.booking_domain='hotel' AND EXISTS (SELECT 1 FROM hotel_room_bookings b WHERE b.hotel_booking_id=pt.booking_id AND b.hotel_resort_id=?)", [$id]],
            default => throw new InvalidArgumentException('Invalid accounting scope.'),
        };
    }

    // Untagged historical records may be looked up; the remote resource must still match
    // the current service mode, payment ID, gross amount and currency before saving.
    public static function modeMatches(array $transaction, bool $live): bool
    {
        $metadata = PayoutAccounting::metadata($transaction);
        return !array_key_exists('paymongo_mode', $metadata) || $metadata['paymongo_mode'] === ($live ? 'live' : 'test');
    }

    public static function batch(PDO $pdo, string $role, int $id, ?object $service = null): array
    {
        $service ??= PayMongoService::fromEnvironment();
        [$scope, $params] = self::scope($role, $id);
        $now = time(); $retryBefore = $now - 300;
        $stmt = $pdo->prepare("SELECT pt.* FROM payment_transactions pt WHERE $scope
            AND pt.provider='paymongo' AND pt.status='paid' AND pt.provider_payment_id IS NOT NULL
            AND JSON_EXTRACT(pt.metadata,'$.paymongo_accounting.fee_minor') IS NULL
            AND (JSON_EXTRACT(pt.metadata,'$.paymongo_mode') IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(pt.metadata,'$.paymongo_mode'))=?)
            AND CAST(COALESCE(JSON_EXTRACT(pt.metadata,'$.paymongo_fee_attempt_at'),0) AS UNSIGNED) < CAST(? AS UNSIGNED)
            ORDER BY pt.payment_transaction_id LIMIT 1");
        $stmt->execute(array_merge($params, [$service->isLiveMode() ? 'live' : 'test', $retryBefore]));
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$transaction) return ['updated' => 0, 'more' => false];
        // Atomic claim prevents duplicate requests across simultaneous admin tabs.
        $claim = $pdo->prepare("UPDATE payment_transactions SET metadata=JSON_SET(COALESCE(metadata,JSON_OBJECT()),'$.paymongo_fee_attempt_at',CAST(? AS UNSIGNED))
            WHERE payment_transaction_id=? AND status='paid' AND JSON_EXTRACT(metadata,'$.paymongo_accounting.fee_minor') IS NULL
            AND CAST(COALESCE(JSON_EXTRACT(metadata,'$.paymongo_fee_attempt_at'),0) AS UNSIGNED) < CAST(? AS UNSIGNED)");
        $claim->execute([$now, $transaction['payment_transaction_id'], $retryBefore]);
        if ($claim->rowCount() !== 1) return ['updated' => 0, 'more' => true];
        try {
            $resource = $service->retrievePayment((string)$transaction['provider_payment_id'])['data'] ?? [];
            PayoutAccounting::capture($pdo, $transaction, $resource, $service->isLiveMode());
            return ['updated' => 1, 'more' => true];
        } catch (Throwable $error) {
            // Preserve unknown values and retry later; never estimate or return diagnostics/PII.
            return ['updated' => 0, 'more' => true];
        }
    }

    /** Call only after the page's existing role authorization and property ownership check. */
    public static function handle(PDO $pdo, string $role, int $id = 0): void
    {
        if (empty($_SESSION['payout_fee_csrf'])) $_SESSION['payout_fee_csrf'] = bin2hex(random_bytes(32));
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ($_POST['accounting_action'] ?? '') !== 'sync_fees') return;
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        if (!hash_equals($_SESSION['payout_fee_csrf'], (string)($_POST['csrf_token'] ?? ''))) {
            http_response_code(403); echo json_encode(['updated' => 0, 'more' => false]); exit;
        }
        session_write_close(); // Network work must not lock the admin's other pages.
        try { echo json_encode(self::batch($pdo, $role, $id)); }
        catch (Throwable $error) { http_response_code(503); echo json_encode(['updated' => 0, 'more' => false]); }
        exit;
    }

    public static function script(string $assetBase = ''): string
    {
        return '<script>window.payoutFeeSync=' . json_encode(['csrf' => $_SESSION['payout_fee_csrf'] ?? ''], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)
            . ';</script><script src="' . htmlspecialchars($assetBase, ENT_QUOTES) . 'js/payout_fee_sync.js?v=1"></script>';
    }
}
