<?php
require_once 'database.php';
require_once 'PlanoSedeManager.php';

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
    $sede_id = $_GET['sede_id'];
    
    if (!$sede_id) {
        echo json_encode(['success' => false, 'error' => 'ID de sede no proporcionado']);
        exit;
    }
    
    // Inicializar el gestor de planos
    $planoManager = new PlanoSedeManager($database);
    
    // Obtener empleados de la sede
    $empleados = $planoManager->obtenerEmpleadosPorSede($sede_id);
    
    // Formatear datos para el frontend
    $empleados_formateados = [];
    foreach ($empleados as $empleado) {
        $empleados_formateados[] = [
            'id' => $empleado['id'],
            'first_Name' => $empleado['first_Name'],
            'first_LastName' => $empleado['first_LastName'],
            'nombre_completo' => $empleado['nombre_completo'],
            'position' => $empleado['position_id'],
            'mesa_numero' => $empleado['mesa_numero']
        ];
    }
    
    echo json_encode(['success' => true, 'empleados' => $empleados_formateados]);
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud no válida']);
}
?>