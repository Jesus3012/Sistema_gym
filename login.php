<?php
session_start();
require_once 'config/database.php';

if (isset($_SESSION['user_id'])) {
    header("Location: dashboard.php");
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    $email = mysqli_real_escape_string($db, $_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = "Por favor complete todos los campos";
    } else {
        $query = "SELECT id, nombre, email, password, rol FROM usuarios WHERE email = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            if (password_verify($password, $user['password'])) {
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['nombre'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_rol'] = $user['rol'];
                
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "Contraseña incorrecta";
            }
        } else {
            $error = "Usuario no encontrado";
        }
        
        $stmt->close();
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Iniciar Sesión - Gym System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/login.css">
</head>
<body>
    <div class="container">
        <div class="login-container">
            <!-- Logo (reemplaza la URL con la de tu logo) -->
            <div class="logo-container">
                <img src="img/logo-gym.jpg" alt="Gym System Logo">
            </div>
            
            <div class="login-header">
                <h1>Sistema de Gym</h1>
                <p>Inicia sesión para continuar</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-circle"></i>
                    <?php echo $error; ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" 
                           placeholder="ejemplo@correo.com" 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                           required>
                </div>
                
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <input type="password" id="password" name="password" 
                           placeholder="••••••••" required>
                </div>
                
                <button type="submit" class="btn-login">
                    Iniciar sesión
                </button>
            </form>
            
            <div class="links">
                <a href="recuperar-password.php">¿Olvidaste tu contraseña?</a>
                <a href="registro.php" class="bold-link">Crear una cuenta</a>
            </div>
            
            <div class="login-footer">
                &copy; <?php echo date('Y'); ?> Todos los derechos reservados.
            </div>
        </div>
    </div>
</body>
</html>