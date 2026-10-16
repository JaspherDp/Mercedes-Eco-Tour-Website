<?php
declare(strict_types=1);

require_once __DIR__ . '/input_validation.php';

function BookingAddonsEnsureSchema(PDO $pdo): void
{
    $known = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'booking_expenses'")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['expense_name' => 'VARCHAR(160) NULL', 'quantity' => 'INT UNSIGNED NULL', 'unit_price' => 'DECIMAL(12,2) NULL'] as $column => $definition) {
        if (!in_array($column, $known, true)) {
            try { $pdo->exec("ALTER TABLE booking_expenses ADD COLUMN {$column} {$definition}"); }
            catch (PDOException $error) { if (($error->errorInfo[1] ?? 0) !== 1060) throw $error; }
        }
    }
}

function BookingAddonsValidateRows(array $input): array
{
    $types = ['additional_boat' => 'Additional Boat', 'additional_tourguide' => 'Additional Tour Guide', 'food' => 'Food', 'others' => 'Other Add-on'];
    // Keep older single-charge callers compatible with the itemized form.
    if (!is_array($input['expense_type'] ?? null)) {
        $type = ItourValidationText($input['expense_type'] ?? '', 'Add-on type', 40, true);
        $input = ['expense_type' => [$type], 'expense_name' => [$types[$type] ?? $type], 'quantity' => ['1'], 'unit_price' => [$input['amount'] ?? ''], 'note' => [$input['note'] ?? '']];
    }
    $keys = ['expense_type', 'expense_name', 'quantity', 'unit_price', 'note'];
    $count = count($input['expense_type']);
    if ($count < 1 || $count > 25) throw new InvalidArgumentException('Add between 1 and 25 add-on items.');
    foreach ($keys as $key) {
        if (!is_array($input[$key] ?? null) || count($input[$key]) !== $count || array_keys($input[$key]) !== range(0, $count - 1)) throw new InvalidArgumentException('Add-on fields must contain matching item rows.');
    }
    $rows = [];
    $totalMinor = 0;
    for ($index = 0; $index < $count; $index++) {
        $type = ItourValidationText($input['expense_type'][$index], 'Add-on type', 40, true);
        if (!isset($types[$type])) throw new InvalidArgumentException('Choose a valid add-on type for every item.');
        $name = ItourValidationText($input['expense_name'][$index], 'Add-on description', 160, true);
        $quantity = ItourValidationInt($input['quantity'][$index], 'Quantity', 1, 1000);
        $unitMinor = (int)round(ItourValidationMoney($input['unit_price'][$index], 'Unit price', 10000000, false) * 100);
        $amountMinor = $quantity * $unitMinor;
        $totalMinor += $amountMinor;
        if ($amountMinor <= 0 || $totalMinor > 1000000000) throw new InvalidArgumentException('Add-ons must have a positive total of no more than ₱10,000,000.00.');
        $rows[] = ['type' => $type, 'name' => $name, 'quantity' => $quantity, 'unit_price' => $unitMinor / 100,
            'amount' => $amountMinor / 100, 'note' => ItourValidationText($input['note'][$index], 'Add-on notes', 500)];
    }
    return ['rows' => $rows, 'total' => $totalMinor / 100];
}

function BookingAddonsSave(PDO $pdo, int $bookingId, array $batch): array
{
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $check = $pdo->prepare('SELECT status, is_complete FROM bookings WHERE booking_id = ?' . $lock);
        $check->execute([$bookingId]);
        $booking = $check->fetch(PDO::FETCH_ASSOC);
        if (!$booking || !in_array(strtolower((string)$booking['status']), ['pending', 'accepted'], true) || strtolower((string)$booking['is_complete']) !== 'uncomplete') {
            throw new RuntimeException('Add-ons can only be added to pending or accepted bookings.');
        }
        $insert = $pdo->prepare('INSERT INTO booking_expenses (booking_id, expense_type, expense_name, quantity, unit_price, amount, note) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ($batch['rows'] as $row) $insert->execute([$bookingId, $row['type'], $row['name'], $row['quantity'], $row['unit_price'], $row['amount'], $row['note']]);
        $updatedAt = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? 'NOW()' : 'CURRENT_TIMESTAMP';
        $update = $pdo->prepare("UPDATE bookings SET grand_total = grand_total + ?, remaining_balance = remaining_balance + ?, is_paid = 0, updated_at = {$updatedAt} WHERE booking_id = ?");
        $update->execute([$batch['total'], $batch['total'], $bookingId]);
        $result = $pdo->prepare('SELECT grand_total, payment_amount, remaining_balance FROM bookings WHERE booking_id = ?');
        $result->execute([$bookingId]);
        $totals = $result->fetch(PDO::FETCH_ASSOC);
        $pdo->commit();
        return $totals;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
