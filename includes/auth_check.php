<?php
// includes/auth_check.php
session_start();

// Verificar si existe sesión activa
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php?error=sesion_no_iniciada");
    exit();
}

// Verificar tiempo de vida de la sesión (12 horas máximo)
if (isset($_SESSION['login_time'])) {
    $tiempo_sesion = time() - $_SESSION['login_time'];
    $tiempo_maximo = 12 * 3600; // 12 horas en segundos
    
    if ($tiempo_sesion >= $tiempo_maximo) {
        // Sesión expirada por tiempo máximo
        session_unset();
        session_destroy();
        header("Location: login.php?error=sesion_expirada_tiempo");
        exit();
    }
}

// Verificar inactividad (también 12 horas)
if (isset($_SESSION['last_activity'])) {
    $tiempo_inactividad = time() - $_SESSION['last_activity'];
    $tiempo_max_inactividad = 12 * 3600; // 12 horas de inactividad
    
    if ($tiempo_inactividad >= $tiempo_max_inactividad) {
        // Sesión expirada por inactividad
        session_unset();
        session_destroy();
        header("Location: login.php?error=sesion_expirada_inactividad");
        exit();
    }
}

// Actualizar última actividad (cada vez que se accede a una página)
$_SESSION['last_activity'] = time();

// Verificar rol si es necesario
if (isset($required_rol) && $_SESSION['user_rol'] != $required_rol) {
    header("Location: dashboard.php?error=acceso_denegado");
    exit();
}
?>