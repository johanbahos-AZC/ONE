<?php
// record_log.php
// Versión corregida para coincidir con la estructura real de la tabla

require_once 'auth.php';
require_once 'functions.php';

// Configuración
date_default_timezone_set('America/Bogota');
header('Content-Type: application/json');

function custom_log($message) {
    file_put_contents('debug.log', "[" . date("Y-m-d H:i:s") . "] " . $message . "\n", FILE_APPEND);
}

try {
    $raw = file_get_contents("php://input");
    $data = json_decode($raw, true);
    
    if (!$data) {
        throw new Exception("Datos JSON inválidos");
    }

    $employeeIdentifier = trim($data['employeeId'] ?? '');
    $logType = trim($data['logType'] ?? '');
    $photoBase64 = trim($data['photoBase64'] ?? '');
    $location = trim($data['location'] ?? 'Ubicación no disponible');

    if (empty($employeeIdentifier) || empty($logType)) {
        throw new Exception('ID de empleado y tipo de registro son obligatorios.');
    }

    // Conexión a BD
    $database = new Database();
    $conn = $database->getConnection();

    custom_log("record_log.php: Buscando empleado con identificador: " . $employeeIdentifier);

    // Buscar el ID real del empleado usando CC o ID
    $stmt = $conn->prepare("
        SELECT id, CC, CONCAT(first_Name, ' ', first_LastName) as name 
        FROM employee 
        WHERE (CC = :identifier OR id = :identifier) AND role NOT IN ('retirado', 'candidato')
    ");
    $stmt->execute(['identifier' => $employeeIdentifier]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('Empleado no encontrado o no activo');
    }

    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    $employeeId = $employee['id'];
    $employeeCC = $employee['CC'];
    $employeeName = $employee['name'];

    custom_log("record_log.php: Empleado encontrado - ID: $employeeId, CC: $employeeCC, Nombre: $employeeName");

    // Manejar foto
    $photoUrl = null;
    if (!empty($photoBase64)) {
        $uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/time_logs/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileName = $logType . '_' . $employeeCC . '_' . date('Y-m-d_His') . '.png';
        $filePath = $uploadDir . $fileName;
        
        $imageData = base64_decode($photoBase64);
        if ($imageData && file_put_contents($filePath, $imageData)) {
            $photoUrl = '/uploads/time_logs/' . $fileName;
            custom_log("record_log.php: Foto guardada: $photoUrl");
        } else {
            custom_log("record_log.php: Error al guardar la foto");
        }
    }

    // ✅ CORRECCIÓN: Usar la estructura correcta de la tabla time_logs
    // Basado en tu CREATE TABLE, la tabla tiene: log_id, employee_id, log_type, log_time, photo_url, location
    // NO tiene created_at
    $stmt = $conn->prepare("
        INSERT INTO time_logs (employee_id, log_type, log_time, photo_url, location) 
        VALUES (:employeeId, :logType, NOW(), :photoUrl, :location)
    ");
    
    $params = [
        ':employeeId' => $employeeId,
        ':logType' => $logType,
        ':photoUrl' => $photoUrl,
        ':location' => $location
    ];

    custom_log("record_log.php: Insertando registro con parámetros: " . json_encode($params));
    
    $success = $stmt->execute($params);

    if ($success) {
        $logId = $conn->lastInsertId();
        custom_log("record_log.php: Registro exitoso. Log ID: $logId");
        
        echo json_encode([
            'success' => true,
            'message' => 'Registro guardado exitosamente',
            'photoUrl' => $photoUrl,
            'employeeName' => $employeeName,
            'logId' => $logId
        ]);
    } else {
        $errorInfo = $stmt->errorInfo();
        custom_log("record_log.php: Error en execute: " . json_encode($errorInfo));
        throw new Exception('Error al guardar en base de datos: ' . ($errorInfo[2] ?? 'Error desconocido'));
    }

} catch (Exception $e) {
    custom_log("record_log.php: ERROR - " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}
exit;