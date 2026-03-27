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

// Obtener datos del usuario desde la sesión (coincidiendo con tu login)
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

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    background: #f5f7fa;
    margin: 0;
    padding: 0;
    overflow-x: hidden;
}

/* Botón Hamburguesa */
.hamburger-btn {
    position: fixed;
    top: 20px;
    left: 20px;
    z-index: 1002;
    background: var(--sidebar-bg);
    border: none;
    cursor: pointer;
    width: 45px;
    height: 45px;
    border-radius: 12px;
    color: white;
    font-size: 1.2rem;
    display: flex;
    align-items: center;
    justify-content: center;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
    transition: all 0.3s ease;
}

.hamburger-btn:hover {
    background: var(--sidebar-hover);
    transform: scale(1.02);
}

/* Sidebar */
.sidebar {
    position: fixed;
    left: 0;
    top: 0;
    width: 280px;
    height: 100vh;
    background: var(--sidebar-bg);
    display: flex;
    flex-direction: column;
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    z-index: 1001;
    overflow-y: auto;
    overflow-x: hidden;
    box-shadow: 2px 0 12px rgba(0, 0, 0, 0.1);
}

/* Estado Colapsado en PC */
.sidebar.collapsed {
    width: 75px;
}

.sidebar.collapsed .logo-text,
.sidebar.collapsed .user-info,
.sidebar.collapsed .nav-text,
.sidebar.collapsed .logout-text {
    display: none;
}

.sidebar.collapsed .logo {
    justify-content: center;
    padding: 0;
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

/* Estado Móvil */
@media (max-width: 768px) {
    .sidebar {
        transform: translateX(-100%);
        width: 280px;
        box-shadow: none;
    }
    
    .sidebar.mobile-open {
        transform: translateX(0);
        box-shadow: 2px 0 12px rgba(0, 0, 0, 0.2);
    }
}

/* Sidebar Header */
.sidebar-header {
    padding: 24px 20px;
    border-bottom: 1px solid var(--sidebar-border);
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
    z-index: 1000;
    display: none;
}

.mobile-overlay.active {
    display: block;
}

/* Ajuste para el contenido principal */
.main-content {
    margin-left: 280px;
    transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    min-height: 100vh;
    padding: 20px;
}

body.sidebar-collapsed .main-content {
    margin-left: 75px;
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

/* Mejoras para móvil - Asegurar visibilidad de letras */
@media (max-width: 768px) {
    .nav-text, 
    .logo-text, 
    .user-info h4, 
    .user-info p,
    .logout-text {
        display: block !important;
        visibility: visible !important;
        opacity: 1 !important;
    }
    
    .sidebar.collapsed .nav-text,
    .sidebar.collapsed .logo-text,
    .sidebar.collapsed .user-info,
    .sidebar.collapsed .logout-text {
        display: none !important;
    }
    
    .nav-link {
        padding: 14px 20px;
    }
    
    .nav-link i {
        font-size: 1.2rem;
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

<!-- Botón Hamburguesa -->
<button class="hamburger-btn" id="hamburgerBtn">
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
    const hamburgerBtn = document.getElementById('hamburgerBtn');
    const mobileOverlay = document.getElementById('mobileOverlay');
    const dragHandle = document.getElementById('dragHandle');
    
    let isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
    let isDragging = false;
    let startX = 0;
    let startWidth = 0;
    
    function toggleCollapse() {
        if (window.innerWidth > 768) {
            isCollapsed = !isCollapsed;
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
            } else {
                sidebar.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
            }
            localStorage.setItem('sidebarCollapsed', isCollapsed);
        }
    }
    
    function toggleMobileSidebar() {
        if (window.innerWidth <= 768) {
            sidebar.classList.toggle('mobile-open');
            mobileOverlay.classList.toggle('active');
            document.body.classList.toggle('sidebar-open');
        } else {
            toggleCollapse();
        }
    }
    
    function closeMobileSidebar() {
        sidebar.classList.remove('mobile-open');
        mobileOverlay.classList.remove('active');
        document.body.classList.remove('sidebar-open');
    }
    
    if (isCollapsed && window.innerWidth > 768) {
        sidebar.classList.add('collapsed');
        document.body.classList.add('sidebar-collapsed');
    }
    
    function initDragResize() {
        if (!dragHandle) return;
        
        dragHandle.addEventListener('mousedown', (e) => {
            if (window.innerWidth <= 768) return;
            if (isCollapsed) return;
            
            isDragging = true;
            startX = e.clientX;
            startWidth = parseInt(window.getComputedStyle(sidebar).width, 10);
            
            document.body.style.cursor = 'ew-resize';
            document.body.style.userSelect = 'none';
            e.preventDefault();
        });
        
        document.addEventListener('mousemove', (e) => {
            if (!isDragging) return;
            
            let newWidth = startWidth + (e.clientX - startX);
            newWidth = Math.min(320, Math.max(200, newWidth));
            sidebar.style.width = newWidth + 'px';
            
            if (newWidth < 210 && !isCollapsed) {
                sidebar.classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
                isCollapsed = true;
                localStorage.setItem('sidebarCollapsed', true);
            } else if (newWidth >= 210 && isCollapsed) {
                sidebar.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
                isCollapsed = false;
                localStorage.setItem('sidebarCollapsed', false);
            }
        });
        
        document.addEventListener('mouseup', () => {
            if (isDragging) {
                isDragging = false;
                document.body.style.cursor = '';
                document.body.style.userSelect = '';
                
                if (!isCollapsed && window.innerWidth > 768) {
                    const currentWidth = parseInt(window.getComputedStyle(sidebar).width, 10);
                    if (currentWidth >= 200 && currentWidth <= 320) {
                        localStorage.setItem('sidebarWidth', currentWidth);
                    }
                }
            }
        });
    }
    
    function restoreWidth() {
        if (window.innerWidth <= 768) return;
        const savedWidth = localStorage.getItem('sidebarWidth');
        if (savedWidth && !isCollapsed) {
            const width = parseInt(savedWidth, 10);
            if (width >= 200 && width <= 320) {
                sidebar.style.width = width + 'px';
            }
        }
    }
    
    function handleResize() {
        if (window.innerWidth <= 768) {
            sidebar.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed');
            sidebar.style.width = '';
            closeMobileSidebar();
        } else {
            sidebar.classList.remove('mobile-open');
            mobileOverlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            if (isCollapsed) {
                sidebar.classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
            } else {
                sidebar.classList.remove('collapsed');
                document.body.classList.remove('sidebar-collapsed');
                restoreWidth();
            }
        }
    }
    
    hamburgerBtn.addEventListener('click', toggleMobileSidebar);
    mobileOverlay.addEventListener('click', closeMobileSidebar);
    window.addEventListener('resize', handleResize);
    
    document.querySelectorAll('.nav-link').forEach(link => {
        link.addEventListener('click', () => {
            if (window.innerWidth <= 768) {
                closeMobileSidebar();
            }
        });
    });
    
    initDragResize();
    handleResize();
    restoreWidth();
})();
</script>