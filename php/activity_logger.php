<?php
/**
 * Records important user activity without interrupting the main operation.
 *
 * Call this only after the related INSERT/UPDATE/DELETE or transaction succeeds.
 */
function logActivity(
    PDO $pdo,
    string $actorType,
    int $actorId,
    string $actorName,
    string $action,
    string $description,
    string $module,
    ?int $referenceId = null
): bool {
    $allowedActorTypes = ['Admin', 'Tourist', 'Tour Operator', 'Hotel Owner'];
    $actorType = in_array($actorType, $allowedActorTypes, true) ? $actorType : 'Admin';
    $actorName = trim($actorName) !== '' ? trim($actorName) : $actorType;
    $action = trim($action);
    $description = trim($description);
    $module = trim($module);

    if ($action === '' || $description === '' || $module === '') {
        return false;
    }

    try {
        $stmt = $pdo->prepare(
            'INSERT INTO admin_activity_logs
             (admin_id, actor_type, actor_id, actor_name, action, module, description, reference_id, ip_address)
             VALUES (:admin_id, :actor_type, :actor_id, :actor_name, :action, :module, :description, :reference_id, :ip_address)'
        );

        return $stmt->execute([
            ':admin_id' => $actorType === 'Admin' && $actorId > 0 ? $actorId : null,
            ':actor_type' => $actorType,
            ':actor_id' => $actorId > 0 ? $actorId : null,
            ':actor_name' => mb_substr($actorName, 0, 190),
            ':action' => mb_substr($action, 0, 100),
            ':module' => mb_substr($module, 0, 100),
            ':description' => $description,
            ':reference_id' => $referenceId,
            ':ip_address' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 100),
        ]);
    } catch (Throwable $e) {
        error_log('Activity log could not be saved: ' . $e->getMessage());
        return false;
    }
}
