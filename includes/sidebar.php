<?php
// Archivo: includes/sidebar.php
// Sidebar reutilizable para todos los módulos

// Determinar módulo activo basado en la página actual
$current_page = basename($_SERVER['PHP_SELF']);
$active_module = '';

if ($current_page == 'dashboard.php') $active_module = 'dashboard';
if ($current_page == 'productos.php') $active_module = 'products';
if ($current_page == 'inscripciones.php') $active_module = 'inscriptions';
if ($current_page == 'clases.php') $active_module = 'classes';
if ($current_page == 'reportes.php') $active_module = 'reports';
if ($current_page == 'configuracion.php') $active_module = 'settings';
?>

<!-- Sidebar -->
<aside class="sidebar">
    <div class="sidebar-header">
        <div class="logo">
            <i class="fas fa-dumbbell"></i>
            <div>
                <span>Gym System</span>
                <small>Panel de control</small>
            </div>
        </div>
    </div>

    <div class="user-profile">
        <div class="user-avatar">
            <i class="fas fa-user-circle"></i>
        </div>
        <div class="user-info">
            <h4><?php echo htmlspecialchars($_SESSION['user_name']); ?></h4>
            <p>
                <i class="fas fa-envelope"></i> 
                <?php echo htmlspecialchars($_SESSION['user_email']); ?>
            </p>
            <span class="rol-badge">
                <i class="fas fa-user-tag"></i> 
                <?php echo ucfirst(htmlspecialchars($_SESSION['user_rol'])); ?>
            </span>
        </div>
    </div>

    <nav class="sidebar-nav">
        <ul>
            <li>
                <a href="dashboard.php" class="<?php echo $active_module == 'dashboard' ? 'active' : ''; ?>">
                    <i class="fas fa-home"></i>
                    <span>Dashboard</span>
                </a>
            </li>
            <li>
                <a href="productos.php" class="<?php echo $active_module == 'products' ? 'active' : ''; ?>">
                    <i class="fas fa-box"></i>
                    <span>Productos</span>
                </a>
            </li>
            <li>
                <a href="inscripciones.php" class="<?php echo $active_module == 'inscriptions' ? 'active' : ''; ?>">
                    <i class="fas fa-users"></i>
                    <span>Inscripciones</span>
                </a>
            </li>
            <li>
                <a href="clases.php" class="<?php echo $active_module == 'classes' ? 'active' : ''; ?>">
                    <i class="fas fa-calendar-alt"></i>
                    <span>Clases</span>
                </a>
            </li>
            <li>
                <a href="reportes.php" class="<?php echo $active_module == 'reports' ? 'active' : ''; ?>">
                    <i class="fas fa-chart-bar"></i>
                    <span>Reportes</span>
                </a>
            </li>
            <li class="nav-divider"></li>
            <li>
                <a href="configuracion.php" class="<?php echo $active_module == 'settings' ? 'active' : ''; ?>">
                    <i class="fas fa-cog"></i>
                    <span>Configuración</span>
                </a>
            </li>
        </ul>
    </nav>

    <div class="sidebar-footer">
        <a href="logout.php" class="logout-btn">
            <i class="fas fa-sign-out-alt"></i>
            <span>Cerrar Sesión</span>
        </a>
    </div>
</aside>