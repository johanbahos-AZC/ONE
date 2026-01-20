<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

$database = new Database();
$conn = $database->getConnection();

$window = $_GET['window'] ?? '';
$desde = $_GET['desde'] ?? '';
$hasta = $_GET['hasta'] ?? '';

$where = "WHERE W.window_name = :window";
$params = [':window' => $window];

if ($desde && $hasta) {
    $where .= " AND W.date_Window BETWEEN :desde AND :hasta";
    $params[':desde'] = $desde . ' 00:00:00';
    $params[':hasta'] = $hasta . ' 23:59:59';
} elseif ($desde) {
    $where .= " AND W.date_Window >= :desde";
    $params[':desde'] = $desde . ' 00:00:00';
} elseif ($hasta) {
    $where .= " AND W.date_Window <= :hasta";
    $params[':hasta'] = $hasta . ' 23:59:59';
}

$sql = "
    SELECT 
        CONCAT_WS(' ', e.first_Name, e.first_LastName) AS user_name,
        SEC_TO_TIME(SUM(TIME_TO_SEC(W.timer))) AS tiempo_usuario
    FROM WINDOWS W
    INNER JOIN employee e ON W.id_login = e.id
    $where
    GROUP BY e.id, user_name
    ORDER BY tiempo_usuario DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($usuarios);