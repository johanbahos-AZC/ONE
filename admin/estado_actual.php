<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

// Obtener conexión a la base de datos del sistema principal
$database = new Database();
$conn = $database->getConnection();

$nombre = $_GET['buscar'] ?? '';
$filtroEstado = $_GET['filtro'] ?? 'todos';
$filtroSede = $_GET['sede'] ?? '';

// Map visual del estado
function colorEstado($estado) {
    switch (strtolower(trim($estado))) {
        case 'online':
        case 'trabajando':
            return 'style="color: green; font-weight: bold;"';
        case 'away':
        case 'descanso':
            return 'style="color: #b58900; font-weight: bold;"'; // ámbar
        case 'offline':
        case 'inactivo':
            return 'style="color: red; font-weight: bold;"';
        default:
            return '';
    }
}

// Función para ícono de estado
function iconoEstado($estado) {
    switch (strtolower(trim($estado))) {
        case 'online':
        case 'trabajando':
            return '<span class="status-indicator status-online"></span>';
        case 'away':
        case 'descanso':
            return '<span class="status-indicator status-away"></span>';
        case 'offline':
        case 'inactivo':
            return '<span class="status-indicator status-offline"></span>';
        default:
            return '<span class="status-indicator status-offline"></span>';
    }
}

// CONSULTA Con filtro de sede
$sql = "
    SELECT 
        e.id AS empleado_id,
        CONCAT_WS(' ', e.first_Name, e.second_Name, e.first_LastName, e.second_LastName) AS full_name,
        T.worktime,
        T.outtime,
        LOWER(COALESCE(e.last_status, 'offline')) AS last_status,
        e.geo,
        e.position,
        e.extension,
        e.activo_fijo,
        s.nombre as sede_nombre,
        s.id as sede_id
    FROM employee e
    LEFT JOIN sedes s ON e.sede_id = s.id
    LEFT JOIN (
        SELECT T1.*
        FROM TIMERS T1
        INNER JOIN (
            SELECT id_login, MAX(date_Work) AS max_date
            FROM TIMERS
            GROUP BY id_login
        ) T2 ON T1.id_login = T2.id_login AND T1.date_Work = T2.max_date
    ) T ON T.id_login = e.id
    WHERE e.role NOT IN ('retirado', 'candidato')
";

$params = [];

if ($nombre !== '') {
    $sql .= " AND CONCAT_WS(' ', e.first_Name, e.second_Name, e.first_LastName, e.second_LastName) LIKE :nombre";
    $params[':nombre'] = "%$nombre%";
}

if ($filtroSede !== '') {
    $sql .= " AND e.sede_id = :sede";
    $params[':sede'] = $filtroSede;
}

// filtros de pestañas
switch (strtolower($filtroEstado)) {
    case 'activos':
        $sql .= " AND LOWER(COALESCE(e.last_status,'offline')) IN ('online','trabajando')";
        break;
    case 'inactivos':
        $sql .= " AND LOWER(COALESCE(e.last_status,'offline')) IN ('offline','inactivo')";
        break;
    case 'away':
        $sql .= " AND LOWER(COALESCE(e.last_status,'offline')) IN ('away','descanso')";
        break;
    // 'todos' => sin filtro
}

$sql .= " ORDER BY full_name ASC";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    error_log("Error en estado_actual.php: " . $e->getMessage());
    echo '<div class="alert alert-danger">Error al cargar los datos de monitoreo: ' . $e->getMessage() . '</div>';
    exit();
}

// métricas rápidas (compatible con tu versión original)
$total = count($resultados);
$activos = 0;  // online + trabajando
$away = 0;     // away + descanso
$inactivos = 0; // offline + inactivo

foreach ($resultados as $r) {
    $st = $r['last_status'];
    if ($st === 'online' || $st === 'trabajando') $activos++;
    elseif ($st === 'away' || $st === 'descanso') $away++;
    elseif ($st === 'offline' || $st === 'inactivo') $inactivos++;
}

?>
<!-- Métricas con estilo Bootstrap -->
<div class="row mb-4">
    <div class="col-md-12">
        <div class="alert alert-secondary">
            <div class="row text-center">
                <div class="col-md-3">
                    <strong>Activos:</strong> 
                    <span class="badge bg-success"><?= $activos ?></span>
                </div>
                <div class="col-md-3">
                    <strong>Away:</strong> 
                    <span class="badge bg-warning"><?= $away ?></span>
                </div>
                <div class="col-md-3">
                    <strong>Inactivos:</strong> 
                    <span class="badge bg-danger"><?= $inactivos ?></span>
                </div>
                <div class="col-md-3">
                    <strong>Total:</strong> 
                    <span class="badge bg-primary"><?= $total ?></span>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (count($resultados) > 0): ?>
<div class="table-responsive">
    <table class="table table-striped table-hover" id="tablaEstado">
        <thead class="table-dark">
            <tr>
                <th class="col-empleado">Empleado <span></span></th>
                <th class="col-cargo">Cargo <span></span></th>
                <th class="col-sede">Sede <span></span></th>
                <th class="col-trabajo">Trabajo <span></span></th>
                <th class="col-inactivo">Inactivo <span></span></th>
                <th class="col-ubicacion">Ubicación <span></span></th>
                <th class="col-estado">Estado <span></span></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($resultados as $row): ?>
            <tr>
                <td class="col-empleado">
                    <strong><?= htmlspecialchars($row['full_name']) ?></strong>
                    <?php /*
                    <?php if ($_SESSION['user_role'] == 'administrador' || $_SESSION['user_role'] == 'it' || $_SESSION['user_role'] == 'talento_humano'): ?>
                    <br>
                    <small>
                        <a href="usuarios.php?busqueda=<?= urlencode($row['full_name']) ?>" 
                           class="text-decoration-none" 
                           title="Ver en gestión de usuarios">
                            <i class="bi bi-person-gear"></i> Gestionar
                        </a>
                    </small>
                    <?php endif; ?>
                    */ ?>
                </td>
                <td class="col-cargo"><?= htmlspecialchars($row['position'] ?? 'N/A') ?></td>
                <td class="col-sede"><?= htmlspecialchars($row['sede_nombre'] ?? 'N/A') ?></td>
                <td class="col-trabajo"><?= $row['worktime'] ?? '—' ?></td>
                <td class="col-inactivo"><?= $row['outtime'] ?? '—' ?></td>
                <td class="col-ubicacion"><?= $row['geo'] ? htmlspecialchars($row['geo']) : '—' ?></td>
                <td class="col-estado">
                    <?php echo iconoEstado($row['last_status']); ?>
                    <span <?= colorEstado($row['last_status']) ?>>
                        <?= htmlspecialchars($row['last_status']) ?>
                    </span>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="alert alert-info">
    <i class="bi bi-info-circle"></i> No se encontraron empleados que coincidan con los criterios de búsqueda.
</div>
<?php endif; ?>