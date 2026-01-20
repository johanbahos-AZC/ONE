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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'items', 'ver')) {
    header("Location: portal.php");
    exit();
}

// Verificar permisos específicos para las acciones
 $permiso_crear_item = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'items', 'crear_item');
  $permiso_crear_item = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'items', 'eliminar_item');

// Obtener todas las sedes activas
 $query = "SELECT * FROM sedes WHERE activa = 1 ORDER BY nombre";
 $stmt = $conn->prepare($query);
 $stmt->execute();
 $sedes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Variable para almacenar la sede seleccionada
 $sede_seleccionada = isset($_GET['sede_id']) ? $_GET['sede_id'] : (isset($_SESSION['sede_seleccionada']) ? $_SESSION['sede_seleccionada'] : null);

// Si no hay sede seleccionada, mostrar la página de selección de sedes
if (!$sede_seleccionada) {
    include 'seleccionar_sede.php';
    exit;
}

// Guardar la sede seleccionada en la sesión
 $_SESSION['sede_seleccionada'] = $sede_seleccionada;

// Verificar si se solicita cambiar de sede
if (isset($_GET['cambiar_sede']) && $_GET['cambiar_sede'] == 1) {
    // Limpiar la variable de sesión
    unset($_SESSION['sede_seleccionada']);
    // Redirigir para mostrar la selección de sedes
    header("Location: items.php");
    exit;
}

// Procesar creación de item
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_item'])) {
    $nombre = trim($_POST['nombre']);
    $categoria_id = $_POST['categoria_id'];
    $sede_id = $_POST['sede_id'];
    $descripcion = trim($_POST['descripcion']);
    $stock = $_POST['stock'];
    $stock_minimo = $_POST['stock_minimo'];
    $tipo = 'consumible';
    $necesita_restock = 1; // Por defecto necesita restock
    
    if (!empty($nombre) && !empty($categoria_id) && !empty($sede_id)) {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "INSERT INTO items (nombre, categoria_id, sede_id, descripcion, stock, stock_minimo, tipo, necesita_restock) 
                  VALUES (:nombre, :categoria_id, :sede_id, :descripcion, :stock, :stock_minimo, :tipo, :necesita_restock)";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':nombre', $nombre);
        $stmt->bindParam(':categoria_id', $categoria_id);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->bindParam(':descripcion', $descripcion);
        $stmt->bindParam(':stock', $stock);
        $stmt->bindParam(':stock_minimo', $stock_minimo);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':necesita_restock', $necesita_restock);
        
        if ($stmt->execute()) {
            $nuevo_item_id = $conn->lastInsertId();
            
            // Obtener información del item creado
            $query_info = "SELECT * FROM items WHERE id = :id";
            $stmt_info = $conn->prepare($query_info);
            $stmt_info->bindParam(':id', $nuevo_item_id);
            $stmt_info->execute();
            $item_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
            
            // Obtener el ID de la sede Tequendama
            $tequendama_id = null;
            foreach ($sedes as $sede) {
                if (stripos($sede['nombre'], 'tequendama') !== false) {
                    $tequendama_id = $sede['id'];
                    break;
                }
            }
            
            // Crear el mismo item en todas las demás sedes con stock 0
            foreach ($sedes as $sede) {
                if ($sede['id'] != $tequendama_id) {
                    $query_replica = "INSERT INTO items (nombre, categoria_id, sede_id, descripcion, stock, stock_minimo, tipo, necesita_restock) 
                                      VALUES (:nombre, :categoria_id, :sede_id, :descripcion, 0, :stock_minimo, :tipo, :necesita_restock)";
                    $stmt_replica = $conn->prepare($query_replica);
                    $stmt_replica->bindParam(':nombre', $item_info['nombre']);
                    $stmt_replica->bindParam(':categoria_id', $item_info['categoria_id']);
                    $stmt_replica->bindParam(':sede_id', $sede['id']);
                    $stmt_replica->bindParam(':descripcion', $item_info['descripcion']);
                    $stmt_replica->bindParam(':stock_minimo', $item_info['stock_minimo']);
                    $stmt_replica->bindParam(':tipo', $item_info['tipo']);
                    $stmt_replica->bindParam(':necesita_restock', $item_info['necesita_restock']);
                    $stmt_replica->execute();
                    
                    $replica_id = $conn->lastInsertId();
                    
                    // Nuevo: Registrar en historial específico de items
                    $functions->registrarHistorialItem(
                        $replica_id, 
                        $_SESSION['admin_id'], 
                        'crear', 
                        0, 
                        0, 
                        0, 
                        "Item replicado automáticamente en sede " . $sede['nombre'] . " (stock 0)",
                        $sede['id']
                    );
                }
            }
            
            // Nuevo: Registrar en historial específico de items
            $functions->registrarHistorialItem(
                $nuevo_item_id, 
                $_SESSION['admin_id'], 
                'crear', 
                $stock, 
                0, 
                $stock, 
                "Item creado: " . $nombre . " - Stock inicial: " . $stock . " (replicado en otras sedes)",
                $sede_id
            );
            
            $success = "Item creado correctamente y replicado en todas las sedes";
        } else {
            $error = "Error al crear el item";
        }
    } else {
        $error = "El nombre, la categoría y la sede son obligatorios";
    }
}

// Procesar actualización de stock
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_stock'])) {
    $item_id = $_POST['item_id'];
    $nuevo_stock = $_POST['nuevo_stock'];
    $motivo = trim($_POST['motivo']);
    $necesita_restock = isset($_POST['necesita_restock']) ? 1 : 0;
    
    $database = new Database();
    $conn = $database->getConnection();
    
    // Obtener stock actual
    $query = "SELECT stock, nombre FROM items WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $item_id);
    $stmt->execute();
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $stock_anterior = $item['stock'];
    $diferencia = $nuevo_stock - $stock_anterior;
    $accion = ($diferencia > 0) ? 'añadir' : 'eliminar';
    
    // Actualizar stock y flag de restock
    $query = "UPDATE items SET stock = :stock, necesita_restock = :necesita_restock WHERE id = :id";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':stock', $nuevo_stock);
    $stmt->bindParam(':necesita_restock', $necesita_restock);
    $stmt->bindParam(':id', $item_id);
    
    if ($stmt->execute()) {
        // Nuevo: Registrar en historial específico de items
        $notas_historial = "Ajuste manual de inventario: " . $motivo;
        if ($necesita_restock == 0) {
            $notas_historial .= " - Restock cancelado";
        } else {
            $notas_historial .= " - Restock activado";
        }
        
        $functions->registrarHistorialItem(
            $item_id, 
            $_SESSION['admin_id'], 
            'editar_stock', 
            abs($diferencia), 
            $stock_anterior, 
            $nuevo_stock, 
            $notas_historial
        );
        
        $success = "Stock actualizado correctamente";
    } else {
        $error = "Error al actualizar el stock";
    }
}

// Procesar movimiento de items entre sedes
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['mover_item'])) {
    
    echo "<script>console.log('INICIANDO PROCESO DE MOVIMIENTO...');</script>";
    
    // 1. Validar que todos los datos necesarios estén presentes
    $required_fields = ['item_id', 'cantidad', 'sede_origen_id', 'sede_destino_id'];
    foreach ($required_fields as $field) {
        if (!isset($_POST[$field]) || empty($_POST[$field])) {
            $error = "Error: Falta el campo obligatorio '$field'.";
            echo "<script>console.error('Validación fallida: Falta campo $field');</script>";
            goto end_movement_processing; 
        }
    }

    $item_id = (int)$_POST['item_id'];
    $cantidad = (int)$_POST['cantidad'];
    $sede_origen_id = (int)$_POST['sede_origen_id'];
    $sede_destino_id = (int)$_POST['sede_destino_id'];
    $notas = trim($_POST['notas'] ?? '');

    echo "<script>console.log('Datos del movimiento:', {item_id: '$item_id', cantidad: '$cantidad', sede_origen_id: '$sede_origen_id', sede_destino_id: '$sede_destino_id'});</script>";

    // 2. Validar la lógica de los datos
    if ($cantidad <= 0) {
        $error = "La cantidad a mover debe ser mayor a cero.";
    } elseif ($sede_origen_id == $sede_destino_id) {
        $error = "La sede de origen y destino deben ser diferentes.";
    }

    // Si hay errores de validación, detener la ejecución
    if (!empty($error)) {
        echo "<script>console.error('Error de lógica: $error');</script>";
        goto end_movement_processing;
    }

    // 3. Verificar y obtener el ID del usuario que envía
    $persona_envia_id = null;
    if (isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id'])) {
        $persona_envia_id = $_SESSION['admin_id'];
    } elseif (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $persona_envia_id = $_SESSION['user_id'];
    }

    if (!$persona_envia_id) {
        $error = "Error de autenticación: No se pudo identificar al usuario que realiza el movimiento.";
        echo "<script>console.error('Error de autenticación.');</script>";
        goto end_movement_processing;
    }
    
    echo "<script>console.log('ID del usuario que envía: $persona_envia_id');</script>";
    
    // --- NUEVO ENFOQUE: OBTENER TODA LA INFORMACIÓN ANTES DE LA TRANSACCIÓN ---
    $database = new Database();
    $conn = $database->getConnection();
    
    echo "<script>console.log('Obteniendo información previa a la transacción...');</script>";

    try {
        // Obtener stock disponible en la sede origen
        $query = "SELECT stock FROM items WHERE id = :item_id AND sede_id = :sede_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':item_id', $item_id);
        $stmt->bindParam(':sede_id', $sede_origen_id);
        $stmt->execute();
        $item_origen = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$item_origen || $item_origen['stock'] < $cantidad) {
            throw new Exception("No hay suficiente stock disponible en la sede de origen. Stock actual: " . ($item_origen['stock'] ?? 0));
        }
        
        // Obtener información del item original
        $query = "SELECT nombre, categoria_id, descripcion, stock_minimo, tipo, necesita_restock FROM items WHERE id = :item_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':item_id', $item_id);
        $stmt->execute();
        $item_info = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Verificar si existe un item con el mismo nombre y categoría en la sede destino
        $query = "SELECT id, stock FROM items WHERE nombre = :nombre AND categoria_id = :categoria_id AND sede_id = :sede_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':nombre', $item_info['nombre']);
        $stmt->bindParam(':categoria_id', $item_info['categoria_id']);
        $stmt->bindParam(':sede_id', $sede_destino_id);
        $stmt->execute();
        $item_destino = $stmt->fetch(PDO::FETCH_ASSOC);

        echo "<script>console.log('Información obtenida. Iniciando transacción corta...');</script>";

        // --- INICIO DE LA TRANSACCIÓN CORTA (SOLO ESCRITURAS) ---
        $conn->beginTransaction();
        
        // Stock antes del movimiento en origen
        $stock_origen_anterior = $item_origen['stock'];
        
        // Reducir stock en la sede origen
        $query = "UPDATE items SET stock = stock - :cantidad WHERE id = :item_id AND sede_id = :sede_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':cantidad', $cantidad);
        $stmt->bindParam(':item_id', $item_id);
        $stmt->bindParam(':sede_id', $sede_origen_id);
        $stmt->execute();
        
        if ($item_destino) {
            // Si existe, actualizar el stock
            $stock_destino_anterior = $item_destino['stock'];
            
            $query = "UPDATE items SET stock = stock + :cantidad WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':cantidad', $cantidad);
            $stmt->bindParam(':id', $item_destino['id']);
            $stmt->execute();
            
            $item_destino_id = $item_destino['id'];
            
            echo "<script>console.log('Intentando registrar historial en destino...');</script>";
            // Nuevo: Registrar historial en destino (pasando la conexión)
            $resultado_historial_destino = $functions->registrarHistorialItem(
                $item_destino_id, 
                $persona_envia_id, 
                'recibir_movimiento', 
                $cantidad, 
                $stock_destino_anterior, 
                $stock_destino_anterior + $cantidad, 
                "Movimiento desde sede origen: " . obtenerNombreSede($sede_origen_id) . " - " . $notas,
                $sede_destino_id,
                null,
                $conn  // Pasar la conexión existente
            );
            
            echo "<script>console.log('Resultado historial destino: ' . ($resultado_historial_destino ? 'EXITOSO' : 'FALLIDO'));</script>";
        } else {
            // Si no existe, crear un nuevo registro para ese item en la sede destino
            $query = "INSERT INTO items (nombre, categoria_id, sede_id, descripcion, stock, stock_minimo, tipo, necesita_restock) 
                      VALUES (:nombre, :categoria_id, :sede_id, :descripcion, :stock, :stock_minimo, :tipo, :necesita_restock)";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':nombre', $item_info['nombre']);
            $stmt->bindParam(':categoria_id', $item_info['categoria_id']);
            $stmt->bindParam(':sede_id', $sede_destino_id);
            $stmt->bindParam(':descripcion', $item_info['descripcion']);
            $stmt->bindParam(':stock', $cantidad);
            $stmt->bindParam(':stock_minimo', $item_info['stock_minimo']);
            $stmt->bindParam(':tipo', $item_info['tipo']);
            $stmt->bindParam(':necesita_restock', $item_info['necesita_restock']);
            $stmt->execute();
            
            $item_destino_id = $conn->lastInsertId();
            
            echo "<script>console.log('Intentando registrar historial de creación por movimiento...');</script>";
            // Nuevo: Registrar historial en destino (pasando la conexión)
            $resultado_historial_destino = $functions->registrarHistorialItem(
                $item_destino_id, 
                $persona_envia_id, 
                'crear_por_movimiento', 
                $cantidad, 
                0, 
                $cantidad, 
                "Item creado por movimiento desde sede origen: " . obtenerNombreSede($sede_origen_id) . " - " . $notas,
                $sede_destino_id,
                null,
                $conn  // Pasar la conexión existente
            );
            
            echo "<script>console.log('Resultado historial creación: ' . ($resultado_historial_destino ? 'EXITOSO' : 'FALLIDO'));</script>";
        }
        
        // Registrar el movimiento entre sedes
        $query = "INSERT INTO movimientos_sedes (item_id, cantidad, sede_origen_id, sede_destino_id, persona_envia_id, fecha_envio, estado, notas) 
                  VALUES (:item_id, :cantidad, :sede_origen_id, :sede_destino_id, :persona_envia_id, NOW(), 'enviado', :notas)";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':item_id', $item_id);
        $stmt->bindParam(':cantidad', $cantidad);
        $stmt->bindParam(':sede_origen_id', $sede_origen_id);
        $stmt->bindParam(':sede_destino_id', $sede_destino_id);
        $stmt->bindParam(':persona_envia_id', $persona_envia_id);
        $stmt->bindParam(':notas', $notas);
        $stmt->execute();
        
        $movimiento_id = $conn->lastInsertId();
        
        echo "<script>console.log('Intentando registrar historial en origen...');</script>";
        // Nuevo: Registrar historial en origen (pasando la conexión)
        $resultado_historial_origen = $functions->registrarHistorialItem(
            $item_id, 
            $persona_envia_id, 
            'enviar_movimiento', 
            $cantidad, 
            $stock_origen_anterior, 
            $stock_origen_anterior - $cantidad, 
            "Movimiento hacia sede destino: " . obtenerNombreSede($sede_destino_id) . " - " . $notas,
            $sede_origen_id,
            $movimiento_id,
            $conn  // Pasar la conexión existente
        );
        
        echo "<script>console.log('Resultado historial origen: ' . ($resultado_historial_origen ? 'EXITOSO' : 'FALLIDO'));</script>";
        
        $conn->commit();
        echo "<script>console.log('Transacción completada con éxito.');</script>";
        
        $success = "Movimiento entre sedes registrado correctamente";
        
    } catch (PDOException $e) {
        // Capturar específicamente el error de lock timeout
        if ($e->errorInfo[0] == 'HY000' && $e->errorInfo[1] == 1205) {
            $error = "El sistema está ocupado con otra operación. Por favor, espere unos segundos y vuelva a intentarlo.";
            echo "<script>console.error('ERROR DE LOCK TIMEOUT: " . $e->getMessage() . "');</script>";
        } else {
            $error = "Error de base de datos: " . $e->getMessage();
            echo "<script>console.error('ERROR PDO: " . $e->getMessage() . "');</script>";
        }
        
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
            echo "<script>console.log('Transacción revertida (rollback).');</script>";
        }
    } catch (Exception $e) {
        $error = "Error al procesar el movimiento: " . $e->getMessage();
        echo "<script>console.error('ERROR GENERAL: " . $e->getMessage() . "');</script>";
        
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
            echo "<script>console.log('Transacción revertida (rollback).');</script>";
        }
    }
}

end_movement_processing:
// Esta etiqueta es un punto de salida limpio para evitar procesamiento innecesario

// Función auxiliar para obtener el nombre de una sede
function obtenerNombreSede($sede_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT nombre FROM sedes WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $sede_id);
        $stmt->execute();
        
        $sede = $stmt->fetch(PDO::FETCH_ASSOC);
        return $sede ? $sede['nombre'] : 'Sede desconocida';
        
    } catch (Exception $e) {
        return 'Sede desconocida';
    }
}

// Procesar eliminación de item
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];

    // Obtener admin_id de la sesión
    $admin_id = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : null;
    
    if (empty($admin_id)) {
        $admin_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
    }
    
    if (empty($admin_id)) {
        $admin_id = 1; // ID del administrador por defecto
    }

    echo "<script>console.log('Solicitada eliminación del item ID: $id');</script>";
    echo "<script>console.log('admin_id: $admin_id');</script>";

    // Validar que el ID sea un número
    if (!is_numeric($id) || $id <= 0) {
        $error = "ID de item inválido.";
        echo "<script>console.error('ID inválido: $id');</script>";
        goto end_deletion_processing;
    }

    $database = new Database();
    $conn = $database->getConnection();

    try {
        $conn->beginTransaction();
        echo "<script>console.log('Transacción iniciada para eliminar item ID: $id');</script>";

        // 1. Verificar que el item existe ANTES de intentar eliminarlo
        $query_info = "SELECT * FROM items WHERE id = :id FOR UPDATE"; // FOR UPDATE bloquea el registro para evitar condiciones de carrera
        $stmt_info = $conn->prepare($query_info);
        $stmt_info->bindParam(':id', $id);
        $stmt_info->execute();
        $item_info = $stmt_info->fetch(PDO::FETCH_ASSOC);

        if (!$item_info) {
            throw new Exception("El item que intenta eliminar no existe o ya fue eliminado por otro proceso.");
        }
        echo "<script>console.log('Item encontrado: " . addslashes($item_info['nombre']) . "');</script>";

        // 2. CAMBIO: Registrar en historial específico de items ANTES de eliminar cualquier registro
        echo "<script>console.log('Intentando registrar historial de eliminación...');</script>";
        $resultado_historial = $functions->registrarHistorialItem(
            $id, 
            $admin_id, 
            'eliminar', 
            $item_info['stock'], 
            $item_info['stock'], 
            0, 
            "Item eliminado: " . $item_info['nombre'],
            $item_info['sede_id'],
            null,
            $conn  // Pasar la conexión existente
        );
        
        // Corregir la forma de imprimir el resultado
        $resultado_texto = $resultado_historial ? 'EXITOSO' : 'FALLIDO';
        echo "<script>console.log('Resultado historial eliminación: " . $resultado_texto . "');</script>";

        // 3. Eliminar registros relacionados en historial (antiguo)
        $query_hist = "DELETE FROM historial WHERE item_id = :item_id";
        $stmt_hist = $conn->prepare($query_hist);
        $stmt_hist->bindParam(':item_id', $id);
        $stmt_hist->execute();
        $historial_eliminado = $stmt_hist->rowCount();
        echo "<script>console.log('Registros de historial antiguo eliminados: $historial_eliminado');</script>";

        // 4. Eliminar registros relacionados en historial_items (nuevo)
        // NOTA: Ya no eliminamos estos registros porque necesitamos mantener el historial de eliminación
        // $query_hist_items = "DELETE FROM historial_items WHERE item_id = :item_id";
        // $stmt_hist_items = $conn->prepare($query_hist_items);
        // $stmt_hist_items->bindParam(':item_id', $id);
        // $stmt_hist_items->execute();
        // $historial_items_eliminado = $stmt_hist_items->rowCount();
        // echo "<script>console.log('Registros de historial_items eliminados: $historial_items_eliminado');</script>";

        // 5. Eliminar registros relacionados en movimientos_sedes
        $query_mov = "DELETE FROM movimientos_sedes WHERE item_id = :item_id";
        $stmt_mov = $conn->prepare($query_mov);
        $stmt_mov->bindParam(':item_id', $id);
        $stmt_mov->execute();
        $movimientos_eliminados = $stmt_mov->rowCount();
        echo "<script>console.log('Registros de movimientos eliminados: $movimientos_eliminados');</script>";

        // 6. Eliminar registros relacionados en tickets (NUEVO)
        $query_tickets = "DELETE FROM tickets WHERE item_devuelto_id = :item_id";
        $stmt_tickets = $conn->prepare($query_tickets);
        $stmt_tickets->bindParam(':item_id', $id);
        $stmt_tickets->execute();
        $tickets_eliminados = $stmt_tickets->rowCount();
        echo "<script>console.log('Registros de tickets eliminados: $tickets_eliminados');</script>";

        // 7. Finalmente, eliminar el item
        $query = "DELETE FROM items WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $id);
        $stmt->execute();

        // Verificar si la eliminación del item afectó alguna fila
        if ($stmt->rowCount() === 0) {
            throw new Exception("No se pudo eliminar el item. Es posible que haya sido eliminado en otro proceso.");
        }

        $conn->commit();
        echo "<script>console.log('Transacción confirmada. Item eliminado exitosamente.');</script>";
        $success = "Item '" . htmlspecialchars($item_info['nombre']) . "' eliminado correctamente (se eliminaron $historial_eliminado registros de historial antiguo, $movimientos_eliminados movimientos y $tickets_eliminados tickets).";

    } catch (PDOException $e) {
        $conn->rollBack();
        echo "<script>console.error('ERROR de Base de Datos al eliminar: " . $e->getMessage() . "');</script>";
        
        // Mensaje específico para el error de restricción de clave foránea
        if ($e->getCode() == 23000 || strpos($e->getMessage(), 'constraint') !== false) {
            $error = "No se puede eliminar el item porque tiene registros asociados (historial, movimientos, tickets). Contacte al administrador para una eliminación segura.";
        } else {
            $error = "Error de base de datos al eliminar el item: " . $e->getMessage();
        }

    } catch (Exception $e) {
        if (isset($conn) && $conn->inTransaction()) {
            $conn->rollBack();
            echo "<script>console.error('ERROR General al eliminar. Transacción revertida: " . $e->getMessage() . "');</script>";
        }
        $error = "Error al eliminar el item: " . $e->getMessage();
    }
}

end_deletion_processing:
// Punto de salida limpio

// Obtener filtros
 $filtro_categoria = isset($_GET['categoria']) ? $_GET['categoria'] : '';
 $filtro_criticos = isset($_GET['filtro']) && $_GET['filtro'] == 'criticos';
 $busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : ''; 

// Construir consulta con filtros
 $query = "SELECT i.*, c.nombre as categoria_nombre, s.nombre as sede_nombre 
          FROM items i 
          LEFT JOIN categorias c ON i.categoria_id = c.id 
          LEFT JOIN sedes s ON i.sede_id = s.id
          WHERE i.sede_id = :sede_id"; // Filtrar por sede seleccionada

 $params = [':sede_id' => $sede_seleccionada];

if ($filtro_categoria) {
    $query .= " AND i.categoria_id = :categoria_id";
    $params[':categoria_id'] = $filtro_categoria;
}

if ($filtro_criticos) {
    $query .= " AND i.stock <= i.stock_minimo AND i.stock_minimo > 0 AND i.necesita_restock = 1";
}

// Aplicar búsqueda
if (!empty($busqueda)) {
    $query .= " AND (i.nombre LIKE :busqueda OR i.descripcion LIKE :busqueda OR c.nombre LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

 $query .= " ORDER BY i.nombre";

 $database = new Database();
 $conn = $database->getConnection();
 $stmt = $conn->prepare($query);

// Vincular parámetros
foreach ($params as $param => $value) {
    $stmt->bindValue($param, $value);
}

 $stmt->execute();
 $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

 $categorias = $functions->obtenerCategorias();

// Obtener información de la sede seleccionada
 $query = "SELECT * FROM sedes WHERE id = :id";
 $stmt = $conn->prepare($query);
 $stmt->bindParam(':id', $sede_seleccionada);
 $stmt->execute();
 $sede_info = $stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Items - <?php echo SITE_NAME; ?></title>
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
    .stock-critico {
        background-color: #fff3cd !important;
    }
    .no-restock {
        opacity: 0.7;
        background-color: #f8f9fa !important;
    }
    .no-restock .text-danger {
        color: #6c757d !important;
    }
    
    /* Estilos para las tarjetas de sedes */
    .sede-card {
        transition: transform 0.3s;
        cursor: pointer;
        height: 100%;
    }
    
    .sede-card:hover {
        transform: translateY(-5px);
        box-shadow: 0 10px 20px rgba(0,0,0,0.1);
    }
    
    .sede-active {
        border: 2px solid #003a5d;
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
                    <h1 class="h2">Gestión de Items - <span class="text-primary"><?php echo htmlspecialchars($sede_info['nombre']); ?></span></h1>
			<div>
			    <button type="button" class="btn btn-outline-secondary me-2" onclick="window.location.href='items.php?cambiar_sede=1'">
			        <i class="bi bi-house"></i> Cambiar Sede
			    </button>
			    <a href="historial_items.php?sede_id=<?php echo $sede_seleccionada; ?>" class="btn btn-outline-info me-2">
			        <i class="bi bi-clock-history"></i> Historial de <?php echo htmlspecialchars($sede_info['nombre']); ?>
			    </a>
			    <?php if ($permiso_crear_item): ?>
			    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearItem">
			        <i class="bi bi-plus-circle"></i> Nuevo Item
			    </button>
			    <?php endif; ?>
			</div>
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
        <!-- Filtros y búsqueda -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" action="">
                    <div class="row">
                        <div class="col-md-3">
                            <label for="categoria" class="form-label">Filtrar por categoría</label>
                            <select class="form-select" id="categoria" name="categoria">
                                <option value="">Todas las categorías</option>
                                <?php foreach ($categorias as $categoria): ?>
                                <option value="<?php echo $categoria['id']; ?>" <?php echo $filtro_categoria == $categoria['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($categoria['nombre']); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label for="busqueda" class="form-label">Buscar</label>
                            <input type="text" class="form-control" id="busqueda" name="busqueda" 
                                   placeholder="Buscar por nombre, descripción..." 
                                   value="<?php echo htmlspecialchars($busqueda ?? ''); ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Filtros rápidos</label><br>
                            <a href="?filtro=criticos&sede_id=<?php echo $sede_seleccionada; ?>" class="btn btn-sm btn-outline-danger">Items Críticos</a>
                            <a href="?sede_id=<?php echo $sede_seleccionada; ?>" class="btn btn-sm btn-outline-secondary">Limpiar Filtros</a>
                        </div>
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                        </div>
                    </div>
                    <input type="hidden" name="sede_id" value="<?php echo $sede_seleccionada; ?>">
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
                                        <th>Categoría</th>
                                        <th>Stock</th>
                                        <th>Mínimo</th>
                                        <th>Tipo</th>
                                        <th>Restock</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($items) > 0): ?>
                                        <?php foreach ($items as $item): ?>
                                        <tr class="<?php 
                                            echo ($item['stock'] <= $item['stock_minimo'] && $item['necesita_restock'] == 1) ? 'table-warning ' : ''; 
                                            echo ($item['necesita_restock'] == 0) ? 'no-restock' : '';
                                        ?>">
                                            <td><?php echo htmlspecialchars($item['nombre']); ?></td>
                                            <td><?php echo htmlspecialchars($item['categoria_nombre']); ?></td>
                                            <td>
                                                <span class="<?php echo ($item['stock'] <= $item['stock_minimo'] && $item['necesita_restock'] == 1) ? 'text-danger fw-bold' : ''; ?>">
                                                    <?php echo $item['stock']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo $item['stock_minimo']; ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $item['tipo'] == 'equipo' ? 'info' : 'secondary'; ?>">
                                                    <?php echo ucfirst($item['tipo']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $item['necesita_restock'] ? 'success' : 'secondary'; ?>">
                                                    <?php echo $item['necesita_restock'] ? 'Activo' : 'Cancelado'; ?>
                                                </span>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalEditarStock<?php echo $item['id']; ?>">
                                                    <i class="bi bi-pencil"></i> Editar Stock
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-success" data-bs-toggle="modal" data-bs-target="#modalMoverItem<?php echo $item['id']; ?>">
                                                    <i class="bi bi-arrow-left-right"></i> Mover
                                                </button>
                                                <?php if ($permiso_eliminar_item): ?>
                                                <a href="?eliminar=<?php echo $item['id']; ?>&sede_id=<?php echo $sede_seleccionada; ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Está seguro de eliminar este item?')">
                                                    <i class="bi bi-trash"></i> Eliminar
                                                </a>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center">No hay items registrados en esta sede</td>
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
    
    <!-- Modal para crear item -->
    <div class="modal fade" id="modalCrearItem" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Crear Nuevo Item</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label for="nombre" class="form-label">Nombre del item</label>
                            <input type="text" class="form-control" id="nombre" name="nombre" required>
                        </div>
                        <div class="mb-3">
                            <label for="categoria_id" class="form-label">Categoría</label>
                            <select class="form-select" id="categoria_id" name="categoria_id" required>
                                <option value="">Seleccione una categoría</option>
                                <?php foreach ($categorias as $categoria): ?>
                                <option value="<?php echo $categoria['id']; ?>"><?php echo htmlspecialchars($categoria['nombre']); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="sede_id" class="form-label">Sede</label>
                            <select class="form-select" id="sede_id" name="sede_id" required>
                                <?php 
                                // Obtener el ID de la sede Tequendama
                                $tequendama_id = null;
                                foreach ($sedes as $sede) {
                                    if (stripos($sede['nombre'], 'tequendama') !== false) {
                                        $tequendama_id = $sede['id'];
                                        break;
                                    }
                                }
                                
                                // Solo mostrar la opción de Tequendama
                                if ($tequendama_id): ?>
                                <option value="<?php echo $tequendama_id; ?>" selected>
                                    Tequendama
                                </option>
                                <?php endif; ?>
                            </select>
                            <div class="form-text">Los items solo se pueden crear en la sede Tequendama y se replicarán automáticamente en las demás sedes con stock 0.</div>
                        </div>
                        <input type="hidden" name="tipo" value="consumible">

                        <div class="mb-3">
                            <label for="descripcion" class="form-label">Descripción</label>
                            <textarea class="form-control" id="descripcion" name="descripcion" rows="3"></textarea>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="stock" class="form-label">Stock inicial</label>
                                    <input type="number" class="form-control" id="stock" name="stock" value="0" min="0" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="stock_minimo" class="form-label">Stock mínimo</label>
                                    <input type="number" class="form-control" id="stock_minimo" name="stock_minimo" value="5" min="0" required>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="crear_item" class="btn btn-primary">Crear Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modales para editar stock -->
    <?php foreach ($items as $item): ?>
    <div class="modal fade" id="modalEditarStock<?php echo $item['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Stock - <?php echo htmlspecialchars($item['nombre']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Stock actual</label>
                            <p class="form-control-plaintext"><?php echo $item['stock']; ?> unidades</p>
                        </div>
                        <div class="mb-3">
                            <label for="nuevo_stock" class="form-label">Nuevo stock</label>
                            <input type="number" class="form-control" id="nuevo_stock" name="nuevo_stock" value="<?php echo $item['stock']; ?>" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="motivo" class="form-label">Motivo del ajuste</label>
                            <textarea class="form-control" id="motivo" name="motivo" rows="3" required placeholder="Ej: Ajuste de inventario físico, entrada de mercancía, etc."></textarea>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" id="necesita_restock<?php echo $item['id']; ?>" name="necesita_restock" <?php echo $item['necesita_restock'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="necesita_restock<?php echo $item['id']; ?>">
                                Solicitar reestock automático
                            </label>
                            <div class="form-text">Si se desmarca, este item no se mostrará como "stock bajo" aunque esté por debajo del mínimo</div>
                        </div>
                    </div>
			<div class="modal-footer">
			    <button type="button" class="btn btn-outline-info" onclick="verHistorialItem(<?php echo $item['id']; ?>)">
			        <i class="bi bi-clock-history"></i> Ver Historial
			    </button>
			    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
			    <button type="submit" name="actualizar_stock" class="btn btn-primary">Actualizar Stock</button>
			</div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modales para mover items entre sedes -->
    <div class="modal fade" id="modalMoverItem<?php echo $item['id']; ?>" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Mover Item - <?php echo htmlspecialchars($item['nombre']); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="item_id" value="<?php echo $item['id']; ?>">
                    <input type="hidden" name="sede_origen_id" value="<?php echo $sede_seleccionada; ?>">
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Stock actual en <?php echo htmlspecialchars($sede_info['nombre']); ?></label>
                            <p class="form-control-plaintext"><?php echo $item['stock']; ?> unidades</p>
                        </div>
                        <div class="mb-3">
                            <label for="cantidad" class="form-label">Cantidad a mover</label>
                            <input type="number" class="form-control" id="cantidad" name="cantidad" value="1" min="1" max="<?php echo $item['stock']; ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="sede_destino_id" class="form-label">Sede destino</label>
                            <select class="form-select" id="sede_destino_id" name="sede_destino_id" required>
                                <option value="">Seleccione una sede</option>
                                <?php foreach ($sedes as $sede): ?>
                                <?php if ($sede['id'] != $sede_seleccionada): ?>
                                <option value="<?php echo $sede['id']; ?>"><?php echo htmlspecialchars($sede['nombre']); ?></option>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label for="notas" class="form-label">Notas del movimiento</label>
                            <textarea class="form-control" id="notas" name="notas" rows="3" placeholder="Ej: Traslado por necesidades temporales, reabastecimiento, etc."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" name="mover_item" class="btn btn-success">Mover Item</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Activar la pestaña correcta según el filtro aplicado
    document.addEventListener('DOMContentLoaded', function() {
        const filtroEstado = "<?php echo $filtro_estado; ?>";
        
        if (filtroEstado === 'cerrados') {
            const cerradosTab = new bootstrap.Tab(document.getElementById('cerrados-tab'));
            cerradosTab.show();
        } else if (filtroEstado === 'abiertos' || filtroEstado === 'pendiente' || filtroEstado === 'aprobado') {
            const abiertosTab = new bootstrap.Tab(document.getElementById('abiertos-tab'));
            abiertosTab.show();
        }
    });
    // Función para ver historial de un item específico
function verHistorialItem(item_id) {
    window.location.href = 'historial_items.php?item_id=' + item_id;
}
</script>
</body>
</html>