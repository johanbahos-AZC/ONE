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
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['editar_tipo_mesa'])) {
        // Editar tipo de mesa existente
        $id = $_POST['id'];
        $nombre = $_POST['nombre'];
        $color = $_POST['color'];
        $ancho = $_POST['ancho'];
        $alto = $_POST['alto'];
        
        if (!$id || !$nombre) {
            echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
            exit;
        }
        
        try {
            // Verificar si ya existe otro tipo de mesa con ese nombre
            $query = "SELECT id FROM tipos_mesa WHERE nombre = :nombre AND id != :id AND activo = 1";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => false, 'error' => 'Ya existe un tipo de mesa con ese nombre']);
                exit;
            }
            
            // Actualizar tipo de mesa
            $query = "UPDATE tipos_mesa 
                      SET nombre = :nombre, color = :color, ancho = :ancho, alto = :alto
                      WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':color', $color);
            $stmt->bindParam(':ancho', $ancho);
            $stmt->bindParam(':alto', $alto);
            $stmt->bindParam(':id', $id);
            $stmt->execute();
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else if (isset($_POST['agregar_tipo_mesa'])) {
        // Agregar nuevo tipo de mesa
        $nombre = $_POST['nombre'];
        $color = $_POST['color'];
        $ancho = $_POST['ancho'];
        $alto = $_POST['alto'];
        
        if (!$nombre) {
            echo json_encode(['success' => false, 'error' => 'El nombre es obligatorio']);
            exit;
        }
        
        try {
            // Verificar si ya existe un tipo de mesa con ese nombre
            $query = "SELECT id FROM tipos_mesa WHERE nombre = :nombre AND activo = 1";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                echo json_encode(['success' => false, 'error' => 'Ya existe un tipo de mesa con ese nombre']);
                exit;
            }
            
            // Insertar nuevo tipo de mesa
            $query = "INSERT INTO tipos_mesa (nombre, color, ancho, alto, activo) 
                      VALUES (:nombre, :color, :ancho, :alto, 1)";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':color', $color);
            $stmt->bindParam(':ancho', $ancho);
            $stmt->bindParam(':alto', $alto);
            $stmt->execute();
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else if (isset($_POST['eliminar_tipo_mesa'])) {
        // Eliminar tipo de mesa
        $tipo_mesa_id = $_POST['tipo_mesa_id'];
        
        if (!$tipo_mesa_id) {
            echo json_encode(['success' => false, 'error' => 'ID de tipo de mesa no proporcionado']);
            exit;
        }
        
        try {
            // Verificar si hay mesas usando este tipo
            $query = "SELECT COUNT(*) as total FROM mesas WHERE tipo_mesa_id = :tipo_mesa_id AND activo = 1";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':tipo_mesa_id', $tipo_mesa_id);
            $stmt->execute();
            $total = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($total > 0) {
                echo json_encode(['success' => false, 'error' => 'No se puede eliminar este tipo de mesa porque está siendo usado por ' . $total . ' mesas']);
                exit;
            }
            
            // Desactivar tipo de mesa
            $query = "UPDATE tipos_mesa SET activo = 0 WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $tipo_mesa_id);
            $stmt->execute();
            
            echo json_encode(['success' => true]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Acción no especificada']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud no válida']);
}
?>