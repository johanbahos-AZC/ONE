<?php
// Verificar si la sesión está iniciada
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Incluir las funciones globales
require_once '../includes/auth.php';
require_once '../includes/functions.php';

 $auth = new Auth();
 $functions = new Functions();

// Buscar el ID de usuario en diferentes variables de sesión posibles
 $user_id = null;
 $user_email = null;

 $possible_id_keys = ['admin_id', 'user_id', 'id', 'employee_id', 'user_data'];
 $possible_email_keys = ['admin_usuario', 'user_email', 'email', 'user_data'];

foreach ($possible_id_keys as $key) {
    if (isset($_SESSION[$key])) {
        $user_id = $_SESSION[$key];
        break;
    }
}

foreach ($possible_email_keys as $key) {
    if (isset($_SESSION[$key])) {
        $user_email = $_SESSION[$key];
        break;
    }
}

// Si no encontramos ID directo, buscar en user_data si existe
if (!$user_id && isset($_SESSION['user_data']) && is_array($_SESSION['user_data'])) {
    $user_data = $_SESSION['user_data'];
    if (isset($user_data['id'])) {
        $user_id = $user_data['id'];
    }
    if (isset($user_data['email'])) {
        $user_email = $user_data['email'];
    }
    if (isset($user_data['mail'])) {
        $user_email = $user_data['mail'];
    }
}

// Obtener información del usuario actual con position_id
 $usuario = null;
if ($user_id) {
    $database = new Database();
    $conn = $database->getConnection();
    
    // Modificar la consulta para incluir position_id y el nombre del cargo
    $query = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name, c.nombre as nombre_cargo
              FROM employee e 
              LEFT JOIN sedes s ON e.sede_id = s.id
              LEFT JOIN firm f ON e.id_firm = f.id 
              LEFT JOIN cargos c ON e.position_id = c.id
              WHERE e.id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $user_id);
    
    if ($stmt->execute()) {
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Si no se encontró por ID, intentar por email
if (!$usuario && $user_email) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name, c.nombre as nombre_cargo
              FROM employee e 
              LEFT JOIN sedes s ON e.sede_id = s.id
              LEFT JOIN firm f ON e.id_firm = f.id 
              LEFT JOIN cargos c ON e.position_id = c.id
              WHERE e.mail = :mail OR e.personal_mail = :mail";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':mail', $user_email);
    
    if ($stmt->execute()) {
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario) {
            // Actualizar la sesión con el ID correcto para futuras consultas
            $_SESSION['admin_id'] = $usuario['id'];
            $_SESSION['admin_usuario'] = $usuario['mail'];
        }
    }
}

// Usar la función global obtenerFotoUsuario
 $foto_header = $functions->obtenerFotoUsuario($usuario['photo'] ?? null);
 $user_position = $usuario['nombre_cargo'] ?? ''; // CAMBIO IMPORTANTE: Usar nombre_cargo en lugar de position
 $user_full_name = ($usuario['first_Name'] ?? 'Usuario') . ' ' . ($usuario['first_LastName'] ?? '');

// Obtener la ruta del logo
 $logo_path = '/assets/images/logo_white.png';
 $logo_full_path = $_SERVER['DOCUMENT_ROOT'] . $logo_path;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
     <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark fixed-top custom-navbar">
    <div class="container-fluid">
        <!-- Logo SIN BORDE NEGRO -->
        <div class="navbar-brand">
            <?php if (file_exists($logo_full_path)): ?>
                <img src="<?php echo $logo_path; ?>" 
                     alt="Logo" 
                     class="navbar-logo">
            <?php else: ?>
                <span class="navbar-brand-text">
                    <i class="bi bi-box-seam me-2"></i><?php echo SITE_NAME; ?>
                </span>
            <?php endif; ?>
        </div>

        <!-- Usuario compacto -->
        <div class="navbar-nav ms-auto">
            <div class="nav-item dropdown user-dropdown">
                <a class="nav-link dropdown-toggle user-info-wrapper p-2" href="#" role="button" data-bs-toggle="dropdown" data-bs-offset="10,20">
                    <div class="user-info-content">
                        <img src="<?php echo $foto_header; ?>" 
                             class="header-photo" 
                             alt="Foto de perfil"
                             onerror="this.src='/assets/images/default_avatar.png'">
                        <div class="user-text">
                            <div class="user-name"><?php echo htmlspecialchars($user_full_name); ?></div>
                            <?php if (!empty($user_position)): ?>
                            <div class="user-position"><?php echo htmlspecialchars($user_position); ?></div>
                            <?php endif; ?>
                        </div>
                        <i class="dropdown-arrow bi bi-chevron-down"></i>
                    </div>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <a class="dropdown-item" href="profile.php">
                            <i class="bi bi-person me-2"></i>Mi Perfil
                        </a>
                    </li>
                    <li><hr class="dropdown-divider my-1"></li>
                    <li>
                        <a class="dropdown-item" href="../logout.php">
                            <i class="bi bi-box-arrow-right me-2"></i>Cerrar Sesión
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- Espaciador para compensar el navbar fijo -->
<div style="height: 60px;"></div>

<style>
/* RESET COMPLETO PARA ELIMINAR BORDES NEGROS */
* {
    box-sizing: border-box;
}

.custom-navbar {
    background-color: #003a5d !important;
    min-height: 60px;
    height: 60px;
    padding: 0;
    border: none !important;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1) !important;
}

/* LOGO SIN BORDE NEGRO */
.navbar-brand {
    background: transparent !important;
    border: none !important;
    padding: 0;
    margin: 0;
}

.navbar-logo {
    height: 35px;
    width: auto;
    object-fit: contain;
    margin-left: 1rem;
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    background: transparent !important;
    display: block;
    margin-right: 0 !important;
    padding-right: 0 !important;
    border-right: none !important;
}

/* Contenedor compacto del usuario */
.user-dropdown {
    margin: 0;
}

.user-info-wrapper {
    padding: 0.25rem 0.5rem !important;
    margin: 0.25rem 0.5rem;
    border-radius: 20px;
    transition: all 0.2s ease;
    border: none !important;
    background: transparent !important;
    text-decoration: none !important;
}

.user-info-wrapper:hover {
    background-color: rgba(255, 255, 255, 0.15) !important;
    text-decoration: none !important;
}

/* ELIMINAR TRIÁNGULOS DEL DROPDOWN */
.dropdown-toggle::after {
    display: none !important;
    content: none !important;
}

.user-info-wrapper:focus {
    box-shadow: none !important;
    outline: none !important;
}

.user-info-content {
    display: flex;
    align-items: center;
    gap: 8px;
    color: white;
    line-height: 1;
    text-decoration: none !important;
}

/* IMAGEN SIN BORDES NEGROS - MÁS AGRESIVO */
.header-photo {
    width: 42px;
    height: 42px;
    object-fit: cover;
    border-radius: 50%;
    border: 2px solid rgba(255, 255, 255, 0.5) !important;
    background: transparent !important;
    box-shadow: none !important;
    outline: none !important;
    display: block;
    padding: 0 !important;
    margin: 0 !important;
}

/* ELIMINAR CUALQUIER BORDE EN IMÁGENES */
img {
    border-style: none !important;
    border-width: 0 !important;
    border-color: transparent !important;
}

.user-text {
    text-align: left;
    white-space: nowrap;
    text-decoration: none !important;
}

.user-name {
    font-size: 1.1rem;
    font-weight: 600;
    margin-bottom: 2;
    color: white;
    text-decoration: none !important;
}

.user-position {
    font-size: 0.75rem;
    opacity: 0.9;
    margin-top: 1px;
    color: rgba(255, 255, 255, 0.9);
    text-decoration: none !important;
}

.dropdown-arrow {
    font-size: 0.8rem;
    opacity: 0.8;
    margin-left: 2px;
    color: white;
}

/* Dropdown compacto SIN TRIÁNGULOS */
.dropdown-menu {
    min-width: 160px;
    border: none !important;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    border-radius: 6px;
    margin-top: 5px !important;
    font-size: 0.9rem;
}

/* ELIMINAR PSEUDO-ELEMENTOS QUE CREAN TRIÁNGULOS */
.dropdown-menu::before,
.dropdown-menu::after {
    display: none !important;
    content: none !important;
}

.dropdown-item {
    padding: 0.5rem 0.75rem;
    border: none !important;
    text-decoration: none !important;
}

.dropdown-item:hover {
    background-color: #3d5dee !important;
    color: white !important;
    text-decoration: none !important;
}

.dropdown-divider {
    margin: 0.2rem 0;
}

.navbar.fixed-top {
    position: fixed;
    top: 0;
    right: 0;
    left: 0;
    z-index: 1030;
}

/* ELIMINAR BORDES DEL TOGGLER */
.navbar-dark .navbar-toggler {
    border-color: rgba(255, 255, 255, 0.3) !important;
    box-shadow: none !important;
}

.navbar-dark .navbar-toggler-icon {
    background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 30 30'%3e%3cpath stroke='rgba%28255, 255, 255, 0.8%29' stroke-linecap='round' stroke-miterlimit='10' stroke-width='2' d='M4 7h22M4 15h22M4 23h22'/%3e%3c/svg%3e");
}

/* Responsive más compacto */
@media (max-width: 768px) {
    .custom-navbar {
        min-height: 55px;
        height: 55px;
    }
    
    .navbar-logo {
        height: 30px;
        margin-left: 0.5rem;
    }
    
    .user-text {
        display: none;
    }
    
    .dropdown-arrow {
        display: none;
    }
    
    .user-info-wrapper {
        padding: 0.4rem !important;
        margin: 0.2rem;
    }
    
    .header-photo {
        width: 28px;
        height: 28px;
    }
    
    /* Espaciador más pequeño en móvil */
    div[style*="height: 60px"] {
        height: 55px !important;
    }
}

/* Asegurar que no haya espacios extra */
.container-fluid {
    padding: 0 0.5rem;
}

.navbar > .container-fluid {
    align-items: center;
}

/* ELIMINAR CUALQUIER EFECTO DE FOCUS NO DESEADO */
.navbar-toggler:focus {
    box-shadow: none !important;
    outline: none !important;
}

/* ELIMINAR BORDES EN TODOS LOS ELEMENTOS */
.btn:focus,
.form-control:focus,
.navbar-brand:focus,
.nav-link:focus {
    box-shadow: none !important;
    outline: none !important;
    border: none !important;
}

/* FORZAR QUE NO HAYA BORDES EN ABSOLUTO */
.navbar,
.navbar * {
    border-color: transparent !important;
}

.navbar-brand img {
    border: none !important;
    outline: none !important;
    box-shadow: none !important;
    background: transparent !important;
    padding: 0 !important;
    margin: 0 !important;
}
</style>

<script>
// Script para eliminar cualquier borde residual
document.addEventListener('DOMContentLoaded', function() {
    // Eliminar bordes de todas las imágenes
    const images = document.querySelectorAll('img');
    images.forEach(img => {
        img.style.border = 'none';
        img.style.outline = 'none';
        img.style.boxShadow = 'none';
        img.style.background = 'transparent';
    });
    
    // Eliminar cualquier estilo de borde del navbar
    const navbar = document.querySelector('.navbar');
    if (navbar) {
        navbar.style.border = 'none';
        navbar.style.outline = 'none';
    }
    
    // Eliminar bordes del logo específicamente
    const logo = document.querySelector('.navbar-logo');
    if (logo) {
        logo.style.border = 'none';
        logo.style.outline = 'none';
        logo.style.boxShadow = 'none';
    }
});
</script>