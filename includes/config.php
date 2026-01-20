<?php
// Configuración de la base de datos
define('DB_HOST', 'mysql.server1872.mylogin.co');
define('DB_NAME', 'pipezafra_soporte_db');
define('DB_USER', 'pipezafra_soporte_db'); 
define('DB_PASS', 'z8912618Z@!$'); 

// Configuración de la API de empleados
define('EMPLEADOS_API_URL', 'https://tu-api-externa.com/empleados/');

// Configuración de la aplicación
define('SITE_NAME', 'AZC one');
define('SITE_URL', 'https://soporte.azclegal.com/'); 
define('SUPPORT_EMAIL', 'soporte@azclegal.com');


// Iniciar sesión
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
?>