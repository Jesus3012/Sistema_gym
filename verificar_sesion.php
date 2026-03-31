<?php
// verificar_sesion.php
session_start();

$response = ['valid' => false, 'message' => ''];

if (isset($_SESSION['user_id']) && isset($_SESSION['login_time'])) {
    $tiempo_sesion = time() - $_SESSION['login_time'];
    $tiempo_maximo = 12 * 3600;
    
    if ($tiempo_sesion < $tiempo_maximo) {
        if (isset($_SESSION['last_activity'])) {
            $tiempo_inactividad = time() - $_SESSION['last_activity'];
            if ($tiempo_inactividad < $tiempo_maximo) {
                $response['valid'] = true;
                $response['remaining_time'] = $tiempo_maximo - $tiempo_sesion;
                $response['remaining_inactivity'] = $tiempo_maximo - $tiempo_inactividad;
            } else {
                $response['message'] = 'Sesión expirada por inactividad';
            }
        }
    } else {
        $response['message'] = 'Sesión expirada por tiempo máximo';
    }
} else {
    $response['message'] = 'No hay sesión activa';
}

header('Content-Type: application/json');
echo json_encode($response);
?>