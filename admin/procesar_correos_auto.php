<?php
/**
 * Procesamiento automático de correos 
 */

// Cambiar al directorio correcto
chdir(dirname(__FILE__));

// Incluir archivos necesarios
require_once '../includes/database.php';
require_once '../includes/functions.php';

try {   
    $functions = new Functions();
    $resultado = $functions->procesarCorreosEntrantes();
    
    exit(0);
    
} catch (Exception $e) {
    exit(1);
}
?>