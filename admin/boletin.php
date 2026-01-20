<?php
require_once '../includes/auth.php';
require_once '../includes/validar_permiso.php';

 $auth = new Auth();
 $auth->redirectIfNotLoggedIn();

// Verificar permisos para ver esta página
 $database = new Database();
 $conn = $database->getConnection();

// Obtener información del usuario actual
 $query_usuario = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name 
                 FROM employee e 
                 LEFT JOIN sedes s ON e.sede_id = s.id
                 LEFT JOIN firm f ON e.id_firm = f.id 
                 WHERE e.id = ?";
 $stmt_usuario = $conn->prepare($query_usuario);
 $stmt_usuario->execute([$_SESSION['user_id']]);
 $usuario_actual = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

// Verificar permiso para ver la página de usuarios
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'boletin', 'ver')) {
    header("Location: portal.php");
    exit();
}

// Obtenemos la URL de la noticia desde la barra de direcciones (parámetro GET)
// Ejemplo: boletin.php?url=https://boletin.azclegal.com/wordpress/mi-noticia/
 $noticia_url = isset($_GET['url']) ? $_GET['url'] : 'https://newsletter.azclegal.com/wordpress/';

// Es CRUCIAL sanitizar la URL para evitar ataques XSS
// Aunque la URL viene de nuestra propia API, es una buena práctica no fiarse nunca de la entrada del usuario.
 $noticia_url_segura = htmlspecialchars($noticia_url, ENT_QUOTES, 'UTF-8');

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Boletín Informativo - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
        /* Estilos para que el iframe ocupe todo el espacio disponible */
        body, html {
            margin: 0;
            padding: 0;
            height: 100%;
            overflow: hidden; /* Evita doble scroll */
        }
        .boletin-header {
            background-color: var(--color-primary);
            color: white;
            padding: 10px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .boletin-header a {
            color: white;
            text-decoration: none;
            font-size: 1.2rem;
        }
        .boletin-header a:hover {
            text-decoration: underline;
        }
        .iframe-container {
            width: 100%;
            height: calc(100vh - 60px); /* 100% de la altura de la vista menos la cabecera */
            border: none;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="main-grid">
            <!-- Sidebar -->
            <?php include '../includes/sidebar.php'; ?>
            
            <!-- Contenido principal -->
            <main class="boletin-content-wrapper">
            <!-- Navegación para volver al portal -->
                <div class="boletin-nav">
                    <a href="boletin.php" class="btn btn-outline-primary">
                        <i class="bi bi-house-fill"></i> Volver al Inicio
                    </a>
                    <span class="ms-3 align-middle fw-bold text-muted">Boletín Informativo</span>
                </div>
                
                <!-- Contenedor donde se cargará el iframe con el boletín -->
                <div class="iframe-container">
                    <iframe src="<?php echo $noticia_url_segura; ?>" class="iframe-container" title="Contenido del Boletín"></iframe>
                </div>
            </main>
        </div>
    </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <!-- No se necesitan scripts adicionales aquí -->
</body>
</html>