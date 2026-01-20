<?php
// includes/debug.php

function debug_log($message, $file = 'debug.log') {
    $timestamp = date('Y-m-d H:i:s');
    $log_message = "[$timestamp] $message\n";
    file_put_contents($file, $log_message, FILE_APPEND | LOCK_EX);
}

// Función para mostrar debug en pantalla (solo en desarrollo)
function debug_display($message) {
    if (isset($_GET['debug'])) {
        echo "<div style='background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 5px;'>DEBUG: $message</div>";
    }
}

// Función para debug que retorna contenido para JSON
function debug_json($message) {
    debug_log($message);
    return $message;
}
?>