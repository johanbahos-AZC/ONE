<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

 $auth = new Auth();
 $auth->redirectIfNotLoggedIn();

// Solo administradores pueden ejecutar este script
if ($_SESSION['user_role'] != 'administrador') {
    header("Location: dashboard.php");
    exit();
}

 $database = new Database();
 $conn = $database->getConnection();

try {
    // Iniciar transacción
    $conn->beginTransaction();
    
    // Recursos del sistema basados en el sidebar actual
    $recursos = [
        ['portal', 'Portal de inicio', 'portal.php', 'bi-house-door', 'COMUNIDAD'],
        ['boletin', 'Boletín Informativo', 'boletin.php', 'bi-newspaper', 'COMUNIDAD'],
        ['dashboard', 'Dashboard', 'dashboard.php', 'bi-speedometer2', 'DASHBOARD'],
        ['tickets', 'Gestión de tickets', 'tickets.php', 'bi-ticket-perforated', 'SOLICITUDES'],
        ['permisos', 'Gestión de permisos', 'permisos.php', 'bi-clipboard-check', 'SOLICITUDES'],
        ['historial', 'Historial', 'historial.php', 'bi-clock-history', 'SOLICITUDES'],
        ['usuarios', 'Gestión de usuarios', 'usuarios.php', 'bi-people', 'GESTIÓN DE PERSONAL'],
        ['registros', 'Asistencia', 'registros.php', 'bi-calendar-check', 'GESTIÓN DE PERSONAL'],
        ['areas', 'Áreas', 'areas.php', 'bi-diagram-3', 'GESTIÓN DE PERSONAL'],
        ['keeper', 'Keeper', 'keeper_list.php', 'keeper_main2.png', 'GESTIÓN DE PERSONAL'],
        ['equipos', 'Equipos', 'equipos.php', 'bi-laptop', 'GESTIÓN DE ACTIVOS'],
        ['items', 'Ítems', 'items.php', 'bi-pc-display', 'GESTIÓN DE ACTIVOS'],
        ['categorias', 'Categorías', 'categorias.php', 'bi-box-seam', 'GESTIÓN DE ACTIVOS'],
        ['firmas', 'Firmas', 'firmas.php', 'bi-bank', 'RECURSOS EMPRESARIALES'],
        ['telefonos', 'Teléfonos IP', 'telefonos.php', 'bi-phone', 'RECURSOS EMPRESARIALES'],
        ['profile', 'Perfil', 'profile.php', 'bi-person', 'CUENTA']
    ];
    
    // Insertar recursos
    foreach ($recursos as $recurso) {
        $stmt = $conn->prepare("INSERT IGNORE INTO recursos (nombre, descripcion, ruta, icono, categoria) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute($recurso);
    }
    
    // Acciones comunes para todos los recursos
    $acciones_comunes = ['ver'];
    
    // Acciones específicas para ciertos recursos
    $acciones_especificas = [
        'portal' => ['crear_publicacion', 'eliminar_publicacion'],
    ];
    
    // Insertar acciones
    foreach ($recursos as $recurso_data) {
        $recurso_nombre = $recurso_data[0];
        
        // Obtener ID del recurso
        $stmt = $conn->prepare("SELECT id FROM recursos WHERE nombre = ?");
        $stmt->execute([$recurso_nombre]);
        $recurso_id = $stmt->fetch(PDO::FETCH_COLUMN);
        
        if ($recurso_id) {
            // Insertar acciones comunes
            foreach ($acciones_comunes as $accion_nombre) {
                $stmt = $conn->prepare("INSERT IGNORE INTO acciones (nombre, recurso_id) VALUES (?, ?)");
                $stmt->execute([$accion_nombre, $recurso_id]);
            }
            
            // Insertar acciones específicas si existen
            if (isset($acciones_especificas[$recurso_nombre])) {
                foreach ($acciones_especificas[$recurso_nombre] as $accion_nombre) {
                    $stmt = $conn->prepare("INSERT IGNORE INTO acciones (nombre, recurso_id) VALUES (?, ?)");
                    $stmt->execute([$accion_nombre, $recurso_id]);
                }
            }
        }
    }
    
    // Asignar permisos predeterminados por rol
    $permisos_roles = [
        'administrador' => 'all', // Todos los permisos
        'it' => [
            'portal' => ['ver'],
            'boletin' => ['ver'],
            'dashboard' => ['ver'],
            'tickets' => ['ver'],
            'usuarios' => ['ver'],
            'equipos' => ['ver'],
            'items' => ['ver'],
            'categorias' => ['ver'],
            'firmas' => ['ver'],
            'telefonos' => ['ver'],
            'profile' => ['ver']
        ],
        'nomina' => [
            'portal' => ['ver'],
            'boletin' => ['ver'],
            'dashboard' => ['ver'],
            'usuarios' => ['ver'],
            'registros' => ['ver'],
            'profile' => ['ver']
        ],
        'talento_humano' => [
            'portal' => ['ver'],
            'boletin' => ['ver'],
            'dashboard' => ['ver'],
            'usuarios' => ['ver'],
            'registros' => ['ver'],
            'areas' => ['ver'],
            'profile' => ['ver']
        ],
        'empleado' => [
            'portal' => ['ver'],
            'boletin' => ['ver'],
            'profile' => ['ver']
        ],
        'retirado' => [
            'profile' => ['ver']
        ]
    ];
    
    foreach ($permisos_roles as $rol => $recursos_permisos) {
        if ($recursos_permisos === 'all') {
            // Todos los permisos para administradores
            $stmt = $conn->prepare("
                INSERT IGNORE INTO permisos_roles (rol, recurso_id, accion_id, permitido)
                SELECT ?, r.id, a.id, TRUE
                FROM recursos r
                JOIN acciones a ON r.id = a.recurso_id
            ");
            $stmt->execute([$rol]);
        } else {
            // Permisos específicos para otros roles
            foreach ($recursos_permisos as $recurso_nombre => $acciones) {
                if ($acciones === 'all') {
                    // Todas las acciones para este recurso
                    $stmt = $conn->prepare("
                        INSERT IGNORE INTO permisos_roles (rol, recurso_id, accion_id, permitido)
                        SELECT ?, r.id, a.id, TRUE
                        FROM recursos r
                        JOIN acciones a ON r.id = a.recurso_id
                        WHERE r.nombre = ?
                    ");
                    $stmt->execute([$rol, $recurso_nombre]);
                } else {
                    // Acciones específicas
                    foreach ($acciones as $accion_nombre) {
                        $stmt = $conn->prepare("
                            INSERT IGNORE INTO permisos_roles (rol, recurso_id, accion_id, permitido)
                            SELECT ?, r.id, a.id, TRUE
                            FROM recursos r
                            JOIN acciones a ON r.id = a.recurso_id
                            WHERE r.nombre = ? AND a.nombre = ?
                        ");
                        $stmt->execute([$rol, $recurso_nombre, $accion_nombre]);
                    }
                }
            }
        }
    }
    
    // Confirmar transacción
    $conn->commit();
    
    $success = "Recursos y permisos iniciales creados correctamente";
    
} catch (Exception $e) {
    // Revertir transacción en caso de error
    $conn->rollBack();
    $error = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Poblar Recursos Iniciales - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Poblar Recursos Iniciales</h1>
                </div>
                
                <?php if (isset($error)): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <p>Los recursos y permisos iniciales han sido configurados correctamente.</p>
                        <p>Ahora puedes:</p>
                        <ul>
                            <li><a href="configurar_permisos.php">Configurar permisos por rol y cargo</a></li>
                            <li><a href="usuarios.php">Gestionar usuarios y sus permisos específicos</a></li>
                            <li><a href="dashboard.php">Ir al dashboard</a></li>
                        </ul>
                    </div>
                </div>
            </main>
        </div>
    </div>
</body>
</html>