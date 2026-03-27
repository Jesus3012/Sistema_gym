<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Array de módulos del sistema (solo para el contenido)
$modules = [
    'products' => [
        'icon' => 'fa-box',
        'title' => 'Productos',
        'description' => 'Gestión de inventario y productos',
        'url' => 'productos.php'
    ],
    'inscriptions' => [
        'icon' => 'fa-users',
        'title' => 'Inscripciones',
        'description' => 'Control de miembros y membresías',
        'url' => 'inscripciones.php'
    ],
    'classes' => [
        'icon' => 'fa-calendar-alt',
        'title' => 'Clases',
        'description' => 'Horarios y reservas de clases',
        'url' => 'clases.php'
    ],
    'reports' => [
        'icon' => 'fa-chart-bar',
        'title' => 'Reportes',
        'description' => 'Estadísticas y reportes del gym',
        'url' => 'reportes.php'
    ]
];
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Gym System</title>
    <!-- Fuentes y estilos -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/dashboard.css">
</head>
<body>
    <div class="dashboard-layout">
        <!-- Incluir el sidebar -->
        <?php include 'includes/sidebar.php'; ?>

        <!-- Contenido principal -->
        <main class="main-content">
            <div class="top-bar">
                <div class="page-title">
                    <h2>Dashboard</h2>
                    <div class="breadcrumb">
                        <a href="dashboard.php">Inicio</a> / Dashboard
                    </div>
                </div>
                <div class="page-actions">
                    <span class="date">
                        <i class="far fa-calendar"></i> 
                        <?php echo date('d/m/Y'); ?>
                    </span>
                </div>
            </div>

            <div class="content-area">
                <!-- Tarjeta de bienvenida -->
                <div class="welcome-card">
                    <h1>¡Bienvenido, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h1>
                    <p>Selecciona un módulo para comenzar a trabajar</p>
                </div>

                <!-- Módulos del sistema -->
                <h3 style="color: var(--primary); margin-bottom: 20px; font-size: 18px;">
                    <i class="fas fa-cubes" style="margin-right: 10px;"></i>
                    Módulos Disponibles
                </h3>
                
                <div class="modules-grid">
                    <?php foreach ($modules as $module): ?>
                    <div class="module-card" onclick="window.location.href='<?php echo $module['url']; ?>'">
                        <div class="module-card-header">
                            <i class="fas <?php echo $module['icon']; ?>"></i>
                        </div>
                        <div class="module-card-body">
                            <h3><?php echo $module['title']; ?></h3>
                            <p><?php echo $module['description']; ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </main>
    </div>
</body>
</html>