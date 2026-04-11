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

// Obtener configuración del gimnasio (incluyendo el logo)
$database = new Database();
$db = $database->getConnection();

// Función para obtener el logo del gimnasio
function getGymLogo($conn) {
    // Intentar obtener logo de la base de datos
    $query = "SELECT logo FROM configuracion_gimnasio WHERE id = 1";
    $result = $conn->query($query);
    
    if ($result && $row = $result->fetch_assoc()) {
        if (!empty($row['logo']) && file_exists($row['logo'])) {
            // Agregar timestamp para evitar caché
            $timestamp = filemtime($row['logo']);
            return $row['logo'] . '?v=' . $timestamp;
        }
    }
    
    // Buscar logo con cualquier extensión en la carpeta img
    $extensiones = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg', 'ico'];
    foreach ($extensiones as $ext) {
        $ruta = "img/logo-gym." . $ext;
        if (file_exists($ruta)) {
            $timestamp = filemtime($ruta);
            return $ruta . '?v=' . $timestamp;
        }
    }
    
    // Logo por defecto (placeholder)
    return 'https://via.placeholder.com/80x80?text=GYM';
}

// Función para obtener el nombre del gimnasio
function getGymName($conn) {
    $query = "SELECT nombre FROM configuracion_gimnasio WHERE id = 1";
    $result = $conn->query($query);
    
    if ($result && $row = $result->fetch_assoc()) {
        return htmlspecialchars($row['nombre']);
    }
    
    return 'Gym System';
}

// Obtener datos del gimnasio
$gym_logo = getGymLogo($db);
$gym_name = getGymName($db);

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
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
    <title><?php echo $gym_name; ?> - Iniciar Sesión</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <!-- SweetAlert2 CSS -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    
    <!-- SweetAlert2 JS -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', 'Poppins', 'Roboto', sans-serif;
            min-height: 100vh;
            background: #f5f5ff;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }

        .container {
            width: 100%;
            max-width: 450px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }

        /* Login Card */
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            padding: 40px 35px;
            transition: transform 0.3s ease;
        }

        .login-container:hover {
            transform: translateY(-5px);
        }

        /* Header con logo y título */
        .login-header {
            text-align: center;
            margin-bottom: 35px;
        }

        .logo-wrapper {
            margin-bottom: 20px;
        }

        .logo-img {
            width: 80px;
            height: 80px;
            object-fit: contain; /* Cambiado de cover a contain para que no se recorte */
            border-radius: 20px;
            box-shadow: 0 5px 15px rgba(0, 51, 102, 0.2);
            transition: transform 0.3s ease;
            background-color: #f8f9fa; /* Fondo claro por si el logo es transparente */
            padding: 5px;
        }

        .logo-img:hover {
            transform: scale(1.05);
        }

        .header-text h1 {
            color: #003366;
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }

        .header-text p {
            color: #666;
            font-size: 14px;
        }

        /* Formulario */
        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            color: #003366;
            font-size: 13px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Input con icono */
        .input-wrapper {
            position: relative;
            width: 100%;
        }

        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #003366;
            font-size: 16px;
        }

        .form-group input {
            width: 100%;
            padding: 14px 15px 14px 45px;
            font-size: 14px;
            color: #333;
            background-color: #f8f9fa;
            border: 2px solid #e1e8ed;
            border-radius: 12px;
            transition: all 0.3s ease;
        }

        .password-wrapper input {
            padding-right: 45px;
        }

        .form-group input:focus {
            outline: none;
            border-color: #003366;
            background-color: #fff;
            box-shadow: 0 0 0 4px rgba(0, 51, 102, 0.1);
        }

        .form-group input::placeholder {
            color: #bbb;
            font-size: 13px;
        }

        .toggle-password {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #003366;
            font-size: 16px;
            background: transparent;
            border: none;
            padding: 0;
            transition: color 0.3s ease;
        }

        .toggle-password:hover {
            color: #0047b3;
        }

        /* Opciones adicionales */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
        }

        .checkbox {
            display: flex;
            align-items: center;
            cursor: pointer;
        }

        .checkbox input {
            margin-right: 8px;
            cursor: pointer;
            accent-color: #003366;
        }

        .checkbox span {
            font-size: 13px;
            color: #666;
        }

        .forgot-link {
            font-size: 13px;
            color: #003366;
            text-decoration: none;
            transition: color 0.3s ease;
        }

        .forgot-link:hover {
            color: #0047b3;
            text-decoration: underline;
        }

        /* Botón de login */
        .btn-login {
            width: 100%;
            padding: 14px;
            background: #003366;
            color: white;
            border: none;
            border-radius: 12px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-bottom: 25px;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: relative;
            overflow: hidden;
        }

        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent);
            transition: left 0.5s ease;
        }

        .btn-login:hover::before {
            left: 100%;
        }

        .btn-login:hover {
            background: #0047b3;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 51, 102, 0.3);
        }

        .btn-login:active {
            transform: translateY(0);
        }

        /* Enlace de registro */
        .register-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid #e1e8ed;
        }

        .register-link p {
            color: #666;
            font-size: 14px;
        }

        .register-link a {
            color: #003366;
            text-decoration: none;
            font-weight: 600;
            transition: color 0.3s ease;
        }

        .register-link a:hover {
            color: #0047b3;
            text-decoration: underline;
        }

        /* Footer */
        .login-footer {
            text-align: center;
            margin-top: 25px;
            color: #aaa;
            font-size: 11px;
        }

        /* Animaciones */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .login-container {
            animation: fadeInUp 0.6s ease;
        }

        /* Responsive */
        @media (max-width: 480px) {
            body {
                padding: 15px;
            }
            
            .login-container {
                padding: 30px 20px;
            }
            
            .logo-img {
                width: 60px;
                height: 60px;
            }
            
            .header-text h1 {
                font-size: 24px;
            }
            
            .form-group input {
                padding: 12px 15px 12px 40px;
            }
            
            .btn-login {
                padding: 12px;
                font-size: 14px;
            }
            
            .form-options {
                flex-direction: column;
                gap: 10px;
                align-items: flex-start;
            }
        }

        /* Para pantallas muy pequeñas */
        @media (max-width: 360px) {
            .login-container {
                padding: 25px 18px;
            }
            
            .header-text h1 {
                font-size: 22px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="login-container">
            <!-- Header con logo y título dinámicos -->
            <div class="login-header">
                <div class="logo-wrapper">
                    <img src="<?php echo $gym_logo; ?>" 
                         alt="<?php echo $gym_name; ?> Logo" 
                         class="logo-img" 
                         onerror="this.src='https://via.placeholder.com/80x80?text=GYM'">
                </div>
                <div class="header-text">
                    <h1><?php echo $gym_name; ?></h1>
                    <p>Inicia sesión para continuar</p>
                </div>
            </div>
            
            <form method="POST" action="" id="loginForm">
                <div class="form-group">
                    <label for="email">Correo electrónico</label>
                    <div class="input-wrapper">
                        <i class="fas fa-envelope input-icon"></i>
                        <input type="email" id="email" name="email" 
                               placeholder="ejemplo@correo.com" 
                               value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>" 
                               required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="password">Contraseña</label>
                    <div class="input-wrapper password-wrapper">
                        <i class="fas fa-lock input-icon"></i>
                        <input type="password" id="password" name="password" 
                               placeholder="Ingresa tu contraseña" required>
                        <button type="button" class="toggle-password" onclick="togglePassword()">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-options">
                    <label class="checkbox">
                        <input type="checkbox" id="remember">
                        <span>Recordarme</span>
                    </label>
                    <a href="recuperar-password.php" class="forgot-link">¿Olvidaste tu contraseña?</a>
                </div>
                
                <button type="submit" class="btn-login">
                    Iniciar sesión
                </button>
            </form>
            
            <div class="register-link">
                <p>¿No tienes una cuenta? <a href="registro.php">Crear cuenta</a></p>
            </div>
            
            <div class="login-footer">
                &copy; <?php echo date('Y'); ?> <?php echo $gym_name; ?>. Todos los derechos reservados.
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
    
    // Guardar email si se selecciona "Recordarme"
    document.getElementById('loginForm').addEventListener('submit', function(e) {
        const remember = document.getElementById('remember').checked;
        const email = document.getElementById('email').value;
        
        if (remember) {
            localStorage.setItem('savedEmail', email);
        } else {
            localStorage.removeItem('savedEmail');
        }
    });
    
    // Cargar email guardado
    window.addEventListener('load', function() {
        const savedEmail = localStorage.getItem('savedEmail');
        if (savedEmail) {
            document.getElementById('email').value = savedEmail;
            document.getElementById('remember').checked = true;
        }
    });
    
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