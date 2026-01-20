<?php
require_once 'database.php';

header('Content-Type: application/json');

// Verificar si el usuario está autenticado
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Verificar permisos
 $database = new Database();
 $conn = $database->getConnection();

 $query_usuario = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name 
                 FROM employee e 
                 LEFT JOIN sedes s ON e.sede_id = s.id
                 LEFT JOIN firm f ON e.id_firm = f.id 
                 WHERE e.id = ?";
 $stmt_usuario = $conn->prepare($query_usuario);
 $stmt_usuario->execute([$_SESSION['user_id']]);
 $usuario_actual = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

require_once 'validar_permiso.php';
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'planos', 'ver')) {
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para ver planos']);
    exit;
}

// Procesar la solicitud
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_GET['id'])) {
        // Obtener un tipo de mesa específico
        $tipoId = $_GET['id'];
        
        $query = "SELECT * FROM tipos_mesa WHERE id = :id AND activo = 1";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $tipoId);
        $stmt->execute();
        $tipo = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($tipo) {
            echo json_encode(['success' => true, 'tipo' => $tipo]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Tipo de mesa no encontrado']);
        }
    } else {
        // Obtener todos los tipos de mesa
        $query = "SELECT * FROM tipos_mesa WHERE activo = 1 ORDER BY nombre";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        $tipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode(['success' => true, 'tipos' => $tipos]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud no válida']);
}
?>