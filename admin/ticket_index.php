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

// Procesar envío del ticket
if (isset($_POST['enviar_ticket']) && $empleado_info) {
    $id_empleado = $empleado_info['CC']; // Usar el CC del empleado actual
    // Siempre usar 'soporte' como tipo de solicitud
    $tipo = 'soporte';
    $notas = trim($_POST['notas']);
    
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
                $stmt->bindParam(':empleado_departamento', $empleado_info['cargo']);
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
        $stmt->bindParam(':empleado_departamento', $empleado_info['cargo']);
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
        
    } else
    */
    // Siempre procesar como soporte técnico
    {
        // Soporte técnico (sin items)
        $query = "INSERT INTO tickets (empleado_id, empleado_nombre, empleado_departamento, tipo, notas, imagen_ruta) 
                  VALUES (:empleado_id, :empleado_nombre, :empleado_departamento, :tipo, :notas, :imagen_ruta)";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':empleado_id', $id_empleado);
        $stmt->bindParam(':empleado_nombre', $empleado_info['nombre']);
        $stmt->bindParam(':empleado_departamento', $empleado_info['cargo']);
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
    
    // Limpiar el formulario después del envío exitoso
    echo '<script>setTimeout(function(){ document.getElementById("ticketForm").reset(); }, 1000);</script>';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crear Ticket - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
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
    
    /* ESTILOS PARA INFORMACIÓN DEL USUARIO */
    .user-info-card {
        background: linear-gradient(135deg, #003a5d 0%, #002b47 100%);
        color: white;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
    }
    
    .user-info-card h5 {
        margin-bottom: 15px;
        font-weight: bold;
    }
    
    .user-info-item {
        margin-bottom: 8px;
        font-size: 0.95rem;
    }
    
    .user-info-item strong {
        display: inline-block;
        width: 120px;
    }
    
    /* RESPONSIVE */
    @media (max-width: 768px) {
        .user-info-item strong {
            width: 100px;
        }
        
        .container {
            padding: 10px;
        }
    }
    /* Estilos para validación de stock */
.is-valid {
    border-color: #198754 !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%23198754' d='M2.3 6.73L.6 4.53c-.4-1.04.46-1.4 1.1-.8l1.1 1.4 3.4-3.8c.6-.63 1.6-.27 1.2.7l-4 4.6c-.43.5-.8.4-1.1.1z'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.is-invalid {
    border-color: #be1622 !important;
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23be1622'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath d='m5.8 3.6.4.4.4-.4'/%3e%3cpath d='M6 7v1'/%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right calc(0.375em + 0.1875rem) center;
    background-size: calc(0.75em + 0.375rem) calc(0.75em + 0.375rem);
}

.is-warning {
    border-color: #ffc107 !important;
}

.stock-warning {
    font-size: 0.8rem;
    padding: 4px 8px;
    background-color: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 4px;
    margin-top: 5px;
}

.stock-error {
    font-size: 0.8rem;
    padding: 4px 8px;
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    border-radius: 4px;
    margin-top: 5px;
}

.stock-success {
    font-size: 0.8rem;
    padding: 4px 8px;
    border: 1px solid #b3d9ff;
    border-radius: 4px;
    margin-top: 5px;
}

/* Estilo para items agotados en los selects */
select option:disabled {
    color: #be1622 !important;
    font-style: italic;
}

/* Mejora visual para los botones deshabilitados */
.btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Mejora visual para inputs deshabilitados */
.form-control:disabled {
    background-color: #e9ecef;
    opacity: 0.7;
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
                            <i class="bi bi-ticket-perforated me-2"></i>Crear Nuevo Ticket
                        </h1>
                        <div class="btn-toolbar mb-2 mb-md-0">
                            <a href="portal.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left me-1"></i>Volver al Portal
                            </a>
                        </div>
                    </div>
                    
                    <div class="card">
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
                            <!-- Formulario de creación de tickets -->
                            <form method="POST" action="" id="ticketForm" enctype="multipart/form-data">
                                <!-- Campo de tipo de solicitud comentado -->
                                <?php /*
                                <div class="mb-4">
                                    <label for="tipo" class="form-label fw-bold">Tipo de Solicitud</label>
                                    <select class="form-select form-select-lg" id="tipo" name="tipo" required onchange="mostrarSeccion()">
                                        <option value="">Seleccione un tipo de solicitud</option>
                                        <option value="solicitud">Solicitud de Item(s)</option>
                                        <option value="offboarding">Offboarding (Devolución)</option>
                                        <option value="intercambio">Intercambio de Item</option>
                                        <option value="soporte">Solicitud de Soporte Técnico</option>
                                    </select>
                                    <div class="form-text">Seleccione el tipo de solicitud que desea realizar</div>
                                </div>
                                */ ?>
                                
                                <!-- Sección para imagen -->
                                <div class="mb-4">
                                    <label class="form-label fw-bold">Imagen (Opcional)</label>
                                    <div class="image-upload-container" id="imageUploadContainer">
                                        <div class="upload-icon">
                                            <i class="bi bi-cloud-arrow-up"></i>
                                        </div>
                                        <p class="mb-1">Haz clic o arrastra una imagen aquí</p>
                                        <small class="text-muted">Formatos permitidos: JPG, PNG, GIF. Tamaño máximo: 5MB</small>
                                        <input type="file" id="imagen_ticket" name="imagen_ticket" accept="image/*" style="display: none;" onchange="previewImage(this)">
                                        <img id="imagePreview" class="image-preview" alt="Vista previa">
                                    </div>
                                </div>
                                
                                <!-- Sección para Soporte (siempre visible) -->
                                <div id="seccionSoporte" class="form-section" style="display: block;">
                                    <div class="alert alert-info">
                                        <i class="bi bi-info-circle me-2"></i> 
                                        <strong>Información:</strong> Utilice esta opción para reportar problemas técnicos, equipos dañados, o solicitar asistencia general del departamento de TI.
                                    </div>
                                </div>
                                
                                <div class="mb-4">
                                    <label for="notas" class="form-label fw-bold">Descripción de la Solicitud</label>
                                    <textarea class="form-control" id="notas" name="notas" rows="4" placeholder="Describa detalladamente su solicitud, incluyendo cualquier información relevante..." required></textarea>
                                    <div class="form-text">Sea lo más específico posible para agilizar el proceso</div>
                                </div>
                                
                                <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                    <a href="portal.php" class="btn btn-secondary me-md-2">
                                        <i class="bi bi-arrow-left me-1"></i>Volver al Portal
                                    </a>
                                    <button type="submit" name="enviar_ticket" class="btn btn-success">
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
    
    // Vista previa de imagen
    function previewImage(input) {
        const file = input.files[0];
        const preview = document.getElementById('imagePreview');
        
        if (file) {
            // Validar tamaño (5MB máximo)
            if (file.size > 5 * 1024 * 1024) {
                alert('La imagen es demasiado grande. El tamaño máximo permitido es 5MB.');
                input.value = '';
                return;
            }
            
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
    </script>
</body>
</html>