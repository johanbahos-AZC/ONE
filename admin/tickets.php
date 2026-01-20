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
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'tickets', 'ver')) {
    header("Location: portal.php");
    exit();
}

 $functions = new Functions();
 $error = '';
 $success = '';

// Procesar actualización de ticket
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_ticket'])) {
    try {
        $ticket_id = $_POST['ticket_id'];
        $estado = $_POST['estado'];
        $tipo = $_POST['tipo'];
        $notas_admin = trim($_POST['notas_admin']);
        $responsable_id = $_POST['responsable_id'] ?? null;
        
        // Campos para items (pueden venir vacíos)
        $item_id = isset($_POST['item_id']) && !empty($_POST['item_id']) ? $_POST['item_id'] : null;
        $cantidad = isset($_POST['cantidad']) ? (int)$_POST['cantidad'] : 1;
        
        // Campos para intercambios
        $item_devuelto_id = isset($_POST['item_devuelto_id']) && !empty($_POST['item_devuelto_id']) ? $_POST['item_devuelto_id'] : null;
        $mal_estado = isset($_POST['mal_estado']) ? 1 : 0;
        
        $database = new Database();
        $conn = $database->getConnection();
        
        // Obtener información del ticket actual
        $query = "SELECT * FROM tickets WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $ticket_id);
        $stmt->execute();
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket) {
            throw new Exception("Ticket no encontrado con ID: $ticket_id");
        }
        
        // Guardar el estado anterior para comparar
        $estado_anterior = $ticket['estado'];
        $tipo_anterior = $ticket['tipo'];
        
        // Obtener el ID del administrador actual
        $admin_actual_id = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
        
        // Lógica de asignación de responsable (código original)
        $responsable_actual = $ticket['responsable_id'];
        $responsable_formulario = $responsable_id ?? null;

        // Verificar EXACTAMENTE qué valores tenemos
        $es_responsable_actual_invalido = (
            $responsable_actual === null || 
            $responsable_actual === '' || 
            $responsable_actual === '0' || 
            $responsable_actual == 0 ||
            $responsable_actual === false
        );

        // LÓGICA DE ASIGNACIÓN MEJORADA
        if (!empty($responsable_formulario) && $responsable_formulario != '0') {
            // Asignación manual tiene prioridad
            $nuevo_responsable = $responsable_formulario;
        } 
        elseif ($es_responsable_actual_invalido && !empty($admin_actual_id)) {
            // Asignación automática solo si no hay responsable válido
            $nuevo_responsable = $admin_actual_id;
        }
        else {
            // Mantener el actual (puede ser válido o seguir siendo inválido)
            $nuevo_responsable = $responsable_actual;
        }

        // Garantizar que nunca sea NULL para la base de datos
        if (empty($nuevo_responsable) || $nuevo_responsable === '0') {
            if (!empty($admin_actual_id)) {
                $nuevo_responsable = $admin_actual_id;
            } else {
                // Si no hay admin, usar un valor por defecto
                $nuevo_responsable = 0;
            }
        }
        
        // Actualizar el ticket con todos los campos
        $query = "UPDATE tickets SET 
                  estado = :estado, 
                  tipo = :tipo, 
                  notas_admin = :notas_admin, 
                  responsable_id = :responsable_id,
                  item_id = :item_id,
                  cantidad = :cantidad,
                  item_devuelto_id = :item_devuelto_id,
                  mal_estado = :mal_estado
                  WHERE id = :id";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':estado', $estado);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':notas_admin', $notas_admin);
        $stmt->bindParam(':responsable_id', $nuevo_responsable);
        $stmt->bindParam(':item_id', $item_id);
        $stmt->bindParam(':cantidad', $cantidad);
        $stmt->bindParam(':item_devuelto_id', $item_devuelto_id);
        $stmt->bindParam(':mal_estado', $mal_estado);
        $stmt->bindParam(':id', $ticket_id);
        
        if ($stmt->execute()) {
            // Obtener información del empleado para determinar su sede
            $query_emp = "SELECT e.sede_id, s.nombre as sede_nombre 
                          FROM employee e 
                          LEFT JOIN sedes s ON e.sede_id = s.id 
                          WHERE e.CC = :empleado_id";
            $stmt_emp = $conn->prepare($query_emp);
            $stmt_emp->bindParam(':empleado_id', $ticket['empleado_id']);
            $stmt_emp->execute();
            $empleado_info = $stmt_emp->fetch(PDO::FETCH_ASSOC);
            
            $sede_empleado_id = $empleado_info ? $empleado_info['sede_id'] : null;
            $sede_empleado_nombre = $empleado_info ? $empleado_info['sede_nombre'] : 'Sede desconocida';
            
            // Procesar según el tipo de ticket y el estado
            if ($tipo == 'solicitud' && $item_id) {
                // Lógica para solicitudes
                if ($estado == 'aprobado' && $ticket['estado'] != 'aprobado') {
                    // Verificar stock suficiente antes de aprobar
                    $query = "SELECT stock, nombre FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item && $item['stock'] >= $cantidad) {
                        // Disminuir stock por solicitud SOLO EN LA SEDE DEL EMPLEADO
                        $query = "UPDATE items SET stock = stock - :cantidad WHERE id = :item_id AND sede_id = :sede_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':cantidad', $cantidad);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':sede_id', $sede_empleado_id);
                        
                        if ($stmt->execute()) {
                            // Registrar en historial
                            $functions->registrarHistorialItem(
                                $item_id, 
                                $admin_actual_id, 
                                'solicitud_aprobada', 
                                $cantidad, 
                                $item['stock'], 
                                $item['stock'] - $cantidad, 
                                "Solicitud aprobada - Stock reducido: " . $cantidad . " unidades. Item: " . ($item['nombre'] ?? 'N/A'),
                                $sede_empleado_id
                            );
                        }
                    } else {
                        $stock_disponible = $item['stock'] ?? 0;
                        $item_nombre = $item['nombre'] ?? 'Item no encontrado';
                        $error = "❌ Stock insuficiente para aprobar la solicitud. 
                                 Se solicitan: {$cantidad} {$item_nombre}
                                 Stock disponible: {$stock_disponible}";
                        
                        // Rechazar automáticamente el ticket por stock insuficiente
                        $estado = 'rechazado';
                        $notas_admin .= " [RECHAZADO AUTOMÁTICAMENTE: Stock insuficiente. Disponible: {$stock_disponible}, Solicitado: {$cantidad}]";
                    }
                    
                } 
                // Cuando se completa una solicitud aprobada, restar definitivamente del inventario
                elseif ($estado == 'completado' && $ticket['estado'] == 'aprobado' && $ticket['tipo'] == 'solicitud') {
                    // Obtener información del item
                    $query = "SELECT stock FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item) {
                        // Registrar en historial que el item fue entregado definitivamente
                        $functions->registrarHistorialItem(
                            $item_id, 
                            $admin_actual_id, 
                            'solicitud_completada', 
                            $cantidad, 
                            $item['stock'], 
                            $item['stock'], 
                            "Solicitud completada - Items entregados definitivamente: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                            $sede_empleado_id
                        );
                    }
                }
                // Si una solicitud aprobada cambia a rechazado o pendiente, devolver al inventario
                elseif (($estado == 'rechazado' || $estado == 'pendiente') && $ticket['estado'] == 'aprobado' && $ticket['tipo'] == 'solicitud') {
                    // Devolver stock (sumar lo que se había restado)
                    $query = "SELECT stock FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item) {
                        $query = "UPDATE items SET stock = stock + :cantidad WHERE id = :item_id AND sede_id = :sede_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':cantidad', $cantidad);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':sede_id', $sede_empleado_id);
                        $stmt->execute();
                        
                        // Registrar reversión en historial
                        $functions->registrarHistorialItem(
                            $item_id, 
                            $admin_actual_id, 
                            'solicitud_revertida', 
                            $cantidad, 
                            $item['stock'], 
                            $item['stock'] + $cantidad, 
                            "Solicitud revertida (estado: $estado) - Stock repuesto: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                            $sede_empleado_id
                        );
                    }
                }
                
            } elseif ($tipo == 'devolucion' && $item_id) {
                // Lógica para devoluciones
                if ($estado == 'aprobado' && $ticket['estado'] != 'aprobado') {
                    // Aumentar stock por devolución SOLO EN LA SEDE DEL EMPLEADO
                    $query = "SELECT stock, nombre FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item) {
                        $query = "UPDATE items SET stock = stock + :cantidad WHERE id = :item_id AND sede_id = :sede_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':cantidad', $cantidad);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':sede_id', $sede_empleado_id);
                        
                        if ($stmt->execute()) {
                            // Registrar en historial
                            $functions->registrarHistorialItem(
                                $item_id, 
                                $admin_actual_id, 
                                'devolucion_aprobada', 
                                $cantidad, 
                                $item['stock'], 
                                $item['stock'] + $cantidad, 
                                "Devolución aprobada - Stock aumentado: " . $cantidad . " unidades. Item: " . ($item['nombre'] ?? 'N/A'),
                                $sede_empleado_id
                            );
                        }
                    }
                    
                } 
                // Cuando se completa una devolución aprobada, añadir definitivamente al inventario
                elseif ($estado == 'completado' && $ticket['estado'] == 'aprobado' && $ticket['tipo'] == 'devolucion') {
                    // Obtener información del item
                    $query = "SELECT stock FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item) {
                        // Registrar en historial que el item fue devuelto definitivamente
                        $functions->registrarHistorialItem(
                            $item_id, 
                            $admin_actual_id, 
                            'devolucion_completada', 
                            $cantidad, 
                            $item['stock'], 
                            $item['stock'], 
                            "Devolución completada - Items devueltos definitivamente: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                            $sede_empleado_id
                        );
                    }
                }
                // Si una devolución aprobada cambia a rechazado o pendiente, quitar del inventario
                elseif (($estado == 'rechazado' || $estado == 'pendiente') && $ticket['estado'] == 'aprobado' && $ticket['tipo'] == 'devolucion') {
                    // Quitar stock (restar lo que se había sumado)
                    $query = "SELECT stock FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item) {
                        $query = "UPDATE items SET stock = stock - :cantidad WHERE id = :item_id AND sede_id = :sede_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':cantidad', $cantidad);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':sede_id', $sede_empleado_id);
                        $stmt->execute();
                        
                        // Registrar reversión en historial
                        $functions->registrarHistorialItem(
                            $item_id, 
                            $admin_actual_id, 
                            'devolucion_revertida', 
                            $cantidad, 
                            $item['stock'], 
                            $item['stock'] - $cantidad, 
                            "Devolución revertida (estado: $estado) - Stock reducido: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                            $sede_empleado_id
                        );
                    }
                }
                
            } elseif ($tipo == 'intercambio' && $item_id && $item_devuelto_id) {
                // Lógica para intercambios
                if ($estado == 'aprobado' && $ticket['estado'] != 'aprobado') {
                    // Procesar intercambio
                    $transaccion_exitosa = true;
                    $mensajes_error = [];
                    
                    // Verificar stock para el item nuevo
                    $query = "SELECT stock, nombre FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item_nuevo = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if (!$item_nuevo || $item_nuevo['stock'] < $cantidad) {
                        $transaccion_exitosa = false;
                        $stock_disponible = $item_nuevo['stock'] ?? 0;
                        $mensajes_error[] = "Stock insuficiente para el item nuevo: {$item_nuevo['nombre']}. Disponible: {$stock_disponible}, Solicitado: {$cantidad}";
                    }
                    
                    if ($transaccion_exitosa) {
                        // Ejecutar el intercambio
                        try {
                            $conn->beginTransaction();
                            
                            // 1. Aumentar stock del item devuelto (si no está en mal estado) SOLO EN LA SEDE DEL EMPLEADO
                            if (!$mal_estado) {
                                $query = "SELECT stock, nombre FROM items WHERE id = :item_devuelto_id AND sede_id = :sede_id";
                                $stmt = $conn->prepare($query);
                                $stmt->bindParam(':item_devuelto_id', $item_devuelto_id);
                                $stmt->bindParam(':sede_id', $sede_empleado_id);
                                $stmt->execute();
                                $item_devuelto = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                if ($item_devuelto) {
                                    $query = "UPDATE items SET stock = stock + :cantidad WHERE id = :item_devuelto_id AND sede_id = :sede_id";
                                    $stmt = $conn->prepare($query);
                                    $stmt->bindParam(':cantidad', $cantidad);
                                    $stmt->bindParam(':item_devuelto_id', $item_devuelto_id);
                                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                                    $stmt->execute();
                                    
                                    // Historial para item devuelto
                                    $functions->registrarHistorialItem(
                                        $item_devuelto_id, 
                                        $admin_actual_id, 
                                        'intercambio_devolucion', 
                                        $cantidad, 
                                        $item_devuelto['stock'], 
                                        $item_devuelto['stock'] + $cantidad, 
                                        "Intercambio - Item devuelto en buen estado. Stock aumentado: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                                        $sede_empleado_id
                                    );
                                } else {
                                    // Historial para item en mal estado
                                    $functions->registrarHistorialItem(
                                        $item_devuelto_id, 
                                        $admin_actual_id, 
                                        'intercambio_mal_estado', 
                                        $cantidad, 
                                        0, 
                                        0, 
                                        "Intercambio - Item devuelto en mal estado. No se repone stock.",
                                        $sede_empleado_id
                                    );
                                }
                            }
                            
                            // 2. Disminuir stock del item nuevo SOLO EN LA SEDE DEL EMPLEADO
                            $query = "UPDATE items SET stock = stock - :cantidad WHERE id = :item_id AND sede_id = :sede_id";
                            $stmt = $conn->prepare($query);
                            $stmt->bindParam(':cantidad', $cantidad);
                            $stmt->bindParam(':item_id', $item_id);
                            $stmt->bindParam(':sede_id', $sede_empleado_id);
                            $stmt->execute();
                            
                            // Historial para item nuevo
                            $functions->registrarHistorialItem(
                                $item_id, 
                                $admin_actual_id, 
                                'intercambio_solicitud', 
                                $cantidad, 
                                $item_nuevo['stock'], 
                                $item_nuevo['stock'] - $cantidad, 
                                "Intercambio - Item solicitado. Stock reducido: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                                $sede_empleado_id
                            );
                            
                            $conn->commit();
                            
                        } catch (Exception $e) {
                            $conn->rollBack();
                            $transaccion_exitosa = false;
                            $mensajes_error[] = "Error en la transacción de intercambio: " . $e->getMessage();
                        }
                    }
                    
                    if (!$transaccion_exitosa) {
                        $error = "❌ No se pudo completar el intercambio: " . implode("; ", $mensajes_error);
                        // Rechazar automáticamente el ticket
                        $estado = 'rechazado';
                        $notas_admin .= " [RECHAZADO AUTOMÁTICAMENTE: " . implode("; ", $mensajes_error) . "]";
                    }
                }
                // Cuando se completa un intercambio aprobado, confirmar definitivamente los cambios
                elseif ($estado == 'completado' && $ticket['estado'] == 'aprobado' && $ticket['tipo'] == 'intercambio') {
                    // Obtener información de los items
                    $query_nuevo = "SELECT stock FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt_nuevo = $conn->prepare($query_nuevo);
                    $stmt_nuevo->bindParam(':item_id', $item_id);
                    $stmt_nuevo->bindParam(':sede_id', $sede_empleado_id);
                    $stmt_nuevo->execute();
                    $item_nuevo = $stmt_nuevo->fetch(PDO::FETCH_ASSOC);
                    
                    $query_devuelto = "SELECT stock FROM items WHERE id = :item_devuelto_id AND sede_id = :sede_id";
                    $stmt_devuelto = $conn->prepare($query_devuelto);
                    $stmt_devuelto->bindParam(':item_devuelto_id', $item_devuelto_id);
                    $stmt_devuelto->bindParam(':sede_id', $sede_empleado_id);
                    $stmt_devuelto->execute();
                    $item_devuelto = $stmt_devuelto->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item_nuevo) {
                        // Registrar en historial que el item nuevo fue entregado definitivamente
                        $functions->registrarHistorialItem(
                            $item_id, 
                            $admin_actual_id, 
                            'intercambio_completado_entrega', 
                            $cantidad, 
                            $item_nuevo['stock'], 
                            $item_nuevo['stock'], 
                            "Intercambio completado - Item entregado definitivamente: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                            $sede_empleado_id
                        );
                    }
                    
                    if ($item_devuelto && !$mal_estado) {
                        // Registrar en historial que el item devuelto fue devuelto definitivamente
                        $functions->registrarHistorialItem(
                            $item_devuelto_id, 
                            $admin_actual_id, 
                            'intercambio_completado_devolucion', 
                            $cantidad, 
                            $item_devuelto['stock'], 
                            $item_devuelto['stock'], 
                            "Intercambio completado - Item devuelto definitivamente: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                            $sede_empleado_id
                        );
                    }
                }
                // Si un intercambio aprobado cambia a rechazado o pendiente, revertir los cambios
                elseif (($estado == 'rechazado' || $estado == 'pendiente') && $ticket['estado'] == 'aprobado' && $ticket['tipo'] == 'intercambio') {
                    // Revertir intercambio
                    try {
                        $conn->beginTransaction();
                        
                        // Revertir: devolver el stock del item nuevo
                        $query = "SELECT stock FROM items WHERE id = :item_id AND sede_id = :sede_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':sede_id', $sede_empleado_id);
                        $stmt->execute();
                        $item_nuevo = $stmt->fetch(PDO::FETCH_ASSOC);
                        
                        if ($item_nuevo) {
                            $query = "UPDATE items SET stock = stock + :cantidad WHERE id = :item_id AND sede_id = :sede_id";
                            $stmt = $conn->prepare($query);
                            $stmt->bindParam(':cantidad', $cantidad);
                            $stmt->bindParam(':item_id', $item_id);
                            $stmt->bindParam(':sede_id', $sede_empleado_id);
                            $stmt->execute();
                            
                            // Historial para reversión de solicitud
                            $functions->registrarHistorialItem(
                                $item_id, 
                                $admin_actual_id, 
                                'intercambio_solicitud_revertida', 
                                $cantidad, 
                                $item_nuevo['stock'], 
                                $item_nuevo['stock'] + $cantidad, 
                                "Intercambio revertido (estado: $estado) - Stock de item solicitado repuesto: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                                $sede_empleado_id
                            );
                        }
                        
                        // Revertir: quitar el stock del item devuelto (solo si está en buen estado)
                        if (!$ticket['mal_estado']) {
                            $query = "SELECT stock FROM items WHERE id = :item_devuelto_id AND sede_id = :sede_id";
                            $stmt = $conn->prepare($query);
                            $stmt->bindParam(':item_devuelto_id', $item_devuelto_id);
                            $stmt->bindParam(':sede_id', $sede_empleado_id);
                            $stmt->execute();
                            $item_devuelto = $stmt->fetch(PDO::FETCH_ASSOC);
                            
                            if ($item_devuelto) {
                                $query = "UPDATE items SET stock = stock - :cantidad WHERE id = :item_devuelto_id AND sede_id = :sede_id";
                                $stmt = $conn->prepare($query);
                                $stmt->bindParam(':cantidad', $cantidad);
                                $stmt->bindParam(':item_devuelto_id', $item_devuelto_id);
                                $stmt->bindParam(':sede_id', $sede_empleado_id);
                                $stmt->execute();
                                
                                // Historial para reversión de devolución
                                $functions->registrarHistorialItem(
                                    $item_devuelto_id, 
                                    $admin_actual_id, 
                                    'intercambio_devolucion_revertida', 
                                    $cantidad, 
                                    $item_devuelto['stock'], 
                                    $item_devuelto['stock'] - $cantidad, 
                                    "Intercambio revertido (estado: $estado) - Stock de item devuelto reducido: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                                    $sede_empleado_id
                                );
                            }
                        }
                        
                        $conn->commit();
                        
                    } catch (Exception $e) {
                        $conn->rollBack();
                        $error = "Error al revertir los cambios de stock: " . $e->getMessage();
                    }
                }
                        } elseif ($tipo == 'prestamo' && $item_id) {
                // Lógica para préstamos
                if ($estado == 'aprobado' && $ticket['estado'] != 'aprobado') {
                    // Verificar stock suficiente antes de aprobar el préstamo
                    $query = "SELECT stock, nombre FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item && $item['stock'] >= $cantidad) {
                        // Disminuir stock por préstamo SOLO EN LA SEDE DEL EMPLEADO
                        $query = "UPDATE items SET stock = stock - :cantidad WHERE id = :item_id AND sede_id = :sede_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':cantidad', $cantidad);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':sede_id', $sede_empleado_id);
                        
                        if ($stmt->execute()) {
                            // Registrar en historial
                            $functions->registrarHistorialItem(
                                $item_id, 
                                $admin_actual_id, 
                                'prestamo_aprobado', 
                                $cantidad, 
                                $item['stock'], 
                                $item['stock'] - $cantidad, 
                                "Préstamo aprobado - Stock reducido: " . $cantidad . " unidades. Item: " . ($item['nombre'] ?? 'N/A'),
                                $sede_empleado_id
                            );
                            
                            // Generar el vale de préstamo
                            $vale_result = $functions->generarValePrestamo($ticket_id, $ticket['empleado_id'], $item_id, $cantidad, $admin_actual_id);
                            
                            if ($vale_result['success']) {
                                // Guardar el hash en una variable de sesión para abrirlo después de la redirección
                                $_SESSION['vale_prestamo_hash'] = $vale_result['file_hash'];
                                $_SESSION['vale_prestamo_nombre'] = $vale_result['file_name'];
                            } else {
                                $error .= " Error al generar el vale de préstamo: " . $vale_result['error'];
                            }
                        }
                    } else {
                        $stock_disponible = $item['stock'] ?? 0;
                        $item_nombre = $item['nombre'] ?? 'Item no encontrado';
                        $error = "❌ Stock insuficiente para aprobar el préstamo. 
                                 Se solicitan: {$cantidad} {$item_nombre}
                                 Stock disponible: {$stock_disponible}";
                        
                        // Rechazar automáticamente el ticket por stock insuficiente
                        $estado = 'rechazado';
                        $notas_admin .= " [RECHAZADO AUTOMÁTICAMENTE: Stock insuficiente. Disponible: {$stock_disponible}, Solicitado: {$cantidad}]";
                    }
                    
                } 
                // Cuando se completa un préstamo aprobado, sumar el item de vuelta al inventario
                elseif ($estado == 'completado' && $ticket['estado'] == 'aprobado' && $ticket['tipo'] == 'prestamo') {
                    // Obtener información del item
                    $query = "SELECT stock FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item) {
                        // Sumar stock de vuelta (el item fue devuelto)
                        $query = "UPDATE items SET stock = stock + :cantidad WHERE id = :item_id AND sede_id = :sede_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':cantidad', $cantidad);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':sede_id', $sede_empleado_id);
                        $stmt->execute();
                        
                        // Registrar en historial
                        $functions->registrarHistorialItem(
                            $item_id, 
                            $admin_actual_id, 
                            'prestamo_completado', 
                            $cantidad, 
                            $item['stock'], 
                            $item['stock'] + $cantidad, 
                            "Préstamo completado - Item devuelto: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                            $sede_empleado_id
                        );
                    }
                }
                // Si un préstamo aprobado cambia a rechazado o pendiente, devolver al inventario
                elseif (($estado == 'rechazado' || $estado == 'pendiente') && $ticket['estado'] == 'aprobado' && $ticket['tipo'] == 'prestamo') {
                    // Devolver stock (sumar lo que se había restado)
                    $query = "SELECT stock FROM items WHERE id = :item_id AND sede_id = :sede_id";
                    $stmt = $conn->prepare($query);
                    $stmt->bindParam(':item_id', $item_id);
                    $stmt->bindParam(':sede_id', $sede_empleado_id);
                    $stmt->execute();
                    $item = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($item) {
                        $query = "UPDATE items SET stock = stock + :cantidad WHERE id = :item_id AND sede_id = :sede_id";
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':cantidad', $cantidad);
                        $stmt->bindParam(':item_id', $item_id);
                        $stmt->bindParam(':sede_id', $sede_empleado_id);
                        $stmt->execute();
                        
                        // Registrar reversión en historial
                        $functions->registrarHistorialItem(
                            $item_id, 
                            $admin_actual_id, 
                            'prestamo_revertido', 
                            $cantidad, 
                            $item['stock'], 
                            $item['stock'] + $cantidad, 
                            "Préstamo revertido (estado: $estado) - Stock repuesto: " . $cantidad . " unidades. Ticket #" . $ticket_id,
                            $sede_empleado_id
                        );
                    }
                }
            } 
            // Enviar correo de notificación si hubo cambios
            if ($estado != $estado_anterior || !empty($notas_admin)) {
                $functions->enviarNotificacionTicket($ticket_id, $estado_anterior, $estado, $notas_admin);
            }
            
            $success = "Ticket actualizado correctamente";
            
            // Recargar la página para ver los cambios
            header("Location: tickets.php");
            exit();
            
        } else {
            $error = "Error al actualizar el ticket";
        }
        
    } catch (Exception $e) {
        $error = "Error al procesar el ticket: " . $e->getMessage();
    }
}

// Obtener parámetros de filtrado y búsqueda
 $filtro_estado = $_GET['estado'] ?? 'todos';
 $filtro_tipo = $_GET['tipo'] ?? 'todos';
 $busqueda = $_GET['busqueda'] ?? '';

// Construir consulta base optimizada
 $database = new Database();
 $conn = $database->getConnection();

 $query = "SELECT t.*, i.nombre as item_nombre, 
                 CASE 
                     WHEN t.responsable_id IS NULL OR t.responsable_id = 0 THEN 'Soporte'
                     ELSE CONCAT(e.first_Name, ' ', e.first_LastName) 
                 END as responsable_nombre,
                 t.responsable_id,
                 t.imagen_ruta,
                 emp_sede.sede_id as empleado_sede_id,
                 emp_sede.sede_nombre as empleado_sede_nombre
          FROM tickets t 
          LEFT JOIN items i ON t.item_id = i.id 
          LEFT JOIN employee e ON t.responsable_id = e.id
          LEFT JOIN (
              SELECT e.CC, e.sede_id, s.nombre as sede_nombre
              FROM employee e 
              LEFT JOIN sedes s ON e.sede_id = s.id
          ) emp_sede ON t.empleado_id = emp_sede.CC
          WHERE 1=1";

 $params = [];

// Aplicar filtros
if ($filtro_estado != 'todos') {
    if ($filtro_estado == 'abiertos') {
        $query .= " AND (t.estado = 'pendiente' OR t.estado = 'aprobado')";
    } elseif ($filtro_estado == 'cerrados') {
        $query .= " AND (t.estado = 'rechazado' OR t.estado = 'completado')";
    } else {
        $query .= " AND t.estado = :estado";
        $params[':estado'] = $filtro_estado;
    }
}

if ($filtro_tipo != 'todos') {
    $query .= " AND t.tipo = :tipo";
    $params[':tipo'] = $filtro_tipo;
}

// Aplicar búsqueda
if (!empty($busqueda)) {
    $query .= " AND (t.empleado_nombre LIKE :busqueda OR i.nombre LIKE :busqueda OR t.empleado_id LIKE :busqueda OR t.asunto LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
}

 $query .= " ORDER BY t.creado_en DESC";

// Preparar y ejecutar consulta
 $stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
 $stmt->execute();
 $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Contar tickets por estado para las pestañas
 $query_contar = "SELECT 
    SUM(CASE WHEN estado IN ('pendiente', 'aprobado') THEN 1 ELSE 0 END) as abiertos,
    SUM(CASE WHEN estado IN ('rechazado', 'completado') THEN 1 ELSE 0 END) as cerrados,
    COUNT(*) as total
    FROM tickets";
 $stmt_contar = $conn->prepare($query_contar);
 $stmt_contar->execute();
 $contadores = $stmt_contar->fetch(PDO::FETCH_ASSOC);

// Obtener lista de administradores para los modales (optimizado)
 $admin_query = "SELECT id, CONCAT(first_Name, ' ', first_LastName) as nombre 
               FROM employee 
               WHERE role IN ('administrador', 'it') 
               ORDER BY nombre";
 $admin_stmt = $conn->prepare($admin_query);
 $admin_stmt->execute();
 $administradores = $admin_stmt->fetchAll(PDO::FETCH_ASSOC);

// Preparar información de items para los modales (optimizado - sin bucles)
 $items_por_sede = [];

// Extraer sedes únicas de los tickets de una sola vez (versión segura)
 $sedes_ids = [];
foreach ($tickets as $ticket) {
    if (!empty($ticket['empleado_sede_id']) && is_numeric($ticket['empleado_sede_id'])) {
        $sedes_ids[] = $ticket['empleado_sede_id'];
    }
}

// Eliminar duplicados y reindexar
 $sedes_ids = array_unique($sedes_ids);
 $sedes_ids = array_values($sedes_ids); // Reindexar para que los índices sean consecutivos

if (!empty($sedes_ids)) {
    try {
        // Crear placeholders seguros
        $placeholders = str_repeat('?,', count($sedes_ids) - 1) . '?';
        
        $items_query = "SELECT i.id, i.nombre, i.stock, i.sede_id, s.nombre as sede_nombre 
                       FROM items i 
                       LEFT JOIN sedes s ON i.sede_id = s.id 
                       WHERE i.sede_id IN ($placeholders) AND i.stock > 0 
                       ORDER BY i.sede_id, i.nombre";
        $items_stmt = $conn->prepare($items_query);
        
        // Vincular parámetros de forma segura
        foreach ($sedes_ids as $i => $sede_id) {
            $items_stmt->bindValue($i + 1, $sede_id, PDO::PARAM_INT);
        }
        
        $items_stmt->execute();
        $items_todos = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Agrupar items por sede
        foreach ($items_todos as $item) {
            $items_por_sede[$item['sede_id']][] = $item;
        }
        
    } catch (Exception $e) {
        // Si hay error, usar el método original como fallback
        error_log("Error en consulta optimizada de items: " . $e->getMessage());
        
        // Método original como fallback (pero optimizado)
        $items_por_sede = [];
        $procesadas_sedes = [];
        
        foreach ($tickets as $ticket) {
            if (!empty($ticket['empleado_sede_id']) && !in_array($ticket['empleado_sede_id'], $procesadas_sedes)) {
                $sede_id = $ticket['empleado_sede_id'];
                $procesadas_sedes[] = $sede_id;
                
                $items_query = "SELECT i.id, i.nombre, i.stock, s.nombre as sede_nombre 
                               FROM items i 
                               LEFT JOIN sedes s ON i.sede_id = s.id 
                               WHERE i.sede_id = :sede_id AND i.stock > 0 
                               ORDER BY i.nombre";
                $items_stmt = $conn->prepare($items_query);
                $items_stmt->bindParam(':sede_id', $sede_id, PDO::PARAM_INT);
                $items_stmt->execute();
                $items_por_sede[$sede_id] = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
        }
    }
}

// Determinar qué tab está activo basado en el filtro
if ($filtro_estado == 'cerrados') {
    $abiertosActive = '';
    $cerradosActive = 'active';
    $abiertosPaneActive = '';
    $cerradosPaneActive = 'show active';
} else {
    // Para 'abiertos', 'pendiente', 'aprobado', 'todos' o cualquier otro valor
    $abiertosActive = 'active';
    $cerradosActive = '';
    $abiertosPaneActive = 'show active';
    $cerradosPaneActive = '';
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Tickets - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
    .nav-tabs .nav-link {
        color: #495057 !important;
        font-weight: normal;
        padding-right: 35px !important;
        display: inline-flex !important;
        align-items: center !important;
        justify-content: center !important;
        min-width: 150px;
        white-space: nowrap;
    }

    .nav-tabs .nav-link.active {
        color: #495057 !important;
        font-weight: bold;
        background-color: #fff;
        border-color: #dee2e6 #dee2e6 #fff;
    }
    
    .nav-tabs .nav-link:not(.active) {
        color: #6c757d !important;
    }
    
    .badge-tab {
        font-size: 0.7rem;
        margin-left: 8px;
        position: relative;
        top: -1px;
    }
    
    .filter-card {
        background-color: #f8f9fa;
        border: 1px solid #dee2e6;
        border-radius: 0.375rem;
    }
    
    .badge-correo {
        background-color: #003a5d !important;
    }
    
    /* Asegurar que el texto sea siempre visible */
    .nav-link {
        color: inherit !important;
        opacity: 1 !important;
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
    
    .badge.bg-dark {
        background-color: #353132 !important;
    }
    
    /* BOTONES EN MODALES */
    .modal .btn-primary {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
    }
    
    .modal .btn-primary:hover {
        background-color: #002b47 !important;
        border-color: #002b47 !important;
    }
    
    .modal .btn-secondary {
        background-color: #6c757d !important;
        border-color: #6c757d !important;
    }
    
    .modal .btn-secondary:hover {
        background-color: #5a6268 !important;
        border-color: #545b62 !important;
    }
    
    /* BADGES EN MODALES */
    .modal .badge.bg-primary {
        background-color: #003a5d !important;
    }
    
    .modal .badge.bg-success {
        background-color: #198754 !important;
    }
    
    .modal .badge.bg-danger {
        background-color: #be1622 !important;
    }
    
    .modal .badge.bg-warning {
        background-color: #ffc107 !important;
        color: #353132 !important;
    }
    
    /* BOTONES DE FILTRO Y BÚSQUEDA */
    .filter-card .btn-primary {
        background-color: #003a5d !important;
        border-color: #003a5d !important;
    }
    
    .filter-card .btn-primary:hover {
        background-color: #002b47 !important;
        border-color: #002b47 !important;
    }
    
    /* BOTONES EN HEADER */
    .btn-toolbar .btn-outline-secondary {
        border-color: #6c757d !important;
        color: #6c757d !important;
    }
    
    .btn-toolbar .btn-outline-secondary:hover {
        background-color: #6c757d !important;
        border-color: #6c757d !important;
        color: white !important;
    }
    
    /* BADGES EN TABS */
    .badge-tab.bg-primary {
        background-color: #003a5d !important;
    }
    
    .badge-tab.bg-secondary {
        background-color: #6c757d !important;
    }
    
    /* BADGE CORREO ESPECIAL */
    .badge-correo {
        background-color: #5c36b2 !important;
    }
    
    /* HOVER EN FILAS DE LA TABLA */
    .table-hover tbody tr:hover {
        background-color: #33617e !important;
        color: white !important;
    }
    
    /* Estilos específicos para esta página */
    .equipo-info td {
        padding: 8px 12px;
        border-bottom: 1px solid #eee;
    }
    .equipo-info td:first-child {
        font-weight: bold;
        width: 30%;
        color: #555;
    }
    .categoria-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .categoria-row:hover {
        background-color: #f8f9fa;
    }
    
    /* Estilos para drag and drop */
    .border-rounded {
        transition: all 0.3s ease;
        border: 2px dashed #ccc;
    }
    .border-rounded:hover {
        border-color: #2196F3;
        background-color: #e8f4fd;
    }
    .border-rounded.drag-over {
        background-color: #e3f2fd !important;
        border-color: #2196F3 !important;
        border-style: solid;
    }
    #vista-previa {
        transition: all 0.3s ease;
    }
    .img-thumbnail {
        max-width: 100%;
        height: auto;
        cursor: pointer;
        transition: transform 0.2s;
    }
    .img-thumbnail:hover {
        transform: scale(1.02);
        box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    }
    .search-container {
        position: relative;
    }
    .search-container .btn {
        border-top-left-radius: 0;
        border-bottom-left-radius: 0;
    }
    .search-container .form-control {
        border-top-right-radius: 0;
        border-bottom-right-radius: 0;
    }
    .pagination {
        margin: 0;
    }
    .page-item.active .page-link {
        background-color: #003a5d;
        border-color: #003a5d;
    }
    .table-responsive {
        min-height: 400px;
    }
    
    /* Estilos para modales de imágenes */
    .modal-img-ampliada .modal-dialog {
        max-width: max-content !important;
        margin: auto;
    }
    .modal-img-ampliada .modal-content {
        background-color: transparent !important;
        border: none !important;
    }
    .modal-img-ampliada .modal-body {
        padding: 0;
        text-align: center;
    }
    .modal-img-ampliada img {
        max-height: 80vh;
        max-width: 100%;
        object-fit: contain;
    }
    .modal-img-ampliada {
        z-index: 1060 !important;
    }
    .modal-img-ampliada .btn-close {
        background-color: white !important;
        opacity: 0.9 !important;
        border: 2px solid #ccc;
        padding: 10px !important;
        box-shadow: 0 2px 5px rgba(0,0,0,0.3);
    }
    .modal-img-ampliada .btn-close:hover {
        opacity: 1 !important;
        background-color: #f8f9fa !important;
    }
    
/* Loader personalizado */
.page-loader {
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(255, 255, 255, 0.95);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 9999;
    backdrop-filter: blur(3px);
}

.loader-container {
    text-align: center;
    max-width: 300px;
    padding: 30px;
    background: white;
    border-radius: 15px;
    box-shadow: 0 10px 30px rgba(0, 58, 93, 0.2);
    border: 1px solid rgba(0, 58, 93, 0.1);
}

/* CSS para el GIF del loader */
.loader-gif {
    display: block;
    margin: 0 auto 20px; /* Centrado y espacio debajo */
    width: 80px;        /* Ancho del GIF */
    height: 80px;       /* Alto del GIF */
    object-fit: contain; /* Asegura que el GIF no se distorsione */
}

.loader-text h5 {
    color: #003a5d;
    font-weight: 600;
    margin-bottom: 8px;
}

.loader-text p {
    color: #6c757d;
    font-size: 14px;
    margin-bottom: 0;
}

/* Ocultar el loader cuando la página está lista */
.page-loader.hidden {
    opacity: 0;
    visibility: hidden;
    transition: opacity 0.5s ease, visibility 0.5s ease;
}

/* Mostrar el contenido principal cuando está listo */
#main-content {
    opacity: 0.3;
    transition: opacity 0.5s ease;
}

#main-content.ready {
    opacity: 1 !important;
}

/* Para el sidebar específicamente */
.sidebar {
    transition: all 0.3s ease;
}

.sidebar.loading {
    opacity: 0.3;
    pointer-events: none;
}

.sidebar.ready {
    opacity: 1 !important;
    pointer-events: auto;
}
</style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <!-- Loader personalizado -->
<div id="page-loader" class="page-loader">
    <div class="loader-container">
        <img src="../assets/images/loader.gif" alt="Cargando..." class="loader-gif">
        <div class="loader-text">
            <h5>Cargando tickets...</h5>
            <p>Por favor espera un momento</p>
            <div class="progress mt-2" style="height: 4px;">
                <div class="progress-bar progress-bar-striped progress-bar-animated" 
                     role="progressbar" 
                     style="width: 100%; background-color: #003a5d;">
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Contenido principal (con opacidad reducida mientras carga) -->
<div id="main-content" style="opacity: 0.3; transition: opacity 0.3s ease;">
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestión de Tickets</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <a href="procesar_correos.php" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-envelope-check"></i> Procesar Correos
                            </a>
                        </div>
                    </div>
                </div>
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Filtros y búsqueda -->
                <div class="card filter-card mb-4">
                    <div class="card-body">
                        <form method="GET" action="">
                            <div class="row">
                                <div class="col-md-3">
                                    <label for="filtro_estado" class="form-label">Estado</label>
                                    <select class="form-select" id="filtro_estado" name="estado">
                                        <option value="todos" <?php echo $filtro_estado == 'todos' ? 'selected' : ''; ?>>Todos los estados</option>
                                        <option value="abiertos" <?php echo $filtro_estado == 'abiertos' ? 'selected' : ''; ?>>Abiertos (Pendiente/Aprobado)</option>
                                        <option value="cerrados" <?php echo $filtro_estado == 'cerrados' ? 'selected' : ''; ?>>Cerrados (Rechazado/Completado)</option>
                                        <option value="pendiente" <?php echo $filtro_estado == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                        <option value="aprobado" <?php echo $filtro_estado == 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                                        <option value="rechazado" <?php echo $filtro_estado == 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                                        <option value="completado" <?php echo $filtro_estado == 'completado' ? 'selected' : ''; ?>>Completado</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label for="filtro_tipo" class="form-label">Tipo</label>
                                    <select class="form-select" id="filtro_tipo" name="tipo">
                                        <option value="todos" <?php echo $filtro_tipo == 'todos' ? 'selected' : ''; ?>>Todos los tipos</option>
                                        <option value="solicitud" <?php echo $filtro_tipo == 'solicitud' ? 'selected' : ''; ?>>Solicitud</option>
                                        <option value="devolucion" <?php echo $filtro_tipo == 'devolucion' ? 'selected' : ''; ?>>Devolución</option>
                                        <option value="intercambio" <?php echo $filtro_tipo == 'intercambio' ? 'selected' : ''; ?>>Intercambio</option>
                                        <option value="email" <?php echo $filtro_tipo == 'email' ? 'selected' : ''; ?>>Correo</option>
                                        <option value="onboarding" <?php echo $filtro_tipo == 'onboarding' ? 'selected' : ''; ?>>OnBoarding</option>
                                        <option value="prestamo" <?php echo $filtro_tipo == 'prestamo' ? 'selected' : ''; ?>>Préstamo</option>
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label for="busqueda" class="form-label">Buscar</label>
                                    <input type="text" class="form-control" id="busqueda" name="busqueda" placeholder="Buscar por empleado, item o asunto..." value="<?php echo htmlspecialchars($busqueda); ?>">
                                </div>
                                <div class="col-md-2 d-flex align-items-end">
                                    <button type="submit" class="btn btn-primary w-100">Filtrar</button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Tabs para tickets abiertos y cerrados -->
                <ul class="nav nav-tabs" id="ticketsTab" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $abiertosActive; ?>" id="abiertos-tab" data-bs-toggle="tab" data-bs-target="#abiertos" type="button" role="tab">
                            Tickets Abiertos <span class="badge bg-primary badge-tab"><?php echo $contadores['abiertos']; ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link <?php echo $cerradosActive; ?>" id="cerrados-tab" data-bs-toggle="tab" data-bs-target="#cerrados" type="button" role="tab">
                            Tickets Cerrados <span class="badge bg-secondary badge-tab"><?php echo $contadores['cerrados']; ?></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="ticketsTabContent">
                    <!-- Tab de tickets abiertos -->
                    <div class="tab-pane fade <?php echo $abiertosPaneActive; ?>" id="abiertos" role="tabpanel">
                        <div class="card mt-3">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Empleado</th>
                                                <th>Item/Asunto</th>
                                                <th>Tipo</th>
                                                <th>Responsable</th>
                                                <th>Estado</th>
                                                <th>Fecha</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $tickets_abiertos = array_filter($tickets, function($ticket) {
                                                return in_array($ticket['estado'], ['pendiente', 'aprobado']);
                                            });
                                            ?>
                                            
                                            <?php if (count($tickets_abiertos) > 0): ?>
                                                <?php foreach ($tickets_abiertos as $ticket): ?>
                                                <tr style="cursor: pointer;" onclick="abrirModalTicket(<?php echo $ticket['id']; ?>)">
                                                    <td>#<?php echo $ticket['id']; ?></td>
                                                    <td><?php echo htmlspecialchars($ticket['empleado_nombre']); ?><br><small class="text-muted"><?php echo $ticket['empleado_id']; ?></small></td>
                                                    <td>
                                                    <?php 
                                                    if ($ticket['tipo'] == 'email') {
                                                        echo htmlspecialchars($ticket['asunto'] ?? 'Sin asunto');
                                                    } elseif ($ticket['tipo'] == 'onboarding') {
                                                        echo "OnBoarding " . htmlspecialchars($ticket['empleado_nombre']);
                                                    } else {
                                                        echo htmlspecialchars($ticket['item_nombre']);
                                                    }
                                                    ?>
                                                    </td>
                                                    <td>
                                                    <?php 
                                                    $badge_class = [
                                                        'solicitud' => 'bg-primary',
                                                        'devolucion' => 'bg-info',
                                                        'intercambio' => 'bg-warning',
                                                        'email' => 'badge-correo',
                                                        'onboarding' => 'bg-success',
                                                        'prestamo' => 'bg-dark'
                                                    ];
                                                    
                                                    // Si el tipo no está en la lista, usar 'ticket' como valor por defecto
                                                    $tipo_mostrar = $ticket['tipo'];
                                                    if (!array_key_exists($ticket['tipo'], $badge_class)) {
                                                        $tipo_mostrar = 'ticket';
                                                        $badge_class[$ticket['tipo']] = 'bg-secondary';
                                                    }
                                                    
                                                    if ($ticket['tipo'] == 'onboarding') {
                                                        $tipo_mostrar = 'OnBoarding ';
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $badge_class[$ticket['tipo']]; ?>"><?php echo ucfirst($tipo_mostrar); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        if (!empty($ticket['responsable_id']) && $ticket['responsable_id'] != '0' && $ticket['responsable_id'] != null) {
                                                            echo htmlspecialchars($ticket['responsable_nombre']);
                                                        } else {
                                                            echo $ticket['estado'] == 'pendiente' ? 'Soporte' : 'Sin asignar';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = [
                                                            'pendiente' => 'bg-warning',
                                                            'aprobado' => 'bg-success',
                                                            'rechazado' => 'bg-danger',
                                                            'completado' => 'bg-info'
                                                        ][$ticket['estado']] ?? 'bg-secondary';
                                                        ?>
                                                        <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($ticket['estado']); ?></span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($ticket['creado_en'])); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4">No hay tickets abiertos</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Tab de tickets cerrados -->
                    <div class="tab-pane fade <?php echo $cerradosPaneActive; ?>" id="cerrados" role="tabpanel">
                        <div class="card mt-3">
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped table-hover">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Empleado</th>
                                                <th>Item/Asunto</th>
                                                <th>Tipo</th>
                                                <th>Responsable</th>
                                                <th>Estado</th>
                                                <th>Fecha</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php 
                                            $tickets_cerrados = array_filter($tickets, function($ticket) {
                                                return in_array($ticket['estado'], ['rechazado', 'completado']);
                                            });
                                            ?>
                                            
                                            <?php if (count($tickets_cerrados) > 0): ?>
                                                <?php foreach ($tickets_cerrados as $ticket): ?>
                                                <tr style="cursor: pointer;" onclick="abrirModalTicket(<?php echo $ticket['id']; ?>)">
                                                    <td>#<?php echo $ticket['id']; ?></td>
                                                    <td><?php echo htmlspecialchars($ticket['empleado_nombre']); ?><br><small class="text-muted"><?php echo $ticket['empleado_id']; ?></small></td>
                                                    <td>
                                                    <?php 
                                                    if ($ticket['tipo'] == 'email') {
                                                        echo htmlspecialchars($ticket['asunto'] ?? 'Sin asunto');
                                                    } elseif ($ticket['tipo'] == 'onboarding') {
                                                        echo "OnBoarding " . htmlspecialchars($ticket['empleado_nombre']);
                                                    } else {
                                                        echo htmlspecialchars($ticket['item_nombre']);
                                                    }
                                                    ?>
                                                    </td>
                                                    <td>
                                                    <?php 
                                                     $badge_class = [
                                                        'solicitud' => 'bg-primary',
                                                        'devolucion' => 'bg-info',
                                                        'intercambio' => 'bg-warning',
                                                        'email' => 'badge-correo',
                                                        'onboarding' => 'bg-success',
                                                        'prestamo' => 'bg-dark'
                                                    ];
                                                    
                                                    // Si el tipo no está en la lista, usar 'ticket' como valor por defecto
                                                    $tipo_mostrar = $ticket['tipo'];
                                                    if (!array_key_exists($ticket['tipo'], $badge_class)) {
                                                        $tipo_mostrar = 'ticket';
                                                        $badge_class[$ticket['tipo']] = 'bg-secondary';
                                                    }
                                                    
                                                    if ($ticket['tipo'] == 'onboarding') {
                                                        $tipo_mostrar = 'OnBoarding ';
                                                    }
                                                    ?>
                                                    <span class="badge <?php echo $badge_class[$ticket['tipo']]; ?>"><?php echo ucfirst($tipo_mostrar); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        if (!empty($ticket['responsable_id']) && $ticket['responsable_id'] != '0' && $ticket['responsable_id'] != null) {
                                                            echo htmlspecialchars($ticket['responsable_nombre']);
                                                        } else {
                                                            echo $ticket['estado'] == 'pendiente' ? 'Soporte' : 'Sin asignar';
                                                        }
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        $badge_class = [
                                                            'pendiente' => 'bg-warning',
                                                            'aprobado' => 'bg-success',
                                                            'rechazado' => 'bg-danger',
                                                            'completado' => 'bg-info'
                                                        ][$ticket['estado']] ?? 'bg-secondary';
                                                        ?>
                                                        <span class="badge <?php echo $badge_class; ?>"><?php echo ucfirst($ticket['estado']); ?></span>
                                                    </td>
                                                    <td><?php echo date('d/m/Y H:i', strtotime($ticket['creado_en'])); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="8" class="text-center py-4">No hay tickets cerrados</td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>
    
    <!-- Modales para ver/editar tickets -->
    <?php foreach ($tickets as $ticket): ?>
    <div class="modal fade" id="modalVerTicket<?php echo $ticket['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ticket #<?php echo $ticket['id']; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <input type="hidden" name="ticket_id" value="<?php echo $ticket['id']; ?>">
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Empleado</label>
                                    <p>
                                        <a href="javascript:void(0);" 
                                           class="text-primary text-decoration-underline" 
                                           style="cursor: pointer;"
                                           onclick="verInformacionEmpleado('<?php echo $ticket['empleado_id']; ?>')">
                                            <?php echo htmlspecialchars($ticket['empleado_nombre']); ?>
                                        </a>
                                        (<?php echo $ticket['empleado_id']; ?>)
                                    </p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Departamento</label>
                                    <p><?php echo htmlspecialchars($ticket['empleado_departamento']); ?></p>
                                </div>
                            </div>
                            
				<div class="col-md-4">
				    <div class="mb-3">
				        <label class="form-label fw-bold">Sede del Empleado</label>
				        <p>
				            <?php 
				            if (!empty($ticket['empleado_sede_nombre'])) {
				                echo htmlspecialchars($ticket['empleado_sede_nombre']);
				            } else {
				                echo '<span class="text-muted">No especificada</span>';
				            }
				            ?>
				        </p>
				    </div>
				</div>
                        </div>
                        
                        <?php if ($ticket['tipo'] == 'intercambio'): ?>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Item a devolver</label>
                                    <p><?php 
                                        $item_devuelto = $functions->obtenerItemPorId($ticket['item_devuelto_id']);
                                        echo htmlspecialchars($item_devuelto['nombre'] ?? 'Item no encontrado');
                                    ?></p>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label fw-bold">Estado del item devuelto</label>
                                    <p>
                                        <?php if ($ticket['mal_estado']): ?>
                                            <span class="badge bg-danger">Mal estado</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Buen estado</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
			<?php if ($ticket['tipo'] == 'prestamo' && $ticket['estado'] == 'aprobado'): ?>
			    <div class="mb-3">
			        <label class="form-label fw-bold">Vale de Préstamo</label>
			        <?php
			        // Buscar el vale más reciente para este ticket - CORREGIDO
			        $query_vale = "SELECT f.file_hash, f.file_name 
			                      FROM files f 
			                      INNER JOIN employee e ON f.id_employee = e.id
			                      WHERE e.CC = :empleado_id AND f.file_type = 'vale_prestamo' 
			                      ORDER BY f.created_at DESC LIMIT 1";
			        $stmt_vale = $conn->prepare($query_vale);
			        $stmt_vale->bindParam(':empleado_id', $ticket['empleado_id']);
			        $stmt_vale->execute();
			        $vale = $stmt_vale->fetch(PDO::FETCH_ASSOC);
			        
			        if ($vale): ?>
			            <a href="../includes/abrir_vale.php?hash=<?php echo $vale['file_hash']; ?>" 
			               target="_blank" 
			               class="btn btn-sm btn-primary">
			                <i class="bi bi-file-pdf"></i> Ver/Descargar Vale
			            </a>
			        <?php else: ?>
			            <span class="text-muted">No se encontró el vale de préstamo</span>
			        <?php endif; ?>
			    </div>
			<?php endif; ?>
                        
                        <div class="row">
                            <div class="col-md-3">
                                <div class="mb-3">
                                    <label for="tipo<?php echo $ticket['id']; ?>" class="form-label fw-bold">Tipo de Ticket</label>
                                    <select class="form-select" id="tipo<?php echo $ticket['id']; ?>" name="tipo" required onchange="actualizarCamposTipo(<?php echo $ticket['id']; ?>)">
                                        <option value="ticket" <?php echo $ticket['tipo'] == 'ticket' ? 'selected' : ''; ?>>Ticket General</option>
                                        <option value="solicitud" <?php echo $ticket['tipo'] == 'solicitud' ? 'selected' : ''; ?>>Solicitud</option>
                                        <option value="devolucion" <?php echo $ticket['tipo'] == 'devolucion' ? 'selected' : ''; ?>>Devolución</option>
                                        <option value="intercambio" <?php echo $ticket['tipo'] == 'intercambio' ? 'selected' : ''; ?>>Intercambio</option>
                                        <option value="email" <?php echo $ticket['tipo'] == 'email' ? 'selected' : ''; ?>>Email</option>
                                        <option value="onboarding" <?php echo $ticket['tipo'] == 'onboarding' ? 'selected' : ''; ?>>OnBoarding</option>
                                        <option value="prestamo" <?php echo $ticket['tipo'] == 'prestamo' ? 'selected' : ''; ?>>Préstamo</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="responsable<?php echo $ticket['id']; ?>" class="form-label fw-bold">Responsable</label>
                                    <select class="form-select" id="responsable<?php echo $ticket['id']; ?>" name="responsable_id">
                                        <option value="">-- Seleccionar responsable --</option>
                                        <?php foreach ($administradores as $admin): ?>
                                        <option value="<?php echo $admin['id']; ?>" 
                                            <?php echo (isset($ticket['responsable_id']) && $ticket['responsable_id'] == $admin['id']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($admin['nombre']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <small class="text-muted">
                                        <?php 
                                        if (empty($ticket['responsable_id']) || $ticket['responsable_id'] == 0) {
                                            echo "Se asignará automáticamente al administrador que realice el cambio";
                                        } else {
                                            echo "Actual: " . htmlspecialchars($ticket['responsable_nombre'] ?? 'Sin asignar');
                                        }
                                        ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                        
<!-- Campos para intercambios (dinámicos) -->
<div id="campos_intercambio<?php echo $ticket['id']; ?>" style="display: <?php echo $ticket['tipo'] == 'intercambio' ? 'block' : 'none'; ?>;">
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label for="item_devuelto_id<?php echo $ticket['id']; ?>" class="form-label fw-bold">Item a Devolver</label>
                <select class="form-select" id="item_devuelto_id<?php echo $ticket['id']; ?>" name="item_devuelto_id">
                    <option value="">Seleccionar item</option>
                    <?php 
                    // Obtener items disponibles de la sede del empleado de forma optimizada
                    $sede_empleado_id = $ticket['empleado_sede_id'];
                    $items_disponibles = isset($items_por_sede[$sede_empleado_id]) ? $items_por_sede[$sede_empleado_id] : [];
                    
                    foreach ($items_disponibles as $item): ?>
                    <option value="<?php echo $item['id']; ?>" 
                        <?php echo isset($ticket['item_devuelto_id']) && $ticket['item_devuelto_id'] == $item['id'] ? 'selected' : ''; ?>
                        data-stock="<?php echo $item['stock']; ?>">
                        <?php echo htmlspecialchars($item['nombre']); ?> (Stock: <?php echo $item['stock']; ?> - <?php echo htmlspecialchars($item['sede_nombre']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3 form-check mt-4">
                <input type="checkbox" class="form-check-input" id="mal_estado<?php echo $ticket['id']; ?>" name="mal_estado" <?php echo isset($ticket['mal_estado']) && $ticket['mal_estado'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="mal_estado<?php echo $ticket['id']; ?>">
                    El item devuelto está en mal estado
                </label>
            </div>
        </div>
    </div>
</div>

<!-- Campos para items (dinámicos) -->
<div id="campos_item<?php echo $ticket['id']; ?>" style="display: <?php echo in_array($ticket['tipo'], ['solicitud', 'devolucion', 'intercambio', 'onboarding', 'prestamo']) ? 'block' : 'none'; ?>;">
    <div class="row">
        <div class="col-md-6">
            <div class="mb-3">
                <label for="item_id<?php echo $ticket['id']; ?>" class="form-label fw-bold">Item Vinculado</label>
                <select class="form-select" id="item_id<?php echo $ticket['id']; ?>" name="item_id" onchange="actualizarCantidadMaxima(<?php echo $ticket['id']; ?>)">
                    <option value="">Seleccionar item</option>
                    <?php foreach ($items_disponibles as $item): ?>
                    <option value="<?php echo $item['id']; ?>" 
                        <?php echo isset($ticket['item_id']) && $ticket['item_id'] == $item['id'] ? 'selected' : ''; ?>
                        data-stock="<?php echo $item['stock']; ?>">
                        <?php echo htmlspecialchars($item['nombre']); ?> (Stock: <?php echo $item['stock']; ?> - <?php echo htmlspecialchars($item['sede_nombre']); ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Solo se muestran items disponibles en la sede del empleado</small>
            </div>
        </div>
        <div class="col-md-6">
            <div class="mb-3">
                <label for="cantidad<?php echo $ticket['id']; ?>" class="form-label fw-bold">Cantidad</label>
                <input type="number" class="form-control" id="cantidad<?php echo $ticket['id']; ?>" name="cantidad" value="<?php echo $ticket['cantidad'] ?? 1; ?>" min="1" required>
                <small class="text-muted">Cantidad máxima: <span id="stock_maximo<?php echo $ticket['id']; ?>">1</span></small>
            </div>
        </div>
    </div>
</div>
                        
                        <?php if ($ticket['tipo'] == 'email'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Contenido del correo</label>
                            <div class="border p-3 bg-light" style="max-height: 300px; overflow-y: auto;">
                                <?php echo nl2br(htmlspecialchars($ticket['notas'] ?? 'Sin contenido')); ?>
                            </div>
                        </div>
                        <?php elseif ($ticket['tipo'] == 'onboarding'): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Información del Candidato</label>
                            <div class="border p-3 bg-light" style="max-height: 300px; overflow-y: auto;">
                                <?php echo nl2br(htmlspecialchars($ticket['notas'] ?? 'Sin información')); ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Notas del empleado</label>
                            <p><?php echo nl2br(htmlspecialchars($ticket['notas'])); ?></p>
                        </div>
                        <?php endif; ?>
                        
                        <?php if (!empty($ticket['imagen_ruta'])): ?>
                        <div class="mb-3">
                            <label class="form-label fw-bold">Imagen Adjunta</label>
                            <div class="text-center">
                                <img src="<?php echo $ticket['imagen_ruta']; ?>" 
                                     class="img-thumbnail" 
                                     style="max-height: 300px; cursor: pointer;" 
                                     alt="Imagen del ticket"
                                     onclick="ampliarImagen('<?php echo $ticket['imagen_ruta']; ?>')">
                                <div class="mt-2">
                                    <small class="text-muted">Haz clic en la imagen para ampliar</small>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <div class="mb-3">
                            <label for="estado<?php echo $ticket['id']; ?>" class="form-label fw-bold">Estado</label>
                            <select class="form-select" id="estado<?php echo $ticket['id']; ?>" name="estado" required>
                                <option value="pendiente" <?php echo $ticket['estado'] == 'pendiente' ? 'selected' : ''; ?>>Pendiente</option>
                                <option value="aprobado" <?php echo $ticket['estado'] == 'aprobado' ? 'selected' : ''; ?>>Aprobado</option>
                                <option value="rechazado" <?php echo $ticket['estado'] == 'rechazado' ? 'selected' : ''; ?>>Rechazado</option>
                                <option value="completado" <?php echo $ticket['estado'] == 'completado' ? 'selected' : ''; ?>>Completado</option>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label for="notas_admin" class="form-label fw-bold">Notas del administrador</label>
                            <textarea class="form-control" id="notas_admin" name="notas_admin" rows="3"><?php echo htmlspecialchars($ticket['notas_admin'] ?? ''); ?></textarea>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Fecha de creación</label>
                            <p><?php echo date('d/m/Y H:i:s', strtotime($ticket['creado_en'])); ?></p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        <button type="submit" name="actualizar_ticket" class="btn btn-primary">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <!-- Modal para imagen ampliada -->
    <div class="modal fade modal-img-ampliada" id="modalImagenAmpliada" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header">
                    <h6 class="modal-title">Imagen del Ticket</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body text-center">
                    <img id="imagenAmpliada" src="" class="img-fluid" alt="Imagen ampliada">
                </div>
            </div>
        </div>
    </div>
    


    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Función para ampliar imagen
    function ampliarImagen(rutaImagen) {
        document.getElementById('imagenAmpliada').src = rutaImagen;
        const modal = new bootstrap.Modal(document.getElementById('modalImagenAmpliada'));
        modal.show();
    }

    // Función para abrir modal del ticket
    function abrirModalTicket(ticketId) {
        const modal = new bootstrap.Modal(document.getElementById('modalVerTicket' + ticketId));
        modal.show();
    }

    // Función para mostrar/ocultar campos según el tipo de ticket
    function actualizarCamposTipo(ticketId) {
        const tipo = document.getElementById('tipo' + ticketId).value;
        const camposItem = document.getElementById('campos_item' + ticketId);
        const camposIntercambio = document.getElementById('campos_intercambio' + ticketId);
        
        // Mostrar/ocultar campos de item - SOLO para solicitud, devolución, intercambio, onboarding y préstamo
	if (tipo === 'solicitud' || tipo === 'devolucion' || tipo === 'intercambio' || tipo === 'onboarding' || tipo === 'prestamo') {
	    camposItem.style.display = 'block';
	} else {
	    camposItem.style.display = 'none';
	}
        
        // Mostrar/ocultar campos de intercambio
        if (tipo === 'intercambio') {
            camposIntercambio.style.display = 'block';
        } else {
            camposIntercambio.style.display = 'none';
        }
    }

    // Función para actualizar la cantidad máxima según el stock disponible
    function actualizarCantidadMaxima(ticketId) {
        const itemSelect = document.getElementById('item_id' + ticketId);
        const cantidadInput = document.getElementById('cantidad' + ticketId);
        const stockMaximoSpan = document.getElementById('stock_maximo' + ticketId);
        
        if (!itemSelect || !cantidadInput || !stockMaximoSpan) return;
        
        const selectedOption = itemSelect.options[itemSelect.selectedIndex];
        const stockDisponible = selectedOption.getAttribute('data-stock');
        
        if (stockDisponible) {
            stockMaximoSpan.textContent = stockDisponible;
            cantidadInput.max = stockDisponible;
            
            // Si la cantidad actual es mayor que el stock disponible, ajustarla
            if (parseInt(cantidadInput.value) > parseInt(stockDisponible)) {
                cantidadInput.value = stockDisponible;
            }
        } else {
            stockMaximoSpan.textContent = '1';
            cantidadInput.max = '';
        }
    }

    // Función para ver información del empleado
    function verInformacionEmpleado(empleadoCC) {
        // Mostrar loading
        const loadingHTML = `
            <div class="text-center py-4">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Cargando información del empleado...</span>
                </div>
                <p class="mt-2">Cargando información del empleado...</p>
            </div>
        `;
        
        // Crear modal dinámico
        const modalContent = `
            <div class="modal fade" id="modalInfoEmpleadoTicket" tabindex="-1">
                <div class="modal-dialog modal-lg">
                    <div class="modal-content">
                        <div class="modal-header text-white" style="background-color: #003a5d;">
                            <h5 class="modal-title">Información del Empleado</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="contenidoEmpleadoTicket">
                            ${loadingHTML}
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                        </div>
                    </div>
                </div>
            </div>`;
        
        // Remover modal existente si hay uno
        const existingModal = document.getElementById('modalInfoEmpleadoTicket');
        if (existingModal) {
            existingModal.remove();
        }
        
        // Añadir nuevo modal al DOM
        document.body.insertAdjacentHTML('beforeend', modalContent);
        
        // Mostrar modal
        const modal = new bootstrap.Modal(document.getElementById('modalInfoEmpleadoTicket'));
        modal.show();
        
        // Cargar información del empleado
        cargarInformacionEmpleado(empleadoCC);
        
        // Limpiar modal cuando se cierre
        document.getElementById('modalInfoEmpleadoTicket').addEventListener('hidden.bs.modal', function() {
            this.remove();
        });
    }

    // Función para cargar información del empleado
    function cargarInformacionEmpleado(empleadoCC) {
        const contenidoDiv = document.getElementById('contenidoEmpleadoTicket');
        
        if (!contenidoDiv) return;
        
        fetch(`../includes/obtener_info_empleado.php?cc=${encodeURIComponent(empleadoCC)}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Error al cargar la información del empleado');
                }
                return response.json();
            })
            .then(empleado => {
                if (empleado && !empleado.error) {
                    contenidoDiv.innerHTML = generarHTMLInfoEmpleado(empleado);
                } else {
                    contenidoDiv.innerHTML = `
                        <div class="alert alert-danger">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Error: ${empleado.error || 'No se pudo cargar la información del empleado'}
                        </div>
                    `;
                }
            })
            .catch(error => {
                console.error('Error cargando empleado:', error);
                contenidoDiv.innerHTML = `
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> 
                        Error al cargar la información del empleado: ${error.message}
                    </div>
                `;
            });
    }

    // Función para generar el HTML de información del empleado
    function generarHTMLInfoEmpleado(empleado) {
        // Definir arrays para traducciones
        const tipo_doc = {
            1: 'Cédula',
            2: 'Pasaporte', 
            3: 'Cédula Extranjería'
        };
        
        const companias = {
            1: 'AZC',
            2: 'BilingueLaw', 
            3: 'LawyerDesk',
            4: 'AZC Legal',
            5: 'Matiz LegalTech'
        };
        
        const roleColors = {
            'administrador': 'danger',
            'it': 'primary',
            'nomina': 'warning',
            'talento_humano': 'info',
            'empleado': 'secondary',
            'retirado': 'dark',
            'candidato': 'success'
        };
        
        // Obtener foto del empleado
        const fotoEmpleado = empleado.photo ? 
            `/uploads/photos/${empleado.photo}` : 
            '/assets/images/default_avatar.png';
        
        return `
            <div class="row">
                <div class="col-md-4 text-center">
                    <img src="${fotoEmpleado}" 
                         class="rounded-circle mb-3" 
                         style="width: 150px; height: 150px; object-fit: cover; border: 3px solid #003a5d;"
                         alt="Foto de perfil">
                    <h5>${escapeHtml(empleado.first_Name + ' ' + empleado.first_LastName)}</h5>
                    <p class="text-muted">${escapeHtml(empleado.position_nombre || 'No asignado')}</p>
                </div>
                <div class="col-md-8">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Información Personal</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-bold" style="width: 40%;">Nombre completo:</td>
                                    <td>${escapeHtml(empleado.first_Name + ' ' + (empleado.second_Name || '') + ' ' + empleado.first_LastName + ' ' + (empleado.second_LastName || ''))}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Documento:</td>
                                    <td>${empleado.CC} (${tipo_doc[empleado.type_CC] || 'Desconocido'})</td>
                                </tr>
                                ${empleado.birthdate ? `
                                <tr>
                                    <td class="fw-bold">Fecha nacimiento:</td>
                                    <td>${new Date(empleado.birthdate).toLocaleDateString('es-ES')}</td>
                                </tr>
                                ` : ''}
                                <tr>
                                    <td class="fw-bold">Correo empresa:</td>
                                    <td>${escapeHtml(empleado.mail)}</td>
                                </tr>
                                ${empleado.personal_mail ? `
                                <tr>
                                    <td class="fw-bold">Correo personal:</td>
                                    <td>${escapeHtml(empleado.personal_mail)}</td>
                                </tr>
                                ` : ''}
                                ${empleado.phone ? `
                                <tr>
                                    <td class="fw-bold">Teléfono:</td>
                                    <td>${escapeHtml(empleado.phone)}</td>
                                </tr>
                                ` : ''}
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>Información Laboral</h6>
                            <table class="table table-sm table-borderless">
                                <tr>
                                    <td class="fw-bold" style="width: 40%;">Compañía:</td>
                                    <td>${companias[empleado.company] || 'Desconocida'}</td>
                                </tr>
                                ${empleado.sede_nombre ? `
                                <tr>
                                    <td class="fw-bold">Sede:</td>
                                    <td>${escapeHtml(empleado.sede_nombre)}</td>
                                </tr>
                                ` : ''}
                                ${empleado.firm_name ? `
                                <tr>
                                    <td class="fw-bold">Firma:</td>
                                    <td>${escapeHtml(empleado.firm_name)}</td>
                                </tr>
                                ` : ''}
                                <tr>
                                    <td class="fw-bold">Cargo:</td>
                                    <td>${escapeHtml(empleado.position_nombre || 'No asignado')}</td>
                                </tr>
                                <tr>
                                    <td class="fw-bold">Rol:</td>
                                    <td>
                                        <span class="badge bg-${roleColors[empleado.role] || 'secondary'}">
                                            ${empleado.role ? empleado.role.charAt(0).toUpperCase() + empleado.role.slice(1) : 'No asignado'}
                                        </span>
                                    </td>
                                </tr>
                                ${empleado.activo_fijo ? `
                                <tr>
                                    <td class="fw-bold">Activo fijo:</td>
                                    <td><span class="badge bg-success">${escapeHtml(empleado.activo_fijo)}</span></td>
                                </tr>
                                ` : ''}
                                <tr>
                                    <td class="fw-bold">Fecha ingreso:</td>
                                    <td>${new Date(empleado.created_at).toLocaleDateString('es-ES')}</td>
                                </tr>
                            </table>
                        </div>
                    </div>
                    
                    ${empleado.extension || empleado.address ? `
                    <div class="row mt-3">
                        ${empleado.extension ? `
                        <div class="col-md-6">
                            <strong>Extensión:</strong> ${escapeHtml(empleado.extension)}
                        </div>
                        ` : ''}
                        ${empleado.address ? `
                        <div class="col-md-6">
                            <strong>Dirección:</strong> ${escapeHtml(empleado.address)}
                        </div>
                        ` : ''}
                    </div>
                    ` : ''}
                </div>
            </div>
        `;
    }

    // Función auxiliar para escapar HTML
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }


    // Inicializar event listeners cuando el DOM esté listo
    document.addEventListener('DOMContentLoaded', function() {
        // Preparar un array con todos los IDs de tickets para inicializar
        const ticketIds = <?php echo json_encode(array_column($tickets, 'id')); ?>;
        
        // Inicializar event listeners para todos los tickets
        ticketIds.forEach(function(ticketId) {
            // Event listener para el select de tipo
            const tipoSelect = document.getElementById('tipo' + ticketId);
            if (tipoSelect) {
                tipoSelect.addEventListener('change', function() {
                    actualizarCamposTipo(ticketId);
                });
            }
            
            // Event listener para el select de item
            const itemSelect = document.getElementById('item_id' + ticketId);
            if (itemSelect) {
                itemSelect.addEventListener('change', function() {
                    actualizarCantidadMaxima(ticketId);
                });
                
                // Ejecutar una vez al cargar para establecer la cantidad máxima inicial
                actualizarCantidadMaxima(ticketId);
            }
        });
    });
    

// Sistema de carga con progreso detallado
document.addEventListener('DOMContentLoaded', function() {
    const loader = document.getElementById('page-loader');
    const mainContent = document.getElementById('main-content');
    const progressBar = document.querySelector('.progress-bar');
    const loaderText = document.querySelector('.loader-text p');
    const loaderTitle = document.querySelector('.loader-text h5');
    
    // Estados de carga
    const loadStates = [
        { progress: 20, title: 'Cargando tickets...', message: 'Obteniendo datos del servidor' },
        { progress: 40, title: 'Procesando información...', message: 'Organizando datos' },
        { progress: 60, title: 'Cargando componentes...', message: 'Preparando interfaz' },
        { progress: 80, title: 'Cargando estilos...', message: 'Aplicando diseño' },
        { progress: 95, title: 'Iniciando sistema...', message: 'Preparando interactividad' },
        { progress: 100, title: '¡Listo!', message: 'Sistema completamente cargado' }
    ];
    
    let currentState = 0;
    
    // Función para actualizar el progreso
    function updateProgress() {
        if (currentState < loadStates.length) {
            const state = loadStates[currentState];
            
            if (progressBar) {
                progressBar.style.width = state.progress + '%';
            }
            
            if (loaderTitle) {
                loaderTitle.textContent = state.title;
            }
            
            if (loaderText) {
                loaderText.textContent = state.message;
            }
            
            currentState++;
            
            // Calcular tiempo de espera basado en el progreso
            const delay = currentState < loadStates.length ? 
                Math.random() * 800 + 200 : // 200-1000ms para estados intermedios
                500; // 500ms para el estado final
            
            setTimeout(updateProgress, delay);
        }
    }
    
    // Iniciar actualización de progreso
    updateProgress();
    
    // Función para verificar si Bootstrap está completamente cargado
    function isBootstrapReady() {
        return typeof bootstrap !== 'undefined' && 
               bootstrap.Modal && 
               bootstrap.Tooltip && 
               bootstrap.Popover;
    }
    
    // Función para ocultar el loader
    function hideLoader() {
        // Asegurar que el progreso llegue al 100%
        if (progressBar) {
            progressBar.style.width = '100%';
        }
        
        if (loaderTitle) {
            loaderTitle.textContent = '¡Listo!';
        }
        
        if (loaderText) {
            loaderText.textContent = 'Sistema completamente cargado';
        }
        
        // Pequeña pausa antes de ocultar
        setTimeout(() => {
            loader.classList.add('hidden');
            
            // Restaurar opacidad del contenido principal
            mainContent.classList.add('ready');
            
            // Restaurar opacidad del sidebar
            const sidebar = document.querySelector('.sidebar');
            if (sidebar) {
                sidebar.classList.remove('loading');
                sidebar.classList.add('ready');
            }
            
            // Agregar animación de entrada
            const elementsToAnimate = document.querySelectorAll('.card, .table, .btn');
            elementsToAnimate.forEach((el, index) => {
                setTimeout(() => {
                    el.classList.add('fade-in-up');
                }, index * 50);
            });
            
            // Eliminar el loader
            setTimeout(() => {
                if (loader.parentNode) {
                    loader.parentNode.removeChild(loader);
                }
            }, 300);
        }, 500);
    }
    
    // Verificar estado de carga
    function checkLoadStatus() {
        const bootstrapReady = isBootstrapReady();
        const domReady = document.readyState === 'complete';
        
        return bootstrapReady && domReady;
    }
    
    // Verificar periódicamente si todo está listo
    let checkCount = 0;
    const maxChecks = 30; // Máximo 15 segundos
    
    const checkInterval = setInterval(() => {
        checkCount++;
        
        if (checkLoadStatus() || checkCount >= maxChecks) {
            clearInterval(checkInterval);
            
            if (checkCount >= maxChecks) {
                console.warn('Tiempo de carga agotado');
            }
            
            hideLoader();
        }
    }, 500);
});

// Optimización: precargar componentes críticos
function preloadCriticalComponents() {
    const criticalImages = [
        '../assets/images/logo_main.png',
        '../assets/images/default_avatar.png'
    ];
    
    criticalImages.forEach(src => {
        const img = new Image();
        img.src = src;
    });
}

preloadCriticalComponents();

    </script>
<?php
// Verificar si hay un vale para abrir
if (isset($_SESSION['vale_prestamo_hash']) && !empty($_SESSION['vale_prestamo_hash'])) {
    $vale_hash = $_SESSION['vale_prestamo_hash'];
    $vale_nombre = $_SESSION['vale_prestamo_nombre'];
    
    // Limpiar la variable de sesión
    unset($_SESSION['vale_prestamo_hash']);
    unset($_SESSION['vale_prestamo_nombre']);
    
    echo "<script>
        // Esperar a que la página esté completamente cargada
        document.addEventListener('DOMContentLoaded', function() {
            // Mostrar notificación de éxito primero
            setTimeout(function() {
                var alert = document.createElement('div');
                alert.className = 'alert alert-success alert-dismissible fade show position-fixed';
                alert.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
                alert.innerHTML = `
                    <strong>¡Vale generado!</strong><br>
                    El vale de préstamo se ha generado correctamente.<br>
                    <small>Archivo: " . json_encode($vale_nombre) . "</small><br>
                    <button type='button' class='btn btn-sm btn-primary mt-2' onclick='abrirValePrestamo()'>
                        <i class='bi bi-file-pdf'></i> Abrir Vale
                    </button>
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                `;
                document.body.appendChild(alert);
                
                // Auto-eliminar después de 10 segundos
                setTimeout(function() {
                    if (alert.parentNode) {
                        alert.parentNode.removeChild(alert);
                    }
                }, 10000);
            }, 1000);
        });
        
        // Función para abrir el vale (se ejecuta con clic del usuario)
        function abrirValePrestamo() {
            var ventana = window.open('../includes/abrir_vale.php?hash=" . $vale_hash . "', '_blank', 'width=800,height=600');
            
            // Si la ventana fue bloqueada, mostrar alternativa
            if (!ventura || ventana.closed || typeof ventana.closed === 'undefined') {
                // Crear un enlace temporal
                var enlace = document.createElement('a');
                enlace.href = '../includes/abrir_vale.php?hash=" . $vale_hash . "';
                enlace.target = '_blank';
                enlace.download = '" . $vale_nombre . "';
                document.body.appendChild(enlace);
                enlace.click();
                document.body.removeChild(enlace);
                
                // Mostrar mensaje alternativo
                var alertAlt = document.createElement('div');
                alertAlt.className = 'alert alert-info alert-dismissible fade show position-fixed';
                alertAlt.style.cssText = 'top: 80px; right: 20px; z-index: 9999; min-width: 300px;';
                alertAlt.innerHTML = `
                    <strong>Popup bloqueado</strong><br>
                    El vale se descargará directamente. Si no se descarga, 
                    <a href='../includes/abrir_vale.php?hash=" . $vale_hash . "' target='_blank' style='color: white; text-decoration: underline;'>haz clic aquí</a>.
                    <button type='button' class='btn-close' data-bs-dismiss='alert'></button>
                `;
                document.body.appendChild(alertAlt);
                
                setTimeout(function() {
                    if (alertAlt.parentNode) {
                        alertAlt.parentNode.removeChild(alertAlt);
                    }
                }, 8000);
            }
        }
    </script>";
}
?>
</body>
</html>