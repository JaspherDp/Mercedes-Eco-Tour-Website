<?php
/*header('Content-Type: application/json');
require 'db_connection.php';

try {
    $stmt = $pdo->prepare("
        SELECT 
            inquiry_id,
            first_name,
            last_name,
            email,
            phone_number,
            package,
            pax,
            inquiry_date,
            status
        FROM inquiries
        ORDER BY inquiry_date DESC
    ");
    $stmt->execute();
    $inquiries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($inquiries);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>*/
