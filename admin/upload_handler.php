<?php
// upload_handler.php - VERSIÓN SIMPLIFICADA Y ROBUSTA

// HEADERS PRIMERO - antes de cualquier output
header('Content-Type: application/json; charset=utf-8');

// Configurar manejo de errores
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Función para enviar respuesta JSON consistente
function sendJsonResponse($success, $message, $error = null) {
    $response = [
        'success' => $success,
        'message' => $message
    ];
    
    if (!$success && $error) {
        $response['error'] = $error;
    }
    
    echo json_encode($response);
    exit;
}

try {
    // Verificar método
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Método no permitido', 'Se esperaba POST');
    }

    // Iniciar sesión
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Verificar que el usuario esté logueado
    if (!isset($_SESSION['user_id'])) {
        sendJsonResponse(false, 'No autenticado', 'Usuario no logueado');
    }

    // Incluir archivos necesarios
    $base_dir = dirname(__DIR__);
    require_once $base_dir . '/includes/auth.php';
    require_once $base_dir . '/includes/functions.php';

    // Procesar datos del formulario
    $contenido = trim($_POST['contenido'] ?? '');
    $archivo_nombre = null;
    $tipo = 'texto';

    // Procesar archivo si existe
    if (isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $archivo = $_FILES['archivo'];
        
        // Validaciones básicas del archivo
        if ($archivo['size'] > 10 * 1024 * 1024) {
            sendJsonResponse(false, 'Archivo demasiado grande', 'El archivo no debe superar los 10MB');
        }
        
        // Mover archivo directamente sin procesamiento complejo
        $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/publicaciones/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }
        
        $extension = pathinfo($archivo['name'], PATHINFO_EXTENSION);
        $archivo_nombre = 'publicacion_' . time() . '_' . uniqid() . '.' . $extension;
        $file_path = $upload_dir . $archivo_nombre;
        
        if (!move_uploaded_file($archivo['tmp_name'], $file_path)) {
            sendJsonResponse(false, 'Error al subir archivo', 'No se pudo guardar el archivo');
        }
        
        // Determinar tipo
        $tipo_archivo = (strpos($archivo['type'], 'video/') !== false) ? 'video' : 'imagen';
        $tipo = $tipo_archivo;
        
        if ($contenido) {
            $tipo = 'mixto';
        }
    } elseif (isset($_FILES['archivo']) && $_FILES['archivo']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Error en la subida
        $error_code = $_FILES['archivo']['error'];
        sendJsonResponse(false, 'Error en la subida del archivo', "Código de error: $error_code");
    }

    // Validar que haya contenido
    if (empty($contenido) && !$archivo_nombre) {
        sendJsonResponse(false, 'Contenido requerido', 'La publicación debe contener texto o un archivo multimedia');
    }

    // Conectar a la base de datos
    $database = new Database();
    $conn = $database->getConnection();

    // Insertar en la base de datos
    $query = "INSERT INTO publicaciones (usuario_id, contenido, imagen, tipo, creado_en) 
              VALUES (:usuario_id, :contenido, :imagen, :tipo, NOW())";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':usuario_id', $_SESSION['user_id']);
    $stmt->bindParam(':contenido', $contenido);
    $stmt->bindParam(':imagen', $archivo_nombre);
    $stmt->bindParam(':tipo', $tipo);

    if ($stmt->execute()) {
        sendJsonResponse(true, 'Publicación creada correctamente');
    } else {
        sendJsonResponse(false, 'Error en la base de datos', 'No se pudo guardar la publicación');
    }

} catch (Exception $e) {
    // Capturar cualquier excepción no manejada
    error_log("Error en upload_handler: " . $e->getMessage());
    sendJsonResponse(false, 'Error del servidor', $e->getMessage());
}

// Asegurar que no haya output adicional
exit;
?>