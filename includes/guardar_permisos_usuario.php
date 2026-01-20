<?php
require_once 'database.php';
require_once 'auth.php';

header('Content-Type: application/json');

try {
    $auth = new Auth();
    $auth->redirectIfNotLoggedIn();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['usuario_id']) || empty($data['usuario_id'])) {
        throw new Exception('ID de usuario no especificado');
    }
    
    if (!isset($data['permisos']) || !is_array($data['permisos'])) {
        throw new Exception('Permisos no especificados');
    }
    
    $usuario_id = $data['usuario_id'];
    $permisos = $data['permisos'];
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Iniciar transacción
    $conn->beginTransaction();
    
    try {
        // Eliminar todos los permisos específicos del usuario
        $query_eliminar = "DELETE FROM permisos_usuarios WHERE usuario_id = ?";
        $stmt_eliminar = $conn->prepare($query_eliminar);
        $stmt_eliminar->execute([$usuario_id]);
        
        // Insertar los nuevos permisos específicos del usuario
        foreach ($permisos as $permiso) {
            // Obtener IDs de recurso y acción
            $query_ids = "SELECT r.id as recurso_id, a.id as accion_id 
                          FROM recursos r 
                          JOIN acciones a ON r.id = a.recurso_id 
                          WHERE r.nombre = ? AND a.nombre = ?";
            $stmt_ids = $conn->prepare($query_ids);
            $stmt_ids->execute([$permiso['recurso'], $permiso['accion']]);
            $ids = $stmt_ids->fetch(PDO::FETCH_ASSOC);
            
            if ($ids) {
                // Insertar permiso específico
                $query_insertar = "INSERT INTO permisos_usuarios (usuario_id, recurso_id, accion_id, permitido) 
                                   VALUES (?, ?, ?, ?)";
                $stmt_insertar = $conn->prepare($query_insertar);
                $stmt_insertar->execute([
                    $usuario_id, 
                    $ids['recurso_id'], 
                    $ids['accion_id'], 
                    $permiso['permitido']
                ]);
            }
        }
        
        // Confirmar transacción
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'Permisos guardados correctamente'
        ]);
        
    } catch (Exception $e) {
        // Revertir transacción en caso de error
        $conn->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>