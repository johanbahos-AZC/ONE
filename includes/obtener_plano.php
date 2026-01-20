<?php
require_once 'database.php';
require_once 'PlanoSedeManager.php';

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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'planos', 'ver')) {
    echo json_encode(['success' => false, 'error' => 'No tiene permisos para ver planos']);
    exit;
}

// Procesar la solicitud
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $piso_id = $_GET['piso_id'];
    $tipo = $_GET['tipo']; // 'mesas' o 'elementos'
    
    if (!$piso_id || !$tipo) {
        echo json_encode(['success' => false, 'error' => 'Parámetros incompletos']);
        exit;
    }
    
    // Inicializar el gestor de planos
    $planoManager = new PlanoSedeManager($database);
    
    if ($tipo === 'mesas') {
        // Obtener mesas
        $mesas = $planoManager->obtenerMesasPorPiso($piso_id);
        
        // Formatear datos para el frontend
        $mesas_formateadas = [];
        foreach ($mesas as $mesa) {
            $mesas_formateadas[] = [
                'id' => $mesa['id'],
                'tipo_mesa_id' => $mesa['tipo_mesa_id'],
                'numero' => $mesa['numero'],
                'posicion_x' => (int)$mesa['posicion_x'],
                'posicion_y' => (int)$mesa['posicion_y'],
                'rotacion' => (int)$mesa['rotacion'],
                'ancho' => (int)$mesa['ancho'],
                'alto' => (int)$mesa['alto'],
                'color' => $mesa['color'],
                'tipo_nombre' => $mesa['tipo_nombre'],
                'empleado_id' => $mesa['empleado_id'],
                'empleado_nombre' => $mesa['empleado_nombre']
            ];
        }
        
        echo json_encode(['success' => true, 'mesas' => $mesas_formateadas]);
    } else if ($tipo === 'elementos') {
        // Obtener elementos del plano (solo muros)
        $elementos = $planoManager->obtenerElementosPlano($piso_id);
        
        // Filtrar solo muros
        $muros = array_filter($elementos, function($elemento) {
            return $elemento['tipo'] === 'muro';
        });
        
        // Formatear datos para el frontend
        $elementos_formateados = [];
        foreach ($muros as $elemento) {
            $elementos_formateados[] = [
                'id' => $elemento['id'],
                'tipo' => $elemento['tipo'],
                'puntos' => $elemento['puntos'],
                'color' => $elemento['color']
            ];
        }
        
        echo json_encode(['success' => true, 'elementos' => $elementos_formateados]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Tipo no válido']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Solicitud no válida']);
}
?>