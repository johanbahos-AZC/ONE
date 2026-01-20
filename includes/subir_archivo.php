<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

// Limpiar buffer
if (ob_get_length()) {
    ob_clean();
}

header('Content-Type: application/json; charset=utf-8');

try {
    $auth = new Auth();
    
    // Verificar autenticación
    if (!$auth->isLoggedIn()) {
        throw new Exception('No autenticado');
    }

    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['user_id']) && isset($_FILES['archivo'])) {
        $user_id = $_POST['user_id'];
        $file_type = $_POST['file_type'] ?? 'otros';
        $description = $_POST['description'] ?? '';
        
        // Validar archivo
        if ($_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Error en la subida del archivo: ' . $_FILES['archivo']['error']);
        }
        
        // Validar tamaño (10MB máximo)
        if ($_FILES['archivo']['size'] > 10485760) {
            throw new Exception('El archivo es demasiado grande (máx. 10MB)');
        }
        
        // Validar tipo de archivo
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'application/pdf', 
                         'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                         'text/plain'];
        $file_type_mime = $_FILES['archivo']['type'];
        
        if (!in_array($file_type_mime, $allowed_types)) {
            throw new Exception('Tipo de archivo no permitido: ' . $file_type_mime);
        }
        
        // Leer contenido del archivo
        $file_content = file_get_contents($_FILES['archivo']['tmp_name']);
        if ($file_content === false) {
            throw new Exception('Error al leer el archivo');
        }
        
        $file_name = $_FILES['archivo']['name'];
        $file_hash = hash('sha256', $file_content);
        $file_size = $_FILES['archivo']['size'];
        
        $functions = new Functions();
        
        // Guardar en base de datos
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "INSERT INTO files (id_employee, file_type, file_name, file_path, file_content, file_hash, file_size, mime_type, description, signed, uploaded_by, created_at) 
                 VALUES (:id_employee, :file_type, :file_name, '', :file_content, :file_hash, :file_size, 
                 :mime_type, :description, 0, :uploaded_by, NOW())";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id_employee', $user_id);
        $stmt->bindParam(':file_type', $file_type);
        $stmt->bindParam(':file_name', $file_name);
        $stmt->bindParam(':file_content', $file_content, PDO::PARAM_LOB);
        $stmt->bindParam(':file_hash', $file_hash);
        $stmt->bindParam(':file_size', $file_size);
        $stmt->bindParam(':mime_type', $file_type_mime);
        $stmt->bindParam(':description', $description);
        $stmt->bindParam(':uploaded_by', $_SESSION['user_id']);
        
        if ($stmt->execute()) {
            // Registrar en historial
            $functions->registrarHistorial(
	    null,           // ticket_id
	    $usuario_id,    // usuario_id
	    null,           // item_id (debe ser null si no aplica)
	    'subir_archivo', // accion
	    1,              // cantidad
	    "Archivo subido: " . $fileName, // notas
	    $_SESSION['user_id'] // admin_id (quién realiza la acción)
	);
            
            echo json_encode([
                'success' => true,
                'message' => 'Archivo subido correctamente',
                'file_name' => $file_name,
                'file_hash' => $file_hash
            ]);
            exit;
        } else {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Error al guardar en base de datos: " . $errorInfo[2]);
        }
        
    } else {
        throw new Exception('Solicitud inválida. Asegúrate de seleccionar un archivo.');
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
    exit;
}
?>