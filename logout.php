<?php
// Archivo: logout.php

declare(strict_types=1);

require_once __DIR__ . '/includes/session_security.php';
secure_session_start();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        (bool) $params['secure'],
        (bool) $params['httponly']
    );
}

session_destroy();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cerrando sesión</title>
</head>
<body>
<script>
(() => {
    const keys = Object.keys(sessionStorage);
    keys.forEach(key => {
        if (key.startsWith('welcomeAlertShown_')) {
            sessionStorage.removeItem(key);
        }
    });

    window.location.replace('login.php');
})();
</script>
<noscript>
    <meta http-equiv="refresh" content="0;url=login.php">
</noscript>
</body>
</html>
