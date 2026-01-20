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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'permisos', 'ver')) {
    header("Location: portal.php");
    exit();
}

$functions = new Functions();
$error = '';
$success = '';

// Verificar permisos - administradores, talento humano y supervisores pueden ver esta página
$user_id = $_SESSION['user_id'];
$user_role = $_SESSION['user_role'];
$es_supervisor = $functions->esSupervisor($user_id);

$allowed_roles = ['administrador', 'talento_humano', 'it'];
if (!in_array($user_role, $allowed_roles) && !$es_supervisor) {
    header("Location: dashboard.php");
    exit();
}

// Procesar actualización de permiso
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_permiso'])) {
    $permiso_id = $_POST['permiso_id'];
    $estado = $_POST['estado'];
    $notas = trim($_POST['notas']);
    $tipo_aprobacion = $_POST['tipo_aprobacion']; // 'supervisor' o 'talento_humano'
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener información completa del permiso actual
    $query = "SELECT p.*, e.company as empleado_company 
              FROM permisos p
              LEFT JOIN employee e ON p.empleado_id = e.CC
              WHERE p.id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $permiso_id);
    $stmt->execute();
    $permiso = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($permiso) {
        // VERIFICAR PERMISOS SEGÚN ROL Y SI ES SUPERVISOR
	if ($_SESSION['user_role'] != 'administrador') {
	    if ($es_supervisor) {
	        // Para supervisores: solo pueden aprobar permisos de sus subordinados
	        if ($permiso['supervisor_id'] != $user_id) {
	            $error = "No tiene permisos para gestionar este permiso";
	        } elseif ($tipo_aprobacion != 'supervisor') {
	            $error = "Los supervisores solo pueden realizar aprobaciones de supervisor";
	        }
	    } elseif ($user_role == 'talento_humano') {
	        // Para talento humano: solo pueden aprobar después del supervisor
	        if ($permiso['estado_supervisor'] != 'aprobado') {
	            $error = "El permiso debe ser aprobado primero por el supervisor";
	        }
	    }
	}

        
        if (empty($error)) {
            // CORRECCIÓN MEJORADA: Lógica de actualización según el tipo de aprobación
            if ($tipo_aprobacion == 'supervisor') {
                $campo_estado = 'estado_supervisor';
                $campo_notas = 'notas_supervisor';
                
                // Si el supervisor aprueba y el empleado es de BilingueLaw, TH queda como 'no_aplica'
                if ($estado == 'aprobado' && $permiso['empleado_company'] == 2) {
                    $query = "UPDATE permisos SET 
                              estado_supervisor = :estado, 
                              notas_supervisor = :notas, 
                              estado_talento_humano = 'no_aplica',
                              responsable_id = :responsable_id 
                              WHERE id = :id";
                } else {
                    $query = "UPDATE permisos SET 
                              $campo_estado = :estado, 
                              $campo_notas = :notas, 
                              responsable_id = :responsable_id 
                              WHERE id = :id";
                }
            } else {
                // Aprobación de talento humano
                $campo_estado = 'estado_talento_humano';
                $campo_notas = 'notas_talento_humano';
                $query = "UPDATE permisos SET 
                          $campo_estado = :estado, 
                          $campo_notas = :notas, 
                          responsable_id = :responsable_id 
                          WHERE id = :id";
            }
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':estado', $estado);
            $stmt->bindParam(':notas', $notas);
            $stmt->bindParam(':responsable_id', $user_id);
            $stmt->bindParam(':id', $permiso_id);
            
            if ($stmt->execute()) {
                // Enviar correo de notificación
                if (method_exists($functions, 'enviarNotificacionPermiso')) {
                    $functions->enviarNotificacionPermiso($permiso_id, $estado, $tipo_aprobacion);
                }
                
                $success = "Permiso actualizado correctamente";
                
                // Recargar para ver cambios
                header("Location: permisos.php?success=Permiso+actualizado+correctamente");
                exit();
            } else {
                $error = "Error al actualizar el permiso";
            }
        }
    } else {
        $error = "Permiso no encontrado";
    }
}

// Obtener permisos según el rol del usuario y si es supervisor
$database = new Database();
$conn = $database->getConnection();

// Construir consulta según el rol y si es supervisor
if ($es_supervisor) {
    // Supervisores ven solo los permisos de sus subordinados
    $query = "SELECT p.*, 
                     e.first_Name as empleado_nombre, 
                     e.first_LastName as empleado_apellido,
                     e.mail as empleado_email,
                     e.company as empleado_company,
                     s.first_Name as supervisor_nombre, 
                     s.first_LastName as supervisor_apellido,
                     emp_super.first_Name as responsable_nombre,
                     emp_super.first_LastName as responsable_apellido
              FROM permisos p
              LEFT JOIN employee e ON p.empleado_id = e.CC
              LEFT JOIN employee s ON p.supervisor_id = s.id
              LEFT JOIN employee emp_super ON p.responsable_id = emp_super.id
              WHERE p.supervisor_id = :user_id 
              ORDER BY p.creado_en DESC";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':user_id', $user_id);
    
} elseif ($user_role == 'talento_humano') {
    // Talento humano ve todos los permisos aprobados por supervisores
    $query = "SELECT p.*, 
                     e.first_Name as empleado_nombre, 
                     e.first_LastName as empleado_apellido,
                     e.mail as empleado_email,
                     e.company as empleado_company,
                     s.first_Name as supervisor_nombre, 
                     s.first_LastName as supervisor_apellido,
                     emp_super.first_Name as responsable_nombre,
                     emp_super.first_LastName as responsable_apellido
              FROM permisos p
              LEFT JOIN employee e ON p.empleado_id = e.CC
              LEFT JOIN employee s ON p.supervisor_id = s.id
              LEFT JOIN employee emp_super ON p.responsable_id = emp_super.id
              WHERE p.estado_supervisor = 'aprobado'
              ORDER BY p.creado_en DESC";
    $stmt = $conn->prepare($query);
    
} else {
    // Administradores ven todos los permisos
    $query = "SELECT p.*, 
                     e.first_Name as empleado_nombre, 
                     e.first_LastName as empleado_apellido,
                     e.mail as empleado_email,
                     e.company as empleado_company,
                     s.first_Name as supervisor_nombre, 
                     s.first_LastName as supervisor_apellido,
                     emp_super.first_Name as responsable_nombre,
                     emp_super.first_LastName as responsable_apellido
              FROM permisos p
              LEFT JOIN employee e ON p.empleado_id = e.CC
              LEFT JOIN employee s ON p.supervisor_id = s.id
              LEFT JOIN employee emp_super ON p.responsable_id = emp_super.id
              ORDER BY p.creado_en DESC";
    $stmt = $conn->prepare($query);
}

$stmt->execute();
$permisos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener contadores para las pestañas
if ($es_supervisor) {
    $query_pendientes = "SELECT COUNT(*) as count FROM permisos WHERE supervisor_id = :user_id AND estado_supervisor = 'pendiente'";
    $stmt_pendientes = $conn->prepare($query_pendientes);
    $stmt_pendientes->bindParam(':user_id', $user_id);
    $stmt_pendientes->execute();
    $contador_pendientes = $stmt_pendientes->fetch(PDO::FETCH_ASSOC)['count'];
    
    $query_todos = "SELECT COUNT(*) as count FROM permisos WHERE supervisor_id = :user_id";
    $stmt_todos = $conn->prepare($query_todos);
    $stmt_todos->bindParam(':user_id', $user_id);
    $stmt_todos->execute();
    $contador_todos = $stmt_todos->fetch(PDO::FETCH_ASSOC)['count'];
} else {
    $query_pendientes = "SELECT COUNT(*) as count FROM permisos WHERE estado_talento_humano = 'pendiente'";
    $stmt_pendientes = $conn->prepare($query_pendientes);
    $stmt_pendientes->execute();
    $contador_pendientes = $stmt_pendientes->fetch(PDO::FETCH_ASSOC)['count'];
    
    $contador_todos = count($permisos);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Permisos - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
    .btn-primary { background-color: #003a5d !important; border-color: #003a5d !important; }
    .btn-success { background-color: #198754 !important; border-color: #198754 !important; }
    .btn-danger { background-color: #be1622 !important; border-color: #be1622 !important; }
    .card-header { background-color: #003a5d !important; color: white !important; }
    
    .badge-pendiente { background-color: #ffc107; color: #000; }
    .badge-aprobado { background-color: #198754; color: #fff; }
    .badge-rechazado { background-color: #be1622; color: #fff; }
    
    .table-hover tbody tr:hover { 
        background-color: #33617e !important; 
        color: white !important;
        cursor: pointer;
    }
    
	.nav-tabs .nav-link {
	    color: #495057 !important;
	    font-weight: normal;
	    padding-right: 35px !important; /* Añade espacio extra a la derecha */
	    display: inline-flex !important;
	    align-items: center !important;
	    justify-content: center !important;
	    min-width: 150px; /* Establece un ancho mínimo */
	    white-space: nowrap; /* Evita que el texto se divida en múltiples líneas */
	}
    .nav-tabs .nav-link.active { color: #495057 !important; font-weight: bold; }
    
    .permiso-row { transition: all 0.2s ease-in-out; }
    .permiso-row:hover { transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <i class="bi bi-clipboard-check"></i> Gestión de Permisos
                        <?php if ($es_supervisor): ?>
                        <small class="text-muted fs-6">(Solo mis subordinados)</small>
                        <?php endif; ?>
                    </h1>
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Pestañas para diferentes estados -->
                <ul class="nav nav-tabs mb-4" id="permisosTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="pendientes-tab" data-bs-toggle="tab" data-bs-target="#pendientes" type="button" role="tab">
                            <i class="bi bi-clock"></i> Pendientes 
                            <span class="badge bg-warning ms-1"><?php echo $contador_pendientes; ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="todos-tab" data-bs-toggle="tab" data-bs-target="#todos" type="button" role="tab">
                            <i class="bi bi-list-ul"></i> Todos los Permisos
                            <span class="badge bg-secondary ms-1"><?php echo $contador_todos; ?></span>
                        </button>
                    </li>
                </ul>
                
                <div class="tab-content" id="permisosTabsContent">
                    <!-- Pestaña 1: Pendientes -->
                    <div class="tab-pane fade show active" id="pendientes" role="tabpanel">
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Empleado</th>
                                                <th>Tipo</th>
                                                <th>Fechas</th>
                                                <th>Estado Supervisor</th>
                                                <th>Estado TH</th>
                                                <th>Fecha Solicitud</th>
                                            </tr>
                                        </thead>
                                        <tbody>
					<?php 
					$permisos_pendientes = array_filter($permisos, function($permiso) use ($es_supervisor, $user_role) {
					    if ($es_supervisor) {
					        // Para supervisores: mostrar permisos pendientes de su aprobación
					        return $permiso['estado_supervisor'] == 'pendiente';
					    } elseif ($user_role == 'talento_humano') {
					        // Para talento humano: mostrar permisos pendientes de TH, excluyendo 'no_aplica'
					        return $permiso['estado_talento_humano'] == 'pendiente' && $permiso['estado_talento_humano'] != 'no_aplica';
					    } else {
					        // Para administradores: mostrar todos los permisos que estén pendientes en al menos un estado
					        return $permiso['estado_supervisor'] == 'pendiente' || 
					               ($permiso['estado_talento_humano'] == 'pendiente' && $permiso['estado_talento_humano'] != 'no_aplica');
					    }
					});
					?>
                                            
                                            <?php if (count($permisos_pendientes) > 0): ?>
                                                <?php foreach ($permisos_pendientes as $permiso): ?>
                                                <tr class="permiso-row" onclick="abrirModalPermiso(<?php echo $permiso['id']; ?>)">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($permiso['empleado_nombre'] . ' ' . $permiso['empleado_apellido']); ?></strong>
                                                        <br><small class="text-muted"><?php echo $permiso['empleado_id']; ?></small>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $tipos = [
                                                            'no_remunerado' => 'No Remunerado',
                                                            'remunerado' => 'Remunerado',
                                                            'por_hora' => 'Por Horas',
                                                            'matrimonio' => 'Matrimonio',
                                                            'trabajo_casa' => 'Trabajo Casa'
                                                        ];
                                                        echo $tipos[$permiso['tipo_permiso']] ?? $permiso['tipo_permiso'];
                                                        ?>
                                                    </td>
							<td>
							    <?php echo date('d/m/Y', strtotime($permiso['fecha_inicio'])); ?>
							    <?php if ($permiso['fecha_fin'] != $permiso['fecha_inicio'] && $permiso['tipo_permiso'] != 'por_hora'): ?>
							    - <?php echo date('d/m/Y', strtotime($permiso['fecha_fin'])); ?>
							    <?php endif; ?>
							    
							    <?php if ($permiso['tipo_permiso'] == 'por_hora' && $permiso['horas']): ?>
							    <br><small class="text-info">
							        <?php 
							        // Mostrar horas solo para permisos por horas
							        $horas_array = explode(':', $permiso['horas']);
							        echo $horas_array[0] . ':' . $horas_array[1] . ' hrs';
							        ?>
							    </small>
							    <?php endif; ?>
							</td>
							<td>
							    <span class="badge badge-<?php echo $permiso['estado_supervisor']; ?>">
							        <?php echo ucfirst($permiso['estado_supervisor']); ?>
							    </span>
							</td>
							<td>
							    <?php if ($permiso['estado_talento_humano'] == 'no_aplica'): ?>
							        <span class="badge bg-secondary">No Aplica</span>
							    <?php else: ?>
							        <span class="badge badge-<?php echo $permiso['estado_talento_humano']; ?>">
							            <?php echo ucfirst($permiso['estado_talento_humano']); ?>
							        </span>
							    <?php endif; ?>
							</td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($permiso['creado_en'])); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center py-4">
                                                        <i class="bi bi-check-circle text-success" style="font-size: 2rem;"></i>
                                                        <p class="mt-2 text-muted">No hay permisos pendientes</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Pestaña 2: Todos los permisos -->
                    <div class="tab-pane fade" id="todos" role="tabpanel">
                        <div class="card">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>Empleado</th>
                                                <th>Tipo</th>
                                                <th>Fechas</th>
                                                <th>Estado Supervisor</th>
                                                <th>Estado TH</th>
                                                <th>Fecha Solicitud</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($permisos) > 0): ?>
                                                <?php foreach ($permisos as $permiso): ?>
                                                <tr class="permiso-row" onclick="abrirModalPermiso(<?php echo $permiso['id']; ?>)">
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($permiso['empleado_nombre'] . ' ' . $permiso['empleado_apellido']); ?></strong>
                                                        <br><small class="text-muted"><?php echo $permiso['empleado_id']; ?></small>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $tipos = [
                                                            'no_remunerado' => 'No Remunerado',
                                                            'remunerado' => 'Remunerado',
                                                            'por_hora' => 'Por Horas',
                                                            'matrimonio' => 'Matrimonio',
                                                            'trabajo_casa' => 'Trabajo Casa'
                                                        ];
                                                        echo $tipos[$permiso['tipo_permiso']] ?? $permiso['tipo_permiso'];
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php echo date('d/m/Y', strtotime($permiso['fecha_inicio'])); ?>
                                                        <?php if ($permiso['fecha_fin'] != $permiso['fecha_inicio'] && $permiso['tipo_permiso'] != 'por_hora'): ?>
                                                        - <?php echo date('d/m/Y', strtotime($permiso['fecha_fin'])); ?>
                                                        <?php endif; ?>
                                                        <?php if ($permiso['horas']): ?>
                                                        <br><small class="text-info">
                                                            <?php 
                                                            // Mostrar horas solo para permisos por horas
                                                            $horas_array = explode(':', $permiso['horas']);
                                                            echo $horas_array[0] . ':' . $horas_array[1] . ' hrs';
                                                            ?>
                                                        </small>
                                                        <?php endif; ?>
                                                    </td>
							<td>
							    <span class="badge badge-<?php echo $permiso['estado_supervisor']; ?>">
							        <?php echo ucfirst($permiso['estado_supervisor']); ?>
							    </span>
							</td>
							<td>
							    <?php if ($permiso['estado_talento_humano'] == 'no_aplica'): ?>
							        <span class="badge bg-secondary">No Aplica</span>
							    <?php else: ?>
							        <span class="badge badge-<?php echo $permiso['estado_talento_humano']; ?>">
							            <?php echo ucfirst($permiso['estado_talento_humano']); ?>
							        </span>
							    <?php endif; ?>
							</td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($permiso['creado_en'])); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="6" class="text-center py-4">
                                                        <i class="bi bi-inbox text-muted" style="font-size: 2rem;"></i>
                                                        <p class="mt-2 text-muted">No hay permisos registrados</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modales para ver/editar permisos -->
    <?php foreach ($permisos as $permiso): ?>
    <div class="modal fade" id="modalVerPermiso<?php echo $permiso['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Permiso #<?php echo $permiso['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="permiso_id" value="<?php echo $permiso['id']; ?>">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Empleado</label>
                                    <p>
                                        <strong><?php echo htmlspecialchars($permiso['empleado_nombre'] . ' ' . $permiso['empleado_apellido']); ?></strong>
                                        <br><small class="text-muted"><?php echo $permiso['empleado_id']; ?></small>
                                        <br><small class="text-muted"><?php echo $permiso['empleado_email']; ?></small>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Departamento</label>
                                    <p><?php echo htmlspecialchars($permiso['empleado_departamento']); ?></p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Tipo de Permiso</label>
                                    <p>
                                        <?php 
                                        $tipos = [
                                            'no_remunerado' => 'No Remunerado',
                                            'remunerado' => 'Remunerado',
                                            'por_hora' => 'Por Horas',
                                            'matrimonio' => 'Matrimonio',
                                            'trabajo_casa' => 'Trabajo Casa'
                                        ];
                                        echo $tipos[$permiso['tipo_permiso']] ?? $permiso['tipo_permiso'];
                                        ?>
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Fecha Inicio</label>
                                    <p><?php echo date('d/m/Y', strtotime($permiso['fecha_inicio'])); ?></p>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Fecha Fin</label>
                                    <p>
                                        <?php if ($permiso['tipo_permiso'] == 'por_hora'): ?>
                                        <em class="text-muted">Mismo día (permiso por horas)</em>
                                        <?php else: ?>
                                        <?php echo date('d/m/Y', strtotime($permiso['fecha_fin'])); ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($permiso['horas']): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Horas</label>
                                    <p>
                                        <?php 
                                        $horas_array = explode(':', $permiso['horas']);
                                        echo $horas_array[0] . ':' . $horas_array[1] . ' horas';
                                        ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Motivo</label>
                            <p><?php echo nl2br(htmlspecialchars($permiso['motivo'])); ?></p>
                        </div>
                        
                        <!-- Información de Aprobación -->
			<div class="row mt-3">
			    <div class="col-12">
			        <h6 class="border-bottom pb-2">Historial de Aprobación</h6>
			        
			        <!-- Información del Supervisor -->
			        <div class="mb-2">
			            <small class="text-muted">Supervisor asignado:</small>
			            <p class="mb-1">
			                <?php if (!empty($permiso['supervisor_nombre'])): ?>
			                    <strong><?php echo htmlspecialchars($permiso['supervisor_nombre'] . ' ' . $permiso['supervisor_apellido']); ?></strong>
			                <?php else: ?>
			                    <em class="text-muted">No asignado</em>
			                <?php endif; ?>
			            </p>
			        </div>
			        <?php if (!empty($permiso['soporte_ruta'])): ?>
				<div class="mb-3">
				    <label class="form-label fw-bold">Soporte Adjunto</label>
				    <div class="card">
				        <div class="card-body">
				            <?php
				            $extension = pathinfo($permiso['soporte_ruta'], PATHINFO_EXTENSION);
				            $es_imagen = in_array(strtolower($extension), ['jpg', 'jpeg', 'png', 'gif', 'webp']);
				            $es_pdf = strtolower($extension) === 'pdf';
				            $es_documento = in_array(strtolower($extension), ['doc', 'docx']);
				            $es_zip = strtolower($extension) === 'zip';
				            ?>
				            
				            <?php if ($es_imagen): ?>
				                <!-- Mostrar imagen -->
				                <div class="text-center">
				                    <img src="<?php echo $permiso['soporte_ruta']; ?>" 
				                         class="img-fluid rounded" 
				                         style="max-height: 400px;" 
				                         alt="Soporte del permiso"
				                         onclick="ampliarImagen('<?php echo $permiso['soporte_ruta']; ?>')">
				                    <div class="mt-2">
				                        <a href="<?php echo $permiso['soporte_ruta']; ?>" 
				                           target="_blank" 
				                           class="btn btn-sm btn-outline-primary">
				                            <i class="bi bi-arrows-fullscreen"></i> Ver imagen completa
				                        </a>
				                        <a href="<?php echo $permiso['soporte_ruta']; ?>" 
				                           download 
				                           class="btn btn-sm btn-outline-success">
				                            <i class="bi bi-download"></i> Descargar
				                        </a>
				                    </div>
				                </div>
				            <?php elseif ($es_pdf): ?>
				                <!-- Mostrar PDF -->
				                <div class="text-center">
				                    <i class="bi bi-file-earmark-pdf display-1 text-danger"></i>
				                    <p class="mt-2">Documento PDF adjunto</p>
				                    <div>
				                        <a href="<?php echo $permiso['soporte_ruta']; ?>" 
				                           target="_blank" 
				                           class="btn btn-sm btn-outline-primary">
				                            <i class="bi bi-eye"></i> Ver PDF
				                        </a>
				                        <a href="<?php echo $permiso['soporte_ruta']; ?>" 
				                           download 
				                           class="btn btn-sm btn-outline-success">
				                            <i class="bi bi-download"></i> Descargar
				                        </a>
				                    </div>
				                </div>
				            <?php elseif ($es_documento): ?>
				                <!-- Mostrar documento Word -->
				                <div class="text-center">
				                    <i class="bi bi-file-earmark-word display-1 text-primary"></i>
				                    <p class="mt-2">Documento Word adjunto</p>
				                    <div>
				                        <a href="<?php echo $permiso['soporte_ruta']; ?>" 
				                           download 
				                           class="btn btn-sm btn-outline-success">
				                            <i class="bi bi-download"></i> Descargar documento
				                        </a>
				                    </div>
				                </div>
				            <?php elseif ($es_zip): ?>
				                <!-- Mostrar archivo ZIP -->
				                <div class="text-center">
				                    <i class="bi bi-file-earmark-zip display-1 text-warning"></i>
				                    <p class="mt-2">Archivo ZIP adjunto</p>
				                    <div>
				                        <a href="<?php echo $permiso['soporte_ruta']; ?>" 
				                           download 
				                           class="btn btn-sm btn-outline-success">
				                            <i class="bi bi-download"></i> Descargar ZIP
				                        </a>
				                    </div>
				                </div>
				            <?php else: ?>
				                <!-- Archivo genérico -->
				                <div class="text-center">
				                    <i class="bi bi-file-earmark display-1 text-secondary"></i>
				                    <p class="mt-2">Archivo adjunto</p>
				                    <div>
				                        <a href="<?php echo $permiso['soporte_ruta']; ?>" 
				                           download 
				                           class="btn btn-sm btn-outline-success">
				                            <i class="bi bi-download"></i> Descargar archivo
				                        </a>
				                    </div>
				                </div>
				            <?php endif; ?>
				        </div>
				    </div>
				</div>
				<?php endif; ?>
			        <!-- Información del último responsable -->
			        <div class="mb-2">
			            <small class="text-muted">Última modificación por:</small>
			            <p class="mb-1">
			                <?php if (!empty($permiso['responsable_id'])): ?>
			                    <?php if (!empty($permiso['responsable_nombre'])): ?>
			                        <strong><?php echo htmlspecialchars($permiso['responsable_nombre'] . ' ' . $permiso['responsable_apellido']); ?></strong>
			                    <?php else: ?>
			                        <strong>Usuario ID: <?php echo $permiso['responsable_id']; ?></strong>
			                    <?php endif; ?>
			                    
			                    <!-- Mostrar rol del responsable -->
			                    <?php 
			                    $rol_responsable = '';
			                    if ($permiso['responsable_id'] == $permiso['supervisor_id']) {
			                        $rol_responsable = ' (Supervisor)';
			                    } elseif ($_SESSION['user_role'] == 'talento_humano') {
			                        $rol_responsable = ' (Talento Humano)';
			                    } elseif ($_SESSION['user_role'] == 'administrador') {
			                        $rol_responsable = ' (Administrador)';
			                    }
			                    echo $rol_responsable;
			                    ?>
			                <?php else: ?>
			                    <em class="text-muted">No se han realizado modificaciones</em>
			                <?php endif; ?>
			            </p>
			        </div>
			
			        <!-- Estados actuales -->
			        <div class="row">
			            <div class="col-md-6">
			                <small class="text-muted">Estado Supervisor:</small>
			                <p>
			                    <span class="badge badge-<?php echo $permiso['estado_supervisor']; ?>">
			                        <?php echo ucfirst($permiso['estado_supervisor']); ?>
			                    </span>
			                    <?php if ($permiso['estado_supervisor'] != 'pendiente' && !empty($permiso['notas_supervisor'])): ?>
			                        <br><small class="text-muted">Notas: <?php echo htmlspecialchars($permiso['notas_supervisor']); ?></small>
			                    <?php endif; ?>
			                </p>
			            </div>
			            <div class="col-md-6">
			                <small class="text-muted">Estado Talento Humano:</small>
			                <p>
			                    <?php if ($permiso['estado_talento_humano'] == 'no_aplica'): ?>
			                        <span class="badge bg-secondary">No Aplica</span>
			                    <?php else: ?>
			                        <span class="badge badge-<?php echo $permiso['estado_talento_humano']; ?>">
			                            <?php echo ucfirst($permiso['estado_talento_humano']); ?>
			                        </span>
			                        <?php if ($permiso['estado_talento_humano'] != 'pendiente' && !empty($permiso['notas_talento_humano'])): ?>
			                            <br><small class="text-muted">Notas: <?php echo htmlspecialchars($permiso['notas_talento_humano']); ?></small>
			                        <?php endif; ?>
			                    <?php endif; ?>
			                </p>
			            </div>
			        </div>
			    </div>
			</div>
                        
                        <!-- Sección para Supervisor -->
                        <?php if ($es_supervisor || $_SESSION['user_role'] == 'administrador'): ?>
                        <?php if ($permiso['estado_supervisor'] == 'pendiente' || $_SESSION['user_role'] == 'administrador'): ?>
                        <div class="form-section border p-3 mb-3">
                            <h6>Aprobación del Supervisor</h6>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Estado</label>
                                <select class="form-select" name="estado" required>
                                    <option value="pendiente" <?php echo $permiso['estado_supervisor'] == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                    <option value="aprobado" <?php echo $permiso['estado_supervisor'] == 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                                    <option value="rechazado" <?php echo $permiso['estado_supervisor'] == 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                                </select>
                                <input type="hidden" name="tipo_aprobacion" value="supervisor">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-bold">Notas del Supervisor</label>
                                <textarea class="form-control" name="notas" rows="3" placeholder="Agregue comentarios sobre su decisión..."><?php echo htmlspecialchars($permiso['notas_supervisor'] ?? ''); ?></textarea>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Este permiso ya fue <?php echo $permiso['estado_supervisor']; ?> por el supervisor.
                            <?php if ($permiso['notas_supervisor']): ?>
                            <br><strong>Notas:</strong> <?php echo htmlspecialchars($permiso['notas_supervisor']); ?>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        
			<!-- Sección para Talento Humano -->
			<?php 
			// Determinar si mostrar la sección de TH
			$mostrar_seccion_th = true;
			
			// NO mostrar sección TH si:
			// 1. El permiso es de BilingueLaw (company = 2)
			// 2. O si el estado de TH es 'no_aplica'
			if (($permiso['empleado_company'] == 2) || $permiso['estado_talento_humano'] == 'no_aplica') {
			    $mostrar_seccion_th = false;
			}
			?>
			
			<?php if (($_SESSION['user_role'] == 'talento_humano' || $_SESSION['user_role'] == 'administrador') && $mostrar_seccion_th): ?>
			<?php if ($permiso['estado_supervisor'] == 'aprobado' || $_SESSION['user_role'] == 'administrador'): ?>
			<div class="form-section border p-3">
			    <h6>Aprobación de Talento Humano</h6>
			    <div class="mb-3">
			        <label class="form-label fw-bold">Estado</label>
			        <select class="form-select" name="estado" required>
			            <option value="pendiente" <?php echo $permiso['estado_talento_humano'] == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
			            <option value="aprobado" <?php echo $permiso['estado_talento_humano'] == 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
			            <option value="rechazado" <?php echo $permiso['estado_talento_humano'] == 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
			        </select>
			        <input type="hidden" name="tipo_aprobacion" value="talento_humano">
			    </div>
			    <div class="mb-3">
			        <label class="form-label fw-bold">Notas de Talento Humano</label>
			        <textarea class="form-control" name="notas" rows="3" placeholder="Agregue comentarios sobre su decisión..."><?php echo htmlspecialchars($permiso['notas_talento_humano'] ?? ''); ?></textarea>
			    </div>
			</div>
			<?php else: ?>
			<div class="alert alert-warning">
			    <i class="bi bi-info-circle"></i> Este permiso debe ser aprobado primero por el supervisor.
			</div>
			<?php endif; ?>
			<?php elseif (!$mostrar_seccion_th): ?>
			<div class="alert alert-info">
			    <i class="bi bi-info-circle"></i> Este permiso solo requiere aprobación del supervisor (BilingueLaw).
			</div>
			<?php endif; ?>
                    </div>
			<div class="modal-footer">
			    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
			    <?php 
			    // Determinar si mostrar botón de guardar
			    $mostrar_boton_guardar = false;
			    
			    if ($_SESSION['user_role'] == 'administrador') {
			        // Administradores pueden editar siempre
			        $mostrar_boton_guardar = true;
			    } elseif ($es_supervisor && $permiso['estado_supervisor'] == 'pendiente') {
			        // Supervisores solo pueden editar si está pendiente
			        $mostrar_boton_guardar = true;
			    } elseif ($_SESSION['user_role'] == 'talento_humano' && 
			              $permiso['estado_supervisor'] == 'aprobado' && 
			              $permiso['estado_talento_humano'] == 'pendiente' &&
			              $permiso['estado_talento_humano'] != 'no_aplica') {
			        // TH solo puede editar si está pendiente y no es de BilingueLaw
			        $mostrar_boton_guardar = true;
			    }
			    ?>
			    
			    <?php if ($mostrar_boton_guardar): ?>
			    <button type="submit" name="actualizar_permiso" class="btn btn-primary">Guardar Cambios</button>
			    <?php endif; ?>
			</div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
<!-- Modal para imagen ampliada -->
<div class="modal fade" id="modalImagenAmpliada" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Soporte del Permiso</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="imagenAmpliada" src="" class="img-fluid" alt="Soporte ampliado">
            </div>
            <div class="modal-footer">
                <a href="#" id="descargarImagen" class="btn btn-success" download>
                    <i class="bi bi-download"></i> Descargar
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
            </div>
        </div>
    </div>
</div>

    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Función para ampliar imagen
function ampliarImagen(rutaImagen) {
    document.getElementById('imagenAmpliada').src = rutaImagen;
    document.getElementById('descargarImagen').href = rutaImagen;
    const modal = new bootstrap.Modal(document.getElementById('modalImagenAmpliada'));
    modal.show();
}
    function abrirModalPermiso(permisoId) {
        const modal = new bootstrap.Modal(document.getElementById('modalVerPermiso' + permisoId));
        modal.show();
    }
    </script>
</body>
</html>