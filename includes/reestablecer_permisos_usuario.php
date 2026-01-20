<?php
// Activar reporte de errores para depuración
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Incluir archivos necesarios
require_once 'database.php';
require_once 'validar_permiso.php';

// Configurar cabeceras
header('Content-Type: application/json');

try {
    // Verificar que se recibió el ID del usuario
    if (!isset($_POST['usuario_id']) || empty($_POST['usuario_id'])) {
        throw new Exception('ID de usuario no proporcionado');
    }
    
    $usuario_id = $_POST['usuario_id'];
    
    // Verificar que el ID sea un número válido
    if (!is_numeric($usuario_id)) {
        throw new Exception('ID de usuario inválido');
    }
    
    // Iniciar sesión si no está iniciada
    if (session_status() == PHP_SESSION_NONE) {
        session_start();
    }
    
    // Verificar que el usuario esté logueado
    if (!isset($_SESSION['user_id'])) {
        throw new Exception('Usuario no autenticado');
    }
    
    // Conexión a la base de datos
    $database = new Database();
    $conn = $database->getConnection();
    
    // Verificar conexión
    if (!$conn) {
        throw new Exception('Error de conexión a la base de datos');
    }
    
    // Obtener información del usuario que realiza la acción
    $query_usuario = "SELECT id, role, position_id FROM employee WHERE id = ?";
    $stmt_usuario = $conn->prepare($query_usuario);
    
    if (!$stmt_usuario) {
        throw new Exception('Error al preparar consulta de usuario');
    }
    
    $stmt_usuario->execute([$_SESSION['user_id']]);
    $usuario_actual = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
    
    if (!$usuario_actual) {
        throw new Exception('Usuario actual no encontrado');
    }
    
    // Verificar permisos para gestionar accesos
    if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'usuarios', 'gestionar_accesos')) {
        throw new Exception('No tienes permisos para realizar esta acción');
    }
    
    // Verificar que el usuario objetivo existe
    $query_verificar = "SELECT id FROM employee WHERE id = ?";
    $stmt_verificar = $conn->prepare($query_verificar);
    $stmt_verificar->execute([$usuario_id]);
    
    if ($stmt_verificar->rowCount() == 0) {
        throw new Exception('El usuario especificado no existe');
    }
    
    // Eliminar todos los permisos específicos del usuario
    $query = "DELETE FROM permisos_usuarios WHERE usuario_id = ?";
    $stmt = $conn->prepare($query);
    
    if (!$stmt) {
        throw new Exception('Error al preparar consulta de eliminación');
    }
    
    $resultado = $stmt->execute([$usuario_id]);
    
    if (!$resultado) {
        throw new Exception('Error al eliminar permisos específicos');
    }
    
    // Respuesta exitosa
    echo json_encode([
        'success' => true,
        'message' => 'Permisos reestablecidos correctamente. Ahora el usuario heredará los permisos de su rol y cargo.',
        'affected_rows' => $stmt->rowCount()
    ]);
    
} catch (Exception $e) {
    // Registrar error en log
    error_log("Error en reestablecer_permisos_usuario.php: " . $e->getMessage());
    
    // Respuesta de error
    http_response_code(500); // Establecer código de error HTTP
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]
    ]);
} catch (Error $e) {
    // Capturar errores fatales de PHP 7+
    error_log("Error fatal en reestablecer_permisos_usuario.php: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Error interno del servidor',
        'debug_info' => [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'message' => $e->getMessage()
        ]
    ]);
}
?>