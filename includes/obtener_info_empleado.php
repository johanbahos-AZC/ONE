<?php
require_once 'auth.php';
require_once 'functions.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->redirectIfNotLoggedIn();
    
    if (!isset($_GET['cc']) || empty($_GET['cc'])) {
        throw new Exception('Documento del empleado no especificado');
    }
    
    $empleado_cc = $_GET['cc'];
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Consulta modificada para obtener el nombre del cargo desde la tabla cargos
    $query = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name, c.nombre as position_nombre
              FROM employee e 
              LEFT JOIN sedes s ON e.sede_id = s.id
              LEFT JOIN firm f ON e.id_firm = f.id
              LEFT JOIN cargos c ON e.position_id = c.id
              WHERE e.CC = :cc";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':cc', $empleado_cc);
    $stmt->execute();
    
    $empleado = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($empleado) {
        echo json_encode($empleado);
    } else {
        echo json_encode(['error' => 'Empleado no encontrado']);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>