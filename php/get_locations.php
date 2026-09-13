<?php
header('Content-Type: application/json');
chdir(__DIR__ . '/..');
require_once 'php/db_connection.php';

function tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.tables
        WHERE table_schema = DATABASE() AND table_name = ?
    ");
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.columns
        WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?
    ");
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn()) > 0;
}

function appendLocations(PDO $pdo, array &$locations, string $table, array $candidateColumns): void
{
    if (!tableExists($pdo, $table)) {
        return;
    }

    foreach ($candidateColumns as $column) {
        if (!columnExists($pdo, $table, $column)) {
            continue;
        }

        $stmt = $pdo->query("SELECT DISTINCT `{$column}` FROM `{$table}` WHERE `{$column}` IS NOT NULL AND TRIM(`{$column}`) != '' ORDER BY `{$column}`");
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
        foreach ($rows as $value) {
            $name = trim((string)$value);
            if ($name !== '') {
                $locations[$name] = true;
            }
        }
    }
}

try {
    $locations = [];

    appendLocations($pdo, $locations, 'hotel_resorts', ['location', 'island', 'destination']);
    appendLocations($pdo, $locations, 'hotel_resort', ['location', 'island', 'destination']);
    appendLocations($pdo, $locations, 'tour_packages', ['destination', 'location']);
    appendLocations($pdo, $locations, 'tour_guides', ['location', 'destination']);
    appendLocations($pdo, $locations, 'boats', ['location', 'destination']);

    $payload = array_map(static function (string $name): array {
        return ['name' => $name];
    }, array_keys($locations));

    echo json_encode(array_values($payload));
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch locations']);
}
