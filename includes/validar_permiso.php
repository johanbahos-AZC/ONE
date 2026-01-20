<?php
require_once 'database.php';
require_once 'auth.php';
// Función para verificar si un usuario tiene permiso para una acción en un recurso
function tienePermiso($conn, $usuario_id, $usuario_role, $usuario_cargo_id, $recurso, $accion) {
    // Los administradores siempre tienen permiso
    if ($usuario_role === 'administrador') {
        return true;
    }
    
    // Verificar si hay un permiso específico para el usuario
    $query_usuario_permiso = "SELECT pu.permitido
                                FROM permisos_usuarios pu
                                JOIN recursos r ON pu.recurso_id = r.id
                                JOIN acciones a ON pu.accion_id = a.id
                                WHERE pu.usuario_id = ? AND r.nombre = ? AND a.nombre = ?";
    $stmt_usuario_permiso = $conn->prepare($query_usuario_permiso);
    $stmt_usuario_permiso->execute([$usuario_id, $recurso, $accion]);
    $permiso_usuario = $stmt_usuario_permiso->fetch(PDO::FETCH_ASSOC);
    
    if ($permiso_usuario) {
        return (bool)$permiso_usuario['permitido'];
    }
    
    // Verificar si hay un permiso para el cargo
    if ($usuario_cargo_id) {
        $query_cargo_permiso = "SELECT pc.permitido
                                FROM permisos_cargos pc
                                JOIN recursos r ON pc.recurso_id = r.id
                                JOIN acciones a ON pc.accion_id = a.id
                                WHERE pc.cargo_id = ? AND r.nombre = ? AND a.nombre = ?";
        $stmt_cargo_permiso = $conn->prepare($query_cargo_permiso);
        $stmt_cargo_permiso->execute([$usuario_cargo_id, $recurso, $accion]);
        $permiso_cargo = $stmt_cargo_permiso->fetch(PDO::FETCH_ASSOC);
        
        if ($permiso_cargo) {
            return (bool)$permiso_cargo['permitido'];
        }
    }
    
    // Verificar si hay un permiso para el rol
    $query_rol_permiso = "SELECT pr.permitido
                            FROM permisos_roles pr
                            JOIN recursos r ON pr.recurso_id = r.id
                            JOIN acciones a ON pr.accion_id = a.id
                            WHERE pr.rol = ? AND r.nombre = ? AND a.nombre = ?";
    $stmt_rol_permiso = $conn->prepare($query_rol_permiso);
    $stmt_rol_permiso->execute([$usuario_role, $recurso, $accion]);
    $permiso_rol = $stmt_rol_permiso->fetch(PDO::FETCH_ASSOC);
    
    if ($permiso_rol) {
        return (bool)$permiso_rol['permitido'];
    }
    
    // Por defecto, denegar el permiso
    return false;
}
?>