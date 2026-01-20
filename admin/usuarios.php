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

// Verificar permisos específicos para las acciones
 $permiso_crear_usuario = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'usuarios', 'crear_usuario');
 $permiso_generar_informe = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'usuarios', 'generar_informe');
 $permiso_editar_usuario = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'usuarios', 'editar_usuario');
 $permiso_eliminar_usuario = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'usuarios', 'eliminar_usuario');
 $permiso_gestionar_accesos = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'usuarios', 'gestionar_accesos');

// El resto del código permanece igual hasta la sección de botones en el HTML

$functions = new Functions();
$error = '';
$success = '';


// Obtener sedes para los selects
$query_sedes = "SELECT id, nombre FROM sedes ORDER BY nombre";
$stmt_sedes = $conn->prepare($query_sedes);
$stmt_sedes->execute();
$sedes = $stmt_sedes->fetchAll(PDO::FETCH_ASSOC);

// Obtener cargos para los selects
 $query_cargos = "SELECT id, nombre FROM cargos ORDER BY nombre";
 $stmt_cargos = $conn->prepare($query_cargos);
 $stmt_cargos->execute();
 $cargos = $stmt_cargos->fetchAll(PDO::FETCH_ASSOC);

// ==================== GESTIÓN DE JERARQUÍAS Y ÁREAS ====================

try {
    // Verificar si las tablas existen antes de intentar usarlas
    $tablas_requeridas = ['areas', 'supervisores'];
    $tablas_existentes = [];
    
    foreach ($tablas_requeridas as $tabla) {
        $check_table = $conn->query("SHOW TABLES LIKE '$tabla'");
        if ($check_table->rowCount() > 0) {
            $tablas_existentes[] = $tabla;
        }
    }
    
    // Inicializar HierarchyManager solo si las tablas existen
    if (in_array('areas', $tablas_existentes) && in_array('supervisores', $tablas_existentes)) {
        require_once '../includes/HierarchyManager.php';
        $hierarchyManager = new HierarchyManager($database);
        
        // Obtener áreas para los selects
        $query_areas = "SELECT id, nombre, padre_id FROM areas ORDER BY nombre";
        $stmt_areas = $conn->prepare($query_areas);
        $stmt_areas->execute();
        $areas = $stmt_areas->fetchAll(PDO::FETCH_ASSOC);
        
        // Obtener supervisores disponibles
        $supervisores_disponibles = $hierarchyManager->obtenerSupervisoresDisponibles();
        
        // Función para construir árbol de áreas
        function construirArbolAreas($areas, $padre_id = null) {
            $arbol = [];
            foreach ($areas as $area) {
                if ($area['padre_id'] == $padre_id) {
                    $hijos = construirArbolAreas($areas, $area['id']);
                    if ($hijos) {
                        $area['hijos'] = $hijos;
                    }
                    $arbol[] = $area;
                }
            }
            return $arbol;
        }
        
        // Función para mostrar áreas en formato HTML
        function generarOpcionesAreas($arbol, $nivel = 0) {
            $html = '';
            $prefijo = str_repeat('--', $nivel);
            
            foreach ($arbol as $area) {
                $html .= '<option value="' . $area['id'] . '">' . $prefijo . ' ' . htmlspecialchars($area['nombre']) . '</option>';
                if (isset($area['hijos'])) {
                    $html .= generarOpcionesAreas($area['hijos'], $nivel + 1);
                }
            }
            
            return $html;
        }
        
        $arbol_areas = construirArbolAreas($areas);
        
    } else {
        // Si las tablas no existen, usar arrays vacíos
        $hierarchyManager = null;
        $areas = [];
        $supervisores_disponibles = [];
        $arbol_areas = [];
        
        error_log("Advertencia: Las tablas de jerarquías no existen todavía");
    }
    
} catch (Exception $e) {
    // En caso de error, inicializar variables vacías
    $hierarchyManager = null;
    $areas = [];
    $supervisores_disponibles = [];
    $arbol_areas = [];
    
    error_log("Error inicializando jerarquías: " . $e->getMessage());
}

// Función auxiliar segura para obtener información de supervisor
function obtenerInfoSupervisorSegura($usuario_id, $hierarchyManager) {
    if ($hierarchyManager && method_exists($hierarchyManager, 'obtenerInfoSupervisor')) {
        return $hierarchyManager->obtenerInfoSupervisor($usuario_id);
    }
    return null;
}

// Procesar creación de usuario
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_usuario'])) {
    $type_CC = $_POST['type_CC'];
    $CC = $_POST['CC'];
    $first_Name = trim($_POST['first_Name']);
    $second_Name = trim($_POST['second_Name']);
    $first_LastName = trim($_POST['first_LastName']);
    $second_LastName = trim($_POST['second_LastName']);
    $birthdate = $_POST['birthdate'];
    $dval = $_POST['dval'] ?? null;
    $mail = trim($_POST['mail']);
    $personal_mail = trim($_POST['personal_mail']);
    $phone = trim($_POST['phone']);
    $country = $_POST['country'];
    $city = trim($_POST['city']);
    $address = trim($_POST['address']);
    $sede_id = $_POST['sede_id'];
    $company = $_POST['company'];
    $position_id = !empty($_POST['position_id']) ? (int)$_POST['position_id'] : null;
    $extension = trim($_POST['extension']);
    $activo_fijo = !empty($_POST['activo_fijo']) ? trim($_POST['activo_fijo']) : null;
    $role = $_POST['role'];
    
    // Procesar foto
    $photo_name = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            try {
                $photo_name = procesarFotoUsuario($_FILES['photo'], $CC);
            } catch (Exception $e) {
                $error = "Error al procesar la foto: " . $e->getMessage();
            }
        }
    
    $needs_phone = isset($_POST['needs_phone']) ? 1 : 0;
    $id_phone = !empty($_POST['id_phone']) ? $_POST['id_phone'] : null;
    $id_firm = !empty($_POST['id_firm']) ? $_POST['id_firm'] : null;
    $locacion_id = !empty($_POST['locacion_id']) ? $_POST['locacion_id'] : null;
    
    // Generar password automático
    $password = 'z' . $CC . 'Z@!$';
    // Generar pin automático (últimos 4 dígitos del CC)
    $pin = substr($CC, -4);
    
    // Validaciones básicas
    if (!empty($first_Name) && !empty($first_LastName) && !empty($CC) && !empty($birthdate) && !empty($mail)) {
        try {
            // Verificar si el CC ya existe
            $query_verificar = "SELECT id FROM employee WHERE CC = :CC";
            $stmt_verificar = $conn->prepare($query_verificar);
            $stmt_verificar->bindParam(':CC', $CC);
            $stmt_verificar->execute();
            
            if ($stmt_verificar->rowCount() > 0) {
                $error = "El número de documento (CC) ya existe en el sistema";
            } else {
                // Verificar si el email ya existe
                $query_verificar_mail = "SELECT id FROM employee WHERE mail = :mail";
                $stmt_verificar_mail = $conn->prepare($query_verificar_mail);
                $stmt_verificar_mail->bindParam(':mail', $mail);
                $stmt_verificar_mail->execute();
                
                if ($stmt_verificar_mail->rowCount() > 0) {
                    $error = "El correo electrónico ya existe en el sistema";
                } else {
                    // Verificar si el activo fijo ya está asignado
                    if (!empty($activo_fijo)) {
                        $query_verificar_activo = "SELECT id FROM employee WHERE activo_fijo = :activo_fijo";
                        $stmt_verificar_activo = $conn->prepare($query_verificar_activo);
                        $stmt_verificar_activo->bindParam(':activo_fijo', $activo_fijo);
                        $stmt_verificar_activo->execute();
                        
                        if ($stmt_verificar_activo->rowCount() > 0) {
                            $error = "El activo fijo ya está asignado a otro usuario";
                        }
                    }
                    
                    if (empty($error)) {
			 $query = "INSERT INTO employee (
			    id_firm, type_CC, CC, first_Name, second_Name, first_LastName, second_LastName, 
			    birthdate, dval, mail, personal_mail, phone, country, city, address, sede_id, company, position_id,
			    extension, activo_fijo, role, password, pin, needs_phone, id_phone, locacion_id, photo, created_at
			) VALUES (
			    :id_firm, :type_CC, :CC, :first_Name, :second_Name, :first_LastName, :second_LastName,
			    :birthdate, :dval, :mail, :personal_mail, :phone, :country, :city, :address, :sede_id, :company, :position_id,
			    :extension, :activo_fijo, :role, :password, :pin, :needs_phone, :id_phone, :locacion_id, :photo, NOW()
			)";
			                        
                        $stmt = $conn->prepare($query);
                        $stmt->bindParam(':id_firm', $id_firm, PDO::PARAM_INT);
                        $stmt->bindParam(':type_CC', $type_CC);
                        $stmt->bindParam(':CC', $CC);
                        $stmt->bindParam(':first_Name', $first_Name);
                        $stmt->bindParam(':second_Name', $second_Name);
                        $stmt->bindParam(':first_LastName', $first_LastName);
                        $stmt->bindParam(':second_LastName', $second_LastName);
                        $stmt->bindParam(':birthdate', $birthdate);
                        $stmt->bindParam(':dval', $dval, PDO::PARAM_INT);
                        $stmt->bindParam(':mail', $mail);
                        $stmt->bindParam(':personal_mail', $personal_mail);
                        $stmt->bindParam(':phone', $phone);
                        $stmt->bindParam(':country', $country);
                        $stmt->bindParam(':city', $city);
                        $stmt->bindParam(':address', $address);
                        $stmt->bindParam(':sede_id', $sede_id);
                        $stmt->bindParam(':company', $company);
                         $stmt->bindParam(':position_id', $position_id, PDO::PARAM_INT);
                        $stmt->bindParam(':extension', $extension);
                        if ($activo_fijo === null) {
			    $stmt->bindValue(':activo_fijo', null, PDO::PARAM_NULL);
			} else {
			    $stmt->bindValue(':activo_fijo', $activo_fijo, PDO::PARAM_STR);
			}
                        $stmt->bindParam(':role', $role);
                        $stmt->bindParam(':photo', $photo_name);
                        $stmt->bindParam(':password', $password);
                        $stmt->bindParam(':pin', $pin);
                        $stmt->bindParam(':needs_phone', $needs_phone);
                        $stmt->bindParam(':id_phone', $id_phone, PDO::PARAM_INT);
                        $stmt->bindParam(':locacion_id', $locacion_id, PDO::PARAM_INT);
                        
                        if ($stmt->execute()) {
                            $nuevo_usuario_id = $conn->lastInsertId();
                            
                            // ASIGNAR EQUIPO SI SE PROPORCIONÓ ACTIVO FIJO
                            if (!empty($activo_fijo)) {
                                $query_asignar_equipo = "UPDATE equipos SET usuario_asignado = :usuario_id, sede_id = :sede_id WHERE activo_fijo = :activo_fijo";
                                $stmt_equipo = $conn->prepare($query_asignar_equipo);
                                $stmt_equipo->bindParam(':usuario_id', $nuevo_usuario_id);
                                $stmt_equipo->bindParam(':sede_id', $sede_id);
                                $stmt_equipo->bindParam(':activo_fijo', $activo_fijo);
                                $stmt_equipo->execute();
                            }
                            
                            // Registrar en historial
                            $functions->registrarHistorial(
                                null, 
                                $nuevo_usuario_id,
                                null, 
                                'crear_empleado', 
                                1, 
                                "Usuario creado: " . $first_Name . " " . $first_LastName . " (CC: " . $CC . ")"
                            );
                            
                            $success = "Usuario creado correctamente. Contraseña: " . $password;
				// Recargar la página para que el nuevo usuario aparezca en la lista con su modal
				echo "<script>
				    setTimeout(function() {
				        window.location.href = 'usuarios.php?success=" . urlencode("Usuario creado correctamente. Contraseña: " . $password) . "';
				    }, 1500);
				</script>";
                        } else {
                            $error = "Error al crear el usuario";
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            $error = "Error en la base de datos: " . $e->getMessage();
        }
    } else {
        $error = "Los campos obligatorios son: Nombre, Apellido, Documento, Fecha de Nacimiento y Correo";
    }
}

// Procesar actualización de usuario
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['actualizar_usuario'])) {
    $user_id = $_POST['user_id'];
    $first_Name = trim($_POST['first_Name']);
    $second_Name = trim($_POST['second_Name']);
    $first_LastName = trim($_POST['first_LastName']);
    $second_LastName = trim($_POST['second_LastName']);
    $birthdate = $_POST['birthdate'];
    $dval = !empty($_POST['dval']) ? (int)$_POST['dval'] : null;
    $mail = trim($_POST['mail']);
    $personal_mail = trim($_POST['personal_mail']);
    $phone = trim($_POST['phone']);
    $country = $_POST['country'];
    $city = trim($_POST['city']);
    $address = trim($_POST['address']);
    $sede_id = $_POST['sede_id'];
    $company = $_POST['company'];
     $position_id = !empty($_POST['position_id']) ? (int)$_POST['position_id'] : null;
    $extension = trim($_POST['extension']);
    $nuevo_activo_fijo = !empty($_POST['activo_fijo']) ? trim($_POST['activo_fijo']) : null;
    $role = $_POST['role'];
    $needs_phone = isset($_POST['needs_phone']) ? 1 : 0;
    $id_phone = !empty($_POST['id_phone']) ? $_POST['id_phone'] : null;
    $id_firm = !empty($_POST['id_firm']) ? $_POST['id_firm'] : null;
    $locacion_id = !empty($_POST['locacion_id']) ? $_POST['locacion_id'] : null;
    $supervisor_id = !empty($_POST['supervisor_id']) ? $_POST['supervisor_id'] : null;
    $es_supervisor = isset($_POST['es_supervisor']) ? 1 : 0;
    $tipo_supervisor = $_POST['tipo_supervisor'] ?? null;
    $area_supervisor = $_POST['area_supervisor'] ?? null;
    
    // Nuevo: manejo del PIN
    $update_pin = false;
    $pin_value = null;
    
    // Si se proporcionó un PIN en el formulario, úsalo
    if (!empty($_POST['pin'])) {
        $update_pin = true;
        $pin_value = $_POST['pin'];
    } 
    // Si no, y el rol cambió a 'candidato', genera un nuevo PIN
    else {
        // Obtener el rol actual del usuario
        $query_rol_actual = "SELECT role FROM employee WHERE id = :id";
        $stmt_rol_actual = $conn->prepare($query_rol_actual);
        $stmt_rol_actual->bindParam(':id', $user_id);
        $stmt_rol_actual->execute();
        $rol_actual = $stmt_rol_actual->fetch(PDO::FETCH_ASSOC)['role'];
        
        // Si el rol cambió a 'candidato', generar PIN automáticamente
        if ($rol_actual != 'candidato' && $role == 'candidato') {
            // Obtener el CC del usuario para generar el PIN
            $query_cc = "SELECT CC FROM employee WHERE id = :id";
            $stmt_cc = $conn->prepare($query_cc);
            $stmt_cc->bindParam(':id', $user_id);
            $stmt_cc->execute();
            $cc_usuario = $stmt_cc->fetch(PDO::FETCH_ASSOC)['CC'];
            
            if ($cc_usuario) {
                $update_pin = true;
                $pin_value = substr($cc_usuario, -4); // Últimos 4 dígitos del CC
            }
        }
    }
    
    try {
        // Obtener usuario actual para la foto
        $query_actual = "SELECT photo, activo_fijo, sede_id FROM employee WHERE id = :id";
        $stmt_actual = $conn->prepare($query_actual);
        $stmt_actual->bindParam(':id', $user_id);
        $stmt_actual->execute();
        $usuario_actual = $stmt_actual->fetch(PDO::FETCH_ASSOC);
        $photo_name = $usuario_actual['photo'] ?? null;
        
        // Procesar nueva foto
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            // Eliminar foto anterior si existe
            if ($photo_name && !empty($photo_name)) {
                eliminarFotoUsuario($photo_name);
            }
            $photo_name = procesarFotoUsuario($_FILES['photo'], $_POST['CC']);
        }
        
        // Si se solicita eliminar la foto
        if (isset($_POST['eliminar_photo']) && $_POST['eliminar_photo'] == '1') {
            if ($photo_name && !empty($photo_name)) {
                eliminarFotoUsuario($photo_name);
                $photo_name = null;
            }
        }
        
        // Construir query de actualización
	 $query = "UPDATE employee SET 
	         first_Name = :first_Name, second_Name = :second_Name, 
	         first_LastName = :first_LastName, second_LastName = :second_LastName,
	         birthdate = :birthdate, dval = :dval, mail = :mail, personal_mail = :personal_mail,
	         phone = :phone, country = :country, city = :city, address = :address, sede_id = :sede_id,
	         company = :company, position_id = :position_id, extension = :extension,
	         activo_fijo = :activo_fijo, role = :role, needs_phone = :needs_phone,
	         id_phone = :id_phone, id_firm = :id_firm, locacion_id = :locacion_id, 
	         supervisor_id = :supervisor_id,  -- NUEVO CAMPO
	         photo = :photo, updated_at = NOW()";
        
        // Agregar PIN a la consulta solo si se debe actualizar
        if ($update_pin) {
            $query .= ", pin = :pin";
        }
        
        $query .= " WHERE id = :id";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':first_Name', $first_Name);
        $stmt->bindParam(':second_Name', $second_Name);
        $stmt->bindParam(':first_LastName', $first_LastName);
        $stmt->bindParam(':second_LastName', $second_LastName);
        $stmt->bindParam(':birthdate', $birthdate);
        $stmt->bindParam(':dval', $dval, PDO::PARAM_INT);
        $stmt->bindParam(':mail', $mail);
        $stmt->bindParam(':personal_mail', $personal_mail);
        $stmt->bindParam(':phone', $phone);
        $stmt->bindParam(':country', $country);
        $stmt->bindParam(':city', $city);
        $stmt->bindParam(':address', $address);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->bindParam(':company', $company);
         $stmt->bindParam(':position_id', $position_id, PDO::PARAM_INT);
        $stmt->bindParam(':extension', $extension);
        $stmt->bindParam(':activo_fijo', $nuevo_activo_fijo);
        $stmt->bindParam(':role', $role);
        $stmt->bindParam(':needs_phone', $needs_phone);
        $stmt->bindParam(':id_phone', $id_phone, PDO::PARAM_INT);
        $stmt->bindParam(':id_firm', $id_firm, PDO::PARAM_INT);
        $stmt->bindParam(':id', $user_id);
        $stmt->bindParam(':locacion_id', $locacion_id, PDO::PARAM_INT);
        $stmt->bindParam(':photo', $photo_name);
        $stmt->bindParam(':supervisor_id', $supervisor_id, PDO::PARAM_INT);
        
        // Solo enlazar el PIN si se debe actualizar
        if ($update_pin) {
            $stmt->bindParam(':pin', $pin_value);
        }
        if ($stmt->execute()) {
            // GESTIÓN DE EQUIPOS - ASIGNAR/DESASIGNAR
            if (!empty($nuevo_activo_fijo) && $nuevo_activo_fijo != $usuario_actual['activo_fijo']) {
                // Desasignar equipo anterior si existía
                if (!empty($usuario_actual['activo_fijo'])) {
                    $query_desasignar = "UPDATE equipos SET usuario_asignado = NULL WHERE activo_fijo = :activo_anterior";
                    $stmt_desasignar = $conn->prepare($query_desasignar);
                    $stmt_desasignar->bindParam(':activo_anterior', $usuario_actual['activo_fijo']);
                    $stmt_desasignar->execute();
                }
                
                // Asignar nuevo equipo
                $query_asignar = "UPDATE equipos SET usuario_asignado = :usuario_id, sede_id = :sede_id WHERE activo_fijo = :nuevo_activo";
                $stmt_asignar = $conn->prepare($query_asignar);
                $stmt_asignar->bindParam(':usuario_id', $user_id);
                $stmt_asignar->bindParam(':sede_id', $sede_id);
                $stmt_asignar->bindParam(':nuevo_activo', $nuevo_activo_fijo);
                $stmt_asignar->execute();
            } elseif (empty($nuevo_activo_fijo) && !empty($usuario_actual['activo_fijo'])) {
                // Desasignar equipo si se quitó el activo fijo
                $query_desasignar = "UPDATE equipos SET usuario_asignado = NULL WHERE activo_fijo = :activo_anterior";
                $stmt_desasignar = $conn->prepare($query_desasignar);
                $stmt_desasignar->bindParam(':activo_anterior', $usuario_actual['activo_fijo']);
                $stmt_desasignar->execute();
            }
            if ($es_supervisor && $tipo_supervisor && $area_supervisor) {
                // Verificar si ya es supervisor
                $query_check = "SELECT id FROM supervisores WHERE empleado_id = ?";
                $stmt_check = $conn->prepare($query_check);
                $stmt_check->execute([$user_id]);
                
                if ($stmt_check->rowCount() > 0) {
                    // Actualizar supervisor existente
                    $query_update_supervisor = "UPDATE supervisores SET 
                                               tipo_supervisor = ?, area_id = ?, updated_at = NOW() 
                                               WHERE empleado_id = ?";
                    $stmt_update = $conn->prepare($query_update_supervisor);
                    $stmt_update->execute([$tipo_supervisor, $area_supervisor, $user_id]);
                } else {
                    // Crear nuevo supervisor
                    $query_insert_supervisor = "INSERT INTO supervisores (empleado_id, tipo_supervisor, area_id) 
                                               VALUES (?, ?, ?)";
                    $stmt_insert = $conn->prepare($query_insert_supervisor);
                    $stmt_insert->execute([$user_id, $tipo_supervisor, $area_supervisor]);
                }
            } else {
                // Si no es supervisor, eliminar de la tabla supervisores
                $query_delete = "DELETE FROM supervisores WHERE empleado_id = ?";
                $stmt_delete = $conn->prepare($query_delete);
                $stmt_delete->execute([$user_id]);
            }
            
            // ========== ACTUALIZAR ÁREA DEL EMPLEADO ==========
            // Si se asignó un supervisor, actualizar el área del empleado al área del supervisor
            if (!empty($supervisor_id)) {
                $query_area_supervisor = "SELECT area_id FROM supervisores WHERE empleado_id = ? LIMIT 1";
                $stmt_area = $conn->prepare($query_area_supervisor);
                $stmt_area->execute([$supervisor_id]);
                $area_supervisor_data = $stmt_area->fetch(PDO::FETCH_ASSOC);
                
                if ($area_supervisor_data) {
                    $query_update_area = "UPDATE employee SET area_id = ? WHERE id = ?";
                    $stmt_update_area = $conn->prepare($query_update_area);
                    $stmt_update_area->execute([$area_supervisor_data['area_id'], $user_id]);
                }
            }else {
	    // Si se quitó el supervisor, quitar también el área
	    $query_remove_area = "UPDATE employee SET area_id = NULL WHERE id = ?";
	    $stmt_remove_area = $conn->prepare($query_remove_area);
	    $stmt_remove_area->execute([$user_id]);
	}
            
            $success = "Usuario actualizado correctamente";
        } else {
            $error = "Error al actualizar el usuario";
        }
    } catch (PDOException $e) {
        $error = "Error en la base de datos: " . $e->getMessage();
    }
}

// Procesar eliminación de usuario
if (isset($_GET['eliminar'])) {
    $id = $_GET['eliminar'];
    
    try {
        // Obtener información del usuario antes de eliminar
        $query_info = "SELECT first_Name, first_LastName, CC, activo_fijo FROM employee WHERE id = :id";
        $stmt_info = $conn->prepare($query_info);
        $stmt_info->bindParam(':id', $id);
        $stmt_info->execute();
        $usuario_info = $stmt_info->fetch(PDO::FETCH_ASSOC);
        
        if ($usuario_info) {
            // Desasignar equipo si tenía uno
            if (!empty($usuario_info['activo_fijo'])) {
                $query_desasignar = "UPDATE equipos SET usuario_asignado = NULL WHERE activo_fijo = :activo_fijo";
                $stmt_desasignar = $conn->prepare($query_desasignar);
                $stmt_desasignar->bindParam(':activo_fijo', $usuario_info['activo_fijo']);
                $stmt_desasignar->execute();
            }
            
            $query = "DELETE FROM employee WHERE id = :id";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':id', $id);
            
            if ($stmt->execute()) {
                // Registrar en historial
                $functions->registrarHistorial(
                    null,
                    $id,
                    null,
                    'eliminar_empleado',
                    1,
                    "Usuario eliminado: " . $usuario_info['first_Name'] . " " . $usuario_info['first_LastName'] . " (CC: " . $usuario_info['CC'] . ")"
                );
                
                $success = "Usuario eliminado correctamente";
            } else {
                $error = "Error al eliminar el usuario";
            }
        } else {
            $error = "El usuario no existe";
        }
    } catch (PDOException $e) {
        $error = "Error en la base de datos: " . $e->getMessage();
    }
}

// Obtener todas las firmas
$query_firmas = "SELECT id, name FROM firm ORDER by name";
$stmt_firmas = $conn->prepare($query_firmas);
$stmt_firmas->execute();
$firmas = $stmt_firmas->fetchAll(PDO::FETCH_ASSOC);

// Obtener phones disponibles
$query_phones = "SELECT p.id, p.phone, f.name as firm_name 
                 FROM phones p 
                 LEFT JOIN firm f ON p.id_firm = f.id 
                 WHERE p.id NOT IN (SELECT id_phone FROM employee WHERE id_phone IS NOT NULL)
                 ORDER BY f.name, p.phone";
$stmt_phones = $conn->prepare($query_phones);
$stmt_phones->execute();
$phones_disponibles = $stmt_phones->fetchAll(PDO::FETCH_ASSOC);

// Obtener parámetros de búsqueda y filtro
$busqueda = isset($_GET['busqueda']) ? trim($_GET['busqueda']) : '';
$filtro_firma = isset($_GET['firma']) ? $_GET['firma'] : '';
$filtro_rol = isset($_GET['rol']) ? $_GET['rol'] : '';
$filtro_estado = isset($_GET['estado']) ? $_GET['estado'] : 'activos';

// Parámetros de paginación
$resultados_por_pagina = isset($_GET['resultados_por_pagina']) ? (int)$_GET['resultados_por_pagina'] : 20;
$pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;

// Validar valores de paginación
if (!in_array($resultados_por_pagina, [20, 50, 100])) {
    $resultados_por_pagina = 20;
}
if ($pagina_actual < 1) {
    $pagina_actual = 1;
}

// Calcular offset
$offset = ($pagina_actual - 1) * $resultados_por_pagina;

// Construir consulta base para WHERE
$where_conditions = [];
$params = [];
$params_count = [];

if (!empty($busqueda)) {
    $where_conditions[] = "(e.first_Name LIKE :busqueda OR e.first_LastName LIKE :busqueda OR e.CC LIKE :busqueda OR e.mail LIKE :busqueda)";
    $params[':busqueda'] = "%$busqueda%";
    $params_count[':busqueda'] = "%$busqueda%";
}

if (!empty($filtro_firma)) {
    $where_conditions[] = "e.id_firm = :firma";
    $params[':firma'] = $filtro_firma;
    $params_count[':firma'] = $filtro_firma;
}

if (!empty($filtro_rol)) {
    $where_conditions[] = "e.role = :rol";
    $params[':rol'] = $filtro_rol;
    $params_count[':rol'] = $filtro_rol;
}

// Filtro de estado (activos/retirados/candidatos/todos)
if ($filtro_estado === 'activos') {
    $where_conditions[] = "e.role != 'retirado' AND e.role != 'candidato'";
} elseif ($filtro_estado === 'retirados') {
    $where_conditions[] = "e.role = 'retirado'";
} elseif ($filtro_estado === 'candidatos') {
    $where_conditions[] = "e.role = 'candidato'";
}

// Construir consulta para obtener usuarios (conteo total)
$query_count = "SELECT COUNT(*) as total 
          FROM employee e 
          LEFT JOIN sedes s ON e.sede_id = s.id
          LEFT JOIN firm f ON e.id_firm = f.id 
          LEFT JOIN phones p ON e.id_phone = p.id
          LEFT JOIN location l ON e.locacion_id = l.id";

if (!empty($where_conditions)) {
    $query_count .= " WHERE " . implode(" AND ", $where_conditions);
}

// Construir consulta para obtener usuarios (datos)
 $query = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name, p.phone as assigned_phone,
                 l.name as locacion_nombre, l.id as locacion_id,
                 supervisor.first_Name as supervisor_nombre, 
                 supervisor.first_LastName as supervisor_apellido,
                 a.nombre as area_nombre,
                 c.nombre as position_nombre
          FROM employee e 
          LEFT JOIN sedes s ON e.sede_id = s.id
          LEFT JOIN firm f ON e.id_firm = f.id 
          LEFT JOIN phones p ON e.id_phone = p.id
          LEFT JOIN location l ON e.locacion_id = l.id
          LEFT JOIN employee supervisor ON e.supervisor_id = supervisor.id
          LEFT JOIN areas a ON e.area_id = a.id
          LEFT JOIN cargos c ON e.position_id = c.id";
if (!empty($where_conditions)) {
    $query .= " WHERE " . implode(" AND ", $where_conditions);
}

$query .= " ORDER BY e.first_Name, e.first_LastName";
$query .= " LIMIT :limit OFFSET :offset";

// Ejecutar consulta de conteo
try {
    $stmt_count = $conn->prepare($query_count);
    foreach ($params_count as $key => $value) {
        $stmt_count->bindValue($key, $value);
    }
    $stmt_count->execute();
    $total_resultados = $stmt_count->fetch(PDO::FETCH_ASSOC)['total'];
    $total_paginas = $total_resultados > 0 ? ceil($total_resultados / $resultados_por_pagina) : 1;
} catch (PDOException $e) {
    error_log("Error en consulta COUNT: " . $e->getMessage());
    $total_resultados = 0;
    $total_paginas = 1;
}

// Ajustar página actual si es mayor que el total de páginas
if ($pagina_actual > $total_paginas && $total_paginas > 0) {
    $pagina_actual = $total_paginas;
    $offset = ($pagina_actual - 1) * $resultados_por_pagina;
}

// Ejecutar consulta de datos
try {
    $stmt = $conn->prepare($query);
    
    // Bind de parámetros de búsqueda
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    
    // Bind de parámetros de paginación
    $stmt->bindValue(':limit', $resultados_por_pagina, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    
    $stmt->execute();
    $usuarios = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error en consulta principal: " . $e->getMessage());
    $usuarios = [];
    $error = "Error al cargar los usuarios: " . $e->getMessage();
}

// Función para obtener color de badge según rol
function getRoleBadgeColor($role) {
    $colors = [
        'administrador' => 'danger',
        'supervisor' => 'info',
        'it' => 'primary',
        'nomina' => 'warning',
        'talento_humano' => 'info',
        'empleado' => 'secondary',
        'retirado' => 'dark',
        'candidato' => 'success'
    ];
    return $colors[$role] ?? 'secondary';
}

// Función auxiliar para generar URLs de paginación
function generarUrlPaginacion($pagina, $resultados_por_pagina) {
    $params = $_GET;
    $params['pagina'] = $pagina;
    $params['resultados_por_pagina'] = $resultados_por_pagina;
    return '?' . http_build_query($params);
}

// Funciones para manejo de fotos
function procesarFotoUsuario($file, $cc) {
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/photos/';
    
    // Crear directorio si no existe
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Validar tipo de archivo
    $allowed_types = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception('Solo se permiten archivos JPEG, PNG y GIF');
    }
    
    // Validar tamaño (máximo 2MB)
    if ($file['size'] > 2 * 1024 * 1024) {
        throw new Exception('La imagen no debe superar los 2MB');
    }
    
    // Generar nombre único
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $file_name = 'user_' . $cc . '_' . time() . '.' . $extension;
    $file_path = $upload_dir . $file_name;
    
    // Mover archivo
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('Error al subir la imagen');
    }
    
    // Redimensionar imagen si es muy grande
    redimensionarImagen($file_path, 300, 300);
    
    return $file_name;
}

function eliminarFotoUsuario($photo_name) {
    if ($photo_name && !empty($photo_name)) {
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/photos/' . $photo_name;
        if (file_exists($file_path)) {
            unlink($file_path);
            return true;
        }
    }
    return false;
}

function redimensionarImagen($file_path, $max_width, $max_height) {
    if (!file_exists($file_path)) return;
    
    list($width, $height, $type) = getimagesize($file_path);
    
    if ($width <= $max_width && $height <= $max_height) {
        return;
    }
    
    $ratio = min($max_width/$width, $max_height/$height);
    $new_width = round($width * $ratio);
    $new_height = round($height * $ratio);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($file_path);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($file_path);
            break;
        case IMAGETYPE_GIF:
            $image = imagecreatefromgif($file_path);
            break;
        default:
            return;
    }
    
    $new_image = imagecreatetruecolor($new_width, $new_height);
    
    // Preservar transparencia para PNG y GIF
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF) {
        imagecolortransparent($new_image, imagecolorallocatealpha($new_image, 0, 0, 0, 127));
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
    }
    
    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($new_image, $file_path, 90);
            break;
        case IMAGETYPE_PNG:
            imagepng($new_image, $file_path, 9);
            break;
        case IMAGETYPE_GIF:
            imagegif($new_image, $file_path);
            break;
    }
    
    imagedestroy($image);
    imagedestroy($new_image);
}

function obtenerFotoUsuario($photo_name, $default = 'default_avatar.png') {
    // Primero verificar si existe la foto del usuario
    if ($photo_name && !empty($photo_name)) {
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/photos/' . $photo_name;
        if (file_exists($file_path)) {
            return '/uploads/photos/' . $photo_name;
        }
    }
    
    // Verificar si existe el avatar por defecto
    $default_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/' . $default;
    if (!file_exists($default_path)) {
        crearAvatarPorDefecto();
    }
    
    return '/assets/images/' . $default;
}

function crearAvatarPorDefecto() {
    $default_path = $_SERVER['DOCUMENT_ROOT'] . '/assets/images/default_avatar.png';
    
    if (!file_exists($default_path)) {
        $image = imagecreate(100, 100);
        $background = imagecolorallocate($image, 108, 117, 125); // Color gris bootstrap
        $text_color = imagecolorallocate($image, 255, 255, 255);
        
        // Crear un círculo
        imagefilledellipse($image, 50, 50, 90, 90, $background);
        
        // Agregar texto "USR"
        imagestring($image, 3, 35, 40, 'USR', $text_color);
        
        // Crear directorio si no existe
        $assets_dir = dirname($default_path);
        if (!is_dir($assets_dir)) {
            mkdir($assets_dir, 0755, true);
        }
        
        imagepng($image, $default_path);
        imagedestroy($image);
    }
}

// Llamar la función para crear el avatar por defecto
crearAvatarPorDefecto();

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Usuarios - <?php echo SITE_NAME; ?></title>
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
    
    .badge.bg-dark {
        background-color: #353132 !important;
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
    .badge-role {
        font-size: 0.8em;
    }
    .table-responsive {
        min-height: 400px;
    }
    .user-actions {
        white-space: nowrap;
    }
    .user-info td {
        padding: 8px 12px;
        border-bottom: 1px solid #eee;
    }
    .user-info td:first-child {
        font-weight: bold;
        width: 30%;
        color: #555;
    }
    .form-section {
        background-color: #f8f9fa;
        padding: 15px;
        border-radius: 5px;
        margin-bottom: 20px;
    }
    .form-section h6 {
        color: #495057;
        margin-bottom: 15px;
        padding-bottom: 5px;
        border-bottom: 2px solid #003a5d;
    }
    .user-row {
        cursor: pointer;
        transition: background-color 0.2s;
    }
    .user-row:hover {
        background-color: #f8f9fa;
    }
    .activo-fijo-link {
        color: #0d6efd;
        text-decoration: underline;
        cursor: pointer;
    }
    .activo-fijo-link:hover {
        color: #0a58ca;
    }
    .pagination-container {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 20px;
        flex-wrap: wrap;
    }
    .results-per-page {
        display: flex;
        align-items: center;
        margin-bottom: 10px;
    }
    .results-per-page select {
        margin: 0 10px;
        width: auto;
    }
    .pagination-info {
        margin: 10px 0;
        text-align: center;
    }
    @media (max-width: 768px) {
        .pagination-container {
            flex-direction: column;
        }
        .results-per-page {
            margin-bottom: 15px;
        }
    }
    
    /* Estilos para pestañas en modales */
    .modal .nav-tabs .nav-link {
        color: #495057 !important;
        font-weight: normal;
        border: 1px solid transparent;
        border-bottom: none;
    }
    
    .modal .nav-tabs .nav-link.active {
        color: #495057 !important;
        font-weight: bold;
        background-color: #fff;
        border-color: #dee2e6 #dee2e6 #fff;
    }
    
    .modal .nav-tabs .nav-link:not(.active) {
        color: #6c757d !important;
        background-color: #f8f9fa;
    }
    
    /* Asegurar que el texto sea siempre visible en modales */
    .modal .nav-link {
        color: inherit !important;
        opacity: 1 !important;
    }
    
    .modal .nav-link.active {
        color: inherit !important;
        background-color: white !important;
    }
    
    /* Estilos para fotos */
    .photo-preview-sm {
        width: 32px;
        height: 32px;
        object-fit: cover;
        border: 2px solid #fff;
    }
    
    .header-photo {
        width: 40px;
        height: 40px;
        object-fit: cover;
        border: 2px solid rgba(255, 255, 255, 0.3);
    }
    
    .photo-preview {
        width: 100px;
        height: 100px;
        object-fit: cover;
        border: 2px solid #dee2e6;
    }
    
    .photo-preview-lg {
        width: 150px;
        height: 150px;
        object-fit: cover;
        border: 3px solid #003a5d;
    }
    
    /* Asegurar que las imágenes sean circulares */
    .rounded-circle {
        border-radius: 50% !important;
    }
    
    /* Color personalizado para el header */
    .navbar-custom {
        background-color: #8c0b0b !important;
    }
    
    .navbar-custom .navbar-brand,
    .navbar-custom .navbar-nav .nav-link {
        color: white !important;
    }
    /* Estilos para filas clickeables de tickets */
.ticket-row {
    transition: all 0.2s ease-in-out;
}

.ticket-row:hover {
    background-color: #e9ecef !important;
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table-hover .ticket-row:hover {
    background-color: #e9ecef !important;
}

/* Indicador visual de que la fila es clickeable */
.ticket-row td {
    position: relative;
}

.ticket-row:hover td:first-child::before {
    content: "👁️";
    position: absolute;
    left: 5px;
    top: 50%;
    transform: translateY(-50%);
    font-size: 12px;
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
		    <h1 class="h2">Gestión de Usuarios</h1>
		    <?php if ($permiso_crear_usuario || $permiso_generar_informe): ?>
		    <div class="btn-group">
		        <?php if ($permiso_crear_usuario): ?>
		        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCrearUsuario">
		            <i class="bi bi-person-plus"></i> Nuevo Usuario
		        </button>
		        <?php endif; ?>
		        <?php if ($permiso_generar_informe): ?>
		        <a href="generar_informe_usuarios_archivos.php" class="btn btn-outline-success">
		            <i class="bi bi-file-earmark-text"></i> Generar Informe
		        </a>
		        <?php endif; ?>
		    </div>
		    <?php endif; ?>
		</div>
                
                
                <?php if ($error): ?>
                <div class="alert alert-danger"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <!-- Filtros -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" action="" id="formBusqueda">
                            <input type="hidden" name="pagina" value="1">
                            <div class="row">
                                <div class="col-md-3">
                                    <label for="busqueda" class="form-label">Buscar usuarios</label>
                                    <input type="text" class="form-control" id="busqueda" name="busqueda" 
                                           value="<?php echo htmlspecialchars($busqueda); ?>" 
                                           placeholder="Nombre, apellido, documento o correo">
                                </div>
                                <div class="col-md-2">
                                    <label for="firma" class="form-label">Firma</label>
                                    <select class="form-select" id="firma" name="firma">
                                        <option value="">Todas las firmas</option>
                                        <?php foreach ($firmas as $firma): ?>
                                        <option value="<?php echo $firma['id']; ?>" <?php echo $filtro_firma == $firma['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($firma['name']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="rol" class="form-label">Rol</label>
                                    <select class="form-select" id="rol" name="rol">
                                        <option value="">Todos los roles</option>
                                        <option value="empleado" <?php echo $filtro_rol == 'empleado' ? 'selected' : ''; ?>>Empleado</option>
                                        <option value="administrador" <?php echo $filtro_rol == 'administrador' ? 'selected' : ''; ?>>Administrador</option>
                                        <option value="supervisor" <?php echo $filtro_rol == 'supervisor' ? 'selected' : ''; ?>>Supervisor</option> 
                                        <option value="nomina" <?php echo $filtro_rol == 'nomina' ? 'selected' : ''; ?>>Nómina</option>
                                        <option value="talento_humano" <?php echo $filtro_rol == 'talento_humano' ? 'selected' : ''; ?>>Talento Humano</option>
                                        <option value="it" <?php echo $filtro_rol == 'it' ? 'selected' : ''; ?>>IT</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label for="estado" class="form-label">Estado</label>
                                    <select class="form-select" id="estado" name="estado">
                                        <option value="activos" <?php echo $filtro_estado == 'activos' ? 'selected' : ''; ?>>Activos</option>
                                        <option value="retirados" <?php echo $filtro_estado == 'retirados' ? 'selected' : ''; ?>>Retirados</option>
                                        <option value="candidatos" <?php echo $filtro_estado == 'candidatos' ? 'selected' : ''; ?>>Candidatos</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">&nbsp;</label><br>
                                    <button type="submit" class="btn btn-primary">Filtrar</button>
                                    <a href="?" class="btn btn-secondary">Limpiar</a>
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
                                        <th>Nombre Completo</th>
                                        <th>Documento</th>
                                        <th>Correo</th>
                                        <th>Extensión</th>
                                        <th>Activo Fijo</th>
                                        <th>Rol</th>
                                        <th>Acciones</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($usuarios) > 0): ?>
                                        <?php foreach ($usuarios as $usuario): ?>
                                        <tr class="user-row" onclick="verUsuario(<?php echo $usuario['id']; ?>)">
                                            <td>
                                                <strong><?php echo htmlspecialchars($usuario['first_Name'] . ' ' . $usuario['first_LastName']); ?></strong>
                                                <?php if (!empty($usuario['second_Name']) || !empty($usuario['second_LastName'])): ?>
                                                <br><small class="text-muted">
                                                    <?php echo htmlspecialchars($usuario['second_Name'] . ' ' . $usuario['second_LastName']); ?>
                                                </small>
                                                <?php endif; ?>
                                                <?php if (!empty($usuario['firm_name'])): ?>
                                                <br><small class="text-info"><?php echo htmlspecialchars($usuario['firm_name']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo $usuario['CC']; ?>
                                                <br><small class="text-muted">
                                                    <?php 
                                                    $tipo_doc = [
                                                        1 => 'Cédula',
                                                        2 => 'Pasaporte', 
                                                        3 => 'Cédula Extranjería'
                                                    ];
                                                    echo $tipo_doc[$usuario['type_CC']] ?? 'Desconocido';
                                                    ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($usuario['mail']); ?>
                                                <?php if (!empty($usuario['personal_mail'])): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($usuario['personal_mail']); ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($usuario['extension'] ?? 'N/A'); ?></td>
                                            <td>
                                                <?php if (!empty($usuario['activo_fijo'])): ?>
                                                    <span class="activo-fijo-link" onclick="event.stopPropagation(); verEquipoPorActivoFijo('<?php echo htmlspecialchars($usuario['activo_fijo']); ?>')">
                                                        <?php echo htmlspecialchars($usuario['activo_fijo']); ?>
                                                    </span>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo getRoleBadgeColor($usuario['role']); ?> badge-role">
                                                    <?php echo ucfirst($usuario['role']); ?>
                                                </span>
                                            </td>
						<td class="user-actions" onclick="event.stopPropagation();">
						    <?php if ($permiso_editar_usuario): ?>
						    <button type="button" class="btn btn-sm btn-outline-primary" 
						            data-bs-toggle="modal" 
						            data-bs-target="#modalEditarUsuario<?php echo $usuario['id']; ?>">
						        <i class="bi bi-pencil"></i> Editar
						    </button>
						    <?php endif; ?>
						    
						    <?php if ($permiso_eliminar_usuario && $usuario['role'] != 'retirado'): ?>
						    <a href="?eliminar=<?php echo $usuario['id']; ?>" class="btn btn-sm btn-danger" 
						       onclick="return confirm('¿Está seguro de eliminar este usuario?')">
						        <i class="bi bi-trash"></i> Eliminar
						    </a>
						    <?php endif; ?>
						</td>
                                        </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7" class="text-center">
                                                <?php if (!empty($busqueda) || !empty($filtro_firma) || !empty($filtro_rol)): ?>
                                                No se encontraron usuarios que coincidan con los filtros
                                                <?php else: ?>
                                                No hay usuarios registrados
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Paginación -->
                        <?php if ($total_resultados > 0): ?>
                        <div class="pagination-container">
                            <div class="results-per-page">
                                <span>Resultados por página:</span>
                                <select class="form-select form-select-sm" id="resultados_por_pagina" name="resultados_por_pagina" onchange="cambiarResultadosPorPagina(this.value)">
                                    <option value="20" <?php echo $resultados_por_pagina == 20 ? 'selected' : ''; ?>>20</option>
                                    <option value="50" <?php echo $resultados_por_pagina == 50 ? 'selected' : ''; ?>>50</option>
                                    <option value="100" <?php echo $resultados_por_pagina == 100 ? 'selected' : ''; ?>>100</option>
                                </select>
                            </div>
                            
                            <div class="pagination-info">
                                <span>Mostrando <?php echo ($offset + 1); ?> - <?php echo min($offset + $resultados_por_pagina, $total_resultados); ?> de <?php echo $total_resultados; ?> resultados</span>
                            </div>
                            
                            <nav aria-label="Page navigation">
                                <ul class="pagination pagination-sm">
                                    <?php if ($pagina_actual > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo generarUrlPaginacion($pagina_actual - 1, $resultados_por_pagina); ?>" aria-label="Anterior">
                                            <span aria-hidden="true">&laquo;</span>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                    
                                    <?php
                                    // Mostrar páginas (máximo 5 páginas alrededor de la actual)
                                    $inicio = max(1, $pagina_actual - 2);
                                    $fin = min($total_paginas, $pagina_actual + 2);
                                    
                                    if ($inicio > 1) {
                                        echo '<li class="page-item"><a class="page-link" href="' . generarUrlPaginacion(1, $resultados_por_pagina) . '">1</a></li>';
                                        if ($inicio > 2) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                    }
                                    
                                    for ($i = $inicio; $i <= $fin; $i++): 
                                    ?>
                                    <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                                        <a class="page-link" href="<?php echo generarUrlPaginacion($i, $resultados_por_pagina); ?>"><?php echo $i; ?></a>
                                    </li>
                                    <?php endfor; ?>
                                    
                                    <?php
                                    if ($fin < $total_paginas) {
                                        if ($fin < $total_paginas - 1) {
                                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                                        }
                                        echo '<li class="page-item"><a class="page-link" href="' . generarUrlPaginacion($total_paginas, $resultados_por_pagina) . '">' . $total_paginas . '</a></li>';
                                    }
                                    ?>
                                    
                                    <?php if ($pagina_actual < $total_paginas): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="<?php echo generarUrlPaginacion($pagina_actual + 1, $resultados_por_pagina); ?>" aria-label="Siguiente">
                                            <span aria-hidden="true">&raquo;</span>
                                        </a>
                                    </li>
                                    <?php endif; ?>
                                </ul>
                            </nav>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </main>
        </div>
    </div>
    <!-- Modal para crear usuario -->
<div class="modal fade" id="modalCrearUsuario" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Crear 
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <div class="modal-body">
                    <div class="form-section">
                        <h6>Información Personal</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="first_Name" class="form-label">Primer Nombre *</label>
                                    <input type="text" class="form-control" id="first_Name" name="first_Name" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="second_Name" class="form-label">Segundo Nombre</label>
                                    <input type="text" class="form-control" id="second_Name" name="second_Name">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="first_LastName" class="form-label">Primer Apellido *</label>
                                    <input type="text" class="form-control" id="first_LastName" name="first_LastName" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="second_LastName" class="form-label">Segundo Apellido</label>
                                    <input type="text" class="form-control" id="second_LastName" name="second_LastName">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="type_CC" class="form-label">Tipo Documento *</label>
                                    <select class="form-select" id="type_CC" name="type_CC" required>
                                        <option value="1">Cédula</option>
                                        <option value="2">Pasaporte</option>
                                        <option value="3">Cédula Extranjería</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="CC" class="form-label">Número Documento *</label>
                                    <input type="text" class="form-control" id="CC" name="CC" 
                                           pattern="[0-9]*" inputmode="numeric" oninput="this.value = this.value.replace(/[^0-9]/g, '')" required>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="birthdate" class="form-label">Fecha Nacimiento *</label>
                                    <input type="date" class="form-control" id="birthdate" name="birthdate" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="estado_civil" class="form-label">Estado Civil</label>
                                    <select class="form-select" name="dval">
					    <option value="">Seleccione estado civil</option>
					    <option value="1">Soltero/a</option>
					    <option value="2">Casado/a</option>
					    <option value="3">Divorciado/a</option>
					    <option value="4">Viudo/a</option>
					    <option value="5">Unión Libre</option>
					</select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="country" class="form-label">País</label>
                                    <input type="text" class="form-control" id="country" name="country" value="Colombia">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="city" class="form-label">Ciudad</label>
                                    <input type="text" class="form-control" id="city" name="city">
                                </div>
                            </div>
                            <div class="col-12">
				    <div class="mb-3">
				        <label for="create_address" class="form-label">Dirección</label>
				        <textarea class="form-control" id="create_address" name="address" rows="2" placeholder="Ingrese la dirección completa"></textarea>
				    </div>
			    </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="personal_mail" class="form-label">Correo Personal</label>
                                    <input type="email" class="form-control" id="personal_mail" name="personal_mail">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="phone" class="form-label">Teléfono Personal</label>
                                    <input type="text" class="form-control" id="phone" name="phone">
                                </div>
                            </div>
                        </div>
                        <div class="form-section">
			    <h6>Foto de Perfil</h6>
			    <div class="row">
			        <div class="col-md-6">
			            <div class="mb-3">
			                <label for="photo" class="form-label">Foto</label>
			                <input type="file" class="form-control" id="photo" name="photo" accept="image/*">
			                <small class="form-text text-muted">Formatos permitidos: JPG, PNG, GIF. Máximo 2MB.</small>
			            </div>
			        </div>
			    </div>
			</div>
                    </div>

                    <div class="form-section">
                        <h6>Información Laboral</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="mail" class="form-label">Correo Empresarial *</label>
                                    <div class="input-group">
                                        <input type="email" class="form-control" id="mail" name="mail" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="password" class="form-label">Contraseña *</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" id="password" name="password" value="<?php echo 'z' . ($_POST['CC'] ?? '') . 'Z@!$'; ?>" required>
                                    </div>
                                    <small class="form-text">No modificar para crear contraseña genérica</small>
                                </div>
                            </div>
                            <div class="col-md-6">
				   <div class="mb-3" style="display: none;">
				        <label for="pin" class="form-label">PIN *</label>
				        <input type="text" class="form-control" id="pin" name="pin" 
				               value="<?php echo isset($_POST['CC']) ? substr($_POST['CC'], -4) : ''; ?>" required readonly>
				        <small class="form-text">Generado automáticamente desde el documento</small>
				   </div>
			    </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="sede_id" class="form-label">Sede *</label>
                                    <select class="form-select" id="sede_id" name="sede_id" required>
                                        <option value="">Seleccione una sede</option>
                                        <?php foreach ($sedes as $sede): ?>
                                        <option value="<?php echo $sede['id']; ?>"><?php echo htmlspecialchars($sede['nombre']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label for="company" class="form-label">Compañía *</label>
                                    <select class="form-select" id="company" name="company" required>
                                        <option value="1">AZC</option>
                                        <option value="2">BilingueLaw</option>
                                        <option value="3">LawyerDesk</option>
                                        <option value="4">AZC Legal</option>
                                        <option value="5">Matiz LegalTech</option>
                                    </select>
                                </div>
                            </div>
                            <div class="row">
				    <div class="col-md-6">
				        <div class="mb-3">
				            <label for="id_firm" class="form-label">Firma</label>
				            <select class="form-select" id="id_firm" name="id_firm">
				                <option value="">Seleccione una firma</option>
				                <?php foreach ($firmas as $firma): ?>
				                <option value="<?php echo $firma['id']; ?>"><?php echo htmlspecialchars($firma['name']); ?></option>
				                <?php endforeach; ?>
				            </select>
				        </div>
				    </div>
				    <div class="col-md-6">
				        <div class="mb-3">
				            <label for="locacion_id" class="form-label">Locación</label>
				            <select class="form-select" id="locacion_id" name="locacion_id">
				                <option value="">Seleccione una firma primero</option>
				            </select>
				        </div>
				    </div>
				</div>
				<div class="row">
				    <div class="col-md-6">
				        <div class="mb-3">
				            <label for="position_id" class="form-label">Cargo *</label>
				            <select class="form-select" id="position_id" name="position_id" required>
				                <option value="">Seleccione un cargo</option>
				                <?php foreach ($cargos as $cargo): ?>
				                <option value="<?php echo $cargo['id']; ?>"><?php echo htmlspecialchars($cargo['nombre']); ?></option>
				                <?php endforeach; ?>
				            </select>
				        </div>
				    </div>
				</div>
				<div class="row">
				    <div class="col-md-6">
				        <div class="mb-3">
				            <label class="form-label">Supervisor Inmediato</label>
				            <select class="form-select" name="supervisor_id">
				                <option value="">Sin supervisor</option>
				                <?php foreach ($supervisores_disponibles as $supervisor): ?>
				                <option value="<?php echo $supervisor['emp_id']; ?>" 
				                        <?php echo (isset($usuario) && $usuario['supervisor_id'] == $supervisor['emp_id']) ? 'selected' : ''; ?>>
				                    <?php echo htmlspecialchars($supervisor['first_Name'] . ' ' . $supervisor['first_LastName'] . ' - ' . 
				                           ucfirst($supervisor['tipo_supervisor']) . ' de ' . $supervisor['area_nombre']); ?>
				                </option>
				                <?php endforeach; ?>
				            </select>
				            <small class="form-text">Al asignar un supervisor, el empleado heredará automáticamente el área</small>
				        </div>
				    </div>
				    <div class="col-md-6">
				        <div class="mb-3">
				            <label class="form-label">Área Actual</label>
				            <p class="form-control-plaintext">
				                <?php 
				                if (isset($usuario) && !empty($usuario['area_id'])) {
				                    // Buscar nombre del área
				                    $query_area_nombre = "SELECT nombre FROM areas WHERE id = ?";
				                    $stmt_area_nombre = $conn->prepare($query_area_nombre);
				                    $stmt_area_nombre->execute([$usuario['area_id']]);
				                    $area_nombre = $stmt_area_nombre->fetch(PDO::FETCH_COLUMN);
				                    echo htmlspecialchars($area_nombre ?: 'No asignada');
				                } else {
				                    echo 'No asignada (se asignará al seleccionar supervisor)';
				                }
				                ?>
				            </p>
				        </div>
				    </div>
				</div>
	                        <div class="row">
	                            <div class="col-md-4">
	                                <div class="mb-3">
	                                    <label for="extension" class="form-label">Extensión</label>
	                                    <input type="text" class="form-control" id="extension" name="extension">
	                                </div>
	                            </div>
	                            <div class="col-md-4">
	                                <div class="mb-3">
	                                    <label for="activo_fijo" class="form-label">Activo Fijo</label>
	                                    <input type="text" class="form-control" id="activo_fijo" name="activo_fijo">
	                                </div>
	                            </div>
	                            <div class="col-md-4">
	                                <div class="mb-3">
	                                    <label for="role" class="form-label">Rol *</label>
	                                    <select class="form-select" id="role" name="role" required>
	                                        <option value="empleado">Empleado</option>
	                                        <option value="administrador">Administrador</option>
	                                        <option value="nomina">Nómina</option>
	                                        <option value="talento_humano">Talento Humano</option>
	                                        <option value="it">IT</option>
	                                    </select>
	                                </div>
	                            </div>
	                        </div>
	                        <div class="row">
	                            <div class="col-md-6">
	                                <div class="mb-3 form-check">
	                                    <input type="checkbox" class="form-check-input" id="needs_phone" name="needs_phone">
	                                    <label class="form-check-label" for="needs_phone">Necesita teléfono corporativo</label>
	                                </div>
	                                <div class="mb-3" id="phone_selection" style="display: none;">
	                                    <label for="id_phone" class="form-label">Teléfono Corporativo</label>
	                                    <select class="form-select" id="id_phone" name="id_phone">
	                                        <option value="">Seleccione un teléfono</option>
	                                        <?php foreach ($phones_disponibles as $phone): ?>
	                                        <option value="<?php echo $phone['id']; ?>">
	                                            <?php echo htmlspecialchars($phone['firm_name'] . ' - ' . $phone['phone']); ?>
	                                        </option>
	                                        <?php endforeach; ?>
	                                    </select>
	                                </div>
	                            </div>
	                        </div>
	                    </div>
			</div>
			</div>
	                <div class="modal-footer">
	                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
	                    <button type="submit" name="crear_usuario" class="btn btn-primary">Crear Usuario</button>
	                </div>
	            </form>
	        </div>
	    </div>
	</div>
    
    <!-- Modales para información de usuarios -->
    <?php foreach ($usuarios as $usuario): ?>
    <!-- Modal de Información Completa -->
    <div class="modal fade" id="modalInfoUsuario<?php echo $usuario['id']; ?>" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header text-white" style="background-color: #003a5d;">
                <?php
		// Definir array de compañías
		$companias = [
		    1 => 'AZC',
		    2 => 'BilingueLaw', 
		    3 => 'LawyerDesk',
		    4 => 'AZC Legal',
		    5 => 'Matiz LegalTech'
		];
		?>
                    <h5 class="modal-title">Información Completa - <?php echo htmlspecialchars($usuario['first_Name'] . ' ' . $usuario['first_LastName']); ?></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
		    <div class="row">
		        <div class="col-md-6">
		            <h5>Información Personal</h5>
		            <table class="table table-sm user-info">
		            <?php 
                    $foto_modal = $functions->obtenerFotoUsuario($usuario['photo'] ?? null);
                    ?>
                    <img src="<?php echo $foto_modal; ?>" 
                         class="rounded-circle me-3" 
                         style="width: 200px; height: 200px; object-fit: cover; border: 2px solid white;"
                         alt="Foto de perfil">
                         
		                <tr><td>Nombre Completo:</td><td><?php echo htmlspecialchars($usuario['first_Name'] . ' ' . $usuario['second_Name'] . ' ' . $usuario['first_LastName'] . ' ' . $usuario['second_LastName']); ?></td></tr>
		                <tr><td>Documento:</td><td><?php echo $usuario['CC']; ?> (<?php echo $tipo_doc[$usuario['type_CC']] ?? 'Desconocido'; ?>)</td></tr>
		                <tr><td>Fecha Nacimiento:</td><td><?php echo date('d/m/Y', strtotime($usuario['birthdate'])); ?></td></tr>
		                <tr>
				    <td>Estado Civil:</td>
				    <td>
				        <?php 
				        $dval_valor = $usuario['dval'] ?? '';
				        $estados_civiles = [
				            1 => 'Soltero/a',
				            2 => 'Casado/a',
				            3 => 'Divorciado/a', 
				            4 => 'Viudo/a',
				            5 => 'Unión Libre'
				        ];
				        echo $estados_civiles[$dval_valor] ?? 'No especificado';
				        ?>
				    </td>
				</tr>
		                <tr><td>Correo Personal:</td><td><?php echo htmlspecialchars($usuario['personal_mail'] ?? 'No registrado'); ?></td></tr>
		                <tr><td>Teléfono Personal:</td><td><?php echo htmlspecialchars($usuario['phone'] ?? 'No registrado'); ?></td></tr>
		                <tr><td>País:</td><td><?php echo htmlspecialchars($usuario['country'] ?? 'Colombia'); ?></td></tr>
		                <tr><td>Ciudad:</td><td><?php echo htmlspecialchars($usuario['city'] ?? 'No registrada'); ?></td></tr>
		                <tr><td>Dirección:</td><td><?php echo htmlspecialchars($usuario['address'] ?? 'No registrada'); ?></td></tr>
		                
		            </table>
		        </div>
		        <div class="col-md-6">
		            <h5>Información Laboral</h5>
		            <table class="table table-sm user-info">
		                <tr>
		                    <td>Correo Empresarial:</td>
		                    <td>
		                        <?php echo htmlspecialchars($usuario['mail']); ?>
		                    </td>
		                </tr>
		                <tr>
		                    <td>Contraseña:</td>
		                    <td>
		                        <?php echo htmlspecialchars($usuario['password']); ?>
		                    </td>
		                </tr>
		                <tr><td>Compañía:</td><td><?php echo $companias[$usuario['company']] ?? 'Desconocida'; ?></td></tr>
		                <tr><td>Sede:</td><td><?php echo htmlspecialchars($usuario['sede_nombre'] ?? 'Sin sede asignada'); ?></td></tr>
		                <tr><td>Firma:</td><td><?php echo htmlspecialchars($usuario['firm_name'] ?? 'No asignada'); ?></td></tr>
		                <tr><td>Locación:</td><td><?php echo htmlspecialchars($usuario['locacion_nombre'] ?? 'No especificada'); ?></td></tr>
		                <tr><td>Cargo:</td><td><?php echo htmlspecialchars($usuario['position_nombre'] ?? 'No asignado'); ?></td></tr>
		                <tr><td>Extensión:</td><td><?php echo htmlspecialchars($usuario['extension'] ?? 'N/A'); ?></td></tr>
		                <tr>
		                    <td>Activo Fijo:</td>
		                    <td>
		                        <?php if (!empty($usuario['activo_fijo'])): ?>
		                            <span class="activo-fijo-link" onclick="verEquipoPorActivoFijo('<?php echo htmlspecialchars($usuario['activo_fijo']); ?>')">
		                                <?php echo htmlspecialchars($usuario['activo_fijo']); ?>
		                            </span>
		                        <?php else: ?>
		                            No asignado
		                        <?php endif; ?>
		                    </td>
		                </tr>
		                <tr><td>Rol:</td><td><?php echo ucfirst($usuario['role']); ?></td></tr>
		                <tr>
				    <td>PIN:</td>
				    <td><?php echo htmlspecialchars($usuario['pin'] ?? 'No asignado'); ?></td>
				</tr>
		                <tr><td>Teléfono Corporativo:</td><td><?php echo htmlspecialchars($usuario['assigned_phone'] ?? 'No asignado'); ?></td></tr>
		                 <tr>
                                <td>Área:</td>
                                <td>
                                    <?php
                                    $area_nombre = 'No asignada';
                                    if (!empty($usuario['area_id'])) {
                                        try {
                                            $query_area = "SELECT nombre FROM areas WHERE id = ?";
                                            $stmt_area = $conn->prepare($query_area);
                                            $stmt_area->execute([$usuario['area_id']]);
                                            $area_data = $stmt_area->fetch(PDO::FETCH_ASSOC);
                                            $area_nombre = $area_data ? htmlspecialchars($area_data['nombre']) : 'Área no encontrada';
                                        } catch (Exception $e) {
                                            $area_nombre = 'Error al cargar área';
                                            error_log("Error cargando área: " . $e->getMessage());
                                        }
                                    }
                                    echo $area_nombre;
                                    ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Supervisor Inmediato:</td>
                                <td>
                                    <?php
                                    $supervisor_nombre = 'No asignado';
                                    if (!empty($usuario['supervisor_id'])) {
                                        try {
                                            $query_supervisor = "SELECT first_Name, first_LastName, position FROM employee WHERE id = ?";
                                            $stmt_supervisor = $conn->prepare($query_supervisor);
                                            $stmt_supervisor->execute([$usuario['supervisor_id']]);
                                            $supervisor_data = $stmt_supervisor->fetch(PDO::FETCH_ASSOC);
                                            
                                            if ($supervisor_data) {
                                                $supervisor_nombre = htmlspecialchars($supervisor_data['first_Name'] . ' ' . $supervisor_data['first_LastName']);
                                                // También mostrar información del tipo de supervisor si es posible
                                                $query_tipo_supervisor = "SELECT tipo_supervisor FROM supervisores WHERE empleado_id = ?";
                                                $stmt_tipo = $conn->prepare($query_tipo_supervisor);
                                                $stmt_tipo->execute([$usuario['supervisor_id']]);
                                                $tipo_data = $stmt_tipo->fetch(PDO::FETCH_ASSOC);
                                                
                                                if ($tipo_data) {
                                                    $supervisor_nombre .= ' (' . ucfirst($tipo_data['tipo_supervisor']) . ')';
                                                }
                                            } else {
                                                $supervisor_nombre = 'Supervisor no encontrado';
                                            }
                                        } catch (Exception $e) {
                                            $supervisor_nombre = 'Error al cargar supervisor';
                                            error_log("Error cargando supervisor: " . $e->getMessage());
                                        }
                                    }
                                    echo $supervisor_nombre;
                                    ?>
                                </td>
                            </tr>
		                <tr><td>Fecha Creación:</td><td><?php echo date('d/m/Y H:i', strtotime($usuario['created_at'])); ?></td></tr>
		                <tr><td>Última Actualización:</td><td><?php echo !empty($usuario['updated_at']) ? date('d/m/Y H:i', strtotime($usuario['updated_at'])) : 'Nunca actualizado'; ?></td></tr>
		            </table>
		            <?php
                        if (!empty($usuario['area_id']) && $hierarchyManager) {
                            try {
                                $cadena_mando = $hierarchyManager->obtenerCadenaMando($usuario['id']);
                                if (!empty(array_filter($cadena_mando))) {
                                    echo '<div class="mt-3">';
                                    echo '<h6>Cadena de Mando</h6>';
                                    echo '<div class="card">';
                                    echo '<div class="card-body p-3">';
                                    
                                    $niveles = [
                                        'gerente' => ['Gerente', 'primary'],
                                        'director' => ['Director', 'info'], 
                                        'coordinador' => ['Coordinador', 'success']
                                    ];
                                    
                                    foreach ($niveles as $nivel => $info) {
                                        if (!empty($cadena_mando[$nivel])) {
                                            $supervisor = $cadena_mando[$nivel];
                                            echo '<div class="d-flex align-items-center mb-2">';
                                            echo '<span class="badge bg-' . $info[1] . ' me-2">' . $info[0] . '</span>';
                                            echo '<span>' . htmlspecialchars($supervisor['first_Name'] . ' ' . $supervisor['first_LastName']) . '</span>';
                                            echo '</div>';
                                        }
                                    }
                                    
                                    echo '</div>';
                                    echo '</div>';
                                    echo '</div>';
                                }
                            } catch (Exception $e) {
                                // Silenciar errores en la cadena de mando para no afectar la visualización principal
                                error_log("Error mostrando cadena de mando: " . $e->getMessage());
                            }
                        }
                        ?>
				<?php if ($usuario['role'] == 'candidato'): ?>
				<div class="row mt-3">
				    <div class="col-12">
				        <div class="alert alert-info">
				            <h6><i class="bi bi-person-badge"></i> Estado de Candidato</h6>
				            <p class="mb-0">Este usuario está registrado como candidato. Para activarlo en el sistema, cambie su rol a "Empleado" u otro rol activo en la edición.</p>
				        </div>
				    </div>
				</div>
				<?php endif; ?>
		        </div>
		    </div>
		</div>
                <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="verHistorialTickets(<?php echo $usuario['id']; ?>, '<?php echo htmlspecialchars($usuario['first_Name'] . ' ' . $usuario['first_LastName']); ?>')">
                        <i class="bi bi-ticket-perforated"></i> Ver Historial de Tickets
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>

<!-- Modal para editar usuarios -->
<div class="modal fade" id="modalEditarUsuario<?php echo $usuario['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-xl"> <!-- Cambiado a modal-xl para más espacio -->
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Usuario: <?php echo htmlspecialchars($usuario['first_Name']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            
            <!-- Pestañas para separar la edición de la gestión de archivos -->
            <ul class="nav nav-tabs" id="userTabs<?php echo $usuario['id']; ?>" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link active" id="edit-tab<?php echo $usuario['id']; ?>" data-bs-toggle="tab" 
                            data-bs-target="#edit<?php echo $usuario['id']; ?>" type="button" role="tab" 
                            aria-controls="edit<?php echo $usuario['id']; ?>" aria-selected="true">
                        Información del Usuario
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" id="files-tab<?php echo $usuario['id']; ?>" data-bs-toggle="tab" 
                            data-bs-target="#files<?php echo $usuario['id']; ?>" type="button" role="tab" 
                            aria-controls="files<?php echo $usuario['id']; ?>" aria-selected="false">
                        Documentos y Archivos
                    </button>
                </li>
            </ul>
            
            <div class="modal-body">
                <div class="tab-content" id="userTabsContent<?php echo $usuario['id']; ?>">
                    <!-- Pestaña 1: Edición de usuario -->
                    <div class="tab-pane fade show active" id="edit<?php echo $usuario['id']; ?>" role="tabpanel" 
                         aria-labelledby="edit-tab<?php echo $usuario['id']; ?>">
                         
                        <form method="POST" action="" id="formEditarUsuario<?php echo $usuario['id']; ?>" enctype="multipart/form-data">
                            <input type="hidden" name="user_id" value="<?php echo $usuario['id']; ?>">
                            <input type="hidden" name="actualizar_usuario" value="1">
                
                <div class="modal-body">
                    <div class="form-section">
                        <h6>Información Personal</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Primer Nombre *</label>
                                    <input type="text" class="form-control" name="first_Name" 
                                           value="<?php echo htmlspecialchars($usuario['first_Name']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Segundo Nombre</label>
                                    <input type="text" class="form-control" name="second_Name" 
                                           value="<?php echo htmlspecialchars($usuario['second_Name'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Primer Apellido *</label>
                                    <input type="text" class="form-control" name="first_LastName" 
                                           value="<?php echo htmlspecialchars($usuario['first_LastName']); ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Segundo Apellido</label>
                                    <input type="text" class="form-control" name="second_LastName" 
                                           value="<?php echo htmlspecialchars($usuario['second_LastName'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Tipo Documento *</label>
                                    <select class="form-select" name="type_CC" required>
                                        <option value="1" <?php echo $usuario['type_CC'] == 1 ? 'selected' : ''; ?>>Cédula</option>
                                        <option value="2" <?php echo $usuario['type_CC'] == 2 ? 'selected' : ''; ?>>Pasaporte</option>
                                        <option value="3" <?php echo $usuario['type_CC'] == 3 ? 'selected' : ''; ?>>Cédula Extranjería</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Número Documento *</label>
                                    <input type="text" class="form-control" name="CC" 
                                           value="<?php echo $usuario['CC']; ?>" required readonly>
                                    <small class="form-text text-muted">No editable</small>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Fecha Nacimiento *</label>
                                    <input type="date" class="form-control" name="birthdate" 
                                           value="<?php echo $usuario['birthdate']; ?>" required>
                                </div>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Estado Civil</label>
                                    <select class="form-select" name="dval">
					    <option value="">Seleccione estado civil</option>
					    <option value="1" <?php echo ($usuario['dval'] ?? '') == 1 ? 'selected' : ''; ?>>Soltero/a</option>
					    <option value="2" <?php echo ($usuario['dval'] ?? '') == 2 ? 'selected' : ''; ?>>Casado/a</option>
					    <option value="3" <?php echo ($usuario['dval'] ?? '') == 3 ? 'selected' : ''; ?>>Divorciado/a</option>
					    <option value="4" <?php echo ($usuario['dval'] ?? '') == 4 ? 'selected' : ''; ?>>Viudo/a</option>
					    <option value="5" <?php echo ($usuario['dval'] ?? '') == 5 ? 'selected' : ''; ?>>Unión Libre</option>
					</select>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">País</label>
                                    <input type="text" class="form-control" name="country" 
                                           value="<?php echo htmlspecialchars($usuario['country'] ?? 'Colombia'); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Ciudad</label>
                                    <input type="text" class="form-control" name="city" 
                                           value="<?php echo htmlspecialchars($usuario['city'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-12">
				    <div class="mb-3">
				        <label for="edit_address" class="form-label">Dirección</label>
				        <textarea class="form-control" id="edit_address" name="address" rows="2" placeholder="Ingrese la dirección completa"></textarea>
				    </div>
			     </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Correo Personal</label>
                                    <input type="email" class="form-control" name="personal_mail" 
                                           value="<?php echo htmlspecialchars($usuario['personal_mail'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Teléfono Personal</label>
                                    <input type="text" class="form-control" name="phone" 
                                           value="<?php echo htmlspecialchars($usuario['phone'] ?? ''); ?>">
                                </div>
                            </div>
                        </div>
                        <div class="form-section">
			    <h6>Foto de Perfil</h6>
			    <div class="row">
			        <div class="col-md-4">
			            <div class="mb-3 text-center">
			                <?php 
			                $foto_usuario = obtenerFotoUsuario($usuario['photo'] ?? null);
			                ?>
			                <img src="<?php echo $foto_usuario; ?>" 
			                     class="rounded-circle mb-2 photo-preview" 
			                     style="width: 100px; height: 100px; object-fit: cover; border: 2px solid #dee2e6;"
			                     id="photo-preview-<?php echo $usuario['id']; ?>"
			                     alt="Foto de perfil">
			                <div class="mt-2">
			                    <input type="file" class="form-control form-control-sm" 
			                           name="photo" 
			                           accept="image/*"
			                           onchange="previewPhoto(this, <?php echo $usuario['id']; ?>)">
			                    <?php if (!empty($usuario['photo'])): ?>
			                    <div class="form-check mt-2">
			                        <input class="form-check-input" type="checkbox" name="eliminar_photo" value="1" id="eliminar_photo_<?php echo $usuario['id']; ?>">
			                        <label class="form-check-label text-danger" for="eliminar_photo_<?php echo $usuario['id']; ?>">
			                            Eliminar foto actual
			                        </label>
			                    </div>
			                    <?php endif; ?>
			                    <input type="hidden" name="current_photo" value="<?php echo $usuario['photo'] ?? ''; ?>">
			                </div>
			            </div>
			        </div>
			    </div>
			</div>
                    </div>
                    

                    <div class="form-section">
                        <h6>Información Laboral</h6>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Correo Empresarial *</label>
                                    <div class="input-group">
                                        <input type="email" class="form-control" name="mail" 
                                               value="<?php echo htmlspecialchars($usuario['mail']); ?>" required>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Contraseña</label>
                                    <div class="input-group">
                                        <input type="text" class="form-control" name="password" 
                                               value="<?php echo htmlspecialchars($usuario['password'] ?? ''); ?>">
                                    </div>
                                    <small class="form-text">Dejar vacío para mantener la actual</small>
                                </div>
                            </div>
                        </div>
                        <div class="row">
			    <div class="col-md-6">
			        <div class="mb-3">
			            <label class="form-label">Firma</label>
			            <select class="form-select" name="id_firm" id="id_firm_edit_<?php echo $usuario['id']; ?>">
			                <option value="">Seleccione una firma</option>
			                <?php foreach ($firmas as $firma): ?>
			                <option value="<?php echo $firma['id']; ?>" 
			                        <?php echo $usuario['id_firm'] == $firma['id'] ? 'selected' : ''; ?>>
			                    <?php echo htmlspecialchars($firma['name']); ?>
			                </option>
			                <?php endforeach; ?>
			            </select>
			        </div>
			    </div>
			    <div class="col-md-6">
			        <div class="mb-3">
			            <label class="form-label">Locación Actual</label>
			            <p class="form-control-plaintext">
			                <?php echo !empty($usuario['locacion_nombre']) ? htmlspecialchars($usuario['locacion_nombre']) : 'No asignada'; ?>
			            </p>
			        </div>
			    </div>
			</div>
			<div class="row">
			    <div class="col-md-6">
			        <div class="mb-3">
			            <label class="form-label">Cambiar Locación</label>
			            <select class="form-select" name="locacion_id" id="locacion_id_<?php echo $usuario['id']; ?>">
			                <option value="">Seleccione una locación</option>
			                <?php 
			                // Obtener locaciones de la firma actual del usuario
			                if (!empty($usuario['id_firm'])) {
			                    $query_locaciones_firma = "SELECT id, name FROM location WHERE id_firm = :id_firm ORDER BY name";
			                    $stmt_locaciones = $conn->prepare($query_locaciones_firma);
			                    $stmt_locaciones->bindParam(':id_firm', $usuario['id_firm']);
			                    $stmt_locaciones->execute();
			                    $locaciones_firma = $stmt_locaciones->fetchAll(PDO::FETCH_ASSOC);
			                    
			                    foreach ($locaciones_firma as $locacion) {
			                        echo '<option value="' . $locacion['id'] . '" ' . 
			                             ($usuario['locacion_id'] == $locacion['id'] ? 'selected' : '') . '>' .
			                             htmlspecialchars($locacion['name']) . '</option>';
			                    }
			                }
			                ?>
			            </select>
			            <small class="form-text">Solo se muestran locaciones de la firma actual</small>
			        </div>
			    </div>
				<div class="col-md-6">
				    <div class="mb-3">
				        <label class="form-label">Cargo *</label>
				        <select class="form-select" name="position_id" required>
				            <option value="">Seleccione un cargo</option>
				            <?php foreach ($cargos as $cargo): ?>
				            <option value="<?php echo $cargo['id']; ?>" <?php echo $usuario['position_id'] == $cargo['id'] ? 'selected' : ''; ?>>
				                <?php echo htmlspecialchars($cargo['nombre']); ?>
				            </option>
				            <?php endforeach; ?>
				        </select>
				    </div>
				</div>
			</div>
			<div class="row">
			    <div class="col-md-4">
			        <div class="mb-3">
			            <label class="form-label">Sede *</label>
			            <select class="form-select" name="sede_id" required>
			                <option value="">Seleccione una sede</option>
			                <?php foreach ($sedes as $sede): ?>
			                <option value="<?php echo $sede['id']; ?>" <?php echo $usuario['sede_id'] == $sede['id'] ? 'selected' : ''; ?>>
			                    <?php echo htmlspecialchars($sede['nombre']); ?>
			                </option>
			                <?php endforeach; ?>
			            </select>
			        </div>
			    </div>
			    <div class="col-md-4">
			        <div class="mb-3">
			            <label class="form-label">Compañía *</label>
			            <select class="form-select" name="company" required>
			                <option value="1" <?php echo $usuario['company'] == 1 ? 'selected' : ''; ?>>AZC</option>
			                <option value="2" <?php echo $usuario['company'] == 2 ? 'selected' : ''; ?>>BilingueLaw</option>
			                <option value="3" <?php echo $usuario['company'] == 3 ? 'selected' : ''; ?>>LawyerDesk</option>
			                <option value="4" <?php echo $usuario['company'] == 4 ? 'selected' : ''; ?>>AZC Legal</option>
			                <option value="5" <?php echo $usuario['company'] == 5 ? 'selected' : ''; ?>>Matiz LegalTech</option>
			            </select>
			        </div>
			    </div>
			</div>
			
			<div class="row">
			    <div class="col-md-6">
			        <div class="mb-3">
			            <label class="form-label">Supervisor Inmediato</label>
				    <select class="form-select" name="supervisor_id">
				        <option value="">Sin supervisor</option>
				        <?php foreach ($supervisores_disponibles as $supervisor): ?>
				        <option value="<?php echo $supervisor['emp_id']; ?>" 
				                <?php echo (isset($usuario) && $usuario['supervisor_id'] == $supervisor['emp_id']) ? 'selected' : ''; ?>>
				            <?php echo htmlspecialchars($supervisor['first_Name'] . ' ' . $supervisor['first_LastName'] . ' - ' . 
				                   ucfirst($supervisor['tipo_supervisor']) . ' de ' . $supervisor['area_nombre']); ?>
				        </option>
				        <?php endforeach; ?>
			            </select>
			            <small class="form-text">Al asignar un supervisor, el empleado heredará automáticamente el área</small>
			        </div>
			    </div>
			    <div class="col-md-6">
			        <div class="mb-3">
			            <label class="form-label">Área Actual</label>
			            <p class="form-control-plaintext">
			                <?php 
			                if (isset($usuario) && !empty($usuario['area_id'])) {
			                    // Buscar nombre del área
			                    $query_area_nombre = "SELECT nombre FROM areas WHERE id = ?";
			                    $stmt_area_nombre = $conn->prepare($query_area_nombre);
			                    $stmt_area_nombre->execute([$usuario['area_id']]);
			                    $area_nombre = $stmt_area_nombre->fetch(PDO::FETCH_COLUMN);
			                    echo htmlspecialchars($area_nombre ?: 'No asignada');
			                } else {
			                    echo 'No asignada (se asignará al seleccionar supervisor)';
			                }
			                ?>
			            </p>
			        </div>
			    </div>
			</div>
			
			
			<?php /*
			//label para edición de pin desactivado, activar en caso de ser necesario
			
			<div class="col-md-6">
			    <div class="mb-3">
			        <label class="form-label">PIN</label>
			        <input type="text" class="form-control" name="pin" 
			               value="<?php echo htmlspecialchars($usuario['pin'] ?? ''); ?>">
			        <small class="form-text">Últimos 4 dígitos del documento</small>
			    </div>
			</div>
			*/ ?>
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Extensión</label>
                                    <input type="text" class="form-control" name="extension" 
                                           value="<?php echo htmlspecialchars($usuario['extension'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Activo Fijo</label>
                                    <input type="text" class="form-control" name="activo_fijo" 
                                           value="<?php echo htmlspecialchars($usuario['activo_fijo'] ?? ''); ?>">
                                    <small class="form-text">Asignará automáticamente el equipo</small>
                                </div>
                            </div>
                            <div class="col-md-4">
				    <div class="mb-3">
				        <label class="form-label">Rol *</label>
				        <select class="form-select" name="role" required>
				            <option value="empleado" <?php echo $usuario['role'] == 'empleado' ? 'selected' : ''; ?>>Empleado</option>
				            <option value="administrador" <?php echo $usuario['role'] == 'administrador' ? 'selected' : ''; ?>>Administrador</option>
				            <option value="nomina" <?php echo $usuario['role'] == 'nomina' ? 'selected' : ''; ?>>Nómina</option>
				            <option value="talento_humano" <?php echo $usuario['role'] == 'talento_humano' ? 'selected' : ''; ?>>Talento Humano</option>
				            <option value="it" <?php echo $usuario['role'] == 'it' ? 'selected' : ''; ?>>IT</option>
				            <option value="candidato" <?php echo $usuario['role'] == 'candidato' ? 'selected' : ''; ?>>Candidato</option>
				            <option value="retirado" <?php echo $usuario['role'] == 'retirado' ? 'selected' : ''; ?>>Retirado</option>
				        </select>
				        <?php if ($usuario['role'] == 'candidato'): ?>
				        <small class="form-text text-info">
				            <i class="bi bi-info-circle"></i> Al cambiar el rol de "Candidato" a cualquier otro, el usuario se activará en el sistema.
				        </small>
				        <?php endif; ?>
				    </div>
			   </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3 form-check">
                                    <input type="checkbox" class="form-check-input" id="needs_phone_edit<?php echo $usuario['id']; ?>" 
                                           name="needs_phone" <?php echo $usuario['needs_phone'] ? 'checked' : ''; ?> 
                                           onchange="togglePhoneSelectionEdit(<?php echo $usuario['id']; ?>)">
                                    <label class="form-check-label" for="needs_phone_edit<?php echo $usuario['id']; ?>">Necesita teléfono corporativo</label>
                                </div>
                                <div class="mb-3" id="phone_selection_edit<?php echo $usuario['id']; ?>" 
                                     style="display: <?php echo $usuario['needs_phone'] ? 'block' : 'none'; ?>;">
                                    <label class="form-label">Teléfono Corporativo</label>
                                    <select class="form-select" name="id_phone">
                                        <option value="">Seleccione un teléfono</option>
                                        <?php foreach ($phones_disponibles as $phone): ?>
                                        <option value="<?php echo $phone['id']; ?>" 
                                                <?php echo $usuario['id_phone'] == $phone['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($phone['firm_name'] . ' - ' . $phone['phone']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <!-- Sección de Supervisor -->
			<div class="form-section">
			    <h6>Gestión como Supervisor</h6>
			    <div class="row">
			        <div class="col-md-12">
			            <div class="mb-3 form-check">
			                <input type="checkbox" class="form-check-input" id="es_supervisor_<?php echo $usuario['id']; ?>" 
			                       name="es_supervisor" <?php echo (isset($supervisor_info) && $supervisor_info) ? 'checked' : ''; ?>
			                       onchange="toggleSupervisorSection(<?php echo $usuario['id']; ?>)">
			                <label class="form-check-label" for="es_supervisor_<?php echo $usuario['id']; ?>">
			                    Este empleado es supervisor
			                </label>
			            </div>
			        </div>
			    </div>
			    
			    <div id="supervisor_section_<?php echo $usuario['id']; ?>" style="display: <?php echo (isset($supervisor_info) && $supervisor_info) ? 'block' : 'none'; ?>;">
			        <div class="row">
			            <div class="col-md-4">
			                <div class="mb-3">
			                    <label class="form-label">Tipo de Supervisor</label>
			                    <select class="form-select" name="tipo_supervisor" id="tipo_supervisor_<?php echo $usuario['id']; ?>">
			                        <option value="">Seleccionar tipo</option>
			                        <option value="coordinador" <?php echo (isset($supervisor_info) && $supervisor_info['tipo_supervisor'] == 'coordinador') ? 'selected' : ''; ?>>Coordinador</option>
			                        <option value="director" <?php echo (isset($supervisor_info) && $supervisor_info['tipo_supervisor'] == 'director') ? 'selected' : ''; ?>>Director</option>
			                        <option value="gerente" <?php echo (isset($supervisor_info) && $supervisor_info['tipo_supervisor'] == 'gerente') ? 'selected' : ''; ?>>Gerente</option>
			                    </select>
			                </div>
			            </div>
			            <div class="col-md-6">
			                <div class="mb-3">
			                    <label class="form-label">Área a Supervisar</label>
			                    <select class="form-select" name="area_supervisor" id="area_supervisor_<?php echo $usuario['id']; ?>">
			                        <option value="">Seleccionar área</option>
			                        <?php echo generarOpcionesAreas($arbol_areas); ?>
			                    </select>
			                </div>
			            </div>
			            <div class="col-md-2">
			                <div class="mb-3">
			                    <label class="form-label">&nbsp;</label>
			                    <button type="button" class="btn btn-outline-primary btn-block" onclick="abrirModalNuevaArea(<?php echo $usuario['id']; ?>)">
			                        <i class="bi bi-plus"></i> Nueva Área
			                    </button>
			                </div>
			            </div>	
			        </div>
			        <?php if (isset($supervisor_info) && $supervisor_info): ?>
			        <div class="alert alert-info">
			            <small>
			                <i class="bi bi-info-circle"></i> Actualmente es <?php echo ucfirst($supervisor_info['tipo_supervisor']); ?> 
			                del área: <?php echo htmlspecialchars($supervisor_info['area_nombre']); ?>
			            </small>
			        </div>
			        <?php endif; ?>
			    </div>
			</div>
                    </div>
                </div>
		<!-- Sección de Permisos -->
		<?php if ($permiso_gestionar_accesos): ?>
		<div class="form-section">
		    <h6>Gestión de Permisos</h6>
		    <div class="row">
		        <div class="col-md-12">
		            <div class="mb-3">
		                <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#modalPermisos<?php echo $usuario['id']; ?>">
		                    <i class="bi bi-shield-check"></i> Gestionar Accesos
		                </button>
		                
		                <small class="form-text text-muted">
		                    Configurar permisos específicos para este usuario. Estos permisos sobreescriben los permisos de su rol y cargo.
		                </small>
		            </div>
		        </div>
		    </div>
		</div>
		<?php endif; ?>
            </form>
        </div>

        
        <!-- Pestaña 2: Gestión de archivos -->
                    <div class="tab-pane fade" id="files<?php echo $usuario['id']; ?>" role="tabpanel" 
                         aria-labelledby="files-tab<?php echo $usuario['id']; ?>">
                         
                        <!-- Sección de Gestión de Archivos -->
                        <div class="form-section">
                            <h6>Gestión de Documentos</h6>
                            
                            <!-- Generar acta de activo fijo -->
                            <?php if (!empty($usuario['activo_fijo'])): ?>
                            <div class="mb-3">
                                <button type="button" class="btn btn-success" onclick="generarActaActivoFijo(<?php echo $usuario['id']; ?>, '<?php echo $usuario['activo_fijo']; ?>')">
				    <i class="bi bi-file-earmark-pdf"></i> Generar Acta de Activo Fijo
				</button>
                                <div id="acta-status-<?php echo $usuario['id']; ?>" class="mt-2"></div>
                            </div>
                            <?php endif; ?>
                            
			    <!-- Subir archivos -->
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h6 class="mb-0">Subir Archivo</h6>
                                </div>
                                <div class="card-body">
                                    <form id="form-subir-archivo-<?php echo $usuario['id']; ?>" enctype="multipart/form-data">
                                        <input type="hidden" name="user_id" value="<?php echo $usuario['id']; ?>">
                                        <div class="row">
                                            <!-- Lista de tipos de archivo actualizada -->
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Tipo de Archivo</label>
                                                    <select class="form-select" name="file_type" id="file_type_<?php echo $usuario['id']; ?>" 
                                                            data-employee-name="<?php echo htmlspecialchars($usuario['first_Name'] . ' ' . $usuario['first_LastName']); ?>" 
                                                            onchange="updateDescription(<?php echo $usuario['id']; ?>)" required>
                                                        <option value="">Seleccione un tipo de archivo</option>
                                                        <option value="otros">Otros</option>
                                                        <option value="hoja_de_vida">Hoja de vida</option>
                                                        <option value="copia_documento_de_identidad">Copia documento de identidad</option>
                                                        <option value="certificado_de_afiliacion_eps">Certificado de afiliación EPS</option>
                                                        <option value="certificado_de_afiliacion_eps_empresa">Certificado de afiliación EPS-EMPRESA</option>
                                                        <option value="certificado_de_afiliacion_pension">Certificado de afiliación Pensión</option>
                                                        <option value="certificado_de_afiliacion_arl">Certificado de afiliación ARL</option>
                                                        <option value="certificado_de_afiliacion_caja_de_compensacion">Certificado de afiliación Caja de Compensación</option>
                                                        <option value="tarjeta_profesional">Tarjeta profesional</option>
                                                        <option value="diploma_pregrado">Diploma pregrado</option>
                                                        <option value="diploma_posgrado">Diploma posgrado</option>
                                                        <option value="certificados_de_formacion">Certificados de formación (diplomados, seminarios, cursos, talleres)</option>
                                                        <option value="contrato_laboral">Contrato laboral</option>
                                                        <option value="otro_si">Otro si (en caso que aplique)</option>
                                                        <option value="autorizacion_tratamiento_de_datos_personales">Autorización tratamiento de datos personales</option>
                                                        <option value="sarlaft">Sarlaft</option>
                                                        <option value="soporte_de_entrega_rit">Soporte de entrega RIT</option>
                                                        <option value="soporte_induccion">Soporte inducción</option>
                                                        <option value="acta_entrega_equipo_ti">Acta entrega equipo TI</option>
                                                        <option value="acuerdo_de_confidencialidad">Acuerdo de Cofidencialidad</option>
                                                        <option value="acta_de_autorizacion_de_descuento">Acta de Autorizacion de Descuento</option>
                                                        <option value="certificado_de_ingles">Certificado de Ingles</option>
                                                        <option value="certificado_de_cesantias">Certificado de Cesantias</option>
                                                        <option value="contactos_de_emergencias">Contactos De emergencias</option>
                                                        <option value="certificado_bancario">Certificado Bancario</option>
                                                        <option value="certificado_policia_nacional">Certificado Policia Nacional (Antecedentes)</option>
                                                        <option value="certificado_procuraduria">Certificado Procuraduria (Antecedentes)</option>
                                                        <option value="certificado_contraloria">Certificado Contraloria (Antecedentes)</option>
                                                        <option value="certificado_vigencia">Certificado Vigencia (Revision T.P si aplica)</option>
                                                        <option value="certificado_laboral">Certificado Laboral</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <!-- Campo de descripción con ID y atributo readonly -->
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Descripción</label>
                                                    <input type="text" class="form-control" name="description" id="description_<?php echo $usuario['id']; ?>" placeholder="La descripción se generará automáticamente" readonly>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <div class="mb-3">
                                                    <label class="form-label">Archivo</label>
                                                    <input type="file" class="form-control" name="archivo" required>
                                                </div>
                                            </div>
                                        </div>
                                        <button type="button" class="btn btn-primary" onclick="subirArchivo(<?php echo $usuario['id']; ?>)">
                                            <i class="bi bi-upload"></i> Subir Archivo
                                        </button>
                                    </form>
                                    <div id="upload-status-<?php echo $usuario['id']; ?>" class="mt-2"></div>
                                </div>
                            </div>
                            
                            <!-- Lista de archivos -->
                            <div class="card">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">Archivos del Usuario</h6>
                                    <button type="button" class="btn btn-outline-primary btn-sm" onclick="actualizarListaArchivos(<?php echo $usuario['id']; ?>)">
                                        <i class="bi bi-arrow-clockwise"></i> Actualizar Lista
                                    </button>
                                </div>
                                <div class="card-body">
                                     <div class="table-responsive">
                                        <table class="table table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Nombre</th>
                                                    <th>Tipo</th>
                                                    <th>Descripción</th>
                                                    <th>Fecha</th>
                                                    <th>Tamaño</th>
                                                    <th>Acciones</th>
                                                </tr>
                                            </thead>
                                            <tbody id="files-body-<?php echo $usuario['id']; ?>">
                                                <!-- Los archivos se cargarán aquí dinámicamente -->
                                                <tr id="no-files-<?php echo $usuario['id']; ?>">
                                                    <td colspan="6" class="text-center">No hay archivos para este usuario.</td>
                                                </tr>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="submit" form="formEditarUsuario<?php echo $usuario['id']; ?>" class="btn btn-primary">Guardar Cambios</button>
            </div>
        </div>
    </div>
</div>



<!-- Modal para gestionar permisos -->
<div class="modal fade" id="modalPermisos<?php echo $usuario['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Gestionar Permisos - <?php echo htmlspecialchars($usuario['first_Name'] . ' ' . $usuario['first_LastName']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> 
                    Los permisos configurados aquí son específicos para este usuario y sobreescriben los permisos de su rol y cargo.
                </div>
                
                <div class="table-responsive">
                    <table class="table table-striped table-hover">
                        <thead>
                            <tr>
                                <th>Recurso</th>
                                <th>Acción</th>
                                <th>Permitido</th>
                                <th>Herencia</th>
                            </tr>
                        </thead>
                        <tbody id="permisos-table-<?php echo $usuario['id']; ?>">
                            <!-- Los permisos se cargarán aquí dinámicamente -->
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-warning" id="reestablecer-permisos-<?php echo $usuario['id']; ?>">
                    <i class="bi bi-arrow-clockwise"></i> Reestablecer Accesos
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                <button type="button" class="btn btn-primary" id="guardar-permisos-<?php echo $usuario['id']; ?>">
                    <i class="bi bi-save"></i> Guardar Permisos
                </button>
            </div>
        </div>
    </div>
</div>

<?php endforeach; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Función para cambiar resultados por página
    function cambiarResultadosPorPagina(valor) {
        const url = new URL(window.location.href);
        url.searchParams.set('resultados_por_pagina', valor);
        url.searchParams.set('pagina', 1); // Volver a la primera página
        window.location.href = url.toString();
    }
    
// Generar pin automáticamente desde el CC
document.getElementById('CC').addEventListener('input', function() {
    const ccValue = this.value;
    if (ccValue.length >= 4) {
        document.getElementById('pin').value = ccValue.slice(-4);
    }
});

// Mostrar/ocultar selección de teléfono corporativo
document.getElementById('needs_phone').addEventListener('change', function() {
    document.getElementById('phone_selection').style.display = this.checked ? 'block' : 'none';
});

function togglePhoneSelectionEdit(userId) {
    var phoneSelection = document.getElementById('phone_selection_edit' + userId);
    var needsPhone = document.getElementById('needs_phone_edit' + userId);
    if (phoneSelection && needsPhone) {
        phoneSelection.style.display = needsPhone.checked ? 'block' : 'none';
    }
}

// Función para abrir modal de información de usuario
function verUsuario(userId) {
    const modalElement = document.getElementById('modalInfoUsuario' + userId);
    if (modalElement) {
        const modal = new bootstrap.Modal(modalElement);
        modal.show();
    } else {
        console.error('Modal no encontrado para usuario ID:', userId);
        alert('Información no disponible para este usuario. Recarga la página e intenta nuevamente.');
    }
}

// Función para buscar y mostrar información del equipo por activo fijo
function verEquipoPorActivoFijo(activoFijo) {
    fetch('../includes/get_equipo_info.php?activo_fijo=' + encodeURIComponent(activoFijo))
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                mostrarModalEquipo(data.equipo);
            } else {
                alert('Equipo no encontrado: ' + activoFijo);
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('Error al cargar la información del equipo');
        });
}

// Función para cargar locaciones según la firma seleccionada
function cargarLocaciones(firmaId, selectId, locacionActual = null) {
    const selectElement = document.getElementById(selectId);
    if (!selectElement) return;

    if (!firmaId) {
        selectElement.innerHTML = '<option value="">Seleccione una firma primero</option>';
        selectElement.disabled = true;
        return;
    }

    selectElement.innerHTML = '<option value="">Cargando locaciones...</option>';
    selectElement.disabled = true;

    fetch('../includes/get_locaciones.php?firma_id=' + firmaId)
        .then(response => response.json())
        .then(locaciones => {
            let options = '<option value="">Seleccione una locación</option>';
            if (locaciones && locaciones.length > 0) {
                locaciones.forEach(locacion => {
                    const selected = locacionActual == locacion.id ? 'selected' : '';
                    options += `<option value="${locacion.id}" ${selected}>${locacion.name}</option>`;
                });
                selectElement.disabled = false;
            } else {
                options = '<option value="">No hay locaciones para esta firma</option>';
                selectElement.disabled = true;
            }
            selectElement.innerHTML = options;
        })
        .catch(error => {
            console.error('Error al cargar locaciones:', error);
            selectElement.innerHTML = '<option value="">Error al cargar locaciones</option>';
            selectElement.disabled = true;
        });
}

// Para el modal de creación
const firmaCreateSelect = document.getElementById('id_firm');
if (firmaCreateSelect) {
    firmaCreateSelect.addEventListener('change', function() {
        cargarLocaciones(this.value, 'locacion_id');
    });
}

function configurarModalEdicion(userId) {
    const firmaSelect = document.getElementById('id_firm_edit_' + userId);
    const locacionSelect = document.getElementById('locacion_id_' + userId);

    if (firmaSelect && locacionSelect) {
        const locacionActual = locacionSelect.value;
        firmaSelect.onchange = null;
        firmaSelect.addEventListener('change', function() {
            cargarLocaciones(this.value, 'locacion_id_' + userId, locacionActual);
        });
        if (firmaSelect.value) {
            cargarLocaciones(firmaSelect.value, 'locacion_id_' + userId, locacionActual);
        }
    }
}

// Función para mostrar modal con información del equipo
function mostrarModalEquipo(equipo) {
    const modalContent = `
        <div class="modal fade" id="modalEquipoInfo" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title">Información del Equipo: ${equipo.activo_fijo}</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Información del Equipo</h5>
                                <table class="table table-sm equipo-info">
                                    <tr><td>Activo Fijo:</td><td>${equipo.activo_fijo}</td></tr>
                                    <tr><td>Serial:</td><td>${equipo.serial_number}</td></tr>
                                    <tr><td>Marca:</td><td>${equipo.marca}</td></tr>
                                    <tr><td>Modelo:</td><td>${equipo.modelo}</td></tr>
                                    <tr><td>Procesador:</td><td>${equipo.procesador}</td></tr>
                                    <tr><td>RAM:</td><td>${equipo.ram}</td></tr>
                                    <tr><td>Disco Duro:</td><td>${equipo.disco_duro}</td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h5>Información de Asignación</h5>
                                <table class="table table-sm equipo-info">
                                    <tr><td>Sede:</td><td>${equipo.sede_nombre}</td></tr>
                                    <tr><td>Estado:</td><td>${equipo.en_it ? 'En IT' : 'Activo'}</td></tr>
                                    <tr><td>Usuario Asignado:</td><td>${equipo.nombre_usuario || 'Sin asignar'}</td></tr>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>`;
    document.body.insertAdjacentHTML('beforeend', modalContent);
    const modal = new bootstrap.Modal(document.getElementById('modalEquipoInfo'));
    modal.show();
    document.getElementById('modalEquipoInfo').addEventListener('hidden.bs.modal', function () {
        this.remove();
    });
}

// ========================== ARCHIVOS ==========================

// Inicializar tabla
function inicializarTablaArchivos(userId) {
    const tbody = document.getElementById('files-body-' + userId);
    if (!tbody) {
        const table = document.querySelector('#files' + userId + ' .table-responsive table');
        if (table) {
            const newTbody = document.createElement('tbody');
            newTbody.id = 'files-body-' + userId;
            table.appendChild(newTbody);
            newTbody.innerHTML = '<tr><td colspan="6" class="text-center">Cargando archivos...</td></tr>';
        }
    }
}

// Objeto con las plantillas de descripción
const descriptionTemplates = {
    'hoja_de_vida': '1. HV - NOMBRE',
    'copia_documento_de_identidad': '2.D.I – NOMBRE',
    'certificado_de_afiliacion_eps': '3.C.EPS – NOMBRE',
    'certificado_de_afiliacion_eps_empresa': '3.C.EPS – NOMBRE',
    'certificado_de_afiliacion_pension': '4.C.AFP – NOMBRE',
    'certificado_de_afiliacion_arl': '5.C.ARL',
    'certificado_de_afiliacion_caja_de_compensacion': '6.C.CAJA COMPENSACIÓN',
    'tarjeta_profesional': '7.T.P – NOMBRE',
    'diploma_pregrado': '8.DIPLOMA PREGRADO-NOMBRE',
    'diploma_posgrado': '8.1. DIPLOMA ESP/MAG/PHD TITULO-NOMBRE',
    'certificados_de_formacion': '8.2. C. SEM (SEMINARIO NOMBRE) C.DIPL (DIPLOMADO NOMBRE) C.(CURSO NOMBRE)',
    'contrato_laboral': '9.CONTRATO - NOMBRE',
    'otro_si': '9.1.OTRO SI-NOMBRE',
    'autorizacion_tratamiento_de_datos_personales': '10.AUT HABEAS DATA- NOMBRE',
    'sarlaft': '11.C. SARLAFT',
    'soporte_de_entrega_rit': '12.SOPORTE ENTREGA RIT',
    'soporte_induccion': '13.SOPORTE INDUCCIÓN',
    'acta_entrega_equipo_ti': '14.ENTREGA EQUIPO',
    'acuerdo_de_confidencialidad': '15.ACUERDO DE CONFIDENCIALIDAD-APELLIDOS NOMBRES',
    'acta_de_autorizacion_de_descuento': '16.ACTA AUTORIZACION DE DESCUENTO',
    'certificado_de_ingles': '17.C.INGLES',
    'certificado_de_cesantias': 'C.CESANTIAS',
    'contactos_de_emergencias': 'C.EMERGENCIA',
    'certificado_bancario': 'C.BANCARIO',
    'certificado_policia_nacional': 'C.POL NAL',
    'certificado_procuraduria': 'C.PROCURADURIA',
    'certificado_contraloria': 'C.CONTRALORIA',
    'certificado_vigencia': 'C.VIGENCIA',
    'certificado_laboral': 'C.LABORAL'
};

// Array con los tipos de archivo que permiten edición manual
const editableTypes = ['diploma_posgrado', 'certificados_de_formacion'];

// NUEVO: Función para formatear el texto del tipo de archivo
function formatFileType(fileType) {
    // Si el tipo de archivo está en el objeto de plantillas, usamos una versión formateada
    if (descriptionTemplates[fileType]) {
        return fileType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
    }
    
    // Para otros tipos, simplemente reemplazamos guiones bajos con espacios y capitalizamos
    return fileType.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
}

// Función para actualizar la descripción según el tipo de archivo
function updateDescription(userId) {
    const fileTypeSelect = document.getElementById('file_type_' + userId);
    const descriptionInput = document.getElementById('description_' + userId);
    
    if (!fileTypeSelect || !descriptionInput) return;

    const selectedType = fileTypeSelect.value;
    const employeeName = fileTypeSelect.dataset.employeeName;

    if (!selectedType) {
        descriptionInput.value = '';
        descriptionInput.readOnly = true;
        descriptionInput.placeholder = 'La descripción se generará automáticamente';
        return;
    }

    // Verificar si el tipo seleccionado es editable
    if (editableTypes.includes(selectedType)) {
        descriptionInput.value = ''; // Limpiar para entrada manual
        descriptionInput.readOnly = false;
        descriptionInput.placeholder = 'Ingrese la descripción manualmente...';
    } else {
        // Autocompletar y hacer de solo lectura
        const template = descriptionTemplates[selectedType];
        if (template) {
            descriptionInput.value = template.replace('NOMBRE', employeeName);
            descriptionInput.readOnly = true;
            descriptionInput.placeholder = '';
        } else {
            // Respaldo para tipos sin plantilla
            descriptionInput.value = '';
            descriptionInput.readOnly = false;
            descriptionInput.placeholder = 'Ingrese una descripción...';
        }
    }
}

// MODIFICADO: Función para subir archivos
function subirArchivo(userId) {
    const form = document.getElementById('form-subir-archivo-' + userId);
    const statusDiv = document.getElementById('upload-status-' + userId);
    
    if (!form) {
        console.error('Formulario no encontrado para userId:', userId);
        return;
    }

    // Asegurarse de que la descripción se incluya en el FormData
    const descriptionInput = document.getElementById('description-' + userId);
    if (descriptionInput && descriptionInput.value) {
        // Crear un input hidden si no existe para asegurar que se envíe la descripción
        let descInput = form.querySelector('input[name="description"]');
        if (!descInput) {
            descInput = document.createElement('input');
            descInput.type = 'hidden';
            descInput.name = 'description';
            form.appendChild(descInput);
        }
        descInput.value = descriptionInput.value;
    }

    const formData = new FormData(form);
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm"></div> Subiendo archivo...';

    fetch('../includes/subir_archivo.php', { 
        method: 'POST', 
        body: formData 
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusDiv.innerHTML = '<div class="alert alert-success">Archivo subido correctamente.</div>';
            
            // FORZAR actualización después de subir
            setTimeout(() => {
                actualizarListaArchivos(userId, false);
                form.reset();
                // Resetear el campo de descripción
                const descInput = document.getElementById('description-' + userId);
                if (descInput) {
                    descInput.value = '';
                    descInput.readOnly = true;
                    descInput.placeholder = 'La descripción se generará automáticamente';
                }
            }, 500);
            
        } else {
            statusDiv.innerHTML = '<div class="alert alert-danger">Error: ' + (data.error || 'Error desconocido') + '</div>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        statusDiv.innerHTML = '<div class="alert alert-danger">Error de conexión: ' + error.message + '</div>';
    });
}

// MODIFICADO: Función para actualizar la lista de archivos
function actualizarListaArchivos(userId, showSuccess = true) {
    console.log('Actualizando lista de archivos para usuario:', userId);

    const statusDiv = document.getElementById('upload-status-' + userId);
    if (statusDiv && showSuccess) {
        statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm" role="status"></div> Cargando archivos...';
    }

    // CREAR el tbody si no existe
    let tbody = document.getElementById('files-body-' + userId);
    if (!tbody) {
        console.log('Creando tbody dinámicamente para userId:', userId);
        const table = document.querySelector('#files' + userId + ' .table-responsive table');
        if (table) {
            // Eliminar tbody existente si hay uno sin ID
            const existingTbody = table.querySelector('tbody');
            if (existingTbody && !existingTbody.id) {
                existingTbody.remove();
            }
            
            // Crear nuevo tbody con ID correcto
            tbody = document.createElement('tbody');
            tbody.id = 'files-body-' + userId;
            table.appendChild(tbody);
            tbody.innerHTML = '<tr><td colspan="6" class="text-center">Cargando archivos...</td></tr>';
        } else {
            console.error('Tabla no encontrada para userId:', userId);
            if (statusDiv) {
                statusDiv.innerHTML = '<div class="alert alert-danger">Error: No se pudo encontrar la tabla de archivos</div>';
            }
            return;
        }
    }

    // URL corregida - usar ruta relativa consistente
    const url = '../includes/obtener_archivos.php?user_id=' + encodeURIComponent(userId) + '&t=' + Date.now();

    fetch(url, {
        method: 'GET',
        credentials: 'same-origin',
        headers: {
            'Accept': 'application/json'
        }
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Error HTTP: ' + response.status);
        }
        return response.json();
    })
    .then(archivos => {
        console.log('Archivos recibidos:', archivos);

        // LIMPIAR y RE-CONSTRUIR la tabla completamente
        tbody.innerHTML = '';

        if (archivos && archivos.length > 0) {
            archivos.forEach(archivo => {
                // MODIFICADO: Usar la función formatFileType para formatear el tipo de archivo
                const formattedType = formatFileType(archivo.file_type);
                
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${escapeHtml(archivo.file_name)}</td>
                    <td><span class="badge bg-secondary">${formattedType}</span></td>
                    <td>${escapeHtml(archivo.description || '')}</td>
                    <td>${formatDate(archivo.created_at)}</td>
                    <td>${Math.round(archivo.file_size / 1024)} KB</td>
                    <td>
                        <a href="../includes/descargar_acta.php?hash=${archivo.file_hash}" class="btn btn-sm btn-primary" target="_blank">
                            <i class="bi bi-download"></i> Descargar
                        </a>
                        <button type="button" class="btn btn-sm btn-danger" onclick="eliminarArchivo('${archivo.file_hash}', ${userId})">
                            <i class="bi bi-trash"></i> Eliminar
                        </button>
                    </td>
                `;
                tbody.appendChild(tr);
            });
        } else {
            tbody.innerHTML = '<tr id="no-files-' + userId + '"><td colspan="6" class="text-center">No hay archivos para este usuario.</td></tr>';
        }

        if (statusDiv && showSuccess) {
            statusDiv.innerHTML = '<div class="alert alert-success">Lista actualizada correctamente</div>';
            setTimeout(() => { 
                if (statusDiv) statusDiv.innerHTML = ''; 
            }, 2000);
        }
    })
    .catch(error => {
        console.error('Error en fetch obtener_archivos:', error);
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error al cargar archivos: ' + error.message + '</td></tr>';
        
        if (statusDiv) {
            statusDiv.innerHTML = '<div class="alert alert-danger"><strong>Error:</strong> ' + error.message + '</div>';
        }
    });
}


// Eliminar archivo
function eliminarArchivo(fileHash, userId) {
    if (!confirm('¿Está seguro de eliminar este archivo?')) return;
    const statusDiv = document.getElementById('upload-status-' + userId);
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm"></div> Eliminando...';

    const formData = new FormData();
    formData.append('file_hash', fileHash);

    fetch('../includes/eliminar_archivo.php', { method: 'POST', body: formData })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                statusDiv.innerHTML = '<div class="alert alert-success">Archivo eliminado</div>';
                actualizarListaArchivos(userId, false);
                setTimeout(() => statusDiv.innerHTML = '', 2000);
            } else {
                throw new Error(data.error || 'Error al eliminar el archivo');
            }
        })
        .catch(error => {
            console.error('Error:', error);
            statusDiv.innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
        });
}

// Generar acta
function generarActaActivoFijo(userId, activoFijo) {
    const statusDiv = document.getElementById('acta-status-' + userId);
    statusDiv.innerHTML = '<div class="spinner-border spinner-border-sm"></div> Generando acta PDF...';
    
    const formData = new FormData();
    formData.append('user_id', userId);
    formData.append('activo_fijo', activoFijo);

    fetch('../includes/generar_acta.php', { method: 'POST', body: formData })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            statusDiv.innerHTML = `
                <div class="alert alert-success">
                    <i class="bi bi-check-circle"></i> Acta PDF generada. 
                    <a href="../includes/descargar_acta.php?hash=${data.file_hash}" class="btn btn-sm btn-outline-primary ms-2" target="_blank">
                        <i class="bi bi-download"></i> Descargar PDF
                    </a>
                </div>`;
            
            // FORZAR actualización después de generar acta
            setTimeout(() => actualizarListaArchivos(userId, true), 1000);
            
        } else {
            statusDiv.innerHTML = '<div class="alert alert-danger">Error: ' + (data.error || 'Error desconocido') + '</div>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        statusDiv.innerHTML = '<div class="alert alert-danger">Error: ' + error.message + '</div>';
    });
}

// Helpers
function formatDate(dateString) {
    try {
        const date = new Date(dateString);
        return date.toLocaleDateString() + ' ' + date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
    } catch (e) {
        return dateString;
    }
}
function updateTimestamp(userId) {
    const now = new Date();
    const element = document.getElementById('update-time-' + userId);
    if (element) element.textContent = now.toLocaleDateString() + ' ' + now.toLocaleTimeString();
}
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ========================== HISTORIAL DE TICKETS ==========================

// Función para ver historial de tickets de un usuario
function verHistorialTickets(userId, userName) {
    // Crear modal dinámico para mostrar el historial
    const modalContent = `
        <div class="modal fade" id="modalHistorialTickets${userId}" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header text-white" style="background-color: #003a5d;">
                        <h5 class="modal-title">
                            <i class="bi bi-ticket-perforated"></i> Historial de Tickets - ${userName}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <p class="text-muted mb-0">
                                Mostrando todos los tickets creados por este usuario
                            </p>
                            <button type="button" class="btn btn-outline-primary btn-sm" onclick="cargarHistorialTickets(${userId})">
                                <i class="bi bi-arrow-clockwise"></i> Actualizar
                            </button>
                        </div>
                        
                        <div id="historial-tickets-content-${userId}">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Cargando...</span>
                                </div>
                                <p class="mt-2">Cargando historial de tickets...</p>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>`;
    
    // Remover modal existente si hay uno
    const existingModal = document.getElementById(`modalHistorialTickets${userId}`);
    if (existingModal) {
        existingModal.remove();
    }
    
    // Añadir nuevo modal al DOM
    document.body.insertAdjacentHTML('beforeend', modalContent);
    
    // Mostrar modal y cargar datos
    const modal = new bootstrap.Modal(document.getElementById(`modalHistorialTickets${userId}`));
    modal.show();
    
    // Cargar historial después de mostrar el modal
    setTimeout(() => {
        cargarHistorialTickets(userId);
    }, 500);
    
    // Limpiar modal cuando se cierre
    document.getElementById(`modalHistorialTickets${userId}`).addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

// Función para cargar el historial de tickets
function cargarHistorialTickets(userId) {
    const contentDiv = document.getElementById(`historial-tickets-content-${userId}`);
    
    if (!contentDiv) return;
    
    contentDiv.innerHTML = `
        <div class="text-center py-2">
            <div class="spinner-border spinner-border-sm text-primary" role="status">
                <span class="visually-hidden">Cargando...</span>
            </div>
            <span class="ms-2">Cargando tickets...</span>
        </div>
    `;
    
    fetch(`../includes/obtener_tickets_usuario.php?user_id=${userId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Error al cargar los tickets');
            }
            return response.json();
        })
        .then(tickets => {
            if (tickets && tickets.length > 0) {
                let html = `
                    <div class="historial-container">
                        <div class="table-responsive">
                            <table class="table table-striped table-hover">
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Tipo</th>
                                        <th>Item/Asunto</th>
                                        <th>Estado</th>
                                        <th>Responsable</th>
                                        <th>Fecha</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                tickets.forEach(ticket => {
                    const badgeClass = {
                        'pendiente': 'bg-warning',
                        'aprobado': 'bg-success', 
                        'rechazado': 'bg-danger',
                        'completado': 'bg-info'
                    }[ticket.estado] || 'bg-secondary';
                    
                    const itemTexto = ticket.tipo === 'email' ? 
                        (ticket.asunto || 'Sin asunto') : 
                        (ticket.item_nombre || 'Soporte técnico');
                    
                    const responsable = (ticket.responsable_id && ticket.responsable_id != '0') ?
                        (ticket.responsable_nombre || 'Sin asignar') : 'Soporte';
                    
                    // Hacer toda la fila clickeable
                    html += `
                        <tr class="ticket-row" style="cursor: pointer;" 
                            onclick="mostrarDetallesTicketModal(${JSON.stringify(ticket).replace(/"/g, '&quot;')})"
                            onmouseover="this.style.backgroundColor='#f8f9fa'" 
                            onmouseout="this.style.backgroundColor=''">
                            <td>#${ticket.id}</td>
                            <td>
                                <span class="badge bg-primary">
                                    ${ticket.tipo === 'email' ? 'correo' : ticket.tipo}
                                </span>
                            </td>
                            <td>${escapeHtml(itemTexto)}</td>
                            <td>
                                <span class="badge ${badgeClass}">
                                    ${ticket.estado}
                                </span>
                            </td>
                            <td>${escapeHtml(responsable)}</td>
                            <td>${new Date(ticket.creado_en).toLocaleDateString('es-ES')}</td>
                        </tr>
                    `;
                });
                
                html += `
                                </tbody>
                            </table>
                        </div>
                    </div>
                `;
                
                contentDiv.innerHTML = html;
            } else {
                contentDiv.innerHTML = `
                    <div class="empty-historial text-center py-5">
                        <i class="bi bi-inbox" style="font-size: 48px; color: #dee2e6;"></i>
                        <h6>No hay tickets registrados</h6>
                        <p class="text-muted">Este usuario no ha creado ningún ticket en el sistema.</p>
                    </div>
                `;
            }
        })
        .catch(error => {
            console.error('Error cargando tickets:', error);
            contentDiv.innerHTML = `
                <div class="alert alert-danger">
                    <i class="bi bi-exclamation-triangle"></i> 
                    Error al cargar el historial de tickets: ${error.message}
                </div>
            `;
        });
}

// Función para mostrar detalles del ticket en un modal
function mostrarDetallesTicketModal(ticket) {
    const modalContent = `
        <div class="modal fade" id="modalDetalleTicket${ticket.id}" tabindex="-1">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Detalles del Ticket #${ticket.id}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">Empleado:</span>
                                    <span class="info-value">${ticket.empleado_nombre || 'N/A'}</span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">Departamento:</span>
                                    <span class="info-value">${ticket.empleado_departamento || 'N/A'}</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">Tipo:</span>
                                    <span class="info-value">
                                        <span class="badge bg-primary">${ticket.tipo === 'email' ? 'correo' : ticket.tipo}</span>
                                    </span>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="info-row">
                                    <span class="info-label">Estado:</span>
                                    <span class="info-value">
                                        <span class="badge ${{
                                            'pendiente': 'bg-warning',
                                            'aprobado': 'bg-success',
                                            'rechazado': 'bg-danger',
                                            'completado': 'bg-info'
                                        }[ticket.estado] || 'bg-secondary'}">${ticket.estado}</span>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Item/Asunto:</span>
                            <span class="info-value">${ticket.tipo === 'email' ? (ticket.asunto || 'Sin asunto') : (ticket.item_nombre || 'Soporte técnico')}</span>
                        </div>
                        
                        ${ticket.cantidad ? `
                        <div class="info-row">
                            <span class="info-label">Cantidad:</span>
                            <span class="info-value">${ticket.cantidad}</span>
                        </div>
                        ` : ''}
                        
                        <div class="info-row">
                            <span class="info-label">Responsable:</span>
                            <span class="info-value">${(ticket.responsable_id && ticket.responsable_id != '0') ? (ticket.responsable_nombre || 'Sin asignar') : 'Soporte'}</span>
                        </div>
                        
                        <div class="info-row">
                            <span class="info-label">Fecha creación:</span>
                            <span class="info-value">${new Date(ticket.creado_en).toLocaleString('es-ES')}</span>
                        </div>
                        
                        ${ticket.notas ? `
                        <div class="info-row">
                            <span class="info-label">Notas del empleado:</span>
                            <div class="info-value">${escapeHtml(ticket.notas)}</div>
                        </div>
                        ` : ''}
                        
                        ${ticket.notas_admin ? `
                        <div class="info-row">
                            <span class="info-label">Notas del administrador:</span>
                            <div class="info-value">${escapeHtml(ticket.notas_admin)}</div>
                        </div>
                        ` : ''}
                        
                        ${ticket.imagen_ruta ? `
                        <div class="info-row">
                            <span class="info-label">Imagen adjunta:</span>
                            <div class="info-value">
                                <img src="${ticket.imagen_ruta}" class="modal-ticket-img" 
                                     alt="Imagen del ticket" 
                                     onclick="ampliarImagenModal('${ticket.imagen_ruta}')"
                                     style="max-width: 200px; max-height: 150px; cursor: pointer;">
                            </div>
                        </div>
                        ` : ''}
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                    </div>
                </div>
            </div>
        </div>`;
    
    // Remover modal existente
    const existingModal = document.getElementById(`modalDetalleTicket${ticket.id}`);
    if (existingModal) {
        existingModal.remove();
    }
    
    document.body.insertAdjacentHTML('beforeend', modalContent);
    const modal = new bootstrap.Modal(document.getElementById(`modalDetalleTicket${ticket.id}`));
    modal.show();
    
    // Limpiar cuando se cierre
    document.getElementById(`modalDetalleTicket${ticket.id}`).addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

// Función auxiliar para ampliar imagen en modal
function ampliarImagenModal(src) {
    const modalContent = `
        <div class="modal fade" id="modalImagenAmpliada" tabindex="-1">
            <div class="modal-dialog modal-xl">
                <div class="modal-content">
                    <div class="modal-header">
                        <h6 class="modal-title">Imagen del Ticket</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body text-center">
                        <img src="${src}" class="img-fluid" alt="Imagen ampliada">
                    </div>
                </div>
            </div>
        </div>`;
    
    document.body.insertAdjacentHTML('beforeend', modalContent);
    const modal = new bootstrap.Modal(document.getElementById('modalImagenAmpliada'));
    modal.show();
    
    document.getElementById('modalImagenAmpliada').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

// ========================== FOTOS ==========================

// Función para previsualizar foto
function previewPhoto(input, userId) {
    const preview = document.getElementById('photo-preview-' + userId);
    const file = input.files[0];
    
    if (file) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.src = e.target.result;
        }
        
        reader.readAsDataURL(file);
    }
}

// Función para eliminar foto
function eliminarFoto(userId) {
    const preview = document.getElementById('photo-preview-' + userId);
    const defaultAvatar = '../assets/images/default_avatar.png';
    
    preview.src = defaultAvatar;
    
    // Marcar checkbox de eliminar
    const eliminarCheckbox = document.getElementById('eliminar_photo_' + userId);
    if (eliminarCheckbox) {
        eliminarCheckbox.checked = true;
    }
    
    // Limpiar input file
    const fileInput = preview.parentNode.querySelector('input[type="file"]');
    if (fileInput) {
        fileInput.value = '';
    }
}

// ========================== INICIALIZACIÓN ==========================
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($usuarios as $usuario): ?>
const modalEdit<?php echo $usuario['id']; ?> = document.getElementById('modalEditarUsuario<?php echo $usuario['id']; ?>');
if (modalEdit<?php echo $usuario['id']; ?>) {
    modalEdit<?php echo $usuario['id']; ?>.addEventListener('show.bs.modal', function() {
        // Asegurar que la tabla exista
        inicializarTablaArchivos(<?php echo $usuario['id']; ?>);
    });
    modalEdit<?php echo $usuario['id']; ?>.addEventListener('shown.bs.modal', function() {
        // Actualizar lista automáticamente al abrir el modal
        updateDescription(<?php echo $usuario['id']; ?>);
        configurarModalEdicion(<?php echo $usuario['id']; ?>);
        actualizarListaArchivos(<?php echo $usuario['id']; ?>, false);
    });
}
<?php endforeach; ?>
});

// Toggle sección de supervisor
function toggleSupervisorSection(userId) {
    const section = document.getElementById('supervisor_section_' + userId);
    const checkbox = document.getElementById('es_supervisor_' + userId);
    section.style.display = checkbox.checked ? 'block' : 'none';
}

// Modal para nueva área
function abrirModalNuevaArea(userId) {
    // Implementar modal para crear nueva área
    const modalContent = `
        <div class="modal fade" id="modalNuevaArea${userId}" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">Crear Nueva Área</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Nombre del Área</label>
                            <input type="text" class="form-control" id="nueva_area_nombre_${userId}" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Área Padre (Opcional)</label>
                            <select class="form-select" id="nueva_area_padre_${userId}">
                                <option value="">Sin área padre (área principal)</option>
                                <?php echo generarOpcionesAreas($arbol_areas); ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Descripción</label>
                            <textarea class="form-control" id="nueva_area_desc_${userId}" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="button" class="btn btn-primary" onclick="crearNuevaArea(${userId})">Crear Área</button>
                    </div>
                </div>
            </div>
        </div>`;
    
    document.body.insertAdjacentHTML('beforeend', modalContent);
    const modal = new bootstrap.Modal(document.getElementById('modalNuevaArea' + userId));
    modal.show();
    
    // Limpiar modal después de cerrar
    document.getElementById('modalNuevaArea' + userId).addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

// Crear nueva área via AJAX
function crearNuevaArea(userId) {
    const nombre = document.getElementById('nueva_area_nombre_' + userId).value;
    const padreId = document.getElementById('nueva_area_padre_' + userId).value;
    const descripcion = document.getElementById('nueva_area_desc_' + userId).value;
    
    if (!nombre) {
        alert('El nombre del área es requerido');
        return;
    }
    
    const formData = new FormData();
    formData.append('crear_area', '1');
    formData.append('nombre', nombre);
    formData.append('padre_id', padreId);
    formData.append('descripcion', descripcion);
    
    fetch('gestion_areas.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Actualizar select de áreas
            location.reload(); // Recargar para actualizar las áreas
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al crear el área');
    });
}

// Función para cargar permisos del usuario
function cargarPermisosUsuario(usuarioId) {
    const tablaPermisos = document.getElementById(`permisos-table-${usuarioId}`);
    
    // Mostrar indicador de carga
    tablaPermisos.innerHTML = `
        <tr>
            <td colspan="4" class="text-center">
                <div class="spinner-border text-primary" role="status">
                    <span class="visually-hidden">Cargando...</span>
                </div>
                <p class="mt-2">Cargando permisos...</p>
            </td>
        </tr>
    `;
    
    // Cargar permisos via AJAX
    fetch(`../includes/obtener_permisos_usuario.php?usuario_id=${usuarioId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                let html = '';
                
                data.permisos.forEach(permiso => {
                    const checked = permiso.permitido ? 'checked' : '';
                    const herencia = permiso.especifico ? 'Específico' : 
                                    (permiso.cargo ? 'Cargo' : 'Rol');
                    const herenciaClass = permiso.especifico ? 'text-primary' : 
                                       (permiso.cargo ? 'text-info' : 'text-secondary');
                    
                    html += `
                        <tr>
                            <td>${permiso.recurso}</td>
                            <td>${permiso.accion}</td>
                            <td>
                                <div class="form-check form-switch">
                                    <input class="form-check-input permiso-switch" 
                                           type="checkbox" 
                                           data-recurso="${permiso.recurso}" 
                                           data-accion="${permiso.accion}" 
                                           ${checked}>
                                </div>
                            </td>
                            <td><span class="badge ${herenciaClass}">${herencia}</span></td>
                        </tr>
                    `;
                });
                
                tablaPermisos.innerHTML = html;
            } else {
                tablaPermisos.innerHTML = `
                    <tr>
                        <td colspan="4" class="text-center text-danger">
                            Error: ${data.error}
                        </td>
                    </tr>
                `;
            }
        })
        .catch(error => {
            console.error('Error:', error);
            tablaPermisos.innerHTML = `
                <tr>
                    <td colspan="4" class="text-center text-danger">
                        Error al cargar los permisos: ${error.message}
                    </td>
                </tr>
            `;
        });
}

// Función para guardar permisos del usuario
function guardarPermisosUsuario(usuarioId) {
    const permisosSwitches = document.querySelectorAll(`#permisos-table-${usuarioId} .permiso-switch`);
    const permisos = [];
    
    permisosSwitches.forEach(switchElement => {
        permisos.push({
            recurso: switchElement.dataset.recurso,
            accion: switchElement.dataset.accion,
            permitido: switchElement.checked
        });
    });
    
    // Mostrar indicador de carga
    const botonGuardar = document.getElementById(`guardar-permisos-${usuarioId}`);
    const textoOriginal = botonGuardar.innerHTML;
    botonGuardar.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Guardando...';
    botonGuardar.disabled = true;
    
    // Enviar permisos via AJAX
    fetch('../includes/guardar_permisos_usuario.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            usuario_id: usuarioId,
            permisos: permisos
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Mostrar mensaje de éxito
            const alerta = document.createElement('div');
            alerta.className = 'alert alert-success alert-dismissible fade show';
            alerta.innerHTML = `
                <i class="bi bi-check-circle"></i> Permisos guardados correctamente
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const modalBody = document.querySelector(`#modalPermisos${usuarioId} .modal-body`);
            modalBody.insertBefore(alerta, modalBody.firstChild);
            
            // Recargar permisos para mostrar los cambios
            setTimeout(() => {
                cargarPermisosUsuario(usuarioId);
            }, 1500);
        } else {
            // Mostrar mensaje de error
            const alerta = document.createElement('div');
            alerta.className = 'alert alert-danger alert-dismissible fade show';
            alerta.innerHTML = `
                <i class="bi bi-exclamation-triangle"></i> Error: ${data.error}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const modalBody = document.querySelector(`#modalPermisos${usuarioId} .modal-body`);
            modalBody.insertBefore(alerta, modalBody.firstChild);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        
        // Mostrar mensaje de error
        const alerta = document.createElement('div');
        alerta.className = 'alert alert-danger alert-dismissible fade show';
        alerta.innerHTML = `
            <i class="bi bi-exclamation-triangle"></i> Error al guardar los permisos: ${error.message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        const modalBody = document.querySelector(`#modalPermisos${usuarioId} .modal-body`);
        modalBody.insertBefore(alerta, modalBody.firstChild);
    })
    .finally(() => {
        // Restaurar botón
        botonGuardar.innerHTML = textoOriginal;
        botonGuardar.disabled = false;
    });
}

// Función para reestablecer permisos del usuario
function reestablecerPermisosUsuario(usuarioId) {
    if (!confirm('¿Está seguro de que desea reestablecer los accesos de este usuario? Se eliminarán todos los permisos específicos y el usuario volverá a heredar los permisos de su rol y cargo.')) {
        return;
    }
    
    // Mostrar indicador de carga
    const botonReestablecer = document.getElementById(`reestablecer-permisos-${usuarioId}`);
    const textoOriginal = botonReestablecer.innerHTML;
    botonReestablecer.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span> Reestableciendo...';
    botonReestablecer.disabled = true;
    
    // Enviar solicitud via AJAX
    fetch('../includes/reestablecer_permisos_usuario.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: `usuario_id=${usuarioId}`
    })
    .then(response => {
        // Verificar si la respuesta es OK
        if (!response.ok) {
            // Si no es OK, intentar obtener el texto del error
            return response.text().then(text => {
                throw new Error(`HTTP ${response.status}: ${text}`);
            });
        }
        
        // Verificar el content-type de la respuesta
        const contentType = response.headers.get('content-type');
        if (!contentType || !contentType.includes('application/json')) {
            return response.text().then(text => {
                throw new Error(`Respuesta no es JSON: ${text}`);
            });
        }
        
        return response.json();
    })
    .then(data => {
        if (data.success) {
            // Mostrar mensaje de éxito
            const alerta = document.createElement('div');
            alerta.className = 'alert alert-success alert-dismissible fade show';
            alerta.innerHTML = `
                <i class="bi bi-check-circle"></i> ${data.message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const modalBody = document.querySelector(`#modalPermisos${usuarioId} .modal-body`);
            modalBody.insertBefore(alerta, modalBody.firstChild);
            
            // Recargar permisos para mostrar los cambios
            setTimeout(() => {
                cargarPermisosUsuario(usuarioId);
            }, 1500);
        } else {
            // Mostrar mensaje de error
            const alerta = document.createElement('div');
            alerta.className = 'alert alert-danger alert-dismissible fade show';
            alerta.innerHTML = `
                <i class="bi bi-exclamation-triangle"></i> Error: ${data.error}
                ${data.debug_info ? `<br><small class="text-muted">Debug: ${data.debug_info.file}:${data.debug_info.line}</small>` : ''}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;
            
            const modalBody = document.querySelector(`#modalPermisos${usuarioId} .modal-body`);
            modalBody.insertBefore(alerta, modalBody.firstChild);
        }
    })
    .catch(error => {
        console.error('Error completo:', error);
        
        // Mostrar mensaje de error detallado
        const alerta = document.createElement('div');
        alerta.className = 'alert alert-danger alert-dismissible fade show';
        alerta.innerHTML = `
            <i class="bi bi-exclamation-triangle"></i> Error al reestablecer los permisos
            <br><small class="text-muted">${error.message}</small>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;
        
        const modalBody = document.querySelector(`#modalPermisos${usuarioId} .modal-body`);
        modalBody.insertBefore(alerta, modalBody.firstChild);
    })
    .finally(() => {
        // Restaurar botón
        botonReestablecer.innerHTML = textoOriginal;
        botonReestablecer.disabled = false;
    });
}

// Configurar event listeners cuando se abre el modal de permisos
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($usuarios as $usuario): ?>
    const modalPermisos<?php echo $usuario['id']; ?> = document.getElementById('modalPermisos<?php echo $usuario['id']; ?>');
    
    if (modalPermisos<?php echo $usuario['id']; ?>) {
        modalPermisos<?php echo $usuario['id']; ?>.addEventListener('show.bs.modal', function() {
            cargarPermisosUsuario(<?php echo $usuario['id']; ?>);
        });
        
        const botonGuardar<?php echo $usuario['id']; ?> = document.getElementById('guardar-permisos-<?php echo $usuario['id']; ?>');
        if (botonGuardar<?php echo $usuario['id']; ?>) {
            botonGuardar<?php echo $usuario['id']; ?>.addEventListener('click', function() {
                guardarPermisosUsuario(<?php echo $usuario['id']; ?>);
            });
        }
        
        // Agregamos el event listener para el botón de reestablecer
        const botonReestablecer<?php echo $usuario['id']; ?> = document.getElementById('reestablecer-permisos-<?php echo $usuario['id']; ?>');
        if (botonReestablecer<?php echo $usuario['id']; ?>) {
            botonReestablecer<?php echo $usuario['id']; ?>.addEventListener('click', function() {
                reestablecerPermisosUsuario(<?php echo $usuario['id']; ?>);
            });
        }
    }
    <?php endforeach; ?>
});

</script>

</body>
</html>