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

// Obtener todas las sedes activas
 $sedes = $planoManager->obtenerSedes();

// Obtener estadísticas generales
 $estadisticas = $planoManager->obtenerEstadisticasGenerales();

// Obtener estadísticas de empleados por sede
 $estadisticas_sedes = [];
foreach ($sedes as $sede) {
    // Obtener número de empleados por sede
    $query = "SELECT COUNT(*) as total_empleados 
              FROM employee 
              WHERE sede_id = :sede_id AND role != 'retirado'";
    $stmt = $conn->prepare($query);
    $stmt->bindParam(':sede_id', $sede['id']);
    $stmt->execute();
    $total_empleados = $stmt->fetch(PDO::FETCH_ASSOC)['total_empleados'];
    
    // Obtener estadísticas de la sede/oficina
    if (stripos($sede['nombre'], 'tequendama') !== false) {
        // Para Tequendama, obtener estadísticas por oficina
        $estadisticas_oficinas = [];
        $oficinas = ['Tequendama 1', 'Tequendama 2', 'Tequendama 3'];
        
        foreach ($oficinas as $oficina) {
            $estadisticas_oficina = $planoManager->obtenerEstadisticasOficina($sede['id'], $oficina);
            $estadisticas_oficinas[$oficina] = $estadisticas_oficina;
        }
        
        $estadisticas_sedes[$sede['id']] = [
            'estadisticas_generales' => $planoManager->obtenerEstadisticasSede($sede['id']),
            'total_empleados' => $total_empleados,
            'es_tequendama' => true,
            'estadisticas_oficinas' => $estadisticas_oficinas
        ];
    } else {
        // Para otras sedes
        $estadisticas_sede = $planoManager->obtenerEstadisticasSede($sede['id']);
        $estadisticas_sedes[$sede['id']] = [
            'estadisticas_generales' => $estadisticas_sede,
            'total_empleados' => $total_empleados,
            'es_tequendama' => false
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestión de Planos de Sedes - <?php echo SITE_NAME; ?></title>
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
        
        /* Estilos específicos para esta página */
        .sede-card {
            transition: transform 0.3s;
            cursor: pointer;
            height: 100%;
            border: 1px solid #e9ecef;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .sede-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        
        .sede-card .card-header {
            background-color: #003a5d;
            color: white;
            font-weight: 600;
            padding: 15px;
        }
        
        .sede-card .card-body {
            padding: 20px;
        }
        
        .stat-mini {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .stat-mini-label {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .stat-mini-value {
            font-size: 0.9rem;
            font-weight: 600;
        }
        
        .tequendama-subcard {
            background-color: #f8f9fa;
            border-left: 4px solid #003a5d;
            padding: 12px;
            margin-bottom: 10px;
            border-radius: 0 5px 5px 0;
            cursor: pointer;
            transition: background-color 0.2s;
        }
        
        .tequendama-subcard:hover {
            background-color: #e9ecef;
        }
        
        .piso-badge {
            font-size: 0.75rem;
            padding: 2px 6px;
            margin-right: 3px;
        }
        
        .estadisticas-generales {
            background-color: #f8f9fa;
            border-radius: 10px;
            padding: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background-color: #ffffff;
            border: 1px solid #e9ecef;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
            transition: transform 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: bold;
            color: #003a5d;
        }
        
        .stat-label {
            font-size: 0.9rem;
            color: #6c757d;
        }
        
        .estadistica-mini {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f1f3f4;
        }
        
        .estadistica-mini:last-child {
            border-bottom: none;
        }
        
        .estadistica-label {
            font-size: 0.85rem;
            color: #6c757d;
        }
        
        .estadistica-valor {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .estadistica-valor.total {
            color: #003a5d;
        }
        
        .estadistica-valor.ocupadas {
            color: #198754;
        }
        
        .estadistica-valor.disponibles {
            color: #6c757d;
        }
        
        .estadistica-valor.utilizacion {
            color: #ffc107;
        }
        
        .estadistica-valor.empleados {
            color: #003a5d;
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
                    <h1 class="h2">Gestión de Planos de Sedes</h1>
                </div>
                
                <!-- Estadísticas generales -->
                <div class="estadisticas-generales">
                    <h4 class="mb-4">Estadísticas Generales</h4>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $estadisticas['total_mesas']; ?></div>
                                <div class="stat-label">Total de Estaciones</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $estadisticas['mesas_disponibles']; ?></div>
                                <div class="stat-label">Estaciones Disponibles</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $estadisticas['mesas_ocupadas']; ?></div>
                                <div class="stat-label">Estaciones Ocupadas</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="stat-card">
                                <div class="stat-number"><?php echo $estadisticas['tasa_utilizacion']; ?>%</div>
                                <div class="stat-label">Tasa de Utilización</div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Tarjetas de sedes -->
                <div class="row">
                    <?php foreach ($sedes as $sede): ?>
                    <div class="col-md-4 mb-4">
                        <div class="card sede-card" onclick="verSede(<?php echo $sede['id']; ?>)">
                            <div class="card-header">
                                <h5 class="card-title mb-0"><?php echo htmlspecialchars($sede['nombre']); ?></h5>
                            </div>
                            <div class="card-body">
                                <?php 
                                // Caso especial para Tequendama
                                if (stripos($sede['nombre'], 'tequendama') !== false): 
                                    $pisos_tequendama = $planoManager->obtenerPisosPorSede($sede['id']);
                                    $oficinas_tequendama = [];
                                    
                                    // Agrupar pisos por oficina, excluyendo los que no tienen oficina definida
                                    foreach ($pisos_tequendama as $piso) {
                                        if ($piso['oficina']) {
                                            if (!isset($oficinas_tequendama[$piso['oficina']])) {
                                                $oficinas_tequendama[$piso['oficina']] = [];
                                            }
                                            $oficinas_tequendama[$piso['oficina']][] = $piso;
                                        }
                                    }
                                    
                                    // Mostrar estadísticas generales de Tequendama
                                    $estadisticas_generales = $estadisticas_sedes[$sede['id']]['estadisticas_generales'];
                                ?>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Empleados:</span>
                                        <span class="estadistica-valor empleados"><?php echo $estadisticas_sedes[$sede['id']]['total_empleados']; ?></span>
                                    </div>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Estaciones totales:</span>
                                        <span class="estadistica-valor total"><?php echo $estadisticas_generales['total_mesas']; ?></span>
                                    </div>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Ocupadas:</span>
                                        <span class="estadistica-valor ocupadas"><?php echo $estadisticas_generales['mesas_ocupadas']; ?></span>
                                    </div>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Disponibles:</span>
                                        <span class="estadistica-valor disponibles"><?php echo $estadisticas_generales['mesas_disponibles']; ?></span>
                                    </div>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Utilización:</span>
                                        <span class="estadistica-valor utilizacion"><?php echo $estadisticas_generales['tasa_utilizacion']; ?>%</span>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <small class="text-muted">Oficinas:</small>
                                    </div>
                                    
                                    <?php foreach ($oficinas_tequendama as $oficina => $pisos): ?>
                                    <div class="tequendama-subcard" onclick="verOficinaTequendama(event, <?php echo $sede['id']; ?>, '<?php echo $oficina; ?>')">
                                        <div class="d-flex justify-content-between align-items-center">
                                            <div>
                                                <h6 class="mb-1"><?php echo htmlspecialchars($oficina); ?></h6>
                                                <div>
                                                    <?php foreach ($pisos as $piso): ?>
                                                        <span class="badge bg-secondary piso-badge">Piso <?php echo $piso['numero']; ?></span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>
                                            <div class="text-end">
                                                <small class="text-muted d-block"><?php 
                                                $estadisticas_oficina = $estadisticas_sedes[$sede['id']]['estadisticas_oficinas'][$oficina];
                                                echo "{$estadisticas_oficina['mesas_ocupadas']}/{$estadisticas_oficina['total_mesas']}";
                                                ?></small>
                                                <div class="badge bg-warning"><?php echo $estadisticas_oficina['tasa_utilizacion']; ?>%</div>
                                            </div>
                                        </div>
                                    </div>
                                <?php 
                                    endforeach;
                                else:
                                    // Sedes normales
                                    $pisos = $planoManager->obtenerPisosPorSede($sede['id']);
                                    $estadisticas_sede = $estadisticas_sedes[$sede['id']]['estadisticas_generales'];
                                ?>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Empleados:</span>
                                        <span class="estadistica-valor empleados"><?php echo $estadisticas_sedes[$sede['id']]['total_empleados']; ?></span>
                                    </div>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Estaciones totales:</span>
                                        <span class="estadistica-valor total"><?php echo $estadisticas_sede['total_mesas']; ?></span>
                                    </div>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Ocupadas:</span>
                                        <span class="estadistica-valor ocupadas"><?php echo $estadisticas_sede['mesas_ocupadas']; ?></span>
                                    </div>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Disponibles:</span>
                                        <span class="estadistica-valor disponibles"><?php echo $estadisticas_sede['mesas_disponibles']; ?></span>
                                    </div>
                                    <div class="estadistica-mini">
                                        <span class="estadistica-label">Utilización:</span>
                                        <span class="estadistica-valor utilizacion"><?php echo $estadisticas_sede['tasa_utilizacion']; ?>%</span>
                                    </div>
                                    
                                    <div class="mt-3">
                                        <small class="text-muted">Pisos:</small>
                                        <div>
                                            <?php foreach ($pisos as $piso): ?>
                                                <span class="badge bg-secondary piso-badge">Piso <?php echo $piso['numero']; ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </main>
        </div>
    </div>
    
    <!-- Modal de configuración -->
    <div class="modal fade" id="modalConfiguracion" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Configuración de Planos</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="sede_config" class="form-label">Seleccionar Sede</label>
                        <select class="form-select" id="sede_config">
                            <option value="">Seleccione una sede</option>
                            <?php foreach ($sedes as $sede): ?>
                            <option value="<?php echo $sede['id']; ?>"><?php echo htmlspecialchars($sede['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label for="accion_config" class="form-label">Acción</label>
                        <select class="form-select" id="accion_config">
                            <option value="">Seleccione una acción</option>
                            <option value="agregar_piso">Agregar Piso</option>
                            <option value="gestionar_tipos_mesa">Gestionar Tipos de Mesa</option>
                        </select>
                    </div>
                    <div id="detalles_configuracion"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="button" class="btn btn-primary" id="btn_guardar_configuracion">Guardar</button>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Función para ver una sede normal
        function verSede(sedeId) {
            window.location.href = 'plano_sede.php?sede_id=' + sedeId;
        }
        
        // Función para ver una oficina específica de Tequendama
        function verOficinaTequendama(event, sedeId, oficina) {
            event.stopPropagation();
            window.location.href = 'plano_sede.php?sede_id=' + sedeId + '&oficina=' + encodeURIComponent(oficina);
        }
        
        // Función para mostrar el modal de configuración
        function mostrarModalConfiguracion() {
            const modal = new bootstrap.Modal(document.getElementById('modalConfiguracion'));
            modal.show();
        }
        
        // Event listener para cambios en el selector de acción
        document.getElementById('accion_config').addEventListener('change', function() {
            const accion = this.value;
            const detallesDiv = document.getElementById('detalles_configuracion');
            
            detallesDiv.innerHTML = '';
            
            if (accion === 'agregar_piso') {
                detallesDiv.innerHTML = `
                    <div class="mb-3">
                        <label for="nombre_piso" class="form-label">Nombre del Piso</label>
                        <input type="text" class="form-control" id="nombre_piso" placeholder="Ej: Piso 1">
                    </div>
                    <div class="mb-3">
                        <label for="numero_piso" class="form-label">Número del Piso</label>
                        <input type="number" class="form-control" id="numero_piso" min="1">
                    </div>
                    <div class="mb-3" id="div_oficina_tequendama" style="display: none;">
                        <label for="oficina_piso" class="form-label">Oficina (solo para Tequendama)</label>
                        <select class="form-select" id="oficina_piso">
                            <option value="Tequendama 1">Tequendama 1</option>
                            <option value="Tequendama 2">Tequendama 2</option>
                            <option value="Tequendama 3">Tequendama 3</option>
                        </select>
                    </div>
                `;
                
                // Mostrar campo de oficina si se selecciona Tequendama
                document.getElementById('sede_config').addEventListener('change', function() {
                    const sedeId = this.value;
                    const divOficina = document.getElementById('div_oficina_tequendama');
                    
                    // Obtener el nombre de la sede seleccionada
                    const sedeOption = this.options[this.selectedIndex];
                    const sedeNombre = sedeOption.text.toLowerCase();
                    
                    if (sedeNombre.includes('tequendama')) {
                        divOficina.style.display = 'block';
                    } else {
                        divOficina.style.display = 'none';
                    }
                });
            } else if (accion === 'gestionar_tipos_mesa') {
                // Cargar tipos de mesa existentes
                fetch('../includes/obtener_tipos_mesa.php')
                    .then(response => response.json())
                    .then(data => {
                        let html = '<h6>Tipos de Mesa Existentes</h6>';
                        html += '<div class="table-responsive">';
                        html += '<table class="table table-sm">';
                        html += '<thead><tr><th>Nombre</th><th>Color</th><th>Dimensiones</th><th>Acciones</th></tr></thead>';
                        html += '<tbody>';
                        
                        data.tipos.forEach(tipo => {
                            html += `<tr>`;
                            html += `<td>${tipo.nombre}</td>`;
                            html += `<td><span style="display: inline-block; width: 20px; height: 20px; background-color: ${tipo.color}; border-radius: 3px;"></span> ${tipo.color}</td>`;
                            html += `<td>${tipo.ancho} x ${tipo.alto}</td>`;
                            html += `<td>
                                        <button type="button" class="btn btn-sm btn-outline-primary" onclick="editarTipoMesa(${tipo.id})">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="eliminarTipoMesa(${tipo.id})">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                     </td>`;
                            html += `</tr>`;
                        });
                        
                        html += '</tbody></table></div>';
                        
                        html += '<h6 class="mt-3">Agregar Nuevo Tipo de Mesa</h6>';
                        html += `
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
                        `;
                        
                        detallesDiv.innerHTML = html;
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        detallesDiv.innerHTML = '<div class="alert alert-danger">Error al cargar los tipos de mesa</div>';
                    });
            }
        });
        
        // Event listener para guardar configuración
        document.getElementById('btn_guardar_configuracion').addEventListener('click', function() {
            const sedeId = document.getElementById('sede_config').value;
            const accion = document.getElementById('accion_config').value;
            
            if (!sedeId || !accion) {
                alert('Por favor, complete todos los campos');
                return;
            }
            
            if (accion === 'agregar_piso') {
                const nombre = document.getElementById('nombre_piso').value;
                const numero = document.getElementById('numero_piso').value;
                const oficina = document.getElementById('oficina_piso').value;
                
                if (!nombre || !numero) {
                    alert('Por favor, complete el nombre y número del piso');
                    return;
                }
                
                // Enviar datos para agregar el piso
                const formData = new FormData();
                formData.append('sede_id', sedeId);
                formData.append('nombre', nombre);
                formData.append('numero', numero);
                formData.append('oficina', oficina);
                formData.append('agregar_piso', '1');
                
                fetch('../includes/gestionar_pisos.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('Piso agregado correctamente');
                        location.reload();
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al agregar el piso');
                });
            } else if (accion === 'gestionar_tipos_mesa') {
                const nombre = document.getElementById('nuevo_tipo_nombre').value;
                const color = document.getElementById('nuevo_tipo_color').value;
                const ancho = document.getElementById('nuevo_tipo_ancho').value;
                const alto = document.getElementById('nuevo_tipo_alto').value;
                
                if (!nombre) {
                    alert('Por favor, ingrese el nombre del tipo de mesa');
                    return;
                }
                
                // Enviar datos para agregar el tipo de mesa
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
                        document.getElementById('accion_config').dispatchEvent(new Event('change'));
                    } else {
                        alert('Error: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('Error al agregar el tipo de mesa');
                });
            }
        });
        
        // Función para editar tipo de mesa
        function editarTipoMesa(tipoId) {
            // Implementar edición de tipo de mesa
            alert('Función de edición de tipo de mesa no implementada aún');
        }
        
        // Función para eliminar tipo de mesa
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
                        document.getElementById('accion_config').dispatchEvent(new Event('change'));
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
    </script>
</body>
</html>