<?php
// Archivo para ejecucion automatica (cron job)
// Ejecutar este archivo 1 vez al dia a las 8:00 AM

date_default_timezone_set('America/Mexico_City');

require_once 'config/database.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';
require_once 'PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$database = new Database();
$conn = $database->getConnection();

// Copiar las funciones necesarias (enviarNotificacionVencimiento y procesarNotificacionesVencimiento)
// ... (incluir las mismas funciones que en notificaciones.php)

// Ejecutar el proceso
$resultados = procesarNotificacionesVencimiento($conn);

// Registrar en log
$log = "[" . date('Y-m-d H:i:s') . "] ";
$log .= "3 dias: {$resultados['enviados_3_dias']}, ";
$log .= "Vencidos: {$resultados['enviados_vencidos']}, ";
$log .= "Errores: {$resultados['errores']}\n";

file_put_contents('logs/vencimientos.log', $log, FILE_APPEND);

echo "Notificaciones procesadas: " . json_encode($resultados);
?>