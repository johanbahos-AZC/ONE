<?php
require_once 'database.php';

header('Content-Type: application/json');

if (isset($_GET['firma_id']) && !empty($_GET['firma_id'])) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $firma_id = $_GET['firma_id'];
    
    $query = "SELECT id, name FROM location WHERE id_firm = :firma_id ORDER BY name";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':firma_id', $firma_id);
    $stmt->execute();
    
    $locaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($locaciones);
} else {
    echo json_encode([]);
}
?>