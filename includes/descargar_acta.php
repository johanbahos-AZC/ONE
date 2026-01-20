<?php
// includes/descargar_acta.php
require_once 'auth.php';
require_once 'functions.php';
require_once 'database.php';

// Activar logging
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', 'descargar_acta_debug.log');

function log_debug($message) {
    file_put_contents('descargar_acta_debug.log', date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

log_debug("=== DESCARGAR_ACTA INICIADO ===");
log_debug("GET: " . print_r($_GET, true));

try {
    $auth = new Auth();
    
    // Verificar sesión
    if (!$auth->isLoggedIn()) {
        throw new Exception("No autenticado");
    }

    if (!isset($_GET['hash'])) {
        throw new Exception("Hash no proporcionado");
    }

    $file_hash = $_GET['hash'];
    $user_id = $_SESSION['user_id'];
    
    log_debug("Solicitando archivo con hash: $file_hash, usuario: $user_id");

    $functions = new Functions();
    $result = $functions->descargarActa($file_hash, $user_id);
    
    if (!$result['success']) {
        throw new Exception($result['error']);
    }

    // Headers para descarga
    header('Content-Type: ' . $result['mime_type']);
    header('Content-Disposition: attachment; filename="' . $result['file_name'] . '"');
    header('Content-Length: ' . strlen($result['file_content']));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    
    // Limpiar buffers de salida
    if (ob_get_length()) {
        ob_clean();
    }
    
    echo $result['file_content'];
    exit;
    
} catch (Exception $e) {
    log_debug("ERROR: " . $e->getMessage());
    
    http_response_code(500);
    header('Content-Type: application/json');
    
    if (ob_get_length()) {
        ob_clean();
    }
    
    echo json_encode([
        'success' => false,
        'error' => 'Error al descargar el archivo: ' . $e->getMessage()
    ]);
    exit;
}
?>