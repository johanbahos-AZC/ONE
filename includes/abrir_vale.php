<?php
require_once 'functions.php';

// Iniciar sesión para acceder a las variables si es necesario
session_start();

if (isset($_GET['hash'])) {
    $functions = new Functions();
    $result = $functions->abrirValePrestamo($_GET['hash']);
    
    // Si hay un error, mostrar mensaje de error
    if (!$result) {
        echo "<html><body><script>
            alert('No se pudo abrir el vale de préstamo. El archivo no existe o está dañado.');
            window.close();
        </script></body></html>";
        exit();
    }
} else {
    echo "<html><body><script>
        alert('Hash del vale no proporcionado');
        window.close();
    </script></body></html>";
    exit();
}
?>