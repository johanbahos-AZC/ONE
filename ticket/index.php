<?php
require_once '../includes/config.php';
require_once '../includes/functions.php';

 $functions = new Functions();
 $error = '';
 $success = '';
 $empleado_info = null;
 $historial_tickets = [];

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $id_empleado = trim($_POST['id_empleado']);
    
    // Validar empleado
    $empleado_info = $functions->obtenerEmpleado($id_empleado);
    
    if (!$empleado_info || isset($empleado_info['error'])) {
        $error = "Empleado no encontrado en el sistema";
        $empleado_info = null;
    } else {
        // CORRECCIÓN: Mapear 'cargo' a 'departamento' para mantener compatibilidad
        $empleado_info['departamento'] = $empleado_info['cargo'];
        
        // Obtener historial de tickets del empleado
        $historial_tickets = $functions->obtenerTicketsPorEmpleado($id_empleado);
    }
}

// Procesar envío del ticket
if (isset($_POST['enviar_ticket'])) {
    $id_empleado = trim($_POST['id_empleado']);
    // Siempre usar 'soporte' como tipo de solicitud
    $tipo = 'soporte';
    $notas = trim($_POST['notas']);
    
    // Obtener información del empleado
    $empleado_info = $functions->obtenerEmpleado($id_empleado);
    
    if ($empleado_info && !isset($empleado_info['error'])) {
        // CORRECCIÓN: Usar 'cargo' como 'departamento'
        $departamento = $empleado_info['cargo'];
        
        $database = new Database();
        $conn = $database->getConnection();
        
        // Procesar imagen si se subió
        $imagen_ruta = null;
        if (isset($_FILES['imagen_ticket']) && $_FILES['imagen_ticket']['error'] === UPLOAD_ERR_OK) {
            $imagen_ruta = $functions->guardarImagenTicket($_FILES['imagen_ticket']);
        }
        
        // Código comentado para otros tipos de tickets
        /*
        // Manejar diferentes tipos de tickets
        if ($tipo == 'solicitud' || $tipo == 'offboarding') {
            // Múltiples items
            $items = $_POST['items'];
            $cantidades = $_POST['cantidades'];
            
            foreach ($items as $index => $item_id) {
                if (!empty($item_id)) {
                    $cantidad = $cantidades[$index];
                    
                    $query = "INSERT INTO tickets (empleado_id, empleado_nombre, empleado_departamento, item_id, cantidad, tipo, notas, imagen_ruta) 
                              VALUES (:empleado_id, :empleado_nombre, :empleado_departamento, :item_id, :cantidad, :tipo, :notas, :imagen_ruta)";
                    
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':empleado_id', $id_empleado);
                    $stmt->bindParam(':empleado_nombre', $empleado_info['nombre']);
                    $stmt->bindParam(':empleado_departamento', $departamento); // Usar cargo como departamento
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':cantidad', $cantidad);
                    $stmt->bindParam(':tipo', $tipo);
                    $stmt->bindParam(':notas', $notas);
                    $stmt->bindParam(':imagen_ruta', $imagen_ruta);
                    
                    if ($stmt->execute()) {
                        $ticket_id = $conn->lastInsertId();
                        $functions->registrarHistorial(
                            $ticket_id, 
                            $id_empleado, 
                            $item_id, 
                            $tipo, 
                            $cantidad, 
                            "Ticket creado: " . $notas
                        );
                    }
                }
            }
            
        } elseif ($tipo == 'intercambio') {
            // Intercambio con 2 items
            $item_devuelto_id = $_POST['item_devuelto_id'];
            $item_nuevo_id = $_POST['item_nuevo_id'];
            $cantidad = $_POST['cantidad_intercambio'];
            $mal_estado = isset($_POST['mal_estado']) ? 1 : 0;
            
            $query = "INSERT INTO tickets (empleado_id, empleado_nombre, empleado_departamento, item_id, item_devuelto_id, cantidad, tipo, notas, mal_estado, imagen_ruta) 
                      VALUES (:empleado_id, :empleado_nombre, :empleado_departamento, :item_id, :item_devuelto_id, :cantidad, :tipo, :notas, :mal_estado, :imagen_ruta)";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':empleado_id', $id_empleado);
            $stmt->bindParam(':empleado_nombre', $empleado_info['nombre']);
            $stmt->bindParam(':empleado_departamento', $departamento); // Usar cargo como departamento
            $stmt->bindParam(':item_id', $item_nuevo_id);
            $stmt->bindParam(':item_devuelto_id', $item_devuelto_id);
            $stmt->bindParam(':cantidad', $cantidad);
            $stmt->bindParam(':tipo', $tipo);
            $stmt->bindParam(':notas', $notas);
            $stmt->bindParam(':mal_estado', $mal_estado);
            $stmt->bindParam(':imagen_ruta', $imagen_ruta);
            
            if ($stmt->execute()) {
                $ticket_id = $conn->lastInsertId();
                $functions->registrarHistorial(
                    $ticket_id, 
                    $id_empleado, 
                    $item_nuevo_id, 
                    $tipo, 
                    $cantidad, 
                    "Intercambio creado: " . $notas
                );
            }
            
        } elseif
        */
        // Siempre procesar como soporte técnico
        {
            // Soporte técnico (sin items)
            $query = "INSERT INTO tickets (empleado_id, empleado_nombre, empleado_departamento, tipo, notas, imagen_ruta) 
                      VALUES (:empleado_id, :empleado_nombre, :empleado_departamento, :tipo, :notas, :imagen_ruta)";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':empleado_id', $id_empleado);
            $stmt->bindParam(':empleado_nombre', $empleado_info['nombre']);
            $stmt->bindParam(':empleado_departamento', $departamento); // Usar cargo como departamento
            $stmt->bindParam(':tipo', $tipo);
            $stmt->bindParam(':notas', $notas);
            $stmt->bindParam(':imagen_ruta', $imagen_ruta);
            
            if ($stmt->execute()) {
                $ticket_id = $conn->lastInsertId();
                $functions->registrarHistorial(
                    $ticket_id, 
                    $id_empleado, 
                    null, 
                    $tipo, 
                    0, 
                    "Ticket de soporte creado: " . $notas
                );
            }
        }
        
        $success = "Ticket(s) enviado(s) correctamente";
        
        // Recargar el historial después de crear un nuevo ticket
        $historial_tickets = $functions->obtenerTicketsPorEmpleado($id_empleado);
        
    } else {
        $error = "Empleado no válido";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Solicitar Item - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
    /* ESTILOS ESPECÍFICOS PARA BOTONES Y BADGES CON COLORES DEL ECOSISTEMA */
    
    /* BOTONES */
    .btn-primary {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
    }
    
    .btn-primary:hover {
        background-color: #002b47 !important;
        border-color: #002b47 !important;
    }
    
    .btn-success {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
    }
    
    .btn-success:hover {
        background-color: #157347 !important;
        border-color: #146c43 !important;
    }
    
    .btn-danger {
        background-color: #be1622 !important;
        border-color: #be1622 !important;
    }
    
    .btn-danger:hover {
        background-color: #a0121d !important;
        border-color: #a0121d !important;
    }
    
    .btn-secondary {
        background-color: #6c757d !important;
        border-color: #6c757d !important;
    }
    
    .btn-secondary:hover {
        background-color: #5a6268 !important;
        border-color: #545b62 !important;
    }
    
    /* ALERTAS */
    .alert-info {
        background-color: #d1ecf1 !important;
        border-color: #003a5d !important;
        color: #0c5460 !important;
    }
    
    .alert-success {
        background-color: #d4edda !important;
        border-color: #198754 !important;
        color: #155724 !important;
    }
    
    .alert-danger {
        background-color: #f8d7da !important;
        border-color: #be1622 !important;
        color: #721c24 !important;
    }
    
    /* CARD HEADER */
    .card-header {
        background-color: #003a5d !important;
        color: white !important;
    }
    
    /* FORM CONTROLS */
    .form-control:focus {
        border-color: #003a5d !important;
        box-shadow: 0 0 0 0.2rem rgba(0, 58, 93, 0.25) !important;
    }
    
    .form-select:focus {
        border-color: #003a5d !important;
        box-shadow: 0 0 0 0.2rem rgba(0, 58, 93, 0.25) !important;
    }
    
    /* FORM SECTIONS */
    .form-section {
        display: none;
        margin-bottom: 20px;
        padding: 15px;
        border: 1px solid #dee2e6;
        border-radius: 5px;
        background-color: #f8f9fa;
    }
    
    /* CHECKBOX PERSONALIZADO */
    .form-check-input:checked {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
    }
    
    .form-check-input:focus {
        border-color: #003a5d !important;
        box-shadow: 0 0 0 0.2rem rgba(0, 58, 93, 0.25) !important;
    }
    
    /* ESTILOS PARA SUBIDA DE IMÁGENES */
    .image-upload-container {
        border: 2px dashed #dee2e6;
        border-radius: 8px;
        padding: 20px;
        text-align: center;
        background-color: #f8f9fa;
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .image-upload-container:hover {
        border-color: #003a5d;
        background-color: #e9ecef;
    }
    
    .image-upload-container.drag-over {
        border-color: #003a5d;
        background-color: #e3f2fd;
        border-style: solid;
    }
    
    .image-preview {
        max-width: 100%;
        max-height: 200px;
        margin-top: 15px;
        display: none;
        border-radius: 5px;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }
    
    .upload-icon {
        font-size: 48px;
        color: #6c757d;
        margin-bottom: 10px;
    }
    
    /* ESTILOS PARA HISTORIAL DE TICKETS */
    .historial-container {
        max-height: 600px;
        overflow-y: auto;
    }
    
    .ticket-card {
        border: 1px solid #dee2e6;
        border-radius: 8px;
        margin-bottom: 15px;
        transition: all 0.3s ease;
        background-color: #fff;
        cursor: pointer;
    }
    
    .ticket-card:hover {
        box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        transform: translateY(-2px);
        border-color: #003a5d;
    }
    
    .ticket-header {
        background-color: #f8f9fa;
        padding: 10px 15px;
        border-bottom: 1px solid #dee2e6;
        border-radius: 8px 8px 0 0;
    }
    
    .ticket-body {
        padding: 15px;
    }
    
    .ticket-info {
        font-size: 0.9rem;
        margin-bottom: 5px;
    }
    
    .ticket-info strong {
        color: #495057;
    }
    
    .badge-estado {
        font-size: 0.75rem;
        padding: 4px 8px;
    }
    
    .historial-title {
        color: #003a5d;
        border-bottom: 2px solid #003a5d;
        padding-bottom: 10px;
        margin-bottom: 20px;
    }
    
    .empty-historial {
        text-align: center;
        padding: 40px 20px;
        color: #6c757d;
    }
    
    .empty-historial i {
        font-size: 48px;
        margin-bottom: 15px;
        color: #dee2e6;
    }
    
    /* Estilos para el modal de detalles */
    .modal-ticket-img {
        max-width: 100%;
        max-height: 300px;
        border-radius: 5px;
        cursor: pointer;
    }
    
    .modal-ticket-img:hover {
        opacity: 0.9;
    }
    
    .info-row {
        padding: 8px 0;
        border-bottom: 1px solid #f0f0f0;
    }
    
    .info-row:last-child {
        border-bottom: none;
    }
    
    .info-label {
        font-weight: bold;
        color: #495057;
        min-width: 120px;
    }
    
    .info-value {
        color: #6c757d;
    }
    
    .historial-item {
        padding: 8px 12px;
        border-left: 3px solid #003a5d;
        background-color: #f8f9fa;
        margin-bottom: 8px;
        border-radius: 0 4px 4px 0;
    }
    
    .historial-fecha {
        font-size: 0.8rem;
        color: #6c757d;
    }
    </style>
</head>
<body class="ticket-body">
    <div class="container py-5">
        <div class="row justify-content-center">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header text-center">
                        <h3>Sistema de Tickets</h3>
                        <p class="mb-0"><?php echo SITE_NAME; ?></p>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>
                        
                        <?php if (!$empleado_info): ?>
                        <form method="POST" action="">
                            <div class="mb-3">
                                <label for="id_empleado" class="form-label">Número de Identificación</label>
                                <input type="text" class="form-control" id="id_empleado" name="id_empleado" required>
                                <div class="form-text">Ingrese su número de identificación para verificar</div>
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Verificar</button>
                        </form>
                        <?php else: ?>
                        <div class="row">
                            <!-- Formulario de creación de tickets -->
                            <div class="col-md-8">
                                <div class="alert alert-info">
                                    <strong>Empleado verificado:</strong> <?php echo $empleado_info['nombre']; ?><br>
                                    <strong>Departamento:</strong> <?php echo $empleado_info['cargo']; ?>
                                </div>
                                
                                <form method="POST" action="" id="ticketForm" enctype="multipart/form-data">
                                    <input type="hidden" name="id_empleado" value="<?php echo $_POST['id_empleado']; ?>">
                                    
                                    <!-- Campo de tipo de solicitud comentado -->
                                    <?php /*
                                    <div class="mb-3">
                                        <label for="tipo" class="form-label">Tipo de Solicitud</label>
                                        <select class="form-select" id="tipo" name="tipo" required onchange="mostrarSeccion()">
                                            <option value="">Seleccione un tipo</option>
                                            <option value="solicitud">Solicitud de Item(s)</option>
                                            <option value="offboarding">Offboarding (Devolución)</option>
                                            <option value="intercambio">Intercambio de Item</option>
                                            <option value="soporte">Solicitud de Soporte</option>
                                        </select>
                                    </div>
                                    */ ?>
                                    
                                    <!-- Sección para imagen -->
                                    <div class="mb-3">
                                        <label class="form-label">Imagen (Opcional)</label>
                                        <div class="image-upload-container" id="imageUploadContainer">
                                            <div class="upload-icon">
                                                <i class="bi bi-cloud-arrow-up"></i>
                                            </div>
                                            <p>Haz clic o arrastra una imagen aquí</p>
                                            <small class="text-muted">Formatos permitidos: JPG, PNG, GIF. Tamaño máximo: 5MB</small>
                                            <input type="file" id="imagen_ticket" name="imagen_ticket" accept="image/*" style="display: none;" onchange="previewImage(this)">
                                            <img id="imagePreview" class="image-preview" alt="Vista previa">
                                        </div>
                                    </div>
                                    
                                    <!-- Sección para Soporte (siempre visible) -->
                                    <div id="seccionSoporte" class="form-section" style="display: block;">
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle"></i> Para reportar problemas técnicos, equipos dañados, o asistencia general.
                                        </div>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="notas" class="form-label">Notas Adicionales</label>
                                        <textarea class="form-control" id="notas" name="notas" rows="3" placeholder="Describa su solicitud..." required></textarea>
                                    </div>
                                    
                                    <div class="d-grid gap-2">
                                        <button type="submit" name="enviar_ticket" class="btn btn-success">Enviar Solicitud</button>
                                        <a href="index.php" class="btn btn-danger">Cancelar</a>
                                    </div>
                                </form>
                            </div>
                            
                            <!-- Historial de tickets -->
                            <div class="col-md-4">
                                <div class="card">
                                    <div class="card-header">
                                        <h5 class="mb-0"><i class="bi bi-clock-history"></i> Historial de Tickets</h5>
                                    </div>
                                    <div class="card-body historial-container">
                                        <?php if (!empty($historial_tickets)): ?>
                                            <div class="historial-title">
                                                <h6>Últimos tickets de <?php echo $empleado_info['nombre']; ?></h6>
                                            </div>
                                            <?php foreach ($historial_tickets as $ticket): ?>
                                                <div class="ticket-card" onclick="mostrarDetallesTicket(<?php echo htmlspecialchars(json_encode($ticket)); ?>)">
                                                    <div class="ticket-header">
                                                        <div class="d-flex justify-content-between align-items-center">
                                                            <strong>Ticket #<?php echo $ticket['id']; ?></strong>
                                                            <?php 
                                                            $badge_class = [
                                                                'pendiente' => 'bg-warning',
                                                                'aprobado' => 'bg-success',
                                                                'rechazado' => 'bg-danger',
                                                                'completado' => 'bg-info'
                                                            ][$ticket['estado']] ?? 'bg-secondary';
                                                            ?>
                                                            <span class="badge badge-estado <?php echo $badge_class; ?>">
                                                                <?php echo ucfirst($ticket['estado']); ?>
                                                            </span>
                                                        </div>
                                                    </div>
                                                    <div class="ticket-body">
                                                        <div class="ticket-info">
                                                            <strong>Item/Asunto:</strong> 
                                                            <?php 
                                                            if ($ticket['tipo'] == 'email') {
                                                                echo htmlspecialchars($ticket['asunto'] ?? 'Sin asunto');
                                                            } else {
                                                                echo htmlspecialchars($ticket['item_nombre'] ?? 'Soporte técnico');
                                                            }
                                                            ?>
                                                        </div>
                                                        <div class="ticket-info">
                                                            <strong>Tipo:</strong> 
                                                            <span class="badge bg-primary">
                                                                <?php echo ucfirst($ticket['tipo'] == 'email' ? 'correo' : $ticket['tipo']); ?>
                                                            </span>
                                                        </div>
                                                        <div class="ticket-info">
                                                            <strong>Encargado:</strong> 
                                                            <?php 
                                                            if (!empty($ticket['responsable_id']) && $ticket['responsable_id'] != '0') {
                                                                echo htmlspecialchars($ticket['responsable_nombre'] ?? 'Sin asignar');
                                                            } else {
                                                                echo 'Soporte';
                                                            }
                                                            ?>
                                                        </div>
                                                        <div class="ticket-info">
                                                            <strong>Fecha:</strong> 
                                                            <?php echo date('d/m/Y H:i', strtotime($ticket['creado_en'])); ?>
                                                        </div>
                                                        <?php if (!empty($ticket['notas_admin'])): ?>
                                                            <div class="ticket-info mt-2">
                                                                <strong>Notas admin:</strong> 
                                                                <small class="text-muted"><?php echo htmlspecialchars(substr($ticket['notas_admin'], 0, 50)); ?>...</small>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <div class="empty-historial">
                                                <i class="bi bi-inbox"></i>
                                                <h6>No hay tickets anteriores</h6>
                                                <p class="text-muted">Este empleado no tiene tickets registrados en el sistema.</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para detalles del ticket -->
    <div class="modal fade" id="modalDetallesTicket" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Detalles del Ticket <span id="modalTicketId"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Empleado:</span>
                                <span class="info-value" id="modalEmpleado"></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Departamento:</span>
                                <span class="info-value" id="modalDepartamento"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Tipo:</span>
                                <span class="info-value" id="modalTipo"></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Estado:</span>
                                <span class="info-value" id="modalEstado"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Item/Asunto:</span>
                                <span class="info-value" id="modalItem"></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Cantidad:</span>
                                <span class="info-value" id="modalCantidad"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Responsable:</span>
                                <span class="info-value" id="modalResponsable"></span>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="info-row">
                                <span class="info-label">Fecha creación:</span>
                                <span class="info-value" id="modalFecha"></span>
                            </div>
                        </div>
                    </div>
                    
                    <div id="modalIntercambioInfo" style="display: none;">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">Item a devolver:</span>
                                    <span class="info-value" id="modalItemDevuelto"></span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">Estado item:</span>
                                    <span class="info-value" id="modalEstadoItem"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="info-row">
                        <span class="info-label">Notas del empleado:</span>
                        <div class="info-value" id="modalNotas"></div>
                    </div>
                    
                    <div class="info-row" id="modalNotasAdminRow" style="display: none;">
                        <span class="info-label">Notas del administrador:</span>
                        <div class="info-value" id="modalNotasAdmin"></div>
                    </div>
                    
                    <div class="info-row" id="modalImagenRow" style="display: none;">
                        <span class="info-label">Imagen adjunta:</span>
                        <div class="info-value">
                            <img id="modalImagen" src="" class="modal-ticket-img" alt="Imagen del ticket" onclick="ampliarImagen(this.src)">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para imagen ampliada -->
    <div class="modal fade" id="modalImagenAmpliada" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Imagen del Ticket</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="imagenAmpliada" src="" class="img-fluid" alt="Imagen ampliada">
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Funcionalidad para subida de imágenes
    document.getElementById('imageUploadContainer').addEventListener('click', function() {
        document.getElementById('imagen_ticket').click();
    });
    
    document.getElementById('imageUploadContainer').addEventListener('dragover', function(e) {
        e.preventDefault();
        this.classList.add('drag-over');
    });
    
    document.getElementById('imageUploadContainer').addEventListener('dragleave', function() {
        this.classList.remove('drag-over');
    });
    
    document.getElementById('imageUploadContainer').addEventListener('drop', function(e) {
        e.preventDefault();
        this.classList.remove('drag-over');
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            document.getElementById('imagen_ticket').files = files;
            previewImage({ files: files });
        }
    });
    
    function previewImage(input) {
        const file = input.files[0];
        const preview = document.getElementById('imagePreview');
        
        if (file) {
            const reader = new FileReader();
            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            }
            reader.readAsDataURL(file);
        } else {
            preview.style.display = 'none';
        }
    }
    
    // Validación del formulario antes de enviar
    document.getElementById('ticketForm').addEventListener('submit', function(e) {
        const notas = document.getElementById('notas').value;
        let isValid = true;
        
        if (!notas.trim()) {
            alert('Por favor, ingrese una descripción para su solicitud');
            isValid = false;
            e.preventDefault();
            return;
        }
        
        if (!isValid) {
            e.preventDefault();
        }
    });
    
    // Función para mostrar detalles del ticket
    function mostrarDetallesTicket(ticket) {
        // Llenar la información básica
        document.getElementById('modalTicketId').textContent = '#' + ticket.id;
        document.getElementById('modalEmpleado').textContent = ticket.empleado_nombre + ' (' + ticket.empleado_id + ')';
        document.getElementById('modalDepartamento').textContent = ticket.empleado_departamento;
        document.getElementById('modalTipo').innerHTML = '<span class="badge bg-primary">' + (ticket.tipo === 'email' ? 'correo' : ticket.tipo) + '</span>';
        
        // Estado con color
        const estadoClass = {
            'pendiente': 'bg-warning',
            'aprobado': 'bg-success',
            'rechazado': 'bg-danger',
            'completado': 'bg-info'
        }[ticket.estado] || 'bg-secondary';
        
        document.getElementById('modalEstado').innerHTML = '<span class="badge ' + estadoClass + '">' + ticket.estado + '</span>';
        
        // Item/Asunto
        const itemTexto = ticket.tipo === 'email' ? (ticket.asunto || 'Sin asunto') : (ticket.item_nombre || 'Soporte técnico');
        document.getElementById('modalItem').textContent = itemTexto;
        
        // Cantidad (si aplica)
        document.getElementById('modalCantidad').textContent = ticket.cantidad || 'N/A';
        
        // Responsable
        const responsable = (ticket.responsable_id && ticket.responsable_id != '0') ? 
            (ticket.responsable_nombre || 'Sin asignar') : 'Soporte';
        document.getElementById('modalResponsable').textContent = responsable;
        
        // Fecha
        document.getElementById('modalFecha').textContent = new Date(ticket.creado_en).toLocaleString('es-ES');
        
        // Información de intercambio (si aplica)
        if (ticket.tipo === 'intercambio') {
            document.getElementById('modalIntercambioInfo').style.display = 'block';
            // Aquí necesitarías obtener el nombre del item devuelto
            document.getElementById('modalItemDevuelto').textContent = ticket.item_devuelto_id ? 'Item ID: ' + ticket.item_devuelto_id : 'No especificado';
            document.getElementById('modalEstadoItem').innerHTML = ticket.mal_estado ? 
                '<span class="badge bg-danger">Mal estado</span>' : 
                '<span class="badge bg-success">Buen estado</span>';
        } else {
            document.getElementById('modalIntercambioInfo').style.display = 'none';
        }
        
        // Notas
        document.getElementById('modalNotas').textContent = ticket.notas || 'Sin notas';
        
        // Notas del administrador
        if (ticket.notas_admin) {
            document.getElementById('modalNotasAdminRow').style.display = 'block';
            document.getElementById('modalNotasAdmin').textContent = ticket.notas_admin;
        } else {
            document.getElementById('modalNotasAdminRow').style.display = 'none';
        }
        
        // Imagen (si existe)
        if (ticket.imagen_ruta) {
            document.getElementById('modalImagenRow').style.display = 'block';
            document.getElementById('modalImagen').src = ticket.imagen_ruta;
        } else {
            document.getElementById('modalImagenRow').style.display = 'none';
        }
        
        // Mostrar el modal
        const modal = new bootstrap.Modal(document.getElementById('modalDetallesTicket'));
        modal.show();
    }
    
    // Función para ampliar imagen
    function ampliarImagen(src) {
        document.getElementById('imagenAmpliada').src = src;
        const modal = new bootstrap.Modal(document.getElementById('modalImagenAmpliada'));
        modal.show();
    }
    </script>
</body>
</html>