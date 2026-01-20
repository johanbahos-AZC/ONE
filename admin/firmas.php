<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/validar_permiso.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

$functions = new Functions();
$error = '';
$success = '';

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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'firmas', 'ver')) {
    header("Location: portal.php");
    exit();
}

// Procesar creación de firma
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_firma'])) {
    $nombre = trim($_POST['nombre']);
    $manager = trim($_POST['manager']);
    $mail_manager = trim($_POST['mail_manager']);
    
    if (!empty($nombre)) {
        try {
            $query = "INSERT INTO firm (name, manager, mail_manager, created_at) 
                      VALUES (:nombre, :manager, :mail_manager, NOW())";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':manager', $manager);
            $stmt->bindParam(':mail_manager', $mail_manager);
            
            if ($stmt->execute()) {
                $success = "Firma creada correctamente";
            } else {
                $error = "Error al crear la firma";
            }
        } catch (PDOException $e) {
            $error = "Error en la base de datos: " . $e->getMessage();
        }
    } else {
        $error = "El nombre de la firma es obligatorio";
    }
}

// Procesar creación de locación
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_locacion'])) {
    $id_firm = $_POST['id_firm'];
    $nombre = trim($_POST['nombre_locacion']);
    $direccion = trim($_POST['direccion_locacion']);
    $ciudad = trim($_POST['ciudad_locacion']);
    
    if (!empty($id_firm) && !empty($nombre)) {
        try {
            $query = "INSERT INTO location (id_firm, name, address, city) 
                      VALUES (:id_firm, :nombre, :direccion, :ciudad)";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id_firm', $id_firm);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':direccion', $direccion);
            $stmt->bindParam(':ciudad', $ciudad);
            
            if ($stmt->execute()) {
                $success = "Locación creada correctamente";
            } else {
                $error = "Error al crear la locación";
            }
        } catch (PDOException $e) {
            $error = "Error en la base de datos: " . $e->getMessage();
        }
    } else {
        $error = "La firma y el nombre de la locación son obligatorios";
    }
}

// Procesar edición de firma
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editar_firma'])) {
    $firma_id = $_POST['firma_id'];
    $nombre = trim($_POST['nombre']);
    $manager = trim($_POST['manager']);
    $mail_manager = trim($_POST['mail_manager']);
    
    if (!empty($nombre)) {
        try {
            $query = "UPDATE firm SET name = :nombre, manager = :manager, mail_manager = :mail_manager, updated_at = NOW() WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':manager', $manager);
            $stmt->bindParam(':mail_manager', $mail_manager);
            $stmt->bindParam(':id', $firma_id);
            
            if ($stmt->execute()) {
                $success = "Firma actualizada correctamente";
                // Recargar la página para ver los cambios
                echo "<script>window.location.href = 'firmas.php';</script>";
                exit();
            } else {
                $error = "Error al actualizar la firma";
            }
        } catch (PDOException $e) {
            $error = "Error en la base de datos: " . $e->getMessage();
        }
    } else {
        $error = "El nombre de la firma es obligatorio";
    }
}

// Procesar edición de locación
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['editar_locacion'])) {
    $locacion_id = $_POST['locacion_id'];
    $nombre = trim($_POST['nombre_locacion']);
    $direccion = trim($_POST['direccion_locacion']);
    $ciudad = trim($_POST['ciudad_locacion']);
    
    if (!empty($nombre)) {
        try {
            $query = "UPDATE location SET name = :nombre, address = :direccion, city = :ciudad, updated_at = NOW() WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nombre', $nombre);
            $stmt->bindParam(':direccion', $direccion);
            $stmt->bindParam(':ciudad', $ciudad);
            $stmt->bindParam(':id', $locacion_id);
            
            if ($stmt->execute()) {
                $success = "Locación actualizada correctamente";
                // Recargar la página para ver los cambios
                echo "<script>window.location.href = 'firmas.php';</script>";
                exit();
            } else {
                $error = "Error al actualizar la locación";
            }
        } catch (PDOException $e) {
            $error = "Error en la base de datos: " . $e->getMessage();
        }
    } else {
        $error = "El nombre de la locación es obligatorio";
    }
}

// Procesar eliminación de firma - CÓDIGO MEJORADO
if (isset($_GET['eliminar_firma'])) {
    $id = $_GET['eliminar_firma'];
    
    debug_log("=== INICIO ELIMINACIÓN FIRMA ID: $id ===");
    
    try {
        $conn->beginTransaction();
        
        // 1. Verificar si la firma tiene locaciones asociadas
        $query_locaciones = "SELECT COUNT(*) as count FROM location WHERE id_firm = :id";
        $stmt_locaciones = $conn->prepare($query_locaciones);
        $stmt_locaciones->bindParam(':id', $id);
        $stmt_locaciones->execute();
        $resultado_locaciones = $stmt_locaciones->fetch(PDO::FETCH_ASSOC);
        
        debug_log("Locaciones de firma $id: " . $resultado_locaciones['count']);
        
        // 2. Verificar si la firma tiene empleados asociados
        $query_empleados = "SELECT COUNT(*) as count FROM employee WHERE id_firm = :id";
        $stmt_empleados = $conn->prepare($query_empleados);
        $stmt_empleados->bindParam(':id', $id);
        $stmt_empleados->execute();
        $resultado_empleados = $stmt_empleados->fetch(PDO::FETCH_ASSOC);
        
        debug_log("Empleados de firma $id: " . $resultado_empleados['count']);
        
        // 3. Verificar si la firma tiene teléfonos asociados
        $query_telefonos = "SELECT COUNT(*) as count FROM phones WHERE id_firm = :id";
        $stmt_telefonos = $conn->prepare($query_telefonos);
        $stmt_telefonos->bindParam(':id', $id);
        $stmt_telefonos->execute();
        $resultado_telefonos = $stmt_telefonos->fetch(PDO::FETCH_ASSOC);
        
        debug_log("Teléfonos de firma $id: " . $resultado_telefonos['count']);
        
        // Verificar restricciones
        $hasDependencies = false;
        $errorMessage = '';
        
        if ($resultado_locaciones['count'] > 0) {
            $hasDependencies = true;
            $errorMessage = "No se puede eliminar la firma porque tiene {$resultado_locaciones['count']} locaciones asociadas";
        } elseif ($resultado_empleados['count'] > 0) {
            $hasDependencies = true;
            $errorMessage = "No se puede eliminar la firma porque tiene {$resultado_empleados['count']} empleados asociados";
        } elseif ($resultado_telefonos['count'] > 0) {
            $hasDependencies = true;
            $errorMessage = "No se puede eliminar la firma porque tiene {$resultado_telefonos['count']} teléfonos asociados";
        }
        
        if ($hasDependencies) {
            debug_log($errorMessage);
            $conn->rollBack();
            $_SESSION['error'] = $errorMessage;
            header("Location: firmas.php");
            exit();
        } else {
            debug_log("No hay restricciones, procediendo a eliminar firma $id");
            
            $query = "DELETE FROM firm WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $rowsAffected = $stmt->rowCount();
                debug_log("Filas afectadas: $rowsAffected");
                
                if ($rowsAffected > 0) {
                    $conn->commit();
                    $_SESSION['success'] = "Firma eliminada correctamente";
                    debug_log("Firma eliminada exitosamente");
                } else {
                    $conn->rollBack();
                    $_SESSION['error'] = "No se encontró la firma para eliminar";
                    debug_log("No se encontró la firma con ID: $id");
                }
                
                header("Location: firmas.php");
                exit();
            } else {
                $errorInfo = $stmt->errorInfo();
                $errorMessage = "Error al eliminar la firma: " . $errorInfo[2];
                debug_log($errorMessage);
                $conn->rollBack();
                $_SESSION['error'] = $errorMessage;
                header("Location: firmas.php");
                exit();
            }
        }
    } catch (PDOException $e) {
        $conn->rollBack();
        $errorMessage = "Error en la base de datos: " . $e->getMessage();
        debug_log("EXCEPCIÓN: " . $e->getMessage());
        $_SESSION['error'] = $errorMessage;
        header("Location: firmas.php");
        exit();
    }
    
    debug_log("=== FIN ELIMINACIÓN FIRMA ID: $id ===");
}

// Procesar eliminación de locación
if (isset($_GET['eliminar_locacion'])) {
    $id = $_GET['eliminar_locacion'];
    
    try {
        // NO hay verificación de dependencias ya que no hay relación directa con employees
        $query = "DELETE FROM location WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            $success = "Locación eliminada correctamente";
            // Recargar para ver cambios
            echo "<script>window.location.href = 'firmas.php';</script>";
            exit();
        } else {
            $error = "Error al eliminar la locación";
        }
    } catch (PDOException $e) {
        $error = "Error en la base de datos: " . $e->getMessage();
    }
}

// Obtener todas las firmas con sus locaciones
$query_firmas = "SELECT f.*, 
                (SELECT COUNT(*) FROM location l WHERE l.id_firm = f.id) as total_locaciones
                FROM firm f 
                ORDER BY f.name";
$stmt_firmas = $conn->prepare($query_firmas);
$stmt_firmas->execute();
$firmas = $stmt_firmas->fetchAll(PDO::FETCH_ASSOC);

// Obtener locaciones para cada firma
foreach ($firmas as &$firma) {
    $query_locaciones = "SELECT * FROM location WHERE id_firm = :id_firm ORDER BY name";
    $stmt_locaciones = $conn->prepare($query_locaciones);
    $stmt_locaciones->bindParam(':id_firm', $firma['id']);
    $stmt_locaciones->execute();
    $firma['locaciones'] = $stmt_locaciones->fetchAll(PDO::FETCH_ASSOC);
}
unset($firma); // Romper la referencia
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Firmas - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
        .card {
            margin-bottom: 20px;
        }
        .table-responsive {
            min-height: 300px;
        }
        .actions-column {
            white-space: nowrap;
        }
        .firma-row {
            cursor: pointer;
            transition: background-color 0.2s;
        }
        .firma-row:hover {
            background-color: #f8f9fa;
        }
        .locaciones-table {
            background-color: #f8f9fa;
        }
        .locaciones-table tr:hover {
            background-color: #e9ecef;
        }
        .accordion-button:not(.collapsed) {
            background-color: #e7f1ff;
            color: #0c63e4;
        }
        /* ESTILOS ESPECÍFICOS PARA BOTONES Y BADGES CON COLORES DEL ECOSISTEMA */
    
    /* BOTONES */
    .btn-primary {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
    }
    
    .btn-primary:hover {
        background-color: #002b47 !important;
        border-color: #002b47 !important;
    }
    
    .btn-success {
        background-color: #198754 !important;
        border-color: #198754 !important;
    }
    
    .btn-success:hover {
        background-color: #157347 !important;
        border-color: #146c43 !important;
    }
    
    .btn-danger {
        background-color: #be1622 !important;
        border-color: #be1622 !important;
    }
    
    .btn-danger:hover {
        background-color: #a0121d !important;
        border-color: #a0121d !important;
    }
    
    .btn-warning {
        background-color: #ffc107 !important;
        border-color: #ffc107 !important;
        color: #353132 !important;
    }
    
    .btn-warning:hover {
        background-color: #ffca2c !important;
        border-color: #ffc720 !important;
        color: #353132 !important;
    }
    
    .btn-outline-primary {
        border-color: #003a5d !important;
        color: #003a5d !important;
    }
    
    .btn-outline-primary:hover {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
        color: white !important;
    }
    
    .btn-outline-success {
        border-color: #198754 !important;
        color: #198754 !important;
    }
    
    .btn-outline-success:hover {
        background-color: #198754 !important;
        border-color: #198754 !important;
        color: white !important;
    }
    
    .btn-outline-danger {
        border-color: #be1622 !important;
        color: #be1622 !important;
    }
    
    .btn-outline-danger:hover {
        background-color: #be1622 !important;
        border-color: #be1622 !important;
        color: white !important;
    }
    
    /* BADGES */
    .badge.bg-success {
        background-color: #198754 !important;
    }
    
    .badge.bg-danger {
        background-color: #be1622 !important;
    }
    
    .badge.bg-primary {
        background-color: #003a5d !important;
    }
    
    .badge.bg-warning {
        background-color: #ffc107 !important;
        color: #353132 !important;
    }
    
    .badge.bg-info {
        background-color: #003a5d !important;
        opacity: 0.8;
    }
    
    .badge.bg-secondary {
        background-color: #6c757d !important;
    }
    
    /* HOVER EN FILAS DE LA TABLA */
    .table-hover tbody tr:hover {
        background-color: #33617e !important;
        color: white !important;
    }
    
    /* Estilos específicos para esta página */
    .card {
        margin-bottom: 20px;
    }
    .table-responsive {
        min-height: 300px;
    }
    .actions-column {
        white-space: nowrap;
    }
    .firma-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .firma-row:hover {
        background-color: #f8f9fa;
    }
    .locaciones-table {
        background-color: #f8f9fa;
    }
    .locaciones-table tr:hover {
        background-color: #e9ecef;
    }
    .accordion-button:not(.collapsed) {
        background-color: #e7f1ff;
        color: #0c63e4;
    }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Firmas</h1>
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <div class="row">
                    <!-- Formulario para crear firma -->
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header" style="background-color: #e9ecef;">
                                <h5 class="card-title">Crear Nueva Firma</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="nombre" class="form-label">Nombre de la Firma *</label>
                                        <input type="text" class="form-control" id="nombre" name="nombre" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="manager" class="form-label">Gerente/Responsable</label>
                                        <input type="text" class="form-control" id="manager" name="manager">
                                    </div>
                                    <div class="mb-3">
                                        <label for="mail_manager" class="form-label">Correo del Gerente</label>
                                        <input type="email" class="form-control" id="mail_manager" name="mail_manager">
                                    </div>
                                    <button type="submit" name="crear_firma" class="btn btn-primary">Crear Firma</button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Formulario para crear locación -->
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header" style="background-color: #e9ecef;">
                                <h5 class="card-title">Crear Nueva Locación</h5>
                            </div>
                            <div class="card-body">
                                <form method="POST" action="">
                                    <div class="mb-3">
                                        <label for="id_firm" class="form-label">Firma *</label>
                                        <select class="form-select" id="id_firm" name="id_firm" required>
                                            <option value="">Seleccione una firma</option>
                                            <?php foreach ($firmas as $firma): ?>
                                            <option value="<?php echo $firma['id']; ?>"><?php echo htmlspecialchars($firma['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="nombre_locacion" class="form-label">Nombre de la Locación *</label>
                                        <input type="text" class="form-control" id="nombre_locacion" name="nombre_locacion" required>
                                    </div>
                                    <div class="mb-3">
                                        <label for="direccion_locacion" class="form-label">Dirección</label>
                                        <textarea class="form-control" id="direccion_locacion" name="direccion_locacion" rows="2"></textarea>
                                    </div>
                                    <div class="mb-3">
                                        <label for="ciudad_locacion" class="form-label">Ciudad</label>
                                        <input type="text" class="form-control" id="ciudad_locacion" name="ciudad_locacion">
                                    </div>
                                    <button type="submit" name="crear_locacion" class="btn btn-primary">Crear Locación</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Lista de firmas con locaciones -->
                <div class="card">
                    <div class="card-header" style="background-color: #e9ecef;">
                        <h5 class="card-title">Firmas Existentes y sus Locaciones</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Nombre</th>
                                        <th>Gerente</th>
                                        <th>Correo Gerente</th>
                                        <th>Locaciones</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($firmas) > 0): ?>
                                        <?php foreach ($firmas as $firma): ?>
                                        <tr class="firma-row" data-bs-toggle="collapse" data-bs-target="#locaciones-<?php echo $firma['id']; ?>" aria-expanded="false">
                                            <td><?php echo $firma['id']; ?></td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($firma['name']); ?></strong>
                                            </td>
                                            <td><?php echo htmlspecialchars($firma['manager'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($firma['mail_manager'] ?? 'N/A'); ?></td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $firma['total_locaciones']; ?> locaciones</span>
                                            </td>
                                            <td class="actions-column">
    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditarFirma<?php echo $firma['id']; ?>">
        <i class="bi bi-pencil"></i> Editar
    </button>
    <button class="btn btn-sm btn-outline-danger btn-eliminar-firma" 
            data-firma-id="<?php echo $firma['id']; ?>"
            data-firma-nombre="<?php echo htmlspecialchars($firma['name']); ?>">
        <i class="bi bi-trash"></i> Eliminar
    </button>
</td>
                                        </tr>
                                        <tr class="collapse" id="locaciones-<?php echo $firma['id']; ?>">
                                            <td colspan="6">
                                                <div class="p-3">
                                                    <h6>Locaciones de <?php echo htmlspecialchars($firma['name']); ?></h6>
                                                    <?php if (!empty($firma['locaciones'])): ?>
                                                        <table class="table table-sm locaciones-table">
                                                            <thead>
                                                                <tr>
                                                                    <th>ID</th>
                                                                    <th>Nombre</th>
                                                                    <th>Dirección</th>
                                                                    <th>Ciudad</th>
                                                                    <th>Acciones</th>
                                                                </tr>
                                                            </thead>
                                                            <tbody>
                                                                <?php foreach ($firma['locaciones'] as $locacion): ?>
                                                                <tr>
                                                                    <td><?php echo $locacion['id']; ?></td>
                                                                    <td><?php echo htmlspecialchars($locacion['name']); ?></td>
                                                                    <td><?php echo htmlspecialchars($locacion['address'] ?? 'N/A'); ?></td>
                                                                    <td><?php echo htmlspecialchars($locacion['city'] ?? 'N/A'); ?></td>
                                                                    <td class="actions-column">
                                                                        <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditarLocacion<?php echo $locacion['id']; ?>">
                                                                            <i class="bi bi-pencil"></i> Editar
                                                                        </button>
                                                                        <button class="btn btn-sm btn-outline-danger btn-eliminar-locacion"
        data-locacion-id="<?php echo $locacion['id']; ?>"
        data-locacion-nombre="<?php echo htmlspecialchars($locacion['name']); ?>">
    <i class="bi bi-trash"></i> Eliminar
</button>
                                                                    </td>
                                                                </tr>
                                                                <?php endforeach; ?>
                                                            </tbody>
                                                        </table>
                                                    <?php else: ?>
                                                        <p class="text-muted">No hay locaciones registradas para esta firma.</p>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No hay firmas registradas</td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <!-- Modales para editar firmas -->
    <?php foreach ($firmas as $firma): ?>
    <div class="modal fade" id="modalEditarFirma<?php echo $firma['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Firma: <?php echo htmlspecialchars($firma['name']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="firma_id" value="<?php echo $firma['id']; ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre de la Firma *</label>
                            <input type="text" class="form-control" name="nombre" value="<?php echo htmlspecialchars($firma['name']); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Gerente/Responsable</label>
                            <input type="text" class="form-control" name="manager" value="<?php echo htmlspecialchars($firma['manager'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Correo del Gerente</label>
                            <input type="email" class="form-control" name="mail_manager" value="<?php echo htmlspecialchars($firma['mail_manager'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="editar_firma" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <!-- Modales para editar locaciones -->
    <?php foreach ($firmas as $firma): ?>
        <?php if (!empty($firma['locaciones'])): ?>
            <?php foreach ($firma['locaciones'] as $locacion): ?>
            <div class="modal fade" id="modalEditarLocacion<?php echo $locacion['id']; ?>" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Editar Locación: <?php echo htmlspecialchars($locacion['name']); ?></h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <form method="POST" action="">
                            <input type="hidden" name="locacion_id" value="<?php echo $locacion['id']; ?>">
                            <div class="modal-body">
                                <div class="mb-3">
                                    <label class="form-label">Nombre de la Locación *</label>
                                    <input type="text" class="form-control" name="nombre_locacion" value="<?php echo htmlspecialchars($locacion['name']); ?>" required>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Dirección</label>
                                    <textarea class="form-control" name="direccion_locacion" rows="2"><?php echo htmlspecialchars($locacion['address'] ?? ''); ?></textarea>
                                </div>
                                <div class="mb-3">
                                    <label class="form-label">Ciudad</label>
                                    <input type="text" class="form-control" name="ciudad_locacion" value="<?php echo htmlspecialchars($locacion['city'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                                <button type="submit" name="editar_locacion" class="btn btn-primary">Guardar Cambios</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    <?php endforeach; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
	<script>
	// Evitar que los clics en botones propaguen el evento de toggle - VERSIÓN MEJORADA
	document.addEventListener('DOMContentLoaded', function() {
	    document.querySelectorAll('.actions-column button, .actions-column a').forEach(element => {
	        element.addEventListener('click', function(e) {
	            e.stopPropagation();
	            e.preventDefault();
	            
	            // Si es un enlace de eliminación, ejecutar la confirmación
	            if (this.getAttribute('href') && this.getAttribute('href').includes('eliminar_')) {
	                if (confirm('¿Está seguro de eliminar este elemento?')) {
	                    window.location.href = this.getAttribute('href');
	                }
	            }
	        });
	    });
	});
	
	// Manejo específico para eliminación de firmas
document.addEventListener('DOMContentLoaded', function() {
    // Para firmas
    document.querySelectorAll('.btn-eliminar-firma').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const firmaId = this.getAttribute('data-firma-id');
            const firmaNombre = this.getAttribute('data-firma-nombre');
            
            if (confirm(`¿Está seguro de eliminar la firma "${firmaNombre}"? Esta acción no se puede deshacer.`)) {
                window.location.href = `?eliminar_firma=${firmaId}`;
            }
        });
    });
    
    // Para locaciones
    document.querySelectorAll('.btn-eliminar-locacion').forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const locacionId = this.getAttribute('data-locacion-id');
            const locacionNombre = this.getAttribute('data-locacion-nombre');
            
            if (confirm(`¿Está seguro de eliminar la locación "${locacionNombre}"?`)) {
                window.location.href = `?eliminar_locacion=${locacionId}`;
            }
        });
    });
});
	</script>
</body>
</html>