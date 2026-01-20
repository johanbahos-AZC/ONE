<?php
// includes/get_pending_tickets.php
require_once 'database.php';

header('Content-Type: application/json');

try {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT COUNT(*) as count FROM tickets WHERE estado = 'pendiente'";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['count' => $result['count']]);
    
} catch (Exception $e) {
    echo json_encode(['count' => 0]);
}
?>