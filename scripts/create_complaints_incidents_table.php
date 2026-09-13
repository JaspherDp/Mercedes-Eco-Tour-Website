<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../php/db_connection.php';
require_once __DIR__ . '/../php/complaints_incidents_helper.php';

ensureComplaintsIncidentsTable($pdo);
echo "complaints_incidents table is ready." . PHP_EOL;
