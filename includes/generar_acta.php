<?php
// includes/generar_acta.php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Activar logging de errores
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '../includes/generar_acta_debug.log');

// Headers primero para JSON
header('Content-Type: application/json; charset=utf-8');

// Limpiar buffer de salida
if (ob_get_length()) {
    ob_clean();
}

function log_debug($message) {
    file_put_contents('../includes/generar_acta_debug.log', date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

log_debug("=== GENERAR_ACTA.PH INICIADO ===");
log_debug("POST: " . print_r($_POST, true));
log_debug("SESSION: " . print_r($_SESSION, true));

try {
    $auth = new Auth();
    
    // Verificar sesión
    if (!$auth->isLoggedIn()) {
        throw new Exception("No autenticado");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $user_id = $_POST['user_id'] ?? '';
        $activo_fijo = $_POST['activo_fijo'] ?? '';
        $admin_id = $_SESSION['user_id'];
        
        log_debug("Datos recibidos - user_id: $user_id, activo_fijo: $activo_fijo, admin_id: $admin_id");
        
        if (empty($user_id) || empty($activo_fijo)) {
            throw new Exception("Datos incompletos");
        }
        
        $functions = new Functions();
        
        // Llamar a la función correcta - asegúrate de que el nombre coincida
        $result = $functions->generarActaActivoFijo($user_id, $activo_fijo, $admin_id);
        
        log_debug("Resultado: " . print_r($result, true));
        
        // Limpiar buffer antes de enviar JSON
        if (ob_get_length()) {
            ob_clean();
        }
        
        echo json_encode($result);
        exit;
        
    } else {
        throw new Exception("Método no permitido");
    }
} catch (Exception $e) {
    log_debug("ERROR: " . $e->getMessage());
    
    // Limpiar buffer antes de enviar error
    if (ob_get_length()) {
        ob_clean();
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}

log_debug("=== GENERAR_ACTA.PH FINALIZADO ===");
?>