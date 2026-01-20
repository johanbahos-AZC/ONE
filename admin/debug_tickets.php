<?php
require_once 'includes/database.php';

$database = new Database();
$conn = $database->getConnection();

// Verificar estructura de la tabla
$stmt = $conn->query("DESCRIBE tickets");
$campos = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Estructura de la tabla tickets:</h3>";
echo "<pre>";
print_r($campos);
echo "</pre>";

// Verificar datos de tickets
$stmt = $conn->query("SELECT id, estado, tipo, creado_en FROM tickets ORDER BY id DESC LIMIT 5");
$tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>Últimos 5 tickets:</h3>";
echo "<pre>";
print_r($tickets);
echo "</pre>";
?>