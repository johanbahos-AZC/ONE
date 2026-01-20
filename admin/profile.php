<?php
// Función de debug personalizada (para uso en archivos de log, no en la consola)
function debug_profile($mensaje, $data = null) {
    $debug_file = __DIR__ . '/../logs/profile_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $mensaje";
    
    if ($data !== null) {
        $log_message .= " | Data: " . print_r($data, true);
    }
    
    $log_message .= "\n";
    
    $log_dir = dirname($debug_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents($debug_file, $log_message, FILE_APPEND | LOCK_EX);
}

// Función para convertir texto a UTF-8
function toUTF8($text) {
    if (!mb_detect_encoding($text, 'UTF-8', true)) {
        return utf8_encode($text);
    }
    return $text;
}

try {
    require_once '../includes/auth.php';
    require_once '../includes/functions.php';
    require_once '../includes/validar_permiso.php';

    $auth = new Auth();
    $auth->redirectIfNotLoggedIn();

    $error = '';
    $success = '';
    $mostrarCambioPassword = false;
    $mostrarDocumentos = false;
    $mostrarTickets = false;
    $datosPerfilUsuario = null; // <-- VARIABLE RENOMBRADA

	// Obtener información del usuario actual
	 $database = new Database();
	 $conn = $database->getConnection();
	
	 $query_usuario = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name, c.nombre as nombre_cargo 
	                 FROM employee e 
	                 LEFT JOIN sedes s ON e.sede_id = s.id
	                 LEFT JOIN firm f ON e.id_firm = f.id 
	                 LEFT JOIN cargos c ON e.position_id = c.id
	                 WHERE e.id = ?";
	 $stmt_usuario = $conn->prepare($query_usuario);
	 $stmt_usuario->execute([$_SESSION['user_id']]);
	 $datosPerfilUsuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

    // Verificar permisos SOLO para ver el perfil
    if ($datosPerfilUsuario && !tienePermiso($conn, $datosPerfilUsuario['id'], $datosPerfilUsuario['role'], $datosPerfilUsuario['position_id'], 'profile', 'ver')) {
        $error = "No tienes permisos para ver esta información.";
        debug_profile("ACCESO DENEGADO: Permisos insuficientes para ver perfil");
    } elseif (!$datosPerfilUsuario) {
        $error = "No se pudo cargar la información del usuario. Sesión inválida o usuario no encontrado.";
        debug_profile("ERROR: No se pudo encontrar usuario con ID: " . $_SESSION['user_id']);
    }

    // Procesar formulario de cambio de contraseña si se envía
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['cambiar_password'])) {
        debug_profile("Procesando cambio de contraseña");
        
        $password_actual = $_POST['password_actual'] ?? '';
        $nueva_password = $_POST['nueva_password'] ?? '';
        $confirmar_password = $_POST['confirmar_password'] ?? '';
        
        if (empty($password_actual) || empty($nueva_password) || empty($confirmar_password)) {
            $error = "Todos los campos son obligatorios";
            debug_profile("ERROR cambio password: Campos vacíos");
        } elseif ($nueva_password !== $confirmar_password) {
            $error = "Las nuevas contraseñas no coinciden";
            debug_profile("ERROR cambio password: Contraseñas no coinciden");
        } elseif (strlen($nueva_password) < 8) {
            $error = "La nueva contraseña debe tener al menos 8 caracteres";
            debug_profile("ERROR cambio password: Contraseña muy corta");
        } else {
            // Verificar contraseña actual
            $password_correcta = password_verify($password_actual, $datosPerfilUsuario['password']) || 
                              $password_actual === $datosPerfilUsuario['password'] || 
                              $password_actual === 'z' . $datosPerfilUsuario['CC'] . 'Z@!$';
            
            if ($password_correcta) {
                $query = "UPDATE employee SET password = :password, updated_at = NOW() WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':password', $nueva_password);
                $stmt->bindParam(':id', $datosPerfilUsuario['id']);
                
                if ($stmt->execute()) {
                    $success = "Contraseña cambiada correctamente";
                    $mostrarCambioPassword = false;
                    debug_profile("Contraseña cambiada exitosamente");
                } else {
                    $error = "Error al cambiar la contraseña en la base de datos";
                    debug_profile("ERROR cambio password: Error en ejecución UPDATE");
                }
            } else {
                $error = "La contraseña actual es incorrecta";
                debug_profile("ERROR cambio password: Contraseña actual incorrecta");
            }
        }
        
        if ($error) {
            $mostrarCambioPassword = true;
        }
    }

    // Procesar generación de certificado laboral si se envía
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['generar_certificado'])) {
        debug_profile("Procesando generación de certificado laboral");
        
        try {
            // Calcular antigüedad
            $fecha_ingreso = new DateTime($datosPerfilUsuario['created_at']);
            $fecha_actual = new DateTime();
            $antiguedad = $fecha_ingreso->diff($fecha_actual);
            
            $anos = $antiguedad->y;
            $meses = $antiguedad->m;
            
            $texto_antiguedad = "";
            if ($anos > 0) {
                $texto_antiguedad .= "$anos año" . ($anos > 1 ? "s" : "");
            }
            if ($meses > 0) {
                $texto_antiguedad .= ($texto_antiguedad ? ", " : "") . "$meses mes" . ($meses > 1 ? "es" : "");
            }
            
            debug_profile("Antigüedad calculada: " . $texto_antiguedad);
            
            // Verificar si FPDF está disponible
            $fpdf_path = '../includes/FPDF/fpdf.php';
            if (!file_exists($fpdf_path)) {
                throw new Exception("La librería FPDF no está disponible en: " . $fpdf_path);
            }
            
            require_once $fpdf_path;
            debug_profile("FPDF cargado correctamente");
            
            // Crear PDF con soporte UTF-8
            $pdf = new FPDF();
            $pdf->AddPage();
            
            // Insertar logo en esquina superior derecha
            $logo = __DIR__ . '/../assets/images/logo_main.png';
            debug_profile("Buscando logo en: " . $logo);
            
            if (file_exists($logo)) {
                $pdf->Image($logo, $pdf->GetPageWidth() - 40, 10, 35);
                debug_profile("Logo insertado en PDF");
            } else {
                debug_profile("Logo no encontrado");
            }
            
            // Encabezado
            $pdf->SetFont('Arial', 'B', 16);
            $pdf->Cell(0, 10, toUTF8('CERTIFICADO LABORAL'), 0, 1, 'C');
            $pdf->Ln(10);
            
            // Fecha
            $pdf->SetFont('Arial', '', 12);
            $meses_es = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio',
                        'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
            $pdf->Cell(0, 10, toUTF8(date('d') . ' de ' . $meses_es[date('n')-1] . ' de ' . date('Y')), 0, 1, 'R');
            $pdf->Ln(10);
            
            // Destinatario
            $pdf->SetFont('Arial', '', 12);
            $pdf->MultiCell(0, 8, toUTF8('A quien corresponda:'), 0, 'L');
            $pdf->Ln(5);
            
            // Cuerpo del certificado
            $pdf->SetFont('Arial', '', 12);
            $nombre_completo = toUTF8($datosPerfilUsuario['first_Name'] . ' ' . 
                              ($datosPerfilUsuario['second_Name'] ? $datosPerfilUsuario['second_Name'] . ' ' : '') . 
                              $datosPerfilUsuario['first_LastName'] . ' ' . 
                              ($datosPerfilUsuario['second_LastName'] ? $datosPerfilUsuario['second_LastName'] : ''));
            
            $tipo_documento = toUTF8('Cédula de Ciudadanía');
            if (isset($datosPerfilUsuario['type_CC'])) {
                if ($datosPerfilUsuario['type_CC'] == 2) $tipo_documento = toUTF8('Pasaporte');
                if ($datosPerfilUsuario['type_CC'] == 3) $tipo_documento = toUTF8('Cédula de Extranjería');
            }
            
            debug_profile("Generando certificado para: " . $nombre_completo);
            
		 $texto_certificado = toUTF8("Por medio de la presente, AZC LEGAL S.A.S. " .
		                    "identificada con NIT 900.680.284-6, " .
		                    "certifica que $nombre_completo, " .
		                    "identificado(a) con $tipo_documento No. " . ($datosPerfilUsuario['CC'] ?? 'N/A') . ", " .
		                    "labora en nuestra compañía desde el " . 
		                    date('d/m/Y', strtotime($datosPerfilUsuario['created_at'])) . 
		                    " desempeñando el cargo de " . ($datosPerfilUsuario['nombre_cargo'] ?? 'N/A') . ".\n\n");
            
            $texto_certificado .= toUTF8("Durante su vinculación con la empresa, " . 
                                 $datosPerfilUsuario['first_Name'] . " " . $datosPerfilUsuario['first_LastName'] . 
                                 "ha demostrado ser una persona responsable, comprometida " .
                                 "y con excelentes aptitudes profesionales.\n\n");
            
            if ($texto_antiguedad) {
                $texto_certificado .= toUTF8("Su antigüedad en la empresa es de $texto_antiguedad.\n\n");
            }
            
            $texto_certificado .= toUTF8("El presente certificado se expide a solicitud del interesado para los fines que estime convenientes.");
            
            $pdf->MultiCell(0, 8, iconv('UTF-8', 'windows-1252', $texto_certificado), 0, 'J');
            $pdf->Ln(15);
            
            // Firma
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, toUTF8('Atentamente,'), 0, 1, 'C');
            $pdf->Ln(15);
            
            $pdf->SetFont('Arial', 'B', 12);
            $pdf->Cell(0, 10, toUTF8('Gerente General'), 0, 1, 'C');
            $pdf->SetFont('Arial', '', 12);
            $pdf->Cell(0, 10, toUTF8('AZC LEGAL S.A.S.'), 0, 1, 'C');
            $pdf->Cell(0, 10, toUTF8('NIT: 900.680.284-6'), 0, 1, 'C');
            
            // Generar contenido del PDF en memoria
            $pdf_content = $pdf->Output('S'); // 'S' para obtener como string
            
            debug_profile("PDF generado en memoria, tamaño: " . strlen($pdf_content) . " bytes");
            
            // Guardar en la tabla files como BLOB
            $file_name = 'certificado_laboral_' . ($datosPerfilUsuario['CC'] ?? 'user') . '_' . date('Ymd_His') . '.pdf';
            $file_hash = hash('sha256', $pdf_content);
            $file_size = strlen($pdf_content);
            $mime_type = 'application/pdf';
            $description = toUTF8('Certificado laboral generado automáticamente');
            
            $query = "INSERT INTO files (
                        id_employee, file_type, file_name, file_content, 
                        file_hash, file_size, mime_type, description, 
                        uploaded_by, created_at
                      ) VALUES (
                        :id_employee, 'certificado_laboral', :file_name, :file_content,
                        :file_hash, :file_size, :mime_type, :description,
                        :uploaded_by, NOW()
                      )";
            
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id_employee', $datosPerfilUsuario['id']);
            $stmt->bindParam(':file_name', $file_name);
            $stmt->bindParam(':file_content', $pdf_content, PDO::PARAM_LOB);
            $stmt->bindParam(':file_hash', $file_hash);
            $stmt->bindParam(':file_size', $file_size);
            $stmt->bindParam(':mime_type', $mime_type);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':uploaded_by', $datosPerfilUsuario['id']);
            
            if ($stmt->execute()) {
                $file_id = $conn->lastInsertId();
                $success = "Certificado laboral generado correctamente. " .
                          "<a href='../includes/descargar_acta.php?hash=$file_hash' target='_blank' class='btn btn-sm btn-success'>" .
                          "<i class='bi bi-download'></i> Descargar certificado</a>";
                debug_profile("Certificado guardado en BD con ID: " . $file_id);
            } else {
                $error_info = $stmt->errorInfo();
                throw new Exception("Error al guardar certificado en BD: " . $error_info[2]);
            }
            
        } catch (Exception $e) {
            $error = "Error al generar el certificado: " . $e->getMessage();
            debug_profile("ERROR generando certificado: " . $e->getMessage());
        }
    }

    // Determinar qué sección mostrar basado en los parámetros GET
    if (isset($_GET['cambiar_password'])) {
        $mostrarCambioPassword = true;
        debug_profile("Mostrando sección de cambio contraseña");
    } elseif (isset($_GET['documentos'])) {
        $mostrarDocumentos = true;
        debug_profile("Mostrando sección de documentos");
    } elseif (isset($_GET['tickets'])) {
        $mostrarTickets = true;
        debug_profile("Mostrando sección de tickets");
    }

    debug_profile("=== FINALIZANDO PROFILE.PHP ===");

} catch (Exception $e) {
    $error = "Error crítico: " . $e->getMessage();
    debug_profile("ERROR CRÍTICO: " . $e->getMessage());
}

// Funciones para manejo de fotos
function obtenerFotoUsuario($photo_name, $default = 'default_avatar.png') {
    if ($photo_name && !empty($photo_name)) {
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/photos/' . $photo_name;
        if (file_exists($file_path)) {
            return '/uploads/photos/' . $photo_name;
        }
    }
    
    // Verificar si existe el avatar por defecto
    $default_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/' . $default;
    if (!file_exists($default_path)) {
        crearAvatarPorDefecto();
    }
    
    return '/assets/images/' . $default;
}

function crearAvatarPorDefecto() {
    $default_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/default_avatar.png';
    
    if (!file_exists($default_path)) {
        $image = imagecreate(100, 100);
        $background = imagecolorallocate($image, 108, 117, 125);
        $text_color = imagecolorallocate($image, 255, 255, 255);
        
        imagefilledellipse($image, 50, 50, 90, 90, $background);
        imagestring($image, 3, 35, 40, 'USR', $text_color);
        
        $assets_dir = dirname($default_path);
        if (!is_dir($assets_dir)) {
            mkdir($assets_dir, 0755, true);
        }
        
        imagepng($image, $default_path);
        imagedestroy($image);
    }
}

// Crear avatar por defecto si no existe
crearAvatarPorDefecto();

?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Perfil - <?php echo defined('SITE_NAME') ? SITE_NAME : 'Sistema'; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
        .info-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border-left: 4px solid #007bff;
        }
        .info-label {
            font-weight: bold;
            color: #495057;
            min-width: 150px;
        }
        .info-value {
            color: #212529;
        }
        .action-btn {
            margin: 0.25rem;
        }
        .badge-role {
            font-size: 0.8em;
            padding: 0.4em 0.8em;
        }
        .documentos-section {
            max-height: 400px;
            overflow-y: auto;
        }
         /* ESTILOS ESPECÍFICOS PARA BADGES DE ACTIVO FIJO Y ROL */
	    .badge.bg-success {
	        background-color: #198754 !important;
	    }
	    
	    .badge.bg-danger {
	        background-color: #be1622 !important;
	    }
	    
	    .badge.bg-primary {
	        background-color: #003a5d !important;
	    }
	    
	    .badge.bg-warning {
	        background-color: #ffc107 !important;
	        color: #353132 !important;
	    }
	    
	    .badge.bg-info {
	        background-color: #003a5d !important;
	        opacity: 0.8;
	    }
	    
	    .badge.bg-secondary {
	        background-color: #6c757d !important;
	    }
	    /* ESTILOS ESPECÍFICOS PARA BOTONES CON COLORES DEL ECOSISTEMA */
    .btn-primary {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
    }
    
    .btn-primary:hover {
        background-color: #002b47 !important;
        border-color: #002b47 !important;
    }
    
    .btn-success {
        background-color: #198754 !important;
        border-color: #198754 !important;
    }
    
    .btn-success:hover {
        background-color: #157347 !important;
        border-color: #146c43 !important;
    }
    
    .btn-warning {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
        color: white !important;
    }
    
    .btn-warning:hover {
        background-color: #ffca2c !important;
        border-color: #ffc720 !important;
        color: #353132 !important;
    }
    
    .btn-outline-primary {
        border-color: #003a5d !important;
        color: #003a5d !important;
    }
    
    .btn-outline-primary:hover {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
        color: white !important;
    }
    
    .btn-outline-success {
        border-color: #198754 !important;
        color: #198754 !important;
    }
    
    .btn-outline-success:hover {
        background-color: #198754 !important;
        border-color: #198754 !important;
        color: white !important;
    }
    /* Estilos para historial de tickets en perfil */
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

.empty-historial {
    text-align: center;
    padding: 40px 20px;
    color: #6c757d;
}
/* Estilos para el modal de detalles del ticket */
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

.modal-ticket-img {
    max-width: 100%;
    max-height: 300px;
    border-radius: 5px;
    cursor: pointer;
    transition: transform 0.2s;
}

.modal-ticket-img:hover {
    transform: scale(1.02);
    box-shadow: 0 2px 5px rgba(0,0,0,0.2);
}
    </style>
</head>
<script>
// DEPURACIÓN CRÍTICA: Verificar el estado de la variable $usuario justo antes de renderizar el HTML
console.log("🔍 ESTADO DE \$usuario JUSTO ANTES DEL HTML:", <?php echo json_encode($usuario, JSON_PRETTY_PRINT); ?>);
</script>
<body>
    <?php 
    if (file_exists('../includes/header.php')) {
        include '../includes/header.php'; 
    }
    ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php 
            if (file_exists('../includes/sidebar.php')) {
                include '../includes/sidebar.php'; 
            }
            ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Mi Perfil</h1>
                    
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <h5 class="alert-heading">Error</h5>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo $success; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php endif; ?>
                
                <div class="container">
                    <?php if ($mostrarCambioPassword && $datosPerfilUsuario): ?>
                    <!-- Sección de Cambio de Contraseña -->
                    <div class="row mb-4">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header text-dark" style="background-color: #e9ecef;">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-shield-lock"></i> Cambiar Contraseña
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <form method="POST" action="">
                                        <div class="row">
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="password_actual" class="form-label">Contraseña Actual *</label>
                                                    <input type="password" class="form-control" id="password_actual" name="password_actual" required>
                                                    <small class="form-text text-muted">Si es nueva, use: z[su documento]Z@!$</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="nueva_password" class="form-label">Nueva Contraseña *</label>
                                                    <input type="password" class="form-control" id="nueva_password" name="nueva_password" required minlength="8">
                                                    <small class="form-text text-muted">Mínimo 8 caracteres</small>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label for="confirmar_password" class="form-label">Confirmar Nueva Contraseña *</label>
                                                    <input type="password" class="form-control" id="confirmar_password" name="confirmar_password" required minlength="8">
                                                </div>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between">
                                            <button type="button" class="btn btn-secondary" onclick="window.location.href='profile.php'">
                                                <i class="bi bi-arrow-left"></i> Volver al Perfil
                                            </button>
                                            <button type="submit" name="cambiar_password" class="btn btn-warning">
                                                <i class="bi bi-key-fill"></i> Cambiar Contraseña
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($mostrarDocumentos && $datosPerfilUsuario): ?>
                    <!-- Sección de Documentos -->
                    <div class="row">
                        <div class="col-12">
                            <div class="card">
                                <div class="card-header text-black" style="background-color: #e9ecef;">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-folder"></i> Mis Documentos
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <!-- Generar Certificado Laboral -->
                                    <div class="mb-4">
                                        <h6>Generar Certificado Laboral</h6>
                                        <form method="POST" action="">
                                            <p class="text-muted">
                                                Genere un certificado laboral con su información actual. 
                                                Este certificado incluirá sus datos personales, cargo, fecha de ingreso y antigüedad en la empresa.
                                            </p>
                                            <button type="submit" name="generar_certificado" class="btn btn-primary">
                                                <i class="bi bi-file-earmark-pdf"></i> Generar Certificado Laboral
                                            </button>
                                            <button type="button" class="btn btn-secondary" onclick="window.location.href='profile.php'">
                                                <i class="bi bi-arrow-left"></i> Volver al Perfil
                                            </button>
                                        </form>
                                    </div>
                                    
                                    <hr>
                                    
                                    <!-- Lista de Documentos Existentes -->
                                    <h6>Mis Documentos</h6>
                                    <?php
                                    if (class_exists('Functions')) {
                                        $functions = new Functions();
                                        $archivos = $functions->obtenerArchivosPorUsuario($datosPerfilUsuario['id']);
                                        
                                        if (!empty($archivos)): 
                                    ?>
                                        <div class="table-responsive documentos-section">
                                            <table class="table table-striped">
                                                <thead>
                                                    <tr>
                                                        <th>Nombre</th>
                                                        <th>Tipo</th>
                                                        <th>Descripción</th>
                                                        <th>Fecha</th>
                                                        <th>Acciones</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($archivos as $archivo): 
                                                        $tipo_texto = [
                                                            'documento_identidad' => 'Documento ID',
                                                            'contrato' => 'Contrato',
                                                            'activo_fijo_entrega' => 'Acta Activo Fijo',
                                                            'certificado_laboral' => 'Certificado Laboral',
                                                            'otros' => 'Otros'
                                                        ];
                                                    ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($archivo['file_name']); ?></td>
                                                        <td><span class="badge bg-secondary"><?php echo $tipo_texto[$archivo['file_type']] ?? $archivo['file_type']; ?></span></td>
                                                        <td><?php echo htmlspecialchars($archivo['description'] ?? ''); ?></td>
                                                        <td><?php echo date('d/m/Y H:i', strtotime($archivo['created_at'])); ?></td>
                                                        <td>
                                                            <?php if (isset($archivo['file_hash'])): ?>
                                                            <a href="../includes/descargar_acta.php?hash=<?php echo $archivo['file_hash']; ?>" class="btn btn-sm btn-primary" target="_blank">
                                                                <i class="bi bi-download"></i> Descargar
                                                            </a>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                        </div>
                                    <?php else: ?>
                                        <div class="alert alert-info">
                                            <i class="bi bi-info-circle"></i> No hay documentos disponibles.
                                        </div>
                                    <?php endif;
                                    } else { ?>
                                        <div class="alert alert-warning">
                                            <i class="bi bi-exclamation-triangle"></i> No se pudo cargar la lista de documentos.
                                        </div>
                                    <?php } ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <?php elseif ($mostrarTickets && $datosPerfilUsuario): ?>
            <!-- Sección de Tickets -->
            <div class="row">
                <div class="col-12">
                    <div class="card">
                        <div class="card-header text-black" style="background-color: #e9ecef;">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-ticket-perforated me-2"></i>Mis Tickets
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-center mb-4">
                                <p class="text-muted mb-0">
                                    Historial de todos tus tickets creados en el sistema
                                </p>
                                <button type="button" class="btn btn-secondary" onclick="window.location.href='profile.php'">
                                    <i class="bi bi-arrow-left"></i> Volver al Perfil
                                </button>
                            </div>
                            
                            <?php
                            // Obtener tickets del usuario
                            if (class_exists('Functions')) {
                                $functions = new Functions();
                                $tickets_usuario = $functions->obtenerTicketsPorEmpleado($datosPerfilUsuario['CC']);
                                
                                if (!empty($tickets_usuario)): 
                            ?>
                                <div class="historial-container" style="max-height: 600px; overflow-y: auto;">
                                    <?php foreach ($tickets_usuario as $ticket): ?>
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
                                </div>
                            <?php else: ?>
                                <div class="empty-historial text-center py-5">
                                    <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                                    <h6>No hay tickets registrados</h6>
                                    <p class="text-muted">No has creado ningún ticket en el sistema.</p>
                                </div>
                            <?php endif;
                            } else { ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i> No se pudo cargar la información de tickets.
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                </div>
            </div>
                    
<?php elseif ($datosPerfilUsuario): ?>
<div class="row">
    <!-- Columna 1: Información Personal con Foto -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header text-black" style="background-color: #e9ecef;">
                <h5 class="card-title mb-0">
                    <i class="bi bi-person-vcard me-2"></i>Información Personal
                </h5>
            </div>
            <div class="card-body">
                <div class="text-center mb-4">
                    <?php 
                    $foto_perfil = obtenerFotoUsuario($datosPerfilUsuario['photo'] ?? null);
                    ?>
                    <img src="<?php echo $foto_perfil; ?>" 
                         class="rounded-circle mb-3" 
                         style="width: 120px; height: 120px; object-fit: cover; border: 3px solid #007bff;"
                         alt="Foto de perfil">
                    <h4><?php echo htmlspecialchars($datosPerfilUsuario['first_Name'] . ' ' . $datosPerfilUsuario['first_LastName']); ?></h4>
                    <p class="text-muted"><?php echo htmlspecialchars($datosPerfilUsuario['nombre_cargo'] ?? ''); ?></p>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-borderless">
                        <tr>
                            <td class="info-label">Nombre Completo:</td>
                            <td class="info-value">
                                <?php echo htmlspecialchars($datosPerfilUsuario['first_Name'] . ' ' . ($datosPerfilUsuario['second_Name'] ? $datosPerfilUsuario['second_Name'] . ' ' : '') . $datosPerfilUsuario['first_LastName'] . ' ' . ($datosPerfilUsuario['second_LastName'] ? $datosPerfilUsuario['second_LastName'] : '')); ?>
                            </td>
                        </tr>
                        <?php if (isset($datosPerfilUsuario['type_CC'])): ?>
                        <tr>
                            <td class="info-label">Tipo Documento:</td>
                            <td class="info-value">
                                <?php 
                                $tipo_doc = [
                                    1 => 'Cédula de Ciudadanía',
                                    2 => 'Pasaporte', 
                                    3 => 'Cédula de Extranjería'
                                ];
                                echo $tipo_doc[$datosPerfilUsuario['type_CC']] ?? 'No especificado';
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($datosPerfilUsuario['CC'])): ?>
                        <tr>
                            <td class="info-label">Número Documento:</td>
                            <td class="info-value"><?php echo htmlspecialchars($datosPerfilUsuario['CC']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['birthdate'])): ?>
                        <tr>
                            <td class="info-label">Fecha Nacimiento:</td>
                            <td class="info-value">
                                <?php echo date('d/m/Y', strtotime($datosPerfilUsuario['birthdate'])); ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (isset($datosPerfilUsuario['dval'])): ?>
                        <tr>
                            <td class="info-label">Estado Civil:</td>
                            <td class="info-value">
                                <?php 
                                $estados_civiles = [
                                    1 => 'Soltero/a',
                                    2 => 'Casado/a',
                                    3 => 'Divorciado/a', 
                                    4 => 'Viudo/a',
                                    5 => 'Unión Libre'
                                ];
                                echo $estados_civiles[$datosPerfilUsuario['dval']] ?? 'No especificado';
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="info-label">País:</td>
                            <td class="info-value"><?php echo htmlspecialchars($datosPerfilUsuario['country'] ?? 'Colombia'); ?></td>
                        </tr>
                        <?php if (!empty($datosPerfilUsuario['city'])): ?>
                        <tr>
                            <td class="info-label">Ciudad:</td>
                            <td class="info-value"><?php echo htmlspecialchars($datosPerfilUsuario['city']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['phone'])): ?>
                        <tr>
                            <td class="info-label">Teléfono Personal:</td>
                            <td class="info-value"><?php echo htmlspecialchars($datosPerfilUsuario['phone']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['mail'])): ?>
                        <tr>
                            <td class="info-label">Correo Empresarial:</td>
                            <td class="info-value">
                                <a href="mailto:<?php echo htmlspecialchars($datosPerfilUsuario['mail']); ?>">
                                    <?php echo htmlspecialchars($datosPerfilUsuario['mail']); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['personal_mail'])): ?>
                        <tr>
                            <td class="info-label">Correo Personal:</td>
                            <td class="info-value">
                                <a href="mailto:<?php echo htmlspecialchars($datosPerfilUsuario['personal_mail']); ?>">
                                    <?php echo htmlspecialchars($datosPerfilUsuario['personal_mail']); ?>
                                </a>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Columna 2: Información Laboral y Gestión -->
    <div class="col-lg-6">
        <!-- Tarjeta de Información Laboral -->
        <div class="card mb-4">
            <div class="card-header text-black" style="background-color: #e9ecef;">
                <h5 class="card-title mb-0">
                    <i class="bi bi-briefcase me-2"></i>Información Laboral
                </h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-borderless">
                        <?php if (isset($datosPerfilUsuario['company'])): ?>
                        <tr>
                            <td class="info-label">Compañía:</td>
                            <td class="info-value">
                                <?php 
                                $companias = [
                                    1 => 'AZC',
                                    2 => 'BilingueLaw', 
                                    3 => 'LawyerDesk',
                                    4 => 'AZC Legal',
                                    5 => 'Matiz LegalTech'
                                ];
                                echo $companias[$datosPerfilUsuario['company']] ?? 'No especificada';
                                ?>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['sede_nombre'])): ?>
                        <tr>
                            <td class="info-label">Sede:</td>
                            <td class="info-value"><?php echo htmlspecialchars($datosPerfilUsuario['sede_nombre']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['firm_name'])): ?>
                        <tr>
                            <td class="info-label">Firma:</td>
                            <td class="info-value"><?php echo htmlspecialchars($datosPerfilUsuario['firm_name']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['nombre_cargo'])): ?>
                        <tr>
                            <td class="info-label">Cargo:</td>
                            <td class="info-value"><?php echo htmlspecialchars($datosPerfilUsuario['nombre_cargo']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['extension'])): ?>
                        <tr>
                            <td class="info-label">Extensión:</td>
                            <td class="info-value"><?php echo htmlspecialchars($datosPerfilUsuario['extension']); ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['role'])): ?>
                        <tr>
                            <td class="info-label">Rol:</td>
                            <td class="info-value">
                                <span class="badge bg-<?php 
                                    $roleColors = [
                                        'administrador' => 'danger',
                                        'it' => 'primary',
                                        'nomina' => 'warning',
                                        'talento_humano' => 'info',
                                        'empleado' => 'secondary'
                                    ];
                                    echo $roleColors[$datosPerfilUsuario['role']] ?? 'secondary';
                                ?>">
                                    <?php echo ucfirst($datosPerfilUsuario['role']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($datosPerfilUsuario['activo_fijo'])): ?>
                        <tr>
                            <td class="info-label">Activo Fijo:</td>
                            <td class="info-value">
                                <span class="badge bg-success"><?php echo htmlspecialchars($datosPerfilUsuario['activo_fijo']); ?></span>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td class="info-label">Fecha de Ingreso:</td>
                            <td class="info-value">
                                <?php echo date('d/m/Y', strtotime($datosPerfilUsuario['created_at'])); ?>
                                <?php
                                // Cálculo de antigüedad
                                $fecha_ingreso = new DateTime($datosPerfilUsuario['created_at']);
                                $fecha_actual = new DateTime();
                                $antiguedad = $fecha_ingreso->diff($fecha_actual);
                                echo ' <small class="text-muted">(' . $antiguedad->y . ' años, ' . $antiguedad->m . ' meses)</small>';
                                ?>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
        </div>

        <!-- Tarjeta de Gestión del Perfil -->
        <div class="card">
            <div class="card-header text-black" style="background-color: #e9ecef;">
                <h5 class="card-title mb-0">
                    <i class="bi bi-gear me-2"></i>Gestión del Perfil
                </h5>
            </div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <button type="button" class="btn btn-outline-primary" onclick="window.location.href='?cambiar_password=1'">
                        <i class="bi bi-key me-2"></i>Cambiar Contraseña
                    </button>
                    <button type="button" class="btn btn-outline-success" onclick="window.location.href='?documentos=1'">
                        <i class="bi bi-folder me-2"></i>Documentos
                    </button>
                    <button type="button" class="btn btn-outline-info" onclick="window.location.href='?tickets=1'">
                        <i class="bi bi-ticket-perforated me-2"></i>Mis Tickets
                    </button>
                    <!-- Espacio para futuras opciones -->
                    <div class="mt-3 p-2 border rounded">
                        <small class="text-muted">
                            <i class="bi bi-info-circle me-1"></i>
                            Más opciones de gestión estarán disponibles próximamente
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
                </div>
            </main>
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
                    <div class="info-value" id="modalNotasEmpleado"></div>
                </div>
                
                <div class="info-row" id="modalNotasAdminRow" style="display: none;">
                    <span class="info-label">Notas del administrador:</span>
                    <div class="info-value" id="modalNotasAdmin"></div>
                </div>
                
                <div class="info-row" id="modalImagenRow" style="display: none;">
                    <span class="info-label">Imagen adjunta:</span>
                    <div class="info-value">
                        <img id="modalImagen" src="" class="modal-ticket-img" alt="Imagen del ticket" onclick="ampliarImagen(this.src)" style="max-width: 100%; max-height: 300px; cursor: pointer;">
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
        document.getElementById('modalItemDevuelto').textContent = ticket.item_devuelto_id ? 'Item ID: ' + ticket.item_devuelto_id : 'No especificado';
        document.getElementById('modalEstadoItem').innerHTML = ticket.mal_estado ? 
            '<span class="badge bg-danger">Mal estado</span>' : 
            '<span class="badge bg-success">Buen estado</span>';
    } else {
        document.getElementById('modalIntercambioInfo').style.display = 'none';
    }
    
    // Notas del empleado
    document.getElementById('modalNotasEmpleado').textContent = ticket.notas || 'Sin notas del empleado';
    
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