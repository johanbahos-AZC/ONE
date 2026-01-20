<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// Inicializar autenticación
$auth = new Auth();
$auth->redirectIfNotLoggedIn();

$functions = new Functions();
$error = '';
$success = '';

// Obtener información del usuario actual desde la sesión
$empleado_info = null;
$user_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;

if ($user_id) {
    // Obtener información del empleado actual
    $empleado_info = $functions->obtenerEmpleadoPorId($user_id);
    
    if (!$empleado_info || isset($empleado_info['error'])) {
        $error = "No se pudo cargar la información del usuario actual";
        $empleado_info = null;
    }
} else {
    $error = "No se pudo identificar al usuario. Por favor, inicie sesión nuevamente.";
}

// Procesar envío del permiso
if (isset($_POST['enviar_permiso']) && $empleado_info) {
    try {
        $id_empleado = $empleado_info['CC'];
        $tipo_permiso = $_POST['tipo_permiso'];
        $fecha_inicio = $_POST['fecha_inicio'];
        
        //Para permisos por horas, fecha_fin = fecha_inicio
        if ($tipo_permiso == 'por_hora') {
            $fecha_fin = $fecha_inicio; // Misma fecha para permisos por horas
            $horas = $_POST['hora_inicio'] . ':' . $_POST['hora_fin'];
        } else {
            $fecha_fin = $_POST['fecha_fin'];
            $horas = null;
        }
        
        $motivo = trim($_POST['motivo']);
        $soporte_ruta = null;
        
        // Procesar archivo adjunto si se subió
        if (isset($_FILES['soporte']) && $_FILES['soporte']['error'] === UPLOAD_ERR_OK) {
            $soporte_ruta = $functions->guardarSoportePermiso($_FILES['soporte']);
            if (!$soporte_ruta) {
                throw new Exception("Error al guardar el archivo adjunto");
            }
        }
        
        // Validaciones básicas
        if (empty($tipo_permiso) || empty($fecha_inicio) || empty($motivo)) {
            throw new Exception("Todos los campos obligatorios deben ser completados");
        }
        
        // Para permisos que no son por horas, validar fecha_fin
        if ($tipo_permiso != 'por_hora' && $fecha_fin < $fecha_inicio) {
            throw new Exception("La fecha de fin no puede ser anterior a la fecha de inicio");
        }
        
        $database = new Database();
        $conn = $database->getConnection();
        
        // Obtener supervisor inmediato - CON MANEJO DE ERRORES
        $supervisor_id = null;
        if (method_exists($functions, 'obtenerSupervisorInmediato')) {
            $supervisor_id = $functions->obtenerSupervisorInmediato($id_empleado);
        }
        
        // Si no se puede obtener el supervisor, usar un valor por defecto
        if (!$supervisor_id) {
            $supervisor_id = 0; // O el ID de un supervisor por defecto
            error_log("No se pudo obtener supervisor para empleado: " . $id_empleado);
        }
        
	// CORRECCIÓN MEJORADA: Estados iniciales según la compañía
	$estado_supervisor = 'pendiente'; // Siempre pendiente inicialmente
	
	// Determinar estado de TH según compañía
	if (isset($empleado_info['company']) && $empleado_info['company'] == 2) {
	    // BilingueLaw: TH no aplica
	    $estado_th = 'no_aplica';
	} else {
	    // Otras compañías: TH pendiente
	    $estado_th = 'pendiente';
	}
	
	$query = "INSERT INTO permisos (
                    empleado_id, empleado_nombre, empleado_departamento, 
                    supervisor_id, tipo_permiso, fecha_inicio, fecha_fin, 
                    horas, motivo, soporte_ruta, estado_supervisor, estado_talento_humano
                  ) VALUES (
                    :empleado_id, :empleado_nombre, :empleado_departamento,
                    :supervisor_id, :tipo_permiso, :fecha_inicio, :fecha_fin,
                    :horas, :motivo, :soporte_ruta, :estado_supervisor, :estado_th
                  )";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':empleado_id', $id_empleado);
        $stmt->bindParam(':empleado_nombre', $empleado_info['nombre']);
        $stmt->bindParam(':empleado_departamento', $empleado_info['cargo']);
        $stmt->bindParam(':supervisor_id', $supervisor_id);
        $stmt->bindParam(':tipo_permiso', $tipo_permiso);
        $stmt->bindParam(':fecha_inicio', $fecha_inicio);
        $stmt->bindParam(':fecha_fin', $fecha_fin);
        $stmt->bindParam(':horas', $horas);
        $stmt->bindParam(':motivo', $motivo);
        $stmt->bindParam(':soporte_ruta', $soporte_ruta);
        $stmt->bindParam(':estado_supervisor', $estado_supervisor);
        $stmt->bindParam(':estado_th', $estado_th);
        
        if ($stmt->execute()) {
            $permiso_id = $conn->lastInsertId();
            
            // Enviar correo de notificación (opcional, puede fallar silenciosamente)
            if (method_exists($functions, 'enviarNotificacionPermiso')) {
                try {
                    $functions->enviarNotificacionPermiso($permiso_id, 'solicitud');
                } catch (Exception $e) {
                    // Log del error pero no interrumpir el flujo
                    error_log("Error enviando notificación: " . $e->getMessage());
                }
            }
            
            $success = "Solicitud de permiso enviada correctamente";
            
            // Limpiar formulario
            echo '<script>setTimeout(function(){ document.getElementById("permisoForm").reset(); }, 1000);</script>';
        } else {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Error en la base de datos: " . ($errorInfo[2] ?? 'Error desconocido'));
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Error en permisos_index.php: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Permiso - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
    .btn-primary {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
    }
    
    .btn-success {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
    }
    
    .card-header {
        background-color: #003a5d !important;
        color: white !important;
    }
    
    .form-control:focus {
        border-color: #003a5d !important;
        box-shadow: 0 0 0 0.2rem rgba(0, 58, 93, 0.25) !important;
    }
    
    .permiso-info {
        background: linear-gradient(135deg, #003a5d 0%, #002b47 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .horas-field {
        display: none;
    }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row" style="display: grid; grid-template-columns: 230px 1fr; min-height: 100vh;">
            <!-- Sidebar izquierdo-->
            <?php include '../includes/sidebar.php'; ?>
            
            <main style="grid-column: 2; margin: 0 auto; max-width: 100%; padding: 0 1rem;">
                <div style="max-width: 800px; margin: 0 auto;">
                    <!-- Encabezado con botón de volver -->
                    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                        <h1 class="h2">
                            <i class="bi bi-check2-circle me-2"></i>Solicitar Permiso
                        </h1>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <a href="portal.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Volver al Portal
                            </a>
                        </div>
                    </div>
                    
                    <div class="card">
                    <?php /*
                        <div class="card-header text-center">
                            <h3 class="mb-0"><i class="bi bi-calendar-check me-2"></i>Sistema de Permisos</h3>
                            <p class="mb-0"><?php echo SITE_NAME; ?></p>
                        </div>
                        */ ?>
                        <div class="card-body">
                            <?php if ($error): ?>
                            <div class="alert alert-danger">
                                <i class="bi bi-exclamation-triangle me-2"></i><?php echo $error; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($success): ?>
                            <div class="alert alert-success">
                                <i class="bi bi-check-circle me-2"></i><?php echo $success; ?>
                            </div>
                            <?php endif; ?>
                            
                            <?php if ($empleado_info): ?>
                            <?php /*
                            <!-- Información del usuario -->
                            <div class="permiso-info">
                                <h5><i class="bi bi-person-circle me-2"></i>Información del Solicitante</h5>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong>Nombre:</strong> <?php echo htmlspecialchars($empleado_info['nombre']); ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Documento:</strong> <?php echo htmlspecialchars($empleado_info['CC']); ?>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-2">
                                            <strong>Departamento:</strong> <?php echo htmlspecialchars($empleado_info['cargo']); ?>
                                        </div>
                                        <div class="mb-2">
                                            <strong>Compañía:</strong> 
                                            <?php 
                                            $companias = [1 => 'AZC', 2 => 'BilingueLaw', 3 => 'LawyerDesk', 4 => 'AZC Legal', 5 => 'Matiz LegalTech'];
                                            echo $companias[$empleado_info['company']] ?? 'No asignada';
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            */ ?>
                            
                            <!-- Formulario de solicitud de permiso -->
                            <form method="POST" action="" id="permisoForm" enctype="multipart/form-data">
                                <div class="mb-4">
                                    <label for="tipo_permiso" class="form-label fw-bold">Tipo de Permiso</label>
                                    <select class="form-select form-select-lg" id="tipo_permiso" name="tipo_permiso" required onchange="mostrarCampoHoras()">
                                        <option value="">Seleccione el tipo de permiso</option>
                                        <option value="no_remunerado">Permiso No Remunerado</option>
                                        <option value="remunerado">Permiso Remunerado</option>
                                        <option value="por_hora">Permiso por Horas</option>
                                        <option value="matrimonio">Día de Matrimonio</option>
                                        <option value="trabajo_casa">Trabajo en Casa</option>
                                    </select>
                                </div>
                                
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <label for="fecha_inicio" class="form-label fw-bold">Fecha de Inicio</label>
                                        <input type="date" class="form-control" id="fecha_inicio" name="fecha_inicio" required>
                                    </div>
                                    <div class="col-md-6">
                                        <label for="fecha_fin" class="form-label fw-bold">Fecha de Fin</label>
                                        <input type="date" class="form-control" id="fecha_fin" name="fecha_fin" required>
                                    </div>
                                </div>
				<div class="mb-4 horas-field" id="campo_horas">
				    <div class="row">
				        <div class="col-md-6">
				            <label for="hora_inicio" class="form-label fw-bold">Hora de Inicio</label>
				            <input type="time" class="form-control" id="hora_inicio" name="hora_inicio" required>
				        </div>
				        <div class="col-md-6">
				            <label for="hora_fin" class="form-label fw-bold">Hora de Fin</label>
				            <input type="time" class="form-control" id="hora_fin" name="hora_fin" required>
				        </div>
				    </div>
				    <div class="form-text">Para permisos por horas, seleccione el rango horario del permiso</div>
				</div>
                                <div class="mb-4">
                                    <label for="motivo" class="form-label fw-bold">Motivo del Permiso</label>
                                    <textarea class="form-control" id="motivo" name="motivo" rows="4" placeholder="Describa detalladamente el motivo de su solicitud de permiso..." required></textarea>
                                    <div class="form-text">Sea específico y claro en su descripción</div>
                                </div>
				<div class="mb-4">
				    <label for="soporte" class="form-label fw-bold">Soporte</label>
				    <input type="file" class="form-control" id="soporte" name="soporte" accept="image/*,.pdf,.doc,.docx,.zip">
				    <div class="form-text">
				        Puede adjuntar imágenes (JPG, PNG), documentos (PDF, Word) o archivos ZIP. 
				        Tamaño máximo: 5MB.
				    </div>
				</div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle me-2"></i>
                                    <strong>Proceso de Aprobación:</strong> 
                                    <?php if (isset($empleado_info['company']) && $empleado_info['company'] == 2): ?>Su solicitud será revisada por su supervisor inmediato.
                                    <?php else: ?>
                                        Su solicitud será revisada primero por su supervisor inmediato y luego por Talento Humano.
                                    <?php endif; ?>
                                </div>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="portal.php" class="btn btn-secondary me-md-2">
                                        <i class="bi bi-arrow-left me-1"></i>Volver al Portal
                                    </a>
                                    <button type="submit" name="enviar_permiso" class="btn btn-success">
                                        <i class="bi bi-send me-1"></i>Enviar Solicitud
                                    </button>
                                </div>
                            </form>
                            <?php else: ?>
                            <div class="alert alert-warning text-center">
                                <i class="bi bi-exclamation-triangle me-2"></i>
                                No se pudo cargar la información del usuario. Por favor, contacte al administrador del sistema.
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
function mostrarCampoHoras() {
    const tipoPermiso = document.getElementById('tipo_permiso').value;
    const campoHoras = document.getElementById('campo_horas');
    const fechaFinInput = document.getElementById('fecha_fin');
    const fechaFinContainer = fechaFinInput.closest('.col-md-6');
    const fechaInicioInput = document.getElementById('fecha_inicio');
    
    if (tipoPermiso === 'por_hora') {
        campoHoras.style.display = 'block';
        fechaFinContainer.style.display = 'none'; // Ocultar fecha fin para permisos por horas
        
        //Igualar fecha_fin a fecha_inicio automáticamente
        fechaFinInput.value = fechaInicioInput.value;
        
        // Hacer requeridos los campos de hora
        document.getElementById('hora_inicio').required = true;
        document.getElementById('hora_fin').required = true;
    } else {
        campoHoras.style.display = 'none';
        fechaFinContainer.style.display = 'block'; // Mostrar fecha fin para otros permisos
        
        // No hacer requeridos los campos de hora
        document.getElementById('hora_inicio').required = false;
        document.getElementById('hora_fin').required = false;
    }
}

// Agregar evento para actualizar fecha_fin cuando cambie fecha_inicio en permisos por horas
document.getElementById('fecha_inicio').addEventListener('change', function() {
    const tipoPermiso = document.getElementById('tipo_permiso').value;
    const fechaFinInput = document.getElementById('fecha_fin');
    
    if (tipoPermiso === 'por_hora') {
        fechaFinInput.value = this.value;
    }
});
    
    // Validación de fechas
    document.getElementById('permisoForm').addEventListener('submit', function(e) {
        const fechaInicio = document.getElementById('fecha_inicio').value;
        const fechaFin = document.getElementById('fecha_fin').value;
        
        if (fechaInicio && fechaFin && fechaFin < fechaInicio) {
            e.preventDefault();
            alert('La fecha de fin no puede ser anterior a la fecha de inicio');
        }
    });
    
    // Establecer fecha mínima como hoy
    document.addEventListener('DOMContentLoaded', function() {
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('fecha_inicio').min = today;
        document.getElementById('fecha_fin').min = today;
        mostrarCampoHoras(); // Inicializar estado del campo horas
    });
    </script>
</body>
</html>