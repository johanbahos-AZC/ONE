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

// Clase PDF con soporte UTF-8 para informe de documentos
class DocumentosPDF extends FPDF {
    // Variable para guardar las cabeceras
    private $tableHeaders = [];
    
    // Cabecera de página
    function Header() {
        $this->SetFont('Arial', 'B', 15);
        $title = 'INFORME DE DOCUMENTOS DE EMPLEADOS - ' . (defined('SITE_NAME') ? SITE_NAME : 'Sistema');
        $this->Cell(0, 10, $this->iconvSafe($title), 0, 1, 'C');
        $this->SetFont('Arial', '', 10);
        $this->Cell(0, 5, $this->iconvSafe('Generado el: ' . date('d/m/Y H:i')), 0, 1, 'C');
        $this->Ln(10);
        
        // Si hay cabeceras de tabla definidas, mostrarlas
        if (!empty($this->tableHeaders)) {
            $this->PrintTableHeaders();
        }
    }
    
    // Método para imprimir las cabeceras de la tabla
    function PrintTableHeaders() {
        // Anchos de las columnas
        $w = array(30, 5, 5, 6, 10, 9, 6, 10, 7, 9, 10, 10, 10, 7, 8, 8, 6, 10, 6, 18, 6, 7, 10, 12, 9, 7, 13, 12, 10, 10, 10);
        
        // Cabeceras
        $this->SetFillColor(59, 89, 152);
        $this->SetTextColor(255);
        $this->SetDrawColor(0, 0, 0);
        $this->SetLineWidth(.3);
        $this->SetFont('Arial', 'B', 6);
        
        for($i = 0; $i < count($this->tableHeaders); $i++)
            $this->CellUTF8($w[$i], 7, $this->tableHeaders[$i], 1, 0, 'C', true);
        $this->Ln();
        
        // Restaurar colores y fuente
        $this->SetFillColor(224, 235, 255);
        $this->SetTextColor(0);
        $this->SetFont('Arial', '', 7);
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
        
        // Reemplazar caracteres problemáticos antes de la conversión
        $text = str_replace(['✓', '✗', '○'], ['Sí', 'No', 'Opc'], $text);
        
        // Usar //IGNORE para ignorar caracteres no convertibles
        return iconv('UTF-8', 'windows-1252//IGNORE', $text);
    }

    // Método para escribir texto con UTF-8
    function CellUTF8($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '') {
        parent::Cell($w, $h, $this->iconvSafe($txt), $border, $ln, $align, $fill, $link);
    }

    // Tabla mejorada con soporte UTF-8
    function DocumentosTable($header, $data, $documentos_opcionales, $documentos_no_aplican_azc) {
        // Guardar las cabeceras para usarlas en cada página
        $this->tableHeaders = $header;
        
        // Anchos de las columnas (ajustados para cabecera de documento, incluyendo hoja_de_vida)
        $w = array(30, 5, 5, 6, 10, 9, 6, 10, 7, 9, 10, 10, 10, 7, 8, 8, 6, 10, 6, 18, 6, 7, 10, 12, 9, 7, 13, 12, 10, 10, 10);
        
        // Imprimir cabeceras
        $this->PrintTableHeaders();
        
        // Datos
        $fill = false;
        foreach($data as $row) {
            // Nombre del empleado (siempre sin relleno)
            $this->CellUTF8($w[0], 6, $row['nombre_completo'] ?? '', 'LR', 0, 'L', false);
            
            // Para cada documento, verificar si el empleado lo tiene (comenzando desde el índice 1)
            for($i = 1; $i < count($header); $i++) {
                $tipo_doc = $this->getTipoDocByIndex($i);
                
                // Determinar color según estado
                if (isset($documentos_no_aplican_azc[$row['company']]) && 
                    in_array($tipo_doc, $documentos_no_aplican_azc[$row['company']])) {
                    // No aplica para esta compañía (gris)
                    $this->SetFillColor(211, 211, 211); // Gris claro similar al CSS
                    $texto = 'N/A';
                } elseif (in_array($tipo_doc, $documentos_opcionales)) {
                    // Documento opcional (gris)
                    $this->SetFillColor(211, 211, 211); // Gris claro similar al CSS
                    $texto = isset($row['documentos'][$tipo_doc]) ? 'Sí' : 'No';
                } elseif (isset($row['documentos'][$tipo_doc])) {
                    // Tiene el documento (verde)
                    $this->SetFillColor(144, 238, 144); // Verde claro similar al CSS
                    $texto = 'Sí';
                } else {
                    // No tiene el documento (rojo)
                    $this->SetFillColor(255, 182, 193); // Rojo claro similar al CSS
                    $texto = 'No';
                }
                
                $this->CellUTF8($w[$i], 6, $texto, 'LR', 0, 'C', true);
            }
            
            $this->Ln();
            $fill = !$fill;
        }
        
        // Línea de cierre
        $this->Cell(array_sum($w), 0, '', 'T');
    }
    
    // Obtener tipo de documento por índice (ajustado, incluyendo hoja_de_vida)
    private function getTipoDocByIndex($index) {
        $tipos = [
            1 => 'hoja_de_vida',
            2 => 'copia_documento_de_identidad',
            3 => 'certificado_de_afiliacion_eps',
            4 => 'certificado_de_afiliacion_eps_empresa',
            5 => 'certificado_de_afiliacion_pension',
            6 => 'certificado_de_afiliacion_arl',
            7 => 'certificado_de_afiliacion_caja_de_compensacion',
            8 => 'tarjeta_profesional',
            9 => 'diploma_pregrado',
            10 => 'diploma_posgrado',
            11 => 'certificados_de_formacion',
            12 => 'contrato_laboral',
            13 => 'otro_si',
            14 => 'autorizacion_tratamiento_de_datos_personales',
            15 => 'sarlaft',
            16 => 'soporte_de_entrega_rit',
            17 => 'soporte_induccion',
            18 => 'acta_entrega_equipo_ti',
            19 => 'acuerdo_de_confidencialidad',
            20 => 'acta_de_autorizacion_de_descuento',
            21 => 'certificado_de_ingles',
            22 => 'certificado_de_cesantias',
            23 => 'contactos_de_emergencias',
            24 => 'certificado_bancario',
            25 => 'certificado_policia_nacional',
            26 => 'certificado_procuraduria',
            27 => 'certificado_contraloria',
            28 => 'certificado_vigencia',
            29 => 'certificado_laboral'
        ];
        
        return isset($tipos[$index]) ? $tipos[$index] : '';
    }
}

// Procesar filtros del formulario
 $filtro_company = $_GET['company'] ?? '';
 $filtro_role = $_GET['role'] ?? '';

 $database = new Database();
 $conn = $database->getConnection();

// Obtener compañías para el filtro
 $query_companies = "SELECT DISTINCT company FROM employee WHERE company IS NOT NULL ORDER BY company";
 $stmt_companies = $conn->prepare($query_companies);
 $stmt_companies->execute();
 $companies = $stmt_companies->fetchAll(PDO::FETCH_ASSOC);

// Obtener roles para el filtro
 $query_roles = "SELECT DISTINCT role FROM employee WHERE role IS NOT NULL ORDER BY role";
 $stmt_roles = $conn->prepare($query_roles);
 $stmt_roles->execute();
 $roles = $stmt_roles->fetchAll(PDO::FETCH_ASSOC);

// Definir documentos opcionales
 $documentos_opcionales = [
    'certificado_de_afiliacion_eps_empresa',
    'diploma_posgrado',
    'certificados_de_formacion',
    'otro_si',
    'certificado_vigencia'
];

// Definir documentos que no aplican para AZC (company = 1)
 $documentos_no_aplican_azc = [
    1 => ['acta_de_autorizacion_de_descuento', 'certificado_de_ingles']
];

// Construir consulta con filtros
 $query = "SELECT e.id, e.first_Name, e.first_LastName, e.second_Name, e.second_LastName, e.company, e.role
          FROM employee e 
          WHERE e.role != 'retirado' AND e.role != 'candidato'";

 $params = [];

if ($filtro_company) {
    $query .= " AND e.company = :company";
    $params[':company'] = $filtro_company;
}

if ($filtro_role) {
    $query .= " AND e.role = :role";
    $params[':role'] = $filtro_role;
}

 $query .= " ORDER BY e.first_Name, e.first_LastName";

try {
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();
    $empleados = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error en la consulta: " . $e->getMessage());
}

// Obtener documentos para cada empleado (incluyendo hoja_de_vida)
foreach ($empleados as &$empleado) {
    $empleado['documentos'] = [];
    
    $query_docs = "SELECT file_type FROM files WHERE id_employee = :employee_id";
    $stmt_docs = $conn->prepare($query_docs);
    $stmt_docs->bindParam(':employee_id', $empleado['id']);
    $stmt_docs->execute();
    $documentos_empleado = $stmt_docs->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($documentos_empleado as $doc) {
        $empleado['documentos'][$doc['file_type']] = true;
    }
    
    // Construir nombre completo con segundo nombre y segundo apellido abreviados
    $empleado['nombre_completo'] = trim($empleado['first_Name'] . ' ' . 
                                   (isset($empleado['second_Name']) && !empty($empleado['second_Name']) ? substr($empleado['second_Name'], 0, 1) . '.' : '') . ' ' . 
                                   $empleado['first_LastName'] . ' ' . 
                                   (isset($empleado['second_LastName']) && !empty($empleado['second_LastName']) ? substr($empleado['second_LastName'], 0, 1) . '.' : ''));
}

// Si se solicita generar PDF
if (isset($_GET['generar_pdf']) && $_GET['generar_pdf'] == 1) {
    try {
        // Crear PDF
        $pdf = new DocumentosPDF('L');
        $pdf->AliasNbPages();
        $pdf->AddPage();
        
        // Título del reporte según filtros
        $titulo_reporte = "Informe de Documentos de Empleados";
        
        if ($filtro_company) {
            $companias = [
                1 => 'AZC',
                2 => 'BilingueLaw', 
                3 => 'LawyerDesk',
                4 => 'AZC Legal',
                5 => 'Matiz LegalTech'
            ];
            $titulo_reporte .= " - Compañía: " . ($companias[$filtro_company] ?? $filtro_company);
        }
        
        if ($filtro_role) {
            $titulo_reporte .= " - Rol: " . ucfirst($filtro_role);
        }
        
        $pdf->SetFont('Arial', 'B', 12);
        $pdf->CellUTF8(0, 10, $titulo_reporte, 0, 1, 'L');
        $pdf->Ln(5);
        
        // Cabeceras de la tabla con caracteres especiales (incluyendo hoja_de_vida)
        $header = array(
            'Empleado', 
            'HV',
            'ID', 
            'EPS', 
            'EPS-EMP', 
            'Pensión', 
            'ARL', 
            'C. Comp.', 
            'T. Prof.', 
            'Diploma', 
            'Posgrado', 
            'Formación', 
            'Contrato', 
            'Otro Si', 
            'Datos', 
            'Sarlaft', 
            'RIT', 
            'Inducción', 
            'AF IT', 
            'Confidencialidad', 
            'Desc.', 
            'Inglés', 
            'Cesantías', 
            'Emergencia', 
            'Bancario', 
            'Policía', 
            'Procuraduria', 
            'Contraloría', 
            'Vigencia', 
            'C.Laboral'
        );
        
        // Generar tabla
        $pdf->DocumentosTable($header, $empleados, $documentos_opcionales, $documentos_no_aplican_azc);
        
        // Salida del PDF - abrir en nueva pestaña
        $pdf->Output('I', 'informe_documentos_' . date('Ymd_His') . '.pdf');
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
    <title>Generar Informe de Documentos</title>
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
        
        /* Estilos para la tabla de documentos */
        .doc-table {
            font-size: 0.8rem;
        }
        
        .doc-table th {
            font-size: 0.7rem;
            padding: 0.3rem;
            vertical-align: middle;
        }
        
        .doc-table td {
            padding: 0.3rem;
            vertical-align: middle;
        }
        
        .doc-status {
            text-align: center;
            font-weight: bold;
        }
        
        .doc-has {
            background-color: #90ee90 !important;
        }
        
        .doc-missing {
            background-color: #ffb6c1 !important;
        }
        
        .doc-optional {
            background-color: #d3d3d3 !important;
        }
        
        .doc-na {
            background-color: #d3d3d3 !important;
        }
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <!-- SIDEBAR -->
            <?php 
            // Verificar si el sidebar ya fue incluido
            if (!defined('SIDEBAR_INCLUDED')) {
                define('SIDEBAR_INCLUDED', true);
                include '../includes/sidebar.php'; 
            }
            ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Generar Informe de Documentos de Empleados</h1>
                </div>

                <!-- Filtros -->
                <div class="filtros-container">
                    <form method="GET" action="" id="formFiltros">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="filtro-group">
                                    <div class="filtro-label">Compañía</div>
                                    <select class="form-select" id="filtroCompany" name="company">
                                        <option value="">Todas las compañías</option>
                                        <?php foreach ($companies as $company): ?>
                                        <option value="<?= $company['company'] ?>" <?= $filtro_company == $company['company'] ? 'selected' : '' ?>>
                                            <?php 
                                            $companias = [
                                                1 => 'AZC',
                                                2 => 'BilingueLaw', 
                                                3 => 'LawyerDesk',
                                                4 => 'AZC Legal',
                                                5 => 'Matiz LegalTech'
                                            ];
                                            echo $companias[$company['company']] ?? $company['company']; 
                                            ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="filtro-group">
                                    <div class="filtro-label">Rol</div>
                                    <select class="form-select" id="filtroRole" name="role">
                                        <option value="">Todos los roles</option>
                                        <?php foreach ($roles as $role): ?>
                                        <option value="<?= $role['role'] ?>" <?= $filtro_role == $role['role'] ? 'selected' : '' ?>>
                                            <?= ucfirst($role['role']) ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-12">
                                <?php if (count($empleados) > 0): ?>
                                <button type="submit" name="generar_pdf" value="1" class="btn btn-success" 
                                        formtarget="_blank">
                                    <i class="bi bi-file-earmark-pdf"></i> Generar PDF
                                </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </form>
                </div>

                <!-- Vista previa de resultados -->
                <?php if (count($empleados) > 0): ?>
                <div class="card mt-4">
                    <div class="card-header text-black" style="background-color: #e9ecef;">
                        <h5 class="mb-0">Vista Previa del Informe</h5>
                        <small>Total: <?php echo count($empleados); ?> empleados encontrados</small>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped table-sm doc-table">
                                <thead>
                                    <tr>
                                        <th>Empleado</th>
                                        <th>Hoja de Vida</th>
                                        <th>Copia D.I.</th>
                                        <th>EPS</th>
                                        <th>EPS-EMP</th>
                                        <th>Pensión</th>
                                        <th>ARL</th>
                                        <th>C. Comp.</th>
                                        <th>T. Prof.</th>
                                        <th>Diploma</th>
                                        <th>Posgrado</th>
                                        <th>Formación</th>
                                        <th>Contrato</th>
                                        <th>Otro Si</th>
                                        <th>Datos</th>
                                        <th>Sarlaft</th>
                                        <th>RIT</th>
                                        <th>Inducción</th>
                                        <th>Equipo TI</th>
                                        <th>Confidencialidad</th>
                                        <th>Acta Desc.</th>
                                        <th>Inglés</th>
                                        <th>Cesantías</th>
                                        <th>Emergencia</th>
                                        <th>Bancario</th>
                                        <th>Policía</th>
                                        <th>Procuraduria</th>
                                        <th>Contraloría</th>
                                        <th>Vigencia</th>
                                        <th>Cert. Laboral</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($empleados as $empleado): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($empleado['nombre_completo']) ?></td>
                                        <td class="doc-status <?= isset($empleado['documentos']['hoja_de_vida']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['hoja_de_vida']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['copia_documento_de_identidad']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['copia_documento_de_identidad']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_de_afiliacion_eps']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_de_afiliacion_eps']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_de_afiliacion_eps_empresa']) ? 'doc-has' : 'doc-optional' ?>">
                                            <?= isset($empleado['documentos']['certificado_de_afiliacion_eps_empresa']) ? '✓' : '○' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_de_afiliacion_pension']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_de_afiliacion_pension']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_de_afiliacion_arl']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_de_afiliacion_arl']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_de_afiliacion_caja_de_compensacion']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_de_afiliacion_caja_de_compensacion']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['tarjeta_profesional']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['tarjeta_profesional']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['diploma_pregrado']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['diploma_pregrado']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['diploma_posgrado']) ? 'doc-has' : 'doc-optional' ?>">
                                            <?= isset($empleado['documentos']['diploma_posgrado']) ? '✓' : '○' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificados_de_formacion']) ? 'doc-has' : 'doc-optional' ?>">
                                            <?= isset($empleado['documentos']['certificados_de_formacion']) ? '✓' : '○' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['contrato_laboral']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['contrato_laboral']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['otro_si']) ? 'doc-has' : 'doc-optional' ?>">
                                            <?= isset($empleado['documentos']['otro_si']) ? '✓' : '○' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['autorizacion_tratamiento_de_datos_personales']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['autorizacion_tratamiento_de_datos_personales']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['sarlaft']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['sarlaft']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['soporte_de_entrega_rit']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['soporte_de_entrega_rit']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['soporte_induccion']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['soporte_induccion']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['acta_entrega_equipo_ti']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['acta_entrega_equipo_ti']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['acuerdo_de_confidencialidad']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['acuerdo_de_confidencialidad']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= 
                                            (isset($documentos_no_aplican_azc[$empleado['company']]) && 
                                             in_array('acta_de_autorizacion_de_descuento', $documentos_no_aplican_azc[$empleado['company']])) ? 'doc-na' : 
                                            (isset($empleado['documentos']['acta_de_autorizacion_de_descuento']) ? 'doc-has' : 'doc-missing') ?>">
                                            <?= 
                                            (isset($documentos_no_aplican_azc[$empleado['company']]) && 
                                             in_array('acta_de_autorizacion_de_descuento', $documentos_no_aplican_azc[$empleado['company']])) ? 'N/A' : 
                                            (isset($empleado['documentos']['acta_de_autorizacion_de_descuento']) ? '✓' : '✗') ?>
                                        </td>
                                        <td class="doc-status <?= 
                                            (isset($documentos_no_aplican_azc[$empleado['company']]) && 
                                             in_array('certificado_de_ingles', $documentos_no_aplican_azc[$empleado['company']])) ? 'doc-na' : 
                                            (isset($empleado['documentos']['certificado_de_ingles']) ? 'doc-has' : 'doc-missing') ?>">
                                            <?= 
                                            (isset($documentos_no_aplican_azc[$empleado['company']]) && 
                                             in_array('certificado_de_ingles', $documentos_no_aplican_azc[$empleado['company']])) ? 'N/A' : 
                                            (isset($empleado['documentos']['certificado_de_ingles']) ? '✓' : '✗') ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_de_cesantias']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_de_cesantias']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['contactos_de_emergencias']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['contactos_de_emergencias']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_bancario']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_bancario']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_policia_nacional']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_policia_nacional']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_procuraduria']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_procuraduria']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_contraloria']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_contraloria']) ? '✓' : '✗' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_vigencia']) ? 'doc-has' : 'doc-optional' ?>">
                                            <?= isset($empleado['documentos']['certificado_vigencia']) ? '✓' : '○' ?>
                                        </td>
                                        <td class="doc-status <?= isset($empleado['documentos']['certificado_laboral']) ? 'doc-has' : 'doc-missing' ?>">
                                            <?= isset($empleado['documentos']['certificado_laboral']) ? '✓' : '✗' ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <div class="mt-3">
                            <small class="text-muted">
                                <strong>Leyenda:</strong> 
                                <span class="badge bg-success">✓ Tiene</span> | 
                                <span class="badge bg-danger">✗ No tiene</span> | 
                                <span class="badge bg-secondary">○ Opcional</span> | 
                                <span class="badge bg-secondary">N/A No aplica</span>
                            </small>
                        </div>
                    </div>
                </div>
                <?php elseif (isset($_GET['company']) || isset($_GET['role'])): ?>
                <div class="alert alert-warning mt-4">
                    <i class="bi bi-exclamation-triangle"></i> No se encontraron empleados con los filtros seleccionados.
                </div>
                <?php endif; ?>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        inicializarFiltrosInforme();
    });

    function inicializarFiltrosInforme() {
        // Filtro de compañía - enviar al cambiar
        document.getElementById('filtroCompany').addEventListener('change', function() {
            document.getElementById('formFiltros').submit();
        });

        // Filtro de rol - enviar al cambiar
        document.getElementById('filtroRole').addEventListener('change', function() {
            document.getElementById('formFiltros').submit();
        });
    }
    </script>
</body>
</html>