<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

$functions = new Functions();

// Procesar correos entrantes y crear tickets
$resultado = $functions->procesarCorreosEntrantes();

if ($resultado['success']) {
    $_SESSION['success'] = "Se procesaron " . $resultado['procesados'] . " correos correctamente";
} else {
    $_SESSION['error'] = "Error al procesar correos: " . $resultado['error'];
}

header("Location: tickets.php");
exit();