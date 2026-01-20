<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';

$auth = new Auth();
$auth->redirectIfNotLoggedIn();

// Verificar permisos - ajusta según los roles que necesiten acceso
$allowed_roles = ['administrador', 'it', 'supervisor'];
if (!in_array($_SESSION['user_role'], $allowed_roles)) {
    header("Location: dashboard.php");
    exit();
}

$functions = new Functions();

// Obtener sedes para el filtro
$database = new Database();
$conn = $database->getConnection();
$query_sedes = "SELECT id, nombre FROM sedes ORDER BY nombre";
$stmt_sedes = $conn->prepare($query_sedes);
$stmt_sedes->execute();
$sedes = $stmt_sedes->fetchAll(PDO::FETCH_ASSOC);

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

$filtro_sede = $_GET['sede'] ?? '';

// Función auxiliar para generar URLs de paginación
function generarUrlPaginacion($pagina, $resultados_por_pagina) {
    $params = $_GET;
    $params['pagina'] = $pagina;
    $params['resultados_por_pagina'] = $resultados_por_pagina;
    return '?' . http_build_query($params);
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Keeper - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <style>
        /* ESTILOS ESPECÍFICOS PARA MONITOREO CON COLORES CORPORATIVOS */
        
        /* BOTONES - Colores corporativos */
        .btn-primary {
            background-color: #003a5d !important;
            border-color: #003a5d !important;
        }
        
        .btn-primary:hover {
            background-color: #002b47 !important;
            border-color: #002b47 !important;
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
        
        .btn-danger {
            background-color: #be1622 !important;
            border-color: #be1622 !important;
        }
        
        .btn-danger:hover {
            background-color: #a0121d !important;
            border-color: #a0121d !important;
        }
        
        /* BADGES - Colores corporativos */
        .badge.bg-success {
            background-color: #198754 !important;
        }
        
        .badge.bg-warning {
            background-color: #ffc107 !important;
            color: #353132 !important;
        }
        
        .badge.bg-danger {
            background-color: #be1622 !important;
        }
        
        .badge.bg-primary {
            background-color: #003a5d !important;
        }
        
        .badge.bg-secondary {
            background-color: #9d9d9c !important;
            color: #353132 !important;
        }
        
        .badge.bg-dark {
            background-color: #353132 !important;
        }
        
        .badge.bg-info {
            background-color: #003a5d !important;
            opacity: 0.8;
        }
        
        /* TABLAS */
        .table-hover tbody tr:hover {
            background-color: rgba(0, 58, 93, 0.05) !important;
        }
        
        .card-header {
            background-color: #003a5d !important;
            border-color: #003a5d;
        }
        
        /* Estilos específicos para monitoreo */
        .status-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 6px;
        }
        
        .status-online { background-color: #198754; }
        .status-trabajando { background-color: #198754; }
        .status-away { background-color: #ffc107; }
        .status-descanso { background-color: #ffc107; }
        .status-offline { background-color: #be1622; }
        .status-inactivo { background-color: #be1622; }
        
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
        
        .search-box {
            max-width: 300px;
        }
        
        .refresh-indicator {
            font-size: 0.875rem;
            color: #9d9d9c;
        }
        
        .last-updated {
            background-color: #f8f9fa;
            padding: 5px 10px;
            border-radius: 4px;
            font-size: 0.875rem;
            border: 1px solid #dee2e6;
            color: #353132;
        }
        
        /* Estilos específicos para la tabla de monitoreo */
        #tablaEstado {
            width: 100%;
            border-collapse: collapse;
        }
        
        #tablaEstado th, #tablaEstado td {
            border: 1px solid #dee2e6;
            padding: 8px 12px;
            text-align: left;
            vertical-align: middle;
        }
        
        #tablaEstado th {
            background-color: #003a5d;
            color: white;
            cursor: pointer;
            position: relative;
            font-weight: 600;
        }
        
        #tablaEstado th span {
            margin-left: 5px;
            font-size: 0.8em;
        }
        
        #tablaEstado tbody tr:hover {
            background-color: rgba(0, 58, 93, 0.04);
        }
        
        /* Ajustes de tamaño de columnas */
        .col-empleado { width: 20%; }
        .col-cargo { width: 15%; }
        .col-sede { width: 12%; }
        .col-trabajo { width: 10%; }
        .col-inactivo { width: 10%; }
        .col-ubicacion { width: 21%; }
        .col-estado { width: 12%; }
        
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
    </style>
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <?php include '../includes/sidebar.php'; ?>
            
            <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Monitoreo de Estado</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <span class="refresh-indicator">
                                <i class="bi bi-arrow-clockwise"></i> Actualizando cada 10 segundos
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Tarjeta de Monitoreo -->
                <div class="card">
                    <div class="card-header text-white">
                        <h5 class="card-title mb-0">
                            <img 
		            src="<?= ($current_page == 'keeper_list.php') 
		                ? '../assets/images/keeper_white.png' 
		                : '../assets/images/keeper_main.png' ?>" 
		            class="sidebar-icon-keeper" 
		            alt="Keeper Icon"> Estado Actual por Empleado
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Filtros -->
                        <div class="filtros-container">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="filtro-group">
                                        <div class="filtro-label">Estado</div>
                                        <div class="btn-group" role="group">
                                            <button type="button" class="tab-btn active" data-tab="todos">
                                                <i class="bi bi-people-fill"></i> Todos
                                            </button>
                                            <button type="button" class="tab-btn" data-tab="activos">
                                                <i class="bi bi-check-circle"></i> Activos
                                            </button>
                                            <button type="button" class="tab-btn" data-tab="inactivos">
                                                <i class="bi bi-x-circle"></i> Inactivos
                                            </button>
                                            <button type="button" class="tab-btn" data-tab="away">
                                                <i class="bi bi-clock"></i> Away
                                            </button>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="filtro-group">
                                        <div class="filtro-label">Sede</div>
                                        <select class="form-select" id="filtroSede" style="max-width: 300px;">
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
                            <div class="row mt-2">
                                <div class="col-md-12">
                                    <div class="filtro-group">
                                        <div class="filtro-label">Buscar empleado</div>
                                        <div class="input-group search-box">
                                            <input type="text" class="form-control" id="buscadorEstado" 
                                                   placeholder="Buscar por nombre...">
                                            <span class="input-group-text">
                                                <i class="bi bi-search"></i>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Contenedor de resultados -->
                        <div id="estado-actual">
                            <div class="text-center py-4">
                                <div class="spinner-border text-primary" role="status">
                                    <span class="visually-hidden">Cargando...</span>
                                </div>
                                <p class="mt-2">Cargando datos de monitoreo...</p>
                            </div>
                        </div>
                        
                        
                        <!-- Información de actualización -->
                        <div class="row mt-3">
                            <div class="col-12">
                                <div class="last-updated d-inline-block">
                                    <i class="bi bi-info-circle"></i>
                                    <span id="last-update-time">Última actualización: --:--:--</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Variables globales
    let filtroTab = "todos";
    let filtroSede = "";
    let columnaOrdenada = null;
    let esAscendente = true;
    let intervaloActualizacion = null;

    // Inicialización
    document.addEventListener('DOMContentLoaded', function() {
        inicializarFiltros();
        actualizarEstado();
        iniciarActualizacionAutomatica();
    });

    // Inicializar filtros
    function inicializarFiltros() {
        // Filtros por pestañas
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                filtroTab = btn.getAttribute('data-tab');
                actualizarEstado();
            });
        });

        // Filtro de sede
        document.getElementById("filtroSede").addEventListener("change", function() {
            filtroSede = this.value;
            actualizarEstado();
        });

        // Buscador
        document.getElementById("buscadorEstado").addEventListener("input", debounce(actualizarEstado, 300));
    }

    // Función debounce para optimizar búsquedas
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    // Actualizar estado
	function actualizarEstado(pagina = 1) {
	    const buscar = document.getElementById('buscadorEstado').value;
	    const sede = document.getElementById('filtroSede').value;
	    const resultados_por_pagina = document.getElementById('resultados_por_pagina') ? document.getElementById('resultados_por_pagina').value : 20;
	    
	    const url = `estado_actual.php?buscar=${encodeURIComponent(buscar)}&filtro=${filtroTab}&sede=${sede}&pagina=${pagina}&resultados_por_pagina=${resultados_por_pagina}&t=${Date.now()}`;
	
	    fetch(url)
	        .then(response => {
	            if (!response.ok) throw new Error('Error en la respuesta del servidor');
	            return response.text();
	        })
	        .then(html => {
	            document.getElementById("estado-actual").innerHTML = html;
	            actualizarTiempoUltimaActualizacion();
	            inicializarOrdenTabla();
	            
	            // Re-aplicar orden si existe
	            if (columnaOrdenada !== null) {
	                const th = document.querySelector(`#tablaEstado thead th:nth-child(${columnaOrdenada + 1})`);
	                if (th) {
	                    ordenarTablaPorColumna(columnaOrdenada, th);
	                }
	            }
	        })
	        .catch(err => {
	            console.error('Error al cargar estado:', err);
	            document.getElementById("estado-actual").innerHTML = `
	                <div class="alert alert-danger">
	                    <i class="bi bi-exclamation-triangle"></i> Error al cargar los datos de monitoreo.
	                    <button type="button" class="btn btn-sm btn-outline-primary ms-2" onclick="actualizarEstado()">
	                        Reintentar
	                    </button>
	                </div>`;
	        });
	}

    // Actualizar timestamp de última actualización
    function actualizarTiempoUltimaActualizacion() {
        const now = new Date();
        const timeString = now.toLocaleTimeString();
        document.getElementById('last-update-time').textContent = `Última actualización: ${timeString}`;
    }

    // Iniciar actualización automática
    function iniciarActualizacionAutomatica() {
        // Actualizar cada 10 segundos
        intervaloActualizacion = setInterval(actualizarEstado, 10000);
    }

    // Ordenamiento de tabla
    let ordenAscendente = [];

    function inicializarOrdenTabla() {
        const tabla = document.getElementById("tablaEstado");
        if (!tabla) return;

        const ths = tabla.querySelectorAll("thead th");
        ths.forEach((th, index) => {
            th.style.cursor = 'pointer';
            // Agregar span para ícono si no existe
            if (!th.querySelector('span')) {
                th.innerHTML += ' <span></span>';
            }
            th.onclick = () => ordenarTablaPorColumna(index, th);
        });
    }

    function ordenarTablaPorColumna(colIndex, thElemento) {
        const tabla = document.getElementById("tablaEstado");
        if (!tabla) return;

        const tbody = tabla.querySelector("tbody");
        const filas = Array.from(tbody.querySelectorAll("tr"));

        ordenAscendente[colIndex] = !ordenAscendente[colIndex];

        // Limpiar íconos de todas las columnas
        tabla.querySelectorAll("th span").forEach(span => span.textContent = '');

        // Agregar ícono a la columna actual
        const icono = ordenAscendente[colIndex] ? '🔼' : '🔽';
        const span = thElemento.querySelector("span");
        if (span) {
            span.textContent = icono;
        }

        filas.sort((a, b) => {
            let valorA = a.cells[colIndex].textContent.trim().toLowerCase();
            let valorB = b.cells[colIndex].textContent.trim().toLowerCase();

            const esTiempo = /^(\d{1,2}:)?\d{1,2}:\d{1,2}$/;
            if (esTiempo.test(valorA) && esTiempo.test(valorB)) {
                valorA = convertirTiempoASegundos(valorA);
                valorB = convertirTiempoASegundos(valorB);
            }

            if (valorA < valorB) return ordenAscendente[colIndex] ? -1 : 1;
            if (valorA > valorB) return ordenAscendente[colIndex] ? 1 : -1;
            return 0;
        });

        // Reinsertar filas ordenadas
        filas.forEach(fila => tbody.appendChild(fila));
        
        // Guardar estado de ordenamiento
        columnaOrdenada = colIndex;
        esAscendente = ordenAscendente[colIndex];
    }

    function convertirTiempoASegundos(hora) {
        const partes = hora.split(':');
        if (partes.length === 3) {
            return parseInt(partes[0]) * 3600 + parseInt(partes[1]) * 60 + parseInt(partes[2]);
        } else if (partes.length === 2) {
            return parseInt(partes[0]) * 60 + parseInt(partes[1]);
        }
        return 0;
    }

    // Limpiar intervalo al salir de la página
    window.addEventListener('beforeunload', function() {
        if (intervaloActualizacion) {
            clearInterval(intervaloActualizacion);
        }
    });
    
    </script>
</body>
</html>