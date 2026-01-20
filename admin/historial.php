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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'usuarios', 'ver')) {
    header("Location: portal.php");
    exit();
}

$query = "SELECT h.*, CONCAT(emp.first_Name, ' ', emp.first_LastName) as admin_nombre
          FROM historial h 
          LEFT JOIN employee emp ON h.admin_id = emp.id
          ORDER BY h.creado_en DESC";

$stmt = $conn->prepare($query);
$stmt->execute();
$historial = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historial - <?php echo SITE_NAME; ?></title>
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
                    <h1 class="h2">Historial de Movimientos</h1>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>Fecha</th>
                                        <th>Usuario</th>
                                        <th>Acción</th>
                                        <th>Elemento</th>
                                        <th>Cantidad</th>
                                        <th>Notas</th>
                                        <th>Ticket</th>
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
                                                    echo htmlspecialchars($registro['usuario_id'] ?? 'N/A');
                                                }
                                                ?>
                                            </td>
                                            <td>
                                                <?php 
                                                // Determinar la acción basada en el tipo de registro
                                                $accion = $registro['accion'];
                                                $notas = $registro['notas'];
                                                
                                                // Si la acción está vacía pero las notas indican una acción
                                                if (empty($accion)) {
                                                    // Detección MEJORADA para categorías
                                                    if (stripos($notas, 'categoria creada') !== false || stripos($notas, 'categoría creada') !== false) {
                                                        $accion = 'crear';
                                                    } elseif (stripos($notas, 'categoria eliminada') !== false || stripos($notas, 'categoría eliminada') !== false) {
                                                        $accion = 'eliminar';
                                                    }
                                                    // Para equipos
                                                    elseif (stripos($notas, 'equipo creado') !== false) {
                                                        $accion = 'crear';
                                                    } elseif (stripos($notas, 'equipo eliminado') !== false) {
                                                        $accion = 'eliminar';
                                                    }
                                                    // Para items
                                                    elseif (stripos($notas, 'item creado') !== false) {
                                                        $accion = 'crear';
                                                    } elseif (stripos($notas, 'item eliminado') !== false) {
                                                        $accion = 'eliminar';
                                                    }
                                                    // Para ajustes de stock
                                                    elseif (stripos($notas, 'ajuste manual') !== false || stripos($notas, 'añadir') !== false) {
                                                        $accion = 'modificar';
                                                    } else {
                                                        $accion = 'modificar';
                                                    }
                                                }
                                                
                                                $badge_class = [
                                                    'añadir' => 'bg-success',
                                                    'agregar' => 'bg-success',
                                                    'eliminar' => 'bg-danger',
                                                    'crear' => 'bg-success',
                                                    'crear_item' => 'bg-success',
                                                    'crear_categoria' => 'bg-success',
                                                    'crear_equipo' => 'bg-success',
                                                    'eliminar_item' => 'bg-danger',
                                                    'eliminar_categoria' => 'bg-danger',
                                                    'eliminar_equipo' => 'bg-danger',
                                                    'intercambio' => 'bg-warning',
                                                    'solicitud' => 'bg-primary',
                                                    'devolucion' => 'bg-info',
                                                    'solicitud_aprobada' => 'bg-success',
                                                    'devolucion_recibida' => 'bg-info',
                                                    'asignar_equipo' => 'bg-primary',
                                                    'desasignar_equipo' => 'bg-warning',
                                                    'modificar' => 'bg-info'
                                                ][$accion] ?? 'bg-secondary';
                                                ?>
                                                <span class="badge <?php echo $badge_class; ?>">
                                                    <?php echo ucfirst($accion); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php 
                                                $notas = $registro['notas'];
                                                
                                                // Para categorías - PATRÓN MEJORADO
                                                if (preg_match('/categor[ií]a\s*(?:creada|eliminada):\s*([^\-]+?)(?:\s*-|\s*$)/i', $notas, $matches)) {
                                                    echo htmlspecialchars(trim($matches[1])) . " (Categoría)";
                                                }
                                                // Para equipos
                                                elseif (preg_match('/equipo\s*(?:creado|eliminado|asignado|desasignado):\s*([^\-]+?)(?:\s*-|\s*$)/i', $notas, $matches)) {
                                                    echo htmlspecialchars(trim($matches[1])) . " (Equipo)";
                                                }
                                                // Para items
                                                elseif (preg_match('/item\s*(?:creado|eliminado|ajuste):\s*([^\-]+?)(?:\s*-|\s*$)/i', $notas, $matches)) {
                                                    echo htmlspecialchars(trim($matches[1])) . " (Item)";
                                                }
                                                // Fallback general
                                                elseif (preg_match('/:\s*([^\-]+?)(?:\s*-|\s*$)/i', $notas, $matches)) {
                                                    echo htmlspecialchars(trim($matches[1]));
                                                } else {
                                                    echo 'N/A';
                                                }
                                                ?>
                                            </td>
                                            <td><?php echo $registro['cantidad']; ?></td>
                                            <td><?php echo htmlspecialchars($registro['notas']); ?></td>
                                            <td>
                                                <?php if ($registro['ticket_id']): ?>
                                                <a href="tickets.php" class="btn btn-sm btn-outline-primary">#<?php echo $registro['ticket_id']; ?></a>
                                                <?php else: ?>
                                                <span class="text-muted">N/A</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center">No hay registros en el historial</td>
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