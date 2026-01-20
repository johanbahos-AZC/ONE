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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'dashboard', 'ver')) {
    header("Location: portal.php");
    exit();
}

$functions = new Functions();

// Obtener parámetro de mes (por defecto mes actual)
$mes_seleccionado = $_GET['mes'] ?? date('Y-m');
$anio_actual = date('Y');
$mes_actual = date('m');

// Obtener estadísticas reales filtradas por mes
$query_tickets_mes = "SELECT COUNT(*) as total FROM tickets 
                     WHERE estado = 'completado' 
                     AND DATE_FORMAT(actualizado_en, '%Y-%m') = :mes_actual";
$stmt = $conn->prepare($query_tickets_mes);
$stmt->bindParam(':mes_actual', $mes_seleccionado);
$stmt->execute();
$ticketsResueltosMes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Tickets pendientes (sin filtro de mes)
$query_tickets = "SELECT COUNT(*) as total FROM tickets WHERE estado = 'pendiente'";
$stmt = $conn->prepare($query_tickets);
$stmt->execute();
$ticketsPendientes = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Equipos disponibles
$query_equipos_disponibles = "SELECT COUNT(*) as total FROM equipos 
                            WHERE (usuario_asignado IS NULL OR usuario_asignado = '')
                            AND estado = 'activo' 
                            AND en_it = 0";
$stmt = $conn->prepare($query_equipos_disponibles);
$stmt->execute();
$equiposDisponibles = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Items con stock crítico
$query_criticos = "SELECT COUNT(*) as total FROM items 
                  WHERE stock <= stock_minimo 
                  AND stock_minimo > 0 
                  AND tipo = 'consumible'
                  AND necesita_restock = 1";
$stmt = $conn->prepare($query_criticos);
$stmt->execute();
$itemsCriticos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];

// Tickets recientes
$query_tickets_recientes = "SELECT t.*, i.nombre as item_nombre
                           FROM tickets t 
                           LEFT JOIN items i ON t.item_id = i.id 
                           ORDER BY t.creado_en DESC 
                           LIMIT 5";
$stmt = $conn->prepare($query_tickets_recientes);
$stmt->execute();
$ticketsRecientes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Items con stock bajo
$query_stock_bajo = "SELECT i.*, c.nombre as categoria_nombre 
                    FROM items i 
                    LEFT JOIN categorias c ON i.categoria_id = c.id 
                    WHERE i.stock <= i.stock_minimo 
                    AND i.stock_minimo > 0 
                    AND i.tipo = 'consumible'
                    AND necesita_restock = 1
                    ORDER BY i.stock ASC 
                    LIMIT 5";
$stmt = $conn->prepare($query_stock_bajo);
$stmt->execute();
$itemsStockBajo = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Sección de equipos en IT
$query_equipos_it = "SELECT eq.*, emp.first_Name as admin_nombre 
                   FROM equipos eq 
                   LEFT JOIN employee emp ON eq.it_admin_id = emp.id 
                   WHERE eq.en_it = 1 
                   AND eq.estado_it != 'descompuesto'
                   ORDER BY eq.it_fecha DESC 
                   LIMIT 5";
$stmt_it = $conn->prepare($query_equipos_it);
$stmt_it->execute();
$equipos_it = $stmt_it->fetchAll(PDO::FETCH_ASSOC);

// ================= ESTADÍSTICAS POR USUARIO CON FILTRO DE MES =================
$query_estadisticas_usuarios = "
    SELECT 
        e.id,
        CONCAT(e.first_Name, ' ', e.first_LastName) as responsable_nombre,
        COUNT(CASE WHEN t.estado = 'completado' AND DATE_FORMAT(t.actualizado_en, '%Y-%m') = :mes THEN 1 END) as completados,
        COUNT(CASE WHEN t.estado = 'rechazado' AND DATE_FORMAT(t.actualizado_en, '%Y-%m') = :mes THEN 1 END) as rechazados,
        COUNT(CASE WHEN t.estado IN ('pendiente', 'aprobado') THEN 1 END) as abiertos,
        COUNT(CASE WHEN DATE_FORMAT(t.actualizado_en, '%Y-%m') = :mes THEN 1 END) as total_tickets_mes,
        COUNT(t.id) as total_tickets
    FROM employee e
    LEFT JOIN tickets t ON e.id = t.responsable_id
    WHERE e.role IN ('administrador', 'it')
    GROUP BY e.id, e.first_Name, e.first_LastName
    HAVING total_tickets_mes > 0
    ORDER BY total_tickets_mes DESC
";

$stmt_estadisticas = $conn->prepare($query_estadisticas_usuarios);
$stmt_estadisticas->bindParam(':mes', $mes_seleccionado);
$stmt_estadisticas->execute();
$estadisticasUsuarios = $stmt_estadisticas->fetchAll(PDO::FETCH_ASSOC);

// Estadísticas generales para gráfica (sin rechazados)
$query_estadisticas_generales = "
    SELECT 
        COUNT(CASE WHEN estado = 'completado' AND DATE_FORMAT(actualizado_en, '%Y-%m') = :mes THEN 1 END) as total_completados,
        COUNT(CASE WHEN estado IN ('pendiente', 'aprobado') THEN 1 END) as total_abiertos,
        COUNT(CASE WHEN DATE_FORMAT(actualizado_en, '%Y-%m') = :mes THEN 1 END) as total_tickets_mes
    FROM tickets
    WHERE responsable_id IS NOT NULL
";

$stmt_generales = $conn->prepare($query_estadisticas_generales);
$stmt_generales->bindParam(':mes', $mes_seleccionado);
$stmt_generales->execute();
$estadisticasGenerales = $stmt_generales->fetch(PDO::FETCH_ASSOC);

// Obtener índice de satisfacción del mes
$query_satisfaccion = "
    SELECT 
        AVG(tc.calificacion) as promedio_calificacion,
        COUNT(tc.id) as total_calificaciones
    FROM ticket_calificaciones tc
    INNER JOIN tickets t ON tc.ticket_id = t.id
    WHERE DATE_FORMAT(tc.fecha_calificacion, '%Y-%m') = :mes
";

$stmt_satisfaccion = $conn->prepare($query_satisfaccion);
$stmt_satisfaccion->bindParam(':mes', $mes_seleccionado);
$stmt_satisfaccion->execute();
$satisfaccion = $stmt_satisfaccion->fetch(PDO::FETCH_ASSOC);

$promedio_satisfaccion = $satisfaccion['promedio_calificacion'] ? round($satisfaccion['promedio_calificacion'], 1) : 0;
$total_calificaciones = $satisfaccion['total_calificaciones'] ?? 0;

// Generar lista de meses disponibles para el dropdown
$query_meses = "
    SELECT DISTINCT DATE_FORMAT(actualizado_en, '%Y-%m') as mes
    FROM tickets 
    WHERE actualizado_en IS NOT NULL
    ORDER BY mes DESC
";

$stmt_meses = $conn->prepare($query_meses);
$stmt_meses->execute();
$meses_disponibles = $stmt_meses->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <!-- Chart.js para gráficas -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
    :root {
        --primary-red: #be1622;
        --dark-gray: #353132;
        --light-gray: #9d9d9c;
        --dark-blue: #003a5d;
        --hover-gray: #e9ecef;
        --success-green: #198754; /* Verde menos brillante */
        --warning-yellow: #ffc107; /* Amarillo menos brillante */
    }
    
    .stats-card {
        transition: transform 0.2s;
        border: 2px solid var(--dark-gray);
    }
    .stats-card:hover {
        transform: translateY(-2px);
        border-color: var(--dark-blue);
    }
    .chart-container {
        position: relative;
        height: 300px;
        margin-bottom: 20px;
    }
    .user-stats-table th {
        background-color: var(--dark-blue);
        color: white;
        border-bottom: 2px solid var(--dark-gray);
    }
    .satisfaction-stars {
        color: var(--dark-blue);
        font-size: 1.2em;
    }
    .month-selector {
        max-width: 200px;
    }
    
    /* === ESTILOS MODIFICADOS SOLICITADOS === */
    
    /* Bordes de las tarjetas principales - usando #353132 (gris oscuro) */
    .card {
        border: 2px solid var(--dark-gray);
    }
    
    /* Header de las tarjetas - usando #003a5d (azul oscuro) */
    .card-header {
        background: var(--dark-blue);
        color: white;
        border-bottom: 2px solid var(--dark-gray);
    }
    
    /* Botón desplegable de estadísticas - usando #be1622 (rojo) */
    .btn-outline-primary {
        border-color: var(--primary-red);
        color: var(--primary-red);
    }
    
    .btn-outline-primary:hover {
        background-color: var(--primary-red);
        color: white;
        border-color: var(--primary-red);
    }
    
    /* Headers de las tablas - usando #003a5d (azul oscuro) */
    .table th {
        background-color: var(--dark-blue);
        color: white;
        border-bottom: 2px solid var(--dark-gray);
    }
    
    /* === TARJETAS DE ESTADÍSTICAS DEL SISTEMA === */
    
    /* Tarjeta Completados - borde azul oscuro y texto azul oscuro */
    .card.stats-card.border-success {
        border-color: var(--dark-blue) !important;
    }
    .card.stats-card.border-success .text-success {
        color: var(--dark-blue) !important;
    }
    
    /* Tarjeta Tickets Abiertos - borde rojo y texto rojo */
    .card.stats-card.border-warning {
        border-color: var(--primary-red) !important;
    }
    .card.stats-card.border-warning .text-warning {
        color: var(--primary-red) !important;
    }
    
    /* Tarjeta Total Mes - borde azul oscuro y texto azul oscuro */
    .card.stats-card.border-primary {
        border-color: var(--dark-blue) !important;
    }
    .card.stats-card.border-primary .text-primary {
        color: var(--dark-blue) !important;
    }
    
    /* Tarjeta Satisfacción - borde gris claro y texto gris oscuro */
    .card.stats-card.border-info {
        border-color: var(--light-gray) !important;
    }
    .card.stats-card.border-info .text-info {
        color: var(--dark-gray) !important;
    }

    /* === TARJETAS DE EQUIPOS DISPONIBLES E ITEMS CRÍTICOS === */
    
    /* Equipos Disponibles - texto azul */
    .card.text-center .text-success {
        color: var(--dark-blue) !important;
    }
    
    /* Items Críticos - texto rojo */
    .card.text-center .text-danger {
        color: var(--primary-red) !important;
    }

    /* === BADGES CON COLORES ACTUALIZADOS === */
    
    /* Badges rojos - primary-red */
    .badge.bg-danger {
        background-color: var(--primary-red) !important;
    }
    
    /* Badges azules - dark-blue */
    .badge.bg-info,
    .badge.bg-success {
        background-color: var(--dark-blue) !important;
    }
    
    /* Badges verde - menos brillante */
    .badge.bg-success:not(.bg-info) {
        background-color: var(--success-green) !important;
    }
    
    /* Badges amarillo - menos brillante */
    .badge.bg-warning {
        background-color: var(--warning-yellow) !important;
        color: var(--dark-gray) !important;
    }

    /* === TABLA ITEMS CON STOCK BAJO === */
    
    /* Letras "stock" en rojo */
    .table tbody .fw-bold.text-danger {
        color: var(--primary-red) !important;
    }

    /* === ESTILOS EXISTENTES === */
    
    .table-hover tbody tr:hover {
        background-color: var(--hover-gray);
    }
    
    .border-bottom {
        border-bottom-color: var(--light-gray) !important;
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
                    <h1 class="h2" style="color: var(--dark-gray);">Dashboard</h1>
                    <div class="btn-group">
                        <button class="btn btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#statsCollapse">
                            <i class="bi bi-graph-up"></i> Estadísticas
                        </button>
                    </div>
                </div>
                
                <!-- Estadísticas Colapsables -->
                <div class="collapse show mb-4" id="statsCollapse">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center" style="background-color: #e9ecef;">
                            <h5 class="mb-0">Estadísticas del Sistema</h5>
                            <div class="month-selector">
                                <form method="GET" class="d-flex gap-2">
                                    <select name="mes" class="form-select form-select-sm" onchange="this.form.submit()">
                                        <?php foreach ($meses_disponibles as $mes): 
                                            $fecha = DateTime::createFromFormat('Y-m', $mes['mes']);
                                            $mes_nombre = $fecha ? $fecha->format('F Y') : $mes['mes'];
                                            $selected = $mes['mes'] == $mes_seleccionado ? 'selected' : '';
                                        ?>
                                            <option value="<?php echo $mes['mes']; ?>" <?php echo $selected; ?>>
                                                <?php echo ucfirst($mes_nombre); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                            </div>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Gráfica de distribución general (sin rechazados) -->
                                <div class="col-md-6">
                                    <div class="chart-container">
                                        <canvas id="estadisticasGeneralesChart"></canvas>
                                    </div>
                                </div>
                                
                                <!-- Resumen numérico -->
                                <div class="col-md-6">
                                    <div class="row">
                                        <div class="col-6 mb-3">
                                            <div class="card stats-card border-success">
                                                <div class="card-body text-center">
                                                    <h4 class="text-success"><?php echo $estadisticasGenerales['total_completados']; ?></h4>
                                                    <small class="text-muted">Completados (<?php echo date('F Y', strtotime($mes_seleccionado)); ?>)</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="card stats-card border-warning">
                                                <div class="card-body text-center">
                                                    <h4 class="text-warning"><?php echo $estadisticasGenerales['total_abiertos']; ?></h4>
                                                    <small class="text-muted">Tickets Abiertos (<?php echo date('F Y', strtotime($mes_seleccionado)); ?>)</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="card stats-card border-primary">
                                                <div class="card-body text-center">
                                                    <h4 class="text-primary"><?php echo $estadisticasGenerales['total_tickets_mes']; ?></h4>
                                                    <small class="text-muted">Total Mes</small>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="card stats-card border-info">
                                                <div class="card-body text-center">
                                                    <h4 class="text-info">
                                                        <?php echo $promedio_satisfaccion; ?>
                                                        <small class="satisfaction-stars">
                                                            <?php 
                                                            $estrellas_llenas = floor($promedio_satisfaccion);
                                                            $media_estrella = $promedio_satisfaccion - $estrellas_llenas >= 0.5;
                                                            $estrellas_vacias = 5 - $estrellas_llenas - ($media_estrella ? 1 : 0);
                                                            
                                                            for ($i = 0; $i < $estrellas_llenas; $i++) {
                                                                echo '★';
                                                            }
                                                            if ($media_estrella) {
                                                                echo '☆';
                                                            }
                                                            for ($i = 0; $i < $estrellas_vacias; $i++) {
                                                                echo '☆';
                                                            }
                                                            ?>
                                                        </small>
                                                    </h4>
                                                    <small class="text-muted">Satisfacción (<?php echo $total_calificaciones; ?> calif.)</small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            
                            <!-- Tabla de estadísticas por usuario -->
                            <div class="row mt-4">
                                <div class="col-12">
                                    <h6 class="mb-3" style="color: var(--dark-gray);">Estadísticas por Responsable - <?php echo date('F Y', strtotime($mes_seleccionado)); ?></h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-hover user-stats-table">
                                            <thead>
                                                <tr>
                                                    <th>Responsable</th>
                                                    <th>Completados</th>
                                                    <th>Rechazados</th>
                                                    <th>Abiertos</th>
                                                    <th>Total Mes</th>
                                                    <th>Satisfacción</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php if (count($estadisticasUsuarios) > 0): ?>
                                                    <?php foreach ($estadisticasUsuarios as $usuario): 
                                                        $total_mes = $usuario['total_tickets_mes'];
                                                        $completados = $usuario['completados'];
                                                        $rechazados = $usuario['rechazados'];
                                                        $abiertos = $usuario['abiertos'];
                                                        
                                                        // Obtener satisfacción del usuario
                                                        $query_satisfaccion_usuario = "
                                                            SELECT AVG(tc.calificacion) as promedio
                                                            FROM ticket_calificaciones tc
                                                            INNER JOIN tickets t ON tc.ticket_id = t.id
                                                            WHERE t.responsable_id = :usuario_id
                                                            AND DATE_FORMAT(tc.fecha_calificacion, '%Y-%m') = :mes
                                                        ";
                                                        $stmt_sat_usuario = $conn->prepare($query_satisfaccion_usuario);
                                                        $stmt_sat_usuario->bindParam(':usuario_id', $usuario['id']);
                                                        $stmt_sat_usuario->bindParam(':mes', $mes_seleccionado);
                                                        $stmt_sat_usuario->execute();
                                                        $satisfaccion_usuario = $stmt_sat_usuario->fetch(PDO::FETCH_ASSOC);
                                                        
                                                        $promedio_usuario = $satisfaccion_usuario['promedio'] ? round($satisfaccion_usuario['promedio'], 1) : 'N/A';
                                                    ?>
                                                    <tr>
                                                        <td>
                                                            <strong style="color: var(--dark-gray);"><?php echo htmlspecialchars($usuario['responsable_nombre']); ?></strong>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-success"><?php echo $completados; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-danger"><?php echo $rechazados; ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-warning"><?php echo $abiertos; ?></span>
                                                        </td>
                                                        <td>
                                                            <strong style="color: var(--dark-gray);"><?php echo $total_mes; ?></strong>
                                                        </td>
                                                        <td>
                                                            <?php if ($promedio_usuario !== 'N/A'): ?>
                                                                <span class="satisfaction-stars">
                                                                    <?php 
                                                                    $estrellas_llenas = floor($promedio_usuario);
                                                                    $media_estrella = $promedio_usuario - $estrellas_llenas >= 0.5;
                                                                    $estrellas_vacias = 5 - $estrellas_llenas - ($media_estrella ? 1 : 0);
                                                                    
                                                                    for ($i = 0; $i < $estrellas_llenas; $i++) {
                                                                        echo '★';
                                                                    }
                                                                    if ($media_estrella) {
                                                                        echo '☆';
                                                                    }
                                                                    for ($i = 0; $i < $estrellas_vacias; $i++) {
                                                                        echo '☆';
                                                                    }
                                                                    ?>
                                                                </span>
                                                                <small class="text-muted">(<?php echo $promedio_usuario; ?>)</small>
                                                            <?php else: ?>
                                                                <span class="text-muted">Sin calificaciones</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                    <?php endforeach; ?>
                                                <?php else: ?>
                                                    <tr>
                                                        <td colspan="6" class="text-center py-3 text-muted">
                                                            <i class="bi bi-people display-4"></i>
                                                            <p class="mt-2">No hay estadísticas para <?php echo date('F Y', strtotime($mes_seleccionado)); ?></p>
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
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card text-center mb-2">
                            <div class="card-body">
                                <h5 class="card-title" style="color: var(--dark-gray);">Equipos Disponibles</h5>
                                <a href="equipos.php?filtro=disponibles" class="text-decoration-none">
                                    <h2 class="card-text text-success"><?php echo $equiposDisponibles; ?></h2>
                                </a>
                                <small class="text-muted">Sin asignar</small>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card text-center mb-2">
                            <div class="card-body">
                                <h5 class="card-title" style="color: var(--dark-gray);">Items Críticos</h5>
                                <a href="items.php?filtro=criticos" class="text-decoration-none">
                                    <h2 class="card-text text-danger"><?php echo $itemsCriticos; ?></h2>
                                </a>
                                <small class="text-muted">Stock bajo</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header" style="background-color: #e9ecef;">
                                <h5 class="mb-0">Tickets Recientes</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>#</th>
                                                <th>Empleado</th>
                                                <th>Estado</th>
                                                <th>Fecha</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($ticketsRecientes) > 0): ?>
                                                <?php foreach ($ticketsRecientes as $ticket): ?>
                                                <tr>
                                                    <td>#<?php echo $ticket['id']; ?></td>
                                                    <td><?php echo htmlspecialchars($ticket['empleado_nombre']); ?></td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = [
                                                            'pendiente' => 'bg-warning',
                                                            'aprobado' => 'bg-success',
                                                            'rechazado' => 'bg-danger',
                                                            'completado' => 'bg-info'
                                                        ][$ticket['estado']] ?? 'bg-secondary';
                                                        ?>
                                                        <span class="badge <?php echo $badge_class; ?>">
                                                            <?php echo ucfirst($ticket['estado']); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($ticket['creado_en'])); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-4">
                                                        <i class="bi bi-inbox display-4 text-muted"></i>
                                                        <p class="mt-2">No hay tickets recientes</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-6">
                        <div class="card">
                            <div class="card-header" style="background-color: #e9ecef;">
                                <h5 class="mb-0">Items con Stock Bajo</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Item</th>
                                                <th>Stock</th>
                                                <th>Mínimo</th>
                                                <th>Diferencia</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($itemsStockBajo) > 0): ?>
                                                <?php foreach ($itemsStockBajo as $item): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($item['nombre']); ?></td>
                                                    <td class="fw-bold text-danger"><?php echo $item['stock']; ?></td>
                                                    <td><?php echo $item['stock_minimo']; ?></td>
                                                    <td>
                                                        <span class="badge bg-<?php echo ($item['stock_minimo'] - $item['stock']) > 0 ? 'danger' : 'warning'; ?>">
                                                            <?php echo $item['stock_minimo'] - $item['stock']; ?>
                                                        </span>
                                                    </td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="4" class="text-center py-4">
                                                        <i class="bi bi-check-circle display-4 text-success"></i>
                                                        <p class="mt-2">Todo el stock está en orden</p>
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
                
                <!-- Sección de equipos en IT -->
                <div class="row mt-4">
                    <div class="col-md-12">
                        <div class="card">
                            <div class="card-header" style="background-color: #e9ecef;">
                                <h5 class="mb-0">Equipos en Mantenimiento IT</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-hover">
                                        <thead>
                                            <tr>
                                                <th>Activo Fijo</th>
                                                <th>Marca/Modelo</th>
                                                <th>Estado</th>
                                                <th>En IT desde</th>
                                                <th>Responsable</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if (count($equipos_it) > 0): ?>
                                                <?php foreach ($equipos_it as $equipo): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($equipo['activo_fijo']); ?></td>
                                                    <td><?php echo htmlspecialchars($equipo['marca']); ?> <?php echo htmlspecialchars($equipo['modelo']); ?></td>
                                                    <td>
                                                        <span class="badge bg-warning">En Mantenimiento</span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y', strtotime($equipo['it_fecha'])); ?></td>
                                                    <td><?php echo htmlspecialchars($equipo['admin_nombre']); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-4">
                                                        <i class="bi bi-laptop display-4 text-muted"></i>
                                                        <p class="mt-2">No hay equipos en mantenimiento</p>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gráfica de estadísticas generales (sin rechazados)
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('estadisticasGeneralesChart').getContext('2d');
            const estadisticasGeneralesChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: ['Completados', 'Abiertos'],
                    datasets: [{
                        data: [
                            <?php echo $estadisticasGenerales['total_completados']; ?>,
                            <?php echo $estadisticasGenerales['total_abiertos']; ?>
                        ],
                        backgroundColor: [
                            '#003a5d', // Azul oscuro para completados
                            '#FFFFFF'  // Gris claro para abiertos
                        ],
                        borderColor: [
                            '#002b47',
                            '#FFFFFF'
                        ],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            position: 'bottom',
                        },
                        title: {
                            display: true,
                            text: 'Distribución de Tickets - <?php echo date("F Y", strtotime($mes_seleccionado)); ?>'
                        }
                    }
                }
            });
        });
    </script>
</body>
</html>