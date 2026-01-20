<?php
// sidebar.php con permisos dinámicos basados en base de datos
require_once 'database.php';
require_once 'auth.php';
require_once 'validar_permiso.php';
require_once __DIR__ . '/functions.php';

 $current_page = basename($_SERVER['PHP_SELF']);
 $user_id = $_SESSION['user_id'] ?? null;
 $user_role = $_SESSION['user_role'] ?? 'empleado'; // Valor por defecto por seguridad

// Obtener información del usuario
 $database = new Database();
 $conn = $database->getConnection();

 $query_usuario = "SELECT e.id, e.role, e.position_id, c.nombre as cargo_nombre 
                  FROM employee e 
                  LEFT JOIN cargos c ON e.position_id = c.id 
                  WHERE e.id = ?";
 $stmt_usuario = $conn->prepare($query_usuario);
 $stmt_usuario->execute([$user_id]);
 $usuario = $stmt_usuario->fetch(PDO::FETCH_ASSOC);

// Si no se encuentra el usuario, usar valores por defecto
if (!$usuario) {
    $usuario = [
        'id' => 0,
        'role' => 'empleado',
        'position_id' => null,
        'cargo_nombre' => null
    ];
}

// Obtener recursos disponibles para el usuario
 $query_recursos = "SELECT DISTINCT r.nombre, r.ruta, r.icono, r.categoria
                    FROM recursos r
                    WHERE r.activo = TRUE
                    ORDER BY r.categoria, r.nombre";
 $stmt_recursos = $conn->prepare($query_recursos);
 $stmt_recursos->execute();
 $recursos_disponibles = $stmt_recursos->fetchAll(PDO::FETCH_ASSOC);

// Filtrar recursos según permisos del usuario
 $allowed_modules = [];
foreach ($recursos_disponibles as $recurso) {
    if (tienePermiso($conn, $usuario['id'], $usuario['role'], $usuario['position_id'], $recurso['nombre'], 'ver')) {
        $allowed_modules[] = $recurso['nombre'];
    }
}

// Función para verificar si un módulo está permitido
function moduloPermitido($modulo) {
    global $allowed_modules;
    return in_array($modulo, $allowed_modules);
}

// Función para verificar si una acción específica está permitida
function accionPermitida($recurso, $accion) {
    global $conn, $usuario;
    return tienePermiso($conn, $usuario['id'], $usuario['role'], $usuario['position_id'], $recurso, $accion);
}
?>

<style>
:root {
    --primary-red: #be1622;
    --dark-gray: #353132;
    --light-gray: #9d9d9c;
    --dark-blue: #003a5d;
    --hover-gray: #f8f9fa;
    --border-gray: #dee2e6;
    --active-category-bg: #fff5f5;
}

.sidebar {
    min-height: 100vh;
    box-shadow: 2px 0 5px rgba(0,0,0,0.1);
    position: fixed;
    left: 0;
    top: -5px;
    width: 100%;
    max-width: 220px;
    z-index: 1000;
    height: calc(100vh - 0px);
    background: white !important;
}

.sidebar-container {
    display: flex;
    flex-direction: column;
    height: 100%;
    padding-top: 0 !important;
}

.sidebar-menu {
    flex: 1;
    overflow-y: auto;
    overflow-x: hidden;
    padding: 0.5rem 0;
}

.sidebar-footer {
    background-color: white;
    flex-shrink: 0;
    margin-top: auto;
    border-top: 2px solid var(--light-gray) !important;
}

/* Personalizar el scrollbar */
.sidebar-menu::-webkit-scrollbar {
    width: 6px;
}

.sidebar-menu::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 3px;
}

.sidebar-menu::-webkit-scrollbar-thumb {
    background: var(--light-gray);
    border-radius: 3px;
}

.sidebar-menu::-webkit-scrollbar-thumb:hover {
    background: var(--dark-blue);
}

/* Estilos para Firefox */
.sidebar-menu {
    scrollbar-width: thin;
    scrollbar-color: var(--light-gray) #f1f1f1;
}

.nav-link {
    border-radius: 0.375rem;
    margin: 0.05rem 0.5rem;
    transition: all 0.2s ease;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    padding: 0.6rem 1rem;
    position: relative;
    color: var(--dark-gray) !important;
    border: 1px solid transparent;
    display: flex;
    align-items: center;
    font-size: 0.9rem;
}

.nav-link:hover {
    background-color: var(--hover-gray);
    color: var(--dark-gray) !important;
    border-color: var(--border-gray);
    transform: translateX(3px);
}

.nav-link.active {
    background-color: var(--primary-red);
    color: white !important;
    border-color: var(--dark-blue);
    box-shadow: 0 2px 4px rgba(190, 22, 34, 0.2);
}

.nav-link.active:hover {
    background-color: var(--primary-red) !important;
    color: white !important;
    border-color: var(--dark-blue);
    transform: translateX(3px); /* Mantener la animación de desplazamiento */
}

/* Asegurar que los íconos tengan espacio consistente */
.nav-link i {
    width: 18px;
    margin-right: 10px;
    text-align: center;
    color: var(--dark-gray);
    transition: color 0.2s ease;
    font-size: 0.95rem;
}

.nav-link.active i {
    color: white;
}

.nav-link:hover i {
    color: var(--dark-blue);
}

.nav-link.active:hover i {
    color: white !important;
}

/* Estilos para el badge de tickets pendientes */
#pending-tickets-count {
    display: none;
    font-size: 0.65em;
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    background-color: var(--primary-red) !important;
    color: white;
    border-radius: 10px;
    min-width: 18px;
    height: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
}

/* Estilos mejorados para los headings - MÁS COMPACTOS */
.sidebar-heading {
    color: var(--dark-gray) !important;
    border-bottom: 1px solid var(--border-gray);
    padding: 0.5rem 1rem;
    cursor: pointer;
    user-select: none;
    transition: all 0.3s ease;
    margin: 0;
    background-color: white;
    border-radius: 0.375rem;
    margin: 2px 0.5rem;
    display: flex;
    align-items: center;
    justify-content: space-between;
    font-size: 0.8rem;
}

.sidebar-heading:hover {
    background-color: var(--hover-gray);
    border-color: var(--dark-blue);
}

.sidebar-heading.active-category {
    background-color: var(--active-category-bg);
    border-left: 3px solid var(--primary-red);
}

.sidebar-heading span {
    color: var(--dark-gray);
    font-weight: 700;
    font-size: 0.8rem;
    letter-spacing: 0.3px;
}

/* CORRECCIÓN: Indicadores de flecha animados */
.sidebar-heading .category-arrow {
    transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    margin-right: 6px;
    color: var(--dark-blue);
    font-size: 0.8rem;
    transform: rotate(-90deg); /* Por defecto apunta hacia arriba (contraído) */
}

/* CORRECCIÓN: Cuando NO está collapsed (está expandido) */
.sidebar-heading:not(.collapsed) .category-arrow {
    transform: rotate(0deg); /* Apunta hacia abajo cuando está expandido */
}

/* Texto del footer */
.sidebar-footer small {
    color: var(--dark-gray) !important;
}

/* Asegurar que el contenido principal no se solape */
main {
    margin-left: 250px;
}

@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        height: 100vh;
        max-width: 100%;
        top: 0;
    }
    
    main {
        margin-left: 0;
    }
}

/* Icono personalizado para Keeper */
.sidebar-icon-keeper {
    width: 18px;
    height: 18px;
    margin-right: 10px;
    vertical-align: middle;
    transition: filter 0.2s ease-in-out;
}

/* Efecto hover - se aclara un poco */
.nav-link:hover .sidebar-icon-keeper {
    filter: brightness(1.2);
}

/* Ajuste para mantener el icono blanco cuando está activo */
.nav-link.active .sidebar-icon-keeper {
    filter: brightness(0) invert(1);
}

.sidebar-icon-keeper {
    margin-left: -2px;
}

/* Estilos mejorados para submenús - MÁS COMPACTOS */
.sidebar-submenu {
    padding-left: 0.5rem;
    max-height: 0;
    overflow: hidden;
    transition: max-height 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    background-color: var(--hover-gray);
    border-radius: 0.375rem;
    margin: 0 0.5rem 2px 0.5rem;
}

.sidebar-submenu.show {
    max-height: 300px;
}

.sidebar-submenu .nav-link {
    margin: 1px 0.25rem;
    padding: 0.5rem 0.75rem;
    border-radius: 0.25rem;
    font-size: 0.85rem;
}

.sidebar-submenu .nav-link:hover {
    background-color: white;
    border-color: var(--light-gray);
}

/* Separadores visuales - MÁS DELGADOS */
.sidebar-separator {
    height: 1px;
    background: linear-gradient(90deg, transparent 0%, var(--border-gray) 50%, transparent 100%);
    margin: 0.5rem 0.5rem;
}

/* Estados de carga y transiciones */
.sidebar-menu * {
    transition: all 0.3s ease;
}

/* Mejora visual para elementos deshabilitados */
.nav-link:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

.nav-link .badge {
    font-size: 0.65em !important;
    position: absolute !important;
    right: 10px !important;
    top: 50% !important;
    transform: translateY(-50%) !important;
    border-radius: 10px !important;
    min-width: 18px !important;
    height: 18px !important;
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    padding: 0 4px !important;
}

/* Badge específico para permisos */
#pending-permisos-count {
    background-color: var(--primary-red);
    color: #000 !important;
    border: 1px solid #ffc107 !important;
}

/* Efecto de profundidad para el sidebar */
.sidebar {
    box-shadow: 3px 0 10px rgba(0,0,0,0.1);
}

/* Animación suave para la expansión de categorías */
@keyframes slideDown {
    from {
        opacity: 0;
        transform: translateY(-5px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.sidebar-submenu.show {
    animation: slideDown 0.2s ease-out;
}

/* Prevenir flash de contenido no estilizado */
.sidebar-menu {
    visibility: hidden;
}

.sidebar-menu.loaded {
    visibility: visible;
}

/* AGREGAR AL FINAL DE TU CSS ACTUAL */

/* Prevenir animaciones durante la carga inicial */
.sidebar-menu:not(.loaded) .sidebar-submenu {
    transition: none !important;
}

.sidebar-menu:not(.loaded) .category-arrow {
    transition: none !important;
}

/* Estado inicial para categorías expandidas */


.sidebar-submenu[data-initial-state="expanded"] + .sidebar-heading .category-arrow {
    transform: rotate(0deg) !important;
}

.sidebar-submenu[data-initial-state="expanded"] + .sidebar-heading {
    background-color: var(--active-category-bg) !important;
    border-left: 3px solid var(--primary-red) !important;
}
</style>

<nav id="sidebar" class="col-md-3 col-lg-2 d-md-block sidebar collapse">
    <div class="sidebar-container">
        <div class="sidebar-menu" id="sidebarMenu">
            <ul class="nav flex-column">
                <!-- COMUNIDAD -->
                <?php if (in_array('portal', $allowed_modules) || in_array('boletin', $allowed_modules)): ?>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-1 mb-0">
                    <span>COMUNIDAD</span>
                </h6>
                
                <!-- Portal -->
                <?php if (in_array('portal', $allowed_modules)): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'portal.php') ? 'active' : '' ?>" href="portal.php" data-navigo>
                        <i class="bi bi-house-door"></i>
                        Portal
                    </a>
                </li>
                <?php endif; ?>
                
                <!-- Boletín Informativo -->
                <?php if (in_array('boletin', $allowed_modules)): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'boletin.php') ? 'active' : '' ?>" href="boletin.php" data-navigo>
                        <i class="bi bi-newspaper"></i>
                        Boletín Informativo
                    </a>
                </li>
                <?php endif; ?>
                <?php endif; ?>
                
                <!-- Dashboard -->
                <?php if (in_array('dashboard', $allowed_modules)): ?>
                <div class="sidebar-separator"></div>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-1 mb-0">
                    <span>DASHBOARD</span>
                </h6>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'dashboard.php') ? 'active' : '' ?>" href="dashboard.php" data-navigo>
                        <i class="bi bi-speedometer2"></i>
                        Dashboard
                    </a>
                </li>
                <?php endif; ?>
                
		<!-- En la sección de "SOLICITUDES" -->
		<?php if (in_array('tickets', $allowed_modules) || in_array('historial', $allowed_modules) || in_array('permisos', $allowed_modules)): ?>
		<div class="sidebar-separator"></div>
		<h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-1 mb-0 collapsed" 
		    data-bs-toggle="collapse" data-bs-target="#soporteSubmenu" aria-expanded="false">
		    <span><i class="bi bi-chevron-down category-arrow"></i> SOLICITUDES</span>
		</h6>
		<div class="sidebar-submenu collapse" id="soporteSubmenu">
		    <?php if (in_array('tickets', $allowed_modules)): ?>
		    <li class="nav-item">
		        <a class="nav-link <?= ($current_page == 'tickets.php') ? 'active' : '' ?>" href="tickets.php" data-navigo>
		            <i class="bi bi-ticket-perforated"></i>
		            Tickets
		            <span class="badge bg-danger ms-1" id="pending-tickets-count" style="display: none;">0</span>
		        </a>
		    </li>
		    <?php endif; ?>
		    
		    <?php if (in_array('permisos', $allowed_modules) || $functions->esSupervisor($_SESSION['user_id'])): ?>
		    <li class="nav-item">
		        <a class="nav-link <?= ($current_page == 'permisos.php') ? 'active' : '' ?>" href="permisos.php" data-navigo>
		            <i class="bi bi-clipboard-check"></i>
		            Permisos
		            <span class="badge bg-warning ms-1" id="pending-permisos-count" style="display: none;">0</span>
		        </a>
		    </li>
		    <?php endif; ?>
		    
		    <?php if (in_array('historial', $allowed_modules)): ?>
		    <li class="nav-item">
		        <a class="nav-link <?= ($current_page == 'historial.php') ? 'active' : '' ?>" href="historial.php" data-navigo>
		            <i class="bi bi-clock-history"></i>
		            Historial
		        </a>
		    </li>
		    <?php endif; ?>
		</div>
		<?php endif; ?>
                
                <!-- Gestión de Personal -->
                <?php if (in_array('usuarios', $allowed_modules) || in_array('registros', $allowed_modules) || in_array('areas', $allowed_modules) || in_array('keeper', $allowed_modules)): ?>
                <div class="sidebar-separator"></div>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-1 mb-0 collapsed" 
                    data-bs-toggle="collapse" data-bs-target="#personalSubmenu" aria-expanded="false">
                    <span><i class="bi bi-chevron-down category-arrow"></i> GESTIÓN DE PERSONAL</span>
                </h6>
                <div class="sidebar-submenu collapse" id="personalSubmenu">
                    <?php if (in_array('usuarios', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'usuarios.php') ? 'active' : '' ?>" href="usuarios.php" data-navigo>
                            <i class="bi bi-people"></i>
                            Usuarios
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (in_array('accesos', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'configurar_permisos.php') ? 'active' : '' ?>" href="configurar_permisos.php" data-navigo>
                            <i class="bi bi-shield-check"></i>
                            Accesos
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (in_array('registros', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'registros.php') ? 'active' : '' ?>" href="registros.php" data-navigo>
                            <i class="bi bi-calendar-check"></i>
                            Asistencia
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (in_array('areas', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'areas.php') ? 'active' : '' ?>" href="areas.php" data-navigo>
                            <i class="bi bi-diagram-3"></i>
                            Áreas
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (in_array('keeper', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'keeper_list.php') ? 'active' : '' ?>" href="keeper_list.php" data-navigo>
                            <img 
                                src="<?= ($current_page == 'keeper_list.php') 
                                    ? '../assets/images/keeper_white.png' 
                                    : '../assets/images/keeper_main2.png' ?>" 
                                class="sidebar-icon-keeper" 
                                alt="Keeper Icon">
                            Keeper
                        </a>
                    </li>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Gestión de Activos -->
                <?php if (in_array('equipos', $allowed_modules) || in_array('items', $allowed_modules) || in_array('categorias', $allowed_modules) || in_array('planos', $allowed_modules)): ?>
                <div class="sidebar-separator"></div>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-1 mb-0 collapsed" 
                    data-bs-toggle="collapse" data-bs-target="#activosSubmenu" aria-expanded="false">
                    <span><i class="bi bi-chevron-down category-arrow"></i> GESTIÓN DE ACTIVOS</span>
                </h6>
                <div class="sidebar-submenu collapse" id="activosSubmenu">
                    <?php if (in_array('equipos', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'equipos.php') ? 'active' : '' ?>" href="equipos.php" data-navigo>
                            <i class="bi bi-laptop"></i>
                            Equipos
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (in_array('items', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'items.php') ? 'active' : '' ?>" href="items.php" data-navigo>
                            <i class="bi bi-pc-display"></i>
                            Ítems
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (in_array('categorias', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'categorias.php') ? 'active' : '' ?>" href="categorias.php" data-navigo>
                            <i class="bi bi-box-seam"></i>
                            Categorías
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (in_array('planos', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'planos_sedes.php' || $current_page == 'plano_sede.php') ? 'active' : '' ?>" href="planos_sedes.php" data-navigo>
                            <i class="bi bi-layers"></i>
                            Planos
                        </a>
                    </li>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Recursos Empresariales -->
                <?php if (in_array('firmas', $allowed_modules) || in_array('telefonos', $allowed_modules)): ?>
                <div class="sidebar-separator"></div>
                <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-1 mb-0 collapsed" 
                    data-bs-toggle="collapse" data-bs-target="#recursosSubmenu" aria-expanded="false">
                    <span><i class="bi bi-chevron-down category-arrow"></i> RECURSOS EMPRESARIALES</span>
                </h6>
                <div class="sidebar-submenu collapse" id="recursosSubmenu">
                    <?php if (in_array('firmas', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'firmas.php') ? 'active' : '' ?>" href="firmas.php" data-navigo>
                            <i class="bi bi-bank"></i>
                            Firmas
                        </a>
                    </li>
                    <?php endif; ?>
                    
                    <?php if (in_array('telefonos', $allowed_modules)): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($current_page == 'telefonos.php') ? 'active' : '' ?>" href="telefonos.php" data-navigo>
                            <i class="bi bi-phone"></i>
                            Teléfonos IP
                        </a>
                    </li>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </ul>

            <div class="sidebar-separator"></div>
            <h6 class="sidebar-heading d-flex justify-content-between align-items-center px-3 mt-1 mb-0">
                <span>CUENTA</span>
            </h6>
            <ul class="nav flex-column mb-1">
                <!-- Perfil -->
                <?php if (in_array('profile', $allowed_modules)): ?>
                <li class="nav-item">
                    <a class="nav-link <?= ($current_page == 'profile.php') ? 'active' : '' ?>" href="profile.php" data-navigo>
                        <i class="bi bi-person"></i>
                        Perfil
                    </a>
                </li>
                <?php endif; ?>
                
                <!-- Cerrar Sesión -->
                <li class="nav-item">
                    <a class="nav-link" href="../logout.php" data-navigo>
                        <i class="bi bi-box-arrow-right"></i>
                        Cerrar Sesión
                    </a>
                </li>
            </ul>
        </div>
        
        <!-- Información de versión en la parte inferior -->
        <div class="sidebar-footer p-2">
            <small class="d-block" style="font-size: 0.75rem;">AZC one V0.11.4</small>
            <small style="font-size: 0.7rem;"><?php echo date('Y'); ?> © <?php echo isset($group_name) ? $group_name : 'AZC'; ?></small>
        </div>
    </div>
</nav>

<script>
// Función para cargar la cantidad de tickets pendientes
function loadPendingTicketsCount() {
    <?php if (in_array('tickets', $allowed_modules)): ?>
    fetch('../includes/get_pending_tickets.php')
        .then(response => response.json())
        .then(data => {
            if (data.count > 0) {
                document.getElementById('pending-tickets-count').textContent = data.count;
                document.getElementById('pending-tickets-count').style.display = 'flex';
            } else {
                document.getElementById('pending-tickets-count').style.display = 'none';
            }
        })
        .catch(error => console.error('Error:', error));
    <?php endif; ?>
}

// Función para guardar la posición del scroll
function saveScrollPosition() {
    const sidebarMenu = document.getElementById('sidebarMenu');
    if (sidebarMenu) {
        const scrollPosition = sidebarMenu.scrollTop;
        sessionStorage.setItem('sidebarScrollPosition', scrollPosition);
    }
}

// Función para restaurar la posición del scroll
function restoreScrollPosition() {
    const sidebarMenu = document.getElementById('sidebarMenu');
    const savedPosition = sessionStorage.getItem('sidebarScrollPosition');
    
    if (sidebarMenu && savedPosition !== null) {
        // Restaurar inmediatamente
        sidebarMenu.scrollTop = parseInt(savedPosition);
    }
}

// ENFOQUE COMPLETAMENTE DIFERENTE: Usar data attributes para el estado inicial
function initializeSidebarState() {
    const categoryStates = JSON.parse(sessionStorage.getItem('sidebarCategoryStates') || '{}');
    
    // Aplicar estados guardados ANTES de que Bootstrap inicialice
    Object.keys(categoryStates).forEach(submenuId => {
        const submenu = document.getElementById(submenuId);
        const heading = document.querySelector(`[data-bs-target="#${submenuId}"]`);
        
        if (submenu && heading && categoryStates[submenuId]) {
            // Marcar para expansión inicial
            submenu.setAttribute('data-initial-state', 'expanded');
        }
    });
    
    // Expandir categoría activa si es necesario
    const activeLink = document.querySelector('.nav-link.active');
    if (activeLink) {
        const submenu = activeLink.closest('.sidebar-submenu');
        if (submenu) {
            submenu.setAttribute('data-initial-state', 'expanded');
        }
    }
}

// Inicializar Bootstrap Collapse con estados predefinidos
function initializeBootstrapCollapse() {
    // Verificar si Bootstrap está disponible
    if (typeof bootstrap === 'undefined' || typeof bootstrap.Collapse === 'undefined') {
        console.warn('Bootstrap no está disponible, reintentando en 100ms...');
        setTimeout(initializeBootstrapCollapse, 100);
        return;
    }
    
    const collapses = document.querySelectorAll('.sidebar-submenu.collapse');
    
    collapses.forEach(submenu => {
        const initialState = submenu.getAttribute('data-initial-state');
        const heading = document.querySelector(`[data-bs-target="#${submenu.id}"]`);
        
        // Configurar opciones basadas en el estado inicial
        const options = {
            toggle: false
        };
        
        try {
            const collapseInstance = new bootstrap.Collapse(submenu, options);
            
            // Aplicar estado inicial inmediatamente después de la inicialización
            if (initialState === 'expanded') {
                setTimeout(() => {
                    collapseInstance.show();
                }, 10);
            }
            
            // Configurar eventos para guardar estado
            submenu.addEventListener('show.bs.collapse', function() {
                saveCategoryState(submenu.id, true);
                if (heading) heading.classList.add('active-category');
            });
            
            submenu.addEventListener('hide.bs.collapse', function() {
                saveCategoryState(submenu.id, false);
                if (heading) heading.classList.remove('active-category');
            });
        } catch (error) {
            console.error('Error inicializando collapse:', error);
        }
    });
}

// Función para guardar estado de categorías
function saveCategoryState(categoryId, isExpanded) {
    const categoryStates = JSON.parse(sessionStorage.getItem('sidebarCategoryStates') || '{}');
    categoryStates[categoryId] = isExpanded;
    sessionStorage.setItem('sidebarCategoryStates', JSON.stringify(categoryStates));
}

// Configurar event listeners
function setupEventListeners() {
    const sidebarMenu = document.getElementById('sidebarMenu');
    if (sidebarMenu) {
        sidebarMenu.addEventListener('scroll', saveScrollPosition);
    }
    
    // Guardar scroll en navegación
    const navLinks = document.querySelectorAll('.nav-link[data-navigo]');
    navLinks.forEach(link => {
        link.addEventListener('click', saveScrollPosition);
    });
}

function loadPendingPermisosCount() {
    <?php if (in_array('permisos', $allowed_modules) || $functions->esSupervisor($_SESSION['user_id'])): ?>
    fetch('../includes/get_pending_permisos.php')
        .then(response => response.json())
        .then(data => {
            const badge = document.getElementById('pending-permisos-count');
            if (badge && data.count > 0) {
                badge.textContent = data.count;
                badge.style.display = 'flex';
            } else if (badge) {
                badge.style.display = 'none';
            }
        })
        .catch(error => console.error('Error:', error));
    <?php endif; ?>
}

// INICIALIZACIÓN PRINCIPAL - ORDEN CRÍTICO
function initializeSidebar() {
    console.log('🔄 Inicializando sidebar...');
    
    // 1. Cargar tickets pendientes
    loadPendingTicketsCount();
    loadPendingPermisosCount();
    
    // 2. Preparar estados ANTES de Bootstrap
    initializeSidebarState();
    
    // 3. Configurar event listeners (pueden ejecutarse inmediatamente)
    setupEventListeners();
    
    // 4. Inicializar Bootstrap Collapse con verificación
    initializeBootstrapCollapse();
    
    // 5. Restaurar scroll position (con delay para que todo esté listo)
    setTimeout(() => {
        restoreScrollPosition();
        document.getElementById('sidebarMenu').classList.add('loaded');
    }, 100);
}

//función para verificar cuando Bootstrap está listo:
function waitForBootstrap(callback) {
    if (typeof bootstrap !== 'undefined' && typeof bootstrap.Collapse !== 'undefined') {
        callback();
    } else {
        setTimeout(() => waitForBootstrap(callback), 100);
    }
}


// Cargar cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    // Esperar a que Bootstrap esté disponible
    waitForBootstrap(function() {
        console.log('✅ Bootstrap cargado, inicializando sidebar...');
        initializeSidebar();
    });
    
    // Actualizar contador de tickets cada 2 minutos
    setInterval(loadPendingTicketsCount, 120000);
    setInterval(loadPendingPermisosCount, 120000);
});

// Guardar scroll antes de recargar/cerrar
window.addEventListener('beforeunload', saveScrollPosition);

// Función para limpiar estados (debug)
function clearSidebarStates() {
    sessionStorage.removeItem('sidebarCategoryStates');
    sessionStorage.removeItem('sidebarScrollPosition');
    console.log('🧹 Estados limpiados');
    location.reload();
}
</script>