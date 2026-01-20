<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

 $auth = new Auth();
 $auth->redirectIfNotLoggedIn();

 $functions = new Functions();
 $sede_id = isset($_GET['sede_id']) ? $_GET['sede_id'] : null;
 $item_id = isset($_GET['item_id']) ? $_GET['item_id'] : null;

// Obtener información de la sede si se especifica
 $sede_info = null;
if ($sede_id) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT * FROM sedes WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $sede_id);
    $stmt->execute();
    $sede_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtener información del item si se especifica
 $item_info = null;
if ($item_id) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT i.*, s.nombre as sede_nombre FROM items i 
              LEFT JOIN sedes s ON i.sede_id = s.id
              WHERE i.id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $item_id);
    $stmt->execute();
    $item_info = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Obtener historial según los parámetros
if ($item_id) {
    $historial = $functions->obtenerHistorialItem($item_id);
    $titulo = "Historial del Item: " . htmlspecialchars($item_info['nombre']);
} elseif ($sede_id) {
    $historial = $functions->obtenerHistorialSede($sede_id);
    $titulo = "Historial de la Sede: " . htmlspecialchars($sede_info['nombre']);
} else {
    $historial = $functions->obtenerHistorialGeneral();
    $titulo = "Historial General de Items";
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $titulo; ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2"><?php echo $titulo; ?></h1>
                    <div>
                        <?php if ($item_id): ?>
                        <a href="items.php?sede_id=<?php echo $item_info['sede_id']; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Volver a Items
                        </a>
                        <?php elseif ($sede_id): ?>
                        <a href="items.php?sede_id=<?php echo $sede_id; ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Volver a Items
                        </a>
                        <?php else: ?>
                        <a href="items.php" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Volver a Items
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($item_info): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Información del Item</h5>
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Nombre:</strong> <?php echo htmlspecialchars($item_info['nombre']); ?></p>
                                <p><strong>Categoría:</strong> <?php echo htmlspecialchars($item_info['categoria_nombre'] ?? 'N/A'); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Stock Actual:</strong> <?php echo $item_info['stock']; ?></p>
                                <p><strong>Sede:</strong> <?php echo htmlspecialchars($item_info['sede_nombre']); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($sede_info): ?>
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Información de la Sede</h5>
                        <p><strong>Nombre:</strong> <?php echo htmlspecialchars($sede_info['nombre']); ?></p>
                        <p><strong>Código:</strong> <?php echo htmlspecialchars($sede_info['codigo']); ?></p>
                    </div>
                </div>
                <?php endif; ?>
                
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Usuario</th>
                                        <th>Acción</th>
                                        <th>Item</th>
                                        <th>Cantidad</th>
                                        <th>Stock Anterior</th>
                                        <th>Stock Nuevo</th>
                                        <th>Sede</th>
                                        <th>Notas</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($historial) > 0): ?>
                                        <?php foreach ($historial as $registro): ?>
                                        <tr>
                                            <td><?php echo date('d/m/Y H:i', strtotime($registro['creado_en'])); ?></td>
                                            <td>
                                                <?php 
                                                if (!empty($registro['admin_nombre'])) {
                                                    echo htmlspecialchars($registro['admin_nombre']);
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                $accion = $registro['accion'];
                                                $badge_class = [
                                                    'crear' => 'bg-success',
                                                    'crear_por_movimiento' => 'bg-success',
                                                    'editar_stock' => 'bg-info',
                                                    'enviar_movimiento' => 'bg-warning',
                                                    'recibir_movimiento' => 'bg-primary',
                                                    'eliminar' => 'bg-danger'
                                                ][$accion] ?? 'bg-secondary';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php 
                                                    $acciones_texto = [
                                                        'crear' => 'Crear',
                                                        'crear_por_movimiento' => 'Crear por Movimiento',
                                                        'editar_stock' => 'Editar Stock',
                                                        'enviar_movimiento' => 'Enviar Movimiento',
                                                        'recibir_movimiento' => 'Recibir Movimiento',
                                                        'eliminar' => 'Eliminar'
                                                    ];
                                                    echo $acciones_texto[$accion] ?? ucfirst($accion);
                                                    ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($registro['item_nombre']); ?></td>
                                            <td><?php echo $registro['cantidad']; ?></td>
                                            <td><?php echo $registro['stock_anterior']; ?></td>
                                            <td><?php echo $registro['stock_nuevo']; ?></td>
                                            <td><?php echo htmlspecialchars($registro['sede_nombre'] ?? 'N/A'); ?></td>
                                            <td><?php echo htmlspecialchars($registro['notas']); ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="9" class="text-center">No hay registros en el historial</td>
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