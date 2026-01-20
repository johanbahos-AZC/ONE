<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/validar_permiso.php';
require_once '../includes/PlanoSedeManager.php';

 $auth = new Auth();
 $auth->redirectIfNotLoggedIn();

 $functions = new Functions();
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

// Verificar permiso para ver la página de planos
if (!tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'planos', 'ver')) {
    header("Location: portal.php");
    exit();
}

// Verificar permisos específicos para las acciones
 $permiso_editar_plano = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'planos', 'editar_plano');
 $permiso_asignar_mesas = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'planos', 'asignar_mesas');

// Inicializar el gestor de planos
 $planoManager = new PlanoSedeManager($database);

// Obtener parámetros
 $sede_id = isset($_GET['sede_id']) ? (int)$_GET['sede_id'] : 0;
 $oficina = isset($_GET['oficina']) ? $_GET['oficina'] : null;
 $piso_id = isset($_GET['piso_id']) ? (int)$_GET['piso_id'] : 0;

// Validar que se proporcionó una sede
if (!$sede_id) {
    header("Location: planos_sedes.php");
    exit();
}

// Obtener información de la sede
 $sede = $planoManager->obtenerSede($sede_id);
if (!$sede) {
    header("Location: planos_sedes.php");
    exit();
}

// Obtener pisos de la sede (filtrados por oficina si aplica)
 $pisos = $planoManager->obtenerPisosPorSede($sede_id, $oficina);

// Si no se especificó un piso, usar el primero
if (!$piso_id && !empty($pisos)) {
    $piso_id = $pisos[0]['id'];
}

// Validar que el piso pertenezca a la sede
 $piso_actual = null;
foreach ($pisos as $piso) {
    if ($piso['id'] == $piso_id) {
        $piso_actual = $piso;
        break;
    }
}

if (!$piso_actual) {
    header("Location: planos_sedes.php");
    exit();
}

// Obtener tipos de mesa
 $tipos_mesa = $planoManager->obtenerTiposMesa();

// Obtener estadísticas de la sede/oficina
if ($oficina) {
    $estadisticas = $planoManager->obtenerEstadisticasOficina($sede_id, $oficina);
} else {
    $estadisticas = $planoManager->obtenerEstadisticasSede($sede_id);
}

// Obtener datos del plano
 $mesas = $planoManager->obtenerMesasPorPiso($piso_id);
 $elementos_plano = $planoManager->obtenerElementosPlano($piso_id);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Plano de Sede - <?php echo htmlspecialchars($sede['nombre'] . ($oficina ? ' - ' . $oficina : '')); ?> - <?php echo SITE_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link rel="icon" href="/assets/images/favicon.ico" type="image/x-icon">
    <!-- Fabric.js -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/fabric.js/5.3.0/fabric.min.js"></script>
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
        
        /* Estilos específicos para esta página */
        .canvas-container {
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 20px;
            background-color: #f8f9fa;
            position: relative;
            overflow: hidden;
        }
        
        .toolbar {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 20px;
        }
        
        .toolbar .btn {
            margin-right: 5px;
            margin-bottom: 5px;
        }
        
        .piso-selector {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
            margin-bottom: 20px;
        }
        
        .piso-tab {
            cursor: pointer;
            padding: 8px 15px;
            margin-right: 5px;
            border-radius: 5px 5px 0 0;
            background-color: #e9ecef;
            border: 1px solid #ddd;
            border-bottom: none;
        }
        
        .piso-tab.active {
            background-color: #003a5d;
            color: white;
            border-color: #003a5d;
        }
        
        .piso-tab:hover:not(.active) {
            background-color: #dee2e6;
        }
        
        .stats-card {
            background-color: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        .stats-number {
            font-size: 1.5rem;
            font-weight: bold;
            color: #003a5d;
        }
        
        .stats-label {
            font-size: 0.8rem;
            color: #6c757d;
        }
        
        .mesa-item {
            padding: 5px 10px;
            margin-bottom: 5px;
            border-radius: 5px;
            cursor: pointer;
            border: 1px solid #ddd;
        }
        
        .mesa-item:hover {
            background-color: #f8f9fa;
        }
        
        .mesa-item.ocupada {
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        
        .mesa-item.selected {
            background-color: #cce5ff;
            border-color: #003a5d;
        }
        
        .mesa-legend {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .mesa-legend-item {
            display: flex;
            align-items: center;
            font-size: 0.85rem;
        }
        
        .mesa-legend-color {
            width: 20px;
            height: 20px;
            border-radius: 3px;
            margin-right: 5px;
            border: 1px solid #ddd;
        }
        
        .employee-list {
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 10px;
        }
        
        .employee-item {
            padding: 8px;
            margin-bottom: 5px;
            border-radius: 5px;
            cursor: pointer;
            border: 1px solid #ddd;
        }
        
        .employee-item:hover {
            background-color: #f8f9fa;
        }
        
        .employee-item.selected {
            background-color: #d1ecf1;
            border-color: #bee5eb;
        }
        
        .modal-body .canvas-container {
            height: 400px;
            margin-bottom: 10px;
        }
        
        .properties-panel {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 20px;
        }
        
        .properties-panel h5 {
            margin-bottom: 15px;
            color: #003a5d;
        }
        
        .element-tools {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .element-tool {
            padding: 8px 12px;
            background-color: #e9ecef;
            border: 1px solid #ddd;
            border-radius: 5px;
            cursor: pointer;
            text-align: center;
            min-width: 80px;
        }
        
        .element-tool:hover {
            background-color: #dee2e6;
        }
        
        .element-tool.active {
            background-color: #003a5d;
            color: white;
            border-color: #003a5d;
        }
        
        .mesa-type-selector {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .mesa-type-btn {
            padding: 8px 12px;
            background-color: #e9ecef;
            border: 1px solid #ddd;
            border-radius: 5px;
            cursor: pointer;
            text-align: center;
            min-width: 100px;
            position: relative;
        }
        
        .mesa-type-btn:hover {
            background-color: #dee2e6;
        }
        
        .mesa-type-btn.active {
            background-color: #003a5d;
            color: white;
            border-color: #003a5d;
        }
        
        .mesa-type-color {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 12px;
            height: 12px;
            border-radius: 50%;
            border: 1px solid #ddd;
        }
        
        .muro-guide {
            position: absolute;
            background-color: rgba(0, 58, 93, 0.2);
            pointer-events: none;
            z-index: 1000;
        }
        
        .mesa-guide {
            position: absolute;
            border: 1px dashed rgba(0, 58, 93, 0.3);
            pointer-events: none;
            z-index: 999;
        }
        
        .snap-point {
            position: absolute;
            width: 10px;
            height: 10px;
            border-radius: 50%;
            background-color: #003a5d;
            transform: translate(-50%, -50%);
            pointer-events: none;
            z-index: 1001;
        }
        
        .edit-tipo-mesa-form {
            background-color: #f8f9fa;
            border: 1px solid #ddd;
            border-radius: 5px;
            padding: 15px;
            margin-bottom: 15px;
        }
        /* Ajustes para el modo edición */
	#contenedor-plano {
	    transition: all 0.3s ease;
	}
	
	/* Asegurar que el contenedor del lienzo se comporte correctamente */
	#contenedor-lienzo {
	    transition: all 0.3s ease;
	    min-height: 700px; /* Altura mínima para evitar colapsos */
	}
	
	#panel-lateral {
	    transition: all 0.3s ease;
	}
	
	/* Ajustes para el canvas */
	.canvas-container {
	    width: 100%;
	    margin: 0 auto;
	}
	
	/* Asegurar que el canvas se ajuste correctamente */
	canvas {
	    max-width: 100%;
	    height: auto !important;
	}
	
	/* Ajustes para el modo edición */
	.modo-edicion #panel-lateral {
	    display: none !important;
	}
	
	.modo-edicion #contenedor-lienzo {
	    flex: 1;
	    max-width: 100%;
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
                    <h1 class="h2">
                        Plano de Sede - 
                        <span class="text-primary"><?php echo htmlspecialchars($sede['nombre']); ?></span>
                        <?php if ($oficina): ?>
                            <span class="text-secondary">- <?php echo htmlspecialchars($oficina); ?></span>
                        <?php endif; ?>
                    </h1>
                    <div>
                        <a href="planos_sedes.php" class="btn btn-outline-secondary me-2">
                            <i class="bi bi-arrow-left"></i> Volver a Sedes
                        </a>
                        <?php if ($permiso_editar_plano): ?>
                        <button type="button" class="btn btn-primary" id="btn_editar_plano">
                            <i class="bi bi-pencil"></i> Editar Plano
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Estadísticas de la sede/oficina -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?php echo $estadisticas['total_mesas']; ?></div>
                            <div class="stats-label">Total de Estaciones</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?php echo $estadisticas['mesas_disponibles']; ?></div>
                            <div class="stats-label">Estaciones Disponibles</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?php echo $estadisticas['mesas_ocupadas']; ?></div>
                            <div class="stats-label">Estaciones Ocupadas</div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="stats-card">
                            <div class="stats-number"><?php echo $estadisticas['tasa_utilizacion']; ?>%</div>
                            <div class="stats-label">Tasa de Utilización</div>
                        </div>
                    </div>
                </div>
                
		<!-- Selector de pisos -->
		<div class="piso-selector">
		    <div class="d-flex flex-wrap align-items-center">
		        <div class="d-flex flex-wrap" id="piso-tabs">
		            <?php foreach ($pisos as $piso): ?>
		            <div class="piso-tab <?php echo $piso['id'] == $piso_id ? 'active' : ''; ?>" 
		                 onclick="cambiarPiso(<?php echo $piso['id']; ?>)">
		                Piso <?php echo $piso['numero']; ?>
		                <?php if ($piso['oficina']): ?>
		                    <small>(<?php echo htmlspecialchars($piso['oficina']); ?>)</small>
		                <?php endif; ?>
		            </div>
		            <?php endforeach; ?>
		        </div>
		        <button type="button" class="btn btn-sm btn-outline-primary ms-2" id="btn_agregar_piso" style="display: none;" onclick="agregarNuevoPiso()">
		            <i class="bi bi-plus-circle"></i> 
		        </button>
		    </div>
		</div>
                
		<div class="row" id="contenedor-plano">
		    <!-- Lienzo del plano -->
		    <div class="col-md-8" id="contenedor-lienzo">
		        <!-- Barra de herramientas -->
		        <div class="toolbar" id="toolbar" style="display: none;">
                            <div class="mb-2">
                                <strong>Herramientas de Edición:</strong>
                            </div>
                            <div class="element-tools">
                                <div class="element-tool" data-tool="select" onclick="setTool('select')">
                                    <i class="bi bi-cursor"></i> Seleccionar
                                </div>
                                <div class="element-tool" data-tool="mesa" onclick="setTool('mesa')">
                                    <i class="bi bi-square"></i> Mesa
                                </div>
                                <div class="element-tool" data-tool="muro" onclick="setTool('muro')">
                                    <i class="bi bi-dash-lg"></i> Muro
                                </div>
                                <div class="element-tool" data-tool="eliminar" onclick="setTool('eliminar')">
                                    <i class="bi bi-trash"></i> Eliminar
                                </div>
                            </div>
                            
                            <!-- Selector de tipos de mesa -->
                            <div id="mesa-type-selector-container" style="display: none;">
                                <div class="mb-2">
                                    <strong>Tipo de Mesa:</strong>
                                </div>
                                <div class="mesa-type-selector" id="mesa-type-selector">
                                    <?php foreach ($tipos_mesa as $tipo): ?>
                                    <div class="mesa-type-btn" data-tipo-id="<?php echo $tipo['id']; ?>" 
                                         data-color="<?php echo $tipo['color']; ?>"
                                         data-ancho="<?php echo $tipo['ancho']; ?>"
                                         data-alto="<?php echo $tipo['alto']; ?>"
                                         onclick="selectMesaType(<?php echo $tipo['id']; ?>)">
                                        <?php echo htmlspecialchars($tipo['nombre']); ?>
                                        <div class="mesa-type-color" style="background-color: <?php echo $tipo['color']; ?>;"></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
				<div class="mt-2">
				    <button type="button" class="btn btn-sm btn-outline-primary" onclick="mostrarModalTiposMesa()">
				        <i class="bi bi-gear"></i> Editar Tipos de Mesa
				    </button>
				    <button type="button" class="btn btn-sm btn-success" onclick="mostrarFormularioNuevoTipoMesaDirecto()">
				        <i class="bi bi-plus-circle"></i> Nuevo Tipo de Mesa
				    </button>
				</div>
				<div class="row">
                                    <div class="col-md-6">
                                        <label for="mesa_numero" class="form-label">Número de Mesa:</label>
                                        <input type="text" class="form-control form-control-sm" id="mesa_numero" placeholder="Ej: M-001">
                                    </div>
                                </div>
                            </div>
                            
                            
                            <div class="mt-3">
                                <button type="button" class="btn btn-success btn-sm" onclick="guardarPlano()">
                                    <i class="bi bi-save"></i> Guardar Cambios
                                </button>
                                <button type="button" class="btn btn-warning btn-sm" onclick="cancelarEdicion()">
                                    <i class="bi bi-x-circle"></i> Cancelar
                                </button>
                                <button type="button" class="btn btn-info btn-sm" onclick="cambiarTamanoCanvas()">
                                    <i class="bi bi-aspect-ratio"></i> Cambiar Tamaño
                                </button>
                            </div>
                        </div>
                        
			<!-- Lienzo -->
		        <div class="canvas-container">
		            <canvas id="plano-canvas"></canvas>
		        </div>
		        
		        <!-- Leyenda de tipos de mesa -->
		        <div class="mesa-legend">
		            <div class="fw-bold">Leyenda:</div>
		            <?php foreach ($tipos_mesa as $tipo): ?>
		            <div class="mesa-legend-item">
		                <div class="mesa-legend-color" style="background-color: <?php echo $tipo['color']; ?>;"></div>
		                <div><?php echo htmlspecialchars($tipo['nombre']); ?></div>
		            </div>
		            <?php endforeach; ?>
		        </div>
		    </div>
		    
		    <!-- Panel lateral -->
		    <div class="col-md-4" id="panel-lateral">
		        <!-- Lista de mesas -->
		        <div class="properties-panel">
		            <h5>Lista de Mesas</h5>
		            <div class="mb-3">
		                <input type="text" class="form-control form-control-sm" id="buscar_mesa" placeholder="Buscar mesa...">
		            </div>
		            <div id="lista_mesas" style="max-height: 150px; overflow-y: auto;">
		                <!-- Se llenará dinámicamente -->
		            </div>
		        </div>
		        
		        <!-- Panel de asignación de empleados -->
		        <?php if ($permiso_asignar_mesas): ?>
		        <div class="properties-panel">
		            <h5>Asignación de Empleados</h5>
		            <div class="mb-3">
		                <div class="d-flex justify-content-between align-items-center mb-2">
		                    <span>Mesa Seleccionada:</span>
		                    <span id="mesa_seleccionada" class="badge bg-primary">Ninguna</span>
		                </div>
		                <div class="d-flex justify-content-between align-items-center mb-2">
		                    <span>Empleado Asignado:</span>
		                    <span id="empleado_asignado" class="badge bg-success">Ninguno</span>
		                </div>
		            </div>
		            <div class="mb-3">
		                <input type="text" class="form-control form-control-sm" id="buscar_empleado" placeholder="Buscar empleado...">
		            </div>
		            <div class="employee-list" id="lista_empleados" style="max-height: 300px; overflow-y: auto;">
		                <!-- Se llenará dinámicamente -->
		            </div>
		            <div class="mt-3">
		                <button type="button" class="btn btn-primary btn-sm w-100" id="btn_asignar_empleado" disabled>
		                    <i class="bi bi-person-plus"></i> Asignar Empleado
		                </button>
		                <button type="button" class="btn btn-danger btn-sm w-100 mt-2" id="btn_desasignar_empleado" disabled>
		                    <i class="bi bi-person-dash"></i> Desasignar Empleado
		                </button>
		            </div>
		        </div>
		        <?php endif; ?>
		    </div>
		</div>
            </main>
        </div>
    </div>
    
	<!-- Modal para cambiar tamaño del lienzo -->
	<div class="modal fade" id="modalTamanoCanvas" tabindex="-1">
	    <div class="modal-dialog">
	        <div class="modal-content">
	            <div class="modal-header">
	                <h5 class="modal-title">Cambiar Tamaño del Lienzo</h5>
	                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
	            </div>
	            <div class="modal-body">
	                <div class="mb-3">
	                    <label for="canvas_ancho" class="form-label">Ancho (px)</label>
	                    <input type="number" class="form-control" id="canvas_ancho" min="500" max="2000" value="1500">
	                </div>
	                <div class="mb-3">
	                    <label for="canvas_alto" class="form-label">Alto (px)</label>
	                    <input type="number" class="form-control" id="canvas_alto" min="400" max="1500" value="1500">
	                </div>
	            </div>
	            <div class="modal-footer">
	                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
	                <button type="button" class="btn btn-primary" onclick="aplicarTamanoCanvas()">Aplicar</button>
	            </div>
	        </div>
	    </div>
	</div>
    
    <!-- Modal para gestión de tipos de mesa -->
    <div class="modal fade" id="modalTiposMesa" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Gestión de Tipos de Mesa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <button type="button" class="btn btn-primary" onclick="mostrarFormularioNuevoTipoMesa()">
                            <i class="bi bi-plus-circle"></i> Agregar Nuevo Tipo
                        </button>
                    </div>
                    
                    <div id="formulario_tipo_mesa" style="display: none;">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0">Nuevo Tipo de Mesa</h6>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label for="nuevo_tipo_nombre" class="form-label">Nombre</label>
                                    <input type="text" class="form-control" id="nuevo_tipo_nombre">
                                </div>
                                <div class="mb-3">
                                    <label for="nuevo_tipo_color" class="form-label">Color</label>
                                    <input type="color" class="form-control" id="nuevo_tipo_color" value="#003a5d">
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="nuevo_tipo_ancho" class="form-label">Ancho (px)</label>
                                            <input type="number" class="form-control" id="nuevo_tipo_ancho" value="80">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="nuevo_tipo_alto" class="form-label">Alto (px)</label>
                                            <input type="number" class="form-control" id="nuevo_tipo_alto" value="60">
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-end">
                                    <button type="button" class="btn btn-secondary me-2" onclick="ocultarFormularioNuevoTipoMesa()">Cancelar</button>
                                    <button type="button" class="btn btn-primary" onclick="guardarNuevoTipoMesa()">Guardar</button>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Nombre</th>
                                    <th>Color</th>
                                    <th>Dimensiones</th>
                                    <th>Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="tabla_tipos_mesa">
                                <!-- Se llenará dinámicamente -->
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cerrar</button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Modal para editar tipo de mesa -->
    <div class="modal fade" id="modalEditarTipoMesa" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Editar Tipo de Mesa</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_tipo_nombre" class="form-label">Nombre</label>
                        <input type="text" class="form-control" id="edit_tipo_nombre">
                    </div>
                    <div class="mb-3">
                        <label for="edit_tipo_color" class="form-label">Color</label>
                        <input type="color" class="form-control" id="edit_tipo_color">
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_tipo_ancho" class="form-label">Ancho (px)</label>
                                <input type="number" class="form-control" id="edit_tipo_ancho">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="edit_tipo_alto" class="form-label">Alto (px)</label>
                                <input type="number" class="form-control" id="edit_tipo_alto">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="guardarCambiosTipoMesa()">Guardar Cambios</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variables globales
        let canvas;
        let modoEdicion = false;
        let herramientaActual = 'select';
        let mesaSeleccionada = null;
        let empleadoSeleccionado = null;
        let mesas = [];
        let elementosPlano = [];
        let puntosMuro = [];
        let puntosMuroTemporal = [];
        let tipoMesaSeleccionado = null;
        let snapThreshold = 15; // Umbral para el "imán" de alineación
        let muroSnapThreshold = 10; // Umbral para el "imán" de muros
        let isDragging = false;
        let isDrawingMuro = false;
        let lastMuroPoint = null;
        let guiasAlineacion = [];
        let editandoTipoMesa = false;
        
        // Inicializar el lienzo cuando se carga la página
        document.addEventListener('DOMContentLoaded', function() {
            inicializarCanvas();
            cargarDatosPlano();
            cargarListaMesas();
            cargarEmpleados();
            
            // Event listeners
            document.getElementById('btn_editar_plano').addEventListener('click', toggleModoEdicion);
            document.getElementById('btn_asignar_empleado').addEventListener('click', asignarEmpleado);
            document.getElementById('btn_desasignar_empleado').addEventListener('click', desasignarEmpleado);
            document.getElementById('buscar_mesa').addEventListener('input', filtrarMesas);
            document.getElementById('buscar_empleado').addEventListener('input', filtrarEmpleados);
        });
        
	// Función para inicializar el lienzo cuando se carga la página
	function inicializarCanvas() {
	    // Obtener las dimensiones del contenedor
	    const container = document.getElementById('contenedor-lienzo');
	    const containerWidth = container ? container.clientWidth - 30 : 770; // Ancho por defecto
	    const containerHeight = 600; // Altura por defecto
	    
	    // Por ahora, usamos un tamaño fijo de 1500x1500
	    // Más adelante implementaremos la carga desde la base de datos
	    const canvasWidth = 1500;
	    const canvasHeight = 1500;
	    
	    canvas = new fabric.Canvas('plano-canvas', {
	        width: canvasWidth,
	        height: canvasHeight,
	        backgroundColor: '#ffffff',
	        selection: modoEdicion,
	        textBaseline: 'middle'
	    });
	    
	    // Aplicar zoom automático inicial
	    aplicarZoomAutomatico();
	    
	    // Event listeners del canvas
	    canvas.on('mouse:down', handleCanvasMouseDown);
	    canvas.on('mouse:up', handleCanvasMouseUp);
	    canvas.on('mouse:move', handleCanvasMouseMove);
	    canvas.on('selection:created', handleSelectionCreated);
	    canvas.on('selection:updated', handleSelectionUpdated);
	    canvas.on('selection:cleared', handleSelectionCleared);
	    canvas.on('object:moving', handleObjectMoving);
	    canvas.on('object:modified', handleObjectModified);
	    
	    // Cargar los datos del plano después de inicializar el canvas
	    cargarDatosPlano();
	}
	
	//función para aplicar zoom automático
	function aplicarZoomAutomatico() {
	    const container = document.getElementById('contenedor-lienzo');
	    if (!container || !canvas) return;
	    
	    // Obtener las dimensiones del contenedor
	    const containerWidth = container.clientWidth - 30; // Restar padding
	    const containerHeight = 900; // Altura fija
	    
	    // Calcular el zoom necesario para que el canvas quepa en el contenedor
	    const scaleX = containerWidth / canvas.getWidth();
	    const scaleY = containerHeight / canvas.getHeight();
	    
	    // Usar el zoom más pequeño para asegurar que todo el canvas sea visible
	    const zoom = Math.min(scaleX, scaleY);
	    
	    // Aplicar el zoom
	    canvas.setZoom(zoom);
	    
	    // Centrar el canvas en el contenedor
	    const canvasContainer = document.querySelector('.canvas-container');
	    if (canvasContainer) {
	        canvasContainer.style.width = containerWidth + 'px';
	        canvasContainer.style.height = containerHeight + 'px';
	        canvasContainer.style.overflow = 'hidden';
	        canvasContainer.style.margin = '0 auto'; // Centrar horizontalmente
	    }
	    
	    // Forzar un redibujado
	    canvas.renderAll();
	}
        
        // Función para cargar los datos del plano
	function cargarDatosPlano() {
	    // Cargar mesas
	    fetch(`../includes/obtener_plano.php?piso_id=<?php echo $piso_id; ?>&tipo=mesas`)
	        .then(response => response.json())
	        .then(data => {
	            if (data.success) {
	                mesas = data.mesas;
	                dibujarMesas();
	                cargarListaMesas(); // Mover la llamada aquí
	                
	                // Aplicar zoom automático después de cargar las mesas
	                setTimeout(() => {
	                    aplicarZoomAutomatico();
	                }, 100);
	            }
	        })
	        .catch(error => {
	            console.error('Error al cargar las mesas:', error);
	        });
	    
	    // Cargar elementos del plano (muros)
	    fetch(`../includes/obtener_plano.php?piso_id=<?php echo $piso_id; ?>&tipo=elementos`)
	        .then(response => response.json())
	        .then(data => {
	            if (data.success) {
	                elementosPlano = data.elementos.filter(e => e.tipo === 'muro'); // Solo muros
	                dibujarElementosPlano();
	                
	                // Aplicar zoom automático después de cargar los elementos
	                setTimeout(() => {
	                    aplicarZoomAutomatico();
	                }, 100);
	            }
	        })
	        .catch(error => {
	            console.error('Error al cargar los elementos del plano:', error);
	        });
	}
	
	// Agrega un listener para aplicar el zoom automático cuando la ventana cambie de tamaño
	let resizeTimeout;
	window.addEventListener('resize', function() {
	    clearTimeout(resizeTimeout);
	    resizeTimeout = setTimeout(function() {
	        aplicarZoomAutomatico();
	    }, 250);
	});
	
	// --- SISTEMA DE TOOLTIP DE INSTANCIA ÚNICA ---

	// Crear el tooltip global una sola vez
	const globalTooltip = document.createElement('div');
	globalTooltip.id = 'global-tooltip';
	globalTooltip.style.position = 'fixed';
	globalTooltip.style.backgroundColor = 'rgba(0, 0, 0, 0.85)';
	globalTooltip.style.color = 'white';
	globalTooltip.style.padding = '8px 12px';
	globalTooltip.style.borderRadius = '6px';
	globalTooltip.style.fontSize = '13px';
	globalTooltip.style.zIndex = '9999';
	globalTooltip.style.pointerEvents = 'none';
	globalTooltip.style.opacity = '0';
	globalTooltip.style.transition = 'opacity 0.2s ease-in-out';
	globalTooltip.style.lineHeight = '1.4';
	globalTooltip.style.maxWidth = '200px'; // Evitar que sea demasiado largo
	globalTooltip.style.wordWrap = 'break-word';
	document.body.appendChild(globalTooltip);
	
	let currentTooltipTarget = null; // Para saber qué mesa está activa
	
	// Función para mostrar el tooltip
	function showTooltip(content, event) {
	    if (currentTooltipTarget) return; // Ya hay uno activo
	
	    globalTooltip.innerHTML = content;
	    globalTooltip.style.opacity = '1';
	    currentTooltipTarget = event.target;
	    
	    // Posicionar y seguir al mouse
	    const updatePosition = (e) => {
	        let left = e.clientX + 35;
	        let top = e.clientY - 40;
	
	        // Asegurar que no se salga por la derecha
	        if (left + globalTooltip.offsetWidth > window.innerWidth) {
	            left = e.clientX - globalTooltip.offsetWidth - 5;
	        }
	        
	        // Asegurar que no se salga por abajo
	        if (top + globalTooltip.offsetHeight > window.innerHeight) {
	            top = e.clientY - globalTooltip.offsetHeight - 5;
	        }
	        
	        // Asegurar que no se salga por arriba
	        if (top < 0) {
	            top = e.clientY + 15;
	        }
	
	        globalTooltip.style.left = `${left}px`;
	        globalTooltip.style.top = `${top}px`;
	    };
	
	    updatePosition(event);
	    document.addEventListener('mousemove', updatePosition, { passive: true });
	    globalTooltip.updatePosition = updatePosition; // Guardar referencia para poder quitarlo después
	}
	
	// Función para ocultar el tooltip
	function hideTooltip() {
	    if (!currentTooltipTarget) return;
	
	    globalTooltip.style.opacity = '0';
	    if (globalTooltip.updatePosition) {
	        document.removeEventListener('mousemove', globalTooltip.updatePosition);
	        delete globalTooltip.updatePosition;
	    }
	    currentTooltipTarget = null;
	    
	    // Ocultar completamente después de la transición
	    setTimeout(() => {
	        if (!currentTooltipTarget) { // Doble chequeo por si se mostró otro rápidamente
	            globalTooltip.style.left = '-9999px';
	        }
	    }, 200);
	}
        
        // Función para dibujar las mesas en el lienzo
	function dibujarMesas() {
	    // Limpiar mesas existentes
	    const objetos = canvas.getObjects();
	    for (let i = objetos.length - 1; i >= 0; i--) {
	        if (objetos[i].mesaId) {
	            canvas.remove(objetos[i]);
	        }
	    }
	    
	    // Calcular el factor de escala para ajustar el tamaño de la fuente
	    const zoomFactor = canvas.getZoom ? canvas.getZoom() : 1;
	    const baseFontSize = 18; // Tamaño de fuente base más grande
    	    const fontSize = Math.max(baseFontSize, Math.round(baseFontSize / zoomFactor));
	    
	    // Dibujar mesas
	    mesas.forEach(mesa => {
	        const tieneEmpleado = mesa.empleado_nombre && mesa.empleado_nombre.trim() !== '';
	        
	        // Crear rectángulo con bordes redondeados
	        const rect = new fabric.Rect({
	            left: mesa.posicion_x,
	            top: mesa.posicion_y,
	            width: mesa.ancho,
	            height: mesa.alto,
	            fill: tieneEmpleado ? mesa.color : 'transparent',
	            stroke: mesa.color,
	            strokeWidth: 3,
	            rx: 5, // Bordes redondeados
	            ry: 5, // Bordes redondeados
	            selectable: modoEdicion,
	            evented: true, // Siempre activar eventos para el tooltip y selección
	            mesaId: mesa.id,
	            mesaNumero: mesa.numero,
	            mesaTipo: mesa.tipo_nombre,
	            mesaEmpleado: mesa.empleado_nombre,
	            rotation: mesa.rotacion || 0
	        });
	        
	        // Agregar texto con el número de mesa
	        const text = new fabric.Text(mesa.numero, {
	            left: mesa.posicion_x + (mesa.ancho / 2),
	            top: mesa.posicion_y + (mesa.alto / 2),
	            fontSize: fontSize,
	            fontWeight: 'bold',
	            fill: tieneEmpleado ? '#ffffff' : mesa.color,
	            originX: 'center',
	            originY: 'center',
	            selectable: false,
	            evented: false,
	            fontFamily: 'Arial, sans-serif' // Fuente más clara

	        });
	        
	        // Agregar al canvas
	        canvas.add(rect);
	        canvas.add(text);
	        
	        // Si hay un empleado asignado, agregar su nombre
	        /**
	        if (mesa.empleado_nombre) {
	            const empleadoText = new fabric.Text(mesa.empleado_nombre, {
	                left: mesa.posicion_x + (mesa.ancho / 2),
	                top: mesa.posicion_y + (mesa.alto / 2) + 20,
	                fontSize: Math.max(12, Math.round(12 / zoomFactor)), // Mínimo 12px, ajustado por zoom
	                fill: '#ffffff',
	                originX: 'center',
	                originY: 'center',
	                selectable: false,
	                evented: false,
	                fontFamily: 'Arial, sans-serif' // Fuente más clara
	            });
	            
	            canvas.add(empleadoText);
	        }
	        **/
	        
		// Añadir tooltip para mostrar el tipo de mesa
		rect.on('mouseover', function(options) {
		    if (modoEdicion) return; // No mostrar tooltip en modo edición
		    
		    const content = `
		        <strong>${mesa.empleado_nombre ? `${mesa.empleado_nombre}` : 'Disponible'}</strong><br>
		        ${mesa.numero}<br>
		        <small>Tipo: ${mesa.tipo_nombre}</small>
		    `;
		    showTooltip(content, options.e);
		});
		
		rect.on('mouseout', function() {
		    hideTooltip();
		});
	        
	        rect.on('mousemove', function(options) {
	            if (rect.tooltip) {
	                // Actualizar posición del tooltip
	                const canvasRect = canvas.getElement().getBoundingClientRect();
	                const pointer = canvas.getPointer(options.e);
	                rect.tooltip.style.left = (canvasRect.left + pointer.x) + 'px';
	                rect.tooltip.style.top = (canvasRect.top + pointer.y - 30) + 'px';
	            }
	        });
	        
	        
	        // Agregar evento de clic para seleccionar la mesa en modo lectura
	        rect.on('mousedown', function(options) {
	            if (!modoEdicion) {
	                // Evitar que se propague el evento
	                options.e.preventDefault();
	                options.e.stopPropagation();
	                
	                // Seleccionar la mesa
	                seleccionarMesaDesdePlano(mesa.id);
	            }
	        });
	    });
	    
	    canvas.renderAll();
	}
	
	// Función para seleccionar una mesa desde el plano
	function seleccionarMesaDesdePlano(mesaId) {
	    // Buscar la mesa en el array
	    const mesa = mesas.find(m => m.id === mesaId);
	    if (!mesa) return;
	    
	    // Actualizar la información de la mesa seleccionada
	    document.getElementById('mesa_seleccionada').textContent = mesa.numero;
	    
	    if (mesa.empleado_nombre) {
	        document.getElementById('empleado_asignado').textContent = mesa.empleado_nombre;
	        document.getElementById('btn_desasignar_empleado').disabled = false;
	    } else {
	        document.getElementById('empleado_asignado').textContent = 'Ninguno';
	        document.getElementById('btn_desasignar_empleado').disabled = true;
	    }
	    
	    document.getElementById('btn_asignar_empleado').disabled = false;
	    
	    // Guardar la referencia a la mesa seleccionada
	    mesaSeleccionada = { mesaId: mesa.id, mesaNumero: mesa.numero };
	    
	    // Resaltar la mesa en el plano
	    const objetos = canvas.getObjects();
	    objetos.forEach(obj => {
	        if (obj.mesaId) {
	            // Restablecer todas las mesas
	            if (obj.mesaId === mesaId) {
	                // Resaltar la mesa seleccionada
	                obj.set({
	                    strokeWidth: 5,
	                    stroke: '#ff0000'
	                });
	            } else {
	                // Restablecer las otras mesas
	                const otraMesa = mesas.find(m => m.id === obj.mesaId);
	                if (otraMesa) {
	                    obj.set({
	                        strokeWidth: 3,
	                        stroke: otraMesa.color
	                    });
	                }
	            }
	        }
	    });
	    
	    canvas.renderAll();
	    
	    // Resaltar la mesa en la lista
	    document.querySelectorAll('#lista_mesas .mesa-item').forEach(item => {
	        item.classList.remove('selected');
	        if (item.dataset.mesaId == mesaId) {
	            item.classList.add('selected');
	            // Hacer scroll hasta el elemento
	            item.scrollIntoView({ behavior: 'smooth', block: 'center' });
	        }
	    });
	}
        
        // Función para dibujar los elementos del plano (muros)
	function dibujarElementosPlano() {
	    // Limpiar elementos existentes
	    const objetos = canvas.getObjects();
	    for (let i = objetos.length - 1; i >= 0; i--) {
	        if (objetos[i].elementoId) {
	            canvas.remove(objetos[i]);
	        }
	    }
	    
	    // Dibujar muros
	    elementosPlano.forEach(elemento => {
	        const puntos = JSON.parse(elemento.puntos);
	        
	        if (elemento.tipo === 'muro' && puntos.length >= 2) {
	            // Dibujar muro como línea
	            const line = new fabric.Line([
	                puntos[0].x, puntos[0].y,
	                puntos[1].x, puntos[1].y
	            ], {
	                stroke: elemento.color,
	                strokeWidth: 5,
	                selectable: modoEdicion,
	                evented: modoEdicion,
	                elementoId: elemento.id,
	                elementoTipo: elemento.tipo,
	                hasBorders: modoEdicion,
	                hasControls: modoEdicion
	            });
	            
	            // Almacenar las coordenadas iniciales como propiedades personalizadas
	            line.set({
	                originalX1: puntos[0].x,
	                originalY1: puntos[0].y,
	                originalX2: puntos[1].x,
	                originalY2: puntos[1].y
	            });
	            
	            canvas.add(line);
	        }
	    });
	    
	    canvas.renderAll();
	}
        
	// Función para cambiar de piso
	function cambiarPiso(pisoId) {
	    // Si estamos en modo edición, preguntar si se desea guardar los cambios
	    if (modoEdicion) {
	        if (!confirm('¿Está seguro de cambiar de piso? Se perderán los cambios no guardados.')) {
	            return;
	        }
	    }
	    
	    // Limpiar selección de mesa
	    mesaSeleccionada = null;
	    document.getElementById('mesa_seleccionada').textContent = 'Ninguna';
	    document.getElementById('empleado_asignado').textContent = 'Ninguno';
	    document.getElementById('btn_asignar_empleado').disabled = true;
	    document.getElementById('btn_desasignar_empleado').disabled = true;
	    
	    // Redirigir al nuevo piso
	    window.location.href = `plano_sede.php?sede_id=<?php echo $sede_id; ?><?php echo $oficina ? '&oficina=' . urlencode($oficina) : ''; ?>&piso_id=${pisoId}`;
	}
        
	// Función para activar/desactivar el modo de edición
	function toggleModoEdicion() {
	    modoEdicion = !modoEdicion;
	    const toolbar = document.getElementById('toolbar');
	    const btnEditar = document.getElementById('btn_editar_plano');
	    const panelLateral = document.getElementById('panel-lateral');
	    const contenedorLienzo = document.getElementById('contenedor-lienzo');
	    const btnAgregarPiso = document.getElementById('btn_agregar_piso');
	    
	    // Verificar que los elementos existan antes de usarlos
	    if (!toolbar || !btnEditar || !panelLateral || !contenedorLienzo) {
	        console.error('No se encontraron los elementos necesarios para cambiar el modo de edición');
	        return;
	    }
	    
	    if (modoEdicion) {
	        // Entrar en modo edición
	        toolbar.style.display = 'block';
	        btnEditar.innerHTML = '<i class="bi bi-eye"></i> Ver Plano';
	        btnEditar.classList.remove('btn-primary');
	        btnEditar.classList.add('btn-secondary');
	        
	        if (btnAgregarPiso) {
	            btnAgregarPiso.style.display = 'inline-block';
	        }
	        
	        // Ocultar panel lateral
	        panelLateral.style.display = 'none';
	        
	        // Cambiar clases del contenedor del lienzo
	        contenedorLienzo.classList.remove('col-md-8');
	        contenedorLienzo.classList.add('col-md-12');
	        
	        // Activar la selección en el canvas
	        canvas.selection = true;
	        
	        // Activar la selección en todos los objetos
	        const objetos = canvas.getObjects();
	        objetos.forEach(obj => {
	            obj.selectable = true;
	            obj.evented = true;
	        });
	        
	        // Seleccionar la herramienta de selección por defecto
	        setTool('select');
	        
	        // Aplicar zoom automático después de un breve retraso
	        setTimeout(() => {
	            aplicarZoomAutomatico();
	        }, 300);
	        
	    } else {
	        // Salir del modo edición
	        toolbar.style.display = 'none';
	        btnEditar.innerHTML = '<i class="bi bi-pencil"></i> Editar Plano';
	        btnEditar.classList.remove('btn-secondary');
	        btnEditar.classList.add('btn-primary');
	        
	        if (btnAgregarPiso) {
	            btnAgregarPiso.style.display = 'none';
	        }
	        
	        // Mostrar panel lateral
	        panelLateral.style.display = 'block';
	        
	        // Cambiar clases del contenedor del lienzo
	        contenedorLienzo.classList.remove('col-md-12');
	        contenedorLienzo.classList.add('col-md-8');
	        
	        // Desactivar la selección en el canvas
	        canvas.selection = false;
	        canvas.discardActiveObject();
	        
	        // Desactivar la selección en todos los objetos
	        const objetos = canvas.getObjects();
	        objetos.forEach(obj => {
	            obj.selectable = false;
	            obj.evented = true; // Mantener evented en true para permitir clics en modo lectura
	        });
	        
	        // Limpiar cualquier selección activa
	        mesaSeleccionada = null;
	        actualizarInfoMesaSeleccionada();
	        
	        // Aplicar zoom automático después de un breve retraso
	        setTimeout(() => {
	            aplicarZoomAutomatico();
	        }, 300);
	    }
	}
	
	// Función para redimensionar el canvas
	function resizeCanvas() {
	    // No cambiar las dimensiones del canvas, solo ajustar el zoom
	    aplicarZoomAutomatico();
	}
	
        
        // Función para establecer la herramienta actual
        function setTool(tool) {
            herramientaActual = tool;
            
            // Actualizar la UI
            document.querySelectorAll('.element-tool').forEach(el => {
                el.classList.remove('active');
            });
            document.querySelector(`.element-tool[data-tool="${tool}"]`).classList.add('active');
            
            // Mostrar/ocultar selector de tipos de mesa
            const mesaTypeSelector = document.getElementById('mesa-type-selector-container');
            if (tool === 'mesa') {
                mesaTypeSelector.style.display = 'block';
                // Seleccionar el primer tipo de mesa por defecto
                if (!tipoMesaSeleccionado) {
                    const firstMesaType = document.querySelector('.mesa-type-btn');
                    if (firstMesaType) {
                        selectMesaType(firstMesaType.dataset.tipoId);
                    }
                }
            } else {
                mesaTypeSelector.style.display = 'none';
            }
            
            // Reiniciar variables temporales
            puntosMuro = [];
            puntosMuroTemporal = [];
            isDrawingMuro = false;
            lastMuroPoint = null;
            
            // Cambiar el cursor según la herramienta
            switch (tool) {
                case 'select':
                    canvas.defaultCursor = 'default';
                    break;
                case 'mesa':
                    canvas.defaultCursor = 'crosshair';
                    break;
                case 'muro':
                    canvas.defaultCursor = 'crosshair';
                    break;
                case 'eliminar':
                    canvas.defaultCursor = 'not-allowed';
                    break;
            }
        }
        
        // Función para seleccionar un tipo de mesa
        function selectMesaType(tipoId) {
            tipoMesaSeleccionado = tipoId;
            
            // Actualizar la UI
            document.querySelectorAll('.mesa-type-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            document.querySelector(`.mesa-type-btn[data-tipo-id="${tipoId}"]`).classList.add('active');
            
            // Actualizar el número de mesa automáticamente
            const mesaNumeroInput = document.getElementById('mesa_numero');
            const siguienteNumero = getSiguienteNumeroMesa();
            mesaNumeroInput.value = siguienteNumero;
        }
        
        // Función para obtener el siguiente número de mesa
	function getSiguienteNumeroMesa() {
	    // Extraer números de las mesas existentes que no estén eliminadas
	    const numeros = mesas.filter(m => !m.eliminado).map(m => {
	        const match = m.numero.match(/M-(\d+)/);
	        return match ? parseInt(match[1]) : 0;
	    });
	    
	    // Encontrar el número más alto
	    const maxNumero = Math.max(...numeros, 0);
	    
	    // Devolver el siguiente número con formato de 3 dígitos
	    return `M-${String(maxNumero + 1).padStart(3, '0')}`;
	}
        
        // Manejadores de eventos del canvas
        function handleCanvasMouseDown(options) {
            if (!modoEdicion) return;
            
            const pointer = canvas.getPointer(options.e);
            const objeto = canvas.findTarget(options.e);
            
            // Si estamos en modo de edición y hay un objeto seleccionado, no crear nuevos objetos
            if (objeto && (herramientaActual === 'mesa' || herramientaActual === 'muro')) {
                return;
            }
            
            switch (herramientaActual) {
                case 'mesa':
                    if (!objeto) {
                        agregarMesa(pointer.x, pointer.y);
                    }
                    break;
                case 'muro':
                    if (!objeto) {
                        agregarPuntoMuro(pointer.x, pointer.y);
                    }
                    break;
                case 'eliminar':
                    if (objeto) {
                        eliminarElemento(objeto);
                    }
                    break;
            }
        }
        
        function handleCanvasMouseUp(options) {
            if (!modoEdicion) return;
            
            if (herramientaActual === 'muro' && isDrawingMuro) {
                // Finalizar el muro si tenemos al menos dos puntos
                if (puntosMuro.length >= 2) {
                    finalizarMuro();
                }
            }
        }
        
        function handleCanvasMouseMove(options) {
            if (!modoEdicion) return;
            
            const pointer = canvas.getPointer(options.e);
            
            if (herramientaActual === 'muro' && isDrawingMuro && puntosMuro.length === 1) {
                // Mostrar línea temporal mientras se mueve el mouse
                // Eliminar línea temporal si existe
                const objetos = canvas.getObjects();
                for (let i = objetos.length - 1; i >= 0; i--) {
                    if (objetos[i].temporal) {
                        canvas.remove(objetos[i]);
                    }
                }
                
                // Calcular puntos ajustados para el muro
                const puntoAjustado = ajustarPuntoMuro(pointer.x, pointer.y);
                
                // Agregar línea temporal
                const line = new fabric.Line([
                    puntosMuro[0].x, puntosMuro[0].y,
                    puntoAjustado.x, puntoAjustado.y
                ], {
                    stroke: '#cccccc',
                    strokeWidth: 5,
                    selectable: false,
                    evented: false,
                    temporal: true
                });
                
                canvas.add(line);
                canvas.renderAll();
            }
        }
        
        function handleSelectionCreated(options) {
            if (!modoEdicion) return;
            
            const selected = options.selected[0];
            if (selected && selected.mesaId) {
                // Se seleccionó una mesa
                mesaSeleccionada = selected;
                actualizarInfoMesaSeleccionada();
            }
        }
        
        function handleSelectionUpdated(options) {
            if (!modoEdicion) return;
            
            const selected = options.selected[0];
            if (selected && selected.mesaId) {
                // Se seleccionó una mesa
                mesaSeleccionada = selected;
                actualizarInfoMesaSeleccionada();
            }
        }
        
        function handleSelectionCleared() {
            // Se deseleccionó todo
            mesaSeleccionada = null;
            actualizarInfoMesaSeleccionada();
        }
        
	function handleObjectMoving(options) {
	    if (!modoEdicion) return;
	    
	    const objeto = options.target;
	    
	    if (objeto.mesaId) {
	        // Aplicar "imán" para alinear la mesa
	        const puntoAjustado = ajustarPuntoMesa(objeto.left, objeto.top, objeto.width, objeto.height);
	        
	        if (puntoAjustado.x !== objeto.left || puntoAjustado.y !== objeto.top) {
	            objeto.left = puntoAjustado.x;
	            objeto.top = puntoAjustado.y;
	        }
	        
	        // Mostrar guías de alineación
	        mostrarGuiasAlineacion(objeto);
	    } else if (objeto.elementoId && objeto.elementoTipo === 'muro') {
	        // Para muros, necesitamos calcular las nuevas coordenadas basadas en el movimiento
	        // fabric.js no actualiza automáticamente x1, y1, x2, y2 al mover una línea
	        
	        // Obtener las coordenadas originales si existen
	        const originalX1 = objeto.originalX1 || 0;
	        const originalY1 = objeto.originalY1 || 0;
	        const originalX2 = objeto.originalX2 || 0;
	        const originalY2 = objeto.originalY2 || 0;
	        
	        // Calcular el desplazamiento actual
	        const dx = objeto.left - (objeto.originalLeft || objeto.left);
	        const dy = objeto.top - (objeto.originalTop || objeto.top);
	        
	        // Actualizar las coordenadas originales si no existen
	        if (!objeto.originalLeft) {
	            objeto.originalLeft = objeto.left;
	            objeto.originalTop = objeto.top;
	        }
	        
	        // Calcular nuevas coordenadas
	        const newX1 = originalX1 + dx;
	        const newY1 = originalY1 + dy;
	        const newX2 = originalX2 + dx;
	        const newY2 = originalY2 + dy;
	        
	        // Actualizar el array de elementosPlano
	        const elemento = elementosPlano.find(e => e.id === objeto.elementoId);
	        if (elemento) {
	            elemento.puntos = JSON.stringify([
	                { x: newX1, y: newY1 },
	                { x: newX2, y: newY2 }
	            ]);
	            
	            // Marcar como modificado si no es nuevo
	            if (!elemento.nuevo) {
	                elemento.modificado = true;
	            }
	        }
	    }
	}
        
	function handleObjectModified(options) {
	    if (!modoEdicion) return;
	    
	    const objeto = options.target;
	    
	    // Limpiar guías de alineación
	    limpiarGuiasAlineacion();
	    
	    if (objeto.mesaId) {
	        // Actualizar posición de la mesa en el array
	        const mesa = mesas.find(m => m.id === objeto.mesaId);
	        if (mesa) {
	            mesa.posicion_x = objeto.left;
	            mesa.posicion_y = objeto.top;
	            mesa.rotacion = objeto.angle || 0;
	            
	            // Marcar como modificado si no es nuevo
	            if (!mesa.nuevo) {
	                mesa.modificado = true;
	            }
	            
	            // Actualizar textos asociados
	            actualizarTextosMesa(objeto);
	        }
	    } else if (objeto.elementoId && objeto.elementoTipo === 'muro') {
	        // Para muros, ya actualizamos en handleObjectMoving
	        // Pero necesitamos actualizar las coordenadas "originales" para futuros movimientos
	        
	        // Buscar el elemento
	        const elemento = elementosPlano.find(e => e.id === objeto.elementoId);
	        if (elemento) {
	            const puntos = JSON.parse(elemento.puntos);
	            
	            // Actualizar las coordenadas originales del objeto
	            objeto.set({
	                originalX1: puntos[0].x,
	                originalY1: puntos[0].y,
	                originalX2: puntos[1].x,
	                originalY2: puntos[1].y,
	                originalLeft: objeto.left,
	                originalTop: objeto.top
	            });
	        }
	    }
	}
        
        // Función para ajustar un punto de mesa con "imán"
        function ajustarPuntoMesa(x, y, ancho, alto) {
            let puntoAjustado = { x, y };
            
            // Revisar todas las mesas existentes para alinear
            mesas.forEach(mesa => {
                // Alineación horizontal (izquierda o derecha)
                if (Math.abs(x - mesa.posicion_x) < snapThreshold) {
                    puntoAjustado.x = mesa.posicion_x;
                } else if (Math.abs(x - (mesa.posicion_x + mesa.ancho)) < snapThreshold) {
                    puntoAjustado.x = mesa.posicion_x + mesa.ancho;
                }
                
                // Alineación vertical (arriba o abajo)
                if (Math.abs(y - mesa.posicion_y) < snapThreshold) {
                    puntoAjustado.y = mesa.posicion_y;
                } else if (Math.abs(y - (mesa.posicion_y + mesa.alto)) < snapThreshold) {
                    puntoAjustado.y = mesa.posicion_y + mesa.alto;
                }
                
                // Alineación al centro
                if (Math.abs(x - (mesa.posicion_x + mesa.ancho / 2)) < snapThreshold) {
                    puntoAjustado.x = mesa.posicion_x + mesa.ancho / 2;
                }
                
                if (Math.abs(y - (mesa.posicion_y + mesa.alto / 2)) < snapThreshold) {
                    puntoAjustado.y = mesa.posicion_y + mesa.alto / 2;
                }
            });
            
            return puntoAjustado;
        }
        
        // Función para ajustar un punto de muro con "imán"
        function ajustarPuntoMuro(x, y) {
            let puntoAjustado = { x, y };
            
            // Revisar todos los muros existentes para encontrar anclas
            elementosPlano.forEach(elemento => {
                if (elemento.tipo === 'muro') {
                    const puntos = JSON.parse(elemento.puntos);
                    
                    // Revisar cada punto del muro existente
                    puntos.forEach(punto => {
                        // Si está cerca de un punto existente, ajustar a ese punto
                        if (Math.abs(x - punto.x) < muroSnapThreshold && Math.abs(y - punto.y) < muroSnapThreshold) {
                            puntoAjustado.x = punto.x;
                            puntoAjustado.y = punto.y;
                        }
                    });
                }
            });
            
            // Asegurar que el muro sea completamente horizontal, vertical o diagonal
            if (puntosMuro.length === 1) {
                const primerPunto = puntosMuro[0];
                const deltaX = Math.abs(puntoAjustado.x - primerPunto.x);
                const deltaY = Math.abs(puntoAjustado.y - primerPunto.y);
                
                // Si es más horizontal que vertical, hacer completamente horizontal
                if (deltaX > deltaY * 2) {
                    puntoAjustado.y = primerPunto.y;
                }
                // Si es más vertical que horizontal, hacer completamente vertical
                else if (deltaY > deltaX * 2) {
                    puntoAjustado.x = primerPunto.x;
                }
                // Si es aproximadamente diagonal, ajustar a 45 grados
                else if (Math.abs(deltaX - deltaY) < snapThreshold) {
                    const distancia = Math.max(deltaX, deltaY);
                    if (puntoAjustado.x > primerPunto.x) {
                        puntoAjustado.x = primerPunto.x + distancia;
                    } else {
                        puntoAjustado.x = primerPunto.x - distancia;
                    }
                    
                    if (puntoAjustado.y > primerPunto.y) {
                        puntoAjustado.y = primerPunto.y + distancia;
                    } else {
                        puntoAjustado.y = primerPunto.y - distancia;
                    }
                }
            }
            
            return puntoAjustado;
        }
        
        // Función para ajustar un muro completo
        function ajustarMuro(muro) {
            let puntosAjustados = null;
            
            // Revisar todos los muros existentes para encontrar anclas
            elementosPlano.forEach(elemento => {
                if (elemento.tipo === 'muro' && elemento.id !== muro.elementoId) {
                    const puntos = JSON.parse(elemento.puntos);
                    
                    // Revisar si el inicio del muro actual está cerca de un punto existente
                    puntos.forEach(punto => {
                        if (Math.abs(muro.x1 - punto.x) < muroSnapThreshold && Math.abs(muro.y1 - punto.y) < muroSnapThreshold) {
                            if (!puntosAjustados) puntosAjustados = {};
                            puntosAjustados.x1 = punto.x;
                            puntosAjustados.y1 = punto.y;
                        }
                        
                        if (Math.abs(muro.x2 - punto.x) < muroSnapThreshold && Math.abs(muro.y2 - punto.y) < muroSnapThreshold) {
                            if (!puntosAjustados) puntosAjustados = {};
                            puntosAjustados.x2 = punto.x;
                            puntosAjustados.y2 = punto.y;
                        }
                    });
                }
            });
            
            // Asegurar que el muro sea completamente horizontal, vertical o diagonal
            if (!puntosAjustados) {
                const deltaX = Math.abs(muro.x2 - muro.x1);
                const deltaY = Math.abs(muro.y2 - muro.y1);
                
                // Si es más horizontal que vertical, hacer completamente horizontal
                if (deltaX > deltaY * 2) {
                    if (!puntosAjustados) puntosAjustados = {};
                    puntosAjustados.y2 = muro.y1;
                }
                // Si es más vertical que horizontal, hacer completamente vertical
                else if (deltaY > deltaX * 2) {
                    if (!puntosAjustados) puntosAjustados = {};
                    puntosAjustados.x2 = muro.x1;
                }
                // Si es aproximadamente diagonal, ajustar a 45 grados
                else if (Math.abs(deltaX - deltaY) < snapThreshold) {
                    if (!puntosAjustados) puntosAjustados = {};
                    const distancia = Math.max(deltaX, deltaY);
                    
                    if (muro.x2 > muro.x1) {
                        puntosAjustados.x2 = muro.x1 + distancia;
                    } else {
                        puntosAjustados.x2 = muro.x1 - distancia;
                    }
                    
                    if (muro.y2 > muro.y1) {
                        puntosAjustados.y2 = muro.y1 + distancia;
                    } else {
                        puntosAjustados.y2 = muro.y1 - distancia;
                    }
                }
            }
            
            return puntosAjustados;
        }
        
        // Función para mostrar guías de alineación
        function mostrarGuiasAlineacion(objeto) {
            // Limpiar guías existentes
            limpiarGuiasAlineacion();
            
            // Mostrar guías horizontales y verticales
            mesas.forEach(mesa => {
                // Guía vertical si están alineados verticalmente
                if (Math.abs(objeto.left - mesa.posicion_x) < snapThreshold) {
                    const linea = new fabric.Line([
                        objeto.left, 0,
                        objeto.left, canvas.height
                    ], {
                        stroke: 'rgba(0, 58, 93, 0.3)',
                        strokeWidth: 1,
                        selectable: false,
                        evented: false,
                        excludeFromExport: true,
                        guide: true
                    });
                    
                    canvas.add(linea);
                    guiasAlineacion.push(linea);
                }
                
                // Guía horizontal si están alineados horizontalmente
                if (Math.abs(objeto.top - mesa.posicion_y) < snapThreshold) {
                    const linea = new fabric.Line([
                        0, objeto.top,
                        canvas.width, objeto.top
                    ], {
                        stroke: 'rgba(0, 58, 93, 0.3)',
                        strokeWidth: 1,
                        selectable: false,
                        evented: false,
                        excludeFromExport: true,
                        guide: true
                    });
                    
                    canvas.add(linea);
                    guiasAlineacion.push(linea);
                }
            });
        }
        
        // Función para limpiar guías de alineación
        function limpiarGuiasAlineacion() {
            // Eliminar guías existentes
            guiasAlineacion.forEach(guia => {
                canvas.remove(guia);
            });
            guiasAlineacion = [];
        }
        
        // Función para agregar una mesa
        function agregarMesa(x, y) {
            if (!tipoMesaSeleccionado) {
                alert('Seleccione un tipo de mesa');
                return;
            }
            
            const mesaTipoBtn = document.querySelector(`.mesa-type-btn[data-tipo-id="${tipoMesaSeleccionado}"]`);
            const mesaNumeroInput = document.getElementById('mesa_numero');
            
            const tipoMesaId = tipoMesaSeleccionado;
            const color = mesaTipoBtn.dataset.color;
            const ancho = parseInt(mesaTipoBtn.dataset.ancho);
            const alto = parseInt(mesaTipoBtn.dataset.alto);
            const numero = mesaNumeroInput.value || getSiguienteNumeroMesa();
            
            // Verificar que el número no exista
            if (mesas.some(m => m.numero === numero)) {
                alert('Ya existe una mesa con ese número');
                return;
            }
            
            // Ajustar posición con "imán"
            const puntoAjustado = ajustarPuntoMesa(x - (ancho / 2), y - (alto / 2), ancho, alto);
            
            // Crear objeto de mesa
            const mesa = {
                id: `temp_${Date.now()}`, // ID temporal
                tipo_mesa_id: tipoMesaId,
                numero: numero,
                posicion_x: puntoAjustado.x,
                posicion_y: puntoAjustado.y,
                ancho: ancho,
                alto: alto,
                color: color,
                rotacion: 0,
                nuevo: true // Marcar como nuevo para guardar
            };
            
            // Agregar a la lista de mesas
            mesas.push(mesa);
            
            // Dibujar la mesa
            const rect = new fabric.Rect({
                left: mesa.posicion_x,
                top: mesa.posicion_y,
                width: mesa.ancho,
                height: mesa.alto,
                fill: 'transparent', // Sin relleno por defecto
                stroke: mesa.color,
                strokeWidth: 3,
                rx: 5, // Bordes redondeados
                ry: 5, // Bordes redondeados
                selectable: true,
                evented: true,
                mesaId: mesa.id,
                mesaNumero: mesa.numero,
                mesaTipo: mesaTipoBtn.textContent.trim()
            });
            
            // Agregar texto con el número de mesa
            const text = new fabric.Text(mesa.numero, {
                left: mesa.posicion_x + (mesa.ancho / 2),
                top: mesa.posicion_y + (mesa.alto / 2),
                fontSize: 14,
                fontWeight: 'bold',
                fill: mesa.color,
                originX: 'center',
                originY: 'center',
                selectable: false,
                evented: false
            });
            
            // Agregar al canvas
            canvas.add(rect);
            canvas.add(text);
            
            // Actualizar el número de mesa para la siguiente
            mesaNumeroInput.value = getSiguienteNumeroMesa();
            
            // Actualizar la lista de mesas
            cargarListaMesas();
        }
        
        // Función para agregar un punto a un muro
        function agregarPuntoMuro(x, y) {
            const puntoAjustado = ajustarPuntoMuro(x, y);
            
            if (puntosMuro.length === 0) {
                // Primer punto
                puntosMuro.push(puntoAjustado);
                isDrawingMuro = true;
                
                // Agregar un punto visual para indicar el inicio del muro
                const circle = new fabric.Circle({
                    left: puntoAjustado.x - 5,
                    top: puntoAjustado.y - 5,
                    radius: 5,
                    fill: '#003a5d',
                    selectable: false,
                    evented: false,
                    temporal: true
                });
                
                canvas.add(circle);
            } else if (puntosMuro.length === 1) {
                // Segundo punto
                puntosMuro.push(puntoAjustado);
                
                // Crear muro
                finalizarMuro();
            }
        }
        
        // Función para finalizar un muro
	function finalizarMuro() {
	    // Crear muro
	    const muro = {
	        id: `temp_${Date.now()}`, // ID temporal
	        tipo: 'muro',
	        puntos: JSON.stringify(puntosMuro),
	        color: '#cccccc',
	        nuevo: true // Marcar como nuevo para guardar
	    };
	    
	    // Agregar a la lista de elementos
	    elementosPlano.push(muro);
	    
	    // Dibujar el muro
	    const line = new fabric.Line([
	        puntosMuro[0].x, puntosMuro[0].y,
	        puntosMuro[1].x, puntosMuro[1].y
	    ], {
	        stroke: muro.color,
	        strokeWidth: 5,
	        selectable: true,
	        evented: true,
	        elementoId: muro.id,
	        elementoTipo: muro.tipo,
	        originalX1: puntosMuro[0].x,
	        originalY1: puntosMuro[0].y,
	        originalX2: puntosMuro[1].x,
	        originalY2: puntosMuro[1].y,
	        originalLeft: puntosMuro[0].x, // Aproximación
	        originalTop: puntosMuro[0].y  // Aproximación
	    });
	    
	    canvas.add(line);
	    
	    // Eliminar elementos temporales
	    const objetos = canvas.getObjects();
	    for (let i = objetos.length - 1; i >= 0; i--) {
	        if (objetos[i].temporal) {
	            canvas.remove(objetos[i]);
	        }
	    }
	    
	    canvas.renderAll();
	    
	    // Reiniciar puntos
	    puntosMuro = [];
	    isDrawingMuro = false;
	}
        
        // Función para eliminar un elemento
	function eliminarElemento(objeto) {
	    if (objeto.mesaId) {
	        // Eliminar mesa
	        const index = mesas.findIndex(m => m.id === objeto.mesaId);
	        if (index !== -1) {
	            // Marcar como eliminado en lugar de eliminar del array
	            mesas[index].eliminado = true;
	            
	            // Eliminar del canvas
	            const objetos = canvas.getObjects();
	            for (let i = objetos.length - 1; i >= 0; i--) {
	                if (objetos[i].mesaId === objeto.mesaId || 
	                    (objetos[i].text && objetos[i].text === objeto.mesaNumero)) {
	                    canvas.remove(objetos[i]);
	                }
	            }
	            
	            canvas.renderAll();
	            cargarListaMesas();
	        }
	    } else if (objeto.elementoId) {
	        // Eliminar elemento del plano
	        const index = elementosPlano.findIndex(e => e.id === objeto.elementoId);
	        if (index !== -1) {
	            // Marcar como eliminado en lugar de eliminar del array
	            elementosPlano[index].eliminado = true;
	            
	            // Eliminar del canvas
	            canvas.remove(objeto);
	            
	            canvas.renderAll();
	        }
	    }
	}
        
        // Función para actualizar los textos de una mesa
	function actualizarTextosMesa(objeto) {
	    // Buscar la mesa en el array
	    const mesa = mesas.find(m => m.id === objeto.mesaId);
	    if (!mesa) return;
	    
	    // Eliminar textos existentes
	    const objetos = canvas.getObjects();
	    for (let i = objetos.length - 1; i >= 0; i--) {
	        if ((objetos[i].text && objetos[i].text === mesa.numero) || 
	            (mesa.empleado_nombre && objetos[i].text === mesa.empleado_nombre)) {
	            canvas.remove(objetos[i]);
	        }
	    }
	    
	    // Agregar texto con el número de mesa
	    const text = new fabric.Text(mesa.numero, {
	        left: objeto.left + (objeto.width / 2),
	        top: objeto.top + (objeto.height / 2),
	        fontSize: 14,
	        fontWeight: 'bold',
	        fill: mesa.empleado_nombre ? '#ffffff' : mesa.color,
	        originX: 'center',
	        originY: 'center',
	        selectable: false,
	        evented: false
	    });
	    
	    canvas.add(text);
	    
	    // Si hay un empleado asignado, agregar su nombre
	    if (mesa.empleado_nombre) {
	        const empleadoText = new fabric.Text(mesa.empleado_nombre, {
	            left: objeto.left + (objeto.width / 2),
	            top: objeto.top + (objeto.height / 2) + 15,
	            fontSize: 10,
	            fill: '#ffffff',
	            originX: 'center',
	            originY: 'center',
	            selectable: false,
	            evented: false
	        });
	        
	        canvas.add(empleadoText);
	    }
	    
	    // Actualizar tooltip
	    objeto.on('mouseover', function(options) {
	        if (!modoEdicion) {
	            // Crear elemento tooltip
	            const tooltip = document.createElement('div');
	            tooltip.className = 'custom-tooltip';
	            tooltip.innerHTML = `
	                <strong>${mesa.numero}</strong><br>
	                Tipo: ${mesa.tipo_nombre}
	                ${mesa.empleado_nombre ? `<br>Asignado a: ${mesa.empleado_nombre}` : ''}
	            `;
	            tooltip.style.position = 'absolute';
	            tooltip.style.backgroundColor = 'rgba(0, 0, 0, 0.8)';
	            tooltip.style.color = 'white';
	            tooltip.style.padding = '5px 10px';
	            tooltip.style.borderRadius = '4px';
	            tooltip.style.fontSize = '12px';
	            tooltip.style.zIndex = '1000';
	            tooltip.style.pointerEvents = 'none';
	            
	            // Añadir al DOM
	            document.body.appendChild(tooltip);
	            
	            // Posicionar tooltip
	            const canvasRect = canvas.getElement().getBoundingClientRect();
	            const pointer = canvas.getPointer(options.e);
	            tooltip.style.left = (canvasRect.left + pointer.x) + 'px';
	            tooltip.style.top = (canvasRect.top + pointer.y - 30) + 'px';
	            
	            // Guardar referencia para eliminarlo después
	            objeto.tooltip = tooltip;
	        }
	    });
	    
	    objeto.on('mousemove', function(options) {
	        if (objeto.tooltip && !modoEdicion) {
	            // Actualizar posición del tooltip
	            const canvasRect = canvas.getElement().getBoundingClientRect();
	            const pointer = canvas.getPointer(options.e);
	            objeto.tooltip.style.left = (canvasRect.left + pointer.x) + 'px';
	            objeto.tooltip.style.top = (canvasRect.top + pointer.y - 30) + 'px';
	        }
	    });
	    
	    objeto.on('mouseout', function() {
	        if (objeto.tooltip) {
	            document.body.removeChild(objeto.tooltip);
	            objeto.tooltip = null;
	        }
	    });
	    
	    canvas.renderAll();
	}
        
	// Función para guardar el plano
	function guardarPlano() {
	    // Actualizar posiciones de las mesas
	    const objetos = canvas.getObjects();
	    
	    objetos.forEach(obj => {
	        if (obj.mesaId) {
	            const mesa = mesas.find(m => m.id === obj.mesaId);
	            if (mesa) {
	                mesa.posicion_x = obj.left;
	                mesa.posicion_y = obj.top;
	                mesa.rotacion = obj.angle || 0;
	                
	                // Marcar como modificado si no es nuevo y no está eliminado
	                if (!mesa.nuevo && !mesa.eliminado) {
	                    mesa.modificado = true;
	                }
	            }
		} else if (obj.elementoId) {
		    const elemento = elementosPlano.find(e => e.id === obj.elementoId);
		    if (elemento) {
		        if (elemento.tipo === 'muro') {
		            // Marcar como modificado si no es nuevo y no está eliminado
		            if (!elemento.nuevo && !elemento.eliminado) {
		                elemento.modificado = true;
		            }
		        }
		    }
		}
	    });
	    
	    // Filtrar las mesas y elementos que NO están eliminados para enviar al servidor
	    const mesasParaGuardar = mesas.filter(m => !m.eliminado);
	    const elementosParaGuardar = elementosPlano.filter(e => !e.eliminado);
	    
	    // Preparar arrays de eliminados
	    const mesasEliminadas = mesas.filter(m => m.eliminado && !m.nuevo); // Solo eliminar las que no son nuevas
	    const elementosEliminados = elementosPlano.filter(e => e.eliminado && !e.nuevo); // Solo eliminar los que no son nuevos
	    
	    // Enviar datos al servidor
	    const formData = new FormData();
	    formData.append('piso_id', <?php echo $piso_id; ?>);
	    formData.append('mesas', JSON.stringify(mesasParaGuardar));
	    formData.append('elementos', JSON.stringify(elementosParaGuardar));
	    formData.append('guardar_plano', '1');
	    
	    // Agregar datos de eliminación
	    if (mesasEliminadas.length > 0) {
	        formData.append('mesas_eliminadas', JSON.stringify(mesasEliminadas));
	    }
	    
	    if (elementosEliminados.length > 0) {
	        formData.append('elementos_eliminados', JSON.stringify(elementosEliminados));
	    }
	    
	    fetch('../includes/guardar_plano.php', {
	        method: 'POST',
	        body: formData
	    })
	    .then(response => {
	        if (!response.ok) {
	            throw new Error('Error en la respuesta del servidor: ' + response.status);
	        }
	        return response.json();
	    })
	    .then(data => {
	        if (data.success) {
	            alert('Plano guardado correctamente');
	            
	            // Actualizar IDs de elementos nuevos
	            if (data.ids_mesas) {
	                data.ids_mesas.forEach(pair => {
	                    const mesa = mesas.find(m => m.id === pair.temp_id);
	                    if (mesa) {
	                        mesa.id = pair.new_id;
	                        mesa.nuevo = false;
	                    }
	                });
	            }
	            
	            if (data.ids_elementos) {
	                data.ids_elementos.forEach(pair => {
	                    const elemento = elementosPlano.find(e => e.id === pair.temp_id);
	                    if (elemento) {
	                        elemento.id = pair.new_id;
	                        elemento.nuevo = false;
	                    }
	                });
	            }
	            
	            // Eliminar del array los elementos marcados como eliminados
	            mesas = mesas.filter(m => !m.eliminado);
	            elementosPlano = elementosPlano.filter(e => !e.eliminado);
	            
	            // Actualizar el canvas con los nuevos IDs
	            dibujarMesas();
	            dibujarElementosPlano();
	            cargarListaMesas();
	        } else {
	            alert('Error al guardar el plano: ' + data.error);
	        }
	    })
	    .catch(error => {
	        console.error('Error:', error);
	        alert('Error al guardar el plano. Ver consola para detalles.');
	    });
	}
        
	// Función para cancelar la edición
	function cancelarEdicion() {
	    if (confirm('¿Está seguro de cancelar la edición? Se perderán todos los cambios no guardados.')) {
	        // Recargar la página para restablecer completamente el estado
	        location.reload();
	    }
	}
        
        // Función para cambiar el tamaño del canvas
        function cambiarTamanoCanvas() {
            const modal = new bootstrap.Modal(document.getElementById('modalTamanoCanvas'));
            modal.show();
        }
        
	function aplicarTamanoCanvas() {
	    const ancho = parseInt(document.getElementById('canvas_ancho').value);
	    const alto = parseInt(document.getElementById('canvas_alto').value);
	    
	    if (ancho < 500 || ancho > 2000 || alto < 400 || alto > 1500) {
	        alert('Las dimensiones del lienzo están fuera de los rangos permitidos');
	        return;
	    }
	    
	    // Cambiar el tamaño del canvas
	    canvas.setWidth(ancho);
	    canvas.setHeight(alto);
	    
	    // Aplicar zoom automático después de cambiar el tamaño
	    aplicarZoomAutomatico();
	    
	    // Cerrar el modal
	    const modal = bootstrap.Modal.getInstance(document.getElementById('modalTamanoCanvas'));
	    modal.hide();
	}
        
        // Función para cargar la lista de mesas
	function cargarListaMesas() {
	    const listaMesas = document.getElementById('lista_mesas');
	    listaMesas.innerHTML = '';
	    
	    // Si no hay mesas, mostrar un mensaje
	    if (mesas.length === 0) {
	        listaMesas.innerHTML = '<div class="text-muted p-2">No hay mesas en este plano</div>';
	        return;
	    }
	    
	    // Ordenar mesas por número
	    const mesasOrdenadas = [...mesas].sort((a, b) => {
	        const numA = parseInt(a.numero.replace(/[^0-9]/g, ''));
	        const numB = parseInt(b.numero.replace(/[^0-9]/g, ''));
	        return numA - numB;
	    });
	    
	    mesasOrdenadas.forEach(mesa => {
	        const div = document.createElement('div');
	        div.className = 'mesa-item';
	        div.dataset.mesaId = mesa.id;
	        
	        if (mesa.empleado_nombre) {
	            div.classList.add('ocupada');
	        }
	        
	        div.innerHTML = `
	            <div class="d-flex justify-content-between align-items-center">
	                <div>
	                    <strong>${mesa.numero}</strong>
	                    <div class="small text-muted">${mesa.tipo_nombre}</div>
	                </div>
	                <div>
	                    ${mesa.empleado_nombre ? 
	                        `<span class="badge bg-success">${mesa.empleado_nombre}</span>` : 
	                        `<span class="badge bg-secondary">Disponible</span>`
	                    }
	                </div>
	            </div>
	        `;
	        
	        div.addEventListener('click', function() {
	            seleccionarMesa(mesa.id);
	        });
	        
	        listaMesas.appendChild(div);
	    });
	}
        
        // Función para filtrar mesas
        function filtrarMesas() {
            const filtro = document.getElementById('buscar_mesa').value.toLowerCase();
            const items = document.querySelectorAll('#lista_mesas .mesa-item');
            
            items.forEach(item => {
                const texto = item.textContent.toLowerCase();
                item.style.display = texto.includes(filtro) ? 'block' : 'none';
            });
        }
        
	// Función para seleccionar una mesa
	function seleccionarMesa(mesaId) {
	    // Buscar la mesa en el canvas
	    const objetos = canvas.getObjects();
	    for (let i = 0; i < objetos.length; i++) {
	        if (objetos[i].mesaId === mesaId) {
	            canvas.setActiveObject(objetos[i]);
	            mesaSeleccionada = objetos[i];
	            actualizarInfoMesaSeleccionada();
	            
	            // Resaltar la mesa en el plano
	            objetos.forEach(obj => {
	                if (obj.mesaId) {
	                    // Restablecer todas las mesas
	                    if (obj.mesaId === mesaId) {
	                        // Resaltar la mesa seleccionada
	                        obj.set({
	                            strokeWidth: 5,
	                            stroke: '#ff0000'
	                        });
	                    } else {
	                        // Restablecer las otras mesas
	                        const otraMesa = mesas.find(m => m.id === obj.mesaId);
	                        if (otraMesa) {
	                            obj.set({
	                                strokeWidth: 3,
	                                stroke: otraMesa.color
	                            });
	                        }
	                    }
	                }
	            });
	            
	            canvas.renderAll();
	            
	            // Resaltar la mesa seleccionada en la lista
	            document.querySelectorAll('#lista_mesas .mesa-item').forEach(item => {
	                item.classList.remove('selected');
	            });
	            
	            // Buscar el elemento por el atributo data-mesa-id
	            const mesaItem = document.querySelector(`#lista_mesas .mesa-item[data-mesa-id="${mesaId}"]`);
	            if (mesaItem) {
	                mesaItem.classList.add('selected');
	            }
	            
	            break;
	        }
	    }
	}
        
        // Función para actualizar la información de la mesa seleccionada
        function actualizarInfoMesaSeleccionada() {
            const mesaSeleccionadaSpan = document.getElementById('mesa_seleccionada');
            const empleadoAsignadoSpan = document.getElementById('empleado_asignado');
            const btnAsignar = document.getElementById('btn_asignar_empleado');
            const btnDesasignar = document.getElementById('btn_desasignar_empleado');
            
            if (mesaSeleccionada) {
                mesaSeleccionadaSpan.textContent = mesaSeleccionada.mesaNumero;
                
                const mesa = mesas.find(m => m.id === mesaSeleccionada.mesaId);
                if (mesa && mesa.empleado_nombre) {
                    empleadoAsignadoSpan.textContent = mesa.empleado_nombre;
                    btnDesasignar.disabled = false;
                } else {
                    empleadoAsignadoSpan.textContent = 'Ninguno';
                    btnDesasignar.disabled = true;
                }
                
                btnAsignar.disabled = false;
            } else {
                mesaSeleccionadaSpan.textContent = 'Ninguna';
                empleadoAsignadoSpan.textContent = 'Ninguno';
                btnAsignar.disabled = true;
                btnDesasignar.disabled = true;
            }
        }
        
        // Función para cargar la lista de empleados
	function cargarEmpleados() {
	    fetch(`../includes/obtener_empleados_sede.php?sede_id=<?php echo $sede_id; ?>`)
	        .then(response => response.json())
	        .then(data => {
	            if (data.success) {
	                const listaEmpleados = document.getElementById('lista_empleados');
	                listaEmpleados.innerHTML = '';
	                
	                data.empleados.forEach(empleado => {
	                    const div = document.createElement('div');
	                    div.className = 'employee-item';
	                    div.dataset.empleadoId = empleado.id;
	                    
	                    div.innerHTML = `
	                        <div class="d-flex justify-content-between align-items-center">
	                            <div>
	                                <strong>${empleado.first_Name} ${empleado.first_LastName}</strong>
	                                <div class="small text-muted">${empleado.position || 'Sin cargo'}</div>
	                            </div>
	                            <div>
	                                ${empleado.mesa_numero ? 
	                                    `<span class="badge bg-primary">${empleado.mesa_numero}</span>` : 
	                                    `<span class="badge bg-secondary">Sin mesa</span>`
	                                }
	                            </div>
	                        </div>
	                    `;
	                    
	                    div.addEventListener('click', function() {
	                        seleccionarEmpleado(empleado.id, empleado.first_Name + ' ' + empleado.first_LastName);
	                    });
	                    
	                    listaEmpleados.appendChild(div);
	                });
	            }
	        })
	        .catch(error => {
	            console.error('Error al cargar los empleados:', error);
	        });
	}
        
        // Función para filtrar empleados
        function filtrarEmpleados() {
            const filtro = document.getElementById('buscar_empleado').value.toLowerCase();
            const items = document.querySelectorAll('#lista_empleados .employee-item');
            
            items.forEach(item => {
                const texto = item.textContent.toLowerCase();
                item.style.display = texto.includes(filtro) ? 'block' : 'none';
            });
        }
        
        // Función para seleccionar un empleado
        function seleccionarEmpleado(empleadoId, empleadoNombre) {
            // Actualizar UI
            document.querySelectorAll('#lista_empleados .employee-item').forEach(item => {
                item.classList.remove('selected');
            });
            
            const itemSeleccionado = document.querySelector(`#lista_empleados .employee-item[data-empleado-id="${empleadoId}"]`);
            if (itemSeleccionado) {
                itemSeleccionado.classList.add('selected');
            }
            
            empleadoSeleccionado = {
                id: empleadoId,
                nombre: empleadoNombre
            };
        }
        
        // Función para asignar un empleado a una mesa
	function asignarEmpleado() {
	    if (!mesaSeleccionada || !empleadoSeleccionado) {
	        alert('Seleccione una mesa y un empleado');
	        return;
	    }
	    
	    const mesa = mesas.find(m => m.id === mesaSeleccionada.mesaId);
	    if (!mesa) {
	        alert('Error al encontrar la mesa seleccionada');
	        return;
	    }
	
	    // Verificar si el empleado ya tiene una mesa asignada para mostrar un mensaje de confirmación adecuado
	    const empleadoConMesa = mesas.find(m => m.empleado_id === empleadoSeleccionado.id);
	    let confirmMessage = `¿Está seguro de asignar a ${empleadoSeleccionado.nombre} a la mesa ${mesa.numero}?`;
	    if (empleadoConMesa) {
	        confirmMessage = `El empleado ${empleadoSeleccionado.nombre} ya tiene asignada la mesa ${empleadoConMesa.numero}. ¿Desea reemplazarla?`;
	    }
	    
	    if (!confirm(confirmMessage)) {
	        return;
	    }
	    
	    // Enviar datos al servidor (SIN modificar el estado local aún)
	    const formData = new FormData();
	    formData.append('mesa_id', mesa.id);
	    formData.append('empleado_id', empleadoSeleccionado.id);
	    formData.append('asignar_empleado', '1');
	    
	    fetch('../includes/asignar_mesa.php', {
	        method: 'POST',
	        body: formData
	    })
	    .then(response => response.json())
	    .then(data => {
	        if (data.success) {
	            // ---- SOLO AHORA ACTUALIZAMOS EL ESTADO LOCAL ----
	            
	            // 1. Desasignar de la mesa anterior (si existía)
	            if (empleadoConMesa) {
	                empleadoConMesa.empleado_id = null;
	                empleadoConMesa.empleado_nombre = null;
	            }
	            
	            // 2. Asignar a la nueva mesa
	            mesa.empleado_id = empleadoSeleccionado.id;
	            mesa.empleado_nombre = empleadoSeleccionado.nombre;
	            
	            // 3. Actualizar el objeto del canvas para el tooltip
	            mesaSeleccionada.mesaEmpleado = empleadoSeleccionado.nombre;
	
	            // 4. Actualizar todas las vistas
	            dibujarMesas();
	            cargarListaMesas();
	            cargarEmpleados();
	            actualizarInfoMesaSeleccionada();
	            
	            // 5. Limpiar selección de empleado
	            empleadoSeleccionado = null;
	            document.querySelectorAll('#lista_empleados .employee-item').forEach(item => {
	                item.classList.remove('selected');
	            });
	        } else {
	            alert('Error al asignar el empleado: ' + data.error);
	        }
	    })
	    .catch(error => {
	        console.error('Error:', error);
	        alert('Error al asignar el empleado');
	    });
	}
        
	// Función para desasignar un empleado de una mesa
	function desasignarEmpleado() {
	    if (!mesaSeleccionada) {
	        alert('Seleccione una mesa');
	        return;
	    }
	    
	    const mesa = mesas.find(m => m.id === mesaSeleccionada.mesaId);
	    // Esta comprobación ahora funcionará correctamente gracias al cambio en PHP
	    if (!mesa || !mesa.empleado_id) {
	        alert('La mesa seleccionada no tiene un empleado asignado');
	        return;
	    }
	    
	    if (!confirm(`¿Está seguro de desasignar a ${mesa.empleado_nombre} de la mesa ${mesa.numero}?`)) {
	        return;
	    }
	    
	    // Enviar datos al servidor (SIN modificar el estado local aún)
	    const formData = new FormData();
	    formData.append('mesa_id', mesa.id);
	    formData.append('desasignar_empleado', '1');
	    
	    fetch('../includes/asignar_mesa.php', {
	        method: 'POST',
	        body: formData
	    })
	    .then(response => response.json())
	    .then(data => {
	        if (data.success) {
	            // ---- SOLO AHORA ACTUALIZAMOS EL ESTADO LOCAL ----
	            mesa.empleado_id = null;
	            mesa.empleado_nombre = null;
	            
	            // Actualizar el objeto del canvas para el tooltip
	            mesaSeleccionada.mesaEmpleado = null;
	
	            // Actualizar las vistas sin recargar todo el plano
	            dibujarMesas();
	            cargarListaMesas();
	            cargarEmpleados();
	            actualizarInfoMesaSeleccionada();
	        } else {
	            alert('Error al desasignar el empleado: ' + data.error);
	        }
	    })
	    .catch(error => {
	        console.error('Error:', error);
	        alert('Error al desasignar el empleado');
	    });
	}
        
        // Función para mostrar el modal de tipos de mesa
        function mostrarModalTiposMesa() {
            // Cargar tipos de mesa existentes
            fetch('../includes/obtener_tipos_mesa.php')
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const tablaTiposMesa = document.getElementById('tabla_tipos_mesa');
                        tablaTiposMesa.innerHTML = '';
                        
                        data.tipos.forEach(tipo => {
                            const tr = document.createElement('tr');
                            tr.innerHTML = `
                                <td>${tipo.nombre}</td>
                                <td>
                                    <span style="display: inline-block; width: 20px; height: 20px; background-color: ${tipo.color}; border-radius: 3px;"></span> 
                                    ${tipo.color}
                                </td>
                                <td>${tipo.ancho} x ${tipo.alto}</td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="editarTipoMesa(${tipo.id})">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarTipoMesa(${tipo.id})">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            `;
                            tablaTiposMesa.appendChild(tr);
                        });
                        
                        // Mostrar el modal
                        const modal = new bootstrap.Modal(document.getElementById('modalTiposMesa'));
                        modal.show();
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al cargar los tipos de mesa');
                });
        }
        
        // Función para mostrar el formulario de nuevo tipo de mesa
        function mostrarFormularioNuevoTipoMesa() {
            document.getElementById('formulario_tipo_mesa').style.display = 'block';
        }
        
        // Función para ocultar el formulario de nuevo tipo de mesa
        function ocultarFormularioNuevoTipoMesa() {
            document.getElementById('formulario_tipo_mesa').style.display = 'none';
        }
        
        // Función para guardar un nuevo tipo de mesa
        function guardarNuevoTipoMesa() {
            const nombre = document.getElementById('nuevo_tipo_nombre').value;
            const color = document.getElementById('nuevo_tipo_color').value;
            const ancho = document.getElementById('nuevo_tipo_ancho').value;
            const alto = document.getElementById('nuevo_tipo_alto').value;
            
            if (!nombre) {
                alert('Ingrese un nombre para el tipo de mesa');
                return;
            }
            
            // Enviar datos al servidor
            const formData = new FormData();
            formData.append('nombre', nombre);
            formData.append('color', color);
            formData.append('ancho', ancho);
            formData.append('alto', alto);
            formData.append('agregar_tipo_mesa', '1');
            
            fetch('../includes/gestionar_tipos_mesa.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Tipo de mesa agregado correctamente');
                    
                    // Limpiar formulario
                    document.getElementById('nuevo_tipo_nombre').value = '';
                    document.getElementById('nuevo_tipo_color').value = '#003a5d';
                    document.getElementById('nuevo_tipo_ancho').value = '80';
                    document.getElementById('nuevo_tipo_alto').value = '60';
                    
                    // Ocultar formulario
                    ocultarFormularioNuevoTipoMesa();
                    
                    // Recargar la lista de tipos de mesa
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al agregar el tipo de mesa');
            });
        }
        
        // Función para editar un tipo de mesa
	function editarTipoMesa(tipoId) {
	    // Obtener datos del tipo de mesa usando el archivo existente
	    fetch(`../includes/obtener_tipos_mesa.php?id=${tipoId}`)
	        .then(response => {
	            if (!response.ok) {
	                throw new Error('Error en la respuesta del servidor');
	            }
	            return response.json();
	        })
	        .then(data => {
	            if (data.success) {
	                const tipo = data.tipo;
	                
	                // Llenar el formulario con los datos del tipo
	                document.getElementById('edit_tipo_nombre').value = tipo.nombre;
	                document.getElementById('edit_tipo_color').value = tipo.color;
	                document.getElementById('edit_tipo_ancho').value = tipo.ancho;
	                document.getElementById('edit_tipo_alto').value = tipo.alto;
	                
	                // Guardar el ID del tipo para usarlo al guardar
	                let editTipoIdInput = document.getElementById('edit_tipo_id');
	                if (!editTipoIdInput) {
	                    editTipoIdInput = document.createElement('input');
	                    editTipoIdInput.type = 'hidden';
	                    editTipoIdInput.id = 'edit_tipo_id';
	                    document.getElementById('modalEditarTipoMesa').appendChild(editTipoIdInput);
	                }
	                editTipoIdInput.value = tipo.id;
	                
	                // Mostrar el modal
	                const modal = new bootstrap.Modal(document.getElementById('modalEditarTipoMesa'));
	                modal.show();
	            } else {
	                alert('Error: ' + data.error);
	            }
	        })
	        .catch(error => {
	            console.error('Error:', error);
	            alert('Error al cargar los datos del tipo de mesa');
	        });
	}
        
        // Función para guardar cambios en un tipo de mesa
        function guardarCambiosTipoMesa() {
            const id = document.getElementById('edit_tipo_id').value;
            const nombre = document.getElementById('edit_tipo_nombre').value;
            const color = document.getElementById('edit_tipo_color').value;
            const ancho = document.getElementById('edit_tipo_ancho').value;
            const alto = document.getElementById('edit_tipo_alto').value;
            
            if (!nombre) {
                alert('Ingrese un nombre para el tipo de mesa');
                return;
            }
            
            // Enviar datos al servidor
            const formData = new FormData();
            formData.append('id', id);
            formData.append('nombre', nombre);
            formData.append('color', color);
            formData.append('ancho', ancho);
            formData.append('alto', alto);
            formData.append('editar_tipo_mesa', '1');
            
            fetch('../includes/gestionar_tipos_mesa.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Tipo de mesa actualizado correctamente');
                    
                    // Cerrar el modal
                    const modal = bootstrap.Modal.getInstance(document.getElementById('modalEditarTipoMesa'));
                    modal.hide();
                    
                    // Recargar la página para mostrar los cambios
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Error al actualizar el tipo de mesa');
            });
        }
        
        // Función para eliminar un tipo de mesa
        function eliminarTipoMesa(tipoId) {
            if (confirm('¿Está seguro de eliminar este tipo de mesa?')) {
                const formData = new FormData();
                formData.append('tipo_mesa_id', tipoId);
                formData.append('eliminar_tipo_mesa', '1');
                
                fetch('../includes/gestionar_tipos_mesa.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Tipo de mesa eliminado correctamente');
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al eliminar el tipo de mesa');
                });
            }
        }
        
        function mostrarFormularioNuevoTipoMesaDirecto() {
	    // Mostrar el modal
	    const modal = new bootstrap.Modal(document.getElementById('modalTiposMesa'));
	    modal.show();
	    
	    // Mostrar el formulario de nuevo tipo
	    mostrarFormularioNuevoTipoMesa();
	}
	
	// Función para agregar un nuevo piso
function agregarNuevoPiso() {
    // Obtener el número del siguiente piso
    const pisosActuales = document.querySelectorAll('#piso-tabs .piso-tab');
    let numerosPiso = [];
    
    pisosActuales.forEach(pisoTab => {
        const texto = pisoTab.textContent.trim();
        const match = texto.match(/Piso (\d+)/);
        if (match) {
            numerosPiso.push(parseInt(match[1]));
        }
    });
    
    // Encontrar el siguiente número disponible
    const siguienteNumero = numerosPiso.length > 0 ? Math.max(...numerosPiso) + 1 : 1;
    const nombrePiso = `Piso ${siguienteNumero}`;
    
    // Verificar si es Tequendama para solicitar la oficina
    const esTequendama = <?php echo stripos($sede['nombre'], 'tequendama') !== false ? 'true' : 'false'; ?>;
    
    if (esTequendama) {
        // Mostrar diálogo para seleccionar oficina
        const oficinas = ['Tequendama 1', 'Tequendama 2', 'Tequendama 3'];
        let opcionesOficina = '';
        
        oficinas.forEach(oficina => {
            opcionesOficina += `<option value="${oficina}">${oficina}</option>`;
        });
        
        const modalHtml = `
            <div class="modal fade" id="modalNuevoPiso" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Agregar Nuevo Piso</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label for="nombre_piso" class="form-label">Nombre del Piso</label>
                                <input type="text" class="form-control" id="nombre_piso" value="${nombrePiso}" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="numero_piso" class="form-label">Número del Piso</label>
                                <input type="number" class="form-control" id="numero_piso" value="${siguienteNumero}" readonly>
                            </div>
                            <div class="mb-3">
                                <label for="oficina_piso" class="form-label">Oficina</label>
                                <select class="form-select" id="oficina_piso">
                                    ${opcionesOficina}
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="button" class="btn btn-primary" onclick="guardarNuevoPiso()">Guardar</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
        
        // Agregar el modal al DOM
        const modalContainer = document.createElement('div');
        modalContainer.innerHTML = modalHtml;
        document.body.appendChild(modalContainer);
        
        // Mostrar el modal
        const modal = new bootstrap.Modal(document.getElementById('modalNuevoPiso'));
        modal.show();
    } else {
        // Para sedes que no son Tequendama, crear directamente
        crearPiso(nombrePiso, siguienteNumero, null);
    }
}

// Función para crear el piso en el servidor
function crearPiso(nombre, numero, oficina) {
    // Enviar datos para agregar el piso
    const formData = new FormData();
    formData.append('sede_id', <?php echo $sede_id; ?>);
    formData.append('nombre', nombre);
    formData.append('numero', numero);
    if (oficina) {
        formData.append('oficina', oficina);
    }
    formData.append('agregar_piso', '1');
    
    fetch('../includes/gestionar_pisos.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Piso agregado correctamente');
            
            // Cerrar el modal si existe
            const modal = document.getElementById('modalNuevoPiso');
            if (modal) {
                const modalInstance = bootstrap.Modal.getInstance(modal);
                if (modalInstance) {
                    modalInstance.hide();
                }
            }
            
            // Recargar la página para mostrar el nuevo piso
            location.reload();
        } else {
            alert('Error: ' + data.error);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error al agregar el piso');
    });
}

// Función para guardar el nuevo piso (para Tequendama)
function guardarNuevoPiso() {
    const nombre = document.getElementById('nombre_piso').value;
    const numero = document.getElementById('numero_piso').value;
    const oficina = document.getElementById('oficina_piso').value;
    
    crearPiso(nombre, numero, oficina);
}

    </script>
</body>
</html>