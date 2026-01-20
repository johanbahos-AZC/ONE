<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['file_hash'])) {
    $file_hash = $_POST['file_hash'];
    $user_id = $_SESSION['user_id'];
    
    $functions = new Functions();
    $result = $functions->eliminarArchivo($file_hash, $user_id);
    
    header('Content-Type: application/json');
    echo json_encode($result);
} else {
    echo json_encode([
        'success' => false,
        'error' => 'Solicitud inválida'
    ]);
}
?>