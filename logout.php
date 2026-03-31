<?php
// logout.php
session_start();

// Limpiar todas las variables de sesión
$_SESSION = array();

// Eliminar la cookie de sesión si existe
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time()-3600, '/');
}

// Destruir la sesión
session_destroy();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cerrando Sesión - Gym System</title>
</head>
<body>
    <script>
    // Limpiar sessionStorage al cerrar sesión (eliminar todas las banderas)
    const keys = Object.keys(sessionStorage);
    keys.forEach(key => {
        if (key.startsWith('welcomeAlertShown_')) {
            sessionStorage.removeItem(key);
        }
    });
    
    // Redirigir directamente al login
    window.location.href = 'login.php';
    </script>
</body>
</html>