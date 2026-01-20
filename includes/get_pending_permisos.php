<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

$auth = new Auth();
if (!$auth->isLoggedIn()) {
    header('HTTP/1.1 401 Unauthorized');
    exit();
}

$database = new Database();
$conn = $database->getConnection();
$functions = new Functions();

$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];

try {
    // Primero verificar si el usuario es supervisor
    $es_supervisor = $functions->esSupervisor($user_id);
    
    if ($es_supervisor) {
        // Para supervisores: contar solo permisos de sus subordinados pendientes de su aprobación
        $query = "SELECT COUNT(*) as count 
                  FROM permisos 
                  WHERE supervisor_id = :user_id 
                  AND estado_supervisor = 'pendiente'";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':user_id', $user_id);
    } elseif ($user_role == 'talento_humano') {
        // Para talento humano: contar permisos pendientes de TH, excluyendo 'no_aplica'
        $query = "SELECT COUNT(*) as count 
                  FROM permisos 
                  WHERE estado_talento_humano = 'pendiente' 
                  AND estado_talento_humano != 'no_aplica'";
        $stmt = $conn->prepare($query);
    } else {
        // Para administradores: contar todos los permisos pendientes en cualquier estado
        $query = "SELECT COUNT(*) as count 
                  FROM permisos 
                  WHERE estado_supervisor = 'pendiente' 
                  OR (estado_talento_humano = 'pendiente' AND estado_talento_humano != 'no_aplica')";
        $stmt = $conn->prepare($query);
    }
    
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo json_encode(['count' => $result['count']]);
    
} catch (Exception $e) {
    echo json_encode(['count' => 0]);
}
?>