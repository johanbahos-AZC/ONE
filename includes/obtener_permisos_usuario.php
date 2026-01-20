<?php
require_once 'database.php';
require_once 'auth.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->redirectIfNotLoggedIn();
    
    if (!isset($_GET['usuario_id']) || empty($_GET['usuario_id'])) {
        throw new Exception('ID de usuario no especificado');
    }
    
    $usuario_id = $_GET['usuario_id'];
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener información del usuario
    $query_usuario = "SELECT role, position_id FROM employee WHERE id = ?";
    $stmt_usuario = $conn->prepare($query_usuario);
    $stmt_usuario->execute([$usuario_id]);
    $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario) {
        throw new Exception('Usuario no encontrado');
    }
    
    // Obtener todos los recursos y acciones
    $query_recursos = "SELECT r.id as recurso_id, r.nombre as recurso_nombre 
                        FROM recursos r 
                        ORDER BY r.categoria, r.nombre";
    $stmt_recursos = $conn->prepare($query_recursos);
    $stmt_recursos->execute();
    $recursos = $stmt_recursos->fetchAll(PDO::FETCH_ASSOC);
    
    $permisos = [];
    
    foreach ($recursos as $recurso) {
        // Obtener acciones para este recurso
        $query_acciones = "SELECT a.id as accion_id, a.nombre as accion_nombre 
                           FROM acciones a 
                           WHERE a.recurso_id = ? 
                           ORDER BY a.nombre";
        $stmt_acciones = $conn->prepare($query_acciones);
        $stmt_acciones->execute([$recurso['recurso_id']]);
        $acciones = $stmt_acciones->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($acciones as $accion) {
            // Verificar si hay un permiso específico para el usuario
            $query_usuario_permiso = "SELECT permitido FROM permisos_usuarios 
                                     WHERE usuario_id = ? AND recurso_id = ? AND accion_id = ?";
            $stmt_usuario_permiso = $conn->prepare($query_usuario_permiso);
            $stmt_usuario_permiso->execute([$usuario_id, $recurso['recurso_id'], $accion['accion_id']]);
            $permiso_usuario = $stmt_usuario_permiso->fetch(PDO::FETCH_ASSOC);
            
            if ($permiso_usuario) {
                // Hay un permiso específico para el usuario
                $permisos[] = [
                    'recurso' => $recurso['recurso_nombre'],
                    'accion' => $accion['accion_nombre'],
                    'permitido' => (bool)$permiso_usuario['permitido'],
                    'especifico' => true,
                    'cargo' => false,
                    'rol' => false
                ];
            } else {
                // Verificar si hay un permiso para el cargo
                $permitido = false;
                $es_cargo = false;
                
                if ($usuario['position_id']) {
                    $query_cargo_permiso = "SELECT permitido FROM permisos_cargos 
                                          WHERE cargo_id = ? AND recurso_id = ? AND accion_id = ?";
                    $stmt_cargo_permiso = $conn->prepare($query_cargo_permiso);
                    $stmt_cargo_permiso->execute([$usuario['position_id'], $recurso['recurso_id'], $accion['accion_id']]);
                    $permiso_cargo = $stmt_cargo_permiso->fetch(PDO::FETCH_ASSOC);
                    
                    if ($permiso_cargo) {
                        $permitido = (bool)$permiso_cargo['permitido'];
                        $es_cargo = true;
                    }
                }
                
                // Si no hay permiso de cargo, verificar el rol
                if (!$es_cargo) {
                    $query_rol_permiso = "SELECT permitido FROM permisos_roles 
                                        WHERE rol = ? AND recurso_id = ? AND accion_id = ?";
                    $stmt_rol_permiso = $conn->prepare($query_rol_permiso);
                    $stmt_rol_permiso->execute([$usuario['role'], $recurso['recurso_id'], $accion['accion_id']]);
                    $permiso_rol = $stmt_rol_permiso->fetch(PDO::FETCH_ASSOC);
                    
                    if ($permiso_rol) {
                        $permitido = (bool)$permiso_rol['permitido'];
                    }
                }
                
                $permisos[] = [
                    'recurso' => $recurso['recurso_nombre'],
                    'accion' => $accion['accion_nombre'],
                    'permitido' => $permitido,
                    'especifico' => false,
                    'cargo' => $es_cargo,
                    'rol' => !$es_cargo
                ];
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'permisos' => $permisos
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>