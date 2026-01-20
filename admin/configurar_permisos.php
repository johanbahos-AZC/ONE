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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'accesos', 'ver')) {
    header("Location: portal.php");
    exit();
}

 $error = '';
 $success = '';

// Función para eliminar todos los permisos de un rol
function eliminarPermisosRol($conn, $rol) {
    $query = "DELETE FROM permisos_roles WHERE rol = ?";
    $stmt = $conn->prepare($query);
    return $stmt->execute([$rol]);
}

// Procesar formulario de recursos
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion'])) {
    try {
        if ($_POST['accion'] == 'agregar_recurso') {
            $nombre = trim($_POST['nombre']);
            $descripcion = trim($_POST['descripcion']);
            $ruta = trim($_POST['ruta']);
            $categoria = trim($_POST['categoria']);
            
            if (!empty($nombre) && !empty($ruta)) {
                $query = "INSERT INTO recursos (nombre, descripcion, ruta, categoria) VALUES (?, ?, ?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->execute([$nombre, $descripcion, $ruta, $categoria]);
                
                $success = "Recurso agregado correctamente";
            } else {
                $error = "El nombre y la ruta son obligatorios";
            }
        } 
        elseif ($_POST['accion'] == 'agregar_accion') {
            $nombre = trim($_POST['nombre']);
            $descripcion = trim($_POST['descripcion']);
            $recurso_id = $_POST['recurso_id'];
            
            if (!empty($nombre) && !empty($recurso_id)) {
                $query = "INSERT INTO acciones (nombre, descripcion, recurso_id) VALUES (?, ?, ?)";
                $stmt = $conn->prepare($query);
                $stmt->execute([$nombre, $descripcion, $recurso_id]);
                
                $success = "Acción agregada correctamente";
            } else {
                $error = "El nombre y el recurso son obligatorios";
            }
        }
	elseif ($_POST['accion'] == 'guardar_permisos_roles') {
	    // Procesar todos los permisos de roles enviados
	    if (isset($_POST['permisos_roles'])) {
	        foreach ($_POST['permisos_roles'] as $rol => $recursos) {
	            // Eliminar todos los permisos existentes para este rol
	            eliminarPermisosRol($conn, $rol);
	            
	            // Insertar los nuevos permisos
	            foreach ($recursos as $recurso_id => $acciones) {
	                foreach ($acciones as $accion_id => $permitido) {
	                    $query = "INSERT INTO permisos_roles (rol, recurso_id, accion_id, permitido) 
	                              VALUES (?, ?, ?, ?)";
	                    $stmt = $conn->prepare($query);
	                    $stmt->execute([$rol, $recurso_id, $accion_id, $permitido]);
	                }
	            }
	        }
	        $success = "Permisos de roles actualizados correctamente";
	    }
	}
        elseif ($_POST['accion'] == 'guardar_permisos_cargos') {
            // Procesar todos los permisos de cargos enviados
            if (isset($_POST['permisos_cargos'])) {
                foreach ($_POST['permisos_cargos'] as $cargo_id => $recursos) {
                    foreach ($recursos as $recurso_id => $acciones) {
                        foreach ($acciones as $accion_id => $permitido) {
                            $query = "INSERT INTO permisos_cargos (cargo_id, recurso_id, accion_id, permitido) 
                                      VALUES (?, ?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE permitido = ?";
                            $stmt = $conn->prepare($query);
                            $stmt->execute([$cargo_id, $recurso_id, $accion_id, $permitido, $permitido]);
                        }
                    }
                }
                $success = "Permisos de cargos actualizados correctamente";
            }
        }
        elseif ($_POST['accion'] == 'eliminar_recurso') {
            $recurso_id = $_POST['recurso_id'];
            
            // Verificar que no haya permisos asociados
            $query_check = "SELECT COUNT(*) as total FROM permisos_roles WHERE recurso_id = ?";
            $stmt_check = $conn->prepare($query_check);
            $stmt_check->execute([$recurso_id]);
            $total_permisos = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($total_permisos == 0) {
                $query = "DELETE FROM recursos WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->execute([$recurso_id]);
                
                $success = "Recurso eliminado correctamente";
            } else {
                $error = "No se puede eliminar el recurso porque tiene permisos asociados";
            }
        }
        elseif ($_POST['accion'] == 'eliminar_accion') {
            $accion_id = $_POST['accion_id'];
            
            // Verificar que no haya permisos asociados
            $query_check = "SELECT COUNT(*) as total FROM permisos_roles WHERE accion_id = ?";
            $stmt_check = $conn->prepare($query_check);
            $stmt_check->execute([$accion_id]);
            $total_permisos = $stmt_check->fetch(PDO::FETCH_ASSOC)['total'];
            
            if ($total_permisos == 0) {
                $query = "DELETE FROM acciones WHERE id = ?";
                $stmt = $conn->prepare($query);
                $stmt->execute([$accion_id]);
                
                $success = "Acción eliminada correctamente";
            } else {
                $error = "No se puede eliminar la acción porque tiene permisos asociados";
            }
        }
    } catch (PDOException $e) {
        $error = "Error: " . $e->getMessage();
    }
}

// Obtener recursos
 $query_recursos = "SELECT * FROM recursos ORDER BY categoria, nombre";
 $stmt_recursos = $conn->prepare($query_recursos);
 $stmt_recursos->execute();
 $recursos = $stmt_recursos->fetchAll(PDO::FETCH_ASSOC);

// Obtener acciones
 $query_acciones = "SELECT a.*, r.nombre as recurso_nombre, r.categoria 
                 FROM acciones a 
                 JOIN recursos r ON a.recurso_id = r.id 
                 ORDER BY r.categoria, r.nombre, a.nombre";
 $stmt_acciones = $conn->prepare($query_acciones);
 $stmt_acciones->execute();
 $acciones = $stmt_acciones->fetchAll(PDO::FETCH_ASSOC);

// Obtener roles
 $roles = ['administrador', 'it', 'nomina', 'talento_humano', 'empleado', 'retirado'];

// Obtener cargos
 $query_cargos = "SELECT id, nombre FROM cargos ORDER BY nombre";
 $stmt_cargos = $conn->prepare($query_cargos);
 $stmt_cargos->execute();
 $cargos = $stmt_cargos->fetchAll(PDO::FETCH_ASSOC);

// Obtener permisos de roles
 $query_permisos_roles = "SELECT * FROM permisos_roles";
 $stmt_permisos_roles = $conn->prepare($query_permisos_roles);
 $stmt_permisos_roles->execute();
 $permisos_roles = $stmt_permisos_roles->fetchAll(PDO::FETCH_ASSOC);

// Obtener permisos de cargos
 $query_permisos_cargos = "SELECT * FROM permisos_cargos";
 $stmt_permisos_cargos = $conn->prepare($query_permisos_cargos);
 $stmt_permisos_cargos->execute();
 $permisos_cargos = $stmt_permisos_cargos->fetchAll(PDO::FETCH_ASSOC);

// Función para verificar si un permiso existe
function permisoExiste($permisos, $rol, $recurso_id, $accion_id) {
    foreach ($permisos as $permiso) {
        if ($permiso['rol'] == $rol && $permiso['recurso_id'] == $recurso_id && $permiso['accion_id'] == $accion_id) {
            return $permiso['permitido'];
        }
    }
    return null;
}

// Función para verificar si un permiso de cargo existe
function permisoCargoExiste($permisos, $cargo_id, $recurso_id, $accion_id) {
    foreach ($permisos as $permiso) {
        if ($permiso['cargo_id'] == $cargo_id && $permiso['recurso_id'] == $recurso_id && $permiso['accion_id'] == $accion_id) {
            return $permiso['permitido'];
        }
    }
    return null;
}

// Agrupar acciones por recurso para mejor visualización
 $acciones_por_recurso = [];
foreach ($acciones as $accion) {
    if (!isset($acciones_por_recurso[$accion['recurso_id']])) {
        $acciones_por_recurso[$accion['recurso_id']] = [
            'recurso_nombre' => $accion['recurso_nombre'],
            'categoria' => $accion['categoria'],
            'acciones' => []
        ];
    }
    $acciones_por_recurso[$accion['recurso_id']]['acciones'][] = $accion;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración de Permisos - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
        .permiso-cell {
            text-align: center;
            vertical-align: middle;
        }
        
        .form-check-input:checked {
            background-color: #003a5d;
            border-color: #003a5d;
        }
        
        /* Contenedor principal para las tablas */
        .table-wrapper {
            position: relative;
            height: calc(100vh - 350px);
            overflow: hidden;
            border: 1px solid #dee2e6;
            border-radius: 0.25rem;
        }
        
        /* Contenedor con scroll para la tabla */
        .table-scroll {
            height: 100%;
            overflow: auto;
        }
        
        /* Estilos para la tabla */
        .table-permisos {
            margin-bottom: 0;
            border-collapse: separate;
            border-spacing: 0;
        }
        
        /* Encabezado pegajoso */
        .sticky-header {
            position: sticky;
            top: 0;
            background-color: white;
            z-index: 10;
        }
        
        /* Columna de recurso congelada */
        .sticky-col-recurso {
            position: sticky;
            left: 0;
            background-color: white;
            z-index: 5;
            border-right: 2px solid #dee2e6;
            min-width: 200px;
        }
        
        /* Columna de acción congelada */
        .sticky-col-accion {
            position: sticky;
            left: 200px;
            background-color: white;
            z-index: 4;
            border-right: 2px solid #dee2e6;
            min-width: 150px;
        }
        
        /* Fila de categoría congelada - SOLUCIÓN DEFINITIVA */
        .categoria-header {
            background-color: #f8f9fa;
            font-weight: bold;
            position: sticky;
            left: 0;
            z-index: 7;
        }
        
        /* Celda de categoría que ocupa todas las columnas */
        .categoria-header td {
            position: sticky;
            left: 0;
            background-color: #f8f9fa;
            z-index: 7;
            border-right: 2px solid #dee2e6;
        }
        
        /* Sombra para las columnas congeladas */
        .sticky-col-recurso {
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        
        .sticky-col-accion {
            box-shadow: 2px 0 5px rgba(0,0,0,0.1);
        }
        
        /* Estilos para las pestañas activas - CORREGIDO */
        .nav-tabs .nav-link.active {
            font-weight: bold;
            color: #003a5d !important;
            background-color: #f8f9fa !important;
            border-color: #dee2e6 #dee2e6 #f8f9fa !important;
        }
        
        /* Íconos en pestañas activas */
        .nav-tabs .nav-link.active i {
            color: #003a5d !important;
        }
        
        .btn-save-container {
            position: sticky;
            bottom: 0;
            background-color: white;
            padding: 15px;
            border-top: 1px solid #dee2e6;
            text-align: right;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            z-index: 20;
        }
        
        .card-header {
            background-color: #f8f9fa;
        }
        
        .tab-content {
            min-height: calc(100vh - 200px);
        }
        
        /* Ajustes para filas con rowspan */
        .table-permisos td {
            vertical-align: middle;
        }
        
        /* Evitar solapamiento de bordes */
        .table-permisos th, .table-permisos td {
            border: 1px solid #dee2e6;
        }
        
        .table-permisos thead th {
            border-bottom: 2px solid #dee2e6;
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
                    <h1 class="h2">Configuración de Permisos</h1>
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Pestañas para diferentes configuraciones -->
                <ul class="nav nav-tabs" id="permisosTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="recursos-tab" data-bs-toggle="tab" data-bs-target="#recursos" type="button" role="tab">
                            <i class="bi bi-grid-3x3-gap me-2"></i>Recursos y Acciones
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="roles-tab" data-bs-toggle="tab" data-bs-target="#roles" type="button" role="tab">
                            <i class="bi bi-shield-lock me-2"></i>Permisos por Rol
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="cargos-tab" data-bs-toggle="tab" data-bs-target="#cargos" type="button" role="tab">
                            <i class="bi bi-briefcase me-2"></i>Permisos por Cargo
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="permisosTabsContent">
                    <!-- Tab de Recursos y Acciones -->
                    <div class="tab-pane fade show active" id="recursos" role="tabpanel">
                        <!-- Formulario para agregar recurso -->
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title"><i class="bi bi-plus-circle me-2"></i>Agregar Recurso</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <input type="hidden" name="accion" value="agregar_recurso">
                                    <div class="row">
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="nombre" class="form-label">Nombre</label>
                                                <input type="text" class="form-control" id="nombre" name="nombre" required>
                                            </div>
                                        </div>
                                        <div class="col-md-3">
                                            <div class="mb-3">
                                                <label for="ruta" class="form-label">Ruta</label>
                                                <input type="text" class="form-control" id="ruta" name="ruta" required>
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="mb-3">
                                                <label for="categoria" class="form-label">Categoría</label>
                                                <input type="text" class="form-control" id="categoria" name="categoria">
                                            </div>
                                        </div>
                                        <div class="col-md-2">
                                            <div class="mb-3">
                                                <label class="form-label">&nbsp;</label>
                                                <button type="submit" class="btn btn-primary w-100">
                                                    <i class="bi bi-plus-lg me-1"></i>Agregar
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row">
                                        <div class="col-md-12">
                                            <div class="mb-3">
                                                <label for="descripcion" class="form-label">Descripción</label>
                                                <textarea class="form-control" id="descripcion" name="descripcion" rows="2"></textarea>
                                            </div>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                        
                        <!-- Lista de recursos -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title"><i class="bi bi-list-ul me-2"></i>Recursos Existentes</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead class="sticky-header">
                                            <tr>
                                                <th>Nombre</th>
                                                <th>Ruta</th>
                                                <th>Categoría</th>
                                                <th>Acciones</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($recursos as $recurso): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($recurso['nombre']); ?></td>
                                                <td><?php echo htmlspecialchars($recurso['ruta']); ?></td>
                                                <td><?php echo htmlspecialchars($recurso['categoria']); ?></td>
                                                <td>
                                                    <button type="button" class="btn btn-sm btn-outline-primary" 
                                                            data-bs-toggle="modal" 
                                                            data-bs-target="#modalAcciones<?php echo $recurso['id']; ?>">
                                                        <i class="bi bi-list"></i> Acciones
                                                    </button>
                                                    <button type="button" class="btn btn-sm btn-outline-danger" 
                                                            onclick="confirmarEliminarRecurso(<?php echo $recurso['id']; ?>)">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tab de Permisos por Rol -->
                    <div class="tab-pane fade" id="roles" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title"><i class="bi bi-shield-lock me-2"></i>Permisos por Rol</h5>
                            </div>
                            <div class="card-body p-0">
                                <form id="form-permisos-roles" method="POST" action="">
                                    <input type="hidden" name="accion" value="guardar_permisos_roles">
                                    <div class="table-wrapper">
                                        <div class="table-scroll">
                                            <table class="table table-striped table-sm table-permisos">
                                                <thead class="sticky-header">
                                                    <tr>
                                                        <th class="sticky-col-recurso">Recurso</th>
                                                        <th class="sticky-col-accion">Acción</th>
                                                        <?php foreach ($roles as $rol): ?>
                                                        <th style="min-width: 100px;"><?php echo ucfirst($rol); ?></th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $categoria_actual = '';
                                                    foreach ($acciones_por_recurso as $recurso_id => $recurso_data): 
                                                        // Mostrar encabezado de categoría si cambia
                                                        if ($categoria_actual != $recurso_data['categoria']) {
                                                            $categoria_actual = $recurso_data['categoria'];
                                                    ?>
                                                    <tr class="categoria-header">
                                                        <td colspan="<?php echo 2 + count($roles); ?>">
                                                            <?php echo htmlspecialchars($categoria_actual); ?>
                                                        </td>
                                                    </tr>
                                                    <?php } ?>
                                                    
                                                    <?php foreach ($recurso_data['acciones'] as $index => $accion): ?>
                                                    <tr>
                                                        <?php if ($index == 0): ?>
                                                        <td class="sticky-col-recurso" rowspan="<?php echo count($recurso_data['acciones']); ?>">
                                                            <?php echo htmlspecialchars($recurso_data['recurso_nombre']); ?>
                                                        </td>
                                                        <?php endif; ?>
                                                        <td class="sticky-col-accion">
                                                            <?php echo htmlspecialchars($accion['nombre']); ?>
                                                        </td>
                                                        <?php foreach ($roles as $rol): ?>
                                                        <td class="permiso-cell">
                                                            <div class="form-check form-switch">
                                                                <input class="form-check-input permiso-rol" type="checkbox" 
                                                                       name="permisos_roles[<?php echo $rol; ?>][<?php echo $accion['recurso_id']; ?>][<?php echo $accion['id']; ?>]" 
                                                                       value="1"
                                                                       data-rol="<?php echo $rol; ?>"
                                                                       data-recurso-id="<?php echo $accion['recurso_id']; ?>"
                                                                       data-accion-id="<?php echo $accion['id']; ?>"
                                                                       <?php 
                                                                       $permitido = permisoExiste($permisos_roles, $rol, $accion['recurso_id'], $accion['id']);
                                                                       echo ($permitido !== null && $permitido) ? 'checked' : '';
                                                                       ?>>
                                                            </div>
                                                        </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="btn-save-container">
                                        <button type="button" id="btn-guardar-permisos-roles" class="btn btn-primary">
                                            <i class="bi bi-save me-1"></i>Guardar Cambios
                                        </button>
                                        <button type="button" id="btn-cancelar-permisos-roles" class="btn btn-secondary ms-2">
                                            <i class="bi bi-x-circle me-1"></i>Cancelar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tab de Permisos por Cargo -->
                    <div class="tab-pane fade" id="cargos" role="tabpanel">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title"><i class="bi bi-briefcase me-2"></i>Permisos por Cargo</h5>
                            </div>
                            <div class="card-body p-0">
                                <form id="form-permisos-cargos" method="POST" action="">
                                    <input type="hidden" name="accion" value="guardar_permisos_cargos">
                                    <div class="table-wrapper">
                                        <div class="table-scroll">
                                            <table class="table table-striped table-sm table-permisos">
                                                <thead class="sticky-header">
                                                    <tr>
                                                        <th class="sticky-col-recurso">Recurso</th>
                                                        <th class="sticky-col-accion">Acción</th>
                                                        <?php foreach ($cargos as $cargo): ?>
                                                        <th style="min-width: 100px;"><?php echo htmlspecialchars($cargo['nombre']); ?></th>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php 
                                                    $categoria_actual = '';
                                                    foreach ($acciones_por_recurso as $recurso_id => $recurso_data): 
                                                        // Mostrar encabezado de categoría si cambia
                                                        if ($categoria_actual != $recurso_data['categoria']) {
                                                            $categoria_actual = $recurso_data['categoria'];
                                                    ?>
                                                    <tr class="categoria-header">
                                                        <td colspan="<?php echo 2 + count($cargos); ?>">
                                                            <?php echo htmlspecialchars($categoria_actual); ?>
                                                        </td>
                                                    </tr>
                                                    <?php } ?>
                                                    
                                                    <?php foreach ($recurso_data['acciones'] as $index => $accion): ?>
                                                    <tr>
                                                        <?php if ($index == 0): ?>
                                                        <td class="sticky-col-recurso" rowspan="<?php echo count($recurso_data['acciones']); ?>">
                                                            <?php echo htmlspecialchars($recurso_data['recurso_nombre']); ?>
                                                        </td>
                                                        <?php endif; ?>
                                                        <td class="sticky-col-accion">
                                                            <?php echo htmlspecialchars($accion['nombre']); ?>
                                                        </td>
                                                        <?php foreach ($cargos as $cargo): ?>
                                                        <td class="permiso-cell">
                                                            <div class="form-check form-switch">
                                                                <input class="form-check-input permiso-cargo" type="checkbox" 
                                                                       name="permisos_cargos[<?php echo $cargo['id']; ?>][<?php echo $accion['recurso_id']; ?>][<?php echo $accion['id']; ?>]" 
                                                                       value="1"
                                                                       data-cargo-id="<?php echo $cargo['id']; ?>"
                                                                       data-recurso-id="<?php echo $accion['recurso_id']; ?>"
                                                                       data-accion-id="<?php echo $accion['id']; ?>"
                                                                       <?php 
                                                                       $permitido = permisoCargoExiste($permisos_cargos, $cargo['id'], $accion['recurso_id'], $accion['id']);
                                                                       echo ($permitido !== null && $permitido) ? 'checked' : '';
                                                                       ?>>
                                                            </div>
                                                        </td>
                                                        <?php endforeach; ?>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="btn-save-container">
                                        <button type="button" id="btn-guardar-permisos-cargos" class="btn btn-primary">
                                            <i class="bi bi-save me-1"></i>Guardar Cambios
                                        </button>
                                        <button type="button" id="btn-cancelar-permisos-cargos" class="btn btn-secondary ms-2">
                                            <i class="bi bi-x-circle me-1"></i>Cancelar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modales para acciones de recursos -->
    <?php foreach ($recursos as $recurso): ?>
    <div class="modal fade" id="modalAcciones<?php echo $recurso['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Acciones para: <?php echo htmlspecialchars($recurso['nombre']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="accion" value="agregar_accion">
                    <input type="hidden" name="recurso_id" value="<?php echo $recurso['id']; ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre_accion" class="form-label">Nombre de la Acción</label>
                            <input type="text" class="form-control" id="nombre_accion" name="nombre" required>
                        </div>
                        <div class="mb-3">
                            <label for="descripcion_accion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion_accion" name="descripcion" rows="2"></textarea>
                        </div>
                        
                        <!-- Acciones existentes -->
                        <div class="mb-3">
                            <label class="form-label">Acciones Existentes</label>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $acciones_recurso = array_filter($acciones, function($a) use ($recurso) {
                                            return $a['recurso_id'] == $recurso['id'];
                                        });
                                        
                                        foreach ($acciones_recurso as $accion): 
                                        ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($accion['nombre']); ?></td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-danger" 
                                                        onclick="confirmarEliminarAccion(<?php echo $accion['id']; ?>)">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Agregar Acción</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Guardar estado inicial de los permisos para poder cancelar cambios
        let estadoInicialPermisosRoles = {};
        let estadoInicialPermisosCargos = {};
        
        // Al cargar la página, guardar el estado inicial de los permisos
        document.addEventListener('DOMContentLoaded', function() {
            // Guardar estado inicial de permisos de roles
            document.querySelectorAll('.permiso-rol').forEach(function(checkbox) {
                const key = `${checkbox.dataset.rol}-${checkbox.dataset.recursoId}-${checkbox.dataset.accionId}`;
                estadoInicialPermisosRoles[key] = checkbox.checked;
            });
            
            // Guardar estado inicial de permisos de cargos
            document.querySelectorAll('.permiso-cargo').forEach(function(checkbox) {
                const key = `${checkbox.dataset.cargoId}-${checkbox.dataset.recursoId}-${checkbox.dataset.accionId}`;
                estadoInicialPermisosCargos[key] = checkbox.checked;
            });
        });
        
        // Botón para guardar permisos de roles
        document.getElementById('btn-guardar-permisos-roles').addEventListener('click', function() {
            document.getElementById('form-permisos-roles').submit();
        });
        
        // Botón para cancelar cambios en permisos de roles
        document.getElementById('btn-cancelar-permisos-roles').addEventListener('click', function() {
            document.querySelectorAll('.permiso-rol').forEach(function(checkbox) {
                const key = `${checkbox.dataset.rol}-${checkbox.dataset.recursoId}-${checkbox.dataset.accionId}`;
                checkbox.checked = estadoInicialPermisosRoles[key];
            });
        });
        
        // Botón para guardar permisos de cargos
        document.getElementById('btn-guardar-permisos-cargos').addEventListener('click', function() {
            document.getElementById('form-permisos-cargos').submit();
        });
        
        // Botón para cancelar cambios en permisos de cargos
        document.getElementById('btn-cancelar-permisos-cargos').addEventListener('click', function() {
            document.querySelectorAll('.permiso-cargo').forEach(function(checkbox) {
                const key = `${checkbox.dataset.cargoId}-${checkbox.dataset.recursoId}-${checkbox.dataset.accionId}`;
                checkbox.checked = estadoInicialPermisosCargos[key];
            });
        });
        
        function confirmarEliminarRecurso(recursoId) {
            if (confirm('¿Está seguro de eliminar este recurso? Esta acción eliminará también todas las acciones asociadas.')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const inputAccion = document.createElement('input');
                inputAccion.type = 'hidden';
                inputAccion.name = 'accion';
                inputAccion.value = 'eliminar_recurso';
                form.appendChild(inputAccion);
                
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'recurso_id';
                inputId.value = recursoId;
                form.appendChild(inputId);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
        
        function confirmarEliminarAccion(accionId) {
            if (confirm('¿Está seguro de eliminar esta acción?')) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.action = '';
                
                const inputAccion = document.createElement('input');
                inputAccion.type = 'hidden';
                inputAccion.name = 'accion';
                inputAccion.value = 'eliminar_accion';
                form.appendChild(inputAccion);
                
                const inputId = document.createElement('input');
                inputId.type = 'hidden';
                inputId.name = 'accion_id';
                inputId.value = accionId;
                form.appendChild(inputId);
                
                document.body.appendChild(form);
                form.submit();
            }
        }
    </script>
</body>
</html>