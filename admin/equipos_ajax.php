<?php
require_once '../includes/auth.php';
require_once '../includes/functions.php';
require_once '../includes/validar_permiso.php';

// Función para calcular antigüedad (para uso en AJAX)
function calcularAntiguedadAjax($fechaCreacion) {
    if (empty($fechaCreacion)) {
        return "Fecha no disponible";
    }
    
    $fechaCreacion = new DateTime($fechaCreacion);
    $fechaActual = new DateTime();
    $diferencia = $fechaActual->diff($fechaCreacion);
    
    $anos = $diferencia->y;
    $meses = $diferencia->m;
    $dias = $diferencia->d;
    
    if ($anos > 0) {
        return $anos . " año" . ($anos > 1 ? 's' : '') . 
               ($meses > 0 ? " y " . $meses . " mes" . ($meses > 1 ? 'es' : '') : '');
    } elseif ($meses > 0) {
        return $meses . " mes" . ($meses > 1 ? 'es' : '') . 
               ($dias > 0 ? " y " . $dias . " día" . ($dias > 1 ? 's' : '') : '');
    } else {
        return $dias . " día" . ($dias > 1 ? 's' : '');
    }
}


$auth = new Auth();
$auth->redirectIfNotLoggedIn();

// Obtener parámetros
$buscar = $_GET['buscar'] ?? '';
$filtro = $_GET['filtro'] ?? 'todos';
$filtroSede = $_GET['sede'] ?? '';
$pagina = isset($_GET['pagina']) ? max(1, intval($_GET['pagina'])) : 1;
$resultados_por_pagina = isset($_GET['resultados_por_pagina']) ? max(10, intval($_GET['resultados_por_pagina'])) : 20;

// Calcular offset
$offset = ($pagina - 1) * $resultados_por_pagina;

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

 $permiso_editar_equipo = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'equipos_tabla', 'editar_equipo');
 $permiso_eliminar_equipo = tienePermiso($conn, $usuario_actual['id'], $usuario_actual['role'], $usuario_actual['position_id'], 'equipos_tabla', 'eliminar_equipo');

// Obtener sedes
$query_sedes = "SELECT id, nombre FROM sedes ORDER BY nombre";
$stmt_sedes = $conn->prepare($query_sedes);
$stmt_sedes->execute();
$sedes = $stmt_sedes->fetchAll(PDO::FETCH_ASSOC);

// Construir consulta base
$query = "SELECT SQL_CALC_FOUND_ROWS e.*, 
                 s.nombre as sede_nombre,
                 CONCAT(emp.first_Name, ' ', emp.first_LastName) as admin_nombre,
                 CONCAT(emp_user.first_Name, ' ', emp_user.first_LastName) as nombre_usuario,
                 emp_user.CC as usuario_cc,
                 COALESCE(emp_user_sede.nombre, s.nombre) as sede_final
          FROM equipos e 
          LEFT JOIN sedes s ON e.sede_id = s.id
          LEFT JOIN employee emp ON e.it_admin_id = emp.id 
          LEFT JOIN employee emp_user ON e.usuario_asignado = emp_user.id 
          LEFT JOIN sedes emp_user_sede ON emp_user.sede_id = emp_user_sede.id
          WHERE 1=1";

$params = [];

// Aplicar filtros
if (!empty($buscar)) {
    $query .= " AND (e.activo_fijo LIKE :buscar OR e.serial_number LIKE :buscar)";
    $params[':buscar'] = "%$buscar%";
}

if (!empty($filtroSede)) {
    $query .= " AND (e.sede_id = :sede_equipo OR emp_user.sede_id = :sede_usuario)";
    $params[':sede_equipo'] = $filtroSede;
    $params[':sede_usuario'] = $filtroSede;
}

// Filtros por pestaña
switch ($filtro) {
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

// Ordenar
$query .= " ORDER BY CAST(e.activo_fijo AS UNSIGNED), e.activo_fijo";

// Paginación
$query .= " LIMIT :limit OFFSET :offset";
$params[':limit'] = $resultados_por_pagina;
$params[':offset'] = $offset;

// Ejecutar consulta
$stmt = $conn->prepare($query);
foreach ($params as $key => $value) {
    if ($key === ':limit' || $key === ':offset') {
        $stmt->bindValue($key, $value, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $value);
    }
}
$stmt->execute();
$equipos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Obtener total
$stmt_total = $conn->prepare("SELECT FOUND_ROWS() as total");
$stmt_total->execute();
$total_registros = $stmt_total->fetch(PDO::FETCH_ASSOC)['total'];
$total_paginas = ceil($total_registros / $resultados_por_pagina);

// Función helper para badge de estado
function getEstadoBadge($equipo) {
    if ($equipo['en_it']) {
        $estados_it = [
            'mantenimiento_azc' => ['Mantenimiento AZC', 'warning'],
            'mantenimiento_computacion' => ['Mantenimiento Computación', 'warning'],
            'descompuesto' => ['Descompuesto', 'danger']
        ];
        $estado = $estados_it[$equipo['estado_it']] ?? ['En IT', 'warning'];
        return '<span class="badge bg-' . $estado[1] . '">' . $estado[0] . '</span>';
    } else {
        return '<span class="badge bg-success">Activo</span>';
    }
}
?>

<!-- Tabla de resultados -->
<div class="table-responsive">
    <table class="table table-striped table-hover">
        <thead>
            <tr>
                <th>Activo Fijo</th>
                <th>Marca</th>
                <th>Modelo</th>
                <th>Sede</th>
                <th>Usuario Asignado</th>
                <th>Estado IT</th>
                <th>Acciones</th>
            </tr>
        </thead>
        <tbody>
            <?php if (count($equipos) > 0): ?>
                <?php foreach ($equipos as $equipo): ?>
                <tr class="categoria-row <?php echo $equipo['en_it'] ? 'table-warning' : ''; ?>" onclick="verEquipo(<?php echo $equipo['id']; ?>)">
                    <td><?php echo htmlspecialchars($equipo['activo_fijo']); ?></td>
                    <td><?php echo htmlspecialchars($equipo['marca']); ?></td>
                    <td><?php echo htmlspecialchars($equipo['modelo']); ?></td>
                    <td><?php echo htmlspecialchars($equipo['sede_final'] ?? 'Sin sede'); ?></td>
                    <td>
                        <?php if (!empty($equipo['nombre_usuario'])): ?>
                            <strong><?php echo htmlspecialchars($equipo['nombre_usuario']); ?></strong>
                            <br><small class="text-muted">CC: <?php echo htmlspecialchars($equipo['usuario_cc']); ?></small>
                        <?php elseif (!empty($equipo['usuario_asignado'])): ?>
                            <span class="text-muted">Usuario ID: <?php echo htmlspecialchars($equipo['usuario_asignado']); ?></span>
                        <?php else: ?>
                            <span class="text-muted">Sin asignar</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo getEstadoBadge($equipo); ?></td>
			<td class="text-nowrap" onclick="event.stopPropagation();">
				<?php if ($permiso_editar_equipo): ?>
			    <button type="button" class="btn btn-sm btn-outline-primary" 
			            onclick="abrirModalEdicion(<?php echo $equipo['id']; ?>, event)">
			        <i class="bi bi-pencil"></i> Editar
			    </button>
			    <?php endif; ?>
			    <?php if ($permiso_eliminar_equipo): ?>
			    <a href="?eliminar=<?php echo $equipo['id']; ?>" class="btn btn-sm btn-danger" onclick="return confirm('¿Está seguro de eliminar este equipo?')">
			        <i class="bi bi-trash"></i> Eliminar
			    </a>
			    <?php endif; ?>
			</td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="7" class="text-center py-4">
                        <i class="bi bi-search display-4 text-muted"></i>
                        <p class="mt-2">No se encontraron equipos</p>
                    </td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Modales para equipos cargados via AJAX -->
<?php foreach ($equipos as $equipo): ?>
<!-- Modal para editar equipo -->
<div class="modal fade" id="modalEditarEquipo<?php echo $equipo['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Editar Equipo - <?php echo htmlspecialchars($equipo['activo_fijo']); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="" enctype="multipart/form-data">
                <input type="hidden" name="equipo_id" value="<?php echo $equipo['id']; ?>">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Activo Fijo</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($equipo['activo_fijo']); ?></p>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Serial</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($equipo['serial_number']); ?></p>
                            </div>
                        </div>
                    </div>
                    <?php /*
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="creado_en<?php echo $equipo['id']; ?>" class="form-label">Fecha de Creación</label>
                                <input type="datetime-local" class="form-control" id="creado_en<?php echo $equipo['id']; ?>" 
                                       name="creado_en" value="<?php echo !empty($equipo['creado_en']) ? date('Y-m-d\TH:i', strtotime($equipo['creado_en'])) : ''; ?>">
                                <small class="form-text">Fecha original de ingreso al inventario (para migración)</small>
                            </div>
                        </div>
                    </div>
                    */ ?>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Marca</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($equipo['marca']); ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Modelo</label>
                                <p class="form-control-plaintext"><?php echo htmlspecialchars($equipo['modelo']); ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="sede_id<?php echo $equipo['id']; ?>" class="form-label">Sede</label>
                                <select class="form-select" id="sede_id<?php echo $equipo['id']; ?>" name="sede_id" required>
                                    <option value="">Seleccione una sede</option>
                                    <?php foreach ($sedes as $sede): ?>
                                    <option value="<?php echo $sede['id']; ?>" <?php echo $equipo['sede_id'] == $sede['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($sede['nombre']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="procesador<?php echo $equipo['id']; ?>" class="form-label">Procesador</label>
                                <input type="text" class="form-control" id="procesador<?php echo $equipo['id']; ?>" name="procesador" value="<?php echo htmlspecialchars($equipo['procesador']); ?>">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="ram<?php echo $equipo['id']; ?>" class="form-label">RAM</label>
                                <input type="text" class="form-control" id="ram<?php echo $equipo['id']; ?>" name="ram" value="<?php echo htmlspecialchars($equipo['ram']); ?>" placeholder="Ej: 8GB DDR4">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="disco_duro<?php echo $equipo['id']; ?>" class="form-label">Disco Duro</label>
                                <input type="text" class="form-control" id="disco_duro<?php echo $equipo['id']; ?>" name="disco_duro" value="<?php echo htmlspecialchars($equipo['disco_duro']); ?>" placeholder="Ej: 256GB SSD">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="precio<?php echo $equipo['id']; ?>" class="form-label">Precio (USD)</label>
                                <input type="number" class="form-control" id="precio<?php echo $equipo['id']; ?>" 
                                       name="precio" step="0.01" min="0" value="<?php echo htmlspecialchars($equipo['precio'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-12">
                            <div class="mb-3">
                                <label class="form-label">Foto Actual</label>
                                <?php if (!empty($equipo['foto'])): ?>
                                <div class="text-center mb-2">
                                    <img src="../uploads/equipos/<?php echo htmlspecialchars($equipo['foto']); ?>" 
                                         class="img-thumbnail" style="max-height: 200px;">
                                    <br>
                                    <small><?php echo htmlspecialchars($equipo['foto']); ?></small>
                                </div>
                                <?php else: ?>
                                <p class="text-muted">No hay foto asignada</p>
                                <?php endif; ?>
                                
                                <label for="foto<?php echo $equipo['id']; ?>" class="form-label">Cambiar Foto</label>
                                <input type="file" class="form-control" id="foto<?php echo $equipo['id']; ?>" 
                                       name="foto" accept="image/*">
                                <small class="form-text">Dejar vacío para mantener la foto actual</small>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Usuario Asignado</label>
                                <p class="form-control-plaintext">
                                    <?php if (!empty($equipo['nombre_usuario'])): ?>
                                        <?php echo htmlspecialchars($equipo['nombre_usuario']); ?>
                                        <br><small class="text-muted">CC: <?php echo htmlspecialchars($equipo['usuario_cc']); ?></small>
                                    <?php elseif (!empty($equipo['usuario_cc'])): ?>
                                        <span class="text-muted">Usuario ID: <?php echo htmlspecialchars($equipo['usuario_cc']); ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">Sin asignar</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Campos IT -->
                    <div class="mb-3 form-check">
                        <input type="checkbox" class="form-check-input" id="en_it<?php echo $equipo['id']; ?>" name="en_it" <?php echo $equipo['en_it'] ? 'checked' : ''; ?> onchange="toggleItFieldsEdit('en_it<?php echo $equipo['id']; ?>', 'it_fields<?php echo $equipo['id']; ?>')">
                        <label class="form-check-label" for="en_it<?php echo $equipo['id']; ?>">En IT</label>
                    </div>
                    
                    <div id="it_fields<?php echo $equipo['id']; ?>" style="display: <?php echo $equipo['en_it'] ? 'block' : 'none'; ?>;">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="estado_it<?php echo $equipo['id']; ?>" class="form-label">Estado en IT</label>
                                    <select class="form-select" id="estado_it<?php echo $equipo['id']; ?>" name="estado_it">
                                        <option value="">Seleccione estado</option>
                                        <option value="mantenimiento_azc" <?php echo $equipo['estado_it'] == 'mantenimiento_azc' ? 'selected' : ''; ?>>Mantenimiento AZC IT</option>
                                        <option value="mantenimiento_computacion" <?php echo $equipo['estado_it'] == 'mantenimiento_computacion' ? 'selected' : ''; ?>>Mantenimiento Computación</option>
                                        <option value="descompuesto" <?php echo $equipo['estado_it'] == 'descompuesto' ? 'selected' : ''; ?>>Descompuesto/Usado para repuestos</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        <div class="mb-3">
                            <label for="notas_it<?php echo $equipo['id']; ?>" class="form-label">Notas de IT</label>
                            <textarea class="form-control" id="notas_it<?php echo $equipo['id']; ?>" name="notas_it" rows="3" placeholder="Razón por la que está en IT"><?php echo htmlspecialchars($equipo['notas_it']); ?></textarea>
                            <?php if ($equipo['it_fecha']): ?>
                            <div class="form-text">
                                Última actualización: <?php echo date('d/m/Y H:i', strtotime($equipo['it_fecha'])); ?> 
                                por <?php echo htmlspecialchars($equipo['admin_nombre']); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" name="actualizar_equipo" class="btn btn-primary">Actualizar Equipo</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endforeach; ?>

<!-- Modales de información para equipos cargados via AJAX -->
<?php foreach ($equipos as $equipo): ?>
<!-- Modal de Información Completa -->
<div class="modal fade" id="modalInfoEquipo<?php echo $equipo['id']; ?>" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header text-white" style="background-color: #003a5d;">
                <h5 class="modal-title">Información Completa - <?php echo htmlspecialchars($equipo['activo_fijo']); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <h5>Información del Equipo</h5>
                        <table class="table table-sm equipo-info">
                            <tr>
                                <td>Activo Fijo:</td>
                                <td><?php echo htmlspecialchars($equipo['activo_fijo']); ?></td>
                            </tr>
                            <tr>
                                <td>Serial:</td>
                                <td><?php echo htmlspecialchars($equipo['serial_number']); ?></td>
                            </tr>
                            <tr>
                                <td>Marca:</td>
                                <td><?php echo htmlspecialchars($equipo['marca']); ?></td>
                            </tr>
                            <tr>
                                <td>Modelo:</td>
                                <td><?php echo htmlspecialchars($equipo['modelo']); ?></td>
                            </tr>
                            <tr>
                                <td>Procesador:</td>
                                <td><?php echo htmlspecialchars($equipo['procesador']); ?></td>
                            </tr>
                            <tr>
                                <td>RAM:</td>
                                <td><?php echo htmlspecialchars($equipo['ram']); ?></td>
                            </tr>
                            <tr>
                                <td>Disco Duro:</td>
                                <td><?php echo htmlspecialchars($equipo['disco_duro']); ?></td>
                            </tr>
                            <tr>
                                <td>Precio:</td>
                                <td>
                                    <?php if (!empty($equipo['precio'])): ?>
                                        $<?php echo number_format($equipo['precio'], 2); ?> USD
                                    <?php else: ?>
                                        <span class="text-muted">No especificado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Foto:</td>
                                <td>
                                    <?php if (!empty($equipo['foto'])): ?>
                                        <img src="../uploads/equipos/<?php echo htmlspecialchars($equipo['foto']); ?>" 
                                             class="img-thumbnail" style="max-height: 150px; cursor: pointer;" 
                                             onclick="ampliarImagen(this.src)">
                                    <?php else: ?>
                                        <span class="text-muted">No hay foto disponible</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h5>Información de Asignación</h5>
                        <table class="table table-sm equipo-info">
                            <tr>
                                <td>Sede:</td>
                                <td>
                                    <?php echo htmlspecialchars($equipo['sede_final'] ?? 'Sin sede'); ?>
                                    <?php if (!empty($equipo['usuario_asignado']) && $equipo['sede_nombre'] != $equipo['sede_final']): ?>
                                    <br><small class="text-muted">(Heredada del usuario asignado)</small>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Usuario Asignado:</td>
                                <td>
                                    <?php if (!empty($equipo['nombre_usuario'])): ?>
                                        <strong><?php echo htmlspecialchars($equipo['nombre_usuario']); ?></strong>
                                        <br><small class="text-muted">CC: <?php echo htmlspecialchars($equipo['usuario_cc']); ?></small>
                                    <?php elseif (!empty($equipo['usuario_asignado'])): ?>
                                        <?php if (!empty($equipo['usuario_cc'])): ?>
                                            <span class="text-muted">CC: <?php echo htmlspecialchars($equipo['usuario_cc']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">Usuario ID: <?php echo htmlspecialchars($equipo['usuario_asignado']); ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">Sin asignar</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Estado IT:</td>
                                <td>
                                    <?php if ($equipo['en_it']): ?>
                                        <span class="badge bg-warning">
                                            <?php 
                                            $estados_it = [
                                                'mantenimiento_azc' => 'Mantenimiento AZC',
                                                'mantenimiento_computacion' => 'Mantenimiento Computación',
                                                'descompuesto' => 'Descompuesto'
                                            ];
                                            $estado_texto = $estados_it[$equipo['estado_it']] ?? 'En IT';
                                            $badge_color = ($equipo['estado_it'] == 'descompuesto') ? 'danger' : 'warning';
                                            ?>
                                            <span class="badge bg-<?php echo $badge_color; ?>">
                                                <?php echo $estado_texto; ?>
                                            </span>
                                    <?php else: ?>
                                        <span class="badge bg-success">Activo</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td>Notas IT:</td>
                                <td><?php echo htmlspecialchars($equipo['notas_it']); ?></td>
                            </tr>
                            <tr>
                                <td>Última actualización:</td>
                                <td>
                                    <?php if ($equipo['it_fecha']): ?>
                                        <?php echo date('d/m/Y H:i', strtotime($equipo['it_fecha'])); ?> 
                                        por <?php echo htmlspecialchars($equipo['admin_nombre']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Nunca actualizado</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
				<tr>
				    <td>Antigüedad en la empresa:</td>
				    <td>
				        <?php 
				        if (!empty($equipo['creado_en'])) {
				            echo calcularAntiguedadAjax($equipo['creado_en']);
				        } else {
				            echo '<span class="text-muted">Fecha no disponible</span>';
				        }
				        ?>
				    </td>
				</tr>
                        </table>
                    </div>
                </div>

                <hr>
                <h5>Historial del Equipo</h5>
                <?php
                // Obtener historial para este equipo específico
                $query_historial = "SELECT h.*, CONCAT(emp.first_Name, ' ', emp.first_LastName) as admin_nombre 
                                  FROM historial h 
                                  LEFT JOIN employee emp ON h.admin_id = emp.id 
                                  WHERE (h.notas LIKE CONCAT('%', :activo_fijo, '%'))
                                  ORDER BY h.creado_en DESC";
                $stmt_historial = $conn->prepare($query_historial);
                $stmt_historial->bindValue(':activo_fijo', $equipo['activo_fijo']);
                $stmt_historial->execute();
                $historial = $stmt_historial->fetchAll(PDO::FETCH_ASSOC);
                ?>
                
                <div class="table-responsive">
                    <table class="table table-striped table-sm">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Acción</th>
                                <th>Administrador</th>
                                <th>Notas</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (count($historial) > 0): ?>
                                <?php foreach ($historial as $registro): ?>
                                <tr>
                                    <td><?php echo date('d/m/Y H:i', strtotime($registro['creado_en'])); ?></td>
                                    <td>
                                        <span class="badge bg-<?php 
                                        $badge_colors = [
                                            'asignar_equipo' => 'success',
                                            'desasignar_equipo' => 'danger',
                                            'crear_equipo' => 'primary',
                                            'eliminar_equipo' => 'warning',
                                            'cambio_sede' => 'info',
                                            'cambio_estado_it' => 'warning',
                                            'cambio_procesador' => 'secondary',
                                            'cambio_ram' => 'secondary',
                                            'cambio_disco' => 'secondary'
                                        ];
                                        echo $badge_colors[$registro['accion']] ?? 'secondary';
                                        ?>">
                                            <?php echo ucfirst(str_replace('_', ' ', $registro['accion'])); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($registro['admin_nombre']); ?></td>
                                    <td><?php echo htmlspecialchars($registro['notas']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="4" class="text-center">No hay historial para este equipo</td>
                                </tr>
                            <?php endif; ?>
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
<?php endforeach; ?>

<!-- Paginación estilo usuarios.php -->
<?php if ($total_registros > 0): ?>
<div class="pagination-container">
    <div class="results-per-page">
        <span>Resultados por página:</span>
        <select class="form-select form-select-sm" id="resultados_por_pagina" name="resultados_por_pagina">
            <option value="20" <?php echo $resultados_por_pagina == 20 ? 'selected' : ''; ?>>20</option>
            <option value="50" <?php echo $resultados_por_pagina == 50 ? 'selected' : ''; ?>>50</option>
            <option value="100" <?php echo $resultados_por_pagina == 100 ? 'selected' : ''; ?>>100</option>
        </select>
    </div>
    
    <div class="pagination-info">
        <span>Mostrando <?php echo ($offset + 1); ?> - <?php echo min($offset + $resultados_por_pagina, $total_registros); ?> de <?php echo $total_registros; ?> resultados</span>
    </div>
    
    <nav aria-label="Page navigation">
        <ul class="pagination pagination-sm">
            <?php if ($pagina > 1): ?>
            <li class="page-item">
                <a class="page-link" href="#" onclick="cambiarPagina(<?php echo $pagina - 1; ?>)" aria-label="Previous">
                    <span aria-hidden="true">&laquo;</span>
                </a>
            </li>
            <?php endif; ?>
            
            <?php
            // Mostrar páginas (máximo 5 páginas alrededor de la actual)
            $inicio = max(1, $pagina - 2);
            $fin = min($total_paginas, $pagina + 2);
            
            if ($inicio > 1) {
                echo '<li class="page-item"><a class="page-link" href="#" onclick="cambiarPagina(1)">1</a></li>';
                if ($inicio > 2) {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }
            
            for ($i = $inicio; $i <= $fin; $i++): 
            ?>
            <li class="page-item <?php echo $i == $pagina ? 'active' : ''; ?>">
                <a class="page-link" href="#" onclick="cambiarPagina(<?php echo $i; ?>)"><?php echo $i; ?></a>
            </li>
            <?php endfor; ?>
            
            <?php
            if ($fin < $total_paginas) {
                if ($fin < $total_paginas - 1) {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                echo '<li class="page-item"><a class="page-link" href="#" onclick="cambiarPagina(' . $total_paginas . ')">' . $total_paginas . '</a></li>';
            }
            ?>
            
            <?php if ($pagina < $total_paginas): ?>
            <li class="page-item">
                <a class="page-link" href="#" onclick="cambiarPagina(<?php echo $pagina + 1; ?>)" aria-label="Next">
                    <span aria-hidden="true">&raquo;</span>
                </a>
            </li>
            <?php endif; ?>
        </ul>
    </nav>
</div>
<?php endif; ?>