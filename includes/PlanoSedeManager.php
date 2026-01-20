<?php
require_once 'database.php';

class PlanoSedeManager {
    private $database;
    private $conn;
    
    public function __construct($database) {
        $this->database = $database;
        $this->conn = $database->getConnection();
    }
    
    // Obtener todas las sedes activas
    public function obtenerSedes() {
        $query = "SELECT * FROM sedes WHERE activa = 1 ORDER BY nombre";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener una sede específica
    public function obtenerSede($sede_id) {
        $query = "SELECT * FROM sedes WHERE id = :id AND activa = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id', $sede_id);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    // Obtener pisos de una sede (opcionalmente filtrados por oficina)
    public function obtenerPisosPorSede($sede_id, $oficina = null) {
        $query = "SELECT * FROM pisos_sede WHERE sede_id = :sede_id AND activo = 1";
        $params = [':sede_id' => $sede_id];
        
        if ($oficina) {
            $query .= " AND oficina = :oficina";
            $params[':oficina'] = $oficina;
        }
        
        $query .= " ORDER BY numero";
        
        $stmt = $this->conn->prepare($query);
        foreach ($params as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener tipos de mesa
    public function obtenerTiposMesa() {
        $query = "SELECT * FROM tipos_mesa WHERE activo = 1 ORDER BY nombre";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener mesas de un piso
    public function obtenerMesasPorPiso($piso_id) {
        $query = "SELECT m.*, tm.nombre as tipo_nombre, tm.color, tm.ancho, tm.alto,
        		 am.empleado_id,
                         e.first_Name, e.first_LastName,
                         CONCAT(e.first_Name, ' ', e.first_LastName) as empleado_nombre
                  FROM mesas m
                  JOIN tipos_mesa tm ON m.tipo_mesa_id = tm.id
                  LEFT JOIN asignaciones_mesa am ON m.id = am.mesa_id AND am.activo = 1
                  LEFT JOIN employee e ON am.empleado_id = e.id
                  WHERE m.piso_id = :piso_id AND m.activo = 1
                  ORDER BY m.numero";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':piso_id', $piso_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener elementos del plano (solo muros)
    public function obtenerElementosPlano($piso_id) {
        $query = "SELECT * FROM elementos_plano WHERE piso_id = :piso_id AND tipo = 'muro' ORDER BY id";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':piso_id', $piso_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Obtener estadísticas generales
    public function obtenerEstadisticasGenerales() {
        // Total de mesas
        $query = "SELECT COUNT(*) as total FROM mesas WHERE activo = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $total_mesas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Mesas ocupadas
        $query = "SELECT COUNT(*) as total FROM asignaciones_mesa WHERE activo = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        $mesas_ocupadas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Calcular estadísticas
        $mesas_disponibles = $total_mesas - $mesas_ocupadas;
        $tasa_utilizacion = $total_mesas > 0 ? round(($mesas_ocupadas / $total_mesas) * 100, 1) : 0;
        
        return [
            'total_mesas' => $total_mesas,
            'mesas_ocupadas' => $mesas_ocupadas,
            'mesas_disponibles' => $mesas_disponibles,
            'tasa_utilizacion' => $tasa_utilizacion
        ];
    }
    
    // Obtener estadísticas de una sede
    public function obtenerEstadisticasSede($sede_id) {
        // Total de mesas de la sede
        $query = "SELECT COUNT(*) as total 
                  FROM mesas m
                  JOIN pisos_sede p ON m.piso_id = p.id
                  WHERE p.sede_id = :sede_id AND m.activo = 1 AND p.activo = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->execute();
        $total_mesas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Mesas ocupadas de la sede
        $query = "SELECT COUNT(*) as total 
                  FROM asignaciones_mesa am
                  JOIN mesas m ON am.mesa_id = m.id
                  JOIN pisos_sede p ON m.piso_id = p.id
                  WHERE p.sede_id = :sede_id AND am.activo = 1 AND m.activo = 1 AND p.activo = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->execute();
        $mesas_ocupadas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Calcular estadísticas
        $mesas_disponibles = $total_mesas - $mesas_ocupadas;
        $tasa_utilizacion = $total_mesas > 0 ? round(($mesas_ocupadas / $total_mesas) * 100, 1) : 0;
        
        return [
            'total_mesas' => $total_mesas,
            'mesas_ocupadas' => $mesas_ocupadas,
            'mesas_disponibles' => $mesas_disponibles,
            'tasa_utilizacion' => $tasa_utilizacion
        ];
    }
    
    // Obtener estadísticas de una oficina (para Tequendama)
    public function obtenerEstadisticasOficina($sede_id, $oficina) {
        // Total de mesas de la oficina
        $query = "SELECT COUNT(*) as total 
                  FROM mesas m
                  JOIN pisos_sede p ON m.piso_id = p.id
                  WHERE p.sede_id = :sede_id AND p.oficina = :oficina AND m.activo = 1 AND p.activo = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->bindParam(':oficina', $oficina);
        $stmt->execute();
        $total_mesas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Mesas ocupadas de la oficina
        $query = "SELECT COUNT(*) as total 
                  FROM asignaciones_mesa am
                  JOIN mesas m ON am.mesa_id = m.id
                  JOIN pisos_sede p ON m.piso_id = p.id
                  WHERE p.sede_id = :sede_id AND p.oficina = :oficina AND am.activo = 1 AND m.activo = 1 AND p.activo = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->bindParam(':oficina', $oficina);
        $stmt->execute();
        $mesas_ocupadas = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Calcular estadísticas
        $mesas_disponibles = $total_mesas - $mesas_ocupadas;
        $tasa_utilizacion = $total_mesas > 0 ? round(($mesas_ocupadas / $total_mesas) * 100, 1) : 0;
        
        return [
            'total_mesas' => $total_mesas,
            'mesas_ocupadas' => $mesas_ocupadas,
            'mesas_disponibles' => $mesas_disponibles,
            'tasa_utilizacion' => $tasa_utilizacion
        ];
    }
    
    // Obtener empleados de una sede
    public function obtenerEmpleadosPorSede($sede_id) {
        $query = "SELECT e.id, e.first_Name, e.first_LastName, c.nombre as position_id,
                         CONCAT(e.first_Name, ' ', e.first_LastName) as nombre_completo,
                         m.numero as mesa_numero
                  FROM employee e
                  LEFT JOIN cargos c ON e.position_id = c.id
                  LEFT JOIN asignaciones_mesa am ON e.id = am.empleado_id AND am.activo = 1
                  LEFT JOIN mesas m ON am.mesa_id = m.id
                  WHERE e.sede_id = :sede_id AND e.role != 'retirado'
                  ORDER BY e.first_Name, e.first_LastName";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Guardar mesas
    public function guardarMesas($mesas, $piso_id) {
        try {
            $this->conn->beginTransaction();
            
            $ids_nuevos = [];
            
            foreach ($mesas as $mesa) {
                if (isset($mesa['nuevo']) && $mesa['nuevo']) {
                    // Insertar nueva mesa
                    $query = "INSERT INTO mesas (piso_id, tipo_mesa_id, numero, posicion_x, posicion_y, rotacion, activo) 
                              VALUES (:piso_id, :tipo_mesa_id, :numero, :posicion_x, :posicion_y, :rotacion, 1)";
                    $stmt = $this->conn->prepare($query);
                    $stmt->bindParam(':piso_id', $piso_id);
                    $stmt->bindParam(':tipo_mesa_id', $mesa['tipo_mesa_id']);
                    $stmt->bindParam(':numero', $mesa['numero']);
                    $stmt->bindParam(':posicion_x', $mesa['posicion_x']);
                    $stmt->bindParam(':posicion_y', $mesa['posicion_y']);
                    $stmt->bindParam(':rotacion', $mesa['rotacion']);
                    $stmt->execute();
                    
                    $nuevo_id = $this->conn->lastInsertId();
                    $ids_nuevos[] = [
                        'temp_id' => $mesa['id'],
                        'new_id' => $nuevo_id
                    ];
                } else if (isset($mesa['modificado']) && $mesa['modificado']) {
                    // Actualizar mesa existente
                    $query = "UPDATE mesas 
                              SET posicion_x = :posicion_x, posicion_y = :posicion_y, rotacion = :rotacion
                              WHERE id = :id";
                    $stmt = $this->conn->prepare($query);
                    $stmt->bindParam(':posicion_x', $mesa['posicion_x']);
                    $stmt->bindParam(':posicion_y', $mesa['posicion_y']);
                    $stmt->bindParam(':rotacion', $mesa['rotacion']);
                    $stmt->bindParam(':id', $mesa['id']);
                    $stmt->execute();
                }
            }
            
            $this->conn->commit();
            return ['success' => true, 'ids_mesas' => $ids_nuevos];
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Guardar elementos del plano (solo muros)
    public function guardarElementosPlano($elementos, $piso_id) {
        try {
            $this->conn->beginTransaction();
            
            $ids_nuevos = [];
            
            foreach ($elementos as $elemento) {
                if (isset($elemento['nuevo']) && $elemento['nuevo']) {
                    // Insertar nuevo elemento
                    $query = "INSERT INTO elementos_plano (piso_id, tipo, puntos, color, bloqueado) 
                              VALUES (:piso_id, :tipo, :puntos, :color, 1)";
                    $stmt = $this->conn->prepare($query);
                    $stmt->bindParam(':piso_id', $piso_id);
                    $stmt->bindParam(':tipo', $elemento['tipo']);
                    $stmt->bindParam(':puntos', $elemento['puntos']);
                    $stmt->bindParam(':color', $elemento['color']);
                    $stmt->execute();
                    
                    $nuevo_id = $this->conn->lastInsertId();
                    $ids_nuevos[] = [
                        'temp_id' => $elemento['id'],
                        'new_id' => $nuevo_id
                    ];
                } else if (isset($elemento['modificado']) && $elemento['modificado']) {
                    // Actualizar elemento existente
                    $query = "UPDATE elementos_plano 
                              SET puntos = :puntos
                              WHERE id = :id";
                    $stmt = $this->conn->prepare($query);
                    $stmt->bindParam(':puntos', $elemento['puntos']);
                    $stmt->bindParam(':id', $elemento['id']);
                    $stmt->execute();
                }
            }
            
            $this->conn->commit();
            return ['success' => true, 'ids_elementos' => $ids_nuevos];
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    // Asignar empleado a mesa
    public function asignarEmpleadoAMesa($mesa_id, $empleado_id) {
        try {
            $this->conn->beginTransaction();
            
            // Verificar si el empleado ya tiene una asignación activa
            $query = "SELECT am.id, am.mesa_id, m.numero 
                      FROM asignaciones_mesa am
                      JOIN mesas m ON am.mesa_id = m.id
                      WHERE am.empleado_id = :empleado_id AND am.activo = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':empleado_id', $empleado_id);
            $stmt->execute();
            $asignacion_existente = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($asignacion_existente) {
                // Desactivar la asignación anterior
                $query = "UPDATE asignaciones_mesa 
                          SET activo = 0, fecha_desasignacion = CURDATE()
                          WHERE id = :id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':id', $asignacion_existente['id']);
                $stmt->execute();
            }
            
            // Verificar si la mesa ya tiene un empleado asignado
            $query = "SELECT am.id, am.empleado_id, e.first_Name, e.first_LastName
                      FROM asignaciones_mesa am
                      JOIN employee e ON am.empleado_id = e.id
                      WHERE am.mesa_id = :mesa_id AND am.activo = 1";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':mesa_id', $mesa_id);
            $stmt->execute();
            $mesa_ocupada = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($mesa_ocupada) {
                // Desactivar la asignación anterior de la mesa
                $query = "UPDATE asignaciones_mesa 
                          SET activo = 0, fecha_desasignacion = CURDATE()
                          WHERE id = :id";
                $stmt = $this->conn->prepare($query);
                $stmt->bindParam(':id', $mesa_ocupada['id']);
                $stmt->execute();
            }
            
            // Crear nueva asignación
            $query = "INSERT INTO asignaciones_mesa (mesa_id, empleado_id, fecha_asignacion, activo) 
                      VALUES (:mesa_id, :empleado_id, CURDATE(), 1)";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':mesa_id', $mesa_id);
            $stmt->bindParam(':empleado_id', $empleado_id);
            $stmt->execute();
            
            $this->conn->commit();
            return ['success' => true];
        } catch (PDOException $e) {
            $this->conn->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
	// Desasignar empleado de mesa
	public function desasignarEmpleadoDeMesa($mesa_id) {
	    try {
	        $this->conn->beginTransaction();
	        
	        // Verificar si existe una asignación activa para esta mesa
	        $query = "SELECT id FROM asignaciones_mesa 
	                  WHERE mesa_id = :mesa_id AND activo = 1";
	        $stmt = $this->conn->prepare($query);
	        $stmt->bindParam(':mesa_id', $mesa_id);
	        $stmt->execute();
	        $asignacion = $stmt->fetch(PDO::FETCH_ASSOC);
	        
	        if (!$asignacion) {
	            $this->conn->rollBack();
	            return ['success' => false, 'error' => 'No hay ningún empleado asignado a esta mesa'];
	        }
	        
	        // Desactivar la asignación actual
	        $query = "UPDATE asignaciones_mesa 
	                  SET activo = 0, fecha_desasignacion = CURDATE()
	                  WHERE mesa_id = :mesa_id AND activo = 1";
	        $stmt = $this->conn->prepare($query);
	        $stmt->bindParam(':mesa_id', $mesa_id);
	        $stmt->execute();
	        
	        $this->conn->commit();
	        return ['success' => true];
	    } catch (PDOException $e) {
	        $this->conn->rollBack();
	        return ['success' => false, 'error' => $e->getMessage()];
	    }
	}
}
?>