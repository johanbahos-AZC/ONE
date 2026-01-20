<?php
require_once '../includes/database.php';
require_once '../includes/functions.php';

session_start();

 $error = '';
 $success = '';
 $database = new Database();
 $conn = $database->getConnection();
 $functions = new Functions();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['registrar_candidato'])) {
    try {
        // Recoger datos personales
        $type_CC = $_POST['type_CC'];
        $CC = $_POST['CC'];
        $first_Name = trim($_POST['first_Name']);
        $second_Name = trim($_POST['second_Name']);
        $first_LastName = trim($_POST['first_LastName']);
        $second_LastName = trim($_POST['second_LastName']);
        $birthdate = $_POST['birthdate'];
        $dval = $_POST['dval'] ?? null;
        $personal_mail = trim($_POST['personal_mail']);
        $phone = trim($_POST['phone']);
        $country = $_POST['country'];
        $city = trim($_POST['city']);
        $address = trim($_POST['address']);
        
        // Validaciones básicas
        if (empty($first_Name) || empty($first_LastName) || empty($CC) || empty($birthdate) || empty($personal_mail) || empty($phone)) {
            throw new Exception("Todos los campos obligatorios deben ser completados");
        }
        if (empty($address)) {
            throw new Exception("El campo dirección es obligatorio");
        }
        
        // Verificar si el CC ya existe
        $query_verificar = "SELECT id FROM employee WHERE CC = :CC";
        $stmt_verificar = $conn->prepare($query_verificar);
        $stmt_verificar->bindParam(':CC', $CC);
        $stmt_verificar->execute();
        
        if ($stmt_verificar->rowCount() > 0) {
            throw new Exception("El número de documento ya existe en el sistema");
        }
        
        // Verificar si el email personal ya existe
        $query_verificar_mail = "SELECT id FROM employee WHERE personal_mail = :personal_mail";
        $stmt_verificar_mail = $conn->prepare($query_verificar_mail);
        $stmt_verificar_mail->bindParam(':personal_mail', $personal_mail);
        $stmt_verificar_mail->execute();
        
        if ($stmt_verificar_mail->rowCount() > 0) {
            throw new Exception("El correo electrónico personal ya existe en el sistema");
        }
        
        // Procesar foto de fondo blanco
        $photo_name = null;
        if (isset($_FILES['foto_fondo_blanco']) && $_FILES['foto_fondo_blanco']['error'] === UPLOAD_ERR_OK) {
            $photo_name = procesarFotoCandidato($_FILES['foto_fondo_blanco'], $CC);
        } else {
            throw new Exception("La foto de fondo blanco es obligatoria");
        }
        
        // Generar password y pin automáticos
        $password = 'z' . $CC . 'Z@!$';
        $pin = substr($CC, -4);
        
        // Insertar candidato en la base de datos (sin campo mail)
        $query = "INSERT INTO employee (
        type_CC, CC, first_Name, second_Name, first_LastName, second_LastName, 
        birthdate, dval, personal_mail, phone, country, city, address,
        role, password, pin, photo, created_at
    ) VALUES (
        :type_CC, :CC, :first_Name, :second_Name, :first_LastName, :second_LastName,
        :birthdate, :dval, :personal_mail, :phone, :country, :city, :address,
        'candidato', :password, :pin, :photo, NOW()
    )";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':type_CC', $type_CC);
        $stmt->bindParam(':CC', $CC);
        $stmt->bindParam(':first_Name', $first_Name);
        $stmt->bindParam(':second_Name', $second_Name);
        $stmt->bindParam(':first_LastName', $first_LastName);
        $stmt->bindParam(':second_LastName', $second_LastName);
        $stmt->bindParam(':birthdate', $birthdate);
        $stmt->bindParam(':dval', $dval, PDO::PARAM_INT);
        $stmt->bindParam(':personal_mail', $personal_mail);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':country', $country);
        $stmt->bindParam(':city', $city);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':password', $password);
        $stmt->bindParam(':pin', $pin);
        $stmt->bindParam(':photo', $photo_name);
        
        if ($stmt->execute()) {
            $candidato_id = $conn->lastInsertId();
            $nombre_completo = $first_Name . ' ' . $first_LastName;
            
            // Obtener la ruta completa de la foto
            $photo_full_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/photos/' . $photo_name;
            
            // Procesar archivos BLOB incluyendo la foto
            $resultado_archivos = procesarArchivosCandidato($_FILES, $candidato_id, $CC, $photo_full_path, $nombre_completo);
            $archivos_procesados = $resultado_archivos['procesados'];
            $archivos_temp = $resultado_archivos['temporales'];
            
            // Registrar en historial
            $functions->registrarHistorial(
                null, 
                $candidato_id,
                null, 
                'crear_candidato', 
                1, 
                "Candidato registrado: " . $first_Name . " " . $first_LastName . " (CC: " . $CC . ")"
            );
            
            // ========== ENVÍO DE CORREOS ==========
            try {
                // Preparar datos COMPLETOS para los correos
                $datos_candidato = [
                    'first_Name' => $first_Name,
                    'second_Name' => $second_Name,
                    'first_LastName' => $first_LastName,
                    'second_LastName' => $second_LastName,
                    'type_CC' => $type_CC,
                    'CC' => $CC,
                    'birthdate' => $birthdate,
                    'dval' => $dval,
                    'personal_mail' => $personal_mail,
                    'phone' => $phone,
                    'country' => $country,
                    'city' => $city,
                    'address' => $address,
                    'certificado_ingles' => isset($_FILES['certificado_ingles']) && $_FILES['certificado_ingles']['error'] === UPLOAD_ERR_OK,
                    'tarjeta_profesional' => isset($_FILES['tarjeta_profesional']) && $_FILES['tarjeta_profesional']['error'] === UPLOAD_ERR_OK,
                    'certificado_pensiones' => isset($_FILES['certificado_pensiones']) && $_FILES['certificado_pensiones']['error'] === UPLOAD_ERR_OK,
                    'certificado_cesantias' => isset($_FILES['certificado_cesantias']) && $_FILES['certificado_cesantias']['error'] === UPLOAD_ERR_OK,
                    'archivos_temp' => $archivos_temp
                ];
                
                // Enviar correos
                $resultado_correos = $functions->enviarCorreosFormularioCandidato($datos_candidato, $candidato_id);
                
                if (!$resultado_correos['success']) {
                    error_log("Error enviando correos: " . ($resultado_correos['error'] ?? 'Error desconocido'));
                }
                
            } catch (Exception $e) {
                error_log("Excepción en envío de correos: " . $e->getMessage());
            } finally {
                // LIMPIAR ARCHIVOS TEMPORALES (excepto la foto que se guarda permanentemente)
                foreach ($archivos_temp as $archivo_temp) {
                    if ($archivo_temp['tipo'] !== 'foto_fondo_blanco' && file_exists($archivo_temp['ruta'])) {
                        unlink($archivo_temp['ruta']);
                    }
                }
            }
            // ========== FIN ENVÍO DE CORREOS ==========
            
            $_SESSION['ultimo_candidato_id'] = $candidato_id;
            header('Location: success.php');
            exit();
        } else {
            throw new Exception("Error al registrar el candidato");
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Función para procesar foto de candidato
function procesarFotoCandidato($file, $cc) {
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/photos/';
    
    // Crear directorio si no existe
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Validar tipo de archivo
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception('La foto debe ser en formato JPG o PNG');
    }
    
    // Validar tamaño (máximo 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception('La foto no debe superar los 2MB');
    }
    
    // Generar nombre único
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $file_name = 'candidato_' . $cc . '_' . time() . '.' . $extension;
    $file_path = $upload_dir . $file_name;
    
    // Mover archivo
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('Error al subir la foto');
    }
    
    // Redimensionar imagen
    redimensionarImagen($file_path, 300, 300);
    
    return $file_name;
}

// Función para procesar archivos BLOB del candidato
function procesarArchivosCandidato($files, $candidato_id, $cc, $photo_path, $nombre_completo) {
    global $conn;
    
    $archivos_procesados = [];
    $archivos_temp = [];
    
    // Definir qué archivos son obligatorios y cuáles opcionales
    $archivos_obligatorios = [
        'documento_identidad' => 'Documento de Identidad',
        'diploma_pregrado' => 'Diploma de Pregrado/Bachiller',
        'hoja_vida' => 'Hoja de Vida'
    ];
    
    $archivos_opcionales = [
        'certificado_bancario' => 'Certificado Bancario',
        'certificado_eps' => 'Certificado EPS',
        'certificado_pensiones' => 'Certificado Pensiones',
        'certificado_cesantias' => 'Certificado Cesantías',
        'certificado_ingles' => 'Certificado Inglés',
        'tarjeta_profesional' => 'Tarjeta Profesional'
    ];
    
    $tipos_archivos = array_merge($archivos_obligatorios, $archivos_opcionales);
    
    $temp_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/temp/';
    if (!is_dir($temp_dir)) {
        mkdir($temp_dir, 0755, true);
    }
    
    // AGREGAR LA FOTO DE FONDO BLANCO COMO PRIMER ARCHIVO (obligatorio)
    if ($photo_path && file_exists($photo_path)) {
        $photo_info = pathinfo($photo_path);
        $archivos_temp[] = [
            'tipo' => 'foto_fondo_blanco',
            'descripcion' => 'Foto de Fondo Blanco',
            'ruta' => $photo_path,
            'nombre_original' => 'foto_fondo_blanco_' . $cc . '.' . $photo_info['extension'],
            'mime_type' => mime_content_type($photo_path)
        ];
    }
    
    // Validar archivos obligatorios
    foreach ($archivos_obligatorios as $tipo => $descripcion) {
        if (!isset($files[$tipo]) || $files[$tipo]['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("El archivo {$descripcion} es obligatorio");
        }
    }
    
    // --- CORRECCIÓN AQUÍ ---
    // 1. Mapeo: NOMBRE DEL CAMPO DEL FORMULARIO (clave) -> TIPO DE ARCHIVO EN BD (valor)
    $mapeo_tipos_bd = [
        'documento_identidad' => 'documento_identidad',
        'diploma_pregrado' => 'diploma_pregrado',
        'hoja_vida' => 'hoja_de_vida',
        'certificado_bancario' => 'certificado_bancario',
        'certificado_eps' => 'certificado_de_afiliacion_eps',
        'certificado_pensiones' => 'certificado_de_afiliacion_pension',
        'certificado_cesantias' => 'certificado_de_cesantias',
        'certificado_ingles' => 'certificado_de_ingles',
        'tarjeta_profesional' => 'tarjeta_profesional'
    ];
    
    // 2. Plantillas de descripción. LA CLAVE DEBE SER EL TIPO DE ARCHIVO DE LA BD.
    $plantillas_descripcion = [
        'documento_identidad' => '2.D.I – NOMBRE',
        'diploma_pregrado' => '8.DIPLOMA PREGRADO-NOMBRE',
        'hoja_de_vida' => '1. HV - NOMBRE',
        'certificado_bancario' => 'C.BANCARIO',
        'certificado_de_afiliacion_eps' => '3.C.EPS – NOMBRE',
        'certificado_de_afiliacion_pension' => '4.C.AFP – NOMBRE',
        'certificado_de_cesantias' => 'C.CESANTIAS',
        'certificado_de_ingles' => '17.C.INGLES',
        'tarjeta_profesional' => '7.T.P – NOMBRE'
    ];
    // --- FIN DE LA CORRECCIÓN ---

    // Procesar todos los archivos (obligatorios y opcionales)
    foreach ($tipos_archivos as $tipo_formulario => $descripcion_formulario) {
        // Saltar si el archivo opcional no fue enviado
        if (isset($archivos_opcionales[$tipo_formulario]) && 
            (!isset($files[$tipo_formulario]) || $files[$tipo_formulario]['error'] !== UPLOAD_ERR_OK)) {
            continue;
        }
        
        $file = $files[$tipo_formulario];
        
        // Obtener el tipo MIME del archivo
        $file_type = mime_content_type($file['tmp_name']);
        
        // Validar tipo de archivo
        $allowed_mimes = ['application/pdf']; // Solo PDF para documentos
        if ($tipo_formulario === 'foto_fondo_blanco') {
            $allowed_mimes = ['image/jpeg', 'image/jpg', 'image/png']; // JPG/PNG solo para foto
        }
        
        if (!in_array($file_type, $allowed_mimes)) {
            if ($tipo_formulario === 'foto_fondo_blanco') {
                throw new Exception("La foto debe ser en formato JPG o PNG");
            } else {
                throw new Exception("El archivo {$descripcion_formulario} debe ser PDF");
            }
        }
        
        // Validar tamaño (máximo 5MB)
        if ($file['size'] > 5 * 1024 * 1024) {
            throw new Exception("El archivo {$descripcion_formulario} no debe superar los 5MB");
        }
        
        // Crear copia temporal para el correo
        $temp_file_path = $temp_dir . $tipo_formulario . '_' . $cc . '_' . time() . '_' . $file['name'];
        if (!move_uploaded_file($file['tmp_name'], $temp_file_path)) {
            throw new Exception("Error al crear copia temporal del archivo {$descripcion_formulario}");
        }
        
        // Guardar información para el correo
        $archivos_temp[] = [
            'tipo' => $tipo_formulario,
            'descripcion' => $descripcion_formulario,
            'ruta' => $temp_file_path,
            'nombre_original' => $file['name'],
            'mime_type' => $file_type
        ];
        
        // Leer contenido del archivo para BLOB
        $file_content = file_get_contents($temp_file_path);
        $file_hash = hash('sha256', $file_content);
        $file_size = $file['size'];
        $file_name = $file['name'];
        $mime_type = $file_type;
        
        // --- LÓGICA CORREGIDA ---
        // 1. Obtener el tipo de archivo correcto para la BD usando el mapeo
        $tipo_archivo_bd = isset($mapeo_tipos_bd[$tipo_formulario]) ? $mapeo_tipos_bd[$tipo_formulario] : $tipo_formulario;
        
        // 2. Obtener la plantilla de descripción usando el TIPO DE ARCHIVO DE LA BD
        $plantilla = isset($plantillas_descripcion[$tipo_archivo_bd]) ? 
                     $plantillas_descripcion[$tipo_archivo_bd] : 
                     $descripcion_formulario; // Descripción por defecto si no hay plantilla

        // 3. Reemplazar el marcador NOMBRE con el nombre del candidato
        $descripcion_final = str_replace('NOMBRE', $nombre_completo, $plantilla);
        // --- FIN DE LA LÓGICA CORREGIDA ---
        
        // Insertar en la tabla files
        $query = "INSERT INTO files (
            id_employee, file_type, file_name, file_content, 
            file_hash, file_size, mime_type, description, 
            uploaded_by, created_at
        ) VALUES (
            :id_employee, :file_type, :file_name, :file_content,
            :file_hash, :file_size, :mime_type, :description,
            :uploaded_by, NOW()
        )";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id_employee', $candidato_id);
        $stmt->bindParam(':file_type', $tipo_archivo_bd); // Usar el tipo mapeado
        $stmt->bindParam(':file_name', $file_name);
        $stmt->bindParam(':file_content', $file_content, PDO::PARAM_LOB);
        $stmt->bindParam(':file_hash', $file_hash);
        $stmt->bindParam(':file_size', $file_size);
        $stmt->bindParam(':mime_type', $mime_type);
        $stmt->bindParam(':description', $descripcion_final); // Usar la descripción final
        $stmt->bindParam(':uploaded_by', $candidato_id);
        
        if (!$stmt->execute()) {
            // Limpiar archivo temporal si hay error
            unlink($temp_file_path);
            throw new Exception("Error al guardar el archivo {$descripcion_formulario}");
        }
        
        $archivos_procesados[] = $tipo_formulario;
    }
    
    return [
        'procesados' => $archivos_procesados,
        'temporales' => $archivos_temp
    ];
}

// Función para redimensionar imagen (la misma que en usuarios.php)
function redimensionarImagen($file_path, $max_width, $max_height) {
    if (!file_exists($file_path)) return;
    
    list($width, $height, $type) = getimagesize($file_path);
    
    if ($width <= $max_width && $height <= $max_height) {
        return;
    }
    
    $ratio = min($max_width/$width, $max_height/$height);
    $new_width = round($width * $ratio);
    $new_height = round($height * $ratio);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($file_path);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($file_path);
            break;
        default:
            return;
    }
    
    $new_image = imagecreatetruecolor($new_width, $new_height);
    
    if ($type == IMAGETYPE_PNG) {
        imagecolortransparent($new_image, imagecolorallocatealpha($new_image, 0, 0, 0, 127));
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
    }
    
    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($new_image, $file_path, 90);
            break;
        case IMAGETYPE_PNG:
            imagepng($new_image, $file_path, 9);
            break;
    }
    
    imagedestroy($image);
    imagedestroy($new_image);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registro de Candidatos - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
        :root {
            --primary-red: #be1622;
            --dark-gray: #353132;
            --light-gray: #9d9d9c;
            --dark-blue: #003a5d;
        }
        
        body {
            background: var(--white);
            color: var(--dark-gray);
        }
        
        .card {
            border: 3px solid var(--dark-blue);
            border-radius: 15px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
        }
        
        .card-header {
            background: var(--dark-blue);
            color: white;
            border-bottom: 3px solid var(--primary-red);
            border-radius: 12px 12px 0 0 !important;
            padding: 25px;
        }
        
        .form-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 10px;
            margin-bottom: 1.5rem;
            border-left: 4px solid var(--dark-blue);
            border: 2px solid var(--light-gray);
        }
        
        .form-label {
            color: var(--dark-gray);
            font-weight: 600;
        }
        
        .form-control, .form-select {
            border: 2px solid var(--light-gray);
            border-radius: 8px;
            padding: 10px 15px;
            color: var(--dark-gray);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--dark-blue);
            box-shadow: 0 0 0 0.2rem rgba(0, 58, 93, 0.25);
        }
        
        .btn-primary {
            background: var(--dark-blue);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            font-size: 1.1rem;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: var(--primary-red);
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(190, 22, 34, 0.3);
        }
        
        .required-field::after {
            content: " *";
            color: var(--primary-red);
        }
        
        .file-requirements {
            font-size: 0.85rem;
            color: var(--light-gray);
        }
        
        .is-invalid {
            border-color: var(--primary-red) !important;
            box-shadow: 0 0 0 0.2rem rgba(190, 22, 34, 0.25) !important;
        }
        
        .is-invalid + .file-requirements {
            color: var(--primary-red);
        }
        
        .alert-danger {
            background: #f8d7da;
            border: 2px solid var(--primary-red);
            color: var(--dark-gray);
            border-radius: 8px;
        }
        
        .alert-success {
            background: #d1edff;
            border: 2px solid var(--dark-blue);
            color: var(--dark-gray);
            border-radius: 8px;
        }
        
        h4 {
            color: var(--dark-blue);
            border-bottom: 2px solid var(--light-gray);
            padding-bottom: 10px;
            margin-bottom: 20px;
        }
        
        .bi {
            color: var(--dark-blue);
        }
        
        .card-header .bi {
            color: white;
        }
    </style>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card shadow">
                    <div class="card-header">
                        <h2 class="text-center mb-0">
                            <i class="bi bi-person-plus me-2"></i>Formulario de Registro de Candidatos
                        </h2>
                    </div>
                    <div class="card-body">
                        <?php if ($error): ?>
                        <div class="alert alert-danger"><?php echo $error; ?></div>
                        <?php endif; ?>
                        
                        <?php if ($success): ?>
                        <div class="alert alert-success"><?php echo $success; ?></div>
                        <?php endif; ?>

                        <form method="POST" action="" enctype="multipart/form-data" id="formCandidato">
                            <!-- Información Personal -->
                            <div class="form-section">
                                <h4 class="mb-3">
                                    <i class="bi bi-person-vcard"></i> Información Personal
                                </h4>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="first_Name" class="form-label required-field">Primer Nombre</label>
                                            <input type="text" class="form-control" id="first_Name" name="first_Name" 
                                                   value="<?php echo isset($_POST['first_Name']) ? htmlspecialchars($_POST['first_Name']) : ''; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="second_Name" class="form-label">Segundo Nombre</label>
                                            <input type="text" class="form-control" id="second_Name" name="second_Name"
                                                   value="<?php echo isset($_POST['second_Name']) ? htmlspecialchars($_POST['second_Name']) : ''; ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="first_LastName" class="form-label required-field">Primer Apellido</label>
                                            <input type="text" class="form-control" id="first_LastName" name="first_LastName"
                                                   value="<?php echo isset($_POST['first_LastName']) ? htmlspecialchars($_POST['first_LastName']) : ''; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="second_LastName" class="form-label">Segundo Apellido</label>
                                            <input type="text" class="form-control" id="second_LastName" name="second_LastName"
                                                   value="<?php echo isset($_POST['second_LastName']) ? htmlspecialchars($_POST['second_LastName']) : ''; ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="type_CC" class="form-label required-field">Tipo Documento</label>
                                            <select class="form-select" id="type_CC" name="type_CC" required>
                                                <option value="1" <?php echo (isset($_POST['type_CC']) && $_POST['type_CC'] == '1') ? 'selected' : ''; ?>>Cédula</option>
                                                <option value="2" <?php echo (isset($_POST['type_CC']) && $_POST['type_CC'] == '2') ? 'selected' : ''; ?>>Pasaporte</option>
                                                <option value="3" <?php echo (isset($_POST['type_CC']) && $_POST['type_CC'] == '3') ? 'selected' : ''; ?>>Cédula Extranjería</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="CC" class="form-label required-field">Número Documento</label>
                                            <input type="text" class="form-control" id="CC" name="CC"
                                                   value="<?php echo isset($_POST['CC']) ? htmlspecialchars($_POST['CC']) : ''; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="birthdate" class="form-label required-field">Fecha Nacimiento</label>
                                            <input type="date" class="form-control" id="birthdate" name="birthdate"
                                                   value="<?php echo isset($_POST['birthdate']) ? htmlspecialchars($_POST['birthdate']) : ''; ?>" required>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="dval" class="form-label">Estado Civil</label>
                                            <select class="form-select" name="dval">
                                                <option value="">Seleccione estado civil</option>
                                                <option value="1" <?php echo (isset($_POST['dval']) && $_POST['dval'] == '1') ? 'selected' : ''; ?>>Soltero/a</option>
                                                <option value="2" <?php echo (isset($_POST['dval']) && $_POST['dval'] == '2') ? 'selected' : ''; ?>>Casado/a</option>
                                                <option value="3" <?php echo (isset($_POST['dval']) && $_POST['dval'] == '3') ? 'selected' : ''; ?>>Divorciado/a</option>
                                                <option value="4" <?php echo (isset($_POST['dval']) && $_POST['dval'] == '4') ? 'selected' : ''; ?>>Viudo/a</option>
                                                <option value="5" <?php echo (isset($_POST['dval']) && $_POST['dval'] == '5') ? 'selected' : ''; ?>>Unión Libre</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="country" class="form-label">País</label>
                                            <input type="text" class="form-control" id="country" name="country" 
                                                   value="<?php echo isset($_POST['country']) ? htmlspecialchars($_POST['country']) : 'Colombia'; ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="city" class="form-label">Ciudad</label>
                                            <input type="text" class="form-control" id="city" name="city"
                                                   value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city']) : ''; ?>">
                                        </div>
                                    </div>
                                </div>

                                <div class="row">
                                    <div class="col-12">
                                        <div class="mb-3">
                                            <label for="address" class="form-label">Dirección Completa</label>
                                            <textarea class="form-control" id="address" name="address" rows="2"><?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?></textarea>
                                            <div class="form-text">Dirección de residencia completa, con barrio</div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="personal_mail" class="form-label required-field">Correo Personal</label>
                                            <input type="email" class="form-control" id="personal_mail" name="personal_mail"
                                                   value="<?php echo isset($_POST['personal_mail']) ? htmlspecialchars($_POST['personal_mail']) : ''; ?>" required>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="phone" class="form-label required-field">Teléfono Personal</label>
                                            <input type="text" class="form-control" id="phone" name="phone"
                                                   value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>" required>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Documentos -->
                            <div class="form-section">
                                <h4 class="mb-3">
                                    <i class="bi bi-files"></i> Documentos Requeridos
                                </h4>
                                
                                <!-- Foto Fondo Blanco -->
                                <div class="mb-3">
                                    <label for="foto_fondo_blanco" class="form-label required-field">Foto Fondo Blanco (PNG o JPG)</label>
                                    <input type="file" class="form-control" id="foto_fondo_blanco" name="foto_fondo_blanco" accept=".jpg,.jpeg,.png" required>
                                    <div class="file-requirements">Formato: JPG o PNG | Tamaño máximo: 2MB</div>
                                </div>
                                
                                <!-- Documentos obligatorios -->
                                <div class="mb-3">
                                    <label for="documento_identidad" class="form-label required-field">Documento de Identidad Escaneado (PDF)</label>
                                    <input type="file" class="form-control" id="documento_identidad" name="documento_identidad" accept=".pdf" required>
                                    <div class="file-requirements">Formato: PDF | Tamaño máximo: 5MB</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="diploma_pregrado" class="form-label required-field">Diploma de Pregrado/Bachiller (PDF)</label>
                                    <input type="file" class="form-control" id="diploma_pregrado" name="diploma_pregrado" accept=".pdf" required>
                                    <div class="file-requirements">Formato: PDF | Tamaño máximo: 5MB</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="hoja_vida" class="form-label required-field">Hoja de Vida (PDF)</label>
                                    <input type="file" class="form-control" id="hoja_vida" name="hoja_vida" accept=".pdf" required>
                                    <div class="file-requirements">Formato: PDF | Tamaño máximo: 5MB</div>
                                </div>
                                
                                <!-- Documentos opcionales -->
                                <div class="mb-3">
                                    <label for="certificado_bancario" class="form-label">Certificado Bancario (Cuenta de Ahorros)</label>
                                    <input type="file" class="form-control" id="certificado_bancario" name="certificado_bancario" accept=".pdf">
                                    <div class="file-requirements">Formato: PDF | Tamaño máximo: 5MB</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="certificado_eps" class="form-label">Certificado Afiliación EPS</label>
                                    <input type="file" class="form-control" id="certificado_eps" name="certificado_eps" accept=".pdf">
                                    <div class="file-requirements">Formato: PDF | Tamaño máximo: 5MB</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="certificado_pensiones" class="form-label">Certificado de Fondo de Pensiones</label>
                                    <input type="file" class="form-control" id="certificado_pensiones" name="certificado_pensiones" accept=".pdf">
                                    <div class="file-requirements">Formato: PDF | Tamaño máximo: 5MB</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="certificado_cesantias" class="form-label">Certificado de Fondo de Cesantías</label>
                                    <input type="file" class="form-control" id="certificado_cesantias" name="certificado_cesantias" accept=".pdf">
                                    <div class="file-requirements">Formato: PDF | Tamaño máximo: 5MB</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="certificado_ingles" class="form-label">Certificado Nivel de Inglés (Si aplica)</label>
                                    <input type="file" class="form-control" id="certificado_ingles" name="certificado_ingles" accept=".pdf">
                                    <div class="file-requirements">Formato: PDF | Tamaño máximo: 5MB</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="tarjeta_profesional" class="form-label">Tarjeta Profesional (Si aplica)</label>
                                    <input type="file" class="form-control" id="tarjeta_profesional" name="tarjeta_profesional" accept=".pdf">
                                    <div class="file-requirements">Formato: PDF | Tamaño máximo: 5MB</div>
                                </div>
                            </div>
                            <div class="text-center">
                                <button type="submit" name="registrar_candidato" class="btn btn-primary btn-lg">
                                    <i class="bi bi-send me-2"></i>Enviar Registro
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.getElementById('formCandidato');
        
        form.addEventListener('submit', function(e) {
            let errores = [];
            
            // Validar campos de texto obligatorios
            const camposObligatorios = [
                'first_Name', 'first_LastName', 'CC', 'birthdate', 'personal_mail', 'phone'
            ];
            
            camposObligatorios.forEach(campo => {
                const input = document.getElementById(campo);
                if (!input.value.trim()) {
                    input.classList.add('is-invalid');
                    errores.push(`El campo ${input.labels[0].textContent} es obligatorio`);
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            
            // Validar archivos obligatorios
            const archivosObligatorios = [
                'foto_fondo_blanco', 'documento_identidad', 'diploma_pregrado', 'hoja_vida'
            ];
            
            archivosObligatorios.forEach(archivo => {
                const input = document.getElementById(archivo);
                if (!input.files.length) {
                    input.classList.add('is-invalid');
                    errores.push(`El archivo ${input.labels[0].textContent} es obligatorio`);
                } else {
                    input.classList.remove('is-invalid');
                }
            });
            
            // Mostrar errores si los hay
            if (errores.length > 0) {
                e.preventDefault();
                
                let mensajeError = 'Por favor complete los siguientes campos:\n\n';
                errores.forEach(error => {
                    mensajeError += `• ${error}\n`;
                });
                
                alert(mensajeError);
                
                // Hacer scroll al primer error
                const primerError = document.querySelector('.is-invalid');
                if (primerError) {
                    primerError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    primerError.focus();
                }
            }
        });
        
        // Remover clase de error cuando el usuario empiece a escribir/seleccionar
        const inputs = form.querySelectorAll('input, select, textarea');
        inputs.forEach(input => {
            input.addEventListener('input', function() {
                this.classList.remove('is-invalid');
            });
            
            input.addEventListener('change', function() {
                this.classList.remove('is-invalid');
            });
        });
    });
    </script>
</body>
</html>