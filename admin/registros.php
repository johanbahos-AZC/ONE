<?php
// admin/registros.php
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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'usuarios', 'ver')) {
    header("Location: portal.php");
    exit();
}
$functions = new Functions();
$error = '';
$success = '';


// --- FUNCIÓN PARA OBTENER NOMBRE DEL BARRIO (Cuando está disponible)---
function getLocationName($lat, $lng) {
    $url = "https://nominatim.openstreetmap.org/reverse?format=json&lat={$lat}&lon={$lng}&zoom=18";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RegistrosAZC/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    
    $response = curl_exec($ch);
    curl_close($ch);
    
    if ($response) {
        $data = json_decode($response, true);
        if (isset($data['display_name'])) {
            // Intentar obtener el nombre más específico
            $address = $data['address'];
            if (isset($address['suburb'])) return $address['suburb'];
            if (isset($address['neighbourhood'])) return $address['neighbourhood'];
            if (isset($address['village'])) return $address['village'];
            if (isset($address['town'])) return $address['town'];
            if (isset($address['city'])) return $address['city'];
            return $data['display_name'];
        }
    }
    return null;
}

// --- FUNCIÓN PARA PROCESAR UBICACIÓN ---
function processLocation($location) {
    // ✅ CORRECCIÓN: Manejar valores null o vacíos
    if ($location === null || $location === '' || $location === 'NULL') {
        return [
            'is_coordinate' => false,
            'display' => 'Sin ubicación'
        ];
    }
    
    // ✅ CORRECCIÓN: Convertir a string para preg_match
    $locationStr = (string)$location;
    
    // Extraer coordenadas
    if (preg_match('/Lat:([\d\.\-]+),Long:([\d\.\-]+)/', $locationStr, $matches)) {
        $lat = floatval($matches[1]);
        $lng = floatval($matches[2]);
        
        return [
            'is_coordinate' => true,
            'lat' => $lat,
            'lng' => $lng,
            'google_url' => "https://www.google.com/maps?q={$lat},{$lng}",
            'location_name' => getLocationName($lat, $lng)
        ];
    }
    
    // Para formato simple de coordenadas
    if (preg_match('/([\d\.\-]+),\s*([\d\.\-]+)/', $locationStr, $matches)) {
        $lat = floatval($matches[1]);
        $lng = floatval($matches[2]);
        
        return [
            'is_coordinate' => true,
            'lat' => $lat,
            'lng' => $lng,
            'google_url' => "https://www.google.com/maps?q={$lat},{$lng}",
            'location_name' => getLocationName($lat, $lng)
        ];
    }
    
    return [
        'is_coordinate' => false,
        'display' => $locationStr
    ];
}

// --- FUNCIÓN PARA OBTENER FOTO DE USUARIO ---
function obtenerFotoUsuario($photo_name, $default = 'default_avatar.png') {
    // Primero verificar si existe la foto del usuario
    if ($photo_name && !empty($photo_name)) {
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/photos/' . $photo_name;
        if (file_exists($file_path)) {
            return '/uploads/photos/' . $photo_name;
        }
    }
    
    // Verificar si existe el avatar por defecto
    $default_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/' . $default;
    if (!file_exists($default_path)) {
        // Crear avatar por defecto si no existe
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
    
    return '/assets/images/' . $default;
}

// --- MANEJO DE FILTROS Y PAGINACIÓN ---
$filters = [
    'date_from' => $_GET['date_from'] ?? '',
    'date_to' => $_GET['date_to'] ?? '',
    'employee_id' => $_GET['employee_id'] ?? '',
    'log_type' => $_GET['log_type'] ?? ''
];

// Parámetros de paginación
$resultados_por_pagina = isset($_GET['resultados_por_pagina']) ? (int)$_GET['resultados_por_pagina'] : 50;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;

// Validar valores de paginación
if (!in_array($resultados_por_pagina, [20, 50, 100])) {
    $resultados_por_pagina = 50;
}
if ($pagina_actual < 1) {
    $pagina_actual = 1;
}

// Calcular offset
$offset = ($pagina_actual - 1) * $resultados_por_pagina;

// --- CONSTRUIR CONSULTA ---
$whereConditions = [];
$params = [];
$params_count = [];

if (!empty($filters['date_from'])) {
    $whereConditions[] = "tl.log_time >= :date_from";
    $params[':date_from'] = $filters['date_from'] . ' 00:00:00';
    $params_count[':date_from'] = $filters['date_from'] . ' 00:00:00';
}

if (!empty($filters['date_to'])) {
    $whereConditions[] = "tl.log_time <= :date_to";
    $params[':date_to'] = $filters['date_to'] . ' 23:59:59';
    $params_count[':date_to'] = $filters['date_to'] . ' 23:59:59';
}

if (!empty($filters['employee_id'])) {
    $whereConditions[] = "tl.employee_id = :employee_id";
    $params[':employee_id'] = $filters['employee_id'];
    $params_count[':employee_id'] = $filters['employee_id'];
}

if (!empty($filters['log_type'])) {
    $whereConditions[] = "tl.log_type = :log_type";
    $params[':log_type'] = $filters['log_type'];
    $params_count[':log_type'] = $filters['log_type'];
}

$whereClause = $whereConditions ? 'WHERE ' . implode(' AND ', $whereConditions) : '';

// --- OBTENER REGISTROS ---
$logs = [];
$totalRecords = 0;

try {
    // Consulta de conteo
    $countSql = "SELECT COUNT(*) as total FROM time_logs tl $whereClause";
    $countStmt = $conn->prepare($countSql);
    foreach ($params_count as $key => $value) {
        $countStmt->bindValue($key, $value);
    }
    $countStmt->execute();
    $totalRecords = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    $total_paginas = $totalRecords > 0 ? ceil($totalRecords / $resultados_por_pagina) : 1;
    
    // Ajustar página actual si es mayor que el total de páginas
    if ($pagina_actual > $total_paginas && $total_paginas > 0) {
        $pagina_actual = $total_paginas;
        $offset = ($pagina_actual - 1) * $resultados_por_pagina;
    }
    
// Consulta de datos
$sql = "SELECT tl.*, 
               CONCAT(e.first_Name, ' ', e.first_LastName) as employee_name,
               e.CC as employee_document,
               e.photo as employee_photo
        FROM time_logs tl 
        LEFT JOIN employee e ON tl.employee_id = e.id 
        $whereClause 
        ORDER BY tl.log_time DESC 
        LIMIT :limit OFFSET :offset";
    
    $stmt = $conn->prepare($sql);
    
    // Bind de parámetros de búsqueda
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    // Bind de parámetros de paginación
    $stmt->bindValue(':limit', $resultados_por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Procesar ubicaciones
    foreach ($logs as &$log) {
        $log['location_processed'] = processLocation($log['location'] ?? null);
    }
    unset($log);
    
} catch (PDOException $e) {
    $error = "Error al cargar registros: " . $e->getMessage();
}

// Función auxiliar para generar URLs de paginación
function generarUrlPaginacion($pagina, $resultados_por_pagina) {
    $params = $_GET;
    $params['pagina'] = $pagina;
    $params['resultados_por_pagina'] = $resultados_por_pagina;
    return '?' . http_build_query($params);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registros de Asistencia - <?php echo SITE_NAME; ?></title>
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
    .location-link {
        color: #0d6efd;
        text-decoration: underline;
        cursor: pointer;
    }
    
    .location-link:hover {
        color: #0a58ca;
    }
    
    .location-name {
        font-size: 0.8em;
        color: #6c757d;
        font-style: italic;
    }
    
    .no-location {
        color: #6c757d;
        font-style: italic;
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
    
    /* Color personalizado para el header */
    .navbar-custom {
        background-color: #8c0b0b !important;
    }
    
    .navbar-custom .navbar-brand,
    .navbar-custom .navbar-nav .nav-link {
        color: white !important;
    }
    .photo-preview-sm {
    width: 40px;
    height: 40px;
    object-fit: cover;
    border: 2px solid #dee2e6;
}

.rounded-circle {
    border-radius: 50% !important;
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
                    <h1 class="h2">Registros de Asistencia</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportToExcel()">
                                <i class="bi bi-download"></i> Exportar
                            </button>
                        </div>
                    </div>
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" id="formFiltros">
                            <input type="hidden" name="pagina" value="1">
                            <div class="row">
                                <div class="col-md-3">
                                    <label for="date_from" class="form-label">Desde</label>
                                    <input type="date" class="form-control" id="date_from" name="date_from" 
                                           value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                                </div>
                                <div class="col-md-3">
                                    <label for="date_to" class="form-label">Hasta</label>
                                    <input type="date" class="form-control" id="date_to" name="date_to" 
                                           value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                                </div>
                                <div class="col-md-2">
                                    <label for="employee_id" class="form-label">ID Empleado</label>
                                    <input type="text" class="form-control" id="employee_id" name="employee_id" 
                                           value="<?php echo htmlspecialchars($filters['employee_id']); ?>" 
                                           placeholder="ID o documento">
                                </div>
                                <div class="col-md-2">
                                    <label for="log_type" class="form-label">Tipo</label>
                                    <select class="form-select" id="log_type" name="log_type">
                                        <option value="">Todos</option>
                                        <option value="Entrada" <?php echo $filters['log_type'] == 'Entrada' ? 'selected' : ''; ?>>Entrada</option>
                                        <option value="Salida" <?php echo $filters['log_type'] == 'Salida' ? 'selected' : ''; ?>>Salida</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">&nbsp;</label><br>
                                    <button type="submit" class="btn btn-primary">Filtrar</button>
                                    <a href="?" class="btn btn-secondary">Limpiar</a>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Foto/Documento</th>
                                        <th>Nombre</th>
                                        <th>Tipo</th>
                                        <th>Fecha y Hora</th>
                                        <th>Ubicación</th>
                                        <th>Foto</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($logs) > 0): ?>
                                        <?php foreach ($logs as $log): ?>
                                        <tr>
                                            <td>
                                                    <?php
					    // OBTENER Y MOSTRAR FOTO DEL USUARIO
					    $foto_usuario = obtenerFotoUsuario($log['employee_photo'] ?? null);
					    ?>
					    <img src="<?php echo $foto_usuario; ?>" 
					         class="rounded-circle photo-preview-sm" 
					         style="width: 40px; height: 40px; object-fit: cover; border: 2px solid #dee2e6;"
					         alt="Foto de <?php echo htmlspecialchars($log['employee_name'] ?? 'Usuario'); ?>"
					         title="ID: <?php echo htmlspecialchars($log['employee_id']); ?> - Doc: <?php echo htmlspecialchars($log['employee_document'] ?? 'N/A'); ?>">
					    
					    <?php if (!empty($log['employee_document'])): ?>
					    <br><small class="text-muted"><?php echo htmlspecialchars($log['employee_document']); ?></small>
					    <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['employee_name'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge <?php echo $log['log_type'] === 'Entrada' ? 'bg-success' : 'bg-danger'; ?>">
                                                    <?php echo htmlspecialchars($log['log_type']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo date('d/m/Y H:i:s', strtotime($log['log_time'])); ?></td>
                                            <td>
                                                <?php if ($log['location_processed']['is_coordinate']): ?>
                                                    <a href="<?php echo htmlspecialchars($log['location_processed']['google_url']); ?>" 
                                                       target="_blank" 
                                                       class="location-link"
                                                       title="Abrir en Google Maps">
                                                        <i class="bi bi-geo-alt"></i> Ver en Maps
                                                    </a>
                                                    <?php if (!empty($log['location_processed']['location_name'])): ?>
                                                        <div class="location-name">
                                                            <?php echo htmlspecialchars($log['location_processed']['location_name']); ?>
                                                        </div>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="no-location">
                                                        <?php echo htmlspecialchars($log['location_processed']['display']); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!empty($log['photo_url'])): ?>
                                                    <a href="<?php echo htmlspecialchars($log['photo_url']); ?>" 
                                                       target="_blank" 
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-eye"></i> Ver
                                                    </a>
                                                <?php else: ?>
                                                    <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">
                                                <?php if (!empty($filters['date_from']) || !empty($filters['date_to']) || !empty($filters['employee_id']) || !empty($filters['log_type'])): ?>
                                                No se encontraron registros que coincidan con los filtros
                                                <?php else: ?>
                                                No hay registros de asistencia
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginación -->
                        <?php if ($totalRecords > 0): ?>
                        <div class="pagination-container">
                            <div class="results-per-page">
                                <span>Resultados por página:</span>
                                <select class="form-select form-select-sm" id="resultados_por_pagina" name="resultados_por_pagina" onchange="cambiarResultadosPorPagina(this.value)">
                                    <option value="20" <?php echo $resultados_por_pagina == 20 ? 'selected' : ''; ?>>20</option>
                                    <option value="50" <?php echo $resultados_por_pagina == 50 ? 'selected' : ''; ?>>50</option>
                                    <option value="100" <?php echo $resultados_por_pagina == 100 ? 'selected' : ''; ?>>100</option>
                                </select>
                            </div>
                            
                            <div class="pagination-info">
                                <span>Mostrando <?php echo ($offset + 1); ?> - <?php echo min($offset + $resultados_por_pagina, $totalRecords); ?> de <?php echo $totalRecords; ?> registros</span>
                            </div>
                            
                            <nav aria-label="Page navigation">
                                <ul class="pagination pagination-sm">
                                    <?php if ($pagina_actual > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo generarUrlPaginacion($pagina_actual - 1, $resultados_por_pagina); ?>" aria-label="Anterior">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    // Mostrar páginas (máximo 5 páginas alrededor de la actual)
                                    $inicio = max(1, $pagina_actual - 2);
                                    $fin = min($total_paginas, $pagina_actual + 2);
                                    
                                    if ($inicio > 1) {
                                        echo '<li class="page-item"><a class="page-link" href="' . generarUrlPaginacion(1, $resultados_por_pagina) . '">1</a></li>';
                                        if ($inicio > 2) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                    }
                                    
                                    for ($i = $inicio; $i <= $fin; $i++): 
                                    ?>
                                    <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo generarUrlPaginacion($i, $resultados_por_pagina); ?>"><?php echo $i; ?></a>
                                    </li>
                                    <?php endfor; ?>
                                    
                                    <?php
                                    if ($fin < $total_paginas) {
                                        if ($fin < $total_paginas - 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                        echo '<li class="page-item"><a class="page-link" href="' . generarUrlPaginacion($total_paginas, $resultados_por_pagina) . '">' . $total_paginas . '</a></li>';
                                    }
                                    ?>
                                    
                                    <?php if ($pagina_actual < $total_paginas): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo generarUrlPaginacion($pagina_actual + 1, $resultados_por_pagina); ?>" aria-label="Siguiente">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Función para cambiar resultados por página
    function cambiarResultadosPorPagina(valor) {
        const url = new URL(window.location.href);
        url.searchParams.set('resultados_por_pagina', valor);
        url.searchParams.set('pagina', 1);
        window.location.href = url.toString();
    }
    
    // Establecer fecha máxima como hoy
    document.addEventListener('DOMContentLoaded', function() {
        const today = new Date().toISOString().split('T')[0];
        document.getElementById('date_from').max = today;
        document.getElementById('date_to').max = today;
    });
    
    // Función para exportar a Excel (placeholder)
    function exportToExcel() {
        alert('Función de exportación a Excel - Próximamente');
        // Aquí puedes implementar la exportación a Excel
    }
    
    </script>
</body>
</html>