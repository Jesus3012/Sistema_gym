<?php
// Archivo: includes/sidebar.php
// Sidebar reutilizable para todos los módulos

// Asegurar que la sesión está iniciada
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Determinar módulo activo basado en la página actual
$current_page = basename($_SERVER['PHP_SELF']);
$active_module = '';

if ($current_page == 'dashboard.php') $active_module = 'dashboard';
if ($current_page == 'productos.php') $active_module = 'products';
if ($current_page == 'inscripciones.php') $active_module = 'inscriptions';
if ($current_page == 'clases.php') $active_module = 'classes';
if ($current_page == 'reportes.php') $active_module = 'reports';
if ($current_page == 'configuracion.php') $active_module = 'settings';

// Obtener datos del usuario desde la sesión
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Usuario';
$user_email = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : 'usuario@email.com';
$user_rol = isset($_SESSION['user_rol']) ? $_SESSION['user_rol'] : 'recepcionista';

// Mostrar rol en español
$rol_spanish = [
    'admin' => 'Administrador',
    'recepcionista' => 'Recepcionista',
    'entrenador' => 'Entrenador'
];
$user_rol_display = isset($rol_spanish[$user_rol]) ? $rol_spanish[$user_rol] : ucfirst($user_rol);
?>

<style>
/* ============================================
   SIDEBAR STYLES - Azul Profesional
============================================ */
:root {
    --sidebar-bg: #0a2540;
    --sidebar-dark: #0a1f32;
    --sidebar-hover: #1e3a5f;
    --sidebar-active: #2c4c7c;
    --sidebar-text: #ffffff;
    --sidebar-text-light: rgba(255, 255, 255, 0.8);
    --sidebar-border: rgba(255, 255, 255, 0.1);
    --sidebar-accent: #3b82f6;
}

* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html, body {
    height: 100%;
    margin: 0;
    padding: 0;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f5f7fa;
    overflow-x: hidden;
}

/* Sidebar - Ocupa toda la altura */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 280px;
    height: 100%;
    min-height: 100vh;
    background: var(--sidebar-bg);
    display: flex;
    flex-direction: column;
    transition: width 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 1000;
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: 2px 0 12px rgba(0, 0, 0, 0.1);
}

/* Botón de colapsar DENTRO del sidebar */
.sidebar-collapse-btn {
    position: absolute;
    right: 12px;
    top: 20px;
    width: 40px;
    height: 40px;
    background: var(--sidebar-accent);
    border: none;
    border-radius: 10px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 0.9rem;
    transition: all 0.3s ease;
    z-index: 10;
    box-shadow: 0 2px 6px rgba(0, 0, 0, 0.2);
}

.sidebar-collapse-btn:hover {
    background: #2563eb;
    transform: scale(1.05);
}

/* Estado Colapsado */
.sidebar.collapsed {
    width: 85px !important;
}

.sidebar.collapsed .logo-text,
.sidebar.collapsed .user-info,
.sidebar.collapsed .nav-text,
.sidebar.collapsed .logout-text {
    display: none;
}

.sidebar.collapsed .logo {
    justify-content: center;
}

.sidebar.collapsed .user-profile {
    justify-content: center;
    padding: 20px 0;
}

.sidebar.collapsed .user-avatar i {
    font-size: 2rem;
}

.sidebar.collapsed .nav-link {
    justify-content: center;
    padding: 14px;
}

.sidebar.collapsed .nav-link i {
    margin: 0;
    font-size: 1.3rem;
}

.sidebar.collapsed .logout-btn {
    justify-content: center;
    padding: 14px;
}

.sidebar.collapsed .sidebar-collapse-btn {
    right: 19px;
}

.sidebar.collapsed .sidebar-collapse-btn i {
    transform: rotate(180deg);
}

/* Botón Hamburguesa para móvil - Solo visible en móvil */
.hamburger-mobile {
    position: fixed;
    top: 15px;
    left: 15px;
    z-index: 1001;
    background: var(--sidebar-bg);
    border: none;
    cursor: pointer;
    width: 45px;
    height: 45px;
    border-radius: 12px;
    color: white;
    font-size: 1.2rem;
    display: none;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
}

.hamburger-mobile:hover {
    background: var(--sidebar-hover);
    transform: scale(1.02);
}

/* Estado Móvil */
@media (max-width: 768px) {
    .hamburger-mobile {
        display: flex;
    }
    
    .sidebar {
        transform: translateX(-100%);
        width: 280px;
        transition: transform 0.3s ease;
    }
    
    .sidebar.mobile-open {
        transform: translateX(0);
    }
    
    .sidebar-collapse-btn {
        display: none;
    }
}

/* Sidebar Header */
.sidebar-header {
    padding: 24px 20px;
    border-bottom: 1px solid var(--sidebar-border);
    position: relative;
}

.logo {
    display: flex;
    align-items: center;
    gap: 12px;
    text-decoration: none;
}

.logo i {
    font-size: 1.8rem;
    color: var(--sidebar-accent);
}

.logo-text {
    font-size: 1.1rem;
    font-weight: 600;
    color: white;
}

.logo-text small {
    display: block;
    font-size: 0.7rem;
    font-weight: 400;
    color: var(--sidebar-text-light);
    margin-top: 3px;
}

/* Perfil de Usuario */
.user-profile {
    padding: 20px;
    border-bottom: 1px solid var(--sidebar-border);
    display: flex;
    align-items: center;
    gap: 12px;
}

.user-avatar i {
    font-size: 2.5rem;
    color: var(--sidebar-accent);
}

.user-info {
    flex: 1;
    overflow: hidden;
}

.user-info h4 {
    font-size: 0.9rem;
    font-weight: 600;
    color: white;
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-info p {
    font-size: 0.7rem;
    color: var(--sidebar-text-light);
    margin-bottom: 5px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.user-info p i {
    font-size: 0.65rem;
    margin-right: 3px;
}

.rol-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: rgba(59, 130, 246, 0.2);
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.65rem;
    color: var(--sidebar-accent);
}

.rol-badge i {
    font-size: 0.6rem;
}

/* Navegación */
.sidebar-nav {
    flex: 1;
    overflow-y: auto;
    padding: 16px 0;
}

.sidebar-nav ul {
    list-style: none;
    padding: 0;
    margin: 0;
}

.nav-item {
    margin-bottom: 4px;
}

.nav-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 20px;
    color: var(--sidebar-text-light);
    text-decoration: none;
    transition: all 0.2s ease;
    border-left: 3px solid transparent;
    font-size: 0.9rem;
    font-weight: 500;
}

.nav-link i {
    width: 24px;
    font-size: 1.1rem;
    color: var(--sidebar-text-light);
}

.nav-link:hover {
    background: var(--sidebar-hover);
    color: white;
}

.nav-link:hover i {
    color: white;
}

.nav-link.active {
    background: var(--sidebar-active);
    color: white;
    border-left-color: var(--sidebar-accent);
}

.nav-link.active i {
    color: var(--sidebar-accent);
}

.nav-text {
    font-weight: 500;
}

.nav-divider {
    height: 1px;
    background: var(--sidebar-border);
    margin: 12px 20px;
}

/* Footer Sidebar */
.sidebar-footer {
    padding: 16px 20px;
    border-top: 1px solid var(--sidebar-border);
    margin-top: auto;
}

.logout-btn {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 10px;
    color: #f87171;
    text-decoration: none;
    border-radius: 8px;
    transition: all 0.2s ease;
    font-size: 0.9rem;
}

.logout-btn i {
    width: 24px;
    font-size: 1rem;
    color: #f87171;
}

.logout-btn:hover {
    background: rgba(248, 113, 113, 0.1);
    color: #ffa2a2;
}

/* Drag Handle */
.drag-handle {
    position: absolute;
    right: -4px;
    top: 0;
    width: 6px;
    height: 100%;
    cursor: ew-resize;
    background: transparent;
    transition: background 0.2s;
    z-index: 10;
}

.drag-handle:hover {
    background: var(--sidebar-accent);
}

@media (max-width: 768px) {
    .drag-handle {
        display: none;
    }
}

/* Scrollbar */
.sidebar::-webkit-scrollbar {
    width: 4px;
}

.sidebar::-webkit-scrollbar-track {
    background: var(--sidebar-dark);
}

.sidebar::-webkit-scrollbar-thumb {
    background: var(--sidebar-accent);
    border-radius: 4px;
}

/* Overlay para móvil */
.mobile-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    z-index: 999;
    display: none;
}

.mobile-overlay.active {
    display: block;
}

/* Contenido principal - Se desplaza según el sidebar */
.main-content {
    margin-left: 280px;
    transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-height: 100vh;
    padding: 20px;
    background: #f5f7fa;
}

.sidebar.collapsed ~ .main-content,
body.sidebar-collapsed .main-content {
    margin-left: 70px;
}

@media (max-width: 768px) {
    .main-content {
        margin-left: 0 !important;
        padding: 80px 15px 15px 15px;
    }
    
    body.sidebar-open .main-content {
        filter: blur(2px);
        pointer-events: none;
    }
}

/* Animación suave */
@keyframes fadeIn {
    from {
        opacity: 0;
        transform: translateX(-10px);
    }
    to {
        opacity: 1;
        transform: translateX(0);
    }
}

.nav-link {
    animation: fadeIn 0.2s ease forwards;
}
</style>

<!-- Botón Hamburguesa para móvil (solo visible en móvil) -->
<button class="hamburger-mobile" id="hamburgerMobile">
    <i class="fas fa-bars"></i>
</button>

<!-- Overlay para móvil -->
<div class="mobile-overlay" id="mobileOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="drag-handle" id="dragHandle"></div>
    
    <div class="sidebar-header">
        <a href="dashboard.php" class="logo">
            <i class="fas fa-dumbbell"></i>
            <div class="logo-text">
                Gym System
                <small>Panel de Control</small>
            </div>
        </a>
        <!-- Botón de colapsar DENTRO del sidebar (visible solo en PC) -->
        <button class="sidebar-collapse-btn" id="sidebarCollapseBtn">
            <i class="fas fa-chevron-left"></i>
        </button>
    </div>

    <div class="user-profile">
        <div class="user-avatar">
            <i class="fas fa-user-circle"></i>
        </div>
        <div class="user-info">
            <h4><?php echo htmlspecialchars($user_name); ?></h4>
            <p>
                <i class="fas fa-envelope"></i> 
                <?php echo htmlspecialchars($user_email); ?>
            </p>
            <span class="rol-badge">
                <i class="fas fa-user-tag"></i> 
                <?php echo htmlspecialchars($user_rol_display); ?>
            </span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <li class="nav-item">
                <a href="dashboard.php" class="nav-link <?php echo $active_module == 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-line"></i>
                    <span class="nav-text">Dashboard</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="productos.php" class="nav-link <?php echo $active_module == 'products' ? 'active' : ''; ?>">
                    <i class="fas fa-box"></i>
                    <span class="nav-text">Productos</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="inscripciones.php" class="nav-link <?php echo $active_module == 'inscriptions' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span class="nav-text">Inscripciones</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="clases.php" class="nav-link <?php echo $active_module == 'classes' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <span class="nav-text">Clases</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="reportes.php" class="nav-link <?php echo $active_module == 'reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span class="nav-text">Reportes</span>
                </a>
            </li>
            <li class="nav-divider"></li>
            <li class="nav-item">
                <a href="configuracion.php" class="nav-link <?php echo $active_module == 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span class="nav-text">Configuración</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span class="logout-text">Cerrar Sesión</span>
        </a>
    </div>
</aside>

<script>
(function() {
    const sidebar = document.getElementById('sidebar');
    const sidebarCollapseBtn = document.getElementById('sidebarCollapseBtn');
    const hamburgerMobile = document.getElementById('hamburgerMobile');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const dragHandle = document.getElementById('dragHandle');
    
    let isCollapsed = false;
    let isDragging = false;
    let startX = 0;
    let startWidth = 0;
    let savedWidth = 280;
    
    // Función para colapsar/expandir el sidebar
    function toggleCollapse() {
        if (window.innerWidth <= 768) return;
        
        if (isCollapsed) {
            // Expandir
            sidebar.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed');
            
            // Restaurar el ancho guardado
            const storedWidth = localStorage.getItem('sidebarWidth');
            if (storedWidth && storedWidth > 70) {
                sidebar.style.width = storedWidth + 'px';
                savedWidth = storedWidth;
            } else {
                sidebar.style.width = '280px';
                savedWidth = 280;
            }
            
            isCollapsed = false;
            localStorage.setItem('sidebarCollapsed', 'false');
        } else {
            // Colapsar
            // Guardar el ancho actual antes de colapsar
            const currentWidth = sidebar.offsetWidth;
            if (currentWidth > 70) {
                savedWidth = currentWidth;
                localStorage.setItem('sidebarWidth', savedWidth);
            }
            
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
            sidebar.style.width = '70px';
            
            isCollapsed = true;
            localStorage.setItem('sidebarCollapsed', 'true');
        }
    }
    
    // Función para manejar el redimensionamiento
    function initDragResize() {
        if (!dragHandle) return;
        
        dragHandle.addEventListener('mousedown', (e) => {
            // No permitir redimensionar si está colapsado o en móvil
            if (window.innerWidth <= 768) return;
            if (isCollapsed) return;
            
            isDragging = true;
            startX = e.clientX;
            startWidth = sidebar.offsetWidth;
            
            document.body.style.cursor = 'ew-resize';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            
            let newWidth = startWidth + (e.clientX - startX);
            newWidth = Math.min(320, Math.max(200, newWidth));
            sidebar.style.width = newWidth + 'px';
        });
        
        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                
                // Guardar el nuevo ancho
                if (!isCollapsed && window.innerWidth > 768) {
                    const currentWidth = sidebar.offsetWidth;
                    if (currentWidth >= 200 && currentWidth <= 320) {
                        savedWidth = currentWidth;
                        localStorage.setItem('sidebarWidth', savedWidth);
                    }
                }
            }
        });
    }
    
    // Función para manejar el sidebar en móvil
    function toggleMobileSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            mobileOverlay.classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
        }
    }
    
    function closeMobileSidebar() {
        sidebar.classList.remove('mobile-open');
        mobileOverlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
    }
    
    // Función para manejar cambios de tamaño de pantalla
    function handleResize() {
        if (window.innerWidth <= 768) {
            // Modo móvil
            if (!isCollapsed && document.body.classList.contains('sidebar-collapsed')) {
                document.body.classList.remove('sidebar-collapsed');
            }
            if (sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
                sidebar.style.width = '';
            }
            closeMobileSidebar();
        } else {
            // Modo desktop
            sidebar.classList.remove('mobile-open');
            mobileOverlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            
            // Restaurar estado colapsado desde localStorage
            const storedCollapsed = localStorage.getItem('sidebarCollapsed');
            const storedWidthVal = localStorage.getItem('sidebarWidth');
            
            if (storedCollapsed === 'true') {
                if (!sidebar.classList.contains('collapsed')) {
                    sidebar.classList.add('collapsed');
                    document.body.classList.add('sidebar-collapsed');
                    sidebar.style.width = '70px';
                    isCollapsed = true;
                }
            } else {
                if (sidebar.classList.contains('collapsed')) {
                    sidebar.classList.remove('collapsed');
                    document.body.classList.remove('sidebar-collapsed');
                }
                if (storedWidthVal && storedWidthVal > 70) {
                    sidebar.style.width = storedWidthVal + 'px';
                    savedWidth = storedWidthVal;
                } else {
                    sidebar.style.width = '280px';
                    savedWidth = 280;
                }
                isCollapsed = false;
            }
        }
    }
    
    // Cargar estado inicial
    const loadInitialState = () => {
        if (window.innerWidth > 768) {
            const storedCollapsed = localStorage.getItem('sidebarCollapsed');
            const storedWidthVal = localStorage.getItem('sidebarWidth');
            
            if (storedCollapsed === 'true') {
                sidebar.classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
                sidebar.style.width = '70px';
                isCollapsed = true;
            } else {
                sidebar.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
                if (storedWidthVal && storedWidthVal > 70) {
                    sidebar.style.width = storedWidthVal + 'px';
                    savedWidth = storedWidthVal;
                } else {
                    sidebar.style.width = '280px';
                    savedWidth = 280;
                }
                isCollapsed = false;
            }
        }
    };
    
    // Event listeners
    if (sidebarCollapseBtn) {
        sidebarCollapseBtn.addEventListener('click', toggleCollapse);
    }
    
    if (hamburgerMobile) {
        hamburgerMobile.addEventListener('click', toggleMobileSidebar);
    }
    
    if (mobileOverlay) {
        mobileOverlay.addEventListener('click', closeMobileSidebar);
    }
    
    window.addEventListener('resize', handleResize);
    
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                closeMobileSidebar();
            }
        });
    });
    
    initDragResize();
    loadInitialState();
})();
</script>