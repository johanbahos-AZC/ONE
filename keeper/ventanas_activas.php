<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

$database = new Database();
$conn = $database->getConnection();

$porPagina = 10;
$pagina = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$pagina = max(1, $pagina);
$inicio = ($pagina - 1) * $porPagina;

$buscar = $_GET['buscar'] ?? '';
$filtro = "%$buscar%";

// Total de registros
$stmtTotal = $conn->prepare("
    SELECT COUNT(*) FROM WINDOWS W
    INNER JOIN employee e ON W.id_login = e.id
    WHERE e.first_Name LIKE :filtro1 OR W.window_name LIKE :filtro2
");
$stmtTotal->bindValue(':filtro1', $filtro, PDO::PARAM_STR);
$stmtTotal->bindValue(':filtro2', $filtro, PDO::PARAM_STR);
$stmtTotal->execute();

$totalRegistros = $stmtTotal->fetchColumn();
$totalPaginas = ceil($totalRegistros / $porPagina);

// Registros paginados
$stmt = $conn->prepare("
    SELECT W.id, e.first_Name, W.window_name, W.timer, W.date_Window
    FROM WINDOWS W
    INNER JOIN employee e ON W.id_login = e.id
    WHERE e.first_Name LIKE :filtro1 OR W.window_name LIKE :filtro2
    ORDER BY W.id DESC
    LIMIT :inicio, :porPagina
");
$stmt->bindValue(':filtro1', $filtro, PDO::PARAM_STR);
$stmt->bindValue(':filtro2', $filtro, PDO::PARAM_STR);
$stmt->bindValue(':inicio', $inicio, PDO::PARAM_INT);
$stmt->bindValue(':porPagina', $porPagina, PDO::PARAM_INT);
$stmt->execute();

$registros = $stmt->fetchAll();

// HTML de tabla
echo '<table id="tabla-ventanas">
<tr>
    <th>ID</th>
    <th>Empleado</th>
    <th>Nombre de ventana</th>
    <th>Tiempo</th>
    <th>Fecha</th>
</tr>';

foreach ($registros as $row) {
    echo "<tr>
        <td>{$row['id']}</td>
        <td>" . htmlspecialchars($row['first_Name']) . "</td>
        <td>" . htmlspecialchars($row['window_name']) . "</td>
        <td>{$row['timer']}</td>
        <td>{$row['date_Window']}</td>
    </tr>";
}
echo '</table>';

// HTML de paginación
echo '<div class="paginacion">';
for ($i = 1; $i <= $totalPaginas; $i++) {
    $active = ($i == $pagina) ? 'class="actual"' : '';
    echo "<a href='#' $active onclick='cargarVentanas($i)'>$i</a>";
}
echo '</div>';