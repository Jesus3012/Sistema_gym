<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $default_password = "ego1";
    $hashed_password = password_hash($default_password, PASSWORD_DEFAULT);

    $query = "INSERT INTO usuarios (nombre, email, password, rol, password_change_required, estado) 
            VALUES (?, ?, ?, ?, 1, 'activo')";
    $stmt = $db->prepare($query);
    $stmt->bind_param("ssss", $nombre, $email, $hashed_password, $rol);
    }
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Registro - Gym System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="container">
        <div class="login-container">
            <div class="logo-container">
                <img src="img/logo-gym.png" alt="Gym System Logo" onerror="this.src='https://via.placeholder.com/120x80/003366/ffffff?text=GYM'">
            </div>
            
            <div class="login-header">
                <h1>Crear Cuenta</h1>
                <p>Regístrate en Gym System</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i>
                    <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="nombre">Nombre completo</label>
                    <input type="text" id="nombre" name="nombre" 
                           placeholder="Tu nombre" required>
                </div>
                
                <div class="form-group">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" 
                           placeholder="ejemplo@correo.com" required>
                </div>
                
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" 
                           placeholder="Mínimo 6 caracteres" required>
                </div>
                
                <div class="form-group">
                    <label for="confirm_password">Confirmar contraseña</label>
                    <input type="password" id="confirm_password" name="confirm_password" 
                           placeholder="Repite tu contraseña" required>
                </div>
                
                <button type="submit" class="btn-login">
                    Registrarse
                </button>
            </form>
            
            <div class="links">
                <a href="login.php" class="bold-link">¿Ya tienes cuenta? Inicia sesión</a>
            </div>
            
            <div class="login-footer">
                &copy; <?php echo date('Y'); ?> Gym System. Todos los derechos reservados.
            </div>
        </div>
    </div>
</body>
</html>