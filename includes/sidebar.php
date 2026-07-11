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

/* ============================================================
   PROTECCIÓN DE RUTAS POR ROL
   ------------------------------------------------------------
   Esta validación se ejecuta en el servidor. No solo oculta los
   enlaces del sidebar: también impide entrar escribiendo la URL.

   IMPORTANTE: este archivo debe cargarse antes de imprimir HTML
   y antes de ejecutar acciones sensibles en cada módulo.
   ============================================================ */
$user_rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));
$current_page = basename(parse_url($_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH));

$rutas_permitidas_por_rol = [
    'admin' => [
        'dashboard.php',
        'productos.php',
        'historial_stock.php',
        'ventas.php',
        'historial_ventas.php',
        'inscripciones.php',
        'asistencias.php',
        'clases.php',
        'inscripciones_clases.php',
        'reportes.php',
        'notificaciones.php',
        'configuracion.php',
        'mi_perfil.php',
    ],
    'recepcionista' => [
        'dashboard.php',
        'inscripciones.php',
        'asistencias.php',
        'reportes.php',
        'ventas.php',
        'historial_ventas.php',
        'mi_perfil.php',
    ],
    'entrenador' => [
        'dashboard.php',
        'clases.php',
        'inscripciones_clases.php',
        'asistencias.php',
        'mi_perfil.php',
    ],
];

// Si el rol guardado en la sesión no existe, cerrar la sesión.
if (!array_key_exists($user_rol, $rutas_permitidas_por_rol)) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    header('Location: login.php?error=rol_invalido');
    exit();
}

/* ============================================================
   MENSAJE AMIGABLE PARA ACCESO DENEGADO
   ============================================================ */
$nombres_roles = [
    'admin' => 'Administrador',
    'recepcionista' => 'Recepcionista',
    'entrenador' => 'Entrenador',
];

$nombres_modulos = [
    'dashboard.php' => 'Dashboard',
    'productos.php' => 'Productos',
    'historial_stock.php' => 'Historial de stock',
    'ventas.php' => 'Venta de productos',
    'historial_ventas.php' => 'Historial de ventas',
    'inscripciones.php' => 'Inscripciones',
    'asistencias.php' => 'Asistencias',
    'clases.php' => 'Clases',
    'inscripciones_clases.php' => 'Inscripciones a clases',
    'reportes.php' => 'Reportes',
    'notificaciones.php' => 'Notificaciones',
    'configuracion.php' => 'Configuración',
    'mi_perfil.php' => 'Mi perfil',
];

// Seguridad por defecto: toda página no registrada queda bloqueada.
if (!in_array($current_page, $rutas_permitidas_por_rol[$user_rol], true)) {
    $nombre_rol = $nombres_roles[$user_rol] ?? ucfirst($user_rol);
    $nombre_modulo = $nombres_modulos[$current_page] ?? 'este módulo';

    // Mensaje flash: se mostrará una sola vez al regresar al dashboard.
    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Acceso restringido',
        'mensaje' => "Tu perfil de {$nombre_rol} no tiene permiso para ingresar al módulo {$nombre_modulo}.",
        'rol' => $nombre_rol,
        'modulo' => $nombre_modulo,
    ];

    $destino = 'dashboard.php';

    // Redirección normal cuando todavía no se ha enviado HTML.
    if (!headers_sent()) {
        header('Location: ' . $destino);
    } else {
        // Respaldo para evitar el warning de headers si el sidebar fue incluido tarde.
        echo '<script>window.location.replace(' . json_encode($destino) . ');</script>';
        echo '<noscript><meta http-equiv="refresh" content="0;url=' . htmlspecialchars($destino, ENT_QUOTES, 'UTF-8') . '"></noscript>';
    }

    exit();
}

// Recuperar la alerta únicamente en el dashboard y eliminarla de la sesión.
$alerta_acceso_denegado = null;
if ($current_page === 'dashboard.php' && isset($_SESSION['alerta_acceso_denegado'])) {
    $alerta_acceso_denegado = $_SESSION['alerta_acceso_denegado'];
    unset($_SESSION['alerta_acceso_denegado']);
}

// Conectar a la base de datos para obtener configuración del gimnasio
require_once __DIR__ . '/../config/database.php';
$database = new Database();
$conn = $database->getConnection();

// Obtener configuración del gimnasio
$gym_nombre = 'Gimnasio';
$gym_logo = '';
$gym_logo_url = '';

$query = "SELECT nombre, logo FROM configuracion_gimnasio WHERE id = 1";
$result = $conn->query($query);

if ($result && $row = $result->fetch_assoc()) {
    $gym_nombre = !empty($row['nombre']) ? $row['nombre'] : 'Gimnasio';
    
    // Verificar si el logo existe en la ruta guardada
    if (!empty($row['logo']) && file_exists($row['logo'])) {
        $gym_logo = $row['logo'];
        $gym_logo_url = $row['logo'];
    }
}

// Si no hay logo en la BD, buscar en la carpeta img con cualquier extensión
if (empty($gym_logo)) {
    $extensiones = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'ico'];
    foreach ($extensiones as $ext) {
        $ruta = "img/logo-gym." . $ext;
        if (file_exists($ruta)) {
            $gym_logo = $ruta;
            $gym_logo_url = $ruta;
            break;
        }
    }
}

// Determinar módulo activo basado en la página actual
$current_page = basename($_SERVER['PHP_SELF']);
$active_module = '';

if ($current_page == 'dashboard.php') $active_module = 'dashboard';
if ($current_page == 'productos.php') $active_module = 'products';
if ($current_page == 'ventas.php') $active_module = 'ventas';
if ($current_page == 'historial_stock.php') $active_module = 'historial';
if ($current_page == 'historial_ventas.php') $active_module = 'historial_ventas';
if ($current_page == 'inscripciones.php') $active_module = 'inscriptions';
if ($current_page == 'asistencias.php') $active_module = 'assistance';
if ($current_page == 'clases.php') $active_module = 'classes';
if ($current_page == 'inscripciones_clases.php') $active_module = 'clases_inscriptions';
if ($current_page == 'reportes.php') $active_module = 'reports';
if ($current_page == 'notificaciones.php') $active_module = 'notificaciones';
if ($current_page == 'configuracion.php') $active_module = 'settings';
if ($current_page == 'mi_perfil.php') $active_module = 'perfil';

// Obtener datos del usuario desde la sesión
$user_name = isset($_SESSION['user_name']) ? $_SESSION['user_name'] : 'Usuario';
$user_email = isset($_SESSION['user_email']) ? $_SESSION['user_email'] : 'usuario@email.com';
$user_rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? 'recepcionista')));

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
   SCROLLBAR: Track azul como sidebar + Thumb gris
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

/* ============================================
   SCROLLBAR DEL SIDEBAR
   Track: AZUL como el fondo del sidebar
   Thumb: GRIS
   ============================================ */

/* Forzar estilos en sidebar y todos sus elementos */
.sidebar,
#sidebar,
aside.sidebar {
    scrollbar-width: thin !important;
    scrollbar-color: #8c959d #0a2540 !important; /* thumb gris, track azul */
}

/* WebKit (Chrome, Safari, Edge) */
.sidebar::-webkit-scrollbar,
#sidebar::-webkit-scrollbar,
aside.sidebar::-webkit-scrollbar {
    width: 6px !important;
    height: 6px !important;
}

/* Track (fondo) - AZUL como el sidebar */
.sidebar::-webkit-scrollbar-track,
#sidebar::-webkit-scrollbar-track,
aside.sidebar::-webkit-scrollbar-track {
    background: #0a2540 !important; /* Mismo color que el sidebar */
    border-radius: 0px !important;
}

/* Thumb (barra deslizante) - GRIS */
.sidebar::-webkit-scrollbar-thumb,
#sidebar::-webkit-scrollbar-thumb,
aside.sidebar::-webkit-scrollbar-thumb {
    background: #6c757d !important; /* Gris */
    border-radius: 10px !important;
}

/* Thumb al hacer hover - Gris más claro */
.sidebar::-webkit-scrollbar-thumb:hover,
#sidebar::-webkit-scrollbar-thumb:hover,
aside.sidebar::-webkit-scrollbar-thumb:hover {
    background: #8c959d !important;
}

/* Esquina del scrollbar */
.sidebar::-webkit-scrollbar-corner,
#sidebar::-webkit-scrollbar-corner,
aside.sidebar::-webkit-scrollbar-corner {
    background: #0a2540 !important;
}

/* Para elementos dentro del sidebar que puedan tener scroll */
.sidebar *::-webkit-scrollbar,
#sidebar *::-webkit-scrollbar {
    width: 6px !important;
    height: 6px !important;
}

.sidebar *::-webkit-scrollbar-track,
#sidebar *::-webkit-scrollbar-track {
    background: #0a2540 !important;
}

.sidebar *::-webkit-scrollbar-thumb,
#sidebar *::-webkit-scrollbar-thumb {
    background: #6c757d !important;
    border-radius: 10px !important;
}

.sidebar *::-webkit-scrollbar-thumb:hover,
#sidebar *::-webkit-scrollbar-thumb:hover {
    background: #8c959d !important;
}

/* Firefox - Track azul, thumb gris */
@-moz-document url-prefix() {
    .sidebar,
    #sidebar,
    aside.sidebar {
        scrollbar-width: thin !important;
        scrollbar-color: #8c959d #0a2540 !important;
    }
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
    /* Firefox */
    scrollbar-width: thin !important;
    scrollbar-color: #8c959d #0a2540 !important;
}

/* Aislar el sidebar para evitar conflictos */
.sidebar {
    isolation: isolate;
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

.sidebar.collapsed .logo-img {
    margin: 0 auto;
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

.logo-img {
    width: 35px;
    height: 35px;
    object-fit: contain;
    border-radius: 10px;
}

.logo i {
    font-size: 1.8rem;
    color: var(--sidebar-accent);
}

.logo-text {
    font-size: 1rem;
    font-weight: 600;
    color: white;
    line-height: 1.3;
}

.logo-text small {
    display: block;
    font-size: 0.65rem;
    font-weight: 400;
    color: var(--sidebar-text-light);
    margin-top: 2px;
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

/* Estilo para la imagen de perfil en el sidebar */
.user-avatar img {
    width: 45px;
    height: 45px;
    border-radius: 50%;
    object-fit: cover;
}

/* Ajuste para el ícono cuando no hay imagen */
.user-avatar i {
    font-size: 2.5rem;
    color: var(--sidebar-accent);
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
    margin-bottom: 6px;
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

/* ============================================
   GARANTIZAR SCROLLBAR EN TODAS LAS SITUACIONES
   ============================================ */
@media screen {
    /* Track azul, thumb gris */
    .sidebar,
    #sidebar,
    aside.sidebar {
        scrollbar-color: #8c959d #0a2540 !important;
        scrollbar-width: thin !important;
    }
    
    .sidebar::-webkit-scrollbar,
    #sidebar::-webkit-scrollbar,
    aside.sidebar::-webkit-scrollbar {
        width: 6px !important;
        background: #0a2540 !important;
    }
    
    .sidebar::-webkit-scrollbar-track,
    #sidebar::-webkit-scrollbar-track,
    aside.sidebar::-webkit-scrollbar-track {
        background: #0a2540 !important;
    }
    
    .sidebar::-webkit-scrollbar-thumb,
    #sidebar::-webkit-scrollbar-thumb,
    aside.sidebar::-webkit-scrollbar-thumb {
        background: #6c757d !important;
        border-radius: 10px !important;
    }
    
    .sidebar::-webkit-scrollbar-thumb:hover,
    #sidebar::-webkit-scrollbar-thumb:hover,
    aside.sidebar::-webkit-scrollbar-thumb:hover {
        background: #8c959d !important;
    }
}

/* SweetAlert de acceso restringido */
.swal-gym-popup {
    width: min(470px, calc(100vw - 32px)) !important;
    border-radius: 22px !important;
    padding: 12px 10px 20px !important;
    box-shadow: 0 24px 65px rgba(15, 37, 64, 0.24) !important;
}

.swal-gym-title {
    color: #0a2540 !important;
    font-size: 1.55rem !important;
    font-weight: 800 !important;
}

.swal-gym-confirm {
    min-width: 145px !important;
    border: none !important;
    border-radius: 11px !important;
    padding: 11px 24px !important;
    font-weight: 700 !important;
    box-shadow: 0 8px 20px rgba(37, 99, 235, 0.24) !important;
}

.swal-gym-access-content {
    padding: 2px 8px 0;
    color: #475569;
}

.swal-gym-access-content > p {
    margin: 0 0 16px;
    font-size: 0.96rem;
    line-height: 1.6;
}

.swal-gym-access-data {
    display: grid;
    gap: 9px;
    padding: 13px;
    margin: 0 0 15px;
    border: 1px solid #dbeafe;
    border-radius: 14px;
    background: #f8fbff;
    text-align: left;
}

.swal-gym-access-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 14px;
    font-size: 0.88rem;
}

.swal-gym-access-row span {
    color: #64748b;
}

.swal-gym-access-row span i {
    width: 19px;
    color: #3b82f6;
}

.swal-gym-access-row strong {
    max-width: 58%;
    color: #0f172a;
    text-align: right;
}

.swal-gym-access-help {
    margin: 0 !important;
    padding-top: 12px;
    border-top: 1px solid #e2e8f0;
    color: #64748b;
    font-size: 0.82rem !important;
}

/* Impresión */
@media print {
    .sidebar {
        display: none;
    }
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
            <?php if (!empty($gym_logo_url) && file_exists($gym_logo_url)): ?>
                <img src="<?php echo htmlspecialchars($gym_logo_url); ?>" alt="Logo" class="logo-img" onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';">
                <i class="fas fa-dumbbell" style="display: none;"></i>
            <?php else: ?>
                <i class="fas fa-dumbbell"></i>
            <?php endif; ?>
            <div class="logo-text">
                <?php echo htmlspecialchars($gym_nombre); ?>
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
            <?php 
            // Verificar si el usuario tiene foto de perfil
            $user_id = $_SESSION['user_id'];
            $foto_perfil = '';
            $query_foto = "SELECT foto_perfil FROM usuarios WHERE id = ?";
            $stmt_foto = $conn->prepare($query_foto);
            $stmt_foto->bind_param("i", $user_id);
            $stmt_foto->execute();
            $result_foto = $stmt_foto->get_result();
            if ($result_foto && $row_foto = $result_foto->fetch_assoc()) {
                $foto_perfil = $row_foto['foto_perfil'];
            }
            
            if (!empty($foto_perfil) && file_exists($foto_perfil)): ?>
                <img src="<?php echo htmlspecialchars($foto_perfil); ?>" alt="Foto de perfil" style="width: 45px; height: 45px; border-radius: 50%; object-fit: cover;">
            <?php else: ?>
                <i class="fas fa-user-circle"></i>
            <?php endif; ?>
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
            <!-- Módulos según el rol -->
            
            <?php if ($user_rol == 'admin'): ?>
                <!-- ADMIN: Acceso a todos los módulos -->
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
                    <a href="historial_stock.php" class="nav-link <?php echo $active_module == 'historial' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i>
                        <span class="nav-text">Historial Stock</span>
                    </a>
                </li>
                <!-- Dentro del bloque de admin y recepcionista, después de Dashboard -->
                <li class="nav-item">
                    <a href="ventas.php" class="nav-link <?php echo $active_module == 'ventas' ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-cart"></i>
                        <span class="nav-text">Venta de Productos</span>
                    </a>
                </li>
                <?php if ($user_rol == 'admin' || $user_rol == 'recepcionista'): ?>
                    <li class="nav-item">
                        <a href="historial_ventas.php" class="nav-link <?php echo $active_module == 'historial_ventas' ? 'active' : ''; ?>">
                            <i class="fas fa-history"></i>
                            <span class="nav-text">Historial Ventas</span>
                        </a>
                    </li>
                <?php endif; ?>
                <li class="nav-item">
                    <a href="inscripciones.php" class="nav-link <?php echo $active_module == 'inscriptions' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span class="nav-text">Inscripciones</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="asistencias.php" class="nav-link <?php echo $active_module == 'assistance' ? 'active' : ''; ?>">
                        <i class="fas fa-fingerprint"></i>
                        <span class="nav-text">Asistencias</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="clases.php" class="nav-link <?php echo $active_module == 'classes' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="nav-text">Clases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="inscripciones_clases.php" class="nav-link <?php echo $active_module == 'clases_inscriptions' ? 'active' : ''; ?>">
                        <i class="fas fa-user-check"></i>
                        <span class="nav-text">Inscripciones a Clases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reportes.php" class="nav-link <?php echo $active_module == 'reports' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i>
                        <span class="nav-text">Reportes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="notificaciones.php" class="nav-link <?php echo $active_module == 'notificaciones' ? 'active' : ''; ?>">
                        <i class="fas fa-bell"></i>
                        <span class="nav-text">Notificaciones</span>
                    </a>
                </li>
                <li class="nav-divider"></li>
                <li class="nav-item">
                    <a href="configuracion.php" class="nav-link <?php echo $active_module == 'settings' ? 'active' : ''; ?>">
                        <i class="fas fa-cog"></i>
                        <span class="nav-text">Configuración</span>
                    </a>
                </li>
                
            <?php elseif ($user_rol == 'recepcionista'): ?>
                <!-- RECEPCIONISTA: Sin productos, historial stock, ni configuración -->
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?php echo $active_module == 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="inscripciones.php" class="nav-link <?php echo $active_module == 'inscriptions' ? 'active' : ''; ?>">
                        <i class="fas fa-users"></i>
                        <span class="nav-text">Inscripciones</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="asistencias.php" class="nav-link <?php echo $active_module == 'assistance' ? 'active' : ''; ?>">
                        <i class="fas fa-fingerprint"></i>
                        <span class="nav-text">Asistencias</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="reportes.php" class="nav-link <?php echo $active_module == 'reports' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-bar"></i>
                        <span class="nav-text">Reportes</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="ventas.php" class="nav-link <?php echo $active_module == 'ventas' ? 'active' : ''; ?>">
                        <i class="fas fa-shopping-cart"></i>
                        <span class="nav-text">Venta de Productos</span>
                    </a>
                </li>
                <?php if ($user_rol == 'admin' || $user_rol == 'recepcionista'): ?>
                <li class="nav-item">
                    <a href="historial_ventas.php" class="nav-link <?php echo $active_module == 'historial_ventas' ? 'active' : ''; ?>">
                        <i class="fas fa-history"></i>
                        <span class="nav-text">Historial Ventas</span>
                    </a>
                </li>
                <?php endif; ?>
                
            <?php elseif ($user_rol == 'entrenador'): ?>
                <!-- ENTRENADOR: Solo clases, inscripciones a clases y asistencias -->
                <li class="nav-item">
                    <a href="dashboard.php" class="nav-link <?php echo $active_module == 'dashboard' ? 'active' : ''; ?>">
                        <i class="fas fa-chart-line"></i>
                        <span class="nav-text">Dashboard</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="clases.php" class="nav-link <?php echo $active_module == 'classes' ? 'active' : ''; ?>">
                        <i class="fas fa-calendar-alt"></i>
                        <span class="nav-text">Clases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="inscripciones_clases.php" class="nav-link <?php echo $active_module == 'clases_inscriptions' ? 'active' : ''; ?>">
                        <i class="fas fa-user-check"></i>
                        <span class="nav-text">Inscripciones a Clases</span>
                    </a>
                </li>
                <li class="nav-item">
                    <a href="asistencias.php" class="nav-link <?php echo $active_module == 'assistance' ? 'active' : ''; ?>">
                        <i class="fas fa-fingerprint"></i>
                        <span class="nav-text">Asistencias</span>
                    </a>
                </li>
            <?php endif; ?>
            
            <!-- Mi Perfil - Visible para todos los roles -->
            <li class="nav-divider"></li>
            <li class="nav-item">
                <a href="mi_perfil.php" class="nav-link <?php echo $active_module == 'perfil' ? 'active' : ''; ?>">
                    <i class="fas fa-user-circle"></i>
                    <span class="nav-text">Mi Perfil</span>
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

<?php if (is_array($alerta_acceso_denegado)): ?>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const alertaAcceso = <?php echo json_encode(
        $alerta_acceso_denegado,
        JSON_UNESCAPED_UNICODE |
        JSON_HEX_TAG |
        JSON_HEX_AMP |
        JSON_HEX_APOS |
        JSON_HEX_QUOT
    ); ?>;

    if (typeof Swal === 'undefined') {
        alert(alertaAcceso.mensaje || 'No tienes permiso para acceder a este módulo.');
        return;
    }

    Swal.fire({
        icon: 'warning',
        title: alertaAcceso.titulo || 'Acceso restringido',
        html: `
            <div class="swal-gym-access-content">
                <p>${alertaAcceso.mensaje}</p>

                <div class="swal-gym-access-data">
                    <div class="swal-gym-access-row">
                        <span><i class="fas fa-user-shield"></i> Rol actual</span>
                        <strong>${alertaAcceso.rol}</strong>
                    </div>
                    <div class="swal-gym-access-row">
                        <span><i class="fas fa-ban"></i> Módulo solicitado</span>
                        <strong>${alertaAcceso.modulo}</strong>
                    </div>
                </div>

                <p class="swal-gym-access-help">
                    Si necesitas utilizar esta función, solicita autorización a un administrador del sistema.
                </p>
            </div>
        `,
        confirmButtonText: 'Entendido',
        confirmButtonColor: '#2563eb',
        allowOutsideClick: false,
        allowEscapeKey: true,
        buttonsStyling: true,
        customClass: {
            popup: 'swal-gym-popup',
            title: 'swal-gym-title',
            confirmButton: 'swal-gym-confirm'
        }
    });
});
</script>
<?php endif; ?>

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
    
    function toggleCollapse() {
        if (window.innerWidth <= 768) return;
        
        if (isCollapsed) {
            sidebar.classList.remove('collapsed');
            document.body.classList.remove('sidebar-collapsed');
            
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
    
    function initDragResize() {
        if (!dragHandle) return;
        
        dragHandle.addEventListener('mousedown', (e) => {
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
    
    function handleResize() {
        if (window.innerWidth <= 768) {
            if (!isCollapsed && document.body.classList.contains('sidebar-collapsed')) {
                document.body.classList.remove('sidebar-collapsed');
            }
            if (sidebar.classList.contains('collapsed')) {
                sidebar.classList.remove('collapsed');
                sidebar.style.width = '';
            }
            closeMobileSidebar();
        } else {
            sidebar.classList.remove('mobile-open');
            mobileOverlay.classList.remove('active');
            document.body.classList.remove('sidebar-open');
            
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