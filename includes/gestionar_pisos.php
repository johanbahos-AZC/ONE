<?php
require_once 'database.php';

header('Content-Type: application/json');

// Verificar si el usuario está autenticado
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

// Verificar permisos
 $database = new Database();
 $conn = $database->getConnection();

 $query_usuario = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name 
                 FROM employee e 
                 LEFT JOIN sedes s ON e.sede_id = s.id
                 LEFT JOIN firm f ON e.id_firm = f.id 
                 WHERE e.id = ?";
 $stmt_usuario = $conn->prepare($query_usuario);
 $stmt_usuario->execute([$_SESSION['user_id']]);
 $usuario_actual = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

require_once 'validar_permiso.php';
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'planos', 'editar_plano')) {
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para editar planos']);
    exit;
}

// Procesar la solicitud
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agregar_piso'])) {
    $sede_id = $_POST['sede_id'];
    $nombre = $_POST['nombre'];
    $numero = $_POST['numero'];
    $oficina = $_POST['oficina'] ?: null;
    
    if (!$sede_id || !$nombre || !$numero) {
        echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
        exit;
    }
    
    try {
        // Verificar si ya existe un piso con ese número para la misma sede/oficina
        $query = "SELECT id FROM pisos_sede 
                  WHERE sede_id = :sede_id AND numero = :numero 
                  AND (oficina = :oficina OR (oficina IS NULL AND :oficina IS NULL))";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->bindParam(':numero', $numero);
        $stmt->bindParam(':oficina', $oficina);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => false, 'error' => 'Ya existe un piso con ese número para esta sede/oficina']);
            exit;
        }
        
        // Insertar nuevo piso
        $query = "INSERT INTO pisos_sede (sede_id, nombre, numero, oficina, activo) 
                  VALUES (:sede_id, :nombre, :numero, :oficina, 1)";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':numero', $numero);
        $stmt->bindParam(':oficina', $oficina);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud no válida']);
}
?>