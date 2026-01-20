<?php
// Activar reporte de errores para debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Iniciar sesión primero
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

require_once '../includes/auth.php';
require_once '../includes/functions.php';

// Verificar la ruta de FPDF
$fpdf_path = __DIR__ . '/../includes/FPDF/fpdf.php';
if (!file_exists($fpdf_path)) {
    die("Error: No se encuentra FPDF en: " . $fpdf_path . 
        "<br>La estructura actual es: " . print_r(scandir('../includes/'), true));
}

require_once $fpdf_path;

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

// Función para calcular antigüedad
function calcularAntiguedad($fechaCreacion) {
    $fechaActual = new DateTime();
    $fechaCreacion = new DateTime($fechaCreacion);
    $diferencia = $fechaActual->diff($fechaCreacion);
    
    $anios = $diferencia->y;
    $meses = $diferencia->m;
    $dias = $diferencia->d;
    
    $resultado = '';
    if ($anios > 0) {
        $resultado .= $anios . ' año' . ($anios > 1 ? 's' : '');
    }
    if ($meses > 0) {
        if ($resultado != '') $resultado .= ', ';
        $resultado .= $meses . ' mes' . ($meses > 1 ? 'es' : '');
    }
    if ($dias > 0 || $resultado == '') {
        if ($resultado != '') $resultado .= ', ';
        $resultado .= $dias . ' día' . ($dias > 1 ? 's' : '');
    }
    
    return $resultado;
}

// Clase PDF con soporte UTF-8
class PDF extends FPDF {
    // Cabecera de página
    function Header() {
        $this->SetFont('Arial', 'B', 15);
        // Usar iconv para convertir caracteres especiales
        $title = 'INFORME DE EQUIPOS - ' . (defined('SITE_NAME') ? SITE_NAME : 'Sistema');
        $this->Cell(0, 10, $this->iconvSafe($title), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, $this->iconvSafe('Generado el: ' . date('d/m/Y H:i')), 0, 1, 'C');
        $this->Ln(10);
    }

    // Pie de página
    function Footer() {
        $this->SetY(-15);
        $this->SetFont('Arial', 'I', 8);
        $this->Cell(0, 10, $this->iconvSafe('Página ' . $this->PageNo() . '/{nb}'), 0, 0, 'C');
    }

    // Método seguro para iconv
    private function iconvSafe($text) {
        // Convertir null a string vacío y asegurar que sea string
        $text = (string)($text ?? '');
        return iconv('UTF-8', 'windows-1252', $text);
    }

    // Método para escribir texto con UTF-8
    function CellUTF8($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '') {
        parent::Cell($w, $h, $this->iconvSafe($txt), $border, $ln, $align, $fill, $link);
    }

    // Tabla mejorada con soporte UTF-8
    function ImprovedTable($header, $data) {
        // Anchuras de las columnas
        $w = array(25, 25, 40, 40, 30, 30, 30, 22, 35); 
        
        // Cabeceras
        $this->SetFillColor(59, 89, 152);
        $this->SetTextColor(255);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(.3);
        $this->SetFont('Arial', 'B', 8);
        
        for($i = 0; $i < count($header); $i++)
            $this->CellUTF8($w[$i], 7, $header[$i], 1, 0, 'C', true);
        $this->Ln();
        
        // Restaurar colores y fuente
        $this->SetFillColor(224, 235, 255);
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 8);
        
        // Datos
        $fill = false;
        foreach($data as $row) {
            // Asegurar que todos los valores sean strings válidos
            $this->CellUTF8($w[0], 6, $row['activo_fijo'] ?? '', 'LR', 0, 'L', $fill);
            $this->CellUTF8($w[1], 6, $row['marca'] ?? '', 'LR', 0, 'L', $fill);
            $this->CellUTF8($w[2], 6, $row['modelo'] ?? '', 'LR', 0, 'L', $fill);
            $this->CellUTF8($w[3], 6, $row['serial_number'] ?? '', 'LR', 0, 'L', $fill);
            $this->CellUTF8($w[4], 6, $row['sede_nombre'] ?? '', 'LR', 0, 'L', $fill);
            $this->CellUTF8($w[5], 6, $row['estado_display'] ?? '', 'LR', 0, 'L', $fill);
            $this->CellUTF8($w[6], 6, $row['usuario_asignado_nombre'] ?? '', 'LR', 0, 'L', $fill);
            $this->CellUTF8($w[7], 6, $row['creado_en_formatted'] ?? '', 'LR', 0, 'L', $fill);
            $this->CellUTF8($w[8], 6, calcularAntiguedad($row['fecha_creacion'] ?? ''), 'LR', 0, 'L', $fill);
            $this->Ln();
            $fill = !$fill;
        }
        
        // Línea de cierre
        $this->Cell(array_sum($w), 0, '', 'T');
    }
}

// Procesar filtros del formulario
$filtro_tipo = $_GET['tipo'] ?? 'todos';
$filtro_sede = $_GET['sede'] ?? '';
$filtro_busqueda = $_GET['buscar'] ?? '';

$database = new Database();
$conn = $database->getConnection();

// Construir consulta con filtros
$query = "SELECT e.*, 
                 s.nombre as sede_nombre,
                 CONCAT(emp.first_Name, ' ', emp.first_LastName) as usuario_asignado_nombre,
                 CASE 
                     WHEN e.en_it = 1 AND e.estado_it = 'descompuesto' THEN 'Descompuesto'
                     WHEN e.en_it = 1 THEN 'En Mantenimiento'
                     ELSE 'Activo'
                 END as estado_display,
                 DATE_FORMAT(e.creado_en, '%d/%m/%Y') as creado_en_formatted,
                 e.creado_en as fecha_creacion
          FROM equipos e 
          LEFT JOIN sedes s ON e.sede_id = s.id
          LEFT JOIN employee emp ON e.usuario_asignado = emp.id 
          WHERE 1=1";

$params = [];

// Aplicar filtros según los botones
switch ($filtro_tipo) {
    case 'disponibles':
        $query .= " AND (e.usuario_asignado IS NULL OR e.usuario_asignado = '') AND e.en_it = 0";
        break;
    case 'en_mantenimiento':
        $query .= " AND e.en_it = 1 AND e.estado_it != 'descompuesto'";
        break;
    case 'descompuestos':
        $query .= " AND e.en_it = 1 AND e.estado_it = 'descompuesto'";
        break;
    case 'asignados':
        $query .= " AND (e.usuario_asignado IS NOT NULL AND e.usuario_asignado != '')";
        break;
    // 'todos' => sin filtro adicional
}

if ($filtro_sede) {
    $query .= " AND (e.sede_id = :sede_equipo)";
    $params[':sede_equipo'] = $filtro_sede;
}

if ($filtro_busqueda) {
    $query .= " AND (e.activo_fijo LIKE :busqueda OR e.serial_number LIKE :busqueda)";
    $params[':busqueda'] = '%' . $filtro_busqueda . '%';
}

$query .= " ORDER BY CAST(e.activo_fijo AS UNSIGNED), e.activo_fijo";

try {
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error en la consulta: " . $e->getMessage());
}

// Obtener sedes para el formulario
$query_sedes = "SELECT id, nombre FROM sedes ORDER BY nombre";
$stmt_sedes = $conn->prepare($query_sedes);
$stmt_sedes->execute();
$sedes = $stmt_sedes->fetchAll(PDO::FETCH_ASSOC);

// Si se solicita generar PDF
if (isset($_GET['generar_pdf'])) {
    try {
        // Crear PDF
        $pdf = new PDF('L');
        $pdf->AliasNbPages();
        $pdf->AddPage();
        
	// Título del reporte según filtros
	$titulo_reporte = "Reporte de Equipos - ";
	switch ($filtro_tipo) {
	    case 'disponibles': $titulo_reporte .= "Disponibles"; break;
	    case 'en_mantenimiento': $titulo_reporte .= "En Mantenimiento"; break;
	    case 'descompuestos': $titulo_reporte .= "Descompuestos"; break;
	    case 'asignados': $titulo_reporte .= "Asignados"; break;
	    default: $titulo_reporte .= "Todos los Equipos";
	}
	
	if ($filtro_sede) {
	    $sede_nombre = '';
	    foreach ($sedes as $sede) {
	        if ($sede['id'] == $filtro_sede) {
	            $sede_nombre = $sede['nombre'];
	            break;
	        }
	    }
	    $titulo_reporte .= " - Sede: " . $sede_nombre;
	}
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->CellUTF8(0, 10, $titulo_reporte, 0, 1, 'L');
        $pdf->Ln(5);
        
        // Cabeceras de la tabla con caracteres especiales
        $header = array('Activo Fijo', 'Marca', 'Modelo', 'Serial', 'Sede', 'Estado', 'Usuario Asignado', 'Fecha Creación', 'Antigüedad');
        
        // Generar tabla
        $pdf->ImprovedTable($header, $equipos);
        
        // Salida del PDF
        $pdf->Output('I', 'reporte_equipos_' . date('Ymd_His') . '.pdf');
        exit;
        
    } catch (Exception $e) {
        die("Error al generar PDF: " . $e->getMessage());
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Generar Informe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
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
    
    /* BOTONES EN CARD HEADERS */
    .card-header.bg-primary {
        background-color: #003a5d !important;
    }
    
    .card-header.bg-success {
        background-color: #198754 !important;
    }
    
    /* HOVER EN FILAS DE LA TABLA */
    .table-hover tbody tr:hover {
        background-color: #33617e !important;
        color: white !important;
    }
    
    /* ALERTAS */
    .alert-warning {
        background-color: #fff3cd !important;
        border-color: #ffc107 !important;
        color: #856404 !important;
    }
    
    /* Estilos específicos para esta página */
    .sidebar {
        position: fixed;
        top: 80px;
        bottom: 0;
        left: 0;
        z-index: 100;
        padding: 48px 0 0;
        box-shadow: inset -1px 0 0 rgba(0, 0, 0, .1);
        width: 250px;
    }
    
    main {
        margin-left: 250px;
        padding: 20px;
    }
    
    @media (max-width: 768px) {
        .sidebar {
            width: 100%;
            position: relative;
            top: 0;
        }
        main {
            margin-left: 0;
        }
    }
    .filtros-container {
    background-color: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 20px;
}

.filtro-group {
    margin-bottom: 10px;
}

.filtro-label {
    font-weight: 600;
    color: #353132;
    margin-bottom: 5px;
}

.tab-btn {
    padding: 8px 16px;
    margin-right: 5px;
    border: 1px solid #9d9d9c;
    background-color: #f8f9fa;
    cursor: pointer;
    border-radius: 4px;
    transition: all 0.3s ease;
    color: #353132;
}

.tab-btn.active {
    background-color: #003a5d;
    border-color: #003a5d;
    color: white;
    font-weight: bold;
}

.tab-btn:hover:not(.active) {
    background-color: #e9ecef;
    border-color: #003a5d;
}


/* Responsive */
@media (max-width: 768px) {
    .btn-group {
        flex-wrap: wrap;
    }
    
    .tab-btn {
        margin-bottom: 5px;
    }
    
}
</style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR - Solo si no está incluido en el header -->
            <?php 
            // Verificar si el sidebar ya fue incluido
            if (!defined('SIDEBAR_INCLUDED')) {
                define('SIDEBAR_INCLUDED', true);
                include '../includes/sidebar.php'; 
            }
            ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Generar Informe de Equipos</h1>
                </div>

                <!-- Filtros -->
		<div class="filtros-container">
		    <div class="row">
		        <div class="col-md-8">
		            <div class="filtro-group">
		                <div class="filtro-label">Estado del equipo</div>
		                <div class="btn-group" role="group">
		                    <button type="button" class="tab-btn <?php echo ($filtro_tipo == 'todos' || $filtro_tipo == '') ? 'active' : ''; ?>" data-tab="todos">
		                        <i class="bi bi-pc"></i> Todos
		                    </button>
		                    <button type="button" class="tab-btn <?php echo $filtro_tipo == 'disponibles' ? 'active' : ''; ?>" data-tab="disponibles">
		                        <i class="bi bi-check-circle"></i> Disponibles
		                    </button>
		                    <button type="button" class="tab-btn <?php echo $filtro_tipo == 'en_mantenimiento' ? 'active' : ''; ?>" data-tab="en_mantenimiento">
		                        <i class="bi bi-tools"></i> En Mantenimiento
		                    </button>
		                    <button type="button" class="tab-btn <?php echo $filtro_tipo == 'descompuestos' ? 'active' : ''; ?>" data-tab="descompuestos">
		                        <i class="bi bi-exclamation-triangle"></i> Descompuestos
		                    </button>
		                    <button type="button" class="tab-btn <?php echo $filtro_tipo == 'asignados' ? 'active' : ''; ?>" data-tab="asignados">
		                        <i class="bi bi-person-check"></i> Asignados
		                    </button>
		                </div>
		            </div>
		        </div>
		        <div class="col-md-4">
		            <div class="filtro-group">
		                <div class="filtro-label">Sede</div>
		                <select class="form-select" id="filtroSede" name="sede">
		                    <option value="">Todas las sedes</option>
		                    <?php foreach ($sedes as $sede): ?>
		                    <option value="<?= $sede['id'] ?>" <?= $filtro_sede == $sede['id'] ? 'selected' : '' ?>>
		                        <?= htmlspecialchars($sede['nombre']) ?>
		                    </option>
		                    <?php endforeach; ?>
		                </select>
		            </div>
		        </div>
		    </div>
		    
		    <div class="row mt-3">
		        <div class="col-12">
		            <?php if (count($equipos) > 0): ?>
		            <button type="submit" name="generar_pdf" value="1" class="btn btn-outline-success" form="formFiltros"
		                    onclick="document.getElementById('formFiltros').target='_blank';">
		                <i class="bi bi-file-earmark-pdf"></i> Generar PDF
		            </button>
		            <?php endif; ?>
		        </div>
		    </div>
		</div>
		
		<!-- Formulario oculto para manejar los filtros -->
		<form method="GET" action="" id="formFiltros" style="display: none;">
		    <input type="hidden" name="tipo" id="inputTipo" value="<?= $filtro_tipo ?>">
		    <input type="hidden" name="sede" id="inputSede" value="<?= $filtro_sede ?>">
		    <input type="hidden" name="buscar" id="inputBuscar" value="<?= htmlspecialchars($filtro_busqueda ?? '') ?>">
		</form>

                <!-- Vista previa de resultados -->
                <?php if (count($equipos) > 0): ?>
                <div class="card mt-4">
                    <div class="card-header text-black" style="background-color: #e9ecef;">
                        <h5 class="mb-0">Vista Previa del Informe</h5>
                        <small>Total: <?php echo count($equipos); ?> equipos encontrados</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm">
                                <thead>
                                    <tr>
                                        <th>Activo Fijo</th>
                                        <th>Marca</th>
                                        <th>Modelo</th>
                                        <th>Serial</th>
                                        <th>Sede</th>
                                        <th>Estado</th>
                                        <th>Usuario</th>
                                        <th>Fecha Creación</th>
                                        <th>Antigüedad</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($equipos as $equipo): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($equipo['activo_fijo']); ?></td>
                                        <td><?php echo htmlspecialchars($equipo['marca']); ?></td>
                                        <td><?php echo htmlspecialchars($equipo['modelo']); ?></td>
                                        <td><?php echo htmlspecialchars($equipo['serial_number']); ?></td>
                                        <td><?php echo htmlspecialchars($equipo['sede_nombre']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php 
                                                echo $equipo['estado_display'] == 'Activo' ? 'success' : 
                                                     ($equipo['estado_display'] == 'En Mantenimiento' ? 'warning' : 'danger'); 
                                            ?>">
                                                <?php echo $equipo['estado_display']; ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($equipo['usuario_asignado_nombre'] ?? 'Sin asignar'); ?></td>
                                        <td><?php echo $equipo['creado_en_formatted']; ?></td>
                                        <td><?php echo calcularAntiguedad($equipo['fecha_creacion']); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php elseif (isset($_GET['tipo'])): ?>
                <div class="alert alert-warning mt-4">
                    <i class="bi bi-exclamation-triangle"></i> No se encontraron equipos con los filtros seleccionados.
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
// Inicializar filtros
document.addEventListener('DOMContentLoaded', function() {
    inicializarFiltrosInforme();
});

function inicializarFiltrosInforme() {
    // Filtros por pestañas
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');
            
            // Actualizar el input hidden
            document.getElementById('inputTipo').value = this.getAttribute('data-tab');
            
            // Enviar formulario automáticamente
            document.getElementById('formFiltros').submit();
        });
    });

    // Filtro de sede - enviar al cambiar
    document.getElementById('filtroSede').addEventListener('change', function() {
        document.getElementById('inputSede').value = this.value;
        document.getElementById('formFiltros').submit();
    });

    // Buscador - enviar al presionar Enter
    document.getElementById('buscadorEquipos').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            document.getElementById('inputBuscar').value = this.value;
            document.getElementById('formFiltros').submit();
        }
    });

    // Botón de búsqueda (icono de lupa)
    document.querySelector('.input-group-text').addEventListener('click', function() {
        const buscador = document.getElementById('buscadorEquipos');
        document.getElementById('inputBuscar').value = buscador.value;
        document.getElementById('formFiltros').submit();
    });
}

// Función para manejar el botón de PDF
function prepararFormularioPDF() {
    document.getElementById('formFiltros').target = '_blank';
}

// Sincronizar valores de los campos visibles con los hidden
document.addEventListener('DOMContentLoaded', function() {
    // Sincronizar sede
    const sedeSelect = document.getElementById('filtroSede');
    const inputSede = document.getElementById('inputSede');
    if (sedeSelect && inputSede) {
        sedeSelect.value = inputSede.value;
    }
    
    // Sincronizar búsqueda
    const buscador = document.getElementById('buscadorEquipos');
    const inputBuscar = document.getElementById('inputBuscar');
    if (buscador && inputBuscar) {
        buscador.value = inputBuscar.value;
    }
});
</script>
</body>
</html>