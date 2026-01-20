<?php
// Versión robusta de debug/entrega JSON

// Configuración logging
$logfile = __DIR__ . '/obtener_archivos_debug.log';
function log_debug($message) {
    global $logfile;
    file_put_contents($logfile, date('Y-m-d H:i:s') . ' - ' . $message . "\n", FILE_APPEND);
}

// Evitar mostrar errores al usuario pero sí loguearlos
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', $logfile);

// FORZAR sesión
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

log_debug("=== OBTENER_ARCHIVOS INICIADO ===");
log_debug("REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'N/A'));
log_debug("GET: " . print_r($_GET, true));
log_debug("COOKIES: " . print_r($_COOKIE, true));
if (function_exists('getallheaders')) {
    log_debug("HEADERS: " . print_r(getallheaders(), true));
}

// Iniciar buffer para capturar cualquier salida previa de includes
ob_start();

try {
    require_once __DIR__ . '/auth.php';
    require_once __DIR__ . '/functions.php';
    require_once __DIR__ . '/database.php';

    // Logueo de cualquier texto que los includes hayan impreso
    $preIncludesOutput = ob_get_contents();
    $lenPre = strlen($preIncludesOutput);
    if ($lenPre > 0) {
        log_debug("Output generado por includes (len={$lenPre}): " . substr($preIncludesOutput, 0, 2000));
    } else {
        log_debug("No hubo output por includes (len=0).");
    }

    // Limpiar buffer pero conservar niveles abiertos
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Cabecera JSON
    header('Content-Type: application/json; charset=utf-8');

    // Autenticación
    $auth = new Auth();
    log_debug("isLoggedIn: " . ($auth->isLoggedIn() ? 'TRUE' : 'FALSE'));
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        $resp = ['error' => 'No autenticado', 'archivos' => []];
        $json = json_encode($resp, JSON_UNESCAPED_UNICODE);
        log_debug("RESP (no autenticado) len=" . strlen($json));
        echo $json;
        exit;
    }

    // Obtener user_id
    if (!isset($_GET['user_id'])) {
        http_response_code(400);
        $resp = ['error' => 'ID de usuario no proporcionado', 'archivos' => []];
        $json = json_encode($resp, JSON_UNESCAPED_UNICODE);
        echo $json;
        exit;
    }

    $user_id = (int) $_GET['user_id'];
    $current_user_id = $_SESSION['user_id'] ?? null;
    $current_user_role = $_SESSION['user_role'] ?? null;
    log_debug("Usuario actual (session): user_id={$current_user_id}, role={$current_user_role}");
    log_debug("Solicitando archivos para usuario: {$user_id}");

    // Permisos
    $allowed_roles = ['administrador', 'it', 'talento_humano'];
    if ($user_id != $current_user_id && !in_array($current_user_role, $allowed_roles)) {
        http_response_code(403);
        $resp = ['error' => 'No tiene permisos para ver estos archivos', 'archivos' => []];
        $json = json_encode($resp, JSON_UNESCAPED_UNICODE);
        log_debug("RESP (no permisos) len=" . strlen($json));
        echo $json;
        exit;
    }

    // Obtener archivos desde Functions
    $functions = new Functions();
	$archivos = $functions->obtenerArchivosPorUsuario($user_id);
	if (!is_array($archivos)) $archivos = [];
	
	// ⚠️ Eliminar campos binarios que rompen el JSON
	foreach ($archivos as &$archivo) {
	    if (isset($archivo['file_content'])) {
	        unset($archivo['file_content']);
	    }
	}
	unset($archivo);

    log_debug("Archivos encontrados: " . count($archivos));
    // Log muestra parcial de array
    if (count($archivos) > 0) {
        log_debug("Sample archivos: " . substr(print_r(array_slice($archivos, 0, 5), true), 0, 2000));
    }

    // Preparar JSON
    $json = json_encode($archivos, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        $jerr = json_last_error_msg();
        log_debug("ERROR JSON encode: " . $jerr);
        // Devolver error JSON
        http_response_code(500);
        $errResp = ['error' => 'Error codificando JSON: ' . $jerr, 'archivos' => []];
        echo json_encode($errResp, JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Finalmente enviar JSON limpio
    // (Aseguramos que no quede ningún buffer.)
    while (ob_get_level()) {
        ob_end_clean();
    }

    // Log longitud que vamos a enviar
    log_debug("Enviar JSON len=" . strlen($json));
    echo $json;
    // Asegurar que se envíe
    flush();
    exit;

} catch (Throwable $e) {
    // Limpiar buffers
    while (ob_get_level()) {
        ob_end_clean();
    }
    $msg = $e->getMessage();
    log_debug("EXCEPCION: " . $msg . ' -- ' . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => $msg, 'archivos' => []], JSON_UNESCAPED_UNICODE);
    exit;
}