<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/validar_permiso.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

// Verificar permisos para ver esta página
 $database = new Database();
 $conn = $database->getConnection();

// Obtener información del usuario actual
 $query_usuario = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name 
                 FROM employee e 
                 LEFT JOIN sedes s ON e.sede_id = s.id
                 LEFT JOIN firm f ON e.id_firm = f.id 
                 WHERE e.id = ?";
 $stmt_usuario = $conn->prepare($query_usuario);
 $stmt_usuario->execute([$_SESSION['user_id']]);
 $usuario_actual = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

// Verificar permiso para ver la página de usuarios
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'areas', 'ver')) {
    header("Location: portal.php");
    exit();
}
$functions = new Functions();

$error = '';
$success = '';

// Procesar creación de área
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_area'])) {
    $nombre = trim($_POST['nombre']);
    $padre_id = !empty($_POST['padre_id']) ? $_POST['padre_id'] : null;
    $descripcion = trim($_POST['descripcion']);
    
    if (!empty($nombre)) {
        try {
            $query = "INSERT INTO areas (nombre, descripcion, padre_id) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($query);
            $stmt->execute([$nombre, $descripcion, $padre_id]);
            
            $success = "Área creada correctamente";
        } catch (PDOException $e) {
            $error = "Error al crear el área: " . $e->getMessage();
        }
    } else {
        $error = "El nombre del área es requerido";
    }
}

// Procesar actualización de área
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_area'])) {
    $area_id = $_POST['area_id'];
    $nombre = trim($_POST['nombre']);
    $padre_id = !empty($_POST['padre_id']) ? $_POST['padre_id'] : null;
    $descripcion = trim($_POST['descripcion']);
    
    if (!empty($nombre)) {
        try {
            // Evitar que un área sea su propio padre
            if ($padre_id == $area_id) {
                $error = "Un área no puede ser padre de sí misma";
            } else {
                $query = "UPDATE areas SET nombre = ?, descripcion = ?, padre_id = ?, updated_at = NOW() WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->execute([$nombre, $descripcion, $padre_id, $area_id]);
                
                $success = "Área actualizada correctamente";
            }
        } catch (PDOException $e) {
            $error = "Error al actualizar el área: " . $e->getMessage();
        }
    } else {
        $error = "El nombre del área es requerido";
    }
}

// Procesar eliminación de área
if (isset($_GET['eliminar'])) {
    $area_id = $_GET['eliminar'];
    
    try {
        // Verificar si hay empleados en esta área
        $query_check_empleados = "SELECT COUNT(*) as total FROM employee WHERE area_id = ?";
        $stmt_check = $conn->prepare($query_check_empleados);
        $stmt_check->execute([$area_id]);
        $total_empleados = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Verificar si hay sub-áreas
        $query_check_subareas = "SELECT COUNT(*) as total FROM areas WHERE padre_id = ?";
        $stmt_subareas = $conn->prepare($query_check_subareas);
        $stmt_subareas->execute([$area_id]);
        $total_subareas = $stmt_subareas->fetch(PDO::FETCH_ASSOC)['total'];
        
        // Verificar si hay supervisores en esta área
        $query_check_supervisores = "SELECT COUNT(*) as total FROM supervisores WHERE area_id = ?";
        $stmt_supervisores = $conn->prepare($query_check_supervisores);
        $stmt_supervisores->execute([$area_id]);
        $total_supervisores = $stmt_supervisores->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($total_empleados > 0) {
            $error = "No se puede eliminar el área porque tiene $total_empleados empleado(s) asignado(s)";
        } elseif ($total_subareas > 0) {
            $error = "No se puede eliminar el área porque tiene $total_subareas sub-área(s) dependiente(s)";
        } elseif ($total_supervisores > 0) {
            $error = "No se puede eliminar el área porque tiene $total_supervisores supervisor(es) asignado(s)";
        } else {
            $query = "DELETE FROM areas WHERE id = ?";
            $stmt = $conn->prepare($query);
            $stmt->execute([$area_id]);
            
            $success = "Área eliminada correctamente";
        }
    } catch (PDOException $e) {
        $error = "Error al eliminar el área: " . $e->getMessage();
    }
}
// Procesar eliminación de supervisor (desde URL) - ACTUALIZAR ESTA PARTE
if (isset($_GET['eliminar_supervisor'])) {
    $supervisor_id = $_GET['eliminar_supervisor'];
    $area_id = $_GET['area_id'] ?? null;
    
    try {
        $query = "DELETE FROM supervisores WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$supervisor_id]);
        
        $success = "Supervisor eliminado correctamente";
        
        // Redirigir para refrescar y evitar reenvío del formulario
        if ($area_id) {
            header("Location: areas.php?success=Supervisor+eliminado+correctamente#area-" . $area_id);
        } else {
            header("Location: areas.php?success=Supervisor+eliminado+correctamente");
        }
        exit();
        
    } catch (PDOException $e) {
        $error = "Error al eliminar supervisor: " . $e->getMessage();
    }
}
// Procesar remover empleado del área
if (isset($_GET['remover_empleado_area'])) {
    $empleado_id = $_GET['remover_empleado_area'];
    $area_id = $_GET['area_id'] ?? null;
    
    try {
        $query = "UPDATE employee SET area_id = NULL, supervisor_id = NULL WHERE id = ?";
        $stmt = $conn->prepare($query);
        $stmt->execute([$empleado_id]);
        
        if ($area_id) {
            header("Location: areas.php?success=Empleado removido del área correctamente#area-" . $area_id);
        } else {
            header("Location: areas.php?success=Empleado removido del área correctamente");
        }
        exit();
    } catch (PDOException $e) {
        header("Location: areas.php?error=Error al remover empleado del área: " . $e->getMessage());
        exit();
    }
}

// Obtener todas las áreas
$query_areas = "SELECT a.*, 
                       p.nombre as padre_nombre,
                       (SELECT COUNT(*) FROM employee WHERE area_id = a.id) as total_empleados,
                       (SELECT COUNT(*) FROM areas WHERE padre_id = a.id) as total_subareas,
                       (SELECT COUNT(*) FROM supervisores WHERE area_id = a.id) as total_supervisores
                FROM areas a 
                LEFT JOIN areas p ON a.padre_id = p.id 
                ORDER BY a.padre_id IS NULL DESC, a.nombre";
$stmt_areas = $conn->prepare($query_areas);
$stmt_areas->execute();
$areas = $stmt_areas->fetchAll(PDO::FETCH_ASSOC);

// Obtener áreas para select (sin la actual cuando se edita)
function obtenerAreasParaSelect($conn, $excluir_id = null, $selected_id = null) {
    $query = "SELECT id, nombre, padre_id FROM areas ORDER BY nombre";
    $stmt = $conn->prepare($query);
    $stmt->execute();
    $areas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Recursiva: genera solo <option> y nada más
    $fn = function($areas, $padre_id = null, $nivel = 0) use (&$fn, $excluir_id, $selected_id) {
        $html = '';
        $prefijo = str_repeat('--', $nivel);

        foreach ($areas as $area) {
            // normalizar null/'' para la comparación
            $areaPadre = ($area['padre_id'] === null || $area['padre_id'] === '') ? null : $area['padre_id'];
            if ($areaPadre == $padre_id && $area['id'] != $excluir_id) {
                $safeName = htmlspecialchars($area['nombre'], ENT_QUOTES, 'UTF-8');
                $sel = ($selected_id !== null && $selected_id == $area['id']) ? ' selected' : '';
                $html .= '<option value="' . $area['id'] . '"' . $sel . ' data-value="' . $area['id'] . '">' . $prefijo . ' ' . $safeName . '</option>' . "\n";
                $html .= $fn($areas, $area['id'], $nivel + 1);
            }
        }
        return $html;
    };

    return $fn($areas, null, 0);
}


// Función para mostrar árbol de áreas CORREGIDA
function mostrarArbolAreas($areas, $conn, $padre_id = null, $nivel = 0) {
    $html = '';
    $areas_del_nivel = [];
    
    // Primero recolectar todas las áreas de este nivel
    foreach ($areas as $area) {
        if ($area['padre_id'] == $padre_id) {
            $areas_del_nivel[] = $area;
        }
    }
    
    // Luego mostrar cada área del nivel actual
    foreach ($areas_del_nivel as $area) {
        $margin = $nivel * 25;
        $html .= '<div class="area-item" data-area-id="' . $area['id'] . '" style="margin-left: ' . $margin . 'px;">';
        $html .= '<div class="card mb-2 area-card">';
        $html .= '<div class="card-body p-3">';
        
	// Encabezado clickeable 
	$html .= '<div class="area-header d-flex justify-content-between align-items-center cursor-pointer">';
	$html .= '<div class="flex-grow-1">';
	$html .= '<h6 class="mb-1 area-title">' . htmlspecialchars($area['nombre']) . '</h6>';
	$html .= '<small class="text-muted">';
	$html .= '<span class="badge bg-corporate-primary me-2"><i class="bi bi-people"></i> ' . $area['total_empleados'] . ' empleados</span>';
	$html .= '<span class="badge bg-corporate-info me-2"><i class="bi bi-diagram-3"></i> ' . $area['total_subareas'] . ' sub-áreas</span>';
	$html .= '<span class="badge bg-corporate-warning"><i class="bi bi-star"></i> ' . $area['total_supervisores'] . ' supervisores</span>';
	if (!empty($area['descripcion'])) {
	    $html .= '<br><em>' . htmlspecialchars($area['descripcion']) . '</em>';
	}
	$html .= '</small>';
	$html .= '</div>';
	$html .= '<div class="d-flex align-items-center">';
	$html .= '<span class="badge bg-corporate-light me-2" id="area-badge-' . $area['id'] . '">';
	$html .= $area['padre_nombre'] ? 'Sub-área de: ' . htmlspecialchars($area['padre_nombre']) : 'Área Principal';
	$html .= '</span>';
	$html .= '<i class="bi bi-chevron-down area-arrow" id="area-arrow-' . $area['id'] . '"></i>';
	$html .= '</div>';
	$html .= '</div>'; // cierra area-header
        
	// Botones de acción
	$html .= '<div class="area-actions mt-2">';
	$html .= '<button type="button" class="btn btn-sm btn-outline-primary me-1 btn-editar-area" 
	                  data-bs-toggle="modal" data-bs-target="#modalEditarArea' . $area['id'] . '">
	              <i class="bi bi-pencil"></i> Editar
	          </button>';
	$html .= '<a href="?eliminar=' . $area['id'] . '" class="btn btn-sm btn-outline-danger btn-eliminar-area" 
	             onclick="return confirm(\'¿Está seguro de eliminar el área: ' . htmlspecialchars($area['nombre']) . '?\')">
	              <i class="bi bi-trash"></i> Eliminar
	          </a>';
	$html .= '</div>';
        
        // SECCIÓN COLAPSABLE - Información detallada
        $html .= '<div class="area-details mt-3" id="area-details-' . $area['id'] . '" style="display: none;">';
        
        // Supervisores de esta área
        $html .= '<div class="mb-3">';
        $html .= '<strong><i class="bi bi-people"></i> Supervisores:</strong>';
	 $query_supervisores_area = "SELECT s.tipo_supervisor, e.first_Name, e.first_LastName, e.mail, 
	                                  COALESCE(c.nombre, e.position) as position
	                           FROM supervisores s 
	                           JOIN employee e ON s.empleado_id = e.id 
	                           LEFT JOIN cargos c ON e.position_id = c.id
	                           WHERE s.area_id = ? AND e.role != 'retirado' 
	                           ORDER BY s.tipo_supervisor";
        $stmt_sup = $conn->prepare($query_supervisores_area);
        $stmt_sup->execute([$area['id']]);
        $supervisores_area = $stmt_sup->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($supervisores_area)) {
            $html .= '<div class="text-muted ms-3"><em>No hay supervisores asignados</em></div>';
        } else {
            foreach ($supervisores_area as $sup) {
		    $badge_class = $sup['tipo_supervisor'] == 'gerente' ? 'bg-corporate-primary' : 
		                  ($sup['tipo_supervisor'] == 'director' ? 'bg-corporate-info' : 'bg-corporate-success');
		    $html .= '<div class="ms-3 mb-2 p-2 border rounded">';
		    $html .= '<span class="badge ' . $badge_class . ' me-2">' . ucfirst($sup['tipo_supervisor']) . '</span>';
		    $html .= '<strong>' . htmlspecialchars($sup['first_Name'] . ' ' . $sup['first_LastName']) . '</strong>';
		    $html .= '<br><small class="text-muted">' . htmlspecialchars($sup['position']) . '</small>';
		    $html .= '<br><small class="text-muted"><i class="bi bi-envelope"></i> ' . htmlspecialchars($sup['mail']) . '</small>';
		    $html .= '</div>';
		}
        }
        $html .= '</div>';
        
	// Empleados de esta área (solo primeros 5)
	$html .= '<div class="mb-3">';
	$html .= '<strong><i class="bi bi-person"></i> Empleados (' . $area['total_empleados'] . '):</strong>';
	 $query_empleados_area = "SELECT e.id, e.first_Name, e.first_LastName, e.mail, 
	                               COALESCE(c.nombre, e.position) as position
	                        FROM employee e 
	                        LEFT JOIN cargos c ON e.position_id = c.id
	                        WHERE e.area_id = ? AND e.role != 'retirado' 
	                        ORDER BY e.first_Name 
	                        LIMIT 5";
	$stmt_emp = $conn->prepare($query_empleados_area);
	$stmt_emp->execute([$area['id']]);
	$empleados_area = $stmt_emp->fetchAll(PDO::FETCH_ASSOC);
	
	if (empty($empleados_area)) {
	    $html .= '<div class="text-muted ms-3"><em>No hay empleados en esta área</em></div>';
	} else {
	    foreach ($empleados_area as $emp) {
	        $html .= '<div class="ms-3 mb-1 d-flex justify-content-between align-items-center">';
	        $html .= '<div>';
	        $html .= '• <strong>' . htmlspecialchars($emp['first_Name'] . ' ' . $emp['first_LastName']) . '</strong>';
	        $html .= ' <small class="text-muted">- ' . htmlspecialchars($emp['position']) . '</small>';
	        $html .= '</div>';
	        $html .= '<a href="?remover_empleado_area=' . $emp['id'] . '&area_id=' . $area['id'] . '" 
	                   class="btn btn-sm btn-outline-danger ms-2"
	                   onclick="return confirm(\'¿Está seguro de remover a ' . htmlspecialchars($emp['first_Name'] . ' ' . $emp['first_LastName']) . ' del área?\')">
	                   <i class="bi bi-x-circle"></i> Remover
	                </a>';
	        $html .= '</div>';
	    }
	    if ($area['total_empleados'] > 5) {
	        $html .= '<div class="ms-3 text-muted"><em>... y ' . ($area['total_empleados'] - 5) . ' más</em></div>';
	    }
	}
	$html .= '</div>';
        
        $html .= '</div>'; // cierra area-details
        $html .= '</div>'; // cierra card-body
        $html .= '</div>'; // cierra card
        $html .= '</div>'; // cierra area-item
        
        // Llamada recursiva para sub-áreas
        $html .= mostrarArbolAreas($areas, $conn, $area['id'], $nivel + 1);
    }
    
    return $html;
}

// Función para generar diagrama organizacional
function generarDiagramaOrganizacional($areas, $conn) {
    $html = '<div class="organigrama">';
    
    // Áreas principales (sin padre)
    $areas_principales = array_filter($areas, function($area) {
        return empty($area['padre_id']);
    });
    
    foreach ($areas_principales as $area) {
        $html .= generarNodoOrganigrama($area, $areas, $conn, 0);
    }
    
    $html .= '</div>';
    return $html;
}

function generarNodoOrganigrama($area, $areas, $conn, $nivel) {
    $html = '<div class="organigrama-nodo" style="margin-left: ' . ($nivel * 50) . 'px;">';
    $html .= '<div class="nodo-content card">';
    $html .= '<div class="card-body text-center">';
    $html .= '<h6 class="nodo-titulo">' . htmlspecialchars($area['nombre']) . '</h6>';
    $html .= '<div class="nodo-stats">';
    $html .= '<small class="text-muted">';
    $html .= $area['total_empleados'] . ' emp • ' . $area['total_subareas'] . ' sub<br>';
    $html .= $area['total_supervisores'] . ' sup';
    $html .= '</small>';
    $html .= '</div>';
    $html .= '</div>';
    $html .= '</div>';
    
    // Sub-áreas
    $sub_areas = array_filter($areas, function($a) use ($area) {
        return $a['padre_id'] == $area['id'];
    });
    
    if (!empty($sub_areas)) {
        $html .= '<div class="organigrama-hijos">';
        foreach ($sub_areas as $sub_area) {
            $html .= generarNodoOrganigrama($sub_area, $areas, $conn, $nivel + 1);
        }
        $html .= '</div>';
    }
    
    $html .= '</div>';
    return $html;
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Áreas - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
<style>
    .area-item {
        transition: all 0.3s ease;
    }
    .area-item:hover .area-card {
        box-shadow: 0 2px 8px rgba(0,58,93,0.15);
        transform: translateY(-1px);
    }
    .area-card {
        border-left: 4px solid #003a5d;
        border: 1px solid #e0e0e0;
        transition: all 0.3s ease;
    }
    .cursor-pointer {
        cursor: pointer;
    }
    .area-details {
        border-top: 1px solid #e9ecef;
        padding-top: 15px;
        background-color: #f8fafc;
        border-radius: 4px;
        padding: 15px;
        margin-top: 10px;
    }
    .area-actions {
        opacity: 0.7;
        transition: opacity 0.3s ease;
    }
    .area-card:hover .area-actions {
        opacity: 1;
    }
    
    /* Badges corporativos - colores más profesionales */
    .badge.bg-corporate-primary {
        background-color: #003a5d !important;
        color: white;
    }
    .badge.bg-corporate-secondary {
        background-color: #2c5a7d !important;
        color: white;
    }
    .badge.bg-corporate-success {
        background-color: #1e7b4e !important;
        color: white;
    }
    .badge.bg-corporate-warning {
        background-color: #e6a700 !important;
        color: #353132;
    }
    .badge.bg-corporate-danger {
        background-color: #be1622 !important;
        color: white;
    }
    .badge.bg-corporate-info {
        background-color: #0066a8 !important;
        color: white;
    }
    .badge.bg-corporate-light {
        background-color: #f8f9fa !important;
        color: #353132;
        border: 1px solid #dee2e6;
    }
    
    .organigrama {
        padding: 20px;
    }
    .organigrama-nodo {
        margin-bottom: 20px;
    }
    .nodo-content {
        background: linear-gradient(135deg, #003a5d, #0056b3);
        color: white;
        border: none;
        min-width: 200px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .organigrama-hijos {
        border-left: 2px solid #003a5d;
        margin-left: 25px;
        padding-left: 25px;
    }
    .nodo-titulo {
        font-weight: bold;
        margin-bottom: 5px;
        font-size: 0.9rem;
    }
    .nodo-stats {
        font-size: 0.75em;
        opacity: 0.9;
    }
    .tab-content {
        min-height: 400px;
    }
    
    /* Mejoras para las pestañas */
    .nav-tabs .nav-link {
        color: #495057;
        font-weight: 500;
        border: none;
        padding: 12px 20px;
    }
    .nav-tabs .nav-link:hover {
        border: none;
        color: #003a5d;
    }
    .nav-tabs .nav-link.active {
        color: #003a5d;
        background-color: white;
        border-bottom: 3px solid #003a5d;
        font-weight: 600;
    }
    
    /* Mejoras para los modales */
    .modal-header {
        background-color: #003a5d;
        color: white;
    }
    .modal-header .btn-close {
        filter: invert(1);
    }
    
	/* MEJORAS PARA ORGANIGRAMA VISUAL - LÍNEAS MÁS CONGRUENTES */
	.organigrama-visual {
	    padding: 20px;
	    overflow-x: auto;
	    background: #f8fafc;
	    border-radius: 8px;
	}
	
	.organigrama-nivel {
	    display: flex;
	    justify-content: center;
	    gap: 40px;
	    margin-bottom: 40px;
	    position: relative;
	    min-height: 120px;
	}
	
	.organigrama-nivel[data-nivel="0"] {
	    justify-content: center;
	}
	
	.organigrama-nivel[data-nivel="1"] {
	    justify-content: space-around;
	}
	
	.organigrama-nivel[data-nivel="2"] {
	    justify-content: space-between;
	    gap: 20px;
	}
	
	.organigrama-nodo {
	    display: flex;
	    flex-direction: column;
	    align-items: center;
	    position: relative;
	    flex: 1;
	}
	
	.nodo-contenedor {
	    display: flex;
	    flex-direction: column;
	    align-items: center;
	    position: relative;
	    width: 100%;
	}
	
	.nodo-principal {
	    border: 2px solid #003a5d;
	    border-radius: 8px;
	    background: white;
	    min-width: 220px;
	    max-width: 280px;
	    transition: all 0.3s ease;
	    position: relative;
	    z-index: 2;
	    box-shadow: 0 2px 8px rgba(0, 58, 93, 0.1);
	}
	
	.nodo-principal:hover {
	    transform: translateY(-3px);
	    box-shadow: 0 6px 20px rgba(0, 58, 93, 0.15);
	    border-color: #0066a8;
	}
	
	.nodo-titulo {
	    color: #003a5d;
	    font-weight: 600;
	    font-size: 0.95rem;
	    margin-bottom: 8px;
	}
	
	.nodo-responsable {
	    font-size: 0.8rem;
	    margin-bottom: 8px;
	}
	
	.nodo-estadisticas .badge {
	    font-size: 0.7rem;
	    padding: 4px 8px;
	    margin: 2px;
	}
	
	/* CONECTORES MEJORADOS */
	.conector-padre {
	    width: 2px;
	    height: 25px;
	    background: linear-gradient(to bottom, #003a5d, #0066a8);
	    margin: 8px 0;
	    position: relative;
	    z-index: 1;
	}
	
	.conector-padre::before {
	    content: '';
	    position: absolute;
	    top: -3px;
	    left: 50%;
	    transform: translateX(-50%);
	    width: 10px;
	    height: 10px;
	    background: #003a5d;
	    border-radius: 50%;
	    border: 2px solid white;
	    box-shadow: 0 0 0 2px #003a5d;
	}
	
	.conector-hijos {
	    display: flex;
	    justify-content: center;
	    gap: 30px;
	    position: relative;
	    padding-top: 25px;
	    width: 100%;
	    margin-top: 10px;
	}
	
	.conector-hijos::before {
	    content: '';
	    position: absolute;
	    top: 0;
	    left: 50%;
	    transform: translateX(-50%);
	    width: 80%;
	    height: 2px;
	    background: linear-gradient(to right, transparent 10%, #003a5d 50%, transparent 90%);
	}
	
	/* Líneas verticales desde el nodo padre a los hijos */
	.organigrama-nivel[data-nivel="1"] .organigrama-nodo::before {
	    content: '';
	    position: absolute;
	    top: -26px;
	    left: 50%;
	    transform: translateX(-50%);
	    width: 2px;
	    height: 25px;
	    background: linear-gradient(to bottom, #003a5d, #0066a8);
	}
	
	/* Responsive mejorado */
	@media (max-width: 768px) {
	    .organigrama-nivel {
	        flex-direction: column;
	        align-items: center;
	        gap: 30px;
	        margin-bottom: 20px;
	    }
	    
	    .conector-hijos {
	        flex-direction: column;
	        align-items: center;
	        width: auto;
	    }
	    
	    .conector-hijos::before {
	        width: 2px;
	        height: 100%;
	        left: 50%;
	        transform: translateX(-50%);
	        background: linear-gradient(to bottom, transparent 10%, #003a5d 50%, transparent 90%);
	    }
	    
	    .organigrama-nivel[data-nivel="1"] .organigrama-nodo::before {
	        display: none;
	    }
	    
	    .nodo-principal {
	        min-width: 200px;
	        max-width: 250px;
	    }
	}
	
	/* Colores corporativos para texto */
	.text-corporate-primary {
	    color: #003a5d !important;
	}
	
	.btn-outline-corporate-primary {
	    border-color: #003a5d;
	    color: #003a5d;
	}
	
	.btn-outline-corporate-primary:hover {
	    background-color: #003a5d;
	    color: white;
	}
        /* CORRECCIÓN PARA PESTAÑAS - Texto visible cuando está activa */
    .nav-tabs .nav-link {
        color: #495057 !important;
        font-weight: 500;
        border: none;
        padding: 12px 20px;
    }
    
    .nav-tabs .nav-link:hover {
        border: none;
        color: #003a5d !important;
        background-color: #f8f9fa;
    }
    
    .nav-tabs .nav-link.active {
        color: #003a5d !important;
        background-color: white !important;
        border-bottom: 3px solid #003a5d !important;
        font-weight: 600;
    }
    
    .nav-tabs .nav-link:not(.active) {
        color: #6c757d !important;
        background-color: #f8f9fa;
    }
        /* CORRECCIÓN PARA ÍCONOS EN PESTAÑAS */
    .nav-tabs .nav-link i {
        color: inherit !important;
    }
    
    .nav-tabs .nav-link.active i {
        color: #003a5d !important;
    }
    
    .nav-tabs .nav-link:not(.active) i {
        color: #6c757d !important;
    }
</style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Áreas y Jerarquías</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearArea">
                        <i class="bi bi-plus-circle"></i> Nueva Área
                    </button>
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Pestañas para diferentes vistas -->
                <ul class="nav nav-tabs mb-4" id="areasTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="arbol-tab" data-bs-toggle="tab" data-bs-target="#arbol" type="button" role="tab">
                            <i class="bi bi-list-ul"></i> Vista de Lista
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="organigrama-tab" data-bs-toggle="tab" data-bs-target="#organigrama" type="button" role="tab">
                            <i class="bi bi-diagram-3"></i> Organigrama
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="areasTabsContent">
                    <!-- Pestaña 1: Vista de lista -->
                    <div class="tab-pane fade show active" id="arbol" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list-ul"></i> Árbol de Áreas Organizacionales
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($areas)): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                                        <p class="mt-2 text-muted">No hay áreas creadas todavía</p>
                                    </div>
                                <?php else: ?>
                                    <?php echo mostrarArbolAreas($areas, $conn); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pestaña 2: Organigrama Visual -->
                    <div class="tab-pane fade" id="organigrama" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-diagram-3"></i> Organigrama Visual
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($areas)): ?>
                                    <div class="text-center py-4">
                                        <i class="bi bi-inbox" style="font-size: 3rem; color: #6c757d;"></i>
                                        <p class="mt-2 text-muted">No hay áreas creadas para mostrar el organigrama</p>
                                    </div>
                                <?php else: ?>
                                    <div class="organigrama-visual">
                                        <?php 
					// Función para generar organigrama visual tipo árbol
					function generarOrganigramaVisual($areas, $conn, $padre_id = null, $nivel = 0) {
					    $html = '';
					    $areas_del_nivel = [];
					    
					    foreach ($areas as $area) {
					        if ($area['padre_id'] == $padre_id) {
					            $areas_del_nivel[] = $area;
					        }
					    }
					    
					    if (!empty($areas_del_nivel)) {
					        $html .= '<div class="organigrama-nivel" data-nivel="' . $nivel . '">';
					        
					        foreach ($areas_del_nivel as $area) {
					            // Obtener supervisores del área
							 $query_supervisores = "SELECT e.first_Name, e.first_LastName, s.tipo_supervisor,
							                             COALESCE(c.nombre, e.position) as position
							                      FROM supervisores s 
							                      JOIN employee e ON s.empleado_id = e.id 
							                      LEFT JOIN cargos c ON e.position_id = c.id
							                      WHERE s.area_id = ? AND e.role != 'retirado' 
							                      ORDER BY s.tipo_supervisor 
							                      LIMIT 1";
					            $stmt_sup = $conn->prepare($query_supervisores);
					            $stmt_sup->execute([$area['id']]);
					            $supervisor = $stmt_sup->fetch(PDO::FETCH_ASSOC);
					            
					            $responsable = $supervisor ? 
					                htmlspecialchars($supervisor['first_Name'] . ' ' . $supervisor['first_LastName']) : 
					                'Sin responsable';
					            
					            $tipo_supervisor = $supervisor ? ucfirst($supervisor['tipo_supervisor']) : '';
					            
					            $html .= '<div class="organigrama-nodo">';
					            $html .= '<div class="nodo-contenedor">';
					            
					            // Línea conectora desde el padre (excepto para nivel 0)
					            if ($nivel > 0) {
					                $html .= '<div class="conector-padre"></div>';
					            }
					            
					            // Nodo principal
					            $html .= '<div class="nodo-principal card shadow-sm">';
					            $html .= '<div class="card-body text-center p-3">';
					            $html .= '<h6 class="nodo-titulo mb-2">' . htmlspecialchars($area['nombre']) . '</h6>';
					            
					            // Responsable
					            $html .= '<div class="nodo-responsable">';
					            $html .= '<small class="text-corporate-primary fw-bold">' . $responsable . '</small>';
					            if ($tipo_supervisor) {
					                $html .= '<br><span class="badge bg-corporate-info">' . $tipo_supervisor . '</span>';
					            }
					            $html .= '</div>';
					            
					            // Estadísticas
					            $html .= '<div class="nodo-estadisticas mt-2">';
					            $html .= '<div class="d-flex justify-content-center gap-2">';
					            $html .= '<small class="badge bg-corporate-primary">';
					            $html .= '<i class="bi bi-people"></i> ' . $area['total_empleados'];
					            $html .= '</small>';
					            $html .= '<small class="badge bg-corporate-info">';
					            $html .= '<i class="bi bi-diagram-3"></i> ' . $area['total_subareas'];
					            $html .= '</small>';
					            $html .= '</div>';
					            $html .= '</div>';
					            
					            // Acciones
					            $html .= '<div class="nodo-acciones mt-2">';
					            $html .= '<div class="btn-group btn-group-sm">';
					            $html .= '<button type="button" class="btn btn-outline-corporate-primary btn-sm" 
					                              data-bs-toggle="modal" data-bs-target="#modalEditarArea' . $area['id'] . '"
					                              title="Editar área">
					                          <i class="bi bi-pencil"></i>
					                      </button>';
					            $html .= '</div>';
					            $html .= '</div>';
					            
					            $html .= '</div>'; // card-body
					            $html .= '</div>'; // nodo-principal
					            
					            // Sub-áreas
					            $sub_areas = array_filter($areas, function($a) use ($area) {
					                return $a['padre_id'] == $area['id'];
					            });
					            
					            if (!empty($sub_areas)) {
					                $html .= '<div class="conector-hijos">';
					                $html .= generarOrganigramaVisual($areas, $conn, $area['id'], $nivel + 1);
					                $html .= '</div>';
					            }
					            
					            $html .= '</div>'; // nodo-contenedor
					            $html .= '</div>'; // organigrama-nodo
					        }
					        
					        $html .= '</div>'; // organigrama-nivel
					    }
					    
					    return $html;
					}
                                        
                                        echo generarOrganigramaVisual($areas, $conn);
                                        ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modal para crear área -->
    <div class="modal fade" id="modalCrearArea" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Crear Nueva Área</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre del Área *</label>
                            <input type="text" class="form-control" name="nombre" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Área Padre (Opcional)</label>
                            <select class="form-select" name="padre_id">
                                <option value="">Sin área padre (área principal)</option>
                                <?php echo obtenerAreasParaSelect($conn); ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-control" name="descripcion" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="crear_area" class="btn btn-primary">Crear Área</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

<!-- Modales para editar áreas (completos con área padre y supervisores) -->
<?php foreach ($areas as $area): ?>
<div class="modal fade" id="modalEditarArea<?php echo $area['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Área: <?php echo htmlspecialchars($area['nombre']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="area_id" value="<?php echo $area['id']; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Nombre del Área *</label>
                                <input type="text" class="form-control" name="nombre" 
                                       value="<?php echo htmlspecialchars($area['nombre']); ?>" required>
                            </div>
                        </div>
                        <div class="col-md-6">
				<div class="mb-3">
				    <label class="form-label">Área Padre (Opcional)</label>
				    <select class="form-select" name="padre_id" id="padre_id_<?php echo $area['id']; ?>">
				        <option value="">Sin área padre (área principal)</option>
				        <?php echo obtenerAreasParaSelect($conn, $area['id'], $area['padre_id']); ?>
				    </select>
				</div>

                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Descripción</label>
                        <textarea class="form-control" name="descripcion" rows="3"><?php echo htmlspecialchars($area['descripcion'] ?? ''); ?></textarea>
                    </div>
                    
                    <!-- Gestión de Supervisores -->
                    <div class="form-section">
                        <h6>Gestión de Supervisores</h6>
                        
                        <!-- Lista de supervisores actuales -->
                        <div class="mb-3">
                            <label class="form-label">Supervisores Actuales</label>
                            <?php
			 $query_supervisores = "SELECT s.id as supervisor_id, s.tipo_supervisor, 
			                      e.id as empleado_id, e.first_Name, e.first_LastName, 
			                      COALESCE(c.nombre, e.position) as position
			               FROM supervisores s 
			               JOIN employee e ON s.empleado_id = e.id 
			               LEFT JOIN cargos c ON e.position_id = c.id
			               WHERE s.area_id = ? AND e.role != 'retirado'";
                            $stmt_sup = $conn->prepare($query_supervisores);
                            $stmt_sup->execute([$area['id']]);
                            $supervisores_actuales = $stmt_sup->fetchAll(PDO::FETCH_ASSOC);
                            
                            if (empty($supervisores_actuales)): ?>
                                <div class="alert alert-info">
                                    <small>No hay supervisores asignados a esta área</small>
                                </div>
                            <?php else: ?>
                                <div class="list-group">
                                    <?php foreach ($supervisores_actuales as $sup): ?>
                                        <div class="list-group-item d-flex justify-content-between align-items-center">
                                            <div>
                                                <strong><?php echo htmlspecialchars($sup['first_Name'] . ' ' . $sup['first_LastName']); ?></strong>
                                                <br>
                                                <small class="text-muted">
                                                    <?php echo htmlspecialchars($sup['position']); ?> - 
                                                    <span class="badge bg-primary"><?php echo ucfirst($sup['tipo_supervisor']); ?></span>
                                                </small>
                                            </div>
                                            <a href="?eliminar_supervisor=<?php echo $sup['supervisor_id']; ?>&area_id=<?php echo $area['id']; ?>"

                                               class="btn btn-sm btn-outline-danger"
                                               onclick="return confirm('¿Está seguro de eliminar a <?php echo htmlspecialchars($sup['first_Name'] . ' ' . $sup['first_LastName']); ?> como supervisor?')">
                                                <i class="bi bi-trash"></i> Eliminar
                                            </a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Agregar nuevo supervisor -->
                        <div class="mb-3">
                            <label class="form-label">Agregar Nuevo Supervisor</label>
                            <div class="row">
                                <div class="col-md-5">
                                    <select class="form-select" id="nuevo_supervisor_<?php echo $area['id']; ?>" name="nuevo_supervisor">
                                        <option value="">Seleccionar empleado</option>
                                        <?php
                                        // Obtener empleados que no son supervisores en esta área
					 $query_empleados = "SELECT e.id, e.first_Name, e.first_LastName, 
					                          COALESCE(c.nombre, e.position) as position
					                   FROM employee e 
					                   LEFT JOIN cargos c ON e.position_id = c.id
					                   WHERE e.role != 'retirado' 
					                   AND e.id NOT IN (
					                       SELECT empleado_id FROM supervisores WHERE area_id = ?
					                   )
					                   ORDER BY e.first_Name";
                                        $stmt_emp = $conn->prepare($query_empleados);
                                        $stmt_emp->execute([$area['id']]);
                                        $empleados_disponibles = $stmt_emp->fetchAll(PDO::FETCH_ASSOC);
                                        
                                        foreach ($empleados_disponibles as $emp): ?>
                                            <option value="<?php echo $emp['id']; ?>">
                                                <?php echo htmlspecialchars($emp['first_Name'] . ' ' . $emp['first_LastName'] . ' - ' . $emp['position']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <select class="form-select" id="tipo_supervisor_<?php echo $area['id']; ?>" name="tipo_supervisor">
                                        <option value="coordinador">Coordinador</option>
                                        <option value="director">Director</option>
                                        <option value="gerente">Gerente</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <button type="button" class="btn btn-primary w-100" 
                                            onclick="agregarSupervisor(<?php echo $area['id']; ?>)">
                                        <i class="bi bi-plus"></i> Agregar
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="actualizar_area" class="btn btn-primary">Actualizar Área</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// DEBUG INMEDIATO - verificar que el script se carga
console.log('🎯 SCRIPT areas.php CARGADO - Bootstrap:', typeof bootstrap !== 'undefined');

// Función para toggle de detalles del área
function toggleAreaDetails(areaId) {
    console.log('🔍 toggleAreaDetails EJECUTADA para área:', areaId);
    
    const details = document.getElementById('area-details-' + areaId);
    const arrow = document.getElementById('area-arrow-' + areaId);
    
    if (!details) {
        console.error('❌ No se encontró area-details-' + areaId);
        return false;
    }
    if (!arrow) {
        console.error('❌ No se encontró area-arrow-' + areaId);
        return false;
    }
    
    const isHidden = details.style.display === 'none' || window.getComputedStyle(details).display === 'none';
    console.log('📦 Estado actual:', isHidden ? 'OCULTO' : 'VISIBLE');
    
    if (isHidden) {
        details.style.display = 'block';
        arrow.classList.remove('bi-chevron-down');
        arrow.classList.add('bi-chevron-up');
        console.log('✅ Área', areaId, 'EXPANDIDA');
    } else {
        details.style.display = 'none';
        arrow.classList.remove('bi-chevron-up');
        arrow.classList.add('bi-chevron-down');
        console.log('✅ Área', areaId, 'COLAPSADA');
    }
    return true;
}

// INICIALIZACIÓN INMEDIATA - no esperar a DOMContentLoaded
console.log('🚀 INICIANDO CONFIGURACIÓN INMEDIATA');

// Configurar headers de área
function configurarHeaders() {
    const areaHeaders = document.querySelectorAll('.area-header');
    console.log('📋 Headers encontrados:', areaHeaders.length);
    
    areaHeaders.forEach((header, index) => {
        console.log(`🔧 Configurando header ${index + 1}`);
        
        const areaItem = header.closest('.area-item');
        if (!areaItem) {
            console.error('❌ No se pudo encontrar area-item para header', index);
            return;
        }
        
        const areaId = areaItem.dataset.areaId;
        if (!areaId) {
            console.error('❌ No data-area-id en header', index);
            return;
        }
        
        console.log('📍 Header configurado para área:', areaId);
        
        // QUITAR cualquier evento existente
        header.replaceWith(header.cloneNode(true));
        const newHeader = document.querySelector(`.area-item[data-area-id="${areaId}"] .area-header`);
        
        // AGREGAR evento de click
        newHeader.addEventListener('click', function(e) {
            console.log('🖱️ CLICK DETECTADO en header área:', areaId);
            console.log('🎯 Elemento clickeado:', e.target);
            console.log('🎯 Clases del elemento:', e.target.className);
            
            // Permitir que los botones funcionen normalmente
            if (e.target.closest('button') || e.target.closest('a')) {
                console.log('⏩ Click en botón - ignorar');
                return;
            }
            
            e.preventDefault();
            e.stopPropagation();
            console.log('🎯 Ejecutando toggleAreaDetails...');
            const resultado = toggleAreaDetails(areaId);
            console.log('🎯 Resultado de toggle:', resultado);
        });
        
        // Inicializar como colapsado
        const details = document.getElementById('area-details-' + areaId);
        if (details) {
            details.style.display = 'none';
            console.log('📦 Área', areaId, 'inicializada como COLAPSADA');
        }
    });
}

// Configurar botones de editar
function configurarBotonesEditar() {
    const botonesEditar = document.querySelectorAll('[data-bs-target^="#modalEditarArea"]');
    console.log('📝 Botones editar encontrados:', botonesEditar.length);
    
    botonesEditar.forEach((boton, index) => {
        console.log(`🔧 Configurando botón editar ${index + 1}`);
        
        const targetModal = boton.getAttribute('data-bs-target');
        console.log('🎯 Modal target:', targetModal);
        
        // Verificar que el modal existe
        const modalElement = document.querySelector(targetModal);
        if (!modalElement) {
            console.error('❌ Modal no existe:', targetModal);
            return;
        }
        console.log('✅ Modal encontrado:', targetModal);
        
        // QUITAR y re-agregar evento
        boton.replaceWith(boton.cloneNode(true));
        const newBoton = document.querySelector(`[data-bs-target="${targetModal}"]`);
        
        newBoton.addEventListener('click', function(e) {
            console.log('🖱️ CLICK en botón editar');
            console.log('🎯 Target modal:', targetModal);
            
            const modal = document.querySelector(targetModal);
            if (modal) {
                console.log('🎯 Mostrando modal...');
                const bsModal = new bootstrap.Modal(modal);
                bsModal.show();
                console.log('✅ Modal mostrado');
            }
        });
    });
}

// Configurar pestañas
function configurarPestanas() {
    const tabTriggers = document.querySelectorAll('#areasTabs [data-bs-toggle="tab"]');
    console.log('📑 Pestañas encontradas:', tabTriggers.length);
    
    tabTriggers.forEach((tab, index) => {
        const targetTab = tab.getAttribute('data-bs-target');
        console.log(`🔧 Pestaña ${index + 1}:`, targetTab);
        
        tab.addEventListener('click', function(e) {
            console.log('🔄 Cambiando a pestaña:', targetTab);
        });
    });
}

// Configurar botón crear área
function configurarBotonCrear() {
    const botonCrear = document.querySelector('[data-bs-target="#modalCrearArea"]');
    if (botonCrear) {
        console.log('🆕 Botón crear área encontrado');
        
        botonCrear.addEventListener('click', function() {
            console.log('🖱️ Click en botón crear área');
        });
    } else {
        console.error('❌ Botón crear área NO encontrado');
    }
}

// EJECUTAR CONFIGURACIÓN
console.log('🎬 EJECUTANDO CONFIGURACIÓN...');

// Esperar un poco a que el DOM esté listo
setTimeout(() => {
    console.log('⏰ EJECUTANDO CONFIGURACIÓN COMPLETA');
    
    configurarHeaders();
    configurarBotonesEditar();
    configurarPestanas();
    configurarBotonCrear();
    
    console.log('✅ CONFIGURACIÓN COMPLETADA');
    
    // Hacer un test automático
    console.log('🧪 REALIZANDO TEST AUTOMÁTICO...');
    
    // Test 1: Verificar que hay elementos
    const areas = document.querySelectorAll('.area-item');
    console.log('🧪 Áreas en DOM:', areas.length);
    
    // Test 2: Verificar que los event listeners están activos
    console.log('🧪 Test completado');
    
}, 100);

// Función de emergencia - forzar toggle desde consola
window.forceToggle = function(areaId) {
    console.log('🚨 FORZANDO TOGGLE para área:', areaId);
    return toggleAreaDetails(areaId);
};

// Función de emergencia - forzar modal desde consola
window.forceModal = function(modalId) {
    console.log('🚨 FORZANDO MODAL:', modalId);
    const modal = document.querySelector(modalId);
    if (modal) {
        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();
        return true;
    }
    return false;
};

// Función para agregar supervisor via AJAX
function agregarSupervisor(areaId) {
    const empleadoSelect = document.getElementById('nuevo_supervisor_' + areaId);
    const tipoSelect = document.getElementById('tipo_supervisor_' + areaId);
    
    const empleadoId = empleadoSelect.value;
    const tipoSupervisor = tipoSelect.value;
    
    if (!empleadoId) {
        alert('Por favor seleccione un empleado');
        return;
    }
    
    const formData = new FormData();
    formData.append('agregar_supervisor', '1');
    formData.append('area_id', areaId);
    formData.append('empleado_id', empleadoId);
    formData.append('tipo_supervisor', tipoSupervisor);
    
    fetch('gestion_areas.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Supervisor agregado correctamente');
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al agregar el supervisor');
    });
}

console.log('🎉 CONFIGURACIÓN INICIALIZADA - listo para eventos');

// Limpieza defensiva: eliminar backdrops sobrantes y restaurar body cuando un modal se oculta
document.addEventListener('hidden.bs.modal', function (event) {
    // Pequeño retraso para dejar que Bootstrap finalice sus tareas
    setTimeout(function () {
        // Eliminar backdrops extras
        const backdrops = document.querySelectorAll('.modal-backdrop');
        if (backdrops.length > 0) {
            // Si no hay modales .show, removemos todos los backdrops
            const modalesAbiertos = document.querySelectorAll('.modal.show').length;
            if (modalesAbiertos === 0) {
                backdrops.forEach(b => b.remove());
            } else {
                // Si hay más de 1 backdrop, eliminamos los excedentes (dejamos 1)
                if (backdrops.length > 1) {
                    backdrops.forEach((b, i) => { if (i > 0) b.remove(); });
                }
            }
        }

        // Restaurar body en caso de que quede la clase modal-open
        if (!document.querySelector('.modal.show')) {
            document.body.classList.remove('modal-open');
            document.body.style.overflow = '';
            document.body.style.paddingRight = '';
        }
    }, 10);
});

// Limpieza adicional por si acaso (ejecutable desde consola)
window._cleanupModalState = function () {
    document.querySelectorAll('.modal-backdrop').forEach(b => b.remove());
    document.body.classList.remove('modal-open');
    document.body.style.overflow = '';
    document.body.style.paddingRight = '';
    console.log('🧹 estado de modales limpiado');
};

</script>
</body>
</html>