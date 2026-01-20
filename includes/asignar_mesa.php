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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'planos', 'asignar_mesas')) {
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para asignar mesas']);
    exit;
}

// Inicializar el gestor de planos
 $planoManager = new PlanoSedeManager($database);

// Procesar la solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['asignar_empleado'])) {
        // Asignar empleado a mesa
        $mesa_id = $_POST['mesa_id'];
        $empleado_id = $_POST['empleado_id'];
        
        if (!$mesa_id || !$empleado_id) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
            exit;
        }
        
        // Verificar que la mesa exista
        $query = "SELECT * FROM mesas WHERE id = :id AND activo = 1";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $mesa_id);
        $stmt->execute();
        $mesa = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mesa) {
            echo json_encode(['success' => false, 'error' => 'La mesa especificada no existe']);
            exit;
        }
        
        // Verificar que el empleado exista
        $query = "SELECT * FROM employee WHERE id = :id AND role != 'retirado'";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $empleado_id);
        $stmt->execute();
        $empleado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$empleado) {
            echo json_encode(['success' => false, 'error' => 'El empleado especificado no existe']);
            exit;
        }
        
        // Verificar que el empleado pertenezca a la misma sede que la mesa
        $query = "SELECT p.sede_id 
                  FROM mesas m 
                  JOIN pisos_sede p ON m.piso_id = p.id 
                  WHERE m.id = :mesa_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':mesa_id', $mesa_id);
        $stmt->execute();
        $mesa_sede = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($mesa_sede['sede_id'] != $empleado['sede_id']) {
            echo json_encode(['success' => false, 'error' => 'El empleado no pertene a la misma sede que la mesa']);
            exit;
        }
        
        $resultado = $planoManager->asignarEmpleadoAMesa($mesa_id, $empleado_id);
        echo json_encode($resultado);
    } else if (isset($_POST['desasignar_empleado'])) {
        // Desasignar empleado de mesa
        $mesa_id = $_POST['mesa_id'];
        
        if (!$mesa_id) {
            echo json_encode(['success' => false, 'error' => 'ID de mesa no proporcionado']);
            exit;
        }
        
        // Verificar que la mesa exista
        $query = "SELECT * FROM mesas WHERE id = :id AND activo = 1";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $mesa_id);
        $stmt->execute();
        $mesa = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$mesa) {
            echo json_encode(['success' => false, 'error' => 'La mesa especificada no existe']);
            exit;
        }
        
        $resultado = $planoManager->desasignarEmpleadoDeMesa($mesa_id);
        echo json_encode($resultado);
    } else {
        echo json_encode(['success' => false, 'error' => 'Acción no especificada']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud no válida']);
}
?>