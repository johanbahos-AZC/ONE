<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

// Solo administradores y IT pueden gestionar áreas
if (!in_array($_SESSION['user_role'], ['administrador', 'it'])) {
    header("Location: dashboard.php");
    exit();
}

$database = new Database();
$conn = $database->getConnection();

// Procesar creación de área
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_area'])) {
    $nombre = trim($_POST['nombre']);
    $padre_id = !empty($_POST['padre_id']) ? $_POST['padre_id'] : null;
    $descripcion = trim($_POST['descripcion']);
    
    $response = ['success' => false, 'error' => ''];
    
    try {
        $query = "INSERT INTO areas (nombre, descripcion, padre_id) VALUES (?, ?, ?)";
        $stmt = $conn->prepare($query);
        $stmt->execute([$nombre, $descripcion, $padre_id]);
        
        $response['success'] = true;
        $response['area_id'] = $conn->lastInsertId();
    } catch (PDOException $e) {
        $response['error'] = "Error al crear el área: " . $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Procesar agregar supervisor
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['agregar_supervisor'])) {
    $area_id = $_POST['area_id'];
    $empleado_id = $_POST['empleado_id'];
    $tipo_supervisor = $_POST['tipo_supervisor'];
    
    $response = ['success' => false, 'error' => ''];
    
    try {
        // Verificar si el empleado ya es supervisor en esta área
        $query_check = "SELECT id FROM supervisores WHERE empleado_id = ? AND area_id = ?";
        $stmt_check = $conn->prepare($query_check);
        $stmt_check->execute([$empleado_id, $area_id]);
        
        if ($stmt_check->rowCount() > 0) {
            $response['error'] = "Este empleado ya es supervisor en esta área";
        } else {
            $query = "INSERT INTO supervisores (empleado_id, tipo_supervisor, area_id) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->execute([$empleado_id, $tipo_supervisor, $area_id]);
            
            $response['success'] = true;
        }
    } catch (PDOException $e) {
        $response['error'] = "Error al agregar supervisor: " . $e->getMessage();
    }
    
    header('Content-Type: application/json');
    echo json_encode($response);
    exit();
}

// Procesar eliminación de supervisor (desde URL)
if (isset($_GET['eliminar_supervisor'])) {
    $supervisor_id = $_GET['eliminar_supervisor'];
    $area_id = $_GET['area_id'] ?? null;
    
    try {
        $query = "DELETE FROM supervisores WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$supervisor_id]);
        
        // Redirigir de vuelta al área
        if ($area_id) {
            header("Location: areas.php?success=Supervisor eliminado correctamente#area-" . $area_id);
        } else {
            header("Location: areas.php?success=Supervisor eliminado correctamente");
        }
        exit();
    } catch (PDOException $e) {
        header("Location: areas.php?error=Error al eliminar supervisor: " . $e->getMessage());
        exit();
    }
}
?>