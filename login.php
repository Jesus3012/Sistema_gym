<?php
session_start();
require_once 'config/database.php';

// Verificar si la sesión actual sigue siendo válida
if (isset($_SESSION['user_id']) && isset($_SESSION['login_time'])) {
    $tiempo_sesion = time() - $_SESSION['login_time'];
    $tiempo_maximo = 12 * 3600; // 12 horas en segundos
    
    if ($tiempo_sesion < $tiempo_maximo) {
        // Verificar inactividad (también 12 horas)
        if (isset($_SESSION['last_activity'])) {
            $tiempo_inactividad = time() - $_SESSION['last_activity'];
            if ($tiempo_inactividad < $tiempo_maximo) {
                // Sesión válida, redirigir al dashboard
                header("Location: dashboard.php");
                exit();
            }
        }
    }
    
    // Sesión expirada, cerrar sesión
    session_unset();
    session_destroy();
    $error = "sesion_expirada";
}

$error = '';
$mensaje_swal = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $database = new Database();
    $db = $database->getConnection();
    
    $email = mysqli_real_escape_string($db, $_POST['email']);
    $password = $_POST['password'];
    
    if (empty($email) || empty($password)) {
        $error = "campos_vacios";
    } else {
        // Modificar la consulta para incluir el campo estado
        $query = "SELECT id, nombre, email, password, rol, estado FROM usuarios WHERE email = ?";
        $stmt = $db->prepare($query);
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows == 1) {
            $user = $result->fetch_assoc();
            
            // Verificar si el usuario está activo
            if ($user['estado'] == 'inactivo') {
                $error = "usuario_inactivo";
            } elseif (password_verify($password, $user['password'])) {
                // Regenerar ID de sesión por seguridad
                session_regenerate_id(true);
                
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_name'] = $user['nombre'];
                $_SESSION['user_email'] = $user['email'];
                $_SESSION['user_rol'] = $user['rol'];
                $_SESSION['login_time'] = time(); // Registrar tiempo de inicio de sesión
                $_SESSION['last_activity'] = time(); // Registrar última actividad
                
                header("Location: dashboard.php");
                exit();
            } else {
                $error = "password_incorrecta";
            }
        } else {
            $error = "usuario_no_encontrado";
        }
        
        $stmt->close();
    }
}

// Mapear errores a mensajes para SweetAlert
$mensaje_error = '';
$tipo_error = '';
if ($error == 'campos_vacios') {
    $mensaje_error = 'Por favor complete todos los campos';
    $tipo_error = 'warning';
} elseif ($error == 'password_incorrecta') {
    $mensaje_error = 'Contraseña incorrecta';
    $tipo_error = 'error';
} elseif ($error == 'usuario_no_encontrado') {
    $mensaje_error = 'Usuario no encontrado';
    $tipo_error = 'error';
} elseif ($error == 'usuario_inactivo') {
    $mensaje_error = 'Su cuenta está desactivada. Por favor contacte al administrador.';
    $tipo_error = 'error';
} elseif ($error == 'sesion_expirada') {
    $mensaje_error = 'Tu sesión ha expirado después de 12 horas. Por favor inicia sesión nuevamente.';
    $tipo_error = 'info';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>Iniciar Sesión - Gym System</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        /* Estilos exclusivos para el login */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background-color: #e6f0fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
        }

        /* Login Card */
        .login-container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 8px 20px rgba(0, 51, 102, 0.12);
            padding: 20px 18px 30px 18px;
            border: 1px solid rgba(0, 51, 102, 0.1);
            width: 100%;
        }

        /* Header con logo y título juntos */
        .login-header {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            margin-bottom: 40px;
            padding-bottom: 15px;
            border-bottom: 2px solid #eef2f6;
        }

        .logo-img {
            width: 70px;
            height: 50px;
            object-fit: cover;
            border-radius: 12px;
        }

        .header-text {
            text-align: left;
        }

        .header-text h1 {
            color: #003366;
            font-size: 24px;
            font-weight: 700;
            margin: 0;
            letter-spacing: -0.5px;
        }

        .header-text p {
            color: #666;
            font-size: 13px;
            margin: 5px 0 0 0;
        }

        /* Alertas */
        .alert {
            padding: 10px 14px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 12px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert i {
            font-size: 14px;
        }

        /* Formulario */
        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 6px;
            color: #003366;
            font-size: 13px;
            font-weight: 500;
        }

        /* Input con icono de contraseña */
        .password-wrapper {
            position: relative;
            width: 100%;
        }

        .form-group input {
            width: 100%;
            padding: 12px 14px;
            font-size: 14px;
            color: #333;
            background-color: #f8fafd;
            border: 1.2px solid #dde7f0;
            border-radius: 10px;
            transition: all 0.2s;
        }

        .password-wrapper input {
            padding-right: 45px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #003366;
            background-color: #fff;
            box-shadow: 0 0 0 2px rgba(0, 51, 102, 0.1);
        }

        .form-group input::placeholder {
            color: #aaa;
            font-size: 13px;
        }

        .toggle-password {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #888;
            font-size: 18px;
            background: transparent;
            border: none;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .toggle-password:hover {
            color: #003366;
        }

        /* Botón de login */
        .btn-login {
            width: 100%;
            padding: 12px;
            background-color: #003366;
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.2s;
            margin: 15px 0 20px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .btn-login:hover {
            background-color: #0047b3;
        }

        .btn-login:active {
            transform: translateY(1px);
        }

        /* Enlaces */
        .links {
            text-align: center;
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-top: 5px;
        }

        .links a {
            color: #666;
            text-decoration: none;
            font-size: 12px;
            transition: color 0.2s;
        }

        .links a:hover {
            color: #003366;
            text-decoration: underline;
        }

        .links .bold-link {
            color: #003366;
            font-weight: 600;
            font-size: 13px;
        }

        /* Footer */
        .login-footer {
            text-align: center;
            margin-top: 25px;
            padding-top: 15px;
            border-top: 1px solid #eef2f6;
            color: #888;
            font-size: 11px;
        }

        /* Responsive - Móviles */
        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .container {
                max-width: 100%;
            }
            
            .login-container {
                padding: 20px 18px 25px 18px;
            }
            
            .login-header {
                gap: 12px;
                margin-bottom: 25px;
            }
            
            .logo-img {
                width: 45px;
                height: 45px;
            }
            
            .header-text h1 {
                font-size: 20px;
            }
            
            .header-text p {
                font-size: 11px;
            }
            
            .form-group {
                margin-bottom: 18px;
            }
            
            .form-group label {
                font-size: 12px;
            }
            
            .form-group input,
            .password-wrapper input {
                padding: 10px 12px;
                font-size: 13px;
            }
            
            .password-wrapper input {
                padding-right: 40px;
            }
            
            .btn-login {
                padding: 10px;
                font-size: 13px;
                margin: 12px 0 18px;
            }
            
            .links a {
                font-size: 11px;
            }
            
            .links .bold-link {
                font-size: 12px;
            }
            
            .login-footer {
                margin-top: 20px;
                padding-top: 12px;
                font-size: 10px;
            }
        }

        /* Para pantallas muy pequeñas */
        @media (max-width: 360px) {
            .login-container {
                padding: 18px 15px 22px 15px;
            }
            
            .login-header {
                gap: 10px;
            }
            
            .logo-img {
                width: 40px;
                height: 40px;
            }
            
            .header-text h1 {
                font-size: 18px;
            }
            
            .header-text p {
                font-size: 10px;
            }
            
            .form-group input,
            .password-wrapper input {
                padding: 9px 12px;
                font-size: 12px;
            }
            
            .btn-login {
                padding: 9px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <!-- Header con logo y título juntos -->
            <div class="login-header">
                <img src="img/logo-gym.jpg" alt="Gym System Logo" class="logo-img" onerror="this.src='https://via.placeholder.com/50x50?text=G'">
                <div class="header-text">
                    <h1>Sistema de Gimnasio</h1>
                    <center><p>Inicia sesion para continuar</p></center>  
                </div>
            </div>
            
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label for="email">Correo electrónico</label>
                    <input type="email" id="email" name="email" 
                           placeholder="ejemplo@correo.com" 
                           value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                           required>
                </div>
                
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <div class="password-wrapper">
                        <input type="password" id="password" name="password" 
                               placeholder="••••••••" required>
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
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
    
    <script>
    // Función para mostrar/ocultar contraseña
    function togglePassword() {
        const passwordInput = document.getElementById('password');
        const toggleIcon = document.querySelector('.toggle-password i');
        
        if (passwordInput.type === 'password') {
            passwordInput.type = 'text';
            toggleIcon.classList.remove('fa-eye');
            toggleIcon.classList.add('fa-eye-slash');
        } else {
            passwordInput.type = 'password';
            toggleIcon.classList.remove('fa-eye-slash');
            toggleIcon.classList.add('fa-eye');
        }
    }
    
    // Mostrar alerta si hay error
    <?php if ($mensaje_error): ?>
    Swal.fire({
        icon: '<?php echo $tipo_error; ?>',
        title: '<?php 
            if ($error == 'usuario_inactivo') {
                echo 'Cuenta Desactivada';
            } elseif ($tipo_error == 'warning') {
                echo 'Atención';
            } elseif ($tipo_error == 'info') {
                echo 'Información';
            } else {
                echo 'Error';
            }
        ?>',
        text: '<?php echo $mensaje_error; ?>',
        confirmButtonColor: '#003366',
        confirmButtonText: 'Entendido',
        timer: <?php echo ($error == 'usuario_inactivo') ? '5000' : ($tipo_error == 'info' ? '5000' : '3000'); ?>,
        timerProgressBar: true
    });
    <?php endif; ?>
    
    // Validación adicional del formulario
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const email = document.getElementById('email').value;
        const password = document.getElementById('password').value;
        
        if (!email || !password) {
            e.preventDefault();
            Swal.fire({
                icon: 'warning',
                title: 'Campos incompletos',
                text: 'Por favor complete todos los campos',
                confirmButtonColor: '#003366',
                confirmButtonText: 'OK'
            });
        }
    });
    </script>
</body>
</html>