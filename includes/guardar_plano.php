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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'planos', 'editar_plano')) {
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para editar planos']);
    exit;
}

// Procesar la solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['guardar_plano'])) {
        $piso_id = $_POST['piso_id'];
        $mesas = json_decode($_POST['mesas'], true);
        $elementos = json_decode($_POST['elementos'], true);
        
        if (!$piso_id || !$mesas || !$elementos) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
            exit;
        }
        
        // Inicializar el gestor de planos
        $planoManager = new PlanoSedeManager($database);
        
	// Procesar mesas eliminadas primero
	if (isset($_POST['mesas_eliminadas'])) {
	    $mesas_eliminadas = json_decode($_POST['mesas_eliminadas'], true);
	    
	    foreach ($mesas_eliminadas as $mesa) {
	        // Verificar que el ID sea numérico (no temporal)
	        if (is_numeric($mesa['id'])) {
	            try {
	                // Eliminar las asignaciones de esta mesa primero (por la FK)
	                $query = "DELETE FROM asignaciones_mesa WHERE mesa_id = :mesa_id";
	                $stmt = $conn->prepare($query);
	                $stmt->bindParam(':mesa_id', $mesa['id']);
	                $stmt->execute();
	                
	                // Ahora eliminar físicamente la mesa
	                $query = "DELETE FROM mesas WHERE id = :id";
	                $stmt = $conn->prepare($query);
	                $stmt->bindParam(':id', $mesa['id']);
	                $stmt->execute();
	                
	                error_log("Mesa eliminada físicamente: ID " . $mesa['id']);
	            } catch (PDOException $e) {
	                error_log("Error al eliminar mesa {$mesa['id']}: " . $e->getMessage());
	                // Continuar con las demás mesas aunque falle una
	            }
	        }
	    }
	}
	
	// Procesar elementos eliminados
	if (isset($_POST['elementos_eliminados'])) {
	    $elementos_eliminados = json_decode($_POST['elementos_eliminados'], true);
	    
	    foreach ($elementos_eliminados as $elemento) {
	        // Verificar que el ID sea numérico (no temporal)
	        if (is_numeric($elemento['id'])) {
	            try {
	                // Eliminar físicamente el elemento del plano
	                $query = "DELETE FROM elementos_plano WHERE id = :id";
	                $stmt = $conn->prepare($query);
	                $stmt->bindParam(':id', $elemento['id']);
	                $stmt->execute();
	                
	                error_log("Elemento eliminado físicamente: ID " . $elemento['id']);
	            } catch (PDOException $e) {
	                error_log("Error al eliminar elemento {$elemento['id']}: " . $e->getMessage());
	                // Continuar con los demás elementos aunque falle uno
	            }
	        }
	    }
	}
        
        // Guardar mesas
        $resultado_mesas = $planoManager->guardarMesas($mesas, $piso_id);
        
        if (!$resultado_mesas['success']) {
            echo json_encode(['success' => false, 'error' => $resultado_mesas['error']]);
            exit;
        }
        
        // Guardar elementos del plano (solo muros)
        $resultado_elementos = $planoManager->guardarElementosPlano($elementos, $piso_id);
        
        if (!$resultado_elementos['success']) {
            echo json_encode(['success' => false, 'error' => $resultado_elementos['error']]);
            exit;
        }
        
        // Devolver los IDs nuevos
        echo json_encode([
            'success' => true,
            'ids_mesas' => $resultado_mesas['ids_mesas'] ?? [],
            'ids_elementos' => $resultado_elementos['ids_elementos'] ?? []
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Solicitud no válida']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud no válida']);
}
?>