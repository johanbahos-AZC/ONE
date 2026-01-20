<?php
// procesar_cumpleanos.php - VERSIÓN CORREGIDA
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/includes/config.php';
require_once ROOT_PATH . '/includes/database.php';
require_once ROOT_PATH . '/includes/functions.php';

function crearPublicacionCumpleanosDiaria() {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        // Usar hora de Colombia
        $timezone = new DateTimeZone('America/Bogota');
        $hoy_colombia = new DateTime('now', $timezone);
        $hoy_md = $hoy_colombia->format('m-d');
        $hoy_date = $hoy_colombia->format('Y-m-d');
        
        echo "=== INICIANDO PROCESAMIENTO CUMPLEAÑOS ===\n";
        echo "Fecha hoy: $hoy_date ($hoy_md)\n";
        
        // Buscar un usuario del sistema (admin/it) o usar NULL si no hay
        $query_usuario = "SELECT id FROM employee WHERE role IN ('administrador', 'it') LIMIT 1";
        $stmt_usuario = $conn->prepare($query_usuario);
        $stmt_usuario->execute();
        $usuario_valido = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
        
        $usuario_sistema = $usuario_valido ? $usuario_valido['id'] : null;
        echo $usuario_sistema ? "✅ Usuario sistema encontrado: ID $usuario_sistema\n" : "ℹ️ No hay usuario admin activo, se usará NULL\n";
        
        // Verificar si ya existe publicación de cumpleaños para hoy
        $query_verificar = "SELECT id, contenido, creado_en 
                           FROM publicaciones 
                           WHERE tipo = 'cumpleanos' 
                           AND DATE(CONVERT_TZ(creado_en, '+00:00', '-05:00')) = :hoy";
        $stmt_verificar = $conn->prepare($query_verificar);
        $stmt_verificar->bindParam(':hoy', $hoy_date);
        $stmt_verificar->execute();
        
        $publicaciones_existentes = $stmt_verificar->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($publicaciones_existentes) > 0) {
            echo "❌ YA EXISTEN publicaciones de cumpleaños para hoy:\n";
            foreach ($publicaciones_existentes as $pub) {
                echo "   - ID: {$pub['id']} | Fecha: {$pub['creado_en']}\n";
                echo "   - Contenido: " . substr($pub['contenido'], 0, 100) . "...\n";
            }
            return false;
        }
        
        // Buscar cumpleaños de hoy CON FOTOS
        $query_cumpleanos = "SELECT id, first_Name, first_LastName, second_LastName, photo 
                            FROM employee 
                            WHERE DATE_FORMAT(birthdate, '%m-%d') = :hoy_md 
                            AND role != 'retirado'";
        $stmt = $conn->prepare($query_cumpleanos);
        $stmt->bindParam(':hoy_md', $hoy_md);
        $stmt->execute();
        $cumpleaneros = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $total_cumpleanos = count($cumpleaneros);
        echo "🔍 Cumpleaños encontrados en BD: $total_cumpleanos\n";
        
        if ($total_cumpleanos > 0) {
            echo "👥 Cumpleañeros:\n";
            foreach ($cumpleaneros as $cumpleanero) {
                echo "   - {$cumpleanero['first_Name']} {$cumpleanero['first_LastName']}\n";
            }
        }
        
        // Solo crear publicación si hay cumpleañeros
        if ($total_cumpleanos > 0) {
            // Obtener likes actuales y estado de like del usuario actual para cada cumpleañero
            $likes_por_empleado = [];
            $user_liked_status = [];
            
            foreach ($cumpleaneros as $cumpleanero) {
                // Obtener total de likes
                $query_likes = "SELECT COUNT(*) as total_likes FROM cumpleanos_likes WHERE empleado_id = :empleado_id";
                $stmt_likes = $conn->prepare($query_likes);
                $stmt_likes->bindParam(':empleado_id', $cumpleanero['id']);
                $stmt_likes->execute();
                $likes_data = $stmt_likes->fetch(PDO::FETCH_ASSOC);
                $likes_por_empleado[$cumpleanero['id']] = $likes_data['total_likes'];
                
                // Obtener si el usuario actual ya dio like (necesitamos un usuario para probar)
                // Como esto se ejecuta via cron, no hay usuario en sesión, así que inicializamos como no-like
                $user_liked_status[$cumpleanero['id']] = false; // CORRECCIÓN AQUÍ
            }
                
            // Crear contenido SIN la estructura de tarjeta externa
            $contenido = "<div class='cumpleanos-permanente' style='border: 2px solid #be1622; border-radius: 8px; margin-bottom: 20px;'>";
            $contenido .= "<div class='card-header bg-white text-dark' style='border-bottom: 1px solid #dee2e6; padding: 1rem;'>";
            $contenido .= "<h5 class='mb-0'><img src='/assets/images/globos.png' style='width: 44px; height: 44px; margin-right: 8px; vertical-align: middle;'> ";
            
            if ($total_cumpleanos == 1) {
                $contenido .= '¡Felicidades! ' . htmlspecialchars($cumpleaneros[0]['first_Name'] . ' ' . $cumpleaneros[0]['first_LastName']) . ' celebra su cumpleaños';
            } else {
                $contenido .= '¡Felicidades! ' . $total_cumpleanos . ' Colaboradores celebran su cumpleaños';
            }
            
            $contenido .= "</h5></div>";
            $contenido .= "<div class='card-body bg-white p-3'>";
            $contenido .= "<div class='row' style='max-height: 400px; overflow-y: auto;'>";
            
            foreach ($cumpleaneros as $cumpleanero) {
	    // Usar rutas relativas para las imágenes
	    if ($cumpleanero['photo'] && !empty($cumpleanero['photo'])) {
	        $foto = '/uploads/photos/' . $cumpleanero['photo'];
	    } else {
	        $foto = '/assets/images/default_avatar.png';
	    }
	    
	    $nombre_completo = htmlspecialchars($cumpleanero['first_Name'] . ' ' . $cumpleanero['first_LastName']);
	    $likes_count = $likes_por_empleado[$cumpleanero['id']] ?? 0;
	    
	    $contenido .= "<div class='col-md-4 col-sm-6 mb-3'>";
	    $contenido .= "<div class='d-flex flex-column align-items-center p-3 rounded text-center' style='background-color: #f1f1f1; min-height: 200px; border-radius: 8px;'>";
	    $contenido .= "<img src='$foto' class='rounded-circle mb-2' style='width: 80px; height: 80px; object-fit: cover; border: 2px solid #be1622;' alt='$nombre_completo'>";
	    $contenido .= "<h6 class='mb-2 text-dark'>$nombre_completo</h6>";
	    
	    // Botón de like NEUTRAL - JavaScript cargará el estado real
	    $contenido .= "<button class='btn btn-outline-danger btn-sm like-btn cumpleanos-like mt-auto' 
	                            data-id='{$cumpleanero['id']}' 
	                            data-tipo='cumpleanos'
	                            data-initial-likes='$likes_count'
	                            style='border-color: #be1622; color: #be1622;'>
	                        <i class='bi bi-heart'></i>
	                        <span class='like-count'>$likes_count</span>
	                      </button>";
	    
	    $contenido .= "</div></div>";
	}
            
            $contenido .= "</div></div></div>";
            
            // Insertar publicación con usuario_id NULL
            $query = "INSERT INTO publicaciones (usuario_id, contenido, tipo, creado_en, activo) 
                      VALUES (NULL, :contenido, 'cumpleanos', CONVERT_TZ(NOW(), '+00:00', '-05:00'), 1)";
            $stmt = $conn->prepare($query);
            $stmt->bindParam(':contenido', $contenido, PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                $publicacion_id = $conn->lastInsertId();
                echo "✅ ✅ ✅ PUBLICACIÓN PERMANENTE CREADA EXITOSAMENTE\n";
                echo "📋 ID: $publicacion_id | Usuario: NULL\n";
                return true;
            } else {
                echo "❌ Error al ejecutar INSERT\n";
                return false;
            }
        } else {
            echo "ℹ️ No hay cumpleañeros hoy\n";
            return false;
        }
        
    } catch (Exception $e) {
        echo "🚨 ERROR: " . $e->getMessage() . "\n";
        return false;
    }
}

// Ejecutar
$resultado = crearPublicacionCumpleanosDiaria();
echo $resultado ? "🎯 RESULTADO: Publicación creada\n" : "🎯 RESULTADO: No se creó publicación\n";
?>