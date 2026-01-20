<?php
require_once 'auth.php';
require_once 'database.php';

header('Content-Type: application/json');

if (isset($_GET['activo_fijo'])) {
    $activo_fijo = $_GET['activo_fijo'];
    
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT e.*, s.nombre as sede_nombre, 
                     CONCAT(emp.first_Name, ' ', emp.first_LastName) as nombre_usuario
              FROM equipos e 
              LEFT JOIN sedes s ON e.sede_id = s.id
              LEFT JOIN employee emp ON e.usuario_asignado = emp.id
              WHERE e.activo_fijo = :activo_fijo";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':activo_fijo', $activo_fijo);
    $stmt->execute();
    
    $equipo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($equipo) {
        echo json_encode(['success' => true, 'equipo' => $equipo]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Equipo no encontrado']);
    }
} else {
    echo json_encode(['success' => false, 'message' => 'Activo fijo no especificado']);
}
?>