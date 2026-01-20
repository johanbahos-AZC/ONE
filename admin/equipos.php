<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/validar_permiso.php';

function calcularAntiguedad($fechaCreacion) {
    if (empty($fechaCreacion)) {
        return "Fecha no disponible";
    }
    
    $fechaCreacion = new DateTime($fechaCreacion);
    $fechaActual = new DateTime();
    $diferencia = $fechaActual->diff($fechaCreacion);
    
    $anos = $diferencia->y;
    $meses = $diferencia->m;
    $dias = $diferencia->d;
    
    if ($anos > 0) {
        return $anos . " año" . ($anos > 1 ? 's' : '') . 
               ($meses > 0 ? " y " . $meses . " mes" . ($meses > 1 ? 'es' : '') : '');
    } elseif ($meses > 0) {
        return $meses . " mes" . ($meses > 1 ? 'es' : '') . 
               ($dias > 0 ? " y " . $dias . " día" . ($dias > 1 ? 's' : '') : '');
    } else {
        return $dias . " día" . ($dias > 1 ? 's' : '');
    }
}

// Funciones para manejo de fotos
function subirFoto($archivo) {
    $directorio = '../uploads/equipos/';
    
    // Verificar si el directorio existe
    if (!is_dir($directorio)) {
        if (!mkdir($directorio, 0755, true)) {
            throw new Exception('No se pudo crear el directorio de uploads');
        }
    }
    
    $extension = pathinfo($archivo['name'], PATHINFO_EXTENSION);
    $extension = strtolower($extension);
    
    // Validar tipo de archivo
    $extensionesPermitidas = ['jpg', 'jpeg', 'png', 'gif'];
    if (!in_array($extension, $extensionesPermitidas)) {
        throw new Exception('Formato de archivo no permitido. Use JPG, PNG o GIF');
    }
    
    // Validar tamaño (2MB máximo)
    if ($archivo['size'] > 2097152) {
        throw new Exception('El archivo es demasiado grande (máx. 2MB)');
    }
    
    // Validar errores de subida
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('Error en la subida del archivo: código ' . $archivo['error']);
    }
    
    // Generar nombre único
    $nombreUnico = uniqid() . '_' . time() . '.' . $extension;
    $rutaCompleta = $directorio . $nombreUnico;
    
    // Mover el archivo
    if (move_uploaded_file($archivo['tmp_name'], $rutaCompleta)) {
        return $nombreUnico;
    } else {
        throw new Exception('Error al mover el archivo subido');
    }
}

function eliminarFoto($nombreArchivo) {
    if (empty($nombreArchivo)) return;
    
    $rutaArchivo = '../uploads/equipos/' . $nombreArchivo;
    if (file_exists($rutaArchivo) && is_file($rutaArchivo)) {
        unlink($rutaArchivo);
    }
}

function obtenerUrlFoto($nombreArchivo) {
    if (!empty($nombreArchivo)) {
        return '../uploads/equipos/' . $nombreArchivo;
    }
    return null;
}

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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'equipos', 'ver')) {
    header("Location: portal.php");
    exit();
}

// Verificar permisos específicos para las acciones
 $permiso_crear_equipo = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'equipos', 'crear_equipo');
 $permiso_generar_informe = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'equipos', 'generar_informe');

$functions = new Functions();
$error = '';
$success = '';

// Obtener parámetros de paginación y filtros
$pagina_actual = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$por_pagina = isset($_GET['por_pagina']) ? max(10, intval($_GET['por_pagina'])) : 20;
$filtro_sede = isset($_GET['sede']) ? $_GET['sede'] : '';
$filtro_disponibles = isset($_GET['filtro']) && $_GET['filtro'] == 'disponibles';
$filtro_busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro_mantenimiento = isset($_GET['mantenimiento']) ? $_GET['mantenimiento'] : '';

// Calcular offset para paginación
$offset = ($pagina_actual - 1) * $por_pagina;

// Procesar creación de equipo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_equipo'])) {
    $activo_fijo = trim($_POST['activo_fijo']);
    $marca = trim($_POST['marca']);
    $modelo = trim($_POST['modelo']);
    $serial_number = trim($_POST['serial_number']);
    $procesador = trim($_POST['procesador']);
    $ram = trim($_POST['ram']);
    $disco_duro = trim($_POST['disco_duro']);
    $usuario_asignado = trim($_POST['usuario_asignado']);
    $sede_id = $_POST['sede_id'];
    $en_it = isset($_POST['en_it']) ? 1 : 0;
    $estado_it = $_POST['estado_it'];
    $notas_it = trim($_POST['notas_it']);
    $precio = !empty($_POST['precio']) ? $_POST['precio'] : null;
    
    if (!empty($activo_fijo) && !empty($marca) && !empty($modelo) && !empty($serial_number)) {
    
    $foto_nombre = null;
    if (!empty($_FILES['foto']['name'])) {
        $foto_nombre = subirFoto($_FILES['foto']);
    }
    
        $database = new Database();
        $conn = $database->getConnection();
        
        // Verificar si el activo fijo o serial ya existen
        $query = "SELECT id FROM equipos WHERE activo_fijo = :activo_fijo OR serial_number = :serial_number";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':activo_fijo', $activo_fijo);
        $stmt->bindParam(':serial_number', $serial_number);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $error = "El activo fijo o número serial ya existe";
        } else {
            $query = "INSERT INTO equipos (activo_fijo, marca, modelo, serial_number, procesador, ram, disco_duro, precio, foto, usuario_asignado, sede_id, en_it, estado_it, notas_it, it_admin_id, it_fecha) 
                      VALUES (:activo_fijo, :marca, :modelo, :serial_number, :procesador, :ram, :disco_duro, :precio, :foto, :usuario_asignado, :sede_id, :en_it, :estado_it, :notas_it, :it_admin_id, NOW())";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':activo_fijo', $activo_fijo);
            $stmt->bindParam(':marca', $marca);
            $stmt->bindParam(':modelo', $modelo);
            $stmt->bindParam(':serial_number', $serial_number);
            $stmt->bindParam(':procesador', $procesador);
            $stmt->bindParam(':ram', $ram);
            $stmt->bindParam(':disco_duro', $disco_duro);
            $stmt->bindParam(':usuario_asignado', $usuario_asignado);
            $stmt->bindParam(':sede_id', $sede_id);
            $stmt->bindParam(':en_it', $en_it);
            $stmt->bindParam(':estado_it', $estado_it);
            $stmt->bindParam(':notas_it', $notas_it);
            $stmt->bindParam(':it_admin_id', $_SESSION['user_id']);
            $stmt->bindParam(':precio', $precio);
    	    $stmt->bindParam(':foto', $foto_nombre);
            
            if ($stmt->execute()) {
		    // Obtener el nombre del usuario desde la base de datos
		    $query_usuario = "SELECT CONCAT(first_Name, ' ', first_LastName) as nombre_completo 
		                      FROM employee 
		                      WHERE id = :user_id";
		    $stmt_usuario = $conn->prepare($query_usuario);
		    $stmt_usuario->bindParam(':user_id', $_SESSION['user_id']);
		    $stmt_usuario->execute();
		    $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
		    
		    $nombre_usuario = $usuario ? $usuario['nombre_completo'] : 'Usuario Desconocido';
		    
		    // Registrar en historial
		    $functions->registrarHistorial(
		        null, 
		        $nombre_usuario,
		        null, 
		        'crear_equipo', 
		        1, 
		        "Equipo creado: " . $activo_fijo . " - " . $marca . " " . $modelo . " por " . $nombre_usuario
		    );
		    
		    $success = "Equipo creado correctamente";
		} else {
		    $error = "Error al crear el equipo";
		}
        }
    } else {
        $error = "Los campos obligatorios son: Activo fijo, Marca, Modelo y Serial";
    }
}

// Procesar eliminación de equipo
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Verificar si el equipo tiene usuario asignado
    $query_verificar = "SELECT activo_fijo, usuario_asignado FROM equipos WHERE id = :id";
    $stmt_verificar = $conn->prepare($query_verificar);
    $stmt_verificar->bindParam(':id', $id);
    $stmt_verificar->execute();
    $equipo_info = $stmt_verificar->fetch(PDO::FETCH_ASSOC);
    
    if ($equipo_info) {
        if (!empty($equipo_info['usuario_asignado'])) {
            $error = "No se puede eliminar el equipo porque está asignado al usuario: " . 
                     $equipo_info['usuario_asignado'] . 
                     ". Primero desasigne el equipo del usuario.";
        } else {
            // Obtener info del equipo antes de eliminar
            $query_info = "SELECT activo_fijo FROM equipos WHERE id = :id";
            $stmt_info = $conn->prepare($query_info);
            $stmt_info->bindParam(':id', $id);
            $stmt_info->execute();
            $equipo_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
            
            if ($equipo_info) {
                $query = "DELETE FROM equipos WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':id', $id);
                
                if ($stmt->execute()) {
                // Obtener el nombre del usuario desde la base de datos
		    $query_usuario = "SELECT CONCAT(first_Name, ' ', first_LastName) as nombre_completo 
		                      FROM employee 
		                      WHERE id = :user_id";
		    $stmt_usuario = $conn->prepare($query_usuario);
		    $stmt_usuario->bindParam(':user_id', $_SESSION['user_id']);
		    $stmt_usuario->execute();
		    $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
		    
		    $nombre_usuario = $usuario ? $usuario['nombre_completo'] : 'Usuario Desconocido';
		    
                    $functions->registrarHistorial(
                        null, 
                        $nombre_usuario,
                        null, 
                        'eliminar_equipo', 
                        1, 
                        "Equipo eliminado: " . $equipo_info['activo_fijo']
                    );
                    
                    $success = "Equipo eliminado correctamente";
                    // Redirigir para evitar reenvío del formulario
                    header("Location: equipos.php");
                    exit();
                } else {
                    $error = "Error al eliminar el equipo";
                }
            } else {
                $error = "El equipo no existe";
            }
        }
    } else {
        $error = "El equipo no existe";
    }
}

// Procesar actualización de equipo
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_equipo'])) {
    $equipo_id = $_POST['equipo_id'];
    $procesador = trim($_POST['procesador']);
    $ram = trim($_POST['ram']);
    $disco_duro = trim($_POST['disco_duro']);
    $sede_id = $_POST['sede_id'];
    $en_it = isset($_POST['en_it']) ? 1 : 0;
    $estado_it = $_POST['estado_it'];
    $notas_it = trim($_POST['notas_it']);
    $creado_en = isset($_POST['creado_en']) && !empty($_POST['creado_en']) ? $_POST['creado_en'] : null;
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener información actual del equipo para comparar
    $query_actual = "SELECT usuario_asignado, sede_id, en_it, estado_it, activo_fijo, procesador, ram, disco_duro, foto
                     FROM equipos WHERE id = :id";
    $stmt_actual = $conn->prepare($query_actual);
    $stmt_actual->bindParam(':id', $equipo_id);
    $stmt_actual->execute();
    $equipo_actual = $stmt_actual->fetch(PDO::FETCH_ASSOC);
    
    $precio = !empty($_POST['precio']) ? $_POST['precio'] : null;
    
    // Procesar la foto si se subió una nueva
    $foto_nombre = $equipo_actual['foto']; // Mantener la actual por defecto
    if (!empty($_FILES['foto']['name'])) {
        // Eliminar foto anterior si existe
        if (!empty($equipo_actual['foto'])) {
            eliminarFoto($equipo_actual['foto']);
        }
        $foto_nombre = subirFoto($_FILES['foto']);
    }
    
    if (empty($error)) {
        try {
            $conn->beginTransaction();
            
            // Construir consulta base
            $query = "UPDATE equipos SET 
                     procesador = :procesador, 
                     ram = :ram, 
                     disco_duro = :disco_duro, 
                     precio = :precio,
                     foto = :foto,
                     sede_id = :sede_id, 
                     en_it = :en_it, 
                     estado_it = :estado_it, 
                     notas_it = :notas_it,
                     it_admin_id = :it_admin_id, 
                     it_fecha = NOW()";
                     
            $params = [
                ':procesador' => $procesador,
                ':ram' => $ram,
                ':disco_duro' => $disco_duro,
                ':precio' => $precio,
                ':foto' => $foto_nombre,
                ':sede_id' => $sede_id,
                ':en_it' => $en_it,
                ':estado_it' => $estado_it,
                ':notas_it' => $notas_it,
                ':it_admin_id' => $_SESSION['user_id'],
                ':id' => $equipo_id
            ];
            
            // Agregar creado_en solo si se proporcionó un valor
            if ($creado_en) {
                $query .= ", creado_en = :creado_en";
                $params[':creado_en'] = $creado_en;
            }
            
            $query .= " WHERE id = :id";
                     
            $stmt = $conn->prepare($query);
            
            // Vincular todos los parámetros
            foreach ($params as $key => $value) {
                $stmt->bindValue($key, $value);
            }
            
            if ($stmt->execute()) {
                // Registrar cambios en el historial
                $cambios = [];
                
                // Registro de cambio de procesador
                if ($equipo_actual['procesador'] != $procesador) {
                    $functions->registrarHistorial(
                        null, 
                        null, 
                        null, 
                        'cambio_procesador', 
                        1, 
                        "Procesador cambiado: " . $equipo_actual['procesador'] . " → " . $procesador . " - Equipo: " . $equipo_actual['activo_fijo'],
                        $_SESSION['user_id']
                    );
                }
                
                // Registro de cambio de RAM
                if ($equipo_actual['ram'] != $ram) {
                    $functions->registrarHistorial(
                        null, 
                        null, 
                        null, 
                        'cambio_ram', 
                        1, 
                        "RAM cambiada: " . $equipo_actual['ram'] . " → " . $ram . " - Equipo: " . $equipo_actual['activo_fijo'],
                        $_SESSION['user_id']
                    );
                }
                
                // Registro de cambio de disco duro
                if ($equipo_actual['disco_duro'] != $disco_duro) {
                    $functions->registrarHistorial(
                        null, 
                        null, 
                        null, 
                        'cambio_disco', 
                        1, 
                        "Disco duro cambiado: " . $equipo_actual['disco_duro'] . " → " . $disco_duro . " - Equipo: " . $equipo_actual['activo_fijo'],
                        $_SESSION['user_id']
                    );
                }
                
                // Registro de cambio de sede
                if ($equipo_actual['sede_id'] != $sede_id) {
                    $functions->registrarHistorial(
                        null, 
                        null, 
                        null, 
                        'cambio_sede', 
                        1, 
                        "Cambio de sede ID: " . $equipo_actual['sede_id'] . " → " . $sede_id . " - Equipo: " . $equipo_actual['activo_fijo'],
                        $_SESSION['user_id']
                    );
                }
                
                // Registro de cambio de estado IT
                if ($equipo_actual['en_it'] != $en_it || $equipo_actual['estado_it'] != $estado_it) {
                    $estado_texto = $en_it ? "En IT (" . $estado_it . ")" : "Activo";
                    $functions->registrarHistorial(
                        null, 
                        null, 
                        null, 
                        'cambio_estado_it', 
                        1, 
                        "Estado IT cambiado: " . $estado_texto . " - " . $notas_it . " - Equipo: " . $equipo_actual['activo_fijo'],
                        $_SESSION['user_id']
                    );
                }
                
                $conn->commit();
                $success = "Equipo actualizado correctamente";
                
            } else {
                $conn->rollBack();
                $error = "Error al actualizar el equipo";
            }
            
        } catch (PDOException $e) {
            $conn->rollBack();
            $error = "Error en la base de datos: " . $e->getMessage();
        }
    }
}

// Obtener sedes para los selects
$database = new Database();
$conn = $database->getConnection();
$query_sedes = "SELECT id, nombre FROM sedes ORDER BY nombre";
$stmt_sedes = $conn->prepare($query_sedes);
$stmt_sedes->execute();
$sedes = $stmt_sedes->fetchAll(PDO::FETCH_ASSOC);

// Construir consulta con filtros
$query = "SELECT SQL_CALC_FOUND_ROWS e.*, 
                 s.nombre as sede_nombre,
                 CONCAT(emp.first_Name, ' ', emp.first_LastName) as admin_nombre,
                 CONCAT(emp_user.first_Name, ' ', emp_user.first_LastName) as nombre_usuario,
                 emp_user.CC as usuario_cc,
                 emp_user.sede_id as usuario_sede_id,
                 emp_user_sede.nombre as usuario_sede_nombre,
                 emp_user.id as usuario_id,
                 COALESCE(emp_user_sede.nombre, s.nombre) as sede_final
          FROM equipos e 
          LEFT JOIN sedes s ON e.sede_id = s.id
          LEFT JOIN employee emp ON e.it_admin_id = emp.id 
          LEFT JOIN employee emp_user ON e.usuario_asignado = emp_user.id 
          LEFT JOIN sedes emp_user_sede ON emp_user.sede_id = emp_user_sede.id
          WHERE 1=1";

$params = [];

if ($filtro_sede) {
    $query .= " AND (e.sede_id = :sede_equipo OR emp_user.sede_id = :sede_usuario)";
    $params[':sede_equipo'] = $filtro_sede;
    $params[':sede_usuario'] = $filtro_sede;
}

if ($filtro_disponibles) {
    $query .= " AND (e.usuario_asignado IS NULL OR e.usuario_asignado = '') AND e.estado = 'activo' AND e.en_it = 0";
}

if ($filtro_busqueda) {
    $query .= " AND (e.activo_fijo LIKE :busqueda OR e.serial_number LIKE :busqueda)";
    $params[':busqueda'] = '%' . $filtro_busqueda . '%';
}

if ($filtro_mantenimiento) {
    if ($filtro_mantenimiento == 'en_mantenimiento') {
        $query .= " AND e.en_it = 1 AND e.estado_it != 'descompuesto'";
    } elseif ($filtro_mantenimiento == 'descompuestos') {
        $query .= " AND e.en_it = 1 AND e.estado_it = 'descompuesto'";
    } elseif ($filtro_mantenimiento == 'todos_mantenimiento') {
        $query .= " AND e.en_it = 1";
    }
}

// Ordenar por activo fijo numéricamente (convertir a número para ordenamiento correcto)
$query .= " ORDER BY CAST(e.activo_fijo AS UNSIGNED), e.activo_fijo";

// Agregar paginación
$query .= " LIMIT :limit OFFSET :offset";
$params[':limit'] = $por_pagina;
$params[':offset'] = $offset;

$stmt = $conn->prepare($query);

foreach ($params as $key => $value) {
    if ($key === ':limit' || $key === ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}

$stmt->execute();
$equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener el total de registros para paginación
$stmt_total = $conn->prepare("SELECT FOUND_ROWS() as total");
$stmt_total->execute();
$total_registros = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $por_pagina);

// Obtener marcas
$query_marcas = "SELECT * FROM marcas_equipos ORDER BY nombre";
$stmt_marcas = $conn->prepare($query_marcas);
$stmt_marcas->execute();
$marcas = $stmt_marcas->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Equipos - <?php echo SITE_NAME; ?></title>
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
        background-color: #198754 !important;
        border-color: #198754 !important;
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
    
    .btn-warning {
        background-color: #ffc107 !important;
        border-color: #ffc107 !important;
        color: #353132 !important;
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
    
    .btn-outline-danger {
        border-color: #be1622 !important;
        color: #be1622 !important;
    }
    
    .btn-outline-danger:hover {
        background-color: #be1622 !important;
        border-color: #be1622 !important;
        color: white !important;
    }
    
    .btn-outline-secondary {
        border-color: #6c757d !important;
        color: #6c757d !important;
    }
    
    .btn-outline-secondary:hover {
        background-color: #6c757d !important;
        border-color: #6c757d !important;
        color: white !important;
    }
    
    /* BADGES */
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
    
    .badge.bg-dark {
        background-color: #353132 !important;
    }
    
    /* HOVER EN FILAS DE LA TABLA */
    .table-hover tbody tr:hover {
        background-color: #33617e !important;
        color: white !important;
    }
    
    /* Estilos específicos para esta página */
    .equipo-info td {
        padding: 8px 12px;
        border-bottom: 1px solid #eee;
    }
    .equipo-info td:first-child {
        font-weight: bold;
        width: 30%;
        color: #555;
    }
    .categoria-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .categoria-row:hover {
        background-color: #f8f9fa;
    }
    
    /* Estilos para drag and drop */
    .border-rounded {
        transition: all 0.3s ease;
        border: 2px dashed #ccc;
    }
    .border-rounded:hover {
        border-color: #2196F3;
        background-color: #e8f4fd;
    }
    .border-rounded.drag-over {
        background-color: #e3f2fd !important;
        border-color: #2196F3 !important;
        border-style: solid;
    }
    #vista-previa {
        transition: all 0.3s ease;
    }
    .img-thumbnail {
        max-width: 100%;
        height: auto;
        cursor: pointer;
        transition: transform 0.2s;
    }
    .img-thumbnail:hover {
        transform: scale(1.02);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    .search-container {
        position: relative;
    }
    .search-container .btn {
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
    }
    .search-container .form-control {
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
    }
    .pagination {
        margin: 0;
    }
    .page-item.active .page-link {
        background-color: #003a5d;
        border-color: #003a5d;
    }
    .table-responsive {
        min-height: 400px;
    }
    
    /* Estilos para modales de imágenes */
    .modal-img-ampliada .modal-dialog {
        max-width: max-content !important;
        margin: auto;
    }
    .modal-img-ampliada .modal-content {
        background-color: transparent !important;
        border: none !important;
    }
    .modal-img-ampliada .modal-body {
        padding: 0;
        text-align: center;
    }
    .modal-img-ampliada img {
        max-height: 80vh;
        max-width: 100%;
        object-fit: contain;
    }
    .modal-img-ampliada {
        z-index: 1060 !important;
    }
    .modal-img-ampliada .btn-close {
        background-color: white !important;
        opacity: 0.9 !important;
        border: 2px solid #ccc;
        padding: 10px !important;
        box-shadow: 0 2px 5px rgba(0,0,0,0.3);
    }
    .modal-img-ampliada .btn-close:hover {
        opacity: 1 !important;
        background-color: #f8f9fa !important;
    }
    
.filtros-container {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 20px;
}

.filtro-group {
    margin-bottom: 10px;
}

.filtro-label {
    font-weight: 600;
    color: #353132;
    margin-bottom: 5px;
}

.tab-btn {
    padding: 8px 16px;
    margin-right: 5px;
    border: 1px solid #9d9d9c;
    background-color: #f8f9fa;
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.3s ease;
    color: #353132;
}

.tab-btn.active {
    background-color: #003a5d;
    border-color: #003a5d;
    color: white;
    font-weight: bold;
}

.tab-btn:hover:not(.active) {
    background-color: #e9ecef;
    border-color: #003a5d;
}

.search-box {
    max-width: 300px;
}

.pagination-container {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-top: 20px;
    flex-wrap: wrap;
}

.results-per-page {
    display: flex;
    align-items: center;
    margin-bottom: 10px;
}

.results-per-page select {
    margin: 0 10px;
    width: auto;
}

.pagination-info {
    margin: 10px 0;
    text-align: center;
}

@media (max-width: 768px) {
    .pagination-container {
        flex-direction: column;
    }
    .results-per-page {
        margin-bottom: 15px;
    }
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
		    <h1 class="h2">Gestión de Equipos</h1>
		    <?php if ($permiso_crear_equipo || $permiso_generar_informe): ?>
		    <div class="btn-group">
		    	<?php if ($permiso_crear_equipo): ?>
		        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearEquipo">
		            <i class="bi bi-plus-circle"></i> Nuevo Equipo
		        </button>
		        <?php endif; ?>
		        <?php if ($permiso_generar_informe): ?>
		        <a href="generar_informe_equipos.php" class="btn btn-outline-success">
		            <i class="bi bi-file-earmark-text"></i> Generar Informe
		        </a>
		        <?php endif; ?>
		    </div>
		    <?php endif; ?>
		</div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
		<!-- Filtros -->
		<div class="filtros-container">
		    <div class="row">
		        <div class="col-md-8">
		            <div class="filtro-group">
		                <div class="filtro-label">Estado</div>
		                <div class="btn-group" role="group">
		                    <button type="button" class="tab-btn active" data-tab="todos">
		                        <i class="bi bi-pc"></i> Todos
		                    </button>
		                    <button type="button" class="tab-btn" data-tab="disponibles">
		                        <i class="bi bi-check-circle"></i> Disponibles
		                    </button>
		                    <button type="button" class="tab-btn" data-tab="en_mantenimiento">
		                        <i class="bi bi-tools"></i> En Mantenimiento
		                    </button>
		                    <button type="button" class="tab-btn" data-tab="descompuestos">
		                        <i class="bi bi-exclamation-triangle"></i> Descompuestos
		                    </button>
		                    <button type="button" class="tab-btn" data-tab="asignados">
		                        <i class="bi bi-person-check"></i> Asignados
		                    </button>
		                </div>
		            </div>
		        </div>
		        <div class="col-md-4">
		            <div class="filtro-group">
		                <div class="filtro-label">Sede</div>
		                <select class="form-select" id="filtroSede" style="max-width: 300px;">
		                    <option value="">Todas las sedes</option>
		                    <?php foreach ($sedes as $sede): ?>
		                    <option value="<?= $sede['id'] ?>" <?= $filtro_sede == $sede['id'] ? 'selected' : '' ?>>
		                        <?= htmlspecialchars($sede['nombre']) ?>
		                    </option>
		                    <?php endforeach; ?>
		                </select>
		            </div>
		        </div>
		    </div>
		    <div class="row mt-2">
		        <div class="col-md-12">
		            <div class="filtro-group">
		                <div class="filtro-label">Buscar equipo</div>
		                <div class="input-group search-box">
		                    <input type="text" class="form-control" id="buscadorEquipos" 
		                           placeholder="Buscar por activo fijo o serial...">
		                    <span class="input-group-text">
		                        <i class="bi bi-search"></i>
		                    </span>
		                </div>
		            </div>
		        </div>
		    </div>
		</div>	                
		<!-- Contenedor de resultados -->
		<div id="resultados-equipos">
		    <div class="text-center py-4">
		        <div class="spinner-border text-primary" role="status">
		            <span class="visually-hidden">Cargando...</span>
		        </div>
		        <p class="mt-2">Cargando equipos...</p>
		    </div>
		</div>
		            </main>
		        </div>
		    </div>
		    
		    <script>
		    function cambiarItemsPorPagina(cantidad) {
		        const urlParams = new URLSearchParams(window.location.search);
		        urlParams.set('por_pagina', cantidad);
		        urlParams.set('pagina', 1); // Volver a la primera página
		        window.location.href = '?' + urlParams.toString();
		    }
		    </script>
		    
		    <!-- Modal para crear equipo -->
		    <div class="modal fade" id="modalCrearEquipo" tabindex="-1">
		        <div class="modal-dialog modal-lg">
		            <div class="modal-content">
		                <div class="modal-header">
		                    <h5 class="modal-title">Crear Nuevo Equipo</h5>
		                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
		                </div>
		                <form method="POST" action="" enctype="multipart/form-data">
		                    <div class="modal-body">
		                        <div class="row">
		                            <div class="col-md-6">
		                                <div class="mb-3">
		                                    <label for="activo_fijo" class="form-label">Activo Fijo *</label>
		                                    <input type="text" class="form-control" id="activo_fijo" name="activo_fijo" required>
		                                </div>
		                            </div>
		                            <div class="col-md-6">
		                                <div class="mb-3">
		                                    <label for="marca" class="form-label">Marca *</label>
		                                    <select class="form-select" id="marca" name="marca" required>
		                                        <option value="">Seleccione una marca</option>
		                                        <?php foreach ($marcas as $marca): ?>
		                                        <option value="<?php echo htmlspecialchars($marca['nombre']); ?>"><?php echo htmlspecialchars($marca['nombre']); ?></option>
		                                        <?php endforeach; ?>
		                                    </select>
		                                </div>
		                            </div>
		                        </div>
		                        <div class="row">
		                            <div class="col-md-6">
		                                <div class="mb-3">
		                                    <label for="modelo" class="form-label">Modelo *</label>
		                                    <input type="text" class="form-control" id="modelo" name="modelo" required>
		                                </div>
		                            </div>
		                            <div class="col-md-6">
		                                <div class="mb-3">
		                                    <label for="serial_number" class="form-label">Número Serial *</label>
		                                    <input type="text" class="form-control" id="serial_number" name="serial_number" required>
		                                </div>
		                            </div>
		                        </div>
		                        <div class="row">
		                            <div class="col-md-4">
		                                <div class="mb-3">
		                                    <label for="sede_id" class="form-label">Sede *</label>
		                                    <select class="form-select" id="sede_id" name="sede_id" required>
		                                        <option value="">Seleccione una sede</option>
		                                        <?php foreach ($sedes as $sede): ?>
		                                        <option value="<?php echo $sede['id']; ?>"><?php echo htmlspecialchars($sede['nombre']); ?></option>
		                                        <?php endforeach; ?>
		                                    </select>
		                                </div>
		                            </div>
		                            <div class="col-md-4">
		                                <div class="mb-3">
		                                    <label for="procesador" class="form-label">Procesador</label>
		                                    <input type="text" class="form-control" id="procesador" name="procesador">
		                                </div>
		                            </div>
		                            <div class="col-md-4">
		                                <div class="mb-3">
		                                    <label for="ram" class="form-label">RAM</label>
		                                    <input type="text" class="form-control" id="ram" name="ram" placeholder="Ej: 8GB DDR4">
		                                </div>
		                            </div>
		                        </div>
		                        <div class="row">
		                            <div class="col-md-6">
		                                <div class="mb-3">
		                                    <label for="disco_duro" class="form-label">Disco Duro</label>
		                                    <input type="text" class="form-control" id="disco_duro" name="disco_duro" placeholder="Ej: 256GB SSD">
		                                </div>
		                            </div>
						    <div class="col-md-6">
						        <div class="mb-3">
						            <label for="precio" class="form-label">Precio (USD)</label>
						            <input type="number" class="form-control" id="precio" name="precio" step="0.01" min="0" placeholder="0.00">
						        </div>
						    </div>
						</div>
						<div class="row">
						    <div class="col-md-12">
						        <div class="mb-3">
						            <label for="foto" class="form-label">Foto del Equipo</label>
						            <input type="file" class="form-control" id="foto" name="foto" accept="image/*">
						            <small class="form-text">Formatos permitidos: JPG, PNG, GIF. Máx. 2MB</small>
						            
						            <!-- Área para arrastrar y soltar - Asegúrate de tener la clase border-rounded -->
						            <div class="mt-2 p-3 border rounded text-center border-rounded" 
						                 style="background-color: #f8f9fa; cursor: pointer;"
						                 onclick="document.getElementById('foto').click()">
						                <i class="bi bi-cloud-upload display-4 text-muted"></i>
						                <p class="mt-2">Haz clic o arrastra una imagen aquí</p>
						                <small class="text-muted">La imagen se mostrará como vista previa</small>
						            </div>
						            
						            <!-- Vista previa de la imagen -->
						            <div id="vista-previa" class="mt-2 text-center" style="display: none;">
						                <img id="preview-image" class="img-thumbnail" style="max-height: 200px; cursor: pointer;" 
						                     onclick="ampliarImagen(this.src)">
						                <button type="button" class="btn btn-sm btn-danger mt-2" onclick="eliminarVistaPrevia()">
						                    <i class="bi bi-trash"></i> Eliminar
						                </button>
						            </div>
						        </div>
						    </div>
						</div>
		                        
		                        <!-- Campos IT -->
		                        <div class="mb-3 form-check">
		                            <input type="checkbox" class="form-check-input" id="en_it" name="en_it" onchange="toggleItFields()">
		                            <label class="form-check-label" for="en_it">En IT</label>
		                        </div>
		                        
		                        <div id="it_fields" style="display: none;">
		                            <div class="row">
		                                <div class="col-md-6">
		                                    <div class="mb-3">
		                                        <label for="estado_it" class="form-label">Estado en IT</label>
		                                        <select class="form-select" id="estado_it" name="estado_it">
		                                            <option value="">Seleccione estado</option>
		                                            <option value="mantenimiento_azc">Mantenimiento AZC IT</option>
		                                            <option value="mantenimiento_computacion">Mantenimiento Computación</option>
		                                            <option value="descompuesto">Descompuesto/Usado para repuestos</option>
		                                        </select>
		                                    </div>
		                                </div>
		                            </div>
		                            <div class="mb-3">
		                                <label for="notas_it" class="form-label">Notas de IT</label>
		                                <textarea class="form-control" id="notas_it" name="notas_it" rows="3" placeholder="Razón por la que está en IT"></textarea>
		                            </div>
		                        </div>
		                    </div>
		                    <div class="modal-footer">
		                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
		                        <button type="submit" name="crear_equipo" class="btn btn-primary">Crear Equipo</button>
		                    </div>
		                </form>
		            </div>
		        </div>
		    </div>
		    
		    <!-- Modal para imagen ampliada -->
		<div class="modal fade modal-img-ampliada" id="modalImagenAmpliada" tabindex="-1" 
		     data-bs-backdrop="true" data-bs-keyboard="true">
		    <div class="modal-dialog modal-dialog-centered">
		        <div class="modal-content" style="background-color: transparent; border: none;">
		            <div class="modal-body text-center p-0 position-relative">
		                <button type="button" class="btn-close position-absolute top-0 end-0 m-2 bg-white rounded-circle p-2" 
		                        data-bs-dismiss="modal" aria-label="Close" 
		                        style="z-index: 10000; border: 2px solid #ccc;">
		                </button>
		                <img id="imagen-ampliada" src="" class="img-fluid" style="max-height: 90vh; max-width: 90vw;">
		            </div>
		        </div>
		    </div>
		</div>
    
	    <!-- Modales para información completa de equipos -->
	    <?php foreach ($equipos as $equipo): ?>
	    <!-- Modal de Información Completa -->
	    <div class="modal fade" id="modalInfoEquipo<?php echo $equipo['id']; ?>" tabindex="-1">
	        <div class="modal-dialog modal-xl">
	            <div class="modal-content">
	                <div class="modal-header text-white" style="background-color: #003a5d;">
	                    <h5 class="modal-title">Información Completa - <?php echo htmlspecialchars($equipo['activo_fijo']); ?></h5>
	                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
	                </div>
	                <div class="modal-body">
	                    <div class="row">
	                        <div class="col-md-6">
	                            <h5>Información del Equipo</h5>
	                            <table class="table table-sm equipo-info">
	                                <tr>
	                                    <td>Activo Fijo:</td>
	                                    <td><?php echo htmlspecialchars($equipo['activo_fijo']); ?></td>
	                                </tr>
	                                <tr>
	                                    <td>Serial:</td>
	                                    <td><?php echo htmlspecialchars($equipo['serial_number']); ?></td>
	                                </tr>
	                                <tr>
	                                    <td>Marca:</td>
	                                    <td><?php echo htmlspecialchars($equipo['marca']); ?></td>
	                                </tr>
	                                <tr>
	                                    <td>Modelo:</td>
	                                    <td><?php echo htmlspecialchars($equipo['modelo']); ?></td>
	                                </tr>
	                                <tr>
	                                    <td>Procesador:</td>
	                                    <td><?php echo htmlspecialchars($equipo['procesador']); ?></td>
	                                </tr>
	                                <tr>
	                                    <td>RAM:</td>
	                                    <td><?php echo htmlspecialchars($equipo['ram']); ?></td>
	                                </tr>
	                                <tr>
	                                    <td>Disco Duro:</td>
	                                    <td><?php echo htmlspecialchars($equipo['disco_duro']); ?></td>
	                                </tr>
	                                <!-- En el modal de información, agregar después de disco duro -->
					<tr>
					    <td>Precio:</td>
					    <td>
					        <?php if (!empty($equipo['precio'])): ?>
					            $<?php echo number_format($equipo['precio'], 2); ?> USD
					        <?php else: ?>
					            <span class="text-muted">No especificado</span>
					        <?php endif; ?>
					    </td>
					</tr>
					<tr>
					    <td>Foto:</td>
					    <td>
					        <?php if (!empty($equipo['foto'])): ?>
					            <img src="../uploads/equipos/<?php echo htmlspecialchars($equipo['foto']); ?>" 
					                 class="img-thumbnail" style="max-height: 150px; cursor: pointer;" 
					                 onclick="ampliarImagen(this.src)">
					        <?php else: ?>
					            <span class="text-muted">No hay foto disponible</span>
					        <?php endif; ?>
					    </td>
					</tr>
	                            </table>
	                        </div>
	                        <div class="col-md-6">
	                            <h5>Información de Asignación</h5>
	                            <table class="table table-sm equipo-info">
	                                <tr>
	                                    <td>Sede:</td>
	                                    <td>
	                                        <?php echo htmlspecialchars($equipo['sede_final'] ?? 'Sin sede'); ?>
					        <?php if (!empty($equipo['usuario_asignado']) && $equipo['sede_nombre'] != $equipo['sede_final']): ?>
					        <br><small class="text-muted">(Heredada del usuario asignado)</small>
					        <?php endif; ?>
	                                    </td>
	                                </tr>
	                                <tr>
					    <td>Usuario Asignado:</td>
					    <td>
					        <?php if (!empty($equipo['nombre_usuario'])): ?>
					            <strong><?php echo htmlspecialchars($equipo['nombre_usuario']); ?></strong>
					            <br><small class="text-muted">CC: <?php echo htmlspecialchars($equipo['usuario_cc']); ?></small>
					        <?php elseif (!empty($equipo['usuario_asignado'])): ?>
					            <?php if (!empty($equipo['usuario_cc'])): ?>
					                <span class="text-muted">CC: <?php echo htmlspecialchars($equipo['usuario_cc']); ?></span>
					            <?php else: ?>
					                <span class="text-muted">Usuario ID: <?php echo htmlspecialchars($equipo['usuario_asignado']); ?></span>
					            <?php endif; ?>
					        <?php else: ?>
					            <span class="text-muted">Sin asignar</span>
					        <?php endif; ?>
					    </td>
					</tr>
	                                <tr>
	                                    <td>Estado IT:</td>
	                                    <td>
	                                        <?php if ($equipo['en_it']): ?>
	                                            <span class="badge bg-warning">
	                                                <?php 
							$estados_it = [
							    'mantenimiento_azc' => 'Mantenimiento AZC',
							    'mantenimiento_computacion' => 'Mantenimiento Computación',
							    'descompuesto' => 'Descompuesto'
							];
							$estado_texto = $estados_it[$equipo['estado_it']] ?? 'En IT';
							$badge_color = ($equipo['estado_it'] == 'descompuesto') ? 'danger' : 'warning';
							?>
							<span class="badge bg-<?php echo $badge_color; ?>">
							    <?php echo $estado_texto; ?>
	                                            </span>
	                                        <?php else: ?>
	                                            <span class="badge bg-success">Activo</span>
	                                        <?php endif; ?>
	                                    </td>
	                                </tr>
	                                <tr>
	                                    <td>Notas IT:</td>
	                                    <td><?php echo htmlspecialchars($equipo['notas_it']); ?></td>
	                                </tr>
	                                <tr>
	                                    <td>Última actualización:</td>
	                                    <td>
	                                        <?php if ($equipo['it_fecha']): ?>
	                                            <?php echo date('d/m/Y H:i', strtotime($equipo['it_fecha'])); ?> 
	                                            por <?php echo htmlspecialchars($equipo['admin_nombre']); ?>
	                                        <?php else: ?>
	                                            <span class="text-muted">Nunca actualizado</span>
	                                        <?php endif; ?>
	                                    </td>
	                                </tr>
	                                <tr>
					    <td>Antigüedad en la empresa:</td>
					    <td>
					        <?php 
					        if (!empty($equipo['creado_en'])) {
					            echo calcularAntiguedad($equipo['creado_en']);
					        } else {
					            echo '<span class="text-muted">Fecha no disponible</span>';
					        }
					        ?>
					    </td>
					</tr>
	                            </table>
	                        </div>
	                    </div>
	
	                    <hr>
	                    <h5>Historial del Equipo</h5>
	                    <?php
	                    $query_historial = "SELECT h.*, CONCAT(emp.first_Name, ' ', emp.first_LastName) as admin_nombre 
	                                      FROM historial h 
	                                      LEFT JOIN employee emp ON h.admin_id = emp.id 
	                                      WHERE (h.notas LIKE CONCAT('%', :activo_fijo, '%'))
	                                      ORDER BY h.creado_en DESC";
	                    $stmt_historial = $conn->prepare($query_historial);
	                    $stmt_historial->bindValue(':activo_fijo', $equipo['activo_fijo']);
	                    $stmt_historial->execute();
	                    $historial = $stmt_historial->fetchAll(PDO::FETCH_ASSOC);
	                    ?>
	                    
	                    <div class="table-responsive">
	                        <table class="table table-striped table-sm">
	                            <thead>
	                                <tr>
	                                    <th>Fecha</th>
	                                    <th>Acción</th>
	                                    <th>Administrador</th>
	                                    <th>Notas</th>
	                                </tr>
	                            </thead>
	                            <tbody>
	                                <?php if (count($historial) > 0): ?>
	                                    <?php foreach ($historial as $registro): ?>
	                                    <tr>
	                                        <td><?php echo date('d/m/Y H:i', strtotime($registro['creado_en'])); ?></td>
	                                        <td>
	                                            <span class="badge bg-<?php 
	                                            $badge_colors = [
	                                                'asignar_equipo' => 'success',
	                                                'desasignar_equipo' => 'danger',
	                                                'crear_equipo' => 'primary',
	                                                'eliminar_equipo' => 'warning',
	                                                'cambio_sede' => 'info',
	                                                'cambio_estado_it' => 'warning',
	                                                'cambio_procesador' => 'secondary',
	                                                'cambio_ram' => 'secondary',
	                                                'cambio_disco' => 'secondary'
	                                            ];
	                                            echo $badge_colors[$registro['accion']] ?? 'secondary';
	                                            ?>">
	                                                <?php echo ucfirst(str_replace('_', ' ', $registro['accion'])); ?>
	                                            </span>
	                                        </td>
	                                        <td><?php echo htmlspecialchars($registro['admin_nombre']); ?></td>
	                                        <td><?php echo htmlspecialchars($registro['notas']); ?></td>
	                                    </tr>
	                                    <?php endforeach; ?>
	                                <?php else: ?>
	                                    <tr>
	                                        <td colspan="4" class="text-center">No hay historial para este equipo</td>
	                                    </tr>
	                                <?php endif; ?>
	                            </tbody>
	                        </table>
	                    </div>
	                </div>
	                <div class="modal-footer">
	                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
	                </div>
	            </div>
	        </div>
	    </div>
	
	    <!-- Modal para editar equipo -->
	    <div class="modal fade" id="modalEditarEquipo<?php echo $equipo['id']; ?>" tabindex="-1">
	        <div class="modal-dialog modal-lg">
	            <div class="modal-content">
	                <div class="modal-header">
	                    <h5 class="modal-title">Editar Equipo - <?php echo htmlspecialchars($equipo['activo_fijo']); ?></h5>
	                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
	                </div>
	                <form method="POST" action="" enctype="multipart/form-data">
	                    <input type="hidden" name="equipo_id" value="<?php echo $equipo['id']; ?>">
	                    <div class="modal-body">
	                        <div class="row">
	                            <div class="col-md-6">
	                                <div class="mb-3">
	                                    <label class="form-label">Activo Fijo</label>
	                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($equipo['activo_fijo']); ?></p>
	                                </div>
	                            </div>
	                            <div class="col-md-6">
	                                <div class="mb-3">
	                                    <label class="form-label">Serial</label>
	                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($equipo['serial_number']); ?></p>
	                                </div>
	                            </div>
	                        </div>
	                        <div class="row">
	                        <div class="col-md-6">
	                        <?php /*
	                            <div class="mb-3">
	                                <label for="creado_en<?php echo $equipo['id']; ?>" class="form-label">Fecha de Creación</label>
	                                <input type="datetime-local" class="form-control" id="creado_en<?php echo $equipo['id']; ?>" 
	                                       name="creado_en" value="<?php echo !empty($equipo['creado_en']) ? date('Y-m-d\TH:i', strtotime($equipo['creado_en'])) : ''; ?>">
	                                <small class="form-text">Fecha original de ingreso al inventario (para migración)</small>
	                            </div>
	                            */ ?>
	                        </div>
	                    </div>
	                        <div class="row">
	                            <div class="col-md-4">
	                                <div class="mb-3">
	                                    <label class="form-label">Marca</label>
	                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($equipo['marca']); ?></p>
	                                </div>
	                            </div>
	                            <div class="col-md-4">
	                                <div class="mb-3">
	                                    <label class="form-label">Modelo</label>
	                                    <p class="form-control-plaintext"><?php echo htmlspecialchars($equipo['modelo']); ?></p>
	                                </div>
	                            </div>
	                            <div class="col-md-4">
	                                <div class="mb-3">
	                                    <label for="sede_id<?php echo $equipo['id']; ?>" class="form-label">Sede</label>
	                                    <select class="form-select" id="sede_id<?php echo $equipo['id']; ?>" name="sede_id" required>
	                                        <option value="">Seleccione una sede</option>
	                                        <?php foreach ($sedes as $sede): ?>
	                                        <option value="<?php echo $sede['id']; ?>" <?php echo $equipo['sede_id'] == $sede['id'] ? 'selected' : ''; ?>>
	                                            <?php echo htmlspecialchars($sede['nombre']); ?>
	                                        </option>
	                                        <?php endforeach; ?>
	                                    </select>
	                                </div>
	                            </div>
	                        </div>
	                        <div class="row">
	                            <div class="col-md-4">
	                                <div class="mb-3">
	                                    <label for="procesador<?php echo $equipo['id']; ?>" class="form-label">Procesador</label>
	                                    <input type="text" class="form-control" id="procesador<?php echo $equipo['id']; ?>" name="procesador" value="<?php echo htmlspecialchars($equipo['procesador']); ?>">
	                                </div>
	                            </div>
	                            <div class="col-md-4">
	                                <div class="mb-3">
	                                    <label for="ram<?php echo $equipo['id']; ?>" class="form-label">RAM</label>
	                                    <input type="text" class="form-control" id="ram<?php echo $equipo['id']; ?>" name="ram" value="<?php echo htmlspecialchars($equipo['ram']); ?>" placeholder="Ej: 8GB DDR4">
	                                </div>
	                            </div>
	                            <div class="col-md-4">
	                                <div class="mb-3">
	                                    <label for="disco_duro<?php echo $equipo['id']; ?>" class="form-label">Disco Duro</label>
	                                    <input type="text" class="form-control" id="disco_duro<?php echo $equipo['id']; ?>" name="disco_duro" value="<?php echo htmlspecialchars($equipo['disco_duro']); ?>" placeholder="Ej: 256GB SSD">
	                                </div>
	                            </div>
	                            <div class="row">
					    <div class="col-md-6">
					        <div class="mb-3">
					            <label for="precio<?php echo $equipo['id']; ?>" class="form-label">Precio (USD)</label>
					            <input type="number" class="form-control" id="precio<?php echo $equipo['id']; ?>" 
					                   name="precio" step="0.01" min="0" value="<?php echo htmlspecialchars($equipo['precio'] ?? ''); ?>">
					        </div>
					    </div>
					</div>
					
					<div class="row">
					    <div class="col-md-12">
					        <div class="mb-3">
					            <label class="form-label">Foto Actual</label>
					            <?php if (!empty($equipo['foto'])): ?>
					            <div class="text-center mb-2">
					                <img src="../uploads/equipos/<?php echo htmlspecialchars($equipo['foto']); ?>" 
					                     class="img-thumbnail" style="max-height: 200px;">
					                <br>
					                <small><?php echo htmlspecialchars($equipo['foto']); ?></small>
					            </div>
					            <?php else: ?>
					            <p class="text-muted">No hay foto asignada</p>
					            <?php endif; ?>
					            
					            <label for="foto<?php echo $equipo['id']; ?>" class="form-label">Cambiar Foto</label>
					            <input type="file" class="form-control" id="foto<?php echo $equipo['id']; ?>" 
					                   name="foto" accept="image/*">
					            <small class="form-text">Dejar vacío para mantener la foto actual</small>
					        </div>
					    </div>
					</div>
	                        </div>
	                        <div class="row">
	                            <div class="col-md-6">
	                                <div class="mb-3">
	                                    <label class="form-label">Usuario Asignado</label>
	                                    <p class="form-control-plaintext">
	                                        <?php if (!empty($equipo['nombre_usuario'])): ?>
	                                            <?php echo htmlspecialchars($equipo['nombre_usuario']); ?>
	                                            <br><small class="text-muted">CC: <?php echo htmlspecialchars($equipo['usuario_cc']); ?></small>
	                                        <?php elseif (!empty($equipo['usuario_cc'])): ?>
	                                            <span class="text-muted">Usuario ID: <?php echo htmlspecialchars($equipo['usuario_cc']); ?></span>
	                                        <?php else: ?>
	                                            <span class="text-muted">Sin asignar</span>
	                                        <?php endif; ?>
	                                    </p>
	                                </div>
	                            </div>
	                        </div>
	                        
	                        <!-- Campos IT -->
	                        <div class="mb-3 form-check">
	                            <input type="checkbox" class="form-check-input" id="en_it<?php echo $equipo['id']; ?>" name="en_it" <?php echo $equipo['en_it'] ? 'checked' : ''; ?> onchange="toggleItFieldsEdit('en_it<?php echo $equipo['id']; ?>', 'it_fields<?php echo $equipo['id']; ?>')">
	                            <label class="form-check-label" for="en_it<?php echo $equipo['id']; ?>">En IT</label>
	                        </div>
	                        
	                        <div id="it_fields<?php echo $equipo['id']; ?>" style="display: <?php echo $equipo['en_it'] ? 'block' : 'none'; ?>;">
	                            <div class="row">
	                                <div class="col-md-6">
	                                    <div class="mb-3">
	                                        <label for="estado_it<?php echo $equipo['id']; ?>" class="form-label">Estado en IT</label>
	                                        <select class="form-select" id="estado_it<?php echo $equipo['id']; ?>" name="estado_it">
	                                            <option value="">Seleccione estado</option>
	                                            <option value="mantenimiento_azc" <?php echo $equipo['estado_it'] == 'mantenimiento_azc' ? 'selected' : ''; ?>>Mantenimiento AZC IT</option>
	                                            <option value="mantenimiento_computacion" <?php echo $equipo['estado_it'] == 'mantenimiento_computacion' ? 'selected' : ''; ?>>Mantenimiento Computación</option>
	                                            <option value="descompuesto" <?php echo $equipo['estado_it'] == 'descompuesto' ? 'selected' : ''; ?>>Descompuesto/Usado para repuestos</option>
	                                        </select>
	                                    </div>
	                                </div>
	                            </div>
	                            <div class="mb-3">
	                                <label for="notas_it<?php echo $equipo['id']; ?>" class="form-label">Notas de IT</label>
	                                <textarea class="form-control" id="notas_it<?php echo $equipo['id']; ?>" name="notas_it" rows="3" placeholder="Razón por la que está en IT"><?php echo htmlspecialchars($equipo['notas_it']); ?></textarea>
	                                <?php if ($equipo['it_fecha']): ?>
	                                <div class="form-text">
	                                    Última actualización: <?php echo date('d/m/Y H:i', strtotime($equipo['it_fecha'])); ?> 
	                                    por <?php echo htmlspecialchars($equipo['admin_nombre']); ?>
	                                </div>
	                                <?php endif; ?>
	                            </div>
	                        </div>
	                    </div>
	                    <div class="modal-footer">
	                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
	                        <button type="submit" name="actualizar_equipo" class="btn btn-primary">Actualizar Equipo</button>
	                    </div>
	                </form>
	            </div>
	        </div>
	    </div>
	    <?php endforeach; ?>
    
   <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ===== FUNCIONES PRINCIPALES =====

// Función para ampliar la imagen
function ampliarImagen(src) {
    try {
        const imgAmpliada = document.getElementById('imagen-ampliada');
        if (imgAmpliada) {
            imgAmpliada.src = src;
            const modal = new bootstrap.Modal(document.getElementById('modalImagenAmpliada'));
            modal.show();
        }
    } catch (error) {
        console.error('Error al ampliar imagen:', error);
    }
}

// Función para mostrar/ocultar campos IT
function toggleItFields() {
    try {
        const enIt = document.getElementById('en_it');
        const itFields = document.getElementById('it_fields');
        if (enIt && itFields) {
            itFields.style.display = enIt.checked ? 'block' : 'none';
        }
    } catch (error) {
        console.error('Error en toggleItFields:', error);
    }
}

// Función para mostrar/ocultar campos IT en edición
function toggleItFieldsEdit(checkboxId, fieldsId) {
    try {
        const enIt = document.getElementById(checkboxId);
        const itFields = document.getElementById(fieldsId);
        if (enIt && itFields) {
            itFields.style.display = enIt.checked ? 'block' : 'none';
        }
    } catch (error) {
        console.error('Error en toggleItFieldsEdit:', error);
    }
}

// Función para ver información del equipo
function verEquipo(equipoId) {
    try {
        const modalElement = document.getElementById('modalInfoEquipo' + equipoId);
        if (modalElement) {
            const modal = new bootstrap.Modal(modalElement);
            modal.show();
        } else {
            console.warn('Modal de información no encontrado para equipo ID:', equipoId);
            // Intentar recargar la página si el modal no existe
            setTimeout(() => {
                window.location.reload();
            }, 500);
        }
    } catch (error) {
        console.error('Error al abrir modal de equipo:', error);
    }
}

// Función para cambiar items por página
function cambiarItemsPorPagina(cantidad) {
    try {
        const urlParams = new URLSearchParams(window.location.search);
        urlParams.set('por_pagina', cantidad);
        urlParams.set('pagina', 1);
        window.location.href = '?' + urlParams.toString();
    } catch (error) {
        console.error('Error al cambiar items por página:', error);
    }
}

// ===== FUNCIONES DRAG & DROP =====

function preventDefaults(e) {
    e.preventDefault();
    e.stopPropagation();
}

function highlight(e) {
    try {
        e.currentTarget.style.backgroundColor = '#e3f2fd';
        e.currentTarget.style.borderColor = '#2196F3';
    } catch (error) {
        console.error('Error en highlight:', error);
    }
}

function unhighlight(e) {
    try {
        e.currentTarget.style.backgroundColor = '#f8f9fa';
        e.currentTarget.style.borderColor = '#ccc';
    } catch (error) {
        console.error('Error en unhighlight:', error);
    }
}

function handleDrop(e) {
    try {
        const dt = e.dataTransfer;
        const files = dt.files;
        const input = e.currentTarget.closest('.mb-3')?.querySelector('input[type="file"]');
        
        if (files.length > 0 && input) {
            input.files = files;
            const event = new Event('change', { bubbles: true });
            input.dispatchEvent(event);
        }
        unhighlight(e);
    } catch (error) {
        console.error('Error en handleDrop:', error);
    }
}

function mostrarVistaPrevia(file, input) {
    try {
        if (!file.type.match('image.*')) {
            alert('Por favor, selecciona solo archivos de imagen (JPG, PNG, GIF)');
            return;
        }
        
        if (file.size > 2097152) {
            alert('El archivo es demasiado grande. Máximo permitido: 2MB');
            return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const previewContainer = input.closest('.mb-3')?.querySelector('#vista-previa');
            const previewImage = previewContainer?.querySelector('#preview-image');
            
            if (previewImage) {
                previewImage.src = e.target.result;
                previewImage.onclick = function() {
                    ampliarImagen(this.src);
                };
            }
            if (previewContainer) previewContainer.style.display = 'block';
        }
        reader.readAsDataURL(file);
    } catch (error) {
        console.error('Error en mostrarVistaPrevia:', error);
    }
}

function eliminarVistaPrevia() {
    try {
        const previewContainer = document.getElementById('vista-previa');
        const fileInput = document.getElementById('foto');
        
        if (previewContainer) previewContainer.style.display = 'none';
        if (fileInput) fileInput.value = '';
    } catch (error) {
        console.error('Error en eliminarVistaPrevia:', error);
    }
}

// ===== INICIALIZACIÓN SEGURA =====

document.addEventListener('DOMContentLoaded', function() {
    try {
        console.log('Inicializando gestión de equipos...');
        
        // Configurar drag and drop de forma segura
        const fileInputs = document.querySelectorAll('input[type="file"][accept="image/*"]');
        
        fileInputs.forEach(input => {
            try {
                const dropZone = input.closest('.mb-3')?.querySelector('.border-rounded');
                
                if (dropZone) {
                    // Prevenir comportamientos por defecto
                    ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                        dropZone.addEventListener(eventName, preventDefaults, false);
                    });
                    
                    // Efectos visuales al arrastrar
                    ['dragenter', 'dragover'].forEach(eventName => {
                        dropZone.addEventListener(eventName, highlight, false);
                    });
                    
                    ['dragleave', 'drop'].forEach(eventName => {
                        dropZone.addEventListener(eventName, unhighlight, false);
                    });
                    
                    // Manejar drop
                    dropZone.addEventListener('drop', handleDrop, false);
                    
                    // Actualizar vista previa cuando se selecciona archivo
                    input.addEventListener('change', function(e) {
                        if (this.files && this.files[0]) {
                            mostrarVistaPrevia(this.files[0], this);
                        }
                    });
                }
            } catch (error) {
                console.error('Error configurando input de archivo:', error);
            }
        });
        
        // Hacer áreas de drop clickeables
        document.querySelectorAll('.border-rounded').forEach(dropZone => {
            dropZone.addEventListener('click', function(e) {
                try {
                    const input = this.closest('.mb-3')?.querySelector('input[type="file"]');
                    if (input) {
                        input.click();
                    }
                } catch (error) {
                    console.error('Error en click de drop zone:', error);
                }
            });
        });
        
        // Inicializar función toggleItFields si existe el checkbox
        const enItCheckbox = document.getElementById('en_it');
        if (enItCheckbox) {
            toggleItFields(); // Establecer estado inicial
        }
        
        console.log('Gestión de equipos inicializada correctamente');
        
    } catch (error) {
        console.error('Error en la inicialización:', error);
    }
});

// Función global para el body onload
function toggleItFields() {
    try {
        const enIt = document.getElementById('en_it');
        const itFields = document.getElementById('it_fields');
        if (enIt && itFields) {
            itFields.style.display = enIt.checked ? 'block' : 'none';
        }
    } catch (error) {
        console.error('Error en toggleItFields (global):', error);
    }
}

// Variables globales para equipos
let filtroTab = "todos";
let filtroSede = "";
let paginaActual = 1;
let resultadosPorPagina = 20;

// Inicialización
document.addEventListener('DOMContentLoaded', function() {
    inicializarFiltrosEquipos();
    cargarEquipos();
});

// Inicializar filtros
function inicializarFiltrosEquipos() {
    // Filtros por pestañas
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            filtroTab = btn.getAttribute('data-tab');
            paginaActual = 1;
            cargarEquipos();
        });
    });

    // Filtro de sede
    document.getElementById("filtroSede").addEventListener("change", function() {
        filtroSede = this.value;
        paginaActual = 1;
        cargarEquipos();
    });

    // Buscador
    document.getElementById("buscadorEquipos").addEventListener("input", debounce(cargarEquipos, 300));
    
    // Select de resultados por página
    const selectPorPagina = document.getElementById('resultados_por_pagina');
    if (selectPorPagina) {
        selectPorPagina.addEventListener('change', function() {
            resultadosPorPagina = this.value;
            paginaActual = 1;
            cargarEquipos();
        });
    }
}

// Función debounce para optimizar búsquedas
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// Inicializar paginación
function inicializarPaginacionEquipos() {
    document.querySelectorAll('.pagination .page-link').forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const url = new URL(this.href);
            paginaActual = parseInt(url.searchParams.get('pagina')) || 1;
            cargarEquipos();
        });
    });
    
    // Select de resultados por página dentro de la tabla
    const selectPorPagina = document.getElementById('resultados_por_pagina');
    if (selectPorPagina) {
        selectPorPagina.addEventListener('change', function() {
            resultadosPorPagina = this.value;
            paginaActual = 1;
            cargarEquipos();
        });
    }
}

// Función para cambiar página
function cambiarPagina(pagina) {
    paginaActual = pagina;
    cargarEquipos();
}
// Función global para abrir modales de edición
function abrirModalEdicion(equipoId, event) {
    if (event) {
        event.preventDefault();
        event.stopPropagation();
    }
    
    console.log('Intentando abrir modal para equipo:', equipoId);
    
    const modalElement = document.getElementById('modalEditarEquipo' + equipoId);
    if (modalElement) {
        console.log('Modal encontrado:', modalElement);
        
        // Verificar si el modal ya tiene una instancia de Bootstrap
        let modal = bootstrap.Modal.getInstance(modalElement);
        if (!modal) {
            console.log('Creando nueva instancia del modal');
            // Si no existe, crear una nueva instancia
            modal = new bootstrap.Modal(modalElement);
        }
        // Mostrar el modal
        modal.show();
    } else {
        console.error('Modal no encontrado: modalEditarEquipo' + equipoId);
        console.log('Elementos modal en el DOM:', document.querySelectorAll('[id^="modalEditarEquipo"]').length);
    }
}

// Función para inicializar eventos después de cargar contenido AJAX
function inicializarEventosEquipos() {
    console.log('Inicializando eventos de equipos...');
    
    // Los botones ya usan onclick, no necesitamos inicializar eventos adicionales
}

// Ejecutar cuando se cargue nuevo contenido via AJAX
document.addEventListener('DOMContentLoaded', function() {
    inicializarEventosEquipos();
});

// También ejecutar después de cargar contenido AJAX
function cargarEquipos(pagina = paginaActual) {
    const buscar = document.getElementById('buscadorEquipos').value;
    const sede = document.getElementById('filtroSede').value;
    
    const url = `equipos_ajax.php?buscar=${encodeURIComponent(buscar)}&filtro=${filtroTab}&sede=${sede}&pagina=${pagina}&resultados_por_pagina=${resultadosPorPagina}&t=${Date.now()}`;

    fetch(url)
        .then(response => {
            if (!response.ok) throw new Error('Error en la respuesta del servidor');
            return response.text();
        })
        .then(html => {
            document.getElementById("resultados-equipos").innerHTML = html;
            
            // Re-inicializar paginación
            inicializarPaginacionEquipos();
        })
        .catch(err => {
            console.error('Error al cargar equipos:', err);
            document.getElementById("resultados-equipos").innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> Error al cargar los equipos.
                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="cargarEquipos()">
                        Reintentar
                    </button>
                </div>`;
        });
}
</script>
</body>