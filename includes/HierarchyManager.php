<?php
class HierarchyManager {
    private $conn;
    
    public function __construct($database) {
        $this->conn = $database->getConnection();
    }
    
    /**
     * Obtener la cadena de mando completa de un empleado
     */
    public function obtenerCadenaMando($empleado_id) {
        $jerarquia = [
            'empleado' => null,
            'coordinador' => null,
            'director' => null,
            'gerente' => null
        ];
        
        // Obtener información del empleado
        $query = "SELECT e.*, a.id as area_id, a.nombre as area_nombre 
                  FROM employee e 
                  LEFT JOIN areas a ON e.area_id = a.id 
                  WHERE e.id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$empleado_id]);
        $empleado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$empleado) return $jerarquia;
        
        $jerarquia['empleado'] = $empleado;
        
        // Buscar supervisores en la jerarquía del área
        if ($empleado['area_id']) {
            $jerarquia = $this->obtenerSupervisoresPorArea($empleado['area_id'], $jerarquia);
        }
        
        return $jerarquia;
    }
    
    /**
     * Obtener todos los supervisores de un área específica
     */
    private function obtenerSupervisoresPorArea($area_id, $jerarquia) {
        $query = "SELECT s.*, e.first_Name, e.first_LastName, e.mail, e.position 
                  FROM supervisores s 
                  JOIN employee e ON s.empleado_id = e.id 
                  WHERE s.area_id = ? AND e.role != 'retirado'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$area_id]);
        $supervisores = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($supervisores as $supervisor) {
            $jerarquia[$supervisor['tipo_supervisor']] = $supervisor;
        }
        
        // Si el área tiene un área padre, buscar supervisores allí también
        $query_padre = "SELECT padre_id FROM areas WHERE id = ?";
        $stmt_padre = $this->conn->prepare($query_padre);
        $stmt_padre->execute([$area_id]);
        $area_padre = $stmt_padre->fetch(PDO::FETCH_ASSOC);
        
        if ($area_padre && $area_padre['padre_id']) {
            $jerarquia = $this->obtenerSupervisoresPorArea($area_padre['padre_id'], $jerarquia);
        }
        
        return $jerarquia;
    }
    
    /**
     * Obtener todos los empleados bajo la supervisión de un jefe
     */
public function obtenerSubordinados($supervisor_id) {
    try {
        // Primero obtener las áreas que supervisa
        $query_areas = "SELECT area_id FROM supervisores WHERE empleado_id = ?";
        $stmt_areas = $this->conn->prepare($query_areas);
        $stmt_areas->execute([$supervisor_id]);
        $areas_supervisadas = $stmt_areas->fetchAll(PDO::FETCH_COLUMN);
        
        if (empty($areas_supervisadas)) {
            return [];
        }
        
        // Obtener empleados de esas áreas
        $placeholders = str_repeat('?,', count($areas_supervisadas) - 1) . '?';
        $query = "SELECT e.*, a.nombre as area_nombre 
                  FROM employee e 
                  LEFT JOIN areas a ON e.area_id = a.id 
                  WHERE e.area_id IN ($placeholders) AND e.role != 'retirado' 
                  ORDER BY e.first_Name";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute($areas_supervisadas);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error en obtenerSubordinados: " . $e->getMessage());
        return [];
    }
}
    
    /**
     * Obtener empleados de un área y todas sus sub-áreas
     */
    private function obtenerEmpleadosPorAreaYSubareas($area_id) {
        $empleados = [];
        
        // Obtener todas las sub-áreas recursivamente
        $areas = $this->obtenerSubAreasRecursivas($area_id);
        $areas[] = $area_id; // Incluir el área principal
        
        // Obtener empleados de todas estas áreas
        $placeholders = str_repeat('?,', count($areas) - 1) . '?';
        $query = "SELECT e.*, a.nombre as area_nombre 
                  FROM employee e 
                  LEFT JOIN areas a ON e.area_id = a.id 
                  WHERE e.area_id IN ($placeholders) AND e.role != 'retirado'";
        $stmt = $this->conn->prepare($query);
        $stmt->execute($areas);
        $empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return $empleados;
    }
    
    /**
     * Obtener todas las sub-áreas de un área (recursivamente)
     */
    private function obtenerSubAreasRecursivas($area_id) {
        $sub_areas = [];
        
        $query = "SELECT id FROM areas WHERE padre_id = ?";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([$area_id]);
        $hijas_directas = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        foreach ($hijas_directas as $hija_id) {
            $sub_areas[] = $hija_id;
            $sub_areas = array_merge($sub_areas, $this->obtenerSubAreasRecursivas($hija_id));
        }
        
        return $sub_areas;
    }
    
    /**
     * Asignar supervisor a un empleado y actualizar su área
     */
    public function asignarSupervisor($empleado_id, $supervisor_id) {
        try {
            // Obtener área del supervisor
            $query_area = "SELECT area_id FROM supervisores WHERE empleado_id = ? LIMIT 1";
            $stmt_area = $this->conn->prepare($query_area);
            $stmt_area->execute([$supervisor_id]);
            $area_supervisor = $stmt_area->fetch(PDO::FETCH_ASSOC);
            
            if (!$area_supervisor) {
                throw new Exception("El supervisor seleccionado no tiene un área asignada");
            }
            
            // Actualizar empleado
            $query_update = "UPDATE employee SET supervisor_id = ?, area_id = ? WHERE id = ?";
            $stmt_update = $this->conn->prepare($query_update);
            $stmt_update->execute([$supervisor_id, $area_supervisor['area_id'], $empleado_id]);
            
            return true;
        } catch (Exception $e) {
            error_log("Error al asignar supervisor: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Obtener todos los supervisores disponibles
     */
    public function obtenerSupervisoresDisponibles() {
        $query = "SELECT s.*, e.id as emp_id, e.first_Name, e.first_LastName, e.mail, 
                         e.position, a.nombre as area_nombre, a.id as area_id
                  FROM supervisores s 
                  JOIN employee e ON s.empleado_id = e.id 
                  JOIN areas a ON s.area_id = a.id 
                  WHERE e.role != 'retirado' 
                  ORDER BY s.tipo_supervisor, e.first_Name";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Verificar si un usuario tiene permisos para ver a otro usuario
     */
    public function tienePermisoVisualizacion($usuario_id, $empleado_a_ver) {
        // Administradores e IT pueden ver todo
        $query_rol = "SELECT role FROM employee WHERE id = ?";
        $stmt_rol = $this->conn->prepare($query_rol);
        $stmt_rol->execute([$usuario_id]);
        $usuario = $stmt_rol->fetch(PDO::FETCH_ASSOC);
        
        if (in_array($usuario['role'], ['administrador', 'it'])) {
            return true;
        }
        
        // Supervisores solo pueden ver a sus subordinados
        $subordinados = $this->obtenerSubordinados($usuario_id);
        $ids_subordinados = array_column($subordinados, 'id');
        
        return in_array($empleado_a_ver, $ids_subordinados);
    }
}
?>