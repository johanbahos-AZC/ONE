<?php
require_once 'auth.php';
require_once 'functions.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->redirectIfNotLoggedIn();
    
    // Verificar que el usuario tenga permisos
    $allowed_roles = ['administrador', 'it', 'nomina', 'talento_humano'];
    if (!in_array($_SESSION['user_role'], $allowed_roles)) {
        throw new Exception('No tiene permisos para ver esta información');
    }
    
    if (!isset($_GET['user_id']) || empty($_GET['user_id'])) {
        throw new Exception('ID de usuario no especificado');
    }
    
    $user_id = intval($_GET['user_id']);
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Primero obtener el CC del usuario
    $query_cc = "SELECT CC FROM employee WHERE id = ?";
    $stmt_cc = $conn->prepare($query_cc);
    $stmt_cc->execute([$user_id]);
    $user_data = $stmt_cc->fetch(PDO::FETCH_ASSOC);
    
    if (!$user_data) {
        throw new Exception('Usuario no encontrado');
    }
    
    $user_cc = $user_data['CC'];
    
    // Obtener tickets del usuario usando su CC
    $functions = new Functions();
    $tickets = $functions->obtenerTicketsPorEmpleado($user_cc);
    
    echo json_encode($tickets ?: []);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
?>