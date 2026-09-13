<?php

function ensureComplaintsIncidentsTable(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS complaints_incidents (
            complaint_incident_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            reference_number VARCHAR(32) NOT NULL,
            tourist_id INT NOT NULL,
            report_type ENUM('complaint', 'incident') NOT NULL,
            category VARCHAR(80) NOT NULL,
            subject VARCHAR(180) NOT NULL,
            incident_at DATETIME NOT NULL,
            location VARCHAR(220) NOT NULL,
            description TEXT NOT NULL,
            people_involved VARCHAR(500) NULL,
            immediate_action TEXT NULL,
            preferred_contact ENUM('email', 'phone', 'either') NOT NULL DEFAULT 'email',
            evidence_paths LONGTEXT NULL,
            status ENUM('submitted', 'in_review', 'resolved', 'dismissed') NOT NULL DEFAULT 'submitted',
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (complaint_incident_id),
            UNIQUE KEY uq_complaints_reference (reference_number),
            KEY idx_complaints_tourist (tourist_id),
            KEY idx_complaints_status_date (status, submitted_at),
            KEY idx_complaints_type (report_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = $pdo->query('SHOW COLUMNS FROM complaints_incidents')->fetchAll(PDO::FETCH_COLUMN);
    $additions = [
        'priority' => "ADD COLUMN priority ENUM('low','normal','high','urgent') NOT NULL DEFAULT 'normal' AFTER status",
        'assigned_admin_id' => 'ADD COLUMN assigned_admin_id INT NULL AFTER priority',
        'first_reviewed_at' => 'ADD COLUMN first_reviewed_at DATETIME NULL AFTER assigned_admin_id',
        'resolved_at' => 'ADD COLUMN resolved_at DATETIME NULL AFTER first_reviewed_at',
        'resolution_summary' => 'ADD COLUMN resolution_summary TEXT NULL AFTER resolved_at',
    ];
    foreach ($additions as $column => $definition) {
        if (!in_array($column, $columns, true)) {
            $pdo->exec('ALTER TABLE complaints_incidents ' . $definition);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS complaint_incident_updates (
            update_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            complaint_incident_id BIGINT UNSIGNED NOT NULL,
            admin_id INT NULL,
            action_type VARCHAR(40) NOT NULL DEFAULT 'note',
            from_status VARCHAR(24) NULL,
            to_status VARCHAR(24) NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (update_id),
            KEY idx_complaint_updates_case (complaint_incident_id, created_at),
            KEY idx_complaint_updates_admin (admin_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function complaintCsrfToken(): string
{
    if (session_status() === PHP_SESSION_NONE) {
    require_once __DIR__ . '/session_security.php';
    AppSessionStart();
    }
    if (empty($_SESSION['complaint_csrf'])) {
        $_SESSION['complaint_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['complaint_csrf'];
}
