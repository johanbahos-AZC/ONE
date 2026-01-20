<?php
require_once 'database.php';
require_once 'debug.php';

class Functions {
    private $conn;
    
    public function __construct() {
        $database = new Database();
        $this->conn = $database->getConnection();
        debug_log("Functions class initialized");
    }
    
   public function obtenerEmpleado($cc) {
    try {
        $database = new Database();
        $conn = $database->getConnection();

        $query = "SELECT 
                    id,
                    CC,
                    CONCAT(first_Name, ' ', 
                           IFNULL(second_Name, ''), ' ', 
                           first_LastName, ' ', 
                           IFNULL(second_LastName, '')) AS nombre,
                    position AS cargo,
                    company,
                    sede_id,
                    city
                  FROM employee 
                  WHERE CC = :cc 
                  LIMIT 1";

        $stmt = $conn->prepare($query);
        $stmt->bindParam(':cc', $cc, PDO::PARAM_INT);
        $stmt->execute();

        $empleado = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($empleado) {
            return $empleado;
        } else {
            return ['error' => 'Empleado no encontrado'];
        }
    } catch (Exception $e) {
        return ['error' => $e->getMessage()];
    }
}

    
    // Función para registrar actividad en el historial (ORIGINAL)
    public function registrarHistorial($ticket_id, $usuario_id, $item_id, $accion, $cantidad, $notas, $admin_id = null) {
        if ($admin_id === null) {
            $admin_id = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : null;
        }
        
        debug_log("registrarHistorial llamado - ticket_id: $ticket_id, usuario_id: $usuario_id, item_id: $item_id, accion: $accion, admin_id: $admin_id");
        
        $query = "INSERT INTO historial (ticket_id, usuario_id, item_id, accion, cantidad, notas, admin_id) 
                  VALUES (:ticket_id, :usuario_id, :item_id, :accion, :cantidad, :notas, :admin_id)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->bindParam(':item_id', $item_id);
        $stmt->bindParam(':accion', $accion);
        $stmt->bindParam(':cantidad', $cantidad);
        $stmt->bindParam(':notas', $notas);
        $stmt->bindParam(':admin_id', $admin_id);
        
        return $stmt->execute();
    }
    
    // Función para registrar historial de equipos (ORIGINAL)
    public function registrarHistorialEquipo($accion, $equipo_id, $notas, $admin_id = null) {
        if ($admin_id === null) {
            $admin_id = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : null;
        }
        
        $query = "INSERT INTO historial (ticket_id, usuario_id, item_id, accion, cantidad, notas, admin_id) 
                  VALUES (NULL, NULL, :equipo_id, :accion, 1, :notas, :admin_id)";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':equipo_id', $equipo_id);
        $stmt->bindParam(':accion', $accion);
        $stmt->bindParam(':notas', $notas);
        $stmt->bindParam(':admin_id', $admin_id);
        
        return $stmt->execute();
    }
    
    public function obtenerItems() {
    try {
        $database = new Database();
        $conn = $database->getConnection();

        $query = "SELECT i.id, i.nombre, i.descripcion, i.stock, i.stock_minimo, i.tipo, 
                         i.necesita_restock, c.nombre AS categoria_nombre
                  FROM items i
                  LEFT JOIN categorias c ON i.categoria_id = c.id
                  ORDER BY i.nombre ASC";

        $stmt = $conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        return [];
    }
}

    
    // Función para obtener todas las categorías
    public function obtenerCategorias() {
        $query = "SELECT * FROM categorias ORDER BY nombre";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Función para obtener usuarios desde API externa
    public function obtenerUsuariosDesdeAPI() {
        try {
            $url = EMPLEADOS_API_URL . 'usuarios';
            
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($http_code == 200 && $response) {
                return json_decode($response, true);
            } else {
                return [];
            }
        } catch (Exception $e) {
            error_log("Error obteniendo usuarios desde API: " . $e->getMessage());
            return [];
        }
    }

    // Función para obtener archivos por usuario (ORIGINAL)
    public function obtenerArchivosPorUsuario($usuario_id, $file_type = null) {
        $query = "SELECT f.* 
                 FROM files f 
                 WHERE f.id_employee = :usuario_id";
        
        if ($file_type) {
            $query .= " AND f.file_type = :file_type";
        }
        
        $query .= " ORDER BY f.description ASC";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':usuario_id', $usuario_id);
        
        if ($file_type) {
            $stmt->bindParam(':file_type', $file_type);
        }
        
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Función para obtener equipos por usuario
    public function obtenerEquiposPorUsuario($usuario_id) {
        $query = "SELECT e.*, eq.activo_fijo, eq.marca, eq.modelo, eq.serial_number
                 FROM employee e 
                 LEFT JOIN equipos eq ON e.activo_fijo = eq.activo_fijo
                 WHERE e.id = :usuario_id AND eq.activo_fijo IS NOT NULL";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':usuario_id', $usuario_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // Función para descargar acta
public function descargarActa($file_hash, $user_id) {
    try {
        debug_log("descargarActa llamado - hash: $file_hash, user_id: $user_id");
        
        $query = "SELECT f.*, e.id as employee_id, e.role as user_role
                 FROM files f 
                 INNER JOIN employee e ON f.id_employee = e.id 
                 WHERE f.file_hash = :file_hash";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':file_hash', $file_hash);
        $stmt->execute();
        
        $file = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$file) {
            throw new Exception("Archivo no encontrado");
        }
        
        // Verificar permisos: dueño, admin o IT pueden descargar
        $is_owner = ($file['id_employee'] == $user_id);
        $is_admin = ($_SESSION['user_role'] == 'administrador' || $_SESSION['user_role'] == 'it');
        
        if (!$is_owner && !$is_admin) {
            throw new Exception("No tiene permisos para descargar este archivo");
        }
        
        // Obtener contenido del archivo
        $file_content = $file['file_content'];
        
        // Si es un resource (BLOB), leerlo
        if (is_resource($file_content)) {
            $file_content = stream_get_contents($file_content);
        }
        
        // Verificar que el contenido no esté vacío
        if (empty($file_content)) {
            throw new Exception("El archivo está vacío o corrupto");
        }
        
        debug_log("Archivo preparado para descarga: " . $file['file_name'] . ", tamaño: " . strlen($file_content) . " bytes");
        
        return [
            'success' => true,
            'file_name' => $file['file_name'],
            'file_content' => $file_content,
            'mime_type' => $file['mime_type']
        ];
        
    } catch (Exception $e) {
        $errorMsg = "Error en descargarActa: " . $e->getMessage();
        debug_log($errorMsg);
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

    // Función para eliminar archivo
    public function eliminarArchivo($file_hash, $user_id) {
        try {
            debug_log("=== ELIMINAR ARCHIVO INICIADO ===");
            debug_log("file_hash: $file_hash");
            debug_log("user_id: $user_id");
            
            // Primero obtener información específica del archivo
            $query = "SELECT * FROM files WHERE file_hash = :file_hash";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':file_hash', $file_hash);
            $stmt->execute();
            
            $archivo = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$archivo) {
                throw new Exception("Archivo no encontrado");
            }
            
            debug_log("Archivo encontrado - ID: " . $archivo['id'] . ", Nombre: " . $archivo['file_name']);
            
            // VERIFICAR PERMISOS: Solo el dueño o un admin puede eliminar
            if ($archivo['id_employee'] != $user_id && $_SESSION['user_role'] != 'administrador' && $_SESSION['user_role'] != 'it') {
                throw new Exception("No tiene permisos para eliminar este archivo");
            }
            
            // Eliminar el archivo ESPECÍFICO por ID (no por hash, para evitar eliminar múltiples)
            $query = "DELETE FROM files WHERE id = :file_id";
            $stmt = $this->conn->prepare($query);
            $stmt->bindParam(':file_id', $archivo['id'], PDO::PARAM_INT);
            
            if ($stmt->execute()) {
                $rowCount = $stmt->rowCount();
                debug_log("Filas afectadas: $rowCount");
                
                if ($rowCount === 1) {
                    debug_log("Archivo eliminado correctamente");
                    
                    // Registrar en historial
                    $this->registrarHistorial(
                        null,                       // ticket_id
                        $archivo['id_employee'],    // usuario_id (dueño del archivo)
                        null,                       // item_id (siempre null para archivos)
                        'eliminar_archivo',         // accion
                        1,                          
                        "Archivo eliminado: " . $archivo['file_name'], // notas
                        $user_id                    
                    );
                    
                    return [
                        'success' => true,
                        'message' => 'Archivo eliminado correctamente'
                    ];
                } else {
                    throw new Exception("No se eliminó el archivo (filas afectadas: $rowCount)");
                }
            } else {
                throw new Exception("Error en la consulta DELETE");
            }
            
        } catch (Exception $e) {
            $errorMsg = "Error en eliminarArchivo: " . $e->getMessage();
            debug_log($errorMsg);
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
public function generarActaActivoFijo($usuario_id, $activo_fijo, $admin_id) {
    try {
        debug_log("=== GENERAR ACTA ACTIVO FIJO INICIADO ===");
        debug_log("usuario_id: $usuario_id, activo_fijo: $activo_fijo, admin_id: $admin_id");

        // Obtener información del usuario
        $query_usuario = "SELECT e.*, s.nombre as sede_nombre, f.name as firm_name 
                        FROM employee e 
                        LEFT JOIN sedes s ON e.sede_id = s.id
                        LEFT JOIN firm f ON e.id_firm = f.id
                        WHERE e.id = :usuario_id";
        $stmt_usuario = $this->conn->prepare($query_usuario);
        $stmt_usuario->bindParam(':usuario_id', $usuario_id);
        
        if (!$stmt_usuario->execute()) {
            $errorInfo = $stmt_usuario->errorInfo();
            throw new Exception("Error en consulta usuario: " . $errorInfo[2]);
        }
        
        $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            throw new Exception("Usuario no encontrado con ID: " . $usuario_id);
        }

        // Obtener información del equipo POR ACTIVO FIJO
        $query_equipo = "SELECT e.*, s.nombre as sede_nombre 
                       FROM equipos e 
                       LEFT JOIN sedes s ON e.sede_id = s.id
                       WHERE e.activo_fijo = :activo_fijo";  
        $stmt_equipo = $this->conn->prepare($query_equipo);
        $stmt_equipo->bindParam(':activo_fijo', $activo_fijo);  // Bind del activo_fijo
        
        if (!$stmt_equipo->execute()) {
            $errorInfo = $stmt_equipo->errorInfo();
            throw new Exception("Error en consulta equipo: " . $errorInfo[2]);
        }
        
        $equipo = $stmt_equipo->fetch(PDO::FETCH_ASSOC);
        
        if (!$equipo) {
            throw new Exception("Equipo no encontrado con Activo Fijo: " . $activo_fijo);
        }

        // Crear el documento PDF
        $fileContent = $this->crearDocumentoPDF($usuario, $equipo);
        
        if (!$fileContent) {
            throw new Exception("Error al crear el documento PDF");
        }

        $fileHash = hash('sha256', $fileContent);
        $fileSize = strlen($fileContent);
        $fileName = 'acta_activo_fijo_' . $usuario['CC'] . '_' . $equipo['activo_fijo'] . '.pdf';

        // Guardar en la base de datos
        $query = "INSERT INTO files (id_employee, file_type, file_name, file_content, file_hash, file_size, mime_type, description, signed, uploaded_by, created_at) 
                 VALUES (:id_employee, 'activo_fijo_entrega', :file_name, :file_content, :file_hash, :file_size, 
                 'application/pdf', :description, 0, :uploaded_by, NOW())";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':id_employee', $usuario_id);
        $stmt->bindParam(':file_name', $fileName);
        $stmt->bindParam(':file_content', $fileContent, PDO::PARAM_LOB);
        $stmt->bindParam(':file_hash', $fileHash);
        $stmt->bindParam(':file_size', $fileSize);
        $stmt->bindParam(':description', $fileName);
        $stmt->bindParam(':uploaded_by', $admin_id);
        
        if ($stmt->execute()) {
            $file_id = $this->conn->lastInsertId();
            
            // Registrar en historial
            $this->registrarHistorial(
                null, 
                $admin_id,     
                null,          
                'generar_acta_activo_fijo', 
                1, 
                "Acta de activo fijo generada: " . $fileName . " para equipo: " . $equipo['activo_fijo'],
                $admin_id
            );
            
            debug_log("Acta generada exitosamente - ID: $file_id");
            
            return [
                'success' => true,
                'file_id' => $file_id,
                'file_name' => $fileName,
                'file_hash' => $fileHash,
                'message' => 'Acta generada correctamente'
            ];
        } else {
            $errorInfo = $stmt->errorInfo();
            throw new Exception("Error al guardar archivo: " . $errorInfo[2]);
        }
        
    } catch (Exception $e) {
        $errorMsg = "Error en generarActaActivoFijo: " . $e->getMessage();
        debug_log($errorMsg);
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

private function crearDocumentoPDF($usuario, $equipo) {
    try {
        debug_log("crearDocumentoPDF llamado - VERSION TABLAS COMPACTAS");

        $fpdf_path = __DIR__ . '/FPDF/fpdf.php';
        if (!file_exists($fpdf_path)) {
            throw new Exception("FPDF no encontrado en: $fpdf_path");
        }
        require_once $fpdf_path;

        $fecha = date('d/m/Y');
        $nombreCorto = trim($usuario['first_Name'] . ' ' . $usuario['first_LastName']);

        $getValue = function($value) {
            if ($value === null || $value === 'No especificado' || $value === '') {
                return 'N/A';
            }
            return $value;
        };

        // Crear PDF en tamaño carta
        $pdf = new FPDF('P', 'mm', [216, 279]); // Carta
        $pdf->AddPage();
        
        // Insertar logo en esquina superior derecha
	$logo = __DIR__ . '/../assets/images/logo_main.png';
	debug_log("Ruta logo: " . $logo);
	if (file_exists($logo)) {
	    $pdf->Image($logo, $pdf->GetPageWidth() - 3.35 - 40, 10, 35);
	} else {
	    debug_log("Logo no encontrado en: " . $logo);
	}


        // Colores y estilos
        $pdf->SetDrawColor(50, 50, 50);
        $pdf->SetFillColor(220, 220, 220);
        $pdf->SetTextColor(0, 0, 0);

        // ================= ENCABEZADO =================
        $pdf->SetFont('Arial', 'B', 14);
        $pdf->Cell(0, 10, utf8_decode('FORMATO DE ACTIVOS FIJOS'), 0, 1, 'C');
        $pdf->Ln(2);

        // ================= DATOS PERSONALES =================
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6.5, utf8_decode('DATOS PERSONALES'), 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 9);
        
        $companias = [
	    1 => 'AZC',
	    2 => 'BilingueLaw', 
	    3 => 'LawyerDesk',
	    4 => 'AZC Legal',
	    5 => 'Matiz LegalTech'
	];
	
	$datos_personales = [
            'NOMBRE'      => $getValue($nombreCorto),
            'CÉDULA'      => $getValue($usuario['CC']),
            'ÁREA'        => $companias[$usuario['company']] ?? 'N/A',
            'CARGO'       => $getValue($usuario['position'] ?? ''),
            'FECHA'       => $fecha,
            'ACTIVO FIJO' => $getValue($equipo['activo_fijo'] ?? ''),
        ];

        // Definir ancho por columna (6 columnas por fila)
        $colWidth = $pdf->GetPageWidth() / 6 - 3.35;

        $i = 0;
        foreach ($datos_personales as $ref => $valor) {
            // Cabecera
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell($colWidth, 6, utf8_decode($ref), 1, 0, 'C', true);
            $i++;
            if ($i % 6 == 0) $pdf->Ln();
        }
        if ($i % 6 != 0) $pdf->Ln();

        // Valores
        $i = 0;
        $pdf->SetFont('Arial', '', 9);
        foreach ($datos_personales as $ref => $valor) {
            $pdf->Cell($colWidth, 8, utf8_decode($valor), 1, 0, 'C');
            $i++;
            if ($i % 6 == 0) $pdf->Ln();
        }
        if ($i % 6 != 0) $pdf->Ln();
        $pdf->Ln(3);

        // ================= DATOS DEL EQUIPO =================
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6.5, utf8_decode('DATOS DEL EQUIPO'), 1, 1, 'C', true);

        $datos_equipo = [
            'MARCA'         => $getValue($equipo['marca'] ?? ''),
            'MODELO'        => $getValue($equipo['modelo'] ?? ''),
            'SERIAL'        => $getValue($equipo['serial_number'] ?? ''),
            'PROCESADOR'    => $getValue($equipo['procesador'] ?? ''),
            'RAM'           => $getValue($equipo['ram'] ?? ''),
            'DISCO DURO'    => $getValue($equipo['disco_duro'] ?? ''),
        ];

        // Cabeceras
        $i = 0;
        foreach ($datos_equipo as $ref => $valor) {
            $pdf->SetFont('Arial', 'B', 9);
            $pdf->Cell($colWidth, 6, utf8_decode($ref), 1, 0, 'C', true);
            $i++;
            if ($i % 6 == 0) $pdf->Ln();
        }
        if ($i % 6 != 0) $pdf->Ln();

        // Valores
        $i = 0;
        $pdf->SetFont('Arial', '', 9);
        foreach ($datos_equipo as $ref => $valor) {
            $pdf->Cell($colWidth, 8, utf8_decode($valor), 1, 0, 'C');
            $i++;
            if ($i % 6 == 0) $pdf->Ln();
        }
        if ($i % 6 != 0) $pdf->Ln();
        $pdf->Ln(3);

        // ================= PERIFÉRICOS =================
	$pdf->SetFont('Arial', 'B', 11);
	$pdf->Cell(0, 6.5, utf8_decode('PERIFÉRICOS'), 1, 1, 'C', true);
	
	$perifericos = [
	    'PANTALLA','AUDÍFONOS','MOUSE','TECLADO','BASE',
	    'EXTENSIÓN USB','MOUSEPAD','ADAPTADORES','TELÉFONO IP','HUB'
	];
	
	// Número de columnas
	$cols = 6;
	
	// Ancho de columna: ya lo tienes definido antes en $colWidth
	$cellHeightHeader = 6;
	$cellHeightValue  = 8;
	$totalHeight      = $cellHeightHeader + $cellHeightValue;
	
	$i = 0;
	foreach ($perifericos as $p) {
	    // Guardar posición inicial
	    $x = $pdf->GetX();
	    $y = $pdf->GetY();
	
	    // Cabecera
	    $pdf->SetFont('Arial', 'B', 9);
	    $pdf->Cell($colWidth, $cellHeightHeader, utf8_decode($p), 1, 0, 'C', true);
	
	    // Valor (casilla en blanco justo debajo)
	    $pdf->SetXY($x, $y + $cellHeightHeader);
	    $pdf->SetFont('Arial', '', 9);
	    $pdf->Cell($colWidth, $cellHeightValue, '', 1, 0, 'C');
	
	    // Devolver cursor arriba, pero avanzando a la siguiente columna
	    $pdf->SetXY($x + $colWidth, $y);
	
	    $i++;
	    if ($i % $cols == 0) {
	        // saltar a la siguiente fila completa
	        $pdf->Ln($totalHeight);
	    }
	}
	if ($i % $cols != 0) {
	    $pdf->Ln($totalHeight);
	}
	$pdf->Ln(3);


        // ================= TARJETA DE ACCESO =================
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6.5, utf8_decode('TARJETA DE ACCESO'), 1, 1, 'C', true);
        $pdf->Cell(0, 5, '', 1, 1);
        $pdf->Ln(2);

        // ================= OBSERVACIONES =================
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6.5, utf8_decode('OBSERVACIONES'), 1, 1, 'C', true);
        $pdf->Cell(0, 20, '', 1, 1);
        $pdf->Ln(2);

        // ================= POLÍTICAS =================
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6.5, utf8_decode('POLÍTICAS DE USO Y SEGURIDAD'), 1, 1, 'C', true);

        $pdf->SetFont('Arial', '', 8.5);
        $politicas = [
            "El usuario es responsable del equipo y periféricos entregados, asegurando su correcto uso y conservación.",
            "Se permite el traslado de los equipos fuera de las instalaciones, siempre que se informe previamente al área de IT.",
            "Cualquier daño, pérdida o mal funcionamiento debe reportarse de inmediato al área de IT.",
            "El usuario debe garantizar que la información se maneje de acuerdo con las políticas de protección de datos.",
            "No se permite instalación de software no autorizado ni modificación de configuraciones sin aprobación de IT.",
            "La tarjeta de acceso solo podrá ser utilizada dentro del horario establecido (07:00 AM - 09:00 PM). En caso de pérdida, el usuario autoriza el descuento de $20.000 COP y notificará de inmediato.",
        ];
        foreach ($politicas as $linea) {
            $pdf->MultiCell(0, 4.5, utf8_decode("* ".$linea), 0, 'J');
        }
        $pdf->Ln(2);

        // ================= ENTREGA =================
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6.5, utf8_decode('ENTREGA DE ACTIVOS FIJOS'), 1, 1, 'C', true);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(105, 13, utf8_decode('FIRMA DEPT IT:'), 1, 0, 'L');
        $pdf->Cell(0, 13, utf8_decode('FIRMA EMPLEADO:'), 1, 1, 'L');
        $pdf->Ln(2);
        
        // ================= REVISIÓN DE EQUIPO =================
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6.7, utf8_decode('REVISIÓN DE EQUIPO'), 1, 1, 'C', true);
        $pdf->SetFont('Arial', '', 8.5);
        $pdf->MultiCell(
	    0, // ancho (0 = ocupa todo el ancho disponible)
	    3, // alto de línea
	    utf8_decode(
	        'El equipo entregado será revisado por el área de IT para verificar su estado y funcionamiento. En caso de presentar un desgaste o daño 
	        superior al porcentaje permitido 40%, el usuario asumirá el costo proporcional del activo entregado'
	    ),
	    1, // borde
	    'L' // alineación justificada
	);

        $pdf->Ln(2);

        // ================= DEVOLUCIÓN =================
        $pdf->SetFont('Arial', 'B', 11);
        $pdf->Cell(0, 6.5, utf8_decode('DEVOLUCIÓN DE ACTIVOS FIJOS'), 1, 1, 'C', true);
        $pdf->SetFont('Arial', '', 9);
        $pdf->Cell(105, 13, utf8_decode('FIRMA DEPT IT: '), 1, 0, 'L');
        $pdf->Cell(0, 13, utf8_decode('FIRMA EMPLEADO:'), 1, 1, 'L');
        $pdf->Ln(4);

        // ================= PIE =================
        $pdf->SetFont('Arial', 'I', 9);
        $pdf->Cell(0, 6, utf8_decode('GRUPO AZC S.A.S. - NIT. 900.680.284-6'), 0, 1, 'C');

        return $pdf->Output('S');
    } catch (Exception $e) {
        debug_log("Error en crearDocumentoPDF: ".$e->getMessage());
        return false;
    }
}


// Función para enviar notificación por correo cuando un ticket es actualizado
public function enviarNotificacionTicket($ticket_id, $estado_anterior, $nuevo_estado, $notas_admin = '') {
    try {
        debug_log("enviarNotificacionTicket llamado - ticket_id: $ticket_id, estado_anterior: $estado_anterior, nuevo_estado: $nuevo_estado");
        
        $database = new Database();
        $conn = $database->getConnection();
        
        // Obtener información del ticket, empleado Y responsable
        $query = "SELECT t.*, e.mail, 
                         CONCAT(resp.first_Name, ' ', resp.first_LastName) as responsable_nombre
                  FROM tickets t 
                  LEFT JOIN employee e ON t.empleado_id = e.CC 
                  LEFT JOIN employee resp ON t.responsable_id = resp.id 
                  WHERE t.id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $ticket_id);
        $stmt->execute();
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$ticket || empty($ticket['mail'])) {
            debug_log("No se encontró el ticket o el empleado no tiene email: " . ($ticket['mail'] ?? 'No tiene email'));
            return false;
        }
        
        // Configuración del correo - FORMATO ESPAÑOL
        $para = $ticket['mail'];
        $asunto = "Actualización de tu ticket #" . $ticket_id . " - " . SITE_NAME;
        
        // Formatear nombre del empleado correctamente (manejar caracteres en español)
        $nombre_empleado = htmlspecialchars($ticket['empleado_nombre'], ENT_QUOTES, 'UTF-8');
        
        // Obtener información del administrador que realiza el cambio
        $admin_id = $_SESSION['admin_id'] ?? null;
        $admin_nombre = 'Soporte'; // Valor por defecto
        
        if ($admin_id) {
            $admin_info = $this->obtenerInfoAdministrador($admin_id);
            if ($admin_info) {
                $admin_nombre = htmlspecialchars($admin_info['nombre'], ENT_QUOTES, 'UTF-8');
            }
        }
        
        // Obtener las notas del empleado o el asunto del correo según el tipo de ticket
        $info_adicional = '';
        $etiqueta_info = 'Notas del empleado';
        
        if ($ticket['tipo'] == 'email') {
            // Para tickets de correo, mostrar el asunto en lugar del contenido
            $info_adicional = !empty($ticket['asunto']) ? htmlspecialchars($ticket['asunto'], ENT_QUOTES, 'UTF-8') : '';
            $etiqueta_info = 'Asunto del correo';
        } else {
            // Para otros tipos de ticket, mostrar las notas del empleado
            $info_adicional = !empty($ticket['notas']) ? nl2br(htmlspecialchars($ticket['notas'], ENT_QUOTES, 'UTF-8')) : '';
        }
        
        // Mensaje personalizado según el estado - EN ESPAÑOL CON TILDES Y Ñ
        switch ($nuevo_estado) {
            case 'aprobado':
                $mensaje = "Hola $nombre_empleado,<br><br>";
                $mensaje .= "Gracias por contactarnos. Hemos recibido tu solicitud y estamos trabajando en ella. ";
                $mensaje .= "Un miembro del equipo de Soporte Técnico te responderá en breve para ayudarte con este tema.<br><br>";
                
                // Agregar la información adicional (asunto del correo o notas del empleado)
                if (!empty($info_adicional)) {
                    $mensaje .= "<strong>$etiqueta_info:</strong><br>$info_adicional<br><br>";
                }
                break;
                
            case 'rechazado':
                $razon_rechazo = !empty($notas_admin) ? nl2br(htmlspecialchars($notas_admin, ENT_QUOTES, 'UTF-8')) : 'No se especificó una razón';
                $mensaje = "Hola $nombre_empleado,<br><br>";
                $mensaje .= "Lamentamos informarte que tu ticket #$ticket_id ha sido rechazado.<br><br>";
                $mensaje .= "<strong>Razón del rechazo:</strong><br>$razon_rechazo<br><br>";
                
                // Agregar la información adicional (asunto del correo o notas del empleado)
                if (!empty($info_adicional)) {
                    $mensaje .= "<strong>$etiqueta_info:</strong><br>$info_adicional<br><br>";
                }
                
                $mensaje .= "Si necesitas aclaraciones adicionales, no dudes en contactarnos.<br><br>";
                break;
                
            case 'completado':
                $responsable = !empty($ticket['responsable_nombre']) ? $ticket['responsable_nombre'] : 'Soporte';
                $mensaje = "Hola $nombre_empleado,<br><br>";
                $mensaje .= "Queremos informarte que tu ticket ha sido cerrado, ya que la solicitud fue atendida ";
                $mensaje .= "y no se han recibido nuevas respuestas.<br><br>";
                
                // Agregar la información adicional (asunto del correo o notas del empleado)
                if (!empty($info_adicional)) {
                    $mensaje .= "<strong>$etiqueta_info:</strong><br>$info_adicional<br><br>";
                }
                
                if (!empty($notas_admin)) {
                    $mensaje .= "<strong>Solución:</strong><br>$notas_admin<br><br>";
                }
                
                $mensaje .= "Este ticket fue cerrado por: <strong>$responsable</strong><br><br>";
                
                // ========== BLOQUE DE CALIFICACIÓN ==========
                $base_url = rtrim(SITE_URL, '/');
                $enlace_calificacion = $base_url . "/admin/calificar_ticket.php?ticket_id=" . $ticket_id;
                
                $mensaje .= "<strong>¿Cómo calificarías el servicio recibido?</strong><br><br>";
                
                // Enlaces de calificación (1-5 estrellas)
                for ($i = 1; $i <= 5; $i++) {
                    $enlace = $enlace_calificacion . "&calificacion=" . $i;
                    $estrellas = str_repeat('★', $i) . str_repeat('☆', 5 - $i);
                    $mensaje .= "<a href='$enlace' style='margin: 5px; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 5px; text-decoration: none; color: #333; display: inline-block;'>";
                    $mensaje .= "$estrellas ($i estrella" . ($i > 1 ? "s" : "") . ")";
                    $mensaje .= "</a><br>";
                }
                
                $mensaje .= "<br><small><em>Al hacer clic en una calificación, se abrirá una ventana que se cerrará automáticamente.</em></small><br><br>";
                // ========== FIN BLOQUE DE CALIFICACIÓN ==========
                
                $mensaje .= "Si el problema persiste o necesitas asistencia adicional, no dudes en crear un nuevo ticket.<br><br>";
                $mensaje .= "Gracias por confiar en el equipo de Soporte Técnico de AZC Legal.";
                break;
                
            default:
                $mensaje = "Hola $nombre_empleado,<br><br>";
                $mensaje .= "Tu ticket #$ticket_id ha sido actualizado.<br><br>";
                $mensaje .= "<strong>Estado anterior:</strong> " . ucfirst($estado_anterior) . "<br>";
                $mensaje .= "<strong>Nuevo estado:</strong> " . ucfirst($nuevo_estado) . "<br><br>";
                
                // Agregar la información adicional (asunto del correo o notas del empleado)
                if (!empty($info_adicional)) {
                    $mensaje .= "<strong>$etiqueta_info:</strong><br>$info_adicional<br><br>";
                }
                
                if (!empty($notas_admin)) {
                    $mensaje .= "<strong>Notas del administrador:</strong><br>" . nl2br(htmlspecialchars($notas_admin, ENT_QUOTES, 'UTF-8')) . "<br><br>";
                }
                
                $mensaje .= "Puedes ver el estado de tu ticket en cualquier momento en el sistema.<br><br>";
        }
        
        // AGREGAR LA FIRMA AL FINAL DEL MENSAJE
        $mensaje .= $this->obtenerFirmaCorreo();
        
        // Configurar cabeceras para UTF-8 (caracteres en español)
        $cabeceras = 'From: ' . SUPPORT_EMAIL . "\r\n" .
                     'Reply-To: ' . SUPPORT_EMAIL . "\r\n" .
                     'Content-Type: text/html; charset=UTF-8' . "\r\n" .
                     'X-Mailer: PHP/' . phpversion();
        
        // Intentar enviar con PHPMailer primero, luego fallback a mail()
        $resultado = $this->enviarCorreoPHPMailer($para, $asunto, $mensaje);
        
        if (!$resultado) {
            // Fallback a la función mail() tradicional con encoding UTF-8
            $mensaje_texto = strip_tags($mensaje); // Versión en texto plano
            $asunto_utf8 = '=?UTF-8?B?' . base64_encode($asunto) . '?='; // Asunto en UTF-8
            
            $resultado = mail($para, $asunto_utf8, $mensaje, $cabeceras);
            
            if ($resultado) {
                debug_log("Correo enviado exitosamente (fallback mail()) a: " . $para);
            } else {
                debug_log("Error al enviar correo (fallback mail()) a: " . $para);
                
                // Último intento: solo texto plano con encoding UTF-8
                $cabeceras_texto = 'From: ' . SUPPORT_EMAIL . "\r\n" .
                                  'Reply-To: ' . SUPPORT_EMAIL . "\r\n" .
                                  'Content-Type: text/plain; charset=UTF-8' . "\r\n" .
                                  'X-Mailer: PHP/' . phpversion();
                
                $resultado = mail($para, $asunto_utf8, $mensaje_texto, $cabeceras_texto);
                
                if ($resultado) {
                    debug_log("Correo enviado exitosamente (texto plano) a: " . $para);
                }
            }
        }
        
        return $resultado;
        
    } catch (Exception $e) {
        debug_log("Error en enviarNotificacionTicket: " . $e->getMessage());
        return false;
    }
}

// Función para enviar correos usando PHPMailer
public function enviarCorreoPHPMailer($para, $asunto, $mensaje) {
    try {
        // Verificar si PHPMailer está disponible
        if (!file_exists(__DIR__ . '/PHPMailer/PHPMailer.php')) {
            debug_log("PHPMailer no encontrado, usando fallback");
            return false;
        }
        
        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configuración del servidor SMTP
        $mail->isSMTP();
        $mail->Host = 'mail.supremecluster.com'; // Tu servidor SMTP
        $mail->SMTPAuth = true;
        $mail->Username = 'techsupport@azclegal.com'; // Tu usuario SMTP
        $mail->Password = 'z8912618Z@!$'; // Tu password SMTP
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 2525;
        
        $mail->CharSet = 'UTF-8';
        
        // Remitente
        $mail->setFrom(SUPPORT_EMAIL, SITE_NAME);
        $mail->addReplyTo(SUPPORT_EMAIL, SITE_NAME);
        
        // Destinatario
        $mail->addAddress($para);
        
        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        

        $ruta_imagen = __DIR__ . '/../assets/images/logo_main.png'; // Ajusta la ruta según tu estructura
        if (file_exists($ruta_imagen)) {
            $mail->addEmbeddedImage($ruta_imagen, 'firma_azc', 'firma-azc.png');
        }
        
        $mail->Body = $mensaje;
        
        // Versión alternativa en texto plano
        $mail->AltBody = strip_tags($mensaje);
        
        $mail->send();
        debug_log("Correo enviado exitosamente (PHPMailer) a: " . $para);
        return true;
        
    } catch (Exception $e) {
        debug_log("Error enviando correo con PHPMailer: " . $e->getMessage());
        return false;
    }
}

// Función auxiliar para marcar tickets de onboarding
private function marcarTicketOnboarding($ticket_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "UPDATE tickets SET 
                  prioridad = 'alta',
                  categoria = 'onboarding',
                  notas = CONCAT(notas, '\n\n---\n🎯 TICKET DE ONBOARDING AUTOMÁTICO\nSe requiere preparar equipos y accesos para nuevo colaborador.')
                  WHERE id = :ticket_id";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->execute();
        
        debug_log("Ticket #$ticket_id marcado como onboarding");
        return true;
        
    } catch (Exception $e) {
        debug_log("Error en marcarTicketOnboarding: " . $e->getMessage());
        return false;
    }
}

// Función auxiliar para buscar empleado por email - MEJORADA
private function buscarEmpleadoPorEmail($email) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT 
                    CC,
                    CONCAT(first_Name, ' ', 
                           IFNULL(second_Name, ''), ' ', 
                           first_LastName, ' ', 
                           IFNULL(second_LastName, '')) AS nombre,
                    position
                  FROM employee 
                  WHERE mail = :email OR personal_mail = :email 
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        $empleado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Si no encuentra empleado, retornar null en lugar de false
        return $empleado ?: null;
        
    } catch (Exception $e) {
        debug_log("Error en buscarEmpleadoPorEmail: " . $e->getMessage());
        return null;
    }
}

// Función principal mejorada para procesar correos entrantes
public function procesarCorreosEntrantes() {
    try {
        debug_log("procesarCorreosEntrantes llamado - CON PROCESAMIENTO DE ADJUNTOS");
        
        // Configuración para conexión IMAP
        $hostname = '{mail.supremecluster.com:993/imap/ssl/novalidate-cert}INBOX';
        $username = 'soporte@azclegal.com';
        $password = 'z8912618Z@!$';
        
        // Conectar al servidor de correo
        $inbox = imap_open($hostname, $username, $password);
        
        if (!$inbox) {
            debug_log("No se pudo conectar al servidor de correo: " . imap_last_error());
            return ['success' => false, 'error' => 'No se pudo conectar al servidor de correo: ' . imap_last_error()];
        }
        
        // Obtener correos no leídos
        $emails = imap_search($inbox, 'UNSEEN');
        $procesados = 0;
        
        if ($emails) {
            rsort($emails);
            
            foreach ($emails as $email_number) {
                $overview = imap_fetch_overview($inbox, $email_number, 0);
                $message = imap_body($inbox, $email_number);
                $structure = imap_fetchstructure($inbox, $email_number);
                
                $subject = $this->decodeMIMEString($overview[0]->subject);
                $from = $overview[0]->from;
                $date = $overview[0]->date;
                
                debug_log("Correo procesado - Asunto: " . $subject);
                
                // Extraer dirección de correo del remitente
                preg_match('/<(.+?)>/', $from, $matches);
                $from_email = $matches[1] ?? $from;
                
                // Procesar adjuntos y extraer imágenes
                $imagen_ruta = $this->procesarAdjuntosCorreo($inbox, $email_number, $structure);
                
                // Verificar si es un correo de OnBoarding
                $es_onboarding = (strpos($subject, 'TICKET AUTOMÁTICO - ONBOARDING SOLICITADO') !== false || 
                                  strpos($from_email, 'techsupport@azclegal.com') !== false);
                
                if ($es_onboarding) {
                    // Extraer nombre del candidato del contenido del correo
                    $nombre_candidato = $this->extraerNombreCandidato($message);
                    
                    // Limpiar contenido del correo
                    $contenido_limpio = $this->limpiarContenidoOnboarding($message);
                    
                    // Crear ticket de OnBoarding
                    $ticket_id = $this->crearTicketOnboarding(
                        $subject,
                        $contenido_limpio,
                        $nombre_candidato,
                        $from_email,
                        $imagen_ruta
                    );
                    
                    if ($ticket_id) {
                        $procesados++;
                        debug_log("Ticket de OnBoarding creado: #" . $ticket_id . 
                                 ($imagen_ruta ? " CON IMAGEN" : " sin imagen"));
                    }
                } else {
                    // Buscar empleado por correo
                    $empleado = $this->buscarEmpleadoPorEmail($from_email);
                    
                    // CREAR TICKET SIEMPRE, incluso si no es empleado registrado
                    $empleado_id = $empleado ? $empleado['CC'] : null;
                    $empleado_nombre = $empleado ? $empleado['nombre'] : $from_email;
                    $empleado_departamento = $empleado ? $empleado['position'] : 'Externo';
                    
                    $ticket_id = $this->crearTicketDesdeCorreo(
                        $empleado_id,
                        $empleado_nombre,
                        $empleado_departamento,
                        $subject,
                        $message,
                        $from_email,
                        $imagen_ruta  // Nueva parámetro para la imagen
                    );
                    
                    if ($ticket_id) {
                        $procesados++;
                        debug_log("Ticket creado desde correo: #" . $ticket_id . 
                                 ($empleado ? " (Empleado)" : " (Externo)") .
                                 ($imagen_ruta ? " CON IMAGEN" : " sin imagen"));
                    }
                }
                
                // Marcar como leído
                imap_setflag_full($inbox, $email_number, "\\Seen");
            }
        }
        
        imap_close($inbox);
        
        debug_log("Procesamiento de correos completado. Tickets creados: " . $procesados);
        return ['success' => true, 'procesados' => $procesados];
        
    } catch (Exception $e) {
        debug_log("Error en procesarCorreosEntrantes: " . $e->getMessage());
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Función para extraer el nombre del candidato del contenido del correo
private function extraerNombreCandidato($contenido) {
    // Buscar el patrón "Nombre completo: [nombre]" en el contenido
    if (preg_match('/Nombre completo:\s*(.+?)(?:\n|$)/', $contenido, $matches)) {
        return trim($matches[1]);
    }
    
    // Si no se encuentra, devolver un valor por defecto
    return 'Candidato';
}

// Función para limpiar el contenido de los correos de OnBoarding
private function limpiarContenidoOnboarding($contenido) {
    // Eliminar los caracteres raros al principio (boundary del correo)
    $contenido = preg_replace('/^--.+?\n\n/s', '', $contenido);
    
    // Eliminar duplicados
    $lineas = explode("\n", $contenido);
    $lineas_unicas = array();
    $lineas_vistas = array();
    
    foreach ($lineas as $linea) {
        $linea_limpia = trim($linea);
        if (!empty($linea_limpia) && !in_array($linea_limpia, $lineas_vistas)) {
            $lineas_unicas[] = $linea;
            $lineas_vistas[] = $linea_limpia;
        }
    }
    
    return implode("\n", $lineas_unicas);
}

// Función para crear un ticket de OnBoarding
private function crearTicketOnboarding($asunto, $contenido, $nombre_candidato, $from_email, $imagen_ruta = null) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        // Limpiar y preparar los datos
        $asunto = substr(trim($asunto), 0, 255);
        $contenido = substr($contenido, 0, 2000);
        
        $query = "INSERT INTO tickets (empleado_id, empleado_nombre, empleado_departamento, tipo, asunto, notas, estado, imagen_ruta, creado_en) 
                  VALUES (:empleado_id, :empleado_nombre, :empleado_departamento, :tipo, :asunto, :notas, 'pendiente', :imagen_ruta, NOW())";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':empleado_id', $from_email);
        $stmt->bindParam(':empleado_nombre', $nombre_candidato);
        $stmt->bindParam(':empleado_departamento', $empleado_departamento);
        $stmt->bindValue(':tipo', 'onboarding');
        $stmt->bindParam(':asunto', $asunto);
        $stmt->bindParam(':notas', $contenido);
        $stmt->bindParam(':imagen_ruta', $imagen_ruta);
        
        if ($stmt->execute()) {
            $ticket_id = $conn->lastInsertId();
            
            // Registrar en historial
            $this->registrarHistorial(
                $ticket_id, 
                $from_email, 
                null, 
                'ticket_onboarding_creado', 
                1, 
                "Ticket de OnBoarding creado para: " . $nombre_candidato . 
                ($imagen_ruta ? ' CON IMAGEN ADJUNTA' : '')
            );
            
            debug_log("Ticket de OnBoarding creado: #$ticket_id - Candidato: $nombre_candidato" . 
                     ($imagen_ruta ? " - Con imagen: $imagen_ruta" : ""));
            
            return $ticket_id;
        }
        
        return false;
        
    } catch (Exception $e) {
        debug_log("Error en crearTicketOnboarding: " . $e->getMessage());
        return false;
    }
}

// Función para procesar adjuntos de correo y extraer imágenes
private function procesarAdjuntosCorreo($inbox, $email_number, $structure) {
    try {
        debug_log("Procesando adjuntos del correo #$email_number");
        
        $imagen_ruta = null;
        
        // Verificar si el correo tiene adjuntos
        if (isset($structure->parts) && count($structure->parts)) {
            for ($i = 0; $i < count($structure->parts); $i++) {
                $attachments = $this->procesarParteAdjunto($inbox, $email_number, $structure, $i);
                
                foreach ($attachments as $attachment) {
                    // Verificar si es una imagen
                    if ($this->esImagenValida($attachment['mime_type'])) {
                        debug_log("Imagen adjunta encontrada: " . $attachment['name']);
                        
                        // Guardar la imagen
                        $imagen_ruta = $this->guardarImagenDesdeAdjunto($attachment);
                        
                        if ($imagen_ruta) {
                            debug_log("Imagen guardada exitosamente: " . $imagen_ruta);
                            // Solo procesamos la primera imagen encontrada
                            break 2;
                        }
                    }
                }
            }
        }
        
        return $imagen_ruta;
        
    } catch (Exception $e) {
        debug_log("Error en procesarAdjuntosCorreo: " . $e->getMessage());
        return null;
    }
}

// Función para procesar una parte adjunta del correo
private function procesarParteAdjunto($inbox, $email_number, $structure, $part_number) {
    $attachments = array();
    
    $part = $structure->parts[$part_number];
    
    // Si esta parte tiene más partes, procesar recursivamente
    if (isset($part->parts)) {
        for ($j = 0; $j < count($part->parts); $j++) {
            $sub_attachments = $this->procesarParteAdjunto($inbox, $email_number, $part, $j);
            $attachments = array_merge($attachments, $sub_attachments);
        }
    } else {
        // Es una parte final (hoja)
        if ($part->ifdparameters) {
            $attachments[] = $this->obtenerDatosAdjunto($inbox, $email_number, $part, $part_number);
        }
    }
    
    return $attachments;
}

// Función para obtener datos del adjunto
private function obtenerDatosAdjunto($inbox, $email_number, $part, $part_number) {
    $attachment = array();
    
    // Obtener nombre del archivo
    if ($part->ifdparameters && $part->dparameters) {
        foreach ($part->dparameters as $param) {
            if (strtolower($param->attribute) == 'filename') {
                $attachment['name'] = $param->value;
                break;
            }
        }
    }
    
    // Si no se encontró por dparameters, buscar en parameters
    if (!isset($attachment['name']) && $part->ifparameters && $part->parameters) {
        foreach ($part->parameters as $param) {
            if (strtolower($param->attribute) == 'name') {
                $attachment['name'] = $param->value;
                break;
            }
        }
    }
    
    // Si aún no tiene nombre, generar uno
    if (!isset($attachment['name'])) {
        $attachment['name'] = 'adjunto_' . $part_number . '.dat';
    }
    
    // Decodificar el nombre si está codificado
    $attachment['name'] = $this->decodeMIMEString($attachment['name']);
    
    // Obtener tipo MIME
    if ($part->type == 0) {
        $attachment['mime_type'] = $part->subtype ? "text/{$part->subtype}" : "text/plain";
    } else if ($part->type == 1) {
        $attachment['mime_type'] = "text/{$part->subtype}";
    } else if ($part->type == 2) {
        $attachment['mime_type'] = "message/{$part->subtype}";
    } else if ($part->type == 3) {
        $attachment['mime_type'] = "application/{$part->subtype}";
    } else if ($part->type == 4) {
        $attachment['mime_type'] = "multipart/{$part->subtype}";
    } else if ($part->type == 5) {
        $attachment['mime_type'] = $part->subtype ? "{$part->subtype}" : "application/octet-stream";
    } else {
        $attachment['mime_type'] = "application/octet-stream";
    }
    
    // Especialmente para imágenes
    if ($part->type == 5) {
        switch(strtolower($part->subtype)) {
            case 'jpeg':
            case 'jpg':
                $attachment['mime_type'] = 'image/jpeg';
                break;
            case 'png':
                $attachment['mime_type'] = 'image/png';
                break;
            case 'gif':
                $attachment['mime_type'] = 'image/gif';
                break;
            case 'bmp':
                $attachment['mime_type'] = 'image/bmp';
                break;
        }
    }
    
    // Obtener datos del adjunto
    $data = imap_fetchbody($inbox, $email_number, $part_number + 1);
    
    // Decodificar según la codificación
    if ($part->encoding == 3) { // BASE64
        $attachment['data'] = base64_decode($data);
    } else if ($part->encoding == 4) { // QUOTED-PRINTABLE
        $attachment['data'] = quoted_printable_decode($data);
    } else { // 7BIT, 8BIT, BINARY
        $attachment['data'] = $data;
    }
    
    return $attachment;
}

// Función para verificar si es una imagen válida
private function esImagenValida($mime_type) {
    $tipos_imagen_validos = [
        'image/jpeg',
        'image/jpg', 
        'image/png',
        'image/gif',
        'image/bmp',
        'image/webp'
    ];
    
    return in_array(strtolower($mime_type), $tipos_imagen_validos);
}

// Función para guardar imagen desde adjunto
private function guardarImagenDesdeAdjunto($attachment) {
    try {
        debug_log("guardarImagenDesdeAdjunto llamado para: " . $attachment['name']);
        
        // Validar que sea una imagen
        if (!$this->esImagenValida($attachment['mime_type'])) {
            throw new Exception('El archivo adjunto no es una imagen válida');
        }
        
        // Validar tamaño (máximo 5MB)
        if (strlen($attachment['data']) > 5 * 1024 * 1024) {
            throw new Exception('La imagen adjunta supera los 5MB');
        }
        
        // Crear estructura de directorios por mes y año (igual que en guardarImagenTicket)
        $anio_actual = date('Y');
        $mes_actual = date('m');
        $base_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/tickets/';
        $directorio_mes = $base_dir . $anio_actual . '/' . $mes_actual . '/';
        
        // Crear directorios si no existen
        if (!is_dir($directorio_mes)) {
            if (!mkdir($directorio_mes, 0755, true)) {
                throw new Exception('No se pudo crear el directorio para las imágenes');
            }
        }
        
        // Generar nombre único para el archivo
        $extension = $this->obtenerExtensionDesdeMime($attachment['mime_type']);
        $nombre_archivo = 'correo_' . time() . '_' . uniqid() . '.' . $extension;
        $ruta_completa = $directorio_mes . $nombre_archivo;
        
        // Guardar archivo
        if (file_put_contents($ruta_completa, $attachment['data']) === false) {
            throw new Exception('Error al guardar la imagen adjunta');
        }
        
        // Verificar que el archivo se creó correctamente
        if (!file_exists($ruta_completa)) {
            throw new Exception('La imagen adjunta no se pudo guardar en el sistema de archivos');
        }
        
        // Redimensionar imagen si es muy grande
        $this->redimensionarImagenTicket($ruta_completa, 1200, 1200);
        
        // Retornar ruta relativa para guardar en BD
        $ruta_relativa = '/uploads/tickets/' . $anio_actual . '/' . $mes_actual . '/' . $nombre_archivo;
        
        debug_log("Imagen desde correo guardada exitosamente: " . $ruta_relativa);
        return $ruta_relativa;
        
    } catch (Exception $e) {
        debug_log("Error en guardarImagenDesdeAdjunto: " . $e->getMessage());
        return null;
    }
}

// Función auxiliar para obtener extensión desde tipo MIME
private function obtenerExtensionDesdeMime($mime_type) {
    $mime_to_extension = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/bmp' => 'bmp',
        'image/webp' => 'webp'
    ];
    
    return $mime_to_extension[strtolower($mime_type)] ?? 'jpg';
}

// Función mejorada para crear ticket desde correo (con imagen)
private function crearTicketDesdeCorreo($empleado_id, $empleado_nombre, $empleado_departamento, $asunto, $descripcion, $from_email = null, $imagen_ruta = null) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        // Limpiar y preparar los datos
        $asunto = substr(trim($asunto), 0, 255);
        
        // Extraer texto plano del correo
        $descripcion_limpia = $this->extraerTextoPlano($descripcion);
        $descripcion = substr($descripcion_limpia, 0, 2000);
        
        $tipo = 'email';
        
        // Si no hay empleado_id (correo de no registrado), crear como "externo"
        if (!$empleado_id && $from_email) {
            $empleado_id = 'EXTERNO-' . substr(md5($from_email), 0, 8);
            $empleado_nombre = $from_email;
            $empleado_departamento = 'Externo';
            $tipo = 'externo';
        }
        
        $query = "INSERT INTO tickets (empleado_id, empleado_nombre, empleado_departamento, tipo, asunto, notas, estado, imagen_ruta, creado_en) 
                  VALUES (:empleado_id, :empleado_nombre, :empleado_departamento, :tipo, :asunto, :notas, 'pendiente', :imagen_ruta, NOW())";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':empleado_id', $empleado_id);
        $stmt->bindParam(':empleado_nombre', $empleado_nombre);
        $stmt->bindParam(':empleado_departamento', $empleado_departamento);
        $stmt->bindParam(':tipo', $tipo);
        $stmt->bindParam(':asunto', $asunto);
        $stmt->bindParam(':notas', $descripcion);
        $stmt->bindParam(':imagen_ruta', $imagen_ruta);
        
        if ($stmt->execute()) {
            $ticket_id = $conn->lastInsertId();
            
            // Registrar en historial
            $this->registrarHistorial(
                $ticket_id, 
                $empleado_id, 
                null, 
                'ticket_creado_desde_correo', 
                1, 
                "Ticket creado automáticamente desde correo: " . $asunto . 
                ($tipo == 'externo' ? ' (Remitente externo)' : '') .
                ($imagen_ruta ? ' CON IMAGEN ADJUNTA' : '')
            );
            
            debug_log("Ticket de correo creado: #$ticket_id - Asunto: $asunto - Tipo: $tipo" . 
                     ($imagen_ruta ? " - Con imagen: $imagen_ruta" : ""));
            
            // Si es un correo relacionado con onboarding, asignar prioridad
            if (stripos($asunto, 'onboarding') !== false || stripos($descripcion, 'onboarding') !== false) {
                $this->marcarTicketOnboarding($ticket_id);
            }
            
            return $ticket_id;
        }
        
        return false;
        
    } catch (Exception $e) {
        debug_log("Error en crearTicketDesdeCorreo: " . $e->getMessage());
        return false;
    }
}

// Función para obtener información del administrador
private function obtenerInfoAdministrador($admin_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT 
                    id,
                    CONCAT(first_Name, ' ', 
                           IFNULL(second_Name, ''), ' ', 
                           first_LastName, ' ', 
                           IFNULL(second_LastName, '')) AS nombre,
                    role
                  FROM employee 
                  WHERE id = :admin_id 
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':admin_id', $admin_id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        debug_log("Error en obtenerInfoAdministrador: " . $e->getMessage());
        return false;
    }
}


// Función para extraer texto plano de correos MIME
private function extraerTextoPlano($contenido) {
    try {
        debug_log("Extrayendo texto plano de correo - Longitud: " . strlen($contenido));
        
        $contenido_original = $contenido;
        
        // Si no es un correo MIME, devolver el contenido tal cual
        if (strpos($contenido, 'Content-Type:') === false) {
            debug_log("No es correo MIME, devolviendo contenido original");
            return $this->eliminarFirma($contenido);
        }
        
        // Buscar la parte de texto plano
        $partes = $this->extraerPartesMIME($contenido);
        
        $texto_final = '';
        
        if (!empty($partes['texto_plano'])) {
            debug_log("Texto plano encontrado en MIME");
            $texto_final = $partes['texto_plano'];
        } elseif (!empty($partes['texto_html'])) {
            debug_log("Usando texto HTML convertido a plano");
            $texto_final = $this->convertirHtmlAPlano($partes['texto_html']);
        } else {
            debug_log("No se encontraron partes útiles, haciendo limpieza básica");
            $texto_final = $this->limpiezaBasica($contenido_original);
        }
        
        // Eliminar firma del texto final
        return $this->eliminarFirma($texto_final);
        
    } catch (Exception $e) {
        debug_log("Error en extraerTextoPlano: " . $e->getMessage());
        return $this->eliminarFirma($contenido_original);
    }
}

// Función para extraer partes MIME
private function extraerPartesMIME($contenido) {
    $partes = [
        'texto_plano' => '',
        'texto_html' => ''
    ];
    
    // Dividir por boundaries
    $boundary = $this->obtenerBoundary($contenido);
    
    if ($boundary) {
        $secciones = explode('--' . $boundary, $contenido);
        
        foreach ($secciones as $seccion) {
            if (strpos($seccion, 'Content-Type: text/plain') !== false) {
                $partes['texto_plano'] = $this->extraerCuerpoSeccion($seccion);
            } elseif (strpos($seccion, 'Content-Type: text/html') !== false) {
                $partes['texto_html'] = $this->extraerCuerpoSeccion($seccion);
            }
        }
    } else {
        // Si no hay boundary, buscar directamente las partes
        if (strpos($contenido, 'Content-Type: text/plain') !== false) {
            $partes['texto_plano'] = $this->extraerCuerpoDirecto($contenido, 'text/plain');
        } elseif (strpos($contenido, 'Content-Type: text/html') !== false) {
            $partes['texto_html'] = $this->extraerCuerpoDirecto($contenido, 'text/html');
        }
    }
    
    return $partes;
}

// Obtener boundary del contenido MIME
private function obtenerBoundary($contenido) {
    if (preg_match('/boundary="([^"]+)"/', $contenido, $matches)) {
        return $matches[1];
    }
    
    if (preg_match('/boundary=([^\s]+)/', $contenido, $matches)) {
        return $matches[1];
    }
    
    return null;
}

// Extraer cuerpo de una sección MIME
private function extraerCuerpoSeccion($seccion) {
    // Encontrar el fin de los headers
    $pos = strpos($seccion, "\n\n");
    if ($pos === false) {
        $pos = strpos($seccion, "\r\n\r\n");
    }
    
    if ($pos !== false) {
        $cuerpo = substr($seccion, $pos + 2);
        
        // Obtener charset del header si está disponible
        $charset = 'UTF-8'; // default
        if (preg_match('/charset="?([^"]+)"?/i', $seccion, $matches)) {
            $charset = strtoupper(trim($matches[1]));
        }
        
        // Decodificar si es quoted-printable
        if (strpos($seccion, 'Content-Transfer-Encoding: quoted-printable') !== false) {
            $cuerpo = quoted_printable_decode($cuerpo);
        }
        
        // Decodificar si es base64
        if (strpos($seccion, 'Content-Transfer-Encoding: base64') !== false) {
            $cuerpo = base64_decode($cuerpo);
        }
        
        // Convertir a UTF-8 si es necesario
        if ($charset != 'UTF-8' && $charset != 'UTF8') {
            $cuerpo = mb_convert_encoding($cuerpo, 'UTF-8', $charset);
        }
        
        return trim($cuerpo);
    }
    
    return trim($seccion);
}


// Extraer cuerpo directamente sin boundary
private function extraerCuerpoDirecto($contenido, $contentType) {
    $patron = '/Content-Type: ' . preg_quote($contentType, '/') . '.*?Content-Transfer-Encoding: ([^\s]+).*?\n\n(.*?)(?=Content-Type:|$)/s';
    
    if (preg_match($patron, $contenido, $matches)) {
        $encoding = $matches[1];
        $cuerpo = $matches[2];
        
        if ($encoding === 'quoted-printable') {
            $cuerpo = quoted_printable_decode($cuerpo);
        } elseif ($encoding === 'base64') {
            $cuerpo = base64_decode($cuerpo);
        }
        
        return trim($cuerpo);
    }
    
    return '';
}

// Convertir HTML a texto plano (mejorado para caracteres especiales)
private function convertirHtmlAPlano($html) {
    // Primero, detectar y convertir encoding si es necesario
    $encoding = mb_detect_encoding($html, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding != 'UTF-8') {
        $html = mb_convert_encoding($html, 'UTF-8', $encoding);
    }
    
    // Reemplazar tags básicos
    $texto = str_replace(['<br>', '<br/>', '<br />', '<BR>', '<BR/>', '<BR />'], "\n", $html);
    $texto = str_replace(['</p>', '</P>'], "\n\n", $texto);
    $texto = str_replace(['</div>', '</DIV>'], "\n", $texto);
    
    // Remover todos los tags HTML preservando el contenido
    $texto = strip_tags($texto);
    
    // Decodificar entidades HTML (importante para tildes y caracteres especiales)
    $texto = html_entity_decode($texto, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Reemplazar múltiples espacios y saltos de línea
    $texto = preg_replace('/[ \t]{2,}/', ' ', $texto);
    $texto = preg_replace('/\n{3,}/', "\n\n", $texto);
    
    // Limpiar caracteres especiales problemáticos
    $texto = str_replace(["\r\n", "\r"], "\n", $texto);
    
    return trim($texto);
}

// Limpieza básica para contenido no MIME (mejorada)
private function limpiezaBasica($contenido) {
    // Primero, detectar encoding
    $encoding = mb_detect_encoding($contenido, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding != 'UTF-8') {
        $contenido = mb_convert_encoding($contenido, 'UTF-8', $encoding);
    }
    
    // Reemplazar caracteres encoded comunes
    $contenido = str_replace(
        ['=20', '=0D=0A', '=3D', '=09', '=A0', '=C2=A0'], 
        [' ', "\n", '=', "\t", ' ', ' '], 
        $contenido
    );
    
    // Decodificar quoted-printable si se detecta
    if (strpos($contenido, '=') !== false && preg_match('/=[0-9A-F]{2}/i', $contenido)) {
        $contenido = quoted_printable_decode($contenido);
    }
    
    // Remover líneas de headers MIME si existen
    $lineas = explode("\n", $contenido);
    $resultado = [];
    $en_cuerpo = false;
    
    foreach ($lineas as $linea) {
        $linea_trim = trim($linea);
        
        if (!$en_cuerpo && $linea_trim === '') {
            $en_cuerpo = true;
            continue;
        }
        
        if ($en_cuerpo) {
            // Saltar líneas que parecen headers MIME
            if (preg_match('/^(Content-|MIME-|Message-|Subject:|From:|To:|Date:)/i', $linea_trim)) {
                continue;
            }
            
            $resultado[] = $linea;
        }
    }
    
    if (!empty($resultado)) {
        $contenido = implode("\n", $resultado);
    }
    
    // Asegurar encoding UTF-8 final
    if (!mb_check_encoding($contenido, 'UTF-8')) {
        $contenido = mb_convert_encoding($contenido, 'UTF-8');
    }
    
    return trim($contenido);
}


// Función para decodificar strings MIME (para subject y otros headers)
private function decodeMIMEString($string) {
    // Si el string está en formato MIME encoded (ej: =?UTF-8?B?...?= o =?ISO-8859-1?Q?...?=)
    if (preg_match('/=\?([^\?]+)\?([BQ])\?([^\?]+)\?=/i', $string)) {
        // Decodificar usando imap_mime_header_decode
        $decoded = imap_mime_header_decode($string);
        $result = '';
        foreach ($decoded as $part) {
            // Convertir a UTF-8 si es necesario
            if ($part->charset != 'UTF-8' && $part->charset != 'default') {
                $result .= mb_convert_encoding($part->text, 'UTF-8', $part->charset);
            } else {
                $result .= $part->text;
            }
        }
        return $result;
    }
    
    // Si no está codificado MIME, intentar detectar encoding y convertir a UTF-8
    $encoding = mb_detect_encoding($string, ['UTF-8', 'ISO-8859-1', 'Windows-1252'], true);
    if ($encoding && $encoding != 'UTF-8') {
        return mb_convert_encoding($string, 'UTF-8', $encoding);
    }
    
    return $string;
}


// Función ultra-simple para eliminar firmas
private function eliminarFirma($contenido) {
    debug_log("Eliminando firma - enfoque ultra-simple");
    
    // Buscar los patrones principales
    $patrones = [
        '/\nAtentamente,.*/is',
        '/\nBest regards,.*/is'
    ];
    
    foreach ($patrones as $patron) {
        $resultado = preg_replace($patron, '', $contenido);
        if ($resultado !== $contenido) {
            debug_log("Firma eliminada usando patrón: " . $patron);
            return trim($resultado);
        }
    }
    
    // Si no se encontraron los patrones principales, devolver original
    debug_log("No se encontró firma para eliminar");
    return $contenido;
}

// Función auxiliar para detectar firmas basada en patrones de líneas
private function esProbableFirma($lineas, $contenido_actual) {
    $indice_actual = count($contenido_actual);
    $lineas_restantes = array_slice($lineas, $indice_actual);
    $lineas_restantes = array_slice($lineas_restantes, 0, 10); // Mirar las próximas 10 líneas
    
    $patrones_firma = [
        '/ext:?\s*\d+/i',
        '/tel:?\s*\d+/i',
        '/phone:?\s*\d+/i',
        '/@.*\.com/i',
        '/@.*\.co/i',
        '/www\./i',
        '/http:/i',
        '/https:/i',
        '/image/i',
        '/icono/i',
        '/☏/',
        '/📞/',
        '/✉/'
    ];
    
    $coincidencias = 0;
    foreach ($lineas_restantes as $linea) {
        $linea = trim($linea);
        if (empty($linea)) continue;
        
        foreach ($patrones_firma as $patron) {
            if (preg_match($patron, $linea)) {
                $coincidencias++;
                break;
            }
        }
        
        // Si hay al menos 2 coincidencias en las próximas líneas, probablemente es firma
        if ($coincidencias >= 2) {
            return true;
        }
    }
    
    return false;
}

// Función para obtener item por ID
public function obtenerItemPorId($item_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();

        $query = "SELECT * FROM items WHERE id = :id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':id', $item_id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        error_log("Error obteniendo item: " . $e->getMessage());
        return null;
    }
}

// Función para obtener la firma del correo en HTML
private function obtenerFirmaCorreo() {
    $firma = "<br><br><hr style='border: 1px solid #e0e0e0; margin: 20px 0;'>";
    $firma .= "<div style='font-family: Arial, sans-serif; color: #333; font-size: 12px;'>";
    $firma .= "<img src='cid:firma_azc' alt='AZC Legal' style='max-width: 125px; margin-bottom: 10px;'><br>";
    $firma .= "<p style='margin: 10px 0;'><strong>Atentamente,</strong></p>";
    $firma .= "<p style='margin: 5px 0;'><strong>Equipo de Soporte Técnico</strong></p>";
    $firma .= "<p style='margin: 5px 0;'><strong>AZC Legal</strong></p>";
    $firma .= "<p style='margin: 5px 0;'>Ext: 6565</p>";
    $firma .= "</div>";
    
    return $firma;
}



// Función para obtener la sede de un empleado por su CC
public function obtenerSedePorCC($empleado_cc) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT s.nombre as sede_nombre
                  FROM employee e 
                  LEFT JOIN sedes s ON e.sede_id = s.id 
                  WHERE e.CC = :cc 
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':cc', $empleado_cc);
        $stmt->execute();
        
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $resultado['sede_nombre'] ?? null;
        
    } catch (Exception $e) {
        debug_log("Error en obtenerSedePorCC: " . $e->getMessage());
        return null;
    }
}

public function obtenerFotoUsuario($photo_name, $default = 'default_avatar.png') {
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/photos/';
    
    if ($photo_name && !empty($photo_name) && file_exists($upload_dir . $photo_name)) {
        return '/uploads/photos/' . $photo_name;
    }
    
    return '/assets/images/' . $default;
}

private function crearAvatarPorDefecto($path) {
    $image = imagecreate(100, 100);
    $background = imagecolorallocate($image, 108, 117, 125); // Color gris bootstrap
    $text_color = imagecolorallocate($image, 255, 255, 255);
    
    // Crear un círculo
    imagefilledellipse($image, 50, 50, 90, 90, $background);
    
    // Agregar texto "AZC
    imagestring($image, 3, 35, 40, 'AZC', $text_color);
    
    imagepng($image, $path);
    imagedestroy($image);
}

public function procesarFotoUsuario($file, $cc) {
    $upload_dir = __DIR__ . '/../../uploads/photos/';
    
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
    
    // Redimensionar imagen
    $this->redimensionarImagen($file_path, 300, 300);
    
    return $file_name;
}

private function redimensionarImagen($file_path, $max_width, $max_height) {
    list($width, $height, $type) = getimagesize($file_path);
    
    if ($width <= $max_width && $height <= $max_height) {
        return;
    }
    
    $ratio = min($max_width/$width, $max_height/$height);
    $new_width = $width * $ratio;
    $new_height = $height * $ratio;
    
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

public function eliminarFotoUsuario($photo_name) {
    if ($photo_name) {
        $file_path = __DIR__ . '/../../uploads/photos/' . $photo_name;
        if (file_exists($file_path)) {
            unlink($file_path);
            return true;
        }
    }
    return false;
}

// Función para enviar correos de confirmación del formulario de candidatos
public function enviarCorreosFormularioCandidato($datos_candidato, $candidato_id) {
    try {
        debug_log("=== ENVÍO DE CORREOS FORMULARIO CANDIDATO INICIADO ===");
        
        // 1. Correo de confirmación al candidato
        $resultado_candidato = $this->enviarCorreoConfirmacionCandidato($datos_candidato);
        
        // 2. Correo a Talento Humano
        $resultado_talento_humano = $this->enviarCorreoTalentoHumano($datos_candidato);
        
        // 3. Correo a Soporte para crear ticket de Onboarding
        $resultado_soporte = $this->enviarCorreoSoporteOnboarding($datos_candidato, $candidato_id);
        
        return [
            'success' => true,
            'candidato' => $resultado_candidato,
            'talento_humano' => $resultado_talento_humano,
            'soporte' => $resultado_soporte
        ];
        
    } catch (Exception $e) {
        $errorMsg = "Error en enviarCorreosFormularioCandidato: " . $e->getMessage();
        debug_log($errorMsg);
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

// Función para enviar correo de confirmación al candidato
private function enviarCorreoConfirmacionCandidato($datos) {
    try {
        $para = $datos['personal_mail'];
        $asunto = "✅ Confirmación de Recepción - Formulario de Candidatos AZC Legal";
        
        $nombre_completo = trim($datos['first_Name'] . ' ' . 
                               ($datos['second_Name'] ? $datos['second_Name'] . ' ' : '') . 
                               $datos['first_LastName'] . ' ' . 
                               ($datos['second_LastName'] ? $datos['second_LastName'] : ''));
        
        $mensaje = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #007bff, #0056b3); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 30px; border-radius: 0 0 10px 10px; }
                .success-icon { font-size: 48px; margin-bottom: 20px; }
                .info-box { background: white; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 4px solid #28a745; }
                .footer { text-align: center; margin-top: 30px; padding: 20px; color: #6c757d; font-size: 14px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✅ Confirmación Exitosa</h1>
                    <p>Formulario de Candidatos AZC Legal</p>
                </div>
                <div class='content'>
                    
                    <h2>¡Hola {$nombre_completo}!</h2>
                    
                    <p>Hemos recibido exitosamente tu formulario de candidatura en <strong>AZC Legal</strong>. Tu información ha sido registrada en nuestro sistema y será revisada por nuestro equipo de Talento Humano.</p>
                    
                    <div class='info-box'>
                        <h3>Detalles de tu Registro:</h3>
                        <p><strong>Nombre completo:</strong> {$nombre_completo}</p>
                        <p><strong>Número de documento:</strong> {$datos['CC']}</p>
                        <p><strong>Correo electrónico:</strong> {$datos['personal_mail']}</p>
                        <p><strong>Teléfono:</strong> {$datos['phone']}</p>
                        <p><strong>Fecha de registro:</strong> " . date('d/m/Y H:i') . "</p>
                    </div>
                    
                    <h3>📝 Próximos Pasos:</h3>
                    <ul>
                        <li>Nuestro equipo de Talento Humano revisará tu información</li>
                        <li>Te contactaremos en los próximos días si tu perfil coincide con nuestras necesidades</li>
                        <li>Mantente atento a tu correo electrónico y teléfono</li>
                    </ul>
                    
                    <p>Si tienes alguna pregunta o necesitas actualizar tu información, no dudes en contactarnos.</p>
                    
                    <div class='footer'>
                        <p><strong>AZC Legal - Departamento de Talento Humano</strong></p>
                        <p>📧 mitalentohumano@azc.com.co</p>
                        <p>© " . date('Y') . " AZC Legal. Todos los derechos reservados.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        return $this->enviarCorreoPHPMailerPersonalizado($para, $asunto, $mensaje);
        
    } catch (Exception $e) {
        debug_log("Error en enviarCorreoConfirmacionCandidato: " . $e->getMessage());
        return false;
    }
}

// Función para enviar correo a Talento Humano CON TODOS LOS DATOS
private function enviarCorreoTalentoHumano($datos) {
    try {
        $para = 'mitalentohumano@azc.com.co';
        $asunto = "Nuevo Formulario de Candidato - " . trim($datos['first_Name'] . ' ' . $datos['first_LastName']);
        
        $nombre_completo = trim($datos['first_Name'] . ' ' . 
                               ($datos['second_Name'] ? $datos['second_Name'] . ' ' : '') . 
                               $datos['first_LastName'] . ' ' . 
                               ($datos['second_LastName'] ? $datos['second_LastName'] : ''));
        
        // Obtener tipo de documento
        $tipos_documento = [
            '1' => 'Cédula de Ciudadanía',
            '2' => 'Pasaporte', 
            '3' => 'Cédula de Extranjería'
        ];
        $tipo_doc = $tipos_documento[$datos['type_CC']] ?? 'No especificado';
        
        // Obtener estado civil
        $estado_civil = $this->obtenerEstadoCivil($datos['dval']);
        
        $mensaje = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='UTF-8'>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 700px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 25px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: #f8f9fa; padding: 25px; border-radius: 0 0 10px 10px; }
                .data-section { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #28a745; }
                .data-row { display: flex; margin-bottom: 8px; padding: 5px 0; border-bottom: 1px solid #eee; }
                .data-label { font-weight: bold; width: 200px; color: #495057; }
                .data-value { flex: 1; color: #212529; }
                .alert { background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .footer { text-align: center; margin-top: 25px; padding: 20px; color: #6c757d; font-size: 14px; }
                .attachments { background: #e9ecef; padding: 15px; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Nuevo Candidato Registrado</h1>
                    <p>Sistema de Formularios - AZC Legal</p>
                </div>
                <div class='content'>
                    <div class='alert'>
                        <strong>⚠️ Acción Requerida:</strong> Se ha registrado un nuevo candidato en el sistema. Por favor revisar la información y documentos adjuntos.
                    </div>
                    
                    <h2>Información Completa del Candidato</h2>
                    
                    <div class='data-section'>
                        <h3>Datos Personales Completos</h3>
                        <div class='data-row'>
                            <div class='data-label'>Nombre completo:</div>
                            <div class='data-value'><strong>{$nombre_completo}</strong></div>
                        </div>
                        <div class='data-row'>
                            <div class='data-label'>Tipo de documento:</div>
                            <div class='data-value'>{$tipo_doc}</div>
                        </div>
                        <div class='data-row'>
                            <div class='data-label'>Número de documento:</div>
                            <div class='data-value'><strong>{$datos['CC']}</strong></div>
                        </div>
                        <div class='data-row'>
                            <div class='data-label'>Fecha de nacimiento:</div>
                            <div class='data-value'>{$datos['birthdate']}</div>
                        </div>
                        <div class='data-row'>
                            <div class='data-label'>Estado civil:</div>
                            <div class='data-value'>{$estado_civil}</div>
                        </div>
                    </div>
                    
                    <div class='data-section'>
                        <h3>Información de Contacto Completa</h3>
                        <div class='data-row'>
                            <div class='data-label'>Correo personal:</div>
                            <div class='data-value'><a href='mailto:{$datos['personal_mail']}'>{$datos['personal_mail']}</a></div>
                        </div>
                        <div class='data-row'>
                            <div class='data-label'>Teléfono:</div>
                            <div class='data-value'>{$datos['phone']}</div>
                        </div>
                        <div class='data-row'>
                            <div class='data-label'>País:</div>
                            <div class='data-value'>{$datos['country']}</div>
                        </div>
                        <div class='data-row'>
                            <div class='data-label'>Ciudad:</div>
                            <div class='data-value'>{$datos['city']}</div>
                        </div>
                        <div class='data-row'>
                            <div class='data-label'>Dirección completa:</div>
                            <div class='data-value'><strong>{$datos['address']}</strong></div>
                        </div>
                    </div>
                    
		<div class='attachments'>
		    <h3>Documentos Adjuntos al Correo</h3>
		    <p>Se han adjuntado los siguientes documentos:</p>
		    <ul>
		        <li>✅ <strong>Foto de fondo blanco</strong> <em>(Obligatorio)</em></li>
		        <li>✅ <strong>Documento de identidad escaneado</strong> <em>(Obligatorio)</em></li>
		        <li>✅ <strong>Diploma de Pregrado/Bachiller</strong> <em>(Obligatorio)</em></li>
		        <li>✅ <strong>Hoja de vida</strong> <em>(Obligatorio)</em></li>
		        " . (isset($datos['archivos_temp']) && $this->tieneArchivo($datos['archivos_temp'], 'certificado_bancario') ? 
		            "<li>✅ Certificado bancario</li>" : 
		            "<li>❌ Certificado bancario <em>(No enviado - Opcional)</em></li>") . "
		        " . (isset($datos['archivos_temp']) && $this->tieneArchivo($datos['archivos_temp'], 'certificado_eps') ? 
		            "<li>✅ Certificado EPS</li>" : 
		            "<li>❌ Certificado EPS <em>(No enviado - Opcional)</em></li>") . "
		        " . (isset($datos['archivos_temp']) && $this->tieneArchivo($datos['archivos_temp'], 'certificado_pensiones') ? 
		            "<li>✅ Certificado de pensiones</li>" : 
		            "<li>❌ Certificado de pensiones <em>(No enviado - Opcional)</em></li>") . "
		        " . (isset($datos['archivos_temp']) && $this->tieneArchivo($datos['archivos_temp'], 'certificado_cesantias') ? 
		            "<li>✅ Certificado de cesantías</li>" : 
		            "<li>❌ Certificado de cesantías <em>(No enviado - Opcional)</em></li>") . "
		        " . (isset($datos['archivos_temp']) && $this->tieneArchivo($datos['archivos_temp'], 'certificado_ingles') ? 
		            "<li>✅ Certificado de inglés</li>" : 
		            "<li>❌ Certificado de inglés <em>(No enviado - Opcional)</em></li>") . "
		        " . (isset($datos['archivos_temp']) && $this->tieneArchivo($datos['archivos_temp'], 'tarjeta_profesional') ? 
		            "<li>✅ Tarjeta profesional</li>" : 
		            "<li>❌ Tarjeta profesional <em>(No enviado - Opcional)</em></li>") . "
		    </ul>
		    <p><strong>Total de archivos adjuntos:</strong> " . (isset($datos['archivos_temp']) ? count($datos['archivos_temp']) : 0) . "</p>
		</div>
                    
                    <div class='data-section'>
                        <h3>📋 Resumen de Registro</h3>
                        <div class='data-row'>
                            <div class='data-label'>Fecha de registro:</div>
                            <div class='data-value'><strong>" . date('d/m/Y H:i') . "</strong></div>
                        </div>
                        <div class='data-row'>
                            <div class='data-label'>Estado:</div>
                            <div class='data-value'><span style='color: #28a745; font-weight: bold;'>✅ REGISTRADO EN SISTEMA</span></div>
                        </div>
                    </div>
                    
                    <div class='footer'>
                        <p><strong>AZC Legal - Sistema de Gestión de Candidatos</strong></p>
                        <p>Este es un mensaje automático, por favor no responder.</p>
                        <p>© " . date('Y') . " AZC Legal. Todos los derechos reservados.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>
        ";
        
        // Enviar correo con archivos adjuntos
        return $this->enviarCorreoConAdjuntos($para, $asunto, $mensaje, $datos['archivos_temp']);
        
    } catch (Exception $e) {
        debug_log("Error en enviarCorreoTalentoHumano: " . $e->getMessage());
        return false;
    }
}

// Función simplificada para enviar correo a Soporte para ticket de Onboarding
private function enviarCorreoSoporteOnboarding($datos, $candidato_id) {
    try {
        $para = SUPPORT_EMAIL;
        $asunto = "Nuevo Ticket de Onboarding - Candidato: " . trim($datos['first_Name'] . ' ' . $datos['first_LastName']);
        
        $nombre_completo = trim($datos['first_Name'] . ' ' . 
                               ($datos['second_Name'] ? $datos['second_Name'] . ' ' : '') . 
                               $datos['first_LastName'] . ' ' . 
                               ($datos['second_LastName'] ? $datos['second_LastName'] : ''));
        
        // Mensaje simple en texto plano
        $mensaje = "TICKET AUTOMÁTICO - ONBOARDING SOLICITADO

Se requiere preparar equipos y acceso para nuevo colaborador.

INFORMACIÓN DEL CANDIDATO:
- Nombre completo: {$nombre_completo}
- Documento: {$datos['CC']}
- Email: {$datos['personal_mail']}
- Teléfono: {$datos['phone']}
- Fecha solicitud: " . date('d/m/Y H:i') . "


NOTA: Este ticket será procesado una vez Talento Humano confirme la contratación.
Mantener en estado 'Pendiente de confirmación'.

--
AZC Legal - Sistema Automático de Tickets";
        
        return $this->enviarCorreoPHPMailerPersonalizado($para, $asunto, $mensaje);
        
    } catch (Exception $e) {
        debug_log("Error en enviarCorreoSoporteOnboarding: " . $e->getMessage());
        return false;
    }
}

// Función auxiliar para enviar correos con las credenciales temporales especificadas
private function enviarCorreoPHPMailerPersonalizado($para, $asunto, $mensaje) {
    try {
        if (!file_exists(__DIR__ . '/PHPMailer/PHPMailer.php')) {
            debug_log("PHPMailer no encontrado");
            return false;
        }
        
        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configuración del servidor SMTP con las credenciales temporales
        $mail->isSMTP();
        $mail->Host = 'mail.supremecluster.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'techsupport@azclegal.com';
        $mail->Password = 'z8912618Z@!$';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 2525;
        
        $mail->CharSet = 'UTF-8';
        
        // Remitente
        $mail->setFrom('techsupport@azclegal.com', 'AZC Legal - Sistema de Candidatos');
        $mail->addReplyTo('techsupport@azclegal.com', 'AZC Legal Soporte');
        
        // Destinatario
        $mail->addAddress($para);
        
        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = $mensaje;
        $mail->AltBody = strip_tags($mensaje);
        
        $mail->send();
        debug_log("Correo enviado exitosamente a: " . $para);
        return true;
        
    } catch (Exception $e) {
        debug_log("Error enviando correo personalizado a {$para}: " . $e->getMessage());
        return false;
    }
}

// Función auxiliar para obtener estado civil
private function obtenerEstadoCivil($codigo) {
    $estados = [
        '1' => 'Soltero/a',
        '2' => 'Casado/a', 
        '3' => 'Divorciado/a',
        '4' => 'Viudo/a',
        '5' => 'Unión Libre'
    ];
    return $estados[$codigo] ?? 'No especificado';
}

// Función para enviar correo con archivos adjuntos
private function enviarCorreoConAdjuntos($para, $asunto, $mensaje, $archivos) {
    try {
        if (!file_exists(__DIR__ . '/PHPMailer/PHPMailer.php')) {
            debug_log("PHPMailer no encontrado");
            return false;
        }
        
        require_once __DIR__ . '/PHPMailer/Exception.php';
        require_once __DIR__ . '/PHPMailer/PHPMailer.php';
        require_once __DIR__ . '/PHPMailer/SMTP.php';
        
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        // Configuración del servidor SMTP
        $mail->isSMTP();
        $mail->Host = 'mail.supremecluster.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'techsupport@azclegal.com';
        $mail->Password = 'z8912618Z@!$';
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 2525;
        
        $mail->CharSet = 'UTF-8';
        
        // Remitente
        $mail->setFrom('techsupport@azclegal.com', 'AZC Legal - Sistema de Candidatos');
        $mail->addReplyTo('techsupport@azclegal.com', 'AZC Legal Soporte');
        
        // Destinatario
        $mail->addAddress($para);
        
        // Adjuntar archivos
        foreach ($archivos as $archivo) {
            if (file_exists($archivo['ruta'])) {
                $mail->addAttachment(
                    $archivo['ruta'], 
                    $archivo['descripcion'] . '_' . $archivo['nombre_original']
                );
            }
        }
        
        // Contenido
        $mail->isHTML(true);
        $mail->Subject = $asunto;
        $mail->Body = $mensaje;
        $mail->AltBody = strip_tags($mensaje);
        
        $mail->send();
        debug_log("Correo con adjuntos enviado exitosamente a: " . $para);
        return true;
        
    } catch (Exception $e) {
        debug_log("Error enviando correo con adjuntos a {$para}: " . $e->getMessage());
        return false;
    }
}

// Función auxiliar para verificar si un archivo está en los archivos temporales
private function tieneArchivo($archivos_temp, $tipo_archivo) {
    if (!is_array($archivos_temp)) {
        return false;
    }
    
    foreach ($archivos_temp as $archivo) {
        if ($archivo['tipo'] === $tipo_archivo) {
            return true;
        }
    }
    return false;
}

// Agregar esta función en la clase Functions

// Función para guardar imagen del ticket
public function guardarImagenTicket($archivo_imagen) {
    try {
        debug_log("guardarImagenTicket llamado");
        
        // Validar tipo de archivo
        $tipos_permitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
        $tipo_archivo = mime_content_type($archivo_imagen['tmp_name']);
        
        if (!in_array($tipo_archivo, $tipos_permitidos)) {
            throw new Exception('Solo se permiten archivos de imagen (JPG, PNG, GIF)');
        }
        
        // Validar tamaño (máximo 5MB)
        if ($archivo_imagen['size'] > 5 * 1024 * 1024) {
            throw new Exception('La imagen no debe superar los 5MB');
        }
        
        // Crear estructura de directorios por mes y año
        $anio_actual = date('Y');
        $mes_actual = date('m');
        $base_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/tickets/';
        $directorio_mes = $base_dir . $anio_actual . '/' . $mes_actual . '/';
        
        // Crear directorios si no existen
        if (!is_dir($directorio_mes)) {
            if (!mkdir($directorio_mes, 0755, true)) {
                throw new Exception('No se pudo crear el directorio para las imágenes');
            }
        }
        
        // Generar nombre único para el archivo
        $extension = pathinfo($archivo_imagen['name'], PATHINFO_EXTENSION);
        $nombre_archivo = 'ticket_' . time() . '_' . uniqid() . '.' . $extension;
        $ruta_completa = $directorio_mes . $nombre_archivo;
        
        // Mover archivo
        if (!move_uploaded_file($archivo_imagen['tmp_name'], $ruta_completa)) {
            throw new Exception('Error al guardar la imagen');
        }
        
        // Redimensionar imagen si es muy grande
        $this->redimensionarImagenTicket($ruta_completa, 1200, 1200);
        
        // Retornar ruta relativa para guardar en BD
        $ruta_relativa = '/uploads/tickets/' . $anio_actual . '/' . $mes_actual . '/' . $nombre_archivo;
        
        debug_log("Imagen guardada exitosamente: " . $ruta_relativa);
        return $ruta_relativa;
        
    } catch (Exception $e) {
        debug_log("Error en guardarImagenTicket: " . $e->getMessage());
        return null;
    }
}

// Función para redimensionar imagen del ticket
private function redimensionarImagenTicket($ruta_imagen, $max_ancho, $max_alto) {
    list($ancho_orig, $alto_orig, $tipo) = getimagesize($ruta_imagen);
    
    // Si la imagen es más pequeña que los máximos, no redimensionar
    if ($ancho_orig <= $max_ancho && $alto_orig <= $max_alto) {
        return;
    }
    
    // Calcular nuevas dimensiones manteniendo proporción
    $ratio = min($max_ancho/$ancho_orig, $max_alto/$alto_orig);
    $nuevo_ancho = $ancho_orig * $ratio;
    $nuevo_alto = $alto_orig * $ratio;
    
    // Crear imagen según el tipo
    switch ($tipo) {
        case IMAGETYPE_JPEG:
            $imagen_orig = imagecreatefromjpeg($ruta_imagen);
            break;
        case IMAGETYPE_PNG:
            $imagen_orig = imagecreatefrompng($ruta_imagen);
            break;
        case IMAGETYPE_GIF:
            $imagen_orig = imagecreatefromgif($ruta_imagen);
            break;
        default:
            return; // No redimensionar si no es un tipo soportado
    }
    
    // Crear nueva imagen
    $imagen_nueva = imagecreatetruecolor($nuevo_ancho, $nuevo_alto);
    
    // Preservar transparencia para PNG y GIF
    if ($tipo == IMAGETYPE_PNG || $tipo == IMAGETYPE_GIF) {
        imagecolortransparent($imagen_nueva, imagecolorallocatealpha($imagen_nueva, 0, 0, 0, 127));
        imagealphablending($imagen_nueva, false);
        imagesavealpha($imagen_nueva, true);
    }
    
    // Redimensionar
    imagecopyresampled($imagen_nueva, $imagen_orig, 0, 0, 0, 0, $nuevo_ancho, $nuevo_alto, $ancho_orig, $alto_orig);
    
    // Guardar imagen redimensionada
    switch ($tipo) {
        case IMAGETYPE_JPEG:
            imagejpeg($imagen_nueva, $ruta_imagen, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($imagen_nueva, $ruta_imagen, 8);
            break;
        case IMAGETYPE_GIF:
            imagegif($imagen_nueva, $ruta_imagen);
            break;
    }
    
    // Liberar memoria
    imagedestroy($imagen_orig);
    imagedestroy($imagen_nueva);
}

// Función para obtener la ruta de la imagen del ticket
public function obtenerImagenTicket($ticket_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT imagen_ruta FROM tickets WHERE id = :ticket_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':ticket_id', $ticket_id);
        $stmt->execute();
        
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($resultado && !empty($resultado['imagen_ruta'])) {
            return $resultado['imagen_ruta'];
        }
        
        return null;
        
    } catch (Exception $e) {
        debug_log("Error en obtenerImagenTicket: " . $e->getMessage());
        return null;
    }
}

public function obtenerTicketsPorEmpleado($empleado_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT t.*, i.nombre as item_nombre, 
                         CASE 
                             WHEN t.responsable_id IS NULL OR t.responsable_id = 0 THEN 'Soporte'
                             ELSE CONCAT(e.first_Name, ' ', e.first_LastName) 
                         END as responsable_nombre
                  FROM tickets t 
                  LEFT JOIN items i ON t.item_id = i.id 
                  LEFT JOIN employee e ON t.responsable_id = e.id 
                  WHERE t.empleado_id = :empleado_id 
                  ORDER BY t.creado_en DESC 
                  LIMIT 10";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':empleado_id', $empleado_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        error_log("Error al obtener tickets del empleado: " . $e->getMessage());
        return [];
    }
}

public function obtenerEmpleadoPorId($user_id) {
    $database = new Database();
    $conn = $database->getConnection();
    
    $query = "SELECT e.*, s.nombre as sede_nombre, 
                     CONCAT(e.first_Name, ' ', e.first_LastName) as nombre,
                     e.position as cargo
              FROM employee e 
              LEFT JOIN sedes s ON e.sede_id = s.id
              WHERE e.id = :id";
    
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':id', $user_id);
    $stmt->execute();
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}


public function obtenerSupervisorInmediato($empleado_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT supervisor_id FROM employee WHERE CC = :empleado_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':empleado_id', $empleado_id);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['supervisor_id'] : null;
        
    } catch (Exception $e) {
        error_log("Error obteniendo supervisor: " . $e->getMessage());
        return null;
    }
}

// Función para enviar notificaciones de permisos usando PHPMailer
public function enviarNotificacionPermiso($permiso_id, $estado, $tipo_aprobacion = null) {
    try {
        debug_log("enviarNotificacionPermiso llamado - permiso_id: $permiso_id, estado: $estado, tipo_aprobacion: $tipo_aprobacion");
        
        $database = new Database();
        $conn = $database->getConnection();
        
        // Obtener información completa del permiso
        $query = "SELECT p.*, 
                         e.mail as empleado_email, 
                         e.company as empleado_company,
                         s.mail as supervisor_email,
                         CONCAT(s.first_Name, ' ', s.first_LastName) as supervisor_nombre,
                         CONCAT(resp.first_Name, ' ', resp.first_LastName) as responsable_nombre
                  FROM permisos p
                  LEFT JOIN employee e ON p.empleado_id = e.CC
                  LEFT JOIN employee s ON p.supervisor_id = s.id
                  LEFT JOIN employee resp ON p.responsable_id = resp.id
                  WHERE p.id = :permiso_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':permiso_id', $permiso_id);
        $stmt->execute();
        $permiso = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$permiso) {
            throw new Exception("Permiso no encontrado");
        }
        
        $tipos = [
            'no_remunerado' => 'No Remunerado',
            'remunerado' => 'Remunerado',
            'por_hora' => 'Por Horas',
            'matrimonio' => 'Matrimonio',
            'trabajo_casa' => 'Trabajo en Casa'
        ];
        
        $tipo_permiso = $tipos[$permiso['tipo_permiso']] ?? $permiso['tipo_permiso'];
        $fecha_inicio_formateada = date('d/m/Y', strtotime($permiso['fecha_inicio']));
        $fecha_fin_formateada = date('d/m/Y', strtotime($permiso['fecha_fin']));
        
        // Determinar destinatarios y contenido según el tipo de notificación
        $para = [];
        $asunto = "";
        $mensaje = "";
        
        switch ($estado) {
            case 'solicitud':
                // Notificación al supervisor
                $asunto = "📋 Nueva Solicitud de Permiso - " . SITE_NAME;
                if (!empty($permiso['supervisor_email'])) {
                    $para[] = $permiso['supervisor_email'];
                }
                
                $mensaje = $this->crearMensajeSolicitudPermiso($permiso, $tipo_permiso, $fecha_inicio_formateada, $fecha_fin_formateada);
                break;
                
            case 'aprobado':
                if ($tipo_aprobacion == 'supervisor') {
                    $asunto = "✅ Permiso Aprobado por Supervisor - " . SITE_NAME;
                    $para[] = $permiso['empleado_email'];
                    
                // NOTIFICAR A TALENTO HUMANO (excepto BilingueLaw)
		if ($permiso['empleado_company'] != 2 && $permiso['estado_talento_humano'] != 'no_aplica') {
		    $para_th = 'mitalentohumano@azc.com.co'; //el email de TH
		    $asunto_th = "📋 Permiso Pendiente de TH - " . SITE_NAME;
		    $mensaje_th = $this->crearMensajePendienteTH($permiso, $tipo_permiso, $fecha_inicio_formateada, $fecha_fin_formateada);
		            $this->enviarCorreoPHPMailer($para_th, $asunto_th, $mensaje_th);
		        }
                    // Si es BilingueLaw y fue aprobado por supervisor, está completamente aprobado
                    if ($permiso['empleado_company'] == 2 || $permiso['estado_talento_humano'] == 'no_aplica') {
                        $mensaje = $this->crearMensajePermisoCompletamenteAprobado($permiso, $tipo_permiso, $fecha_inicio_formateada, $fecha_fin_formateada, 'supervisor');
                    } else {
                        $mensaje = $this->crearMensajePermisoAprobadoSupervisor($permiso, $tipo_permiso, $fecha_inicio_formateada, $fecha_fin_formateada);
                    }
                } else {
                    $asunto = "✅ Permiso Aprobado Completamente - " . SITE_NAME;
                    $para[] = $permiso['empleado_email'];
                    $mensaje = $this->crearMensajePermisoCompletamenteAprobado($permiso, $tipo_permiso, $fecha_inicio_formateada, $fecha_fin_formateada, 'talento_humano');
                }
                break;
                
            case 'rechazado':
                $asunto = "❌ Permiso Rechazado - " . SITE_NAME;
                $para[] = $permiso['empleado_email'];
                $aprobador = ($tipo_aprobacion == 'supervisor') ? 'supervisor' : 'Talento Humano';
                $notas_campo = 'notas_' . $tipo_aprobacion;
                $notas = $permiso[$notas_campo] ?? 'No se proporcionó motivo específico';
                $mensaje = $this->crearMensajePermisoRechazado($permiso, $tipo_permiso, $fecha_inicio_formateada, $fecha_fin_formateada, $aprobador, $notas);
                break;
                
            default:
                throw new Exception("Tipo de notificación no válido: $estado");
        }
        
        // Si no hay destinatarios, no enviar correo
        if (empty($para)) {
            debug_log("No hay destinatarios para la notificación del permiso #$permiso_id");
            return false;
        }
        
        // Enviar correo usando PHPMailer
        $resultado = false;
        foreach ($para as $destinatario) {
            if (!empty($destinatario)) {
                $resultado = $this->enviarCorreoPHPMailer($destinatario, $asunto, $mensaje);
                if ($resultado) {
                    debug_log("Notificación de permiso enviada exitosamente a: $destinatario");
                } else {
                    debug_log("Error enviando notificación de permiso a: $destinatario");
                }
            }
        }
        
        return $resultado;
        
    } catch (Exception $e) {
        $errorMsg = "Error en enviarNotificacionPermiso: " . $e->getMessage();
        debug_log($errorMsg);
        return false;
    }
}

// Función para crear mensaje de solicitud de permiso
private function crearMensajeSolicitudPermiso($permiso, $tipo_permiso, $fecha_inicio, $fecha_fin) {
    $empleado_nombre = htmlspecialchars($permiso['empleado_nombre'], ENT_QUOTES, 'UTF-8');
    $motivo = nl2br(htmlspecialchars($permiso['motivo'], ENT_QUOTES, 'UTF-8'));
    
    $rango_fechas = ($permiso['fecha_inicio'] == $permiso['fecha_fin']) 
        ? $fecha_inicio 
        : "$fecha_inicio al $fecha_fin";
    
    $horas_info = "";
    if ($permiso['tipo_permiso'] == 'por_hora' && !empty($permiso['horas'])) {
        $horas_array = explode(':', $permiso['horas']);
        $horas_info = "<p><strong>Horario:</strong> {$horas_array[0]}:{$horas_array[1]} horas</p>";
    }
    
    $compania_info = "";
    if ($permiso['empleado_company'] == 2) {
        $compania_info = "<div style='background: #fff3cd; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #ffc107;'>
                            <strong>💡 Información importante:</strong> Este permiso es de BilingueLaw y solo requiere su aprobación como supervisor.
                          </div>";
    }
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #003a5d, #002b47); color: white; padding: 25px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 25px; border-radius: 0 0 10px 10px; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #003a5d; }
            .footer { text-align: center; margin-top: 25px; padding: 20px; color: #6c757d; font-size: 14px; }
            .btn { display: inline-block; padding: 10px 20px; background: #003a5d; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>📋 Nueva Solicitud de Permiso</h1>
                <p>Sistema de Gestión de Permisos - " . SITE_NAME . "</p>
            </div>
            <div class='content'>
                <h2>Hola " . htmlspecialchars($permiso['supervisor_nombre'] ?? 'Supervisor', ENT_QUOTES, 'UTF-8') . ",</h2>
                
                <p>Se ha recibido una nueva solicitud de permiso que requiere su revisión y aprobación.</p>
                
                <div class='info-box'>
                    <h3>Detalles de la Solicitud</h3>
                    <p><strong>Empleado:</strong> $empleado_nombre</p>
                    <p><strong>Tipo de permiso:</strong> $tipo_permiso</p>
                    <p><strong>Fechas:</strong> $rango_fechas</p>
                    $horas_info
                    <p><strong>Motivo:</strong><br>$motivo</p>
                    <p><strong>Fecha de solicitud:</strong> " . date('d/m/Y H:i', strtotime($permiso['creado_en'])) . "</p>
                </div>
                
                $compania_info
                
                <div style='text-align: center; margin: 25px 0;'>
                    <a href='" . SITE_URL . "/admin/permisos.php' class='btn'>📝 Revisar Solicitud</a>
                </div>
                
                <p>Por favor, acceda al sistema para revisar y gestionar esta solicitud de permiso.</p>
            </div>
            " . $this->obtenerFirmaCorreo() . "
        </div>
    </body>
    </html>";
}

// Función para crear mensaje de permiso aprobado por supervisor
private function crearMensajePermisoAprobadoSupervisor($permiso, $tipo_permiso, $fecha_inicio, $fecha_fin) {
    $empleado_nombre = htmlspecialchars($permiso['empleado_nombre'], ENT_QUOTES, 'UTF-8');
    
    $rango_fechas = ($permiso['fecha_inicio'] == $permiso['fecha_fin']) 
        ? $fecha_inicio 
        : "$fecha_inicio al $fecha_fin";
    
    $notas_supervisor = "";
    if (!empty($permiso['notas_supervisor'])) {
        $notas_supervisor = "<div style='background: #e7f3ff; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #007bff;'>
                                <strong>📝 Notas del supervisor:</strong><br>" . nl2br(htmlspecialchars($permiso['notas_supervisor'], ENT_QUOTES, 'UTF-8')) . "
                             </div>";
    }
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 25px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 25px; border-radius: 0 0 10px 10px; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #28a745; }
            .footer { text-align: center; margin-top: 25px; padding: 20px; color: #6c757d; font-size: 14px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>✅ Permiso Aprobado por Supervisor</h1>
                <p>Sistema de Gestión de Permisos - " . SITE_NAME . "</p>
            </div>
            <div class='content'>
                <h2>Hola $empleado_nombre,</h2>
                
                <p>Su solicitud de permiso ha sido <strong>aprobada por su supervisor</strong> y ahora está pendiente de revisión por el área de Talento Humano.</p>
                
                <div class='info-box'>
                    <h3>Detalles del Permiso</h3>
                    <p><strong>Tipo:</strong> $tipo_permiso</p>
                    <p><strong>Fechas:</strong> $rango_fechas</p>
                    <p><strong>Estado actual:</strong> <span style='color: #28a745; font-weight: bold;'>✅ Aprobado por Supervisor</span></p>
                    <p><strong>Próximo paso:</strong> Aprobación de Talento Humano</p>
                </div>
                
                $notas_supervisor
                
                <div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #ffc107;'>
                    <strong>📋 Proceso de Aprobación:</strong><br>
                    Su permiso ha sido aprobado por su supervisor. Ahora será revisado por el área de Talento Humano para la aprobación final. 
                    Recibirá una nueva notificación cuando el proceso esté completo.
                </div>
                
                <p>Puede ver el estado de su solicitud en cualquier momento accediendo al sistema.</p>
            </div>
            " . $this->obtenerFirmaCorreo() . "
        </div>
    </body>
    </html>";
}

// Función para crear mensaje de permiso completamente aprobado
private function crearMensajePermisoCompletamenteAprobado($permiso, $tipo_permiso, $fecha_inicio, $fecha_fin, $aprobador_final) {
    $empleado_nombre = htmlspecialchars($permiso['empleado_nombre'], ENT_QUOTES, 'UTF-8');
    
    $rango_fechas = ($permiso['fecha_inicio'] == $permiso['fecha_fin']) 
        ? $fecha_inicio 
        : "$fecha_inicio al $fecha_fin";
    
    $aprobador_texto = ($aprobador_final == 'supervisor') ? 'supervisor' : 'Talento Humano';
    $notas_campo = 'notas_' . $aprobador_final;
    $notas = "";
    
    if (!empty($permiso[$notas_campo])) {
        $notas = "<div style='background: #e7f3ff; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #007bff;'>
                    <strong>📝 Notas del $aprobador_texto:</strong><br>" . nl2br(htmlspecialchars($permiso[$notas_campo], ENT_QUOTES, 'UTF-8')) . "
                 </div>";
    }
    
    $info_bilinguelaw = "";
    if ($permiso['empleado_company'] == 2) {
        $info_bilinguelaw = "<div style='background: #d4edda; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #28a745;'>
                                <strong>💡 Información BilingueLaw:</strong> Su permiso ha sido completamente aprobado y solo requería la aprobación del supervisor.
                             </div>";
    }
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #28a745, #20c997); color: white; padding: 25px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 25px; border-radius: 0 0 10px 10px; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #28a745; }
            .success-badge { background: #28a745; color: white; padding: 10px 20px; border-radius: 20px; display: inline-block; margin: 10px 0; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎉 Permiso Aprobado Completamente</h1>
                <p>Sistema de Gestión de Permisos - " . SITE_NAME . "</p>
            </div>
            <div class='content'>
                <h2>¡Hola $empleado_nombre!</h2>
                
                <div class='success-badge'>
                    <strong>✅ PERMISO APROBADO</strong>
                </div>
                
                <p>Nos complace informarle que su solicitud de permiso ha sido <strong>completamente aprobada</strong> y está lista para ser disfrutada.</p>
                
                <div class='info-box'>
                    <h3>Detalles del Permiso Aprobado</h3>
                    <p><strong>Tipo:</strong> $tipo_permiso</p>
                    <p><strong>Fechas:</strong> $rango_fechas</p>
                    <p><strong>Estado:</strong> <span style='color: #28a745; font-weight: bold;'>✅ Completamente Aprobado</span></p>
                    <p><strong>Aprobado por:</strong> $aprobador_texto</p>
                    <p><strong>Fecha de aprobación:</strong> " . date('d/m/Y H:i') . "</p>
                </div>
                
                $notas
                $info_bilinguelaw
                
                <div style='background: #d1ecf1; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #0dcaf0;'>
                    <strong>📌 Recordatorio importante:</strong><br>
                    • Asegúrese de coordinar con su equipo las responsabilidades durante su ausencia.<br>
                    • Si el permiso es por horas, respete el horario establecido.<br>
                    • Para permisos de trabajo en casa, mantenga comunicación constante con su supervisor.
                </div>
                
                <p>¡Disfrute de su permiso y esperamos verlo de regreso!</p>
            </div>
            " . $this->obtenerFirmaCorreo() . "
        </div>
    </body>
    </html>";
}

// Función para crear mensaje de permiso rechazado
private function crearMensajePermisoRechazado($permiso, $tipo_permiso, $fecha_inicio, $fecha_fin, $aprobador, $notas) {
    $empleado_nombre = htmlspecialchars($permiso['empleado_nombre'], ENT_QUOTES, 'UTF-8');
    
    $rango_fechas = ($permiso['fecha_inicio'] == $permiso['fecha_fin']) 
        ? $fecha_inicio 
        : "$fecha_inicio al $fecha_fin";
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #dc3545, #c82333); color: white; padding: 25px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 25px; border-radius: 0 0 10px 10px; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #dc3545; }
            .rejection-reason { background: #f8d7da; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #dc3545; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>❌ Permiso Rechazado</h1>
                <p>Sistema de Gestión de Permisos - " . SITE_NAME . "</p>
            </div>
            <div class='content'>
                <h2>Hola $empleado_nombre,</h2>
                
                <p>Lamentamos informarle que su solicitud de permiso ha sido <strong>rechazada</strong>.</p>
                
                <div class='info-box'>
                    <h3>Detalles de la Solicitud</h3>
                    <p><strong>Tipo:</strong> $tipo_permiso</p>
                    <p><strong>Fechas solicitadas:</strong> $rango_fechas</p>
                    <p><strong>Estado:</strong> <span style='color: #dc3545; font-weight: bold;'>❌ Rechazado</span></p>
                    <p><strong>Rechazado por:</strong> $aprobador</p>
                </div>
                
                <div class='rejection-reason'>
                    <h4>📝 Motivo del Rechazo</h4>
                    <p>" . nl2br(htmlspecialchars($notas, ENT_QUOTES, 'UTF-8')) . "</p>
                </div>
                
                <div style='background: #fff3cd; padding: 15px; border-radius: 5px; margin: 15px 0; border-left: 4px solid #ffc107;'>
                    <strong>💡 ¿Necesita aclaraciones?</strong><br>
                    Si tiene preguntas sobre esta decisión o necesita aclaraciones adicionales, puede contactar directamente al $aprobador que revisó su solicitud.
                </div>
                
                <p>Si considera que hay información adicional que podría cambiar esta decisión, puede contactar al área correspondiente para discutir las opciones disponibles.</p>
            </div>
            " . $this->obtenerFirmaCorreo() . "
        </div>
    </body>
    </html>";
}

// En la clase Functions, agregar este método:

public function esSupervisor($empleado_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        // Buscar por ID del empleado (no por CC)
        $query = "SELECT COUNT(*) as total FROM supervisores WHERE empleado_id = :empleado_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':empleado_id', $empleado_id);
        $stmt->execute();
        
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result && $result['total'] > 0;
        
    } catch (Exception $e) {
        error_log("Error verificando supervisor: " . $e->getMessage());
        return false;
    }
}

// Función para guardar soporte multimedia de permisos
public function guardarSoportePermiso($archivo_soporte) {
    try {
        debug_log("guardarSoportePermiso llamado");

        // Validar tipo de archivo
        $tipos_permitidos = [
            'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf', 
            'application/msword', 
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/zip', 'application/x-zip-compressed'
        ];
        
        $tipo_archivo = mime_content_type($archivo_soporte['tmp_name']);
        
        if (!in_array($tipo_archivo, $tipos_permitidos)) {
            throw new Exception('Tipo de archivo no permitido. Solo se permiten imágenes, PDF, Word y ZIP.');
        }

        // Validar tamaño (máximo 5MB)
        if ($archivo_soporte['size'] > 5 * 1024 * 1024) {
            throw new Exception('El archivo no debe superar los 5MB');
        }

        // Crear estructura de directorios por mes y año
        $anio_actual = date('Y');
        $mes_actual = date('m');
        $base_dir = $_SERVER['DOCUMENT_ROOT'] . '/uploads/permisos/';
        $directorio_mes = $base_dir . $anio_actual . '/' . $mes_actual . '/';

        // Crear directorios si no existen
        if (!is_dir($directorio_mes)) {
            if (!mkdir($directorio_mes, 0755, true)) {
                throw new Exception('No se pudo crear el directorio para los archivos de soporte');
            }
        }

        // Generar nombre único para el archivo
        $extension = pathinfo($archivo_soporte['name'], PATHINFO_EXTENSION);
        $nombre_archivo = 'permiso_' . time() . '_' . uniqid() . '.' . $extension;
        $ruta_completa = $directorio_mes . $nombre_archivo;

        // Mover archivo
        if (!move_uploaded_file($archivo_soporte['tmp_name'], $ruta_completa)) {
            throw new Exception('Error al guardar el archivo de soporte');
        }

        // Redimensionar imágenes si son muy grandes
        if (strpos($tipo_archivo, 'image/') === 0) {
            $this->redimensionarImagenPermiso($ruta_completa, 1200, 1200);
        }

        // Retornar ruta relativa para guardar en BD
        $ruta_relativa = '/uploads/permisos/' . $anio_actual . '/' . $mes_actual . '/' . $nombre_archivo;

        debug_log("Soporte de permiso guardado exitosamente: " . $ruta_relativa);
        return $ruta_relativa;

    } catch (Exception $e) {
        debug_log("Error en guardarSoportePermiso: " . $e->getMessage());
        return null;
    }
}

// Función para redimensionar imágenes de permisos
private function redimensionarImagenPermiso($ruta_imagen, $max_ancho, $max_alto) {
    list($ancho_orig, $alto_orig, $tipo) = getimagesize($ruta_imagen);
    
    // Si la imagen es más pequeña que los máximos, no redimensionar
    if ($ancho_orig <= $max_ancho && $alto_orig <= $max_alto) {
        return;
    }
    
    // Calcular nuevas dimensiones manteniendo proporción
    $ratio = min($max_ancho/$ancho_orig, $max_alto/$alto_orig);
    $nuevo_ancho = $ancho_orig * $ratio;
    $nuevo_alto = $alto_orig * $ratio;
    
    // Crear imagen según el tipo
    switch ($tipo) {
        case IMAGETYPE_JPEG:
            $imagen_orig = imagecreatefromjpeg($ruta_imagen);
            break;
        case IMAGETYPE_PNG:
            $imagen_orig = imagecreatefrompng($ruta_imagen);
            break;
        case IMAGETYPE_GIF:
            $imagen_orig = imagecreatefromgif($ruta_imagen);
            break;
        default:
            return; // No redimensionar si no es un tipo soportado
    }
    
    // Crear nueva imagen
    $imagen_nueva = imagecreatetruecolor($nuevo_ancho, $nuevo_alto);
    
    // Preservar transparencia para PNG y GIF
    if ($tipo == IMAGETYPE_PNG || $tipo == IMAGETYPE_GIF) {
        imagecolortransparent($imagen_nueva, imagecolorallocatealpha($imagen_nueva, 0, 0, 0, 127));
        imagealphablending($imagen_nueva, false);
        imagesavealpha($imagen_nueva, true);
    }
    
    // Redimensionar
    imagecopyresampled($imagen_nueva, $imagen_orig, 0, 0, 0, 0, $nuevo_ancho, $nuevo_alto, $ancho_orig, $alto_orig);
    
    // Guardar imagen redimensionada
    switch ($tipo) {
        case IMAGETYPE_JPEG:
            imagejpeg($imagen_nueva, $ruta_imagen, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($imagen_nueva, $ruta_imagen, 8);
            break;
        case IMAGETYPE_GIF:
            imagegif($imagen_nueva, $ruta_imagen);
            break;
    }
    
    // Liberar memoria
    imagedestroy($imagen_orig);
    imagedestroy($imagen_nueva);
}

// Función para obtener la ruta del soporte del permiso
public function obtenerSoportePermiso($permiso_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT soporte_ruta FROM permisos WHERE id = :permiso_id";
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':permiso_id', $permiso_id);
        $stmt->execute();
        
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($resultado && !empty($resultado['soporte_ruta'])) {
            return $resultado['soporte_ruta'];
        }
        
        return null;
        
    } catch (Exception $e) {
        debug_log("Error en obtenerSoportePermiso: " . $e->getMessage());
        return null;
    }
}

// Función auxiliar para obtener información del responsable
private function obtenerInfoResponsable($responsable_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT 
                    CONCAT(first_Name, ' ', first_LastName) as nombre,
                    role
                  FROM employee 
                  WHERE id = :responsable_id 
                  LIMIT 1";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':responsable_id', $responsable_id);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        debug_log("Error en obtenerInfoResponsable: " . $e->getMessage());
        return null;
    }
}

// Función para crear mensaje de permiso pendiente para Talento Humano
private function crearMensajePendienteTH($permiso, $tipo_permiso, $fecha_inicio, $fecha_fin) {
    $empleado_nombre = htmlspecialchars($permiso['empleado_nombre'], ENT_QUOTES, 'UTF-8');
    $supervisor_nombre = htmlspecialchars($permiso['supervisor_nombre'] ?? 'Supervisor', ENT_QUOTES, 'UTF-8');
    
    $rango_fechas = ($permiso['fecha_inicio'] == $permiso['fecha_fin']) 
        ? $fecha_inicio 
        : "$fecha_inicio al $fecha_fin";
    
    $notas_supervisor = "";
    if (!empty($permiso['notas_supervisor'])) {
        $notas_supervisor = "<div style='background: #e7f3ff; padding: 10px; border-radius: 5px; margin: 10px 0; border-left: 4px solid #007bff;'>
                                <strong>📝 Notas del supervisor:</strong><br>" . nl2br(htmlspecialchars($permiso['notas_supervisor'], ENT_QUOTES, 'UTF-8')) . "
                             </div>";
    }
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='UTF-8'>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: linear-gradient(135deg, #ffc107, #e0a800); color: #212529; padding: 25px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { background: #f8f9fa; padding: 25px; border-radius: 0 0 10px 10px; }
            .info-box { background: white; padding: 20px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #ffc107; }
            .btn { display: inline-block; padding: 10px 20px; background: #003a5d; color: white; text-decoration: none; border-radius: 5px; margin: 10px 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>📋 Permiso Pendiente de Revisión</h1>
                <p>Talento Humano - " . SITE_NAME . "</p>
            </div>
            <div class='content'>
                <h2>Nuevo permiso requiere su aprobación</h2>
                
                <p>Un permiso ha sido aprobado por el supervisor y ahora está pendiente de su revisión en Talento Humano.</p>
                
                <div class='info-box'>
                    <h3>Detalles del Permiso</h3>
                    <p><strong>Empleado:</strong> $empleado_nombre</p>
                    <p><strong>Supervisor:</strong> $supervisor_nombre</p>
                    <p><strong>Tipo de permiso:</strong> $tipo_permiso</p>
                    <p><strong>Fechas:</strong> $rango_fechas</p>
                    <p><strong>Estado actual:</strong> <span style='color: #ffc107; font-weight: bold;'>⏳ Pendiente de TH</span></p>
                    <p><strong>Fecha de solicitud:</strong> " . date('d/m/Y H:i', strtotime($permiso['creado_en'])) . "</p>
                </div>
                
                $notas_supervisor
                
                <div style='text-align: center; margin: 25px 0;'>
                    <a href='" . SITE_URL . "/admin/permisos.php' class='btn'>📝 Revisar Permiso</a>
                </div>
                
                <p>Por favor, acceda al sistema para revisar y gestionar esta solicitud de permiso.</p>
            </div>
            " . $this->obtenerFirmaCorreo() . "
        </div>
    </body>
    </html>";
}

// Función para obtener todas las sedes
public function obtenerSedes() {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        // Usar una consulta más específica para evitar duplicados
        $query = "SELECT s.id, s.nombre, s.codigo, s.descripcion, s.activa, s.creado_en
                  FROM sedes s
                  WHERE s.activa = 1
                  ORDER BY s.id ASC";  // Ordenar por ID para mayor control
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        $sedes = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Depuración: mostrar todas las sedes encontradas
        echo "<script>console.log('Sedes encontradas en la función obtenerSedes():', " . json_encode($sedes) . ");</script>";
        
        // NO FILTRAR DUPLICADOS AQUÍ - DEVOLVER LAS SEDES TAL COMO VIENEN
        return $sedes;
        
    } catch (Exception $e) {
        echo "<script>console.error('Error en obtenerSedes():', '" . $e->getMessage() . "');</script>";
        return [];
    }
}

// Función para obtener todos los movimientos entre sedes
public function obtenerMovimientosSedes($sede_id = null) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT ms.*, i.nombre as item_nombre, 
                  so.nombre as sede_origen_nombre, sd.nombre as sede_destino_nombre,
                  e1.first_Name as persona_envia_nombre, e1.first_LastName as persona_envia_apellido,
                  e2.first_Name as persona_recibe_nombre, e2.first_LastName as persona_recibe_apellido
                  FROM movimientos_sedes ms
                  LEFT JOIN items i ON ms.item_id = i.id
                  LEFT JOIN sedes so ON ms.sede_origen_id = so.id
                  LEFT JOIN sedes sd ON ms.sede_destino_id = sd.id
                  LEFT JOIN employee e1 ON ms.persona_envia_id = e1.id
                  LEFT JOIN employee e2 ON ms.persona_recibe_id = e2.id";
        
        if ($sede_id) {
            $query .= " WHERE ms.sede_origen_id = :sede_id OR ms.sede_destino_id = :sede_id";
        }
        
        $query .= " ORDER BY ms.fecha_envio DESC";
        
        $stmt = $conn->prepare($query);
        
        if ($sede_id) {
            $stmt->bindParam(':sede_id', $sede_id);
        }
        
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        debug_log("Error en obtenerMovimientosSedes: " . $e->getMessage());
        return [];
    }
}

// Función para registrar historial específico de items
public function registrarHistorialItem($item_id, $admin_id, $accion, $cantidad = 0, $stock_anterior = 0, $stock_nuevo = 0, $notas = '', $sede_id = null, $referencia_id = null, $conn = null) {
    try {
        // Validar parámetros obligatorios
        if (empty($item_id) || empty($accion)) {
            return false;
        }
        
        // Si admin_id está vacío, intentar obtenerlo de la sesión
        if (empty($admin_id)) {
            $admin_id = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : null;
            
            // Si todavía está vacío, intentar con user_id
            if (empty($admin_id)) {
                $admin_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
            }
            
            // Si todavía está vacío, usar un valor predeterminado
            if (empty($admin_id)) {
                $admin_id = 1; // ID del administrador por defecto
            }
        }
        
        // Usar la conexión proporcionada o crear una nueva
        if (!$conn) {
            $database = new Database();
            $conn = $database->getConnection();
        }
        
        // Si no se proporciona sede_id, obtenerla del item
        if (!$sede_id) {
            $query_item = "SELECT sede_id FROM items WHERE id = :item_id";
            $stmt_item = $conn->prepare($query_item);
            $stmt_item->bindParam(':item_id', $item_id);
            $stmt_item->execute();
            $item_data = $stmt_item->fetch(PDO::FETCH_ASSOC);
            $sede_id = $item_data ? $item_data['sede_id'] : null;
        }
        
        // Preparar y ejecutar la consulta
        $query = "INSERT INTO historial_items (item_id, admin_id, accion, cantidad, stock_anterior, stock_nuevo, notas, sede_id, referencia_id) 
                  VALUES (:item_id, :admin_id, :accion, :cantidad, :stock_anterior, :stock_nuevo, :notas, :sede_id, :referencia_id)";
        
        $stmt = $conn->prepare($query);
        
        if (!$stmt) {
            return false;
        }
        
        // Vincular parámetros con tipos específicos
        $stmt->bindParam(':item_id', $item_id, PDO::PARAM_INT);
        $stmt->bindParam(':admin_id', $admin_id, PDO::PARAM_INT);
        $stmt->bindParam(':accion', $accion, PDO::PARAM_STR);
        $stmt->bindParam(':cantidad', $cantidad, PDO::PARAM_INT);
        $stmt->bindParam(':stock_anterior', $stock_anterior, PDO::PARAM_INT);
        $stmt->bindParam(':stock_nuevo', $stock_nuevo, PDO::PARAM_INT);
        $stmt->bindParam(':notas', $notas, PDO::PARAM_STR);
        
        // Manejar parámetros que pueden ser NULL
        if ($sede_id === null) {
            $stmt->bindValue(':sede_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':sede_id', $sede_id, PDO::PARAM_INT);
        }
        
        if ($referencia_id === null) {
            $stmt->bindValue(':referencia_id', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindParam(':referencia_id', $referencia_id, PDO::PARAM_INT);
        }
        
        return $stmt->execute();
        
    } catch (PDOException $e) {
        return false;
    } catch (Exception $e) {
        return false;
    }
}

// Función para obtener historial de un item específico
public function obtenerHistorialItem($item_id) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT hi.*, i.nombre as item_nombre, 
                         CONCAT(e.first_Name, ' ', e.first_LastName) as admin_nombre,
                         s.nombre as sede_nombre
                  FROM historial_items hi
                  LEFT JOIN items i ON hi.item_id = i.id
                  LEFT JOIN employee e ON hi.admin_id = e.id
                  LEFT JOIN sedes s ON hi.sede_id = s.id
                  WHERE hi.item_id = :item_id
                  ORDER BY hi.creado_en DESC";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':item_id', $item_id);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        debug_log("Error en obtenerHistorialItem: " . $e->getMessage());
        return [];
    }
}

// Función para obtener historial de una sede específica
public function obtenerHistorialSede($sede_id, $limit = 100) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT hi.*, i.nombre as item_nombre, 
                         CONCAT(e.first_Name, ' ', e.first_LastName) as admin_nombre,
                         s.nombre as sede_nombre
                  FROM historial_items hi
                  LEFT JOIN items i ON hi.item_id = i.id
                  LEFT JOIN employee e ON hi.admin_id = e.id
                  LEFT JOIN sedes s ON hi.sede_id = s.id
                  WHERE hi.sede_id = :sede_id
                  ORDER BY hi.creado_en DESC
                  LIMIT :limit";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':sede_id', $sede_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        debug_log("Error en obtenerHistorialSede: " . $e->getMessage());
        return [];
    }
}

// Función para obtener historial general de todas las sedes
public function obtenerHistorialGeneral($limit = 100) {
    try {
        $database = new Database();
        $conn = $database->getConnection();
        
        $query = "SELECT hi.*, i.nombre as item_nombre, 
                         CONCAT(e.first_Name, ' ', e.first_LastName) as admin_nombre,
                         s.nombre as sede_nombre
                  FROM historial_items hi
                  LEFT JOIN items i ON hi.item_id = i.id
                  LEFT JOIN employee e ON hi.admin_id = e.id
                  LEFT JOIN sedes s ON hi.sede_id = s.id
                  ORDER BY hi.creado_en DESC
                  LIMIT :limit";
        
        $stmt = $conn->prepare($query);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (Exception $e) {
        debug_log("Error en obtenerHistorialGeneral: " . $e->getMessage());
        return [];
    }
}

// Función auxiliar para obtener el nombre de una sede
public function obtenerNombreSede($sede_id) {
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





}
?>