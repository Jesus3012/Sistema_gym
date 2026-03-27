<?php
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Productos - Gym System</title>
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
                    <h2>Productos</h2>
                    <div class="breadcrumb">
                        <a href="dashboard.php">Inicio</a> / Productos
                    </div>
                </div>
                <div class="page-actions">
                    <button class="btn-primary" onclick="window.location.href='productos_nuevo.php'">
                        <i class="fas fa-plus"></i> Nuevo Producto
                    </button>
                </div>
            </div>

            <div class="content-area">
                <!-- Contenido específico del módulo de productos -->
                <div class="card">
                    <div class="card-header">
                        <h3>Lista de Productos</h3>
                    </div>
                    <div class="card-body">
                        <!-- Aquí irá la tabla de productos -->
                        <p>Contenido del módulo de productos...</p>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>