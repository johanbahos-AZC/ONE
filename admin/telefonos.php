<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'telefonos', 'ver')) {
    header("Location: portal.php");
    exit();
}

$functions = new Functions();
$error = '';
$success = '';

// Obtener todas las firmas
$query_firmas = "SELECT * FROM firm ORDER BY name";
$stmt_firmas = $conn->prepare($query_firmas);
$stmt_firmas->execute();
$firmas = $stmt_firmas->fetchAll(PDO::FETCH_ASSOC);

// Procesar creación de teléfono
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_telefono'])) {
    $id_firm = $_POST['id_firm'];
    $numero = trim($_POST['numero']);
    
    if (!empty($id_firm) && !empty($numero)) {
        try {
            // Verificar si el número ya existe
            $query_verificar = "SELECT id FROM phones WHERE phone = :numero";
            $stmt_verificar = $conn->prepare($query_verificar);
            $stmt_verificar->bindParam(':numero', $numero);
            $stmt_verificar->execute();
            
            if ($stmt_verificar->rowCount() > 0) {
                $error = "El número de teléfono ya existe";
            } else {
                $query = "INSERT INTO phones (id_firm, phone) VALUES (:id_firm, :numero)";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':id_firm', $id_firm);
                $stmt->bindParam(':numero', $numero);
                
                if ($stmt->execute()) {
                    $success = "Teléfono creado correctamente";
                } else {
                    $error = "Error al crear el teléfono";
                }
            }
        } catch (PDOException $e) {
            $error = "Error en la base de datos: " . $e->getMessage();
        }
    } else {
        $error = "La firma y el número son obligatorios";
    }
}

// Procesar eliminación de teléfono
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    
    try {
        // Verificar si el teléfono está asignado a algún usuario
        $query_verificar = "SELECT id FROM employee WHERE id_phone = :id";
        $stmt_verificar = $conn->prepare($query_verificar);
        $stmt_verificar->bindParam(':id', $id);
        $stmt_verificar->execute();
        
        if ($stmt_verificar->rowCount() > 0) {
            $error = "No se puede eliminar el teléfono porque está asignado a un usuario";
        } else {
            $query = "DELETE FROM phones WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                $success = "Teléfono eliminado correctamente";
            } else {
                $error = "Error al eliminar el teléfono";
            }
        }
    } catch (PDOException $e) {
        $error = "Error en la base de datos: " . $e->getMessage();
    }
}

// Obtener todos los teléfonos con información de firma
$query_telefonos = "SELECT p.*, f.name as firm_name 
                    FROM phones p 
                    LEFT JOIN firm f ON p.id_firm = f.id 
                    ORDER BY f.name, p.phone";
$stmt_telefonos = $conn->prepare($query_telefonos);
$stmt_telefonos->execute();
$telefonos = $stmt_telefonos->fetchAll(PDO::FETCH_ASSOC);

// Obtener teléfonos asignados
$query_asignados = "SELECT e.first_Name, e.first_LastName, p.id as phone_id 
                    FROM employee e 
                    INNER JOIN phones p ON e.id_phone = p.id";
$stmt_asignados = $conn->prepare($query_asignados);
$stmt_asignados->execute();
$asignados = $stmt_asignados->fetchAll(PDO::FETCH_ASSOC);

// Crear array de teléfonos asignados para fácil verificación
$telefonos_asignados = [];
foreach ($asignados as $asignado) {
    $telefonos_asignados[$asignado['phone_id']] = $asignado['first_Name'] . ' ' . $asignado['first_LastName'];
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Teléfonos IP - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
<style>
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
</style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Teléfonos IP</h1>
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Formulario para crear teléfono -->
                <div class="card mb-4">
                    <div class="card-header" style="background-color: #e9ecef;">
                        <h5 class="card-title">Agregar Nuevo Teléfono</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" action="">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="id_firm" class="form-label">Firma *</label>
                                        <select class="form-select" id="id_firm" name="id_firm" required>
                                            <option value="">Seleccione una firma</option>
                                            <?php foreach ($firmas as $firma): ?>
                                            <option value="<?php echo $firma['id']; ?>"><?php echo htmlspecialchars($firma['name']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="numero" class="form-label">Número de Teléfono *</label>
                                        <input type="text" class="form-control" id="numero" name="numero" required 
                                               pattern="[0-9]*" placeholder="Solo números">
                                    </div>
                                </div>
                            </div>
                            <button type="submit" name="crear_telefono" class="btn btn-primary">Agregar Teléfono</button>
                        </form>
                    </div>
                </div>
                
                <!-- Lista de teléfonos -->
                <div class="card">
                    <div class="card-header" style="background-color: #e9ecef;">
                        <h5 class="card-title">Teléfonos Existentes</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Firma</th>
                                        <th>Número</th>
                                        <th>Estado</th>
                                        <th>Asignado a</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($telefonos) > 0): ?>
                                        <?php foreach ($telefonos as $telefono): ?>
                                        <tr>
                                            <td><?php echo $telefono['id']; ?></td>
                                            <td><?php echo htmlspecialchars($telefono['firm_name']); ?></td>
                                            <td><?php echo htmlspecialchars($telefono['phone']); ?></td>
                                            <td>
                                                <?php if (isset($telefonos_asignados[$telefono['id']])): ?>
                                                <span class="badge bg-warning">Asignado</span>
                                                <?php else: ?>
                                                <span class="badge bg-success">Disponible</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (isset($telefonos_asignados[$telefono['id']])): ?>
                                                <?php echo htmlspecialchars($telefonos_asignados[$telefono['id']]); ?>
                                                <?php else: ?>
                                                <span class="text-muted">No asignado</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if (!isset($telefonos_asignados[$telefono['id']])): ?>
                                                <a href="?eliminar=<?php echo $telefono['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Está seguro de eliminar este teléfono?')">
                                                    Eliminar
                                                </a>
                                                <?php else: ?>
                                                <span class="text-muted">No se puede eliminar</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center">No hay teléfonos registrados</td>
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
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>