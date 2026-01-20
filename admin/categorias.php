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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'categorias', 'ver')) {
    header("Location: portal.php");
    exit();
}

$functions = new Functions();
$error = '';
$success = '';

// Procesar creación de categoría
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_categoria'])) {
    $nombre = trim($_POST['nombre']);
    $descripcion = trim($_POST['descripcion']);
    
    if (!empty($nombre)) {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "INSERT INTO categorias (nombre, descripcion) VALUES (:nombre, :descripcion)";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':descripcion', $descripcion);
        
        if ($stmt->execute()) {
            // Registrar en historial
            $functions->registrarHistorial(
                null, 
                $_SESSION['admin_id'], 
                null, 
                'crear_categoria', 
                1, 
                "Categoría creada: " . $nombre
            );
            
            $success = "Categoría creada correctamente";
        } else {
            $error = "Error al crear la categoría";
        }
    } else {
        $error = "El nombre de la categoría es obligatorio";
    }
}

// Procesar eliminación de categoría
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    
    // Verificar si la categoría está siendo usada
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT COUNT(*) as total FROM items WHERE categoria_id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->execute();
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result['total'] == 0) {
        // Obtener nombre de la categoría antes de eliminar
        $query_nombre = "SELECT nombre FROM categorias WHERE id = :id";
        $stmt_nombre = $conn->prepare($query_nombre);
        $stmt_nombre->bindParam(':id', $id);
        $stmt_nombre->execute();
        $categoria = $stmt_nombre->fetch(PDO::FETCH_ASSOC);
        
        $query = "DELETE FROM categorias WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        
        if ($stmt->execute()) {
            // Registrar en historial
            $functions->registrarHistorial(
                null, 
                $_SESSION['admin_id'], 
                null, 
                'eliminar_categoria', 
                1, 
                "Categoría eliminada: " . $categoria['nombre']
            );
            
            $success = "Categoría eliminada correctamente";
        } else {
            $error = "Error al eliminar la categoría";
        }
    } else {
        $error = "No se puede eliminar la categoría porque tiene items asociados";
    }
}

// Obtener parámetro de búsqueda
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';

// Obtener todas las categorías con información de items
$database = new Database();
$conn = $database->getConnection();

$query = "SELECT c.*, 
                 COUNT(i.id) as total_items,
                 COALESCE(SUM(i.stock), 0) as total_unidades
          FROM categorias c 
          LEFT JOIN items i ON c.id = i.categoria_id 
          WHERE 1=1";

if (!empty($busqueda)) {
    $query .= " AND (c.nombre LIKE :busqueda OR c.descripcion LIKE :busqueda)";
}

$query .= " GROUP BY c.id ORDER BY c.nombre";

$stmt = $conn->prepare($query);

if (!empty($busqueda)) {
    $param_busqueda = "%$busqueda%";
    $stmt->bindParam(':busqueda', $param_busqueda);
}

$stmt->execute();
$categorias = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Función para obtener items de una categoría
function obtenerItemsPorCategoria($categoria_id) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT nombre, stock, stock_minimo 
              FROM items 
              WHERE categoria_id = :categoria_id 
              ORDER BY nombre";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':categoria_id', $categoria_id);
    $stmt->execute();
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Categorías - <?php echo SITE_NAME; ?></title>
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
    
    .btn-outline-danger {
        border-color: #be1622 !important;
        color: #be1622 !important;
    }
    
    .btn-outline-danger:hover {
        background-color: #be1622 !important;
        border-color: #be1622 !important;
        color: white !important;
    }
    
    .btn-outline-secondary {
        border-color: #6c757d !important;
        color: #6c757d !important;
    }
    
    .btn-outline-secondary:hover {
        background-color: #6c757d !important;
        border-color: #6c757d !important;
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
    .search-highlight {
        background-color: yellow;
        font-weight: bold;
    }
    .categoria-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .categoria-row:hover {
        background-color: #f8f9fa;
    }
    .stock-critico {
        color: #dc3545;
        font-weight: bold;
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
                    <h1 class="h2">Gestión de Categorías</h1>
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearCategoria">
                        <i class="bi bi-plus-circle"></i> Nueva Categoría
                    </button>
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Buscador -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" id="formBusqueda">
                            <div class="row">
                                <div class="col-md-6">
                                    <label for="busqueda" class="form-label">Buscar categorías</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="busqueda" name="busqueda" 
                                               value="<?php echo htmlspecialchars($busqueda); ?>" 
                                               placeholder="Escribe para buscar..." 
                                               oninput="buscarCategorias()">
                                        <?php if (!empty($busqueda)): ?>
                                        <a href="?" class="btn btn-outline-secondary">Limpiar</a>
                                        <?php endif; ?>
                                    </div>
                                    <div class="form-text">Busca por nombre o descripción de categoría</div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Nombre</th>
                                        <th>Descripción</th>
                                        <th>Items</th>
                                        <th>Unidades</th>
                                        <th>Fecha Creación</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($categorias) > 0): ?>
                                        <?php foreach ($categorias as $categoria): ?>
                                        <tr class="categoria-row" onclick="verItemsCategoria(<?php echo $categoria['id']; ?>, '<?php echo htmlspecialchars($categoria['nombre']); ?>')">
                                            <td>
                                                <?php 
                                                if (!empty($busqueda)) {
                                                    echo highlight_text($categoria['nombre'], $busqueda);
                                                } else {
                                                    echo htmlspecialchars($categoria['nombre']);
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                if (!empty($busqueda)) {
                                                    echo highlight_text($categoria['descripcion'], $busqueda);
                                                } else {
                                                    echo htmlspecialchars($categoria['descripcion']);
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo $categoria['total_items']; ?> items</span>
                                            </td>
                                            <td>
                                                <span class="badge bg-success"><?php echo $categoria['total_unidades']; ?> unidades</span>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($categoria['creado_en'])); ?></td>
                                            <td>
                                                <a href="?eliminar=<?php echo $categoria['id']; ?>" class="btn btn-sm btn-danger" onclick="event.stopPropagation(); return confirm('¿Está seguro de eliminar esta categoría?')">
                                                    <i class="bi bi-trash"></i> Eliminar
                                                </a>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center">
                                                <?php if (!empty($busqueda)): ?>
                                                No se encontraron categorías que coincidan con "<?php echo htmlspecialchars($busqueda); ?>"
                                                <?php else: ?>
                                                No hay categorías registradas
                                                <?php endif; ?>
                                            </td>
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
    
    <!-- Modal para crear categoría -->
    <div class="modal fade" id="modalCrearCategoria" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Crear Nueva Categoría</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre de la categoría</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="crear_categoria" class="btn btn-primary">Crear Categoría</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal para ver items de la categoría -->
    <div class="modal fade" id="modalItemsCategoria" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalItemsTitulo">Items de la Categoría</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="contenidoItemsCategoria">
                        <p>Cargando items...</p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Función para búsqueda en tiempo real con debounce
    let timeoutId;
    function buscarCategorias() {
        clearTimeout(timeoutId);
        timeoutId = setTimeout(() => {
            document.getElementById('formBusqueda').submit();
        }, 300);
    }
    
    // Función para ver items de una categoría
    function verItemsCategoria(categoriaId, categoriaNombre) {
        // Actualizar título del modal
        document.getElementById('modalItemsTitulo').textContent = 'Items de: ' + categoriaNombre;
        
        // Mostrar loading
        document.getElementById('contenidoItemsCategoria').innerHTML = '<p>Cargando items...</p>';
        
        // Mostrar modal
        var modal = new bootstrap.Modal(document.getElementById('modalItemsCategoria'));
        modal.show();
        
        // Hacer petición AJAX para obtener los items
        fetch('../includes/obtener_items_categoria.php?categoria_id=' + categoriaId)
            .then(response => response.text())
            .then(data => {
                document.getElementById('contenidoItemsCategoria').innerHTML = data;
            })
            .catch(error => {
                document.getElementById('contenidoItemsCategoria').innerHTML = 
                    '<div class="alert alert-danger">Error al cargar los items: ' + error + '</div>';
            });
    }
    
    // Focus en el campo de búsqueda al cargar la página
    document.addEventListener('DOMContentLoaded', function() {
        <?php if (!empty($busqueda)): ?>
        document.getElementById('busqueda').focus();
        <?php endif; ?>
    });
    </script>
</body>
</html>

<?php
// Función para resaltar texto en resultados de búsqueda
function highlight_text($text, $search) {
    if (empty($search) || empty($text)) {
        return htmlspecialchars($text);
    }
    
    $pattern = '/(' . preg_quote($search, '/') . ')/i';
    $replacement = '<span class="search-highlight">$1</span>';
    
    return preg_replace($pattern, $replacement, htmlspecialchars($text));
}
?>