<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

$database = new Database();
$conn = $database->getConnection();

// Manejar fechas desde/hasta
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';
$ventana = $_GET['ventana'] ?? '';

$where = '';
$params = [];

if ($desde && $hasta) {
    $where = "WHERE W.date_Window BETWEEN :desde AND :hasta";
    $params[':desde'] = $desde . ' 00:00:00';
    $params[':hasta'] = $hasta . ' 23:59:59';
} elseif ($desde) {
    $where = "WHERE W.date_Window >= :desde";
    $params[':desde'] = $desde . ' 00:00:00';
} elseif ($hasta) {
    $where = "WHERE W.date_Window <= :hasta";
    $params[':hasta'] = $hasta . ' 23:59:59';
}

$sql = "
    SELECT 
        W.window_name, 
        SEC_TO_TIME(SUM(TIME_TO_SEC(W.timer))) AS total_time
    FROM WINDOWS W
    $where
    GROUP BY W.window_name
    ORDER BY total_time DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$resultados = $stmt->fetchAll();

$empleados = [];
if ($ventana) {
    $sqlEmpleados = "
        SELECT 
            e.first_Name,
            SEC_TO_TIME(SUM(TIME_TO_SEC(W.timer))) AS tiempo_total
        FROM WINDOWS W
        INNER JOIN employee e ON W.id_login = e.id
        WHERE W.window_name = :ventana
        " . ($where ? " AND " . substr($where, 6) : "") . "
        GROUP BY e.id, e.first_Name
        ORDER BY tiempo_total DESC
    ";

    $paramsEmpleado = array_merge([':ventana' => $ventana], $params);
    $stmtEmp = $conn->prepare($sqlEmpleados);
    $stmtEmp->execute($paramsEmpleado);
    $empleados = $stmtEmp->fetchAll();
}
?>

<style>
    .tabla-contenedor {
        overflow-x: auto;
        max-width: 100%;
    }
    table {
        border-collapse: collapse;
        width: 100%;
        min-width: 600px;
    }
    th, td {
        padding: 8px;
        border: 1px solid #ccc;
        text-align: left;
    }
    th {
        background-color: #f0f0f0;
    }
</style>

<h2>Resumen por Ventana</h2>

<?php if ($ventana): ?>
    <h3>Empleados que usaron: "<?= htmlspecialchars($ventana) ?>"</h3>
    <div class="tabla-contenedor" style="margin-bottom: 30px;">
        <table>
            <thead>
                <tr>
                    <th>Empleado</th>
                    <th>Tiempo total en ventana</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($empleados as $emp): ?>
                <tr>
                    <td><?= htmlspecialchars($emp['first_Name']) ?></td>
                    <td><?= $emp['tiempo_total'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<!-- Filtro de fechas -->
<form method="get" style="margin-bottom: 20px;">
    <label>Desde: <input type="date" name="desde" value="<?= htmlspecialchars($desde) ?>"></label>
    <label>Hasta: <input type="date" name="hasta" value="<?= htmlspecialchars($hasta) ?>"></label>
    <button type="submit">Filtrar</button>
    <?php if ($desde || $hasta): ?>
        <a href="window.php" style="margin-left: 10px;">Limpiar filtro</a>
    <?php endif; ?>
</form>

<div class="tabla-contenedor">
    <table>
        <thead>
            <tr>
                <th>Ventana</th>
                <th>Tiempo total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($resultados as $row): ?>
            <tr>
                <td title="<?= htmlspecialchars($row['window_name']) ?>">
                    <a href="?ventana=<?= urlencode($row['window_name']) ?>&desde=<?= urlencode($desde) ?>&hasta=<?= urlencode($hasta) ?>">
                        <?= htmlspecialchars(mb_strimwidth($row['window_name'], 0, 120, '...')) ?>
                    </a>
                </td>
                <td><?= $row['total_time'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>