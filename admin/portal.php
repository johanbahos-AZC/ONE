<?php
// Activar mostrar errores para debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/database.php';
require_once '../includes/validar_permiso.php';

// Obtener información del usuario
 $database = new Database();
 $conn = $database->getConnection();

 $query_usuario = "SELECT e.id, e.role, e.position_id, c.nombre as cargo_nombre 
                 FROM employee e 
                 LEFT JOIN cargos c ON e.position_id = c.id 
                 WHERE e.id = ?";
 $stmt_usuario = $conn->prepare($query_usuario);
 $stmt_usuario->execute([$_SESSION['user_id']]);
 $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

function limitar_palabras($texto, $num_palabras = 55, $more = null) {
    if (null === $more) {
        $more = '&hellip;';
    }
    $texto = strip_tags($texto); // Elimina etiquetas HTML para un conteo de palabras correcto
    $palabras_array = preg_split('/[\s]+/', $texto, -1, PREG_SPLIT_NO_EMPTY);
    
    if (count($palabras_array) > $num_palabras) {
        $palabras_array = array_slice($palabras_array, 0, $num_palabras);
        $texto = implode(' ', $palabras_array);
        $texto = $texto . $more;
    } else {
        $texto = implode(' ', $palabras_array);
    }
    
    return $texto;
}

// --- INICIO: CÓDIGO MEJORADO PARA EL CARRUSEL DEL BOLETÍN ---
$boletin_error = '';
$posts_boletin = [];

// Configuración mejorada con cache y manejo de concurrencia
function obtenerPostsBoletin() {
    global $boletin_error;
    
    // Configuración de cache (5 minutos)
    $cache_file = '../cache/boletin_cache.json';
    $cache_time = 5 * 60; // 5 minutos
    
    // Verificar cache primero
    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < $cache_time) {
        $cached_data = file_get_contents($cache_file);
        $posts = json_decode($cached_data, true);
        if (json_last_error() === JSON_ERROR_NONE && !empty($posts)) {
            error_log("Boletín cargado desde cache");
            return $posts;
        }
    }
    
    try {
        // URL de la API REST de tu WordPress
        $api_url = 'https://newsletter.azclegal.com/wordpress/wp-json/wp/v2/posts?per_page=5&_embed';
        
        // Configuración MEJORADA de cURL para concurrencia
        $ch = curl_init();
        
        // Opciones de cURL optimizadas para múltiples usuarios
        $curl_options = [
            CURLOPT_URL => $api_url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 8, // Reducido de 10 a 8 segundos
            CURLOPT_TIMEOUT => 12, // Reducido de 15 a 12 segundos
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; AZC-Legal-Portal/1.0)',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_DNS_CACHE_TIMEOUT => 300, // Cache DNS por 5 minutos
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4, // Forzar IPv4
            CURLOPT_TCP_FASTOPEN => true, // Habilitar TCP Fast Open
            CURLOPT_TCP_NODELAY => true, // Deshabilitar algoritmo de Nagle
            CURLOPT_FAILONERROR => true, // Fallar en errores HTTP >= 400
        ];
        
        curl_setopt_array($ch, $curl_options);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);
        
        curl_close($ch);

        // Log para diagnóstico (solo en caso de error)
        if ($curl_errno > 0 || $http_code != 200) {
            error_log("Boletín API - HTTP Code: $http_code, cURL Error: $curl_error, cURL Errno: $curl_errno");
        }

        if ($curl_errno > 0) {
            // Si hay error de conexión, intentar cargar desde cache aunque esté expirado
            if (file_exists($cache_file)) {
                $cached_data = file_get_contents($cache_file);
                $posts = json_decode($cached_data, true);
                if (json_last_error() === JSON_ERROR_NONE && !empty($posts)) {
                    return $posts;
                }
            }
            
            $boletin_error = "Error temporal de conexión. Intentando cargar noticias en cache...";
            error_log("Error cURL al cargar boletín: $curl_error (Código: $curl_errno)");
            return [];
            
        } elseif ($http_code == 200) {
            $posts_boletin = json_decode($response, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $boletin_error = "Error al procesar las noticias";
                error_log("Error JSON al cargar boletín: " . json_last_error_msg());
                return [];
            } elseif (empty($posts_boletin)) {
                $boletin_error = "No hay noticias disponibles en este momento";
                return [];
            } else {
                // Guardar en cache
                if (!is_dir('../cache')) {
                    mkdir('../cache', 0755, true);
                }
                file_put_contents($cache_file, json_encode($posts_boletin));
                error_log("Boletín actualizado en cache");
                return $posts_boletin;
            }
        } else {
            // Intentar cargar desde cache en caso de error HTTP
            if (file_exists($cache_file)) {
                $cached_data = file_get_contents($cache_file);
                $posts = json_decode($cached_data, true);
                if (json_last_error() === JSON_ERROR_NONE && !empty($posts)) {
                    error_log("Boletín cargado desde cache (fallback por error HTTP: $http_code)");
                    $boletin_error = "Noticias cargadas desde cache (error temporal del servidor)";
                    return $posts;
                }
            }
            
            $boletin_error = "Error temporal del servidor de noticias";
            error_log("Error HTTP al cargar boletín: $http_code");
            return [];
        }

    } catch (Exception $e) {
        error_log("Excepción al cargar API del boletín: " . $e->getMessage());
        
        // Fallback a cache en caso de excepción
        if (file_exists($cache_file)) {
            $cached_data = file_get_contents($cache_file);
            $posts = json_decode($cached_data, true);
            if (json_last_error() === JSON_ERROR_NONE && !empty($posts)) {
                error_log("Boletín cargado desde cache (fallback por excepción)");
                $boletin_error = "Noticias cargadas desde cache (error temporal)";
                return $posts;
            }
        }
        
        $boletin_error = "Error inesperado al cargar el boletín";
        return [];
    }
}

// Obtener posts del boletín (con cache y manejo de errores)
$posts_boletin = obtenerPostsBoletin();
// --- FIN: CÓDIGO MEJORADO PARA EL CARRUSEL DEL BOLETÍN ---

$error = '';
$success = '';



try {
    $auth = new Auth();
    $auth->redirectIfNotLoggedIn();

    $functions = new Functions();
    $database = new Database();
    $conn = $database->getConnection();

    // Procesar nueva publicación (SIN REDIRECCIÓN)
    if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_publicacion'])) {
        $contenido = trim($_POST['contenido']);
        $tipo = 'texto';
        
	// Procesar multimedia si se subió
	$imagen_nombre = null;
	if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
	    $imagen_nombre = procesarMultimediaPublicacion($_FILES['imagen']);
	    $tipo_archivo = determinarTipoArchivo($imagen_nombre);
	    $tipo = $tipo_archivo; // 'imagen', 'video', o 'archivo'
	    if ($contenido) {
	        $tipo = 'mixto';
	    }
	}
        
        if (!empty($contenido) || $imagen_nombre) {
            try {
                $query = "INSERT INTO publicaciones (usuario_id, contenido, imagen, tipo, creado_en) 
                          VALUES (:usuario_id, :contenido, :imagen, :tipo, NOW())";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':usuario_id', $_SESSION['user_id']);
                $stmt->bindParam(':contenido', $contenido);
                $stmt->bindParam(':imagen', $imagen_nombre);
                $stmt->bindParam(':tipo', $tipo);
                
                if ($stmt->execute()) {
                    $success = "Publicación creada correctamente";
                    // Recargar la página sin parámetros para evitar el error
                    echo "<script>window.location.href = 'portal.php';</script>";
                    exit();
                } else {
                    $error = "Error al crear la publicación";
                }
            } catch (PDOException $e) {
                $error = "Error en la base de datos: " . $e->getMessage();
            }
        } else {
            $error = "La publicación debe contener texto o una imagen";
        }
    }

// Procesar eliminación de publicación (SIN REDIRECCIÓN)
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['eliminar_publicacion'])) {
    $publicacion_id = $_POST['publicacion_id'];
    
    try {
        // Primero obtener la publicación para verificar permisos
        $query_verificar = "SELECT p.* FROM publicaciones p 
                           WHERE p.id = :id AND p.tipo != 'cumpleanos'";
        $stmt_verificar = $conn->prepare($query_verificar);
        $stmt_verificar->bindParam(':id', $publicacion_id);
        $stmt_verificar->execute();
        
        $publicacion = $stmt_verificar->fetch(PDO::FETCH_ASSOC);
        
        if ($publicacion) {
            // Verificar permisos para eliminar
            $puede_eliminar = false;
            
            // Si es el autor de la publicación, verificar si puede eliminar sus propias publicaciones
            if ($publicacion['usuario_id'] == $_SESSION['user_id']) {
                $puede_eliminar = tienePermiso($conn, $usuario['id'], $usuario['role'], $usuario['position_id'], 'portal', 'eliminar_propia_publicacion');
            }
            
            // Si no es el autor o no puede eliminar la suya, verificar si puede eliminar cualquier publicación
            if (!$puede_eliminar) {
                $puede_eliminar = tienePermiso($conn, $usuario['id'], $usuario['role'], $usuario['position_id'], 'portal', 'eliminar_publicacion');
            }
            
            if ($puede_eliminar) {
                // Eliminar likes primero
                $query_likes = "DELETE FROM publicaciones_likes WHERE publicacion_id = :id";
                $stmt_likes = $conn->prepare($query_likes);
                $stmt_likes->bindParam(':id', $publicacion_id);
                $stmt_likes->execute();
                
                // Eliminar la publicación
                $query = "DELETE FROM publicaciones WHERE id = :id";
                $stmt = $conn->prepare($query);
                $stmt->bindParam(':id', $publicacion_id);
                
                if ($stmt->execute()) {
                    // Eliminar imagen del servidor si existe
                    if (!empty($publicacion['imagen'])) {
                        $imagen_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/publicaciones/' . $publicacion['imagen'];
                        if (file_exists($imagen_path)) {
                            unlink($imagen_path);
                        }
                    }
                    
                    $success = "Publicación eliminada correctamente";
                    // Recargar la página sin parámetros
                    echo "<script>window.location.href = 'portal.php';</script>";
                    exit();
                } else {
                    $error = "Error al eliminar la publicación";
                }
            } else {
                $error = "No tienes permisos para eliminar esta publicación";
            }
        } else {
            $error = "La publicación no existe o es una publicación de cumpleaños del sistema";
        }
    } catch (PDOException $e) {
        $error = "Error en la base de datos: " . $e->getMessage();
        // DEBUG: Mostrar información de publicaciones de cumpleaños
        error_log("=== DEBUG PORTAL - PUBLICACIONES CUMPLEAÑOS ===");
        $cumpleanos_count = 0;
        foreach ($publicaciones as $pub) {
            if ($pub['tipo'] == 'cumpleanos') {
                $cumpleanos_count++;
                $fecha = date('Y-m-d H:i', strtotime($pub['creado_en']));
                error_log("📅 Cumpleaños ID: {$pub['id']} | Fecha: $fecha");
                error_log("📝 Contenido: " . substr($pub['contenido'], 0, 80));
            }
        }
        error_log("📊 Total publicaciones cumpleaños encontradas: $cumpleanos_count");
    }
}

// En la sección de procesamiento de likes, reemplaza el código actual con:
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion']) && $_POST['accion'] == 'like') {
    $publicacion_id = $_POST['publicacion_id'];
    $tipo = $_POST['tipo']; // 'publicacion' o 'cumpleanos'
    $accion = $_POST['accion_like']; // 'like' o 'unlike'
    
    try {
        if ($accion == 'like') {
            if ($tipo == 'publicacion') {
                $query = "INSERT INTO publicaciones_likes (publicacion_id, usuario_id, creado_en) 
                          VALUES (:publicacion_id, :usuario_id, NOW()) 
                          ON DUPLICATE KEY UPDATE creado_en = NOW()";
            } else {
                $query = "INSERT INTO cumpleanos_likes (empleado_id, usuario_id, creado_en) 
                          VALUES (:publicacion_id, :usuario_id, NOW()) 
                          ON DUPLICATE KEY UPDATE creado_en = NOW()";
            }
        } else { // unlike
            if ($tipo == 'publicacion') {
                $query = "DELETE FROM publicaciones_likes WHERE publicacion_id = :publicacion_id AND usuario_id = :usuario_id";
            } else {
                $query = "DELETE FROM cumpleanos_likes WHERE empleado_id = :publicacion_id AND usuario_id = :usuario_id";
            }
        }
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':publicacion_id', $publicacion_id);
        $stmt->bindParam(':usuario_id', $_SESSION['user_id']);
        $stmt->execute();
        
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// En portal.php, corrige la función obtener_likes_usuario:
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion']) && $_POST['accion'] == 'obtener_likes_usuario') {
    $empleados_ids = $_POST['empleados_ids'];
    $empleados_ids_array = explode(',', $empleados_ids);
    
    // DEBUG
    error_log("OBTENER_LIKES_USUARIO - IDs recibidos: " . implode(', ', $empleados_ids_array));
    error_log("OBTENER_LIKES_USUARIO - Usuario ID: " . $_SESSION['user_id']);
    
    try {
        $placeholders = str_repeat('?,', count($empleados_ids_array) - 1) . '?';
        $query = "SELECT empleado_id FROM cumpleanos_likes 
                  WHERE empleado_id IN ($placeholders) AND usuario_id = ?";
        
        $stmt = $conn->prepare($query);
        $params = array_merge($empleados_ids_array, [$_SESSION['user_id']]);
        
        // DEBUG
        error_log("OBTENER_LIKES_USUARIO - Query: " . $query);
        error_log("OBTENER_LIKES_USUARIO - Params: " . implode(', ', $params));
        
        $stmt->execute($params);
        $result = $stmt->fetchAll(PDO::FETCH_COLUMN);
        
        // DEBUG - Asegurarnos de que los IDs sean integers
        $result = array_map('intval', $result);
        
        error_log("OBTENER_LIKES_USUARIO - Resultados: " . json_encode($result));
        
        echo json_encode(['success' => true, 'likes_usuario' => $result]);
        exit;
    } catch (PDOException $e) {
        error_log("OBTENER_LIKES_USUARIO - Error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// En portal.php, después de la función de obtener likes del usuario, agrega:
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['accion']) && $_POST['accion'] == 'obtener_likes_totales') {
    $empleados_ids = $_POST['empleados_ids'];
    $empleados_ids_array = explode(',', $empleados_ids);
    
    try {
        $placeholders = str_repeat('?,', count($empleados_ids_array) - 1) . '?';
        $query = "SELECT empleado_id, COUNT(*) as total_likes 
                  FROM cumpleanos_likes 
                  WHERE empleado_id IN ($placeholders) 
                  GROUP BY empleado_id";
        
        $stmt = $conn->prepare($query);
        $stmt->execute($empleados_ids_array);
        $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Convertir a formato más fácil de usar
        $likes_totales = [];
        foreach ($result as $row) {
            $likes_totales[$row['empleado_id']] = $row['total_likes'];
        }
        
        echo json_encode(['success' => true, 'likes_totales' => $likes_totales]);
        exit;
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Función para verificar cumpleaños
function verificarCumpleanosDelDia($conn) {
    try {
        // Usar hora de Colombia (UTC-5)
        $timezone = new DateTimeZone('America/Bogota');
        $hoy_colombia = new DateTime('now', $timezone);
        $hoy_md = $hoy_colombia->format('m-d');
        $hoy_date = $hoy_colombia->format('Y-m-d');
        
        debug_log("Verificando cumpleaños - Fecha Colombia: $hoy_date ($hoy_md)");
        
        // SOLO VERIFICAR, NO CREAR PUBLICACIÓN
        $query_cumpleanos = "SELECT COUNT(*) as total FROM employee 
                            WHERE DATE_FORMAT(birthdate, '%m-%d') = :hoy_md 
                            AND role != 'retirado'";
        $stmt = $conn->prepare($query_cumpleanos);
        $stmt->bindParam(':hoy_md', $hoy_md);
        $stmt->execute();
        $total_cumpleanos = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        debug_log("Cumpleaños encontrados para $hoy_md: $total_cumpleanos");
        
        return $total_cumpleanos;
        
    } catch (Exception $e) {
        error_log("Error en verificarCumpleanosDelDia: " . $e->getMessage());
        return 0;
    }
}

// Solo verificar, no crear publicación
$total_cumpleanos_hoy = verificarCumpleanosDelDia($conn);

// Verificar cumpleaños (pero NO crear publicación de texto)
verificarCumpleanosDelDia($conn);

    // Obtener parámetros de paginación
    $publicaciones_por_pagina = 10;
    $pagina_actual = isset($_GET['pagina']) ? (int)$_GET['pagina'] : 1;
    if ($pagina_actual < 1) $pagina_actual = 1;

    $offset = ($pagina_actual - 1) * $publicaciones_por_pagina;

// Obtener publicaciones con paginación 
$query_publicaciones = "SELECT p.*, 
                        u.first_Name, u.first_LastName, u.photo,
                        COUNT(pl.id) as likes,
                        EXISTS(SELECT 1 FROM publicaciones_likes WHERE publicacion_id = p.id AND usuario_id = :usuario_id_actual) as usuario_dio_like
                        FROM publicaciones p 
                        LEFT JOIN employee u ON p.usuario_id = u.id
                        LEFT JOIN publicaciones_likes pl ON p.id = pl.publicacion_id 
                        WHERE p.activo = 1 
                        GROUP BY p.id 
                        ORDER BY p.creado_en DESC 
                        LIMIT :limit OFFSET :offset";

    $stmt_publicaciones = $conn->prepare($query_publicaciones);
    $stmt_publicaciones->bindParam(':usuario_id_actual', $_SESSION['user_id']);
    $stmt_publicaciones->bindParam(':limit', $publicaciones_por_pagina, PDO::PARAM_INT);
    $stmt_publicaciones->bindParam(':offset', $offset, PDO::PARAM_INT);
    $stmt_publicaciones->execute();
    $publicaciones = $stmt_publicaciones->fetchAll(PDO::FETCH_ASSOC);

    // Obtener total de publicaciones para paginación
    $query_total = "SELECT COUNT(*) as total FROM publicaciones WHERE activo = 1";
    $stmt_total = $conn->prepare($query_total);
    $stmt_total->execute();
    $total_publicaciones = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];
    $total_paginas = ceil($total_publicaciones / $publicaciones_por_pagina);

    // Obtener cumpleaños del día actual (para la sección especial de cumpleaños)
    $hoy = date('m-d');
    $query_cumpleanos = "SELECT e.id, e.first_Name, e.first_LastName, e.second_LastName, e.photo,
                         COUNT(cl.id) as likes,
                         EXISTS(SELECT 1 FROM cumpleanos_likes WHERE empleado_id = e.id AND usuario_id = :usuario_id_actual) as usuario_dio_like
                         FROM employee e 
                         LEFT JOIN cumpleanos_likes cl ON e.id = cl.empleado_id 
                         WHERE DATE_FORMAT(e.birthdate, '%m-%d') = :hoy 
                         AND e.role != 'retirado' 
                         GROUP BY e.id 
                         ORDER BY e.first_Name";
    $stmt_cumpleanos = $conn->prepare($query_cumpleanos);
    $stmt_cumpleanos->bindParam(':usuario_id_actual', $_SESSION['user_id']);
    $stmt_cumpleanos->bindParam(':hoy', $hoy);
    $stmt_cumpleanos->execute();
    $cumpleanos = $stmt_cumpleanos->fetchAll(PDO::FETCH_ASSOC);

} catch (Exception $e) {
    error_log("Error general en portal.php: " . $e->getMessage());
    $error = "Ha ocurrido un error inesperado. Por favor, intenta nuevamente.";
}

// Funciones auxiliares
function procesarMultimediaPublicacion($file) {
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/publicaciones/';
    
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    
    // Límite real del servidor: 10MB
    $max_size = 10 * 1024 * 1024;
    $min_video_size = 8 * 1024 * 1024; // 8MB - mínimo para comprimir videos
    
    // Verificar errores de subida
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $error_messages = [
            UPLOAD_ERR_INI_SIZE => 'El archivo excede el límite de 10MB permitido por el servidor',
            UPLOAD_ERR_FORM_SIZE => 'El archivo excede el tamaño máximo permitido por el formulario',
            UPLOAD_ERR_PARTIAL => 'El archivo fue subido parcialmente',
            UPLOAD_ERR_NO_FILE => 'No se seleccionó ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'No existe directorio temporal',
            UPLOAD_ERR_CANT_WRITE => 'Error al escribir en el disco',
            UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la subida'
        ];
        throw new Exception($error_messages[$file['error']] ?? 'Error desconocido al subir archivo');
    }
    
    // Validar tamaño
    if ($file['size'] > $max_size) {
        $tamaño_mb = round($file['size'] / (1024 * 1024), 2);
        throw new Exception("El archivo es demasiado grande ({$tamaño_mb}MB). El límite es 10MB.");
    }
    
    // Tipos de archivo permitidos (imágenes + videos)
    $allowed_types = [
        'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
        'video/mp4', 'video/mpeg', 'video/quicktime', 'video/x-msvideo', 'video/webm'
    ];
    $file_type = mime_content_type($file['tmp_name']);
    
    if (!in_array($file_type, $allowed_types)) {
        throw new Exception('Solo se permiten archivos JPEG, PNG, GIF, WebP, MP4, AVI, MOV y WebM');
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $file_name = 'publicacion_' . time() . '_' . uniqid() . '.' . $extension;
    $file_path = $upload_dir . $file_name;
    
    // Determinar si es video y si necesita compresión
    $es_video = strpos($file_type, 'video/') !== false;
    $necesita_compresion = $es_video && $file['size'] > $min_video_size;
    
    if ($es_video && !$necesita_compresion) {
        // Video pequeño (<8MB), subir sin compresión
        if (!move_uploaded_file($file['tmp_name'], $file_path)) {
            throw new Exception('Error al subir el video');
        }
        return $file_name;
    }
    
    if (!move_uploaded_file($file['tmp_name'], $file_path)) {
        throw new Exception('Error al subir el archivo');
    }
    
    // Solo procesar si es necesario
    if (strpos($file_type, 'image/') !== false) {
        // Para imágenes, usar mejor calidad
        redimensionarImagenPublicacion($file_path, 1200, 900); // Aumentar dimensiones máximas
    } elseif ($necesita_compresion) {
        // Para videos grandes, comprimir con mejor calidad
        comprimirVideoPublicacion($file_path);
    }
    
    return $file_name;
}

// Y modifica el procesamiento de la publicación:
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['crear_publicacion'])) {
    $contenido = trim($_POST['contenido']);
    $tipo = 'texto';
    
    // Procesar multimedia si se subió
    $archivo_nombre = null;
    if (isset($_FILES['multimedia']) && $_FILES['multimedia']['error'] === UPLOAD_ERR_OK) {
        $archivo_nombre = procesarMultimediaPublicacion($_FILES['multimedia']);
        $tipo = determinarTipoArchivo($archivo_nombre);
        if ($contenido) {
            $tipo = 'mixto';
        }
    }
    
    if (!empty($contenido) || $archivo_nombre) {
        try {
            $query = "INSERT INTO publicaciones (usuario_id, contenido, imagen, tipo, creado_en) 
                      VALUES (:usuario_id, :contenido, :imagen, :tipo, NOW())";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':usuario_id', $_SESSION['user_id']);
            $stmt->bindParam(':contenido', $contenido);
            $stmt->bindParam(':imagen', $archivo_nombre);
            $stmt->bindParam(':tipo', $tipo);
            
            if ($stmt->execute()) {
                $success = "Publicación creada correctamente";
                echo "<script>window.location.href = 'portal.php';</script>";
                exit();
            } else {
                $error = "Error al crear la publicación";
            }
        } catch (PDOException $e) {
            $error = "Error en la base de datos: " . $e->getMessage();
        }
    } else {
        $error = "La publicación debe contener texto o un archivo multimedia";
    }
}

function determinarTipoArchivo($nombre_archivo) {
    $extension = strtolower(pathinfo($nombre_archivo, PATHINFO_EXTENSION));
    $extensiones_imagen = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extensiones_video = ['mp4', 'avi', 'mov', 'mpeg', 'webm', 'mkv', 'flv', 'wmv'];
    
    if (in_array($extension, $extensiones_imagen)) {
        return 'imagen';
    } elseif (in_array($extension, $extensiones_video)) {
        return 'video';
    } else {
        return 'archivo';
    }
}

function comprimirVideoPublicacion($file_path) {
    if (!file_exists($file_path)) return;
    
    // Verificar si FFmpeg está disponible
    $ffmpeg_path = shell_exec('which ffmpeg') ?: '/usr/bin/ffmpeg';
    
    if (!file_exists($ffmpeg_path)) {
        error_log("FFmpeg no disponible, no se puede comprimir video");
        return;
    }
    
    $file_info = pathinfo($file_path);
    $temp_path = $file_path . '_compressed.' . $file_info['extension'];
    
    // Configuración de compresión con MUCHA MEJOR CALIDAD
    $bitrate = '1500k'; // Aumentar bitrate para mejor calidad
    $crf = '23'; // CRF más bajo = mejor calidad (18-28 es un rango bueno, 23 es balanceado)
    $preset = 'medium'; // Preset más lento pero mejor calidad
    
    $command = "{$ffmpeg_path} -i \"{$file_path}\" " .
               "-c:v libx264 -preset {$preset} -crf {$crf} -b:v {$bitrate} " .
               "-c:a aac -b:a 128k " .
               "-movflags +faststart " . // Para streaming rápido
               "-y \"{$temp_path}\" 2>&1";
    
    exec($command, $output, $return_code);
    
    if ($return_code === 0 && file_exists($temp_path)) {
        // Verificar que el archivo comprimido es más pequeño
        $original_size = filesize($file_path);
        $compressed_size = filesize($temp_path);
        
        if ($compressed_size < $original_size) {
            // Reemplazar el original con el comprimido
            unlink($file_path);
            rename($temp_path, $file_path);
            error_log("Video comprimido: " . round($original_size/1024/1024, 2) . 
                     "MB -> " . round($compressed_size/1024/1024, 2) . "MB");
        } else {
            // Si la compresión no redujo el tamaño, mantener el original
            unlink($temp_path);
            error_log("Compresión no efectiva, manteniendo video original");
        }
    } else {
        error_log("Error en compresión de video: " . implode("\n", $output));
        if (file_exists($temp_path)) {
            unlink($temp_path);
        }
    }
}

function redimensionarImagenPublicacion($file_path, $max_width, $max_height) {
    if (!file_exists($file_path)) return;
    
    list($width, $height, $type) = getimagesize($file_path);
    
    // Solo redimensionar si es más grande que las dimensiones máximas
    if ($width <= $max_width && $height <= $max_height) {
        return;
    }
    
    // Calcular nuevas dimensiones manteniendo aspect ratio
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
        case IMAGETYPE_WEBP:
            $image = imagecreatefromwebp($file_path);
            break;
        default:
            return;
    }
    
    if (!$image) return;
    
    $new_image = imagecreatetruecolor($new_width, $new_height);
    
    // Mantener transparencia para PNG y GIF
    if ($type == IMAGETYPE_PNG || $type == IMAGETYPE_GIF || $type == IMAGETYPE_WEBP) {
        imagecolortransparent($new_image, imagecolorallocatealpha($new_image, 0, 0, 0, 127));
        imagealphablending($new_image, false);
        imagesavealpha($new_image, true);
    }
    
    imagecopyresampled($new_image, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
    
    // Guardar con MEJOR CALIDAD
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($new_image, $file_path, 90); // Aumentar calidad a 90%
            break;
        case IMAGETYPE_PNG:
            imagepng($new_image, $file_path, 9); // Máxima compresión PNG
            break;
        case IMAGETYPE_GIF:
            imagegif($new_image, $file_path);
            break;
        case IMAGETYPE_WEBP:
            imagewebp($new_image, $file_path, 90); // Calidad WebP al 90%
            break;
    }
    
    imagedestroy($image);
    imagedestroy($new_image);
}

function obtenerImagenPublicacion($imagen_nombre) {
    if ($imagen_nombre && !empty($imagen_nombre)) {
        $file_path = $_SERVER['DOCUMENT_ROOT'] . '/uploads/publicaciones/' . $imagen_nombre;
        if (file_exists($file_path)) {
            return '/uploads/publicaciones/' . $imagen_nombre;
        }
    }
    return null;
}

function generarUrlPaginacion($pagina) {
    $params = $_GET;
    $params['pagina'] = $pagina;
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Portal - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.css"/>
    <link rel="stylesheet" type="text/css" href="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick-theme.css"/>
<script src="https://unpkg.com/@ffmpeg/ffmpeg@0.12.6/dist/ffmpeg.min.js"></script>
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script type="text/javascript" src="https://cdn.jsdelivr.net/npm/slick-carousel@1.8.1/slick/slick.min.js"></script>
    <style>
        /* ESTILOS CON COLORES DEL ECOSISTEMA */
        :root {
            --color-primary: #003a5d;
            --color-secondary: #353132;
            --color-accent: #be1622;
            --color-light: #9d9d9c;
        }
        
        .btn-primary {
            background-color: var(--color-primary) !important;
            border-color: var(--color-primary) !important;
        }
        
        .btn-primary:hover {
            background-color: #002b47 !important;
            border-color: #002b47 !important;
        }
        
        .btn-outline-primary {
            border-color: var(--color-primary) !important;
            color: var(--color-primary) !important;
        }
        
        .btn-outline-primary:hover {
            background-color: var(--color-primary) !important;
            border-color: var(--color-primary) !important;
            color: white !important;
        }
        
        .btn-danger {
            background-color: var(--color-accent) !important;
            border-color: var(--color-accent) !important;
        }
        
        .btn-danger:hover {
            background-color: #a0121d !important;
            border-color: #a0121d !important;
        }
        
        .badge.bg-primary {
            background-color: var(--color-primary) !important;
        }
        
        .badge.bg-secondary {
            background-color: var(--color-secondary) !important;
        }
        
        .badge.bg-danger {
            background-color: var(--color-accent) !important;
        }
        
        .badge.bg-light {
            background-color: var(--color-light) !important;
            color: var(--color-secondary) !important;
        }
        
        .card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .card-header {
            background-color: var(--color-primary);
            color: white;
            border-bottom: 1px solid var(--color-primary);
        }
        
        .publicacion-imagen {
            max-width: 100%;
            max-height: 400px;
            border-radius: 8px;
            margin: 10px 0;
        }
        
        .like-btn {
            transition: all 0.3s ease;
            border: none;
            background: none;
        }
        
        .like-btn:hover {
            transform: scale(1.1);
        }
        
        .like-btn.liked {
            color: var(--color-accent) !important;
        }
        
        .cumpleanos-card {
            background: linear-gradient(135deg, #fff3cd, #ffeaa7);
            border: 2px solid #ffc107;
        }
        
        .mini-perfil {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border: 2px solid var(--color-primary);
        }
        
        .publicacion-card {
            transition: transform 0.2s ease;
        }
        
        .publicacion-card:hover {
            transform: translateY(-2px);
        }
        
        .sidebar-card {
            background-color: #f8f9fa;
            border-left: 4px solid var(--color-primary);
        }
        
        .pagination .page-link {
            color: var(--color-primary);
        }
        
        .pagination .page-item.active .page-link {
            background-color: var(--color-primary);
            border-color: var(--color-primary);
        }
        
        .btn-image {
            background-color: #f8f9fa;
            border: 1px dashed #dee2e6;
            padding: 6px 12px;
            font-size: 0.875rem;
        }
        
        .btn-image:hover {
            background-color: #e9ecef;
            border-color: var(--color-primary);
        }
        
        .image-preview {
            max-width: 200px;
            max-height: 200px;
            display: none;
            margin-top: 10px;
            border-radius: 8px;
        }
        
        .collapse-form {
            margin-bottom: 20px;
        }

	/* Estilos para videos en publicaciones */
	video.publicacion-imagen {
	    max-width: 100%;
	    max-height: 400px;
	    border-radius: 8px;
	    margin: 10px 0;
	    background-color: #000;
	}


/* Asegura que el contenedor general de cumpleaños ocupe todo el ancho */
.publicacion-card[data-tipo="cumpleanos"] .card-body > div {
    width: 100%;
}

/* Centrar correctamente las tarjetas dentro del HTML generado dinámicamente */
.publicacion-card[data-tipo="cumpleanos"] .cumpleanos-publicacion,
.publicacion-card[data-tipo="cumpleanos"] .cumpleanos-container,
.publicacion-card[data-tipo="cumpleanos"] .cumpleaneros-multiple {
    display: flex !important;
    justify-content: center !important;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    width: 100%;
    text-align: center;
}

/* Mantener tamaño original de las tarjetas individuales */
.publicacion-card[data-tipo="cumpleanos"] .cumpleanero-item {
    width: 100px;
    max-width: 100px;
    flex: 0 0 auto;
    text-align: center;
}

/* Evitar que el body o los divs internos reduzcan el ancho */
.publicacion-card[data-tipo="cumpleanos"] .card-body {
    display: block !important;
    width: 100% !important;
    text-align: center;
    padding: 1rem !important;
    background: transparent !important;
}


/* Asegura que cualquier elemento directo dentro del cuerpo de la tarjeta se centre */
.publicacion-card[data-tipo="cumpleanos"] .card-body {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    text-align: center;
    width: 100% !important;
    padding: 1rem !important;
    background: transparent !important;
}

/* Si hay un div contenedor interno, que no rompa el centrado */
.publicacion-card[data-tipo="cumpleanos"] .card-body > div {
    display: flex !important;
    justify-content: center !important;
    align-items: center !important;
    flex-wrap: wrap !important;
    width: 100% !important;
}

/* Asegura el tamaño y margen uniforme de las tarjetas individuales */
.publicacion-card[data-tipo="cumpleanos"] .cumpleanero-item {
    width: 100px;
    max-width: 100px;
    margin: 10px;
    flex: 0 0 auto;
    text-align: center;
}

/* Ocultar hora en publicaciones de cumpleaños */
.publicacion-card[data-tipo="cumpleanos"] .text-muted:has(.bi-clock) {
    display: none !important;
}

/* Alternativa más específica - ocultar el elemento que contiene la hora */
.publicacion-card[data-tipo="cumpleanos"] .d-flex.justify-content-between .text-muted:first-child {
    display: none !important;
}

/* Otra alternativa - ocultar directamente el small que contiene la hora */
.publicacion-card[data-tipo="cumpleanos"] .border-top .text-muted:first-child {
    visibility: hidden !important;
    width: 0 !important;
    height: 0 !important;
    overflow: hidden !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* --- INICIO: ESTILOS PARA LAS FLECHAS DEL CARRUSEL --- */

/* Estilo base para las flechas de Slick Carousel */
.slick-prev, .slick-next {
    font-size: 0; /* Ocultar el texto por defecto (< y >) */
    line-height: 0;
    position: absolute;
    top: 50%;
    display: block;
    width: 20px;
    height: 20px;
    padding: 0;
    transform: translate(0, -50%);
    cursor: pointer;
    color: transparent;
    border: none;
    outline: none;
    background: transparent;
    z-index: 10;
}

/* Icono de la flecha (usando Bootstrap Icons) */
.slick-prev:before, .slick-next:before {
    font-family: "bootstrap-icons";
    font-size: 16px;
    line-height: 1;
    opacity: 0.75;
    color: var(--dark-gray); /* Usamos tu color gris oscuro */
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
}

/* Flecha anterior */
.slick-prev:before {
    content: "\f12f"; /* icon-chevron-left */
}

/* Flecha siguiente */
.slick-next:before {
    content: "\f138"; /* icon-chevron-right */
}

/* Efecto hover */
.slick-prev:hover:before, .slick-next:hover:before {
    opacity: 1;
    color: var(--color-accent); /* Cambia al color principal al pasar el ratón */
}

/* Posicionamiento para que no se superpongan con el contenido */
.slick-prev {
    left: 0px;
}

.slick-next {
    right: 0px;
}

/* --- FIN: ESTILOS PARA LAS FLECHAS DEL CARRUSEL --- */

/* ESTILOS PARA EL BOTÓN TOGGLE Y ANIMACIONES */
.btn-toggle-accesos {
    background-color: var(--color-primary) !important;
    border-color: var(--color-primary) !important;
    color: white !important;
    padding: 0.75rem 1rem;
    border: none;
    font-weight: 600;
    transition: all 0.3s ease;
}

.btn-toggle-accesos:hover {
    background-color: #002b47 !important;
    border-color: #002b47 !important;
    transform: translateY(-1px);
}

.btn-toggle-accesos:focus {
    box-shadow: 0 0 0 0.2rem rgba(0, 58, 93, 0.25);
}

.btn-toggle-accesos .bi {
    transition: transform 0.3s ease;
}

/* Contenedores con transiciones suaves */
.carrusel-container, .accesos-content {
    transition: all 0.4s ease-in-out;
}

/* Estados iniciales */
.carrusel-container {
    opacity: 1;
    transform: translateY(0);
    max-height: 500px;
    overflow: hidden;
}

.accesos-content {
    opacity: 0;
    transform: translateY(-10px);
    max-height: 0;
    overflow: hidden;
    padding: 0 !important;
    border: none !important;
}

/* Estados cuando están visibles */
.carrusel-container.hidden {
    opacity: 0;
    transform: translateY(-10px);
    max-height: 0;
    margin-bottom: 0;
    padding: 0;
    border: none;
}

.accesos-content.visible {
    opacity: 1;
    transform: translateY(0);
    max-height: 500px;
    padding: 1rem !important;
}

/* Asegurar que cuando los accesos están ocultos, no haya espacio residual */
.accesos-content:not(.visible) {
    display: none !important;
}

/* Estilos para los elementos de la lista */
.lista-accesos .list-group-item {
    border: none;
    padding: 0.75rem 0;
    transition: all 0.2s ease;
}

.lista-accesos .list-group-item:hover {
    background-color: #f8f9fa;
    padding-left: 10px;
}

/* Asegurar que la tarjeta de accesos no tenga padding extra cuando está colapsada */
.card.sidebar-card:has(.accesos-content:not(.visible)) {
    padding-bottom: 0 !important;
}
    </style>
</head>
<body>
<?php include '../includes/header.php'; ?>
    
<div class="container-fluid">
    <div class="row" style="display: grid; grid-template-columns: 230px 1fr 300px; min-height: 100vh;">
        <!-- Sidebar izquierdo-->
        <?php include '../includes/sidebar.php'; ?>
        
        <main style="grid-column: 2; margin: 0 auto; max-width: 100%; padding: 0 1rem;">
            <div style="max-width: 700px; margin: 0 auto;">
                <!-- Botón para crear publicación -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Portal de Inicio</h1>
                    <?php if (tienePermiso($conn, $usuario['id'], $usuario['role'], $usuario['position_id'], 'portal', 'crear_publicacion')): ?>
                    <button type="button" class="btn btn-primary" data-bs-toggle="collapse" data-bs-target="#formPublicacion">
                        <i class="bi bi-plus-circle"></i> Crear Publicación
                    </button>
                    <?php endif; ?>
                </div>
                
                <!-- Formulario para nueva publicación (colapsable) -->
                <?php if (tienePermiso($conn, $usuario['id'], $usuario['role'], $usuario['position_id'], 'portal', 'crear_publicacion')): ?>
                <div class="collapse collapse-form" id="formPublicacion">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0"><i class="bi bi-pencil-square"></i> Crear Nueva Publicación</h5>
                        </div>
                        <div class="card-body">
                            <form method="POST" action="" enctype="multipart/form-data" id="formPublicacionContent">
                                <div class="mb-3">
                                    <label for="contenido" class="form-label">Contenido</label>
                                    <textarea class="form-control" id="contenido" name="contenido" rows="3" 
                                              placeholder="¿Qué quieres compartir?" maxlength="1000"></textarea>
                                    <div class="form-text">Máximo 1000 caracteres</div>
                                </div>
				<div class="mb-3">
				    <label class="form-label">Multimedia (opcional)</label>
				    <div class="d-flex align-items-center gap-2">
				        <button type="button" class="btn btn-image" onclick="document.getElementById('archivo').click()">
				            <i class="bi bi-file-earmark-plus"></i> Seleccionar Archivo
				        </button>
				        <small class="text-muted">
				            Formatos: JPG, PNG, GIF, MP4, AVI.<br>
				            <strong>Límites automáticos:</strong> Imágenes 10MB | Videos se comprimen automáticamente<br>
				            <em>Los videos largos pueden tomar varios minutos en procesarse</em>
				        </small>
				    </div>
				    <input type="file" class="form-control d-none" id="archivo" name="archivo" 
				           accept="image/*,video/*">
				    <div id="multimediaPreview" class="mt-2"></div>
				    <div id="uploadProgress" class="mt-2" style="display: none;">
				        <div class="progress">
				            <div class="progress-bar" role="progressbar" style="width: 0%"></div>
				        </div>
				        <small class="text-muted">Procesando... <span id="progressPercent">0%</span></small>
				    </div>
				    <div id="sizeError" class="alert alert-danger mt-2" style="display: none;"></div>
				</div>
                                <div class="d-flex gap-2">
					<button type="submit" name="crear_publicacion" class="btn btn-primary" id="btnPublicar">
					    <i class="bi bi-send"></i> Publicar
					</button>
                                    <button type="button" class="btn btn-secondary" data-bs-toggle="collapse" data-bs-target="#formPublicacion">
                                        Cancelar
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Publicaciones -->
                <div id="publicaciones-container">
                <?php /*
                    <!-- SECCIÓN DE CUMPLEAÑOS (SI HAY CUMPLEAÑEROS HOY) -->
                    <?php if (count($cumpleanos) > 0): ?>
                    <div class="card mb-4" style="border: 2px solid #be1622;">
                        <div class="card-header bg-white text-dark">
                            <h5 class="mb-0"><i class="bi bi-balloon"></i> 
                                <?php echo count($cumpleanos) == 1 ? 
                                    '¡Felicidades! ' . htmlspecialchars($cumpleanos[0]['first_Name'] . ' ' . $cumpleanos[0]['first_LastName']) . ' celebra su cumpleaños' : 
                                    '¡Felicidades! ' . count($cumpleanos) . ' Colaboradores celebran su cumpleaños'; ?>
                            </h5>
                        </div>
                        <div class="card-body bg-white p-3">
                            <div class="row" style="max-height: 400px; overflow-y: auto;">
                                <?php foreach ($cumpleanos as $cumpleanero): ?>
                                <div class="col-md-4 col-sm-6 mb-3">
                                    <div class="d-flex flex-column align-items-center p-3 rounded text-center" style="background-color: #f1f1f1; min-height: 200px; border-radius: 8px;">
                                        <?php 
                                        $foto_cumpleanero = $functions->obtenerFotoUsuario($cumpleanero['photo'] ?? null);
                                        ?>
                                        <img src="<?php echo $foto_cumpleanero; ?>" 
                                             class="rounded-circle mb-2" 
                                             style="width: 80px; height: 80px; object-fit: cover; border: 2px solid #be1622;"
                                             alt="<?php echo htmlspecialchars($cumpleanero['first_Name']); ?>">
                                        
                                        <h6 class="mb-2 text-dark"><?php echo htmlspecialchars($cumpleanero['first_Name'] . ' ' . $cumpleanero['first_LastName']); ?></h6>
                                        
                                        <button class="btn btn-outline-danger btn-sm like-btn cumpleanos-like mt-auto <?php echo $cumpleanero['usuario_dio_like'] ? 'liked' : ''; ?>" 
                                                data-id="<?php echo $cumpleanero['id']; ?>" 
                                                data-tipo="cumpleanos"
                                                style="border-color: #be1622; color: #be1622;">
                                            <i class="bi bi-heart<?php echo $cumpleanero['usuario_dio_like'] ? '-fill' : ''; ?>"></i>
                                            <span class="like-count"><?php echo $cumpleanero['likes']; ?></span>
                                        </button>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    */ ?>

                    <!-- SECCIÓN DE PUBLICACIONES NORMALES -->
                    <?php
			$publicaciones_normales = $publicaciones;
                    // Agrupar publicaciones normales por fecha
                    $publicaciones_agrupadas = [];
                    foreach ($publicaciones_normales as $publicacion) {
                        $fecha = date('Y-m-d', strtotime($publicacion['creado_en']));
                        $publicaciones_agrupadas[$fecha][] = $publicacion;
                    }
                    
                    // Función para formatear la fecha de manera amigable
                    function formatearFecha($fecha) {
                        $hoy = date('Y-m-d');
                        $ayer = date('Y-m-d', strtotime('-1 day'));
                        
                        if ($fecha == $hoy) {
                            return 'Hoy';
                        } elseif ($fecha == $ayer) {
                            return 'Ayer';
                        } else {
                            return date('d/m/Y', strtotime($fecha));
                        }
                    }
                    ?>

                    <?php if (count($publicaciones_normales) > 0): ?>
                        <?php foreach ($publicaciones_agrupadas as $fecha => $publicaciones_dia): ?>
                        <!-- Separador de fecha -->
                        <div class="d-flex align-items-center my-4">
                            <hr class="flex-grow-1" style="border-color: #9d9d9c; opacity: 0.3;">
                            <span class="mx-3 text-muted fw-bold" style="color: #9d9d9c !important;">
                                <?php echo formatearFecha($fecha); ?>
                            </span>
                            <hr class="flex-grow-1" style="border-color: #9d9d9c; opacity: 0.3;">
                        </div>
                        
                        <!-- Publicaciones normales del día -->
                        <?php foreach ($publicaciones_dia as $publicacion): ?>
                        <div class="card publicacion-card mb-4 <?php echo $publicacion['tipo'] == 'cumpleanos' ? 'cumpleanos-card' : ''; ?>" 
			     style="<?php echo $publicacion['tipo'] == 'cumpleanos' ? 'border: none !important; background: transparent !important; box-shadow: none !important;' : 'border-color: #be1622;'; ?>"
			     data-tipo="<?php echo $publicacion['tipo']; ?>">
                            <div class="card-body">
                                <!-- Información del usuario (SOLO para publicaciones normales) -->
				<?php if ($publicacion['tipo'] != 'cumpleanos'): ?>
				<div class="d-flex align-items-center mb-3">
				    <?php 
				    $foto_usuario = $functions->obtenerFotoUsuario($publicacion['photo'] ?? null);
				    ?>
				    <img src="<?php echo $foto_usuario; ?>" 
				         class="rounded-circle me-3" 
				         style="width: 50px; height: 50px; object-fit: cover;"
				         alt="<?php echo htmlspecialchars($publicacion['first_Name'] ?? 'Usuario'); ?>">
				    <div>
				        <h6 class="mb-0"><?php echo htmlspecialchars($publicacion['first_Name'] . ' ' . $publicacion['first_LastName']); ?></h6>
				        <small class="text-muted">
				            <?php echo date('H:i', strtotime($publicacion['creado_en'])); ?>
				        </small>
				    </div>
				</div>
				<?php endif; ?>

				<!-- Contenido de la publicación -->
				<?php if ($publicacion['contenido']): ?>
				<div class="mb-3">
				    <?php 
				    if ($publicacion['tipo'] == 'cumpleanos') {
				        // PUBLICACIÓN DE CUMPLEAÑOS - Renderizar HTML directamente
				        echo $publicacion['contenido'];
				    } else {
				        // PUBLICACIÓN NORMAL - Escapar y formatear
				        echo nl2br(htmlspecialchars($publicacion['contenido']));
				    }
				    ?>
				</div>
				<?php endif; ?>
                                
                                <!-- Imagen de la publicación -->
                                <?php 
				if ($publicacion['imagen']): 
				    $archivo_publicacion = obtenerImagenPublicacion($publicacion['imagen']);
				    if ($archivo_publicacion): 
				        $tipo_archivo = determinarTipoArchivo($publicacion['imagen']);
				?>
				<div class="text-center">
				    <?php if ($tipo_archivo == 'imagen'): ?>
				        <img src="<?php echo $archivo_publicacion; ?>" 
				             class="publicacion-imagen" 
				             alt="Imagen de la publicación">
				    <?php elseif ($tipo_archivo == 'video'): ?>
				        <video controls class="publicacion-imagen">
				            <source src="<?php echo $archivo_publicacion; ?>" 
				                    type="<?php echo mime_content_type($_SERVER['DOCUMENT_ROOT'] . $archivo_publicacion); ?>">
				            Tu navegador no soporta el elemento video.
				        </video>
				    <?php else: ?>
				        <div class="alert alert-info">
				            <i class="bi bi-file-earmark"></i>
				            Archivo adjunto: <?php echo htmlspecialchars($publicacion['imagen']); ?>
				        </div>
				    <?php endif; ?>
				</div>
				<?php endif; endif; ?>
                                
                                <!-- Información y acciones -->
				<div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
				    <small class="text-muted">
				        <i class="bi bi-clock"></i> 
				        <?php echo date('H:i', strtotime($publicacion['creado_en'])); ?>
				    </small>
				    
				    <!-- SOLO mostrar botones para publicaciones NORMALES, NO para cumpleaños -->
				    <?php if ($publicacion['tipo'] != 'cumpleanos'): ?>
				    <div class="d-flex align-items-center gap-2">
				        <!-- Botón de like -->
				        <button class="btn btn-outline-danger btn-sm like-btn publicacion-like <?php echo $publicacion['usuario_dio_like'] ? 'liked' : ''; ?>" 
				                data-id="<?php echo $publicacion['id']; ?>" 
				                data-tipo="publicacion">
				            <i class="bi bi-heart<?php echo $publicacion['usuario_dio_like'] ? '-fill' : ''; ?>"></i>
				            <span class="like-count"><?php echo $publicacion['likes']; ?></span>
				        </button>
				        
				        <!-- Botón de eliminar (solo para usuarios autorizados) -->
				        <?php if (tienePermiso($conn, $usuario['id'], $usuario['role'], $usuario['position_id'], 'portal', 'eliminar_publicacion')): ?>
				        <button class="btn btn-outline-secondary btn-sm delete-btn" 
				                data-bs-toggle="modal" 
				                data-bs-target="#modalEliminarPublicacion"
				                data-publicacion-id="<?php echo $publicacion['id']; ?>">
				            <i class="bi bi-trash"></i>
				        </button>
				        <?php endif; ?>
				    </div>
				    <?php else: ?>
				    <!-- Para cumpleaños, mostrar solo el espacio vacío para mantener el layout -->
				    <div></div>
				    <?php endif; ?>
				</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    
                    <?php elseif (empty($cumpleanos)): ?>
                        <!-- Mensaje cuando no hay publicaciones normales NI cumpleaños -->
                        <div class="card" style="border-color: #be1622;">
                            <div class="card-body text-center py-5">
                                <i class="bi bi-inbox display-1 text-muted"></i>
                                <h3 class="text-muted mt-3">No hay publicaciones aún</h3>
                                <p class="text-muted">Sé el primero en compartir algo con la comunidad.</p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Paginación -->
                <?php if ($total_paginas > 1): ?>
                <nav aria-label="Paginación de publicaciones">
                    <ul class="pagination justify-content-center">
                        <?php if ($pagina_actual > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo generarUrlPaginacion($pagina_actual - 1); ?>">
                                <i class="bi bi-chevron-left"></i> Anterior
                            </a>
                        </li>
                        <?php endif; ?>
                        
                        <?php
                        // Mostrar páginas (máximo 5 páginas alrededor de la actual)
                        $inicio = max(1, $pagina_actual - 2);
                        $fin = min($total_paginas, $pagina_actual + 2);
                        
                        if ($inicio > 1) {
                            echo '<li class="page-item"><a class="page-link" href="' . generarUrlPaginacion(1) . '">1</a></li>';
                            if ($inicio > 2) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                        }
                        
                        for ($i = $inicio; $i <= $fin; $i++): 
                        ?>
                        <li class="page-item <?php echo $i == $pagina_actual ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo generarUrlPaginacion($i); ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        
                        <?php
                        if ($fin < $total_paginas) {
                            if ($fin < $total_paginas - 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            echo '<li class="page-item"><a class="page-link" href="' . generarUrlPaginacion($total_paginas) . '">' . $total_paginas . '</a></li>';
                        }
                        ?>
                        
                        <?php if ($pagina_actual < $total_paginas): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo generarUrlPaginacion($pagina_actual + 1); ?>">
                                Siguiente <i class="bi bi-chevron-right"></i>
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                
                <div class="text-center text-muted mb-4">
                    <small>Mostrando página <?php echo $pagina_actual; ?> de <?php echo $total_paginas; ?> 
                    (<?php echo $total_publicaciones; ?> publicaciones en total)</small>
                </div>
                <?php endif; ?>
            </div>
        </main>
        
	<!-- Sidebar derecha para accesos rápidos -->
	<aside style="grid-column: 3;">
	    <div class="position-sticky" style="top: 2rem;">
		<!-- INICIO: BLOQUE DE CARRUSEL -->
		<div class="card sidebar-card mb-4 carrusel-container" id="carruselContainer">
		    <div class="card-header">
		        <h6 class="mb-0"><i class="bi bi-newspaper"></i> Últimas del Boletín</h6>
		    </div>
		    <div class="card-body p-0">
		        <?php if (!empty($posts_boletin)): ?>
		            <div class="boletin-carousel">
		                <?php foreach ($posts_boletin as $post): ?>
		                    <div class="p-3">
		                        <?php
		                        $default_image = 'https://picsum.photos/seed/azclegal/300/200.jpg';
		                        $imagen_url = !empty($post['_embedded']['wp:featuredmedia'][0]['source_url']) ? $post['_embedded']['wp:featuredmedia'][0]['source_url'] : $default_image;
		                        $enlace_a_noticia = "boletin.php?url=" . urlencode($post['link']);
		                        $titulo = htmlspecialchars($post['title']['rendered']);
		                        $extracto = htmlspecialchars(limitar_palabras($post['excerpt']['rendered'], 10, '...'));
		                        ?>
		                        <a href="<?php echo $enlace_a_noticia; ?>" class="text-decoration-none text-dark">
		                            <div style="display: flex; flex-direction: column; justify-content: center; align-items: center; height: 100%; text-align: center;">
		                                <img src="<?php echo htmlspecialchars($imagen_url); ?>" 
		                                     class="img-fluid rounded mb-2" 
		                                     style="max-width: 100%; max-height: 150px; object-fit: cover;" 
		                                     alt="<?php echo $titulo; ?>"
		                                     onerror="this.src='<?php echo $default_image; ?>'">
		                                <h6 class="mb-1" style="color: #be1622 !important;"><?php echo $titulo; ?></h6>
		                                <small class="text-muted d-block">
		                                    <?php echo $extracto; ?>
		                                </small>
		                            </div>
		                        </a>
		                    </div>
		                <?php endforeach; ?>
		            </div>
		            
		            <?php if ($boletin_error): ?>
		                <div class="alert alert-warning m-3">
		                    <small>
		                        <i class="bi bi-exclamation-triangle"></i>
		                        <?php echo $boletin_error; ?>
		                    </small>
		                </div>
		            <?php endif; ?>
		        <?php else: ?>
		            <div class="text-center p-3 text-muted">
		                <i class="bi bi-inbox"></i>
		                <p class="mb-0">No hay noticias recientes.</p>
		                <?php if ($boletin_error): ?>
		                    <div class="alert alert-warning mt-3">
		                        <small>
		                            <i class="bi bi-exclamation-triangle"></i>
		                            <?php echo $boletin_error; ?>
		                        </small>
		                    </div>
		                <?php endif; ?>
		            </div>
		        <?php endif; ?>
		    </div>
		</div>
		<!-- FIN: BLOQUE DE CARRUSEL -->
	
	        <!-- INICIO: BOTÓN DE ACCESOS RÁPIDOS (COLLAPSIBLE) -->
	        <div class="card sidebar-card">
	            <div class="card-header p-0">
	                <button class="btn btn-primary w-100 text-start d-flex justify-content-between align-items-center btn-toggle-accesos" 
	                        type="button" 
	                        id="btnToggleAccesos">
	                    <span>
	                        <i class="bi bi-lightning" id="btnIconLeft"></i> 
	                        <span id="btnText">Accesos Rápidos</span>
	                    </span>
	                    <i class="bi bi-chevron-down" id="btnIcon"></i>
	                </button>
	            </div>
	            <div class="card-body accesos-content lista-accesos" id="accesosRapidosContent">
	                <div class="list-group list-group-flush">
	                    <a href="ticket_index.php" class="list-group-item list-group-item-action d-flex align-items-center">
	                        <i class="bi bi-ticket-perforated me-2 text-primary"></i>
	                        <div>
	                            <strong>Crear Ticket</strong>
	                            <small class="d-block text-muted">Solicitar items, soporte o intercambios</small>
	                        </div>
	                    </a>
	                    <a href="permisos_index.php" class="list-group-item list-group-item-action d-flex align-items-center">
	                        <i class="bi bi-check2-circle me-2 text-primary"></i>
	                        <div>
	                            <strong>Solicitar Permiso</strong>
	                            <small class="d-block text-muted">Solicitar permisos remunerados, no remunerados, por horas o trabajo en casa</small>
	                        </div>
	                    </a>
	                </div>
	            </div>
	        </div>
	        <!-- FIN: BOTÓN DE ACCESOS RÁPIDOS -->
	    </div>
	</aside>
	    </div>
	</div>
	
	<!-- Modal para confirmar eliminación -->
	<div class="modal fade" id="modalEliminarPublicacion" tabindex="-1">
	    <div class="modal-dialog">
	        <div class="modal-content">
	            <div class="modal-header">
	                <h5 class="modal-title">Confirmar Eliminación</h5>
	                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
	            </div>
	            <div class="modal-body">
	                <p>¿Estás seguro de que quieres eliminar esta publicación? Esta acción no se puede deshacer.</p>
	            </div>
	            <div class="modal-footer">
	                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
	                <form method="POST" action="" id="formEliminarPublicacion">
	                    <input type="hidden" name="publicacion_id" id="publicacion_id_eliminar">
	                    <button type="submit" name="eliminar_publicacion" class="btn btn-danger">Eliminar</button>
	                </form>
	            </div>
	        </div>
	    </div>
	</div>



<script>
    jQuery(document).ready(function($) {
        

        console.log('📄 DOM y dependencias (jQuery, Bootstrap, Slick) cargados correctamente.');


        // Función para mostrar notificaciones
        function mostrarNotificacion(mensaje, tipo = 'success') {
            const notification = $(`<div class="alert alert-${tipo === 'success' ? 'success' : 'danger'} alert-dismissible fade show" style="position: fixed; top: 20px; right: 20px; z-index: 9999; min-width: 300px;">${mensaje}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>`);
            $('body').append(notification);
            setTimeout(() => notification.fadeOut(500, function() { $(this).remove(); }), 5000);
        }

        // Función para la vista previa de multimedia:
        function configurarVistaPreviaMultimedia() {
            const multimediaInput = $('#archivo');
            const multimediaPreview = $('#multimediaPreview');
            
            if (multimediaInput.length && multimediaPreview.length) {
                multimediaInput.on('change', function(e) {
                    const file = e.target.files[0];
                    multimediaPreview.empty();
                    
                    if (file) {
                        const reader = new FileReader();
                        reader.onload = function(e) {
                            let previewHtml = '';
                            if (file.type.startsWith('image/')) {
                                previewHtml = `<img src="${e.target.result}" class="publicacion-imagen" style="max-width: 300px; max-height: 300px;" alt="Vista previa"><div class="mt-1"><small>${file.name} (${(file.size / (1024 * 1024)).toFixed(2)} MB)</small></div>`;
                            } else if (file.type.startsWith('video/')) {
                                previewHtml = `<video controls class="publicacion-imagen" style="max-width: 300px; max-height: 300px;"><source src="${e.target.result}" type="${file.type}">Tu navegador no soporta el elemento video.</video><div class="mt-1"><small>${file.name} (${(file.size / (1024 * 1024)).toFixed(2)} MB)</small></div>`;
                            }
                            multimediaPreview.html(previewHtml);
                        };
                        reader.readAsDataURL(file);
                    }
                });
            }
        }
        


// --- INICIO: Toda la LÓGICA DE LIKES (Refactorizada a jQuery) CORREGIDA ---

function cargarEstadoLikesUsuario() {
    console.log('🔍 Cargando estado de likes del usuario...');
    const cumpleanosLikeButtons = $('.like-btn.cumpleanos-like');
    if (cumpleanosLikeButtons.length === 0) return;
    
    const empleadosIds = cumpleanosLikeButtons.map(function() { return $(this).data('id'); }).get();
    console.log('🆔 IDs a verificar:', empleadosIds);
    
    $.post('', { 'accion': 'obtener_likes_usuario', 'empleados_ids': empleadosIds.join(',') }, function(data) {
        console.log('📡 Respuesta del servidor:', data);
        if (data.success) {
            cumpleanosLikeButtons.each(function() {
                const empleadoId = $(this).data('id');
                if (data.likes_usuario.includes(parseInt(empleadoId))) {
                    $(this).addClass('liked').find('i').removeClass('bi-heart').addClass('bi-heart-fill');
                }
            });
            console.log('🎉 Estado de likes cargado correctamente');
        } else {
            console.error('❌ Error del servidor:', data.error);
        }
    }, 'json').fail(function(xhr, status, error) {
        console.error('🌐 Error de red:', error);
    });
}

function cargarLikesCumpleanosPublicaciones() {
    console.log('🎂 Cargando likes de cumpleaños en publicaciones permanentes...');
    const cumpleanosLikeButtons = $('.publicacion-card[data-tipo="cumpleanos"] .like-btn.cumpleanos-like');
    if (cumpleanosLikeButtons.length === 0) return;
    
    const empleadosIds = cumpleanosLikeButtons.map(function() { return parseInt($(this).data('id')); }).get();
    if (empleadosIds.length === 0) return;

    console.log('🆔 IDs a procesar:', empleadosIds);

    // Primero obtener los likes del usuario
    $.post('', { 
        'accion': 'obtener_likes_usuario', 
        'empleados_ids': empleadosIds.join(',') 
    }, function(userData) {
        console.log('👤 Respuesta likes usuario:', userData);
        
        if (userData.success && userData.likes_usuario) {
            const likesUsuario = userData.likes_usuario.map(id => parseInt(id));
            
            // Luego obtener los likes totales
            $.post('', { 
                'accion': 'obtener_likes_totales', 
                'empleados_ids': empleadosIds.join(',') 
            }, function(totalData) {
                console.log('📊 Respuesta likes totales:', totalData);
                
                if (totalData.success && totalData.likes_totales) {
                    const likesTotales = totalData.likes_totales;
                    
                    // Actualizar cada botón
                    cumpleanosLikeButtons.each(function() {
                        const $this = $(this);
                        const empleadoId = parseInt($this.data('id'));
                        const yaTieneLike = likesUsuario.includes(empleadoId);
                        const likesTotalesCount = parseInt(likesTotales[empleadoId]) || 0;
                        
                        console.log(`🎯 Actualizando botón ID ${empleadoId}:`, {
                            yaTieneLike: yaTieneLike,
                            likesTotales: likesTotalesCount
                        });
                        
                        // Actualizar contador
                        $this.find('.like-count').text(likesTotalesCount);
                        
                        // Actualizar estado visual
                        if (yaTieneLike) {
                            $this.addClass('liked').find('i').removeClass('bi-heart').addClass('bi-heart-fill');
                        } else {
                            $this.removeClass('liked').find('i').removeClass('bi-heart-fill').addClass('bi-heart');
                        }
                    });
                    
                    console.log('🎉 Estado de likes en publicaciones cargado correctamente');
                } else {
                    console.error('❌ Error en likes totales:', totalData.error);
                }
            }, 'json').fail(function(xhr, status, error) {
                console.error('🌐 Error cargando likes totales:', error);
            });
            
        } else {
            console.error('❌ Error en likes usuario:', userData.error);
        }
    }, 'json').fail(function(xhr, status, error) {
        console.error('🌐 Error cargando likes usuario:', error);
    });
}

function inicializarTodosLosLikes() {
    console.log('🎮 Inicializando event listeners para likes...');
    const likeButtons = $('.like-btn');
    
    // Usar .off().on() para evitar duplicados
    likeButtons.off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();
        
        const $this = $(this);
        const elementoId = $this.data('id');
        const tipo = $this.data('tipo');
        const likeIcon = $this.find('i');
        const likeCount = $this.find('.like-count');
        
        const yaTieneLike = $this.hasClass('liked');
        const vaASerLiked = !yaTieneLike;
        const currentLikes = parseInt(likeCount.text()) || 0;
        const accion = vaASerLiked ? 'like' : 'unlike';
        
        console.log('🎯 Click en like:', {
            id: elementoId,
            tipo: tipo,
            estadoActual: yaTieneLike,
            accion: accion,
            likesActuales: currentLikes
        });
        
        // Animación visual INMEDIATA
        if (vaASerLiked) {
            $this.addClass('liked');
            likeIcon.removeClass('bi-heart').addClass('bi-heart-fill');
            likeCount.text(currentLikes + 1);
        } else {
            $this.removeClass('liked');
            likeIcon.removeClass('bi-heart-fill').addClass('bi-heart');
            likeCount.text(Math.max(0, currentLikes - 1));
        }
        
        $this.prop('disabled', true);
        
        $.post('', {
            'accion': 'like', 
            'publicacion_id': elementoId, 
            'tipo': tipo, 
            'accion_like': accion
        }, function(data) {
            $this.prop('disabled', false);
            console.log('✅ Respuesta servidor:', data);
            
            if (data.success) {
                if (tipo === 'cumpleanos') {
                    // Para cumpleaños, recargar los contadores reales después de un breve delay
                    setTimeout(() => {
                        cargarLikesCumpleanosPublicaciones();
                    }, 500);
                }
            } else {
                console.error('❌ Error del servidor:', data.error);
                // Revertir cambios visuales
                revertirCambiosVisuales($this, likeIcon, likeCount, vaASerLiked, currentLikes);
            }
        }, 'json').fail(function(xhr, status, error) {
            console.error('🌐 Error de red:', error);
            $this.prop('disabled', false);
            // Revertir cambios visuales por error de red
            revertirCambiosVisuales($this, likeIcon, likeCount, vaASerLiked, currentLikes);
        });
    });
    
    console.log('🎉 Event listeners inicializados para ' + likeButtons.length + ' botones');
}

// Función auxiliar para revertir cambios visuales
function revertirCambiosVisuales($boton, $icono, $contador, fueLike, likesOriginales) {
    if (fueLike) {
        $boton.removeClass('liked');
        $icono.removeClass('bi-heart-fill').addClass('bi-heart');
        $contador.text(likesOriginales);
    } else {
        $boton.addClass('liked');
        $icono.removeClass('bi-heart').addClass('bi-heart-fill');
        $contador.text(likesOriginales);
    }
}

function inicializarSistemaLikes() {
    console.log('🚀 INICIANDO SISTEMA DE LIKES...');
    inicializarTodosLosLikes();
    
    // Cargar estado después de un breve delay para asegurar que el DOM esté listo
    setTimeout(() => {
        cargarEstadoLikesUsuario();
        cargarLikesCumpleanosPublicaciones();
    }, 300);
}

function verificarEstadoFinalBotones() {
    setTimeout(() => {
        const todosBotonesCumpleanos = $('.publicacion-card[data-tipo="cumpleanos"] .like-btn.cumpleanos-like');
        console.log('🔍 ESTADO FINAL BOTONES CUMPLEAÑOS:');
        todosBotonesCumpleanos.each(function(index) {
            const $btn = $(this);
            const id = $btn.data('id');
            const tieneLike = $btn.hasClass('liked');
            const likesCount = $btn.find('.like-count').text();
            console.log(`   ${index + 1}. ID: ${id}, Liked: ${tieneLike}, Likes: ${likesCount}`);
        });
        
        // Verificar también botones de publicaciones normales
        const botonesPublicaciones = $('.like-btn.publicacion-like');
        console.log('🔍 Botones publicaciones normales:', botonesPublicaciones.length);
    }, 2000);
}

// --- FIN: LÓGICA DE LIKES CORREGIDA ---


        // --- INICIO: Toda la LÓGICA DE FORMULARIO Y VIDEOS (Sin cambios en la lógica interna) ---

// Función para comprimir video antes de subir (VERSIÓN MEJORADA)
function comprimirVideo(file, calidad = 0.7) {
    return new Promise((resolve, reject) => {
        if (!file.type.startsWith('video/')) {
            resolve(file);
            return;
        }

        // Si el video es menor a 10MB, no comprimir
        if (file.size <= 10 * 1024 * 1024) {
            resolve(file);
            return;
        }

        console.log('🎬 Comprimiendo video...', file.name, 'Tamaño original:', (file.size / (1024 * 1024)).toFixed(2), 'MB');

        const video = document.createElement('video');
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        
        video.src = URL.createObjectURL(file);
        
        video.onloadedmetadata = function() {
            // Redimensionar manteniendo aspect ratio
            const maxWidth = 1280;
            const scale = Math.min(maxWidth / video.videoWidth, 0.8); // Reducir al 80% máximo
            canvas.width = Math.floor(video.videoWidth * scale);
            canvas.height = Math.floor(video.videoHeight * scale);
            
            console.log('📐 Dimensiones originales:', video.videoWidth, 'x', video.videoHeight);
            console.log('📏 Dimensiones comprimidas:', canvas.width, 'x', canvas.height);
            
            // Ir al primer frame
            video.currentTime = 0;
        };
        
        video.onseeked = function() {
            try {
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                
                canvas.toBlob(function(blob) {
                    if (!blob) {
                        reject(new Error('No se pudo comprimir el video'));
                        return;
                    }
                    
                    console.log('✅ Video comprimido. Tamaño original:', (file.size / (1024 * 1024)).toFixed(2), 'MB → Comprimido:', (blob.size / (1024 * 1024)).toFixed(2), 'MB');
                    
                    const compressedFile = new File([blob], file.name, {
                        type: 'video/mp4',
                        lastModified: Date.now()
                    });
                    
                    URL.revokeObjectURL(video.src);
                    resolve(compressedFile);
                }, 'video/mp4', calidad);
            } catch (error) {
                reject(error);
            }
        };
        
        video.onerror = function() {
            reject(new Error('Error al cargar el video para compresión'));
        };
        
        // Timeout por seguridad
        setTimeout(() => {
            reject(new Error('Timeout en compresión de video'));
        }, 30000); // 30 segundos timeout
    });
}

// Actualizar la función de envío del formulario
function configurarEnvioFormulario() {
    const form = document.getElementById('formPublicacionContent');
    const btnPublicar = document.getElementById('btnPublicar');
    const progressContainer = document.getElementById('uploadProgress');
    
    if (!form || !btnPublicar) {
        console.error('❌ No se encontraron los elementos del formulario');
        return;
    }
    
    const progressBar = progressContainer ? progressContainer.querySelector('.progress-bar') : null;
    const progressPercent = document.getElementById('progressPercent');
    
    form.addEventListener('submit', async function(e) {
        e.preventDefault();
        
        const archivoInput = document.getElementById('archivo');
        const contenido = document.getElementById('contenido').value;
        
        // Validaciones básicas
        if (!contenido.trim() && (!archivoInput || archivoInput.files.length === 0)) {
            mostrarNotificacion('La publicación debe contener texto o un archivo multimedia', 'error');
            return false;
        }
        
        btnPublicar.disabled = true;
        btnPublicar.innerHTML = '<i class="bi bi-hourglass-split"></i> Procesando...';
        
        if (progressContainer) {
            progressContainer.style.display = 'block';
        }
        
        try {
            let archivoFinal = null;
            
            // Procesar archivo si existe
            if (archivoInput && archivoInput.files.length > 0) {
                const archivoOriginal = archivoInput.files[0];
                
                // Para imágenes, solo validar tamaño
                if (archivoOriginal.type.startsWith('image/')) {
                    if (archivoOriginal.size > 10 * 1024 * 1024) {
                        throw new Error('La imagen es demasiado grande. Máximo 10MB.');
                    }
                    archivoFinal = archivoOriginal;
                } 
                // Para videos, validar y comprimir
                else if (archivoOriginal.type.startsWith('video/')) {
                    try {
                        mostrarNotificacion('Procesando video...', 'info');
                        archivoFinal = await validarYComprimirVideo(archivoOriginal);
                    } catch (error) {
                        throw new Error('Error con el video: ' + error.message);
                    }
                } 
                // Otros tipos de archivo
                else {
                    if (archivoOriginal.size > 10 * 1024 * 1024) {
                        throw new Error('El archivo es demasiado grande. Máximo 10MB.');
                    }
                    archivoFinal = archivoOriginal;
                }
            }
            
            const formData = new FormData();
            formData.append('contenido', contenido);
            
            if (archivoFinal) {
                formData.append('archivo', archivoFinal);
            }
            
            // Mostrar progreso de subida
            if (progressBar) progressBar.style.width = '0%';
            if (progressPercent) progressPercent.textContent = '0%';
            
            // Enviar con AJAX
            const xhr = new XMLHttpRequest();
            
            xhr.upload.addEventListener('progress', function(e) {
                if (e.lengthComputable && progressBar && progressPercent) {
                    const percentComplete = (e.loaded / e.total) * 100;
                    progressBar.style.width = percentComplete + '%';
                    progressPercent.textContent = Math.round(percentComplete) + '%';
                }
            });
            
            xhr.addEventListener('load', function() {
                if (progressContainer) progressContainer.style.display = 'none';
                
                console.log('Respuesta del servidor:', xhr.responseText);
                
                // Verificar si la respuesta es HTML (error)
                if (xhr.responseText.trim().startsWith('<!DOCTYPE') || 
                    xhr.responseText.trim().startsWith('<')) {
                    console.error('El servidor devolvió HTML en lugar de JSON');
                    mostrarNotificacion('Error del servidor: respuesta inesperada', 'error');
                    btnPublicar.disabled = false;
                    btnPublicar.innerHTML = '<i class="bi bi-send"></i> Publicar';
                    return;
                }
                
                try {
                    const response = JSON.parse(xhr.responseText);
                    
                    if (response.success) {
                        mostrarNotificacion(response.message, 'success');
                        form.reset();
                        const multimediaPreview = document.getElementById('multimediaPreview');
                        if (multimediaPreview) multimediaPreview.innerHTML = '';
                        
                        // Recargar después de 1.5 segundos
                        setTimeout(() => {
                            window.location.reload();
                        }, 1500);
                    } else {
                        mostrarNotificacion(response.error || response.message || 'Error desconocido', 'error');
                    }
                } catch (parseError) {
                    console.error('Error parseando JSON:', parseError);
                    console.error('Respuesta cruda:', xhr.responseText);
                    mostrarNotificacion('Error procesando la respuesta del servidor', 'error');
                } finally {
                    btnPublicar.disabled = false;
                    btnPublicar.innerHTML = '<i class="bi bi-send"></i> Publicar';
                }
            });
            
            xhr.addEventListener('error', function() {
                if (progressContainer) progressContainer.style.display = 'none';
                mostrarNotificacion('Error de conexión. Verifica tu internet.', 'error');
                btnPublicar.disabled = false;
                btnPublicar.innerHTML = '<i class="bi bi-send"></i> Publicar';
            });
            
            xhr.addEventListener('timeout', function() {
                if (progressContainer) progressContainer.style.display = 'none';
                mostrarNotificacion('Tiempo de espera agotado', 'error');
                btnPublicar.disabled = false;
                btnPublicar.innerHTML = '<i class="bi bi-send"></i> Publicar';
            });
            
            // Configurar timeout de 60 segundos (más tiempo para compresión)
            xhr.timeout = 60000;
            xhr.open('POST', 'upload_handler.php');
            xhr.send(formData);
            
        } catch (error) {
            if (progressContainer) progressContainer.style.display = 'none';
            console.error('Error general:', error);
            mostrarNotificacion('Error: ' + error.message, 'error');
            btnPublicar.disabled = false;
            btnPublicar.innerHTML = '<i class="bi bi-send"></i> Publicar';
        }
    });
}

// Función de compresión nativa mejorada - VERSIÓN CORREGIDA
async function comprimirVideoNativo(file) {
    return new Promise((resolve, reject) => {
        if (!file.type.startsWith('video/')) {
            resolve(file);
            return;
        }

        console.log('🎬 Iniciando compresión nativa...', file.name, 'Tamaño:', (file.size / (1024 * 1024)).toFixed(2), 'MB');

        const video = document.createElement('video');
        const canvas = document.createElement('canvas');
        const ctx = canvas.getContext('2d');
        const chunks = [];
        
        video.src = URL.createObjectURL(file);
        video.muted = true;
        video.playsInline = true;

        video.onloadedmetadata = function() {
            console.log('📐 Dimensiones originales:', video.videoWidth, 'x', video.videoHeight);
            
            // Calcular nuevas dimensiones (máximo 640x480) - VARIABLES DEFINIDAS AQUÍ
            let newWidth = video.videoWidth;
            let newHeight = video.videoHeight;
            
            if (newWidth > 640) {
                newHeight = (newHeight * 640) / newWidth;
                newWidth = 640;
            }
            if (newHeight > 480) {
                newWidth = (newWidth * 480) / newHeight;
                newHeight = 480;
            }
            
            canvas.width = Math.floor(newWidth);
            canvas.height = Math.floor(newHeight);
            
            console.log('📏 Dimensiones comprimidas:', canvas.width, 'x', canvas.height);

            // Configurar bitrate según tamaño
            let videoBitsPerSecond = 1000000; // 1 Mbps para mejor calidad
            
            if (file.size > 20 * 1024 * 1024) {
                videoBitsPerSecond = 1500000; // 1.5 Mbps para videos muy grandes
            } else if (file.size <= 15 * 1024 * 1024) {
                videoBitsPerSecond = 800000; // 800 kbps para compresión ligera
            }

            // Configurar MediaRecorder - intentar usar MP4 primero
            const stream = canvas.captureStream(15); // 15 fps
            let mediaRecorder;
            let mimeType = 'video/mp4';
            
            try {
                // Intentar con MP4 primero
                if (MediaRecorder.isTypeSupported('video/mp4')) {
                    mediaRecorder = new MediaRecorder(stream, {
                        mimeType: 'video/mp4',
                        videoBitsPerSecond: videoBitsPerSecond
                    });
                    console.log('✅ Usando codec MP4');
                } else {
                    // Fallback a WebM
                    mimeType = 'video/webm';
                    mediaRecorder = new MediaRecorder(stream, {
                        mimeType: 'video/webm;codecs=vp9',
                        videoBitsPerSecond: videoBitsPerSecond
                    });
                    console.log('✅ Usando codec WebM VP9');
                }
            } catch (e) {
                // Fallback final a WebM VP8
                mimeType = 'video/webm';
                mediaRecorder = new MediaRecorder(stream, {
                    mimeType: 'video/webm;codecs=vp8',
                    videoBitsPerSecond: videoBitsPerSecond
                });
                console.log('✅ Usando codec WebM VP8 (fallback)');
            }

            mediaRecorder.ondataavailable = function(e) {
                if (e.data && e.data.size > 0) {
                    chunks.push(e.data);
                }
            };

            mediaRecorder.onstop = function() {
                const extension = mimeType === 'video/mp4' ? 'mp4' : 'webm';
                const compressedBlob = new Blob(chunks, { type: mimeType });
                URL.revokeObjectURL(video.src);
                
                const tamañoOriginalMB = (file.size / (1024 * 1024)).toFixed(2);
                const tamañoComprimidoMB = (compressedBlob.size / (1024 * 1024)).toFixed(2);
                const reduccion = ((1 - compressedBlob.size / file.size) * 100).toFixed(1);
                
                console.log('✅ Compresión completada:',
                    `Original: ${tamañoOriginalMB}MB → Comprimido: ${tamañoComprimidoMB}MB (${reduccion}% reducción)`
                );

                if (compressedBlob.size > 8 * 1024 * 1024) {
                    console.warn('⚠️ La compresión no fue suficiente');
                    mostrarNotificacion(`Video comprimido a ${tamañoComprimidoMB}MB. Si es muy grande, intenta con un video más corto.`, 'warning');
                } else {
                    mostrarNotificacion(`✅ Video comprimido: ${tamañoOriginalMB}MB → ${tamañoComprimidoMB}MB`, 'success');
                }

                const compressedFile = new File([compressedBlob], 
                    file.name.replace(/\.[^/.]+$/, "") + '_compressed.' + extension,
                    { type: mimeType, lastModified: Date.now() }
                );

                resolve(compressedFile);
            };

            mediaRecorder.onerror = function(e) {
                URL.revokeObjectURL(video.src);
                reject(new Error('Error en la grabación: ' + e.error));
            };

            // Iniciar grabación
            mediaRecorder.start(1000);

            // Función para procesar el video frame por frame
            let currentTime = 0;
            const frameInterval = 100; // 100ms entre frames
            const maxDuration = Math.min(video.duration, 120); // Máximo 2 minutos

            function processFrame() {
                if (currentTime >= maxDuration || video.ended) {
                    mediaRecorder.stop();
                    return;
                }

                video.currentTime = currentTime;
            }

            video.onseeked = function() {
                ctx.clearRect(0, 0, canvas.width, canvas.height);
                ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
                currentTime += frameInterval / 1000;
                
                setTimeout(processFrame, 10);
            };

            // Iniciar procesamiento
            video.currentTime = 0;
        };

        video.onerror = function() {
            URL.revokeObjectURL(video.src);
            reject(new Error('Error al cargar el video'));
        };

        // Timeout de seguridad
        setTimeout(() => {
            if (video.src) {
                URL.revokeObjectURL(video.src);
            }
            reject(new Error('Timeout en compresión de video (máximo 2 minutos)'));
        }, 120000);
    });
}

// Función de validación mejorada
async function validarYComprimirVideo(file) {
    if (!file.type.startsWith('video/')) {
        return file;
    }
    
    // NO COMPRIMIR VIDEOS PEQUEÑOS (<8MB)
    if (file.size <= 8 * 1024 * 1024) {
        console.log('📹 Video pequeño (<8MB), no necesita compresión');
        return file;
    }
    
    // Para videos entre 8MB y 15MB, compresión ligera
    if (file.size <= 15 * 1024 * 1024) {
        const confirmar = confirm(
            `🎥 COMPRESIÓN LIGERA DE VIDEO\n\n` +
            `Tamaño: ${(file.size / (1024 * 1024)).toFixed(1)}MB\n\n` +
            `¿Deseas comprimir ligeramente el video manteniendo buena calidad?`
        );
        if (!confirmar) {
            return file;
        }
    }
    
    // Para videos >15MB, compresión más agresiva
    mostrarNotificacion('Comprimiendo video para optimizar tamaño...', 'info');
    
    return await comprimirVideoNativo(file);
}

// Función para obtener duración del video (mejorada)
function obtenerDuracionVideo(file) {
    return new Promise((resolve, reject) => {
        const video = document.createElement('video');
        video.preload = 'metadata';
        
        video.onloadedmetadata = function() {
            URL.revokeObjectURL(video.src);
            resolve(video.duration);
        };
        
        video.onerror = function() {
            URL.revokeObjectURL(video.src);
            reject(new Error('No se pudo obtener la duración del video'));
        };
        
        video.src = URL.createObjectURL(file);
        
        // Timeout
        setTimeout(() => {
            if (video.src) {
                URL.revokeObjectURL(video.src);
            }
            reject(new Error('Timeout obteniendo duración'));
        }, 5000);
    });
}

        // --- FIN: LÓGICA DE FORMULARIO Y VIDEOS ---


        // --- INICIO: EJECUCIÓN PRINCIPAL AL CARGAR LA PÁGINA ---

        // 1. Configurar elementos básicos
        configurarVistaPreviaMultimedia();
        configurarEnvioFormulario();
        inicializarSistemaLikes();

        // 2. Configurar modal de eliminación
        const deleteButtons = $('.delete-btn');
        const publicacionIdInput = $('#publicacion_id_eliminar');
        deleteButtons.on('click', function() {
            publicacionIdInput.val($(this).data('publicacion-id'));
        });

        // 3. Inicializar el carrusel del boletín (Ahora funciona porque jQuery y Slick están cargados)
        // En la inicialización del carrusel, agregar manejo de errores
function inicializarCarruselBoletin() {
    const $carrusel = $('.boletin-carousel');
    
    if ($carrusel.length > 0) {
        try {
            $carrusel.slick({
                infinite: true,
                slidesToShow: 1,
                slidesToScroll: 1,
                autoplay: true,
                autoplaySpeed: 4000,
                arrows: true,
                dots: true,
                adaptiveHeight: true,
                pauseOnHover: true,
                pauseOnFocus: false,
                waitForAnimate: false // Mejorar rendimiento
            });
            
            console.log('✅ Carrusel del boletín inicializado correctamente');
        } catch (error) {
            console.error('❌ Error al inicializar carrusel:', error);
            // Fallback: mostrar noticias en lista
            $carrusel.parent().html('<div class="alert alert-info">Las noticias se están cargando...</div>');
        }
    }
}

// Llamar después de que jQuery esté listo
$(document).ready(function() {
    inicializarCarruselBoletin();
});
        
            if (typeof initializeSidebar === 'function') {
        initializeSidebar();
    }

        // 4. Auto-expandir el formulario si hay un error de PHP (Ahora funciona porque Bootstrap está cargado)
        <?php if ($error && isset($_POST['crear_publicacion'])): ?>
        const formPublicacion = document.getElementById('formPublicacion');
        if (formPublicacion) {
            new bootstrap.Collapse(formPublicacion, { toggle: true });
        }
        <?php endif; ?>

        // 5. Debug final
        setTimeout(() => {
            verificarEstadoFinalBotones();
        }, 2000);

// 6. Configurar comportamiento del botón de Accesos Rápidos/Boletín con animaciones
function configurarAccesosRapidos() {
    const btnToggleAccesos = document.getElementById('btnToggleAccesos');
    const accesosRapidosContent = document.getElementById('accesosRapidosContent');
    const carruselContainer = document.getElementById('carruselContainer');
    const btnText = document.getElementById('btnText');
    const btnIcon = document.getElementById('btnIcon');
    const btnIconLeft = document.getElementById('btnIconLeft');
    
    if (btnToggleAccesos && accesosRapidosContent && carruselContainer) {
        let accesosVisibles = false;
        
        btnToggleAccesos.addEventListener('click', function() {
            accesosVisibles = !accesosVisibles;
            
            if (accesosVisibles) {
                // Mostrar accesos rápidos con animación, ocultar carrusel con animación
                carruselContainer.classList.add('hidden');
                
                // Primero mostrar el contenido para que la animación funcione
                accesosRapidosContent.style.display = 'block';
                // Forzar reflow para que la animación CSS funcione
                accesosRapidosContent.offsetHeight;
                
                setTimeout(() => {
                    accesosRapidosContent.classList.add('visible');
                }, 10);
                
                // Cambiar texto e íconos
                btnText.textContent = 'Boletín';
                btnIcon.classList.replace('bi-chevron-down', 'bi-chevron-up');
                btnIconLeft.classList.replace('bi-lightning', 'bi-newspaper');
            } else {
                // Ocultar accesos rápidos con animación, mostrar carrusel con animación
                accesosRapidosContent.classList.remove('visible');
                
                setTimeout(() => {
                    carruselContainer.classList.remove('hidden');
                    // Ocultar completamente después de la animación
                    setTimeout(() => {
                        accesosRapidosContent.style.display = 'none';
                    }, 400);
                }, 200);
                
                // Cambiar texto e íconos
                btnText.textContent = 'Accesos Rápidos';
                btnIcon.classList.replace('bi-chevron-up', 'bi-chevron-down');
                btnIconLeft.classList.replace('bi-newspaper', 'bi-lightning');
            }
        });
        
        // Asegurar estado inicial correcto
        carruselContainer.classList.remove('hidden');
        accesosRapidosContent.classList.remove('visible');
        accesosRapidosContent.style.display = 'none';
    }
}
        // --- FIN: EJECUCIÓN PRINCIPAL ---

// Llamar la función después de que todo esté cargado
setTimeout(configurarAccesosRapidos, 100);
    });
</script>
</body>
</html>