<?php
// check_employee.php
// Versión corregida usando la misma conexión que usuarios.php

require_once 'auth.php';
require_once 'functions.php';

function custom_log($message) {
    file_put_contents('debug.log', "[" . date("Y-m-d H:i:s") . "] " . $message . "\n", FILE_APPEND);
}

try {
    custom_log("check_employee.php: Solicitud recibida. Método: " . $_SERVER['REQUEST_METHOD']);

    $response = ['success' => false, 'message' => ''];

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $raw = file_get_contents("php://input");
        custom_log("check_employee.php: RAW input: " . $raw);

        $data = json_decode($raw, true);

        if ($data === null) {
            custom_log("check_employee.php: ERROR - JSON inválido");
            throw new Exception("Datos recibidos inválidos (JSON)");
        }

        $employeeId = trim($data['employeeId'] ?? '');
        custom_log("check_employee.php: Empleado recibido: " . $employeeId);

        if (empty($employeeId)) {
            throw new Exception("ID de empleado es obligatorio.");
        }

        // USAR EXACTAMENTE LA MISMA CONEXIÓN QUE usuarios.php
        $database = new Database();
        $conn = $database->getConnection();

        // Consulta adaptada a la estructura de la tabla employee
        $stmt = $conn->prepare("
            SELECT 
                id, 
                CC as document,
                CONCAT(first_Name, ' ', first_LastName) as name,
                role,
                sede_id,
                position,
                activo_fijo,
                photo
            FROM employee 
            WHERE CC = :cc OR id = :id
        ");
        
        $stmt->execute([
            'cc' => $employeeId,
            'id' => $employeeId
        ]);

        if ($stmt->rowCount() > 0) {
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Verificar si el empleado está activo (no retirado ni candidato)
            if ($employee['role'] === 'retirado') {
                $response['success'] = false;
                $response['message'] = 'Empleado retirado. No puede registrar asistencia.';
                custom_log("check_employee.php: Empleado RETIRADO: " . $employeeId);
            } elseif ($employee['role'] === 'candidato') {
                $response['success'] = false;
                $response['message'] = 'Candidato. No puede registrar asistencia hasta ser activado.';
                custom_log("check_employee.php: Empleado CANDIDATO: " . $employeeId);
            } else {
                $response['success'] = true;
                $response['message'] = 'Empleado encontrado y activo.';
                $response['employee'] = [
                    'id' => $employee['id'],
                    'document' => $employee['document'],
                    'name' => $employee['name'],
                    'role' => $employee['role'],
                    'position' => $employee['position'],
                    'sede_id' => $employee['sede_id'],
                    'activo_fijo' => $employee['activo_fijo'],
                    'photo' => $employee['photo']
                ];
                custom_log("check_employee.php: Empleado encontrado: " . $employeeId . " - Nombre: " . $employee['name']);
            }
        } else {
            $response['success'] = false;
            $response['message'] = 'Empleado no encontrado en el sistema.';
            custom_log("check_employee.php: Empleado NO encontrado: " . $employeeId);
        }

    } else {
        throw new Exception("Método de solicitud no permitido: " . $_SERVER['REQUEST_METHOD']);
    }
} catch (Exception $e) {
    $response = [
        "success" => false,
        "message" => "Error al verificar empleado: " . $e->getMessage()
    ];
    http_response_code(500);
    custom_log("check_employee.php: ERROR - " . $e->getMessage());
}

// --- Envío de respuesta final ÚNICO ---
header('Content-Type: application/json');
$json = json_encode($response);

if ($json === false) {
    http_response_code(500);
    custom_log("check_employee.php: ERROR AL GENERAR JSON: " . json_last_error_msg());
    echo json_encode([
        'success' => false,
        'message' => 'Error al generar respuesta JSON',
        'error' => json_last_error_msg()
    ]);
} else {
    custom_log("check_employee.php: RESPUESTA FINAL ENVIADA: " . $json);
    echo $json;
}
exit;
?>