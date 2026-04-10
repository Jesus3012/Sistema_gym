<?php
date_default_timezone_set('America/Mexico_City');

session_start();
require_once 'config/database.php';
require_once 'PHPMailer/PHPMailer.php';
require_once 'PHPMailer/SMTP.php';
require_once 'PHPMailer/Exception.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

$database = new Database();
$conn = $database->getConnection();

if (!$conn) {
    die("Error: No se pudo establecer la conexion a la base de datos");
}

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['user_name'];
$usuario_rol = $_SESSION['user_rol'];

// Verificar permisos
if (!in_array($usuario_rol, ['admin', 'recepcionista'])) {
    header('Location: dashboard.php');
    exit;
}

// Obtener numero de pagina para paginacion
$pagina_manual = isset($_GET['pagina_manual']) ? (int)$_GET['pagina_manual'] : 1;
$pagina_automatica = isset($_GET['pagina_automatica']) ? (int)$_GET['pagina_automatica'] : 1;
$registros_por_pagina = 10;
$offset_manual = ($pagina_manual - 1) * $registros_por_pagina;
$offset_automatica = ($pagina_automatica - 1) * $registros_por_pagina;

// Obtener total de registros para paginacion
$total_manual = $conn->query("SELECT COUNT(*) as total FROM notificaciones")->fetch_assoc()['total'];
$total_paginas_manual = ceil($total_manual / $registros_por_pagina);

$total_automatica = $conn->query("SELECT COUNT(*) as total FROM notificaciones_vencimiento_historial")->fetch_assoc()['total'];
$total_paginas_automatica = ceil($total_automatica / $registros_por_pagina);

// Funcion para enviar correo con PHPMailer
function enviarCorreo($email, $nombre, $titulo, $mensaje, $tipo) {
    if (empty($email)) {
        return false;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jesusgabrielmtz78@gmail.com';
        $mail->Password = 'iwdf uyqu erzq wvbm';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        $mail->setFrom('jesusgabrielmtz78@gmail.com', 'Gimnasio System');
        $mail->addAddress($email, $nombre);
        $mail->isHTML(true);
        
        $asunto = "Notificacion Gimnasio - " . $titulo;
        $mail->Subject = $asunto;
        
        $mensaje_limpio = str_replace(array('\r\n', '\r', '\n', "\r\n", "\r", "\n"), "\n", $mensaje);
        $mensaje_limpio = str_replace('\\r\\n', "\n", $mensaje_limpio);
        $mensaje_limpio = str_replace('\\n', "\n", $mensaje_limpio);
        $mensaje_html = nl2br(trim($mensaje_limpio));
        
        $color = '#3b82f6';
        if ($tipo == 'aviso') $color = '#f59e0b';
        if ($tipo == 'alerta') $color = '#ef4444';
        if ($tipo == 'promocion') $color = '#10b981';
        
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; margin: 0; padding: 0; background-color: #f4f6f9; }
                .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                .header { background: ' . $color . '; padding: 20px; text-align: center; color: white; }
                .header h2 { margin: 0; font-size: 20px; }
                .content { padding: 25px; }
                .mensaje { color: #333; line-height: 1.6; margin: 20px 0; }
                .mensaje p { margin-bottom: 12px; }
                .footer { background: #f8fafc; padding: 12px; text-align: center; font-size: 11px; color: #64748b; border-top: 1px solid #e2e8f0; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Notificacion del Gimnasio</h2>
                </div>
                <div class="content">
                    <h3>Hola ' . htmlspecialchars($nombre) . ',</h3>
                    <div class="mensaje">
                        ' . $mensaje_html . '
                    </div>
                    <hr>
                    <p style="color: #64748b; font-size: 12px;">Este es un mensaje automatico del sistema de gestion del gimnasio.</p>
                </div>
                <div class="footer">
                    <p> ' . date('Y') . ' Sistema de Gestion de Gimnasio</p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Body = $html;
        $mail->AltBody = strip_tags(str_replace('<br>', "\n", $mensaje_html));
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Error al enviar correo a $email: " . $mail->ErrorInfo);
        return false;
    }
}

// Procesar envio de notificacion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'enviar_notificacion') {
        $titulo = $conn->real_escape_string($_POST['titulo']);
        $mensaje_raw = $_POST['mensaje'];
        $mensaje_limpio = str_replace(array('\r\n', '\r', '\n', "\r\n", "\r", "\n", '\\r\\n', '\\n'), "\n", $mensaje_raw);
        $mensaje = $conn->real_escape_string($mensaje_limpio);
        $tipo = $conn->real_escape_string($_POST['tipo']);
        $destinatarios = $conn->real_escape_string($_POST['destinatarios']);
        $fecha_envio = date('Y-m-d H:i:s');
        
        $query = "INSERT INTO notificaciones (titulo, mensaje, tipo, destinatarios, fecha_envio, enviado_por, estado) 
                  VALUES ('$titulo', '$mensaje', '$tipo', '$destinatarios', '$fecha_envio', $usuario_id, 'enviado')";
        
        if ($conn->query($query)) {
            $notificacion_id = $conn->insert_id;
            $destinatarios_lista = array();
            
            switch($destinatarios) {
                case 'todos_clientes_activos':
                    $query_dest = "SELECT id, nombre, apellido, email FROM clientes WHERE estado = 'activo' AND email IS NOT NULL AND email != ''";
                    $result = $conn->query($query_dest);
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $destinatarios_lista[] = array(
                                'email' => $row['email'],
                                'nombre' => $row['nombre'] . ' ' . $row['apellido'],
                                'tipo' => 'cliente'
                            );
                        }
                    }
                    break;
                    
                case 'clientes_membresia_activa':
                    $query_dest = "SELECT DISTINCT c.id, c.nombre, c.apellido, c.email 
                                  FROM clientes c 
                                  INNER JOIN inscripciones i ON c.id = i.cliente_id 
                                  WHERE c.estado = 'activo' 
                                  AND i.estado = 'activa' 
                                  AND i.fecha_fin >= CURDATE()
                                  AND c.email IS NOT NULL AND c.email != ''";
                    $result = $conn->query($query_dest);
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $destinatarios_lista[] = array(
                                'email' => $row['email'],
                                'nombre' => $row['nombre'] . ' ' . $row['apellido'],
                                'tipo' => 'cliente'
                            );
                        }
                    }
                    break;
                    
                case 'todos_usuarios':
                    $query_dest = "SELECT id, nombre, email FROM usuarios WHERE estado = 'activo' AND email IS NOT NULL AND email != ''";
                    $result = $conn->query($query_dest);
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $destinatarios_lista[] = array(
                                'email' => $row['email'],
                                'nombre' => $row['nombre'],
                                'tipo' => 'usuario'
                            );
                        }
                    }
                    break;
                    
                case 'todos_membresia_usuarios':
                    $query_clientes = "SELECT DISTINCT c.id, c.nombre, c.apellido, c.email 
                                  FROM clientes c 
                                  INNER JOIN inscripciones i ON c.id = i.cliente_id 
                                  WHERE c.estado = 'activo' 
                                  AND i.estado = 'activa' 
                                  AND i.fecha_fin >= CURDATE()
                                  AND c.email IS NOT NULL AND c.email != ''";
                    $result = $conn->query($query_clientes);
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $destinatarios_lista[] = array(
                                'email' => $row['email'],
                                'nombre' => $row['nombre'] . ' ' . $row['apellido'],
                                'tipo' => 'cliente'
                            );
                        }
                    }
                    $query_usuarios = "SELECT id, nombre, email FROM usuarios WHERE estado = 'activo' AND email IS NOT NULL AND email != ''";
                    $result = $conn->query($query_usuarios);
                    if ($result && $result->num_rows > 0) {
                        while($row = $result->fetch_assoc()) {
                            $destinatarios_lista[] = array(
                                'email' => $row['email'],
                                'nombre' => $row['nombre'],
                                'tipo' => 'usuario'
                            );
                        }
                    }
                    break;
            }
            
            $enviados = 0;
            $fallidos = 0;
            $errores = array();
            
            foreach($destinatarios_lista as $destinatario) {
                $envio_exitoso = enviarCorreo(
                    $destinatario['email'],
                    $destinatario['nombre'],
                    $titulo,
                    $mensaje,
                    $tipo
                );
                
                if ($envio_exitoso) {
                    $enviados++;
                    $insert_detalle = "INSERT INTO notificaciones_enviadas (notificacion_id, destinatario_email, destinatario_nombre, tipo_destinatario, fecha_envio) 
                                       VALUES ($notificacion_id, '{$destinatario['email']}', '{$destinatario['nombre']}', '{$destinatario['tipo']}', '$fecha_envio')";
                    $conn->query($insert_detalle);
                } else {
                    $fallidos++;
                    $errores[] = $destinatario['email'];
                }
            }
            
            echo json_encode(array(
                'success' => true, 
                'enviados' => $enviados, 
                'fallidos' => $fallidos, 
                'total' => count($destinatarios_lista),
                'errores' => $errores
            ));
            exit;
        } else {
            echo json_encode(array('success' => false, 'error' => 'Error al guardar la notificacion: ' . $conn->error));
            exit;
        }
    }
    
    // ========== BUSQUEDA EN TIEMPO REAL - NOTIFICACIONES MANUALES ==========
    if ($_POST['action'] === 'buscar_manuales') {
        $search = $conn->real_escape_string($_POST['search']);
        $page = (int)$_POST['page'];
        $offset = ($page - 1) * $registros_por_pagina;
        
        $where = "";
        if (!empty($search)) {
            $where = " WHERE n.titulo LIKE '%$search%' OR n.mensaje LIKE '%$search%' OR u.nombre LIKE '%$search%'";
        }
        
        $count_query = "SELECT COUNT(*) as total FROM notificaciones n LEFT JOIN usuarios u ON n.enviado_por = u.id" . $where;
        $total = $conn->query($count_query)->fetch_assoc()['total'];
        $total_paginas = ceil($total / $registros_por_pagina);
        
        $query = "SELECT n.*, u.nombre as usuario_envio, 
                    (SELECT COUNT(*) FROM notificaciones_enviadas WHERE notificacion_id = n.id) as total_enviados
                    FROM notificaciones n 
                    LEFT JOIN usuarios u ON n.enviado_por = u.id 
                    $where
                    ORDER BY n.fecha_envio DESC 
                    LIMIT $registros_por_pagina OFFSET $offset";
        $result = $conn->query($query);
        
        $html = '';
        if ($result && $result->num_rows > 0) {
            while($notif = $result->fetch_assoc()) {
                $tipo_clase = '';
                $tipo_texto = '';
                switch($notif['tipo']) {
                    case 'info': $tipo_clase = 'info'; $tipo_texto = 'Informativo'; break;
                    case 'aviso': $tipo_clase = 'aviso'; $tipo_texto = 'Aviso'; break;
                    case 'alerta': $tipo_clase = 'alerta'; $tipo_texto = 'Alerta'; break;
                    case 'promocion': $tipo_clase = 'promocion'; $tipo_texto = 'Promocion'; break;
                }
                
                $destinatarios_texto = array(
                    'todos_clientes_activos' => 'Todos los clientes activos',
                    'clientes_membresia_activa' => 'Clientes con membresia activa',
                    'todos_usuarios' => 'Todos los usuarios',
                    'todos_membresia_usuarios' => 'Clientes membresia activa + Usuarios activos'
                );
                $destinatario_texto = isset($destinatarios_texto[$notif['destinatarios']]) ? $destinatarios_texto[$notif['destinatarios']] : $notif['destinatarios'];
                
                $html .= '
                <div class="notificacion-item ' . $tipo_clase . '">
                    <div class="titulo">
                        ' . htmlspecialchars($notif['titulo']) . '
                        <span class="badge-custom badge-' . $tipo_clase . ' float-right">' . $tipo_texto . '</span>
                    </div>
                    <div class="mensaje">' . nl2br(htmlspecialchars($notif['mensaje'])) . '</div>
                    <div class="meta">
                        <span><i class="fas fa-calendar"></i> ' . date('d/m/Y h:i A', strtotime($notif['fecha_envio'])) . '</span>
                        <span><i class="fas fa-user"></i> Enviado por: ' . htmlspecialchars($notif['usuario_envio']) . '</span>
                        <span><i class="fas fa-users"></i> ' . $destinatario_texto . '</span>
                        <span><i class="fas fa-envelope"></i> Enviados: ' . $notif['total_enviados'] . ' correos</span>
                    </div>
                </div>';
            }
        } else {
            $html = '<div class="text-center text-muted py-5"><i class="fas fa-envelope-open fa-3x mb-3"></i><p>No hay notificaciones que coincidan con la busqueda</p></div>';
        }
        
        echo json_encode(array(
            'html' => $html,
            'total' => $total,
            'total_paginas' => $total_paginas,
            'pagina_actual' => $page
        ));
        exit;
    }
    
    // ========== BUSQUEDA EN TIEMPO REAL - NOTIFICACIONES AUTOMATICAS ==========
    if ($_POST['action'] === 'buscar_automaticas') {
        $search = $conn->real_escape_string($_POST['search']);
        $page = (int)$_POST['page'];
        $offset = ($page - 1) * $registros_por_pagina;
        
        $where = "";
        if (!empty($search)) {
            $where = " WHERE cliente_nombre LIKE '%$search%' OR cliente_email LIKE '%$search%' OR plan_nombre LIKE '%$search%' OR tipo_notificacion LIKE '%$search%'";
        }
        
        $count_query = "SELECT COUNT(*) as total FROM notificaciones_vencimiento_historial" . $where;
        $total = $conn->query($count_query)->fetch_assoc()['total'];
        $total_paginas = ceil($total / $registros_por_pagina);
        
        $query = "SELECT * FROM notificaciones_vencimiento_historial 
                    $where
                    ORDER BY fecha_envio DESC 
                    LIMIT $registros_por_pagina OFFSET $offset";
        $result = $conn->query($query);
        
        $html = '';
        if ($result && $result->num_rows > 0) {
            while($notif = $result->fetch_assoc()) {
                $tipo_clase = $notif['tipo_notificacion'] == '3_dias' ? 'info' : 'danger';
                $tipo_texto = $notif['tipo_notificacion'] == '3_dias' ? '3 dias antes' : 'Dia del vencimiento';
                $estado_clase = $notif['estado'] == 'enviado' ? 'success' : 'danger';
                $estado_texto = $notif['estado'] == 'enviado' ? 'Enviado' : 'Fallido';
                
                $html .= '
                <div class="notificacion-item ' . $tipo_clase . '">
                    <div class="titulo">
                        <i class="fas fa-bell"></i> Notificacion de Vencimiento - ' . $tipo_texto . '
                        <span class="badge-custom badge-' . $estado_clase . ' float-right">' . $estado_texto . '</span>
                    </div>
                    <div class="mensaje">
                        <strong>Cliente:</strong> ' . htmlspecialchars($notif['cliente_nombre']) . '<br>
                        <strong>Email:</strong> ' . htmlspecialchars($notif['cliente_email']) . '<br>
                        <strong>Plan:</strong> ' . htmlspecialchars($notif['plan_nombre']) . '<br>
                        <strong>Fecha vencimiento:</strong> ' . date('d/m/Y', strtotime($notif['fecha_vencimiento'])) . '
                    </div>
                    <div class="meta">
                        <span><i class="fas fa-calendar"></i> Enviado: ' . date('d/m/Y h:i A', strtotime($notif['fecha_envio'])) . '</span>
                        ' . ($notif['dias_restantes'] > 0 ? '<span><i class="fas fa-hourglass-half"></i> Dias restantes: ' . $notif['dias_restantes'] . '</span>' : '') . '
                    </div>
                </div>';
            }
        } else {
            $html = '<div class="text-center text-muted py-5"><i class="fas fa-bell-slash fa-3x mb-3"></i><p>No hay notificaciones que coincidan con la busqueda</p></div>';
        }
        
        echo json_encode(array(
            'html' => $html,
            'total' => $total,
            'total_paginas' => $total_paginas,
            'pagina_actual' => $page
        ));
        exit;
    }
}

// Funcion para enviar notificacion de vencimiento
function enviarNotificacionVencimiento($email, $nombre, $dias_restantes, $fecha_vencimiento, $plan_nombre) {
    if (empty($email)) {
        return false;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = 'jesusgabrielmtz78@gmail.com';
        $mail->Password = 'iwdf uyqu erzq wvbm';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;
        
        $mail->SMTPOptions = array(
            'ssl' => array(
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            )
        );
        
        $mail->setFrom('jesusgabrielmtz78@gmail.com', 'Ego Gym');
        $mail->addAddress($email, $nombre);
        $mail->isHTML(true);
        
        if ($dias_restantes > 0) {
            $asunto = "Recordatorio: Tu membresia esta por vencer";
            $mensaje = "
            <div style='text-align: center;'>
                <h2 style='color: #dc3545;'>Tu membresia esta por vencer</h2>
                <p>Hola <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
                <p>Te recordamos que tu membresia <strong>" . htmlspecialchars($plan_nombre) . "</strong> vencera en <strong style='color: #dc3545; font-size: 18px;'>$dias_restantes dias</strong>.</p>
                <p>Fecha de vencimiento: <strong>" . date('d/m/Y', strtotime($fecha_vencimiento)) . "</strong></p>
                <p>Te invitamos a renovar tu membresia para seguir disfrutando de nuestros servicios.</p>
                <br>
                <p>No dejes que tu membresia expire!</p>
                <p style='margin-top: 20px;'>Atentamente,<br><strong>Ego Gym</strong></p>
            </div>";
        } else {
            $asunto = "Tu membresia ha vencido";
            $mensaje = "
            <div style='text-align: center;'>
                <h2 style='color: #dc3545;'>Tu membresia ha vencido</h2>
                <p>Hola <strong>" . htmlspecialchars($nombre) . "</strong>,</p>
                <p>Tu membresia <strong>" . htmlspecialchars($plan_nombre) . "</strong> ha vencido hoy.</p>
                <p>Fecha de vencimiento: <strong>" . date('d/m/Y', strtotime($fecha_vencimiento)) . "</strong></p>
                <p>Para seguir accediendo al gimnasio, por favor renueva tu membresia lo antes posible.</p>
                <br>
                <p>Renueva hoy y continua entrenando!</p>
                <p style='margin-top: 20px;'>Atentamente,<br><strong>Ego Gym</strong></p>
            </div>";
        }
        
        $color = '#dc3545';
        $html = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <style>
                body { font-family: Arial, sans-serif; background-color: #f4f6f9; }
                .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
                .header { background: ' . $color . '; padding: 20px; text-align: center; color: white; }
                .header h2 { margin: 0; }
                .content { padding: 25px; }
                .footer { background: #f8fafc; padding: 12px; text-align: center; font-size: 11px; color: #64748b; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h2>Ego Gym - Notificacion</h2>
                </div>
                <div class="content">
                    ' . $mensaje . '
                </div>
                <div class="footer">
                    <p> ' . date('Y') . ' Ego Gym - Todos los derechos reservados</p>
                </div>
            </div>
        </body>
        </html>';
        
        $mail->Subject = $asunto;
        $mail->Body = $html;
        $mail->AltBody = strip_tags($mensaje);
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Error al enviar correo de vencimiento a $email: " . $mail->ErrorInfo);
        return false;
    }
}

// Funcion para procesar notificaciones de vencimiento
function procesarNotificacionesVencimiento($conn) {
    $fecha_actual = date('Y-m-d');
    $resultados = array(
        'enviados_3_dias' => 0,
        'enviados_vencidos' => 0,
        'errores' => 0
    );
    
    $query = "SELECT i.*, c.nombre, c.apellido, c.email, p.nombre as plan_nombre 
              FROM inscripciones i
              INNER JOIN clientes c ON i.cliente_id = c.id
              INNER JOIN planes p ON i.plan_id = p.id
              WHERE i.estado = 'activa' 
              AND i.fecha_fin >= DATE_SUB(CURDATE(), INTERVAL 1 DAY)
              AND p.nombre != 'Visita'
              AND c.email IS NOT NULL AND c.email != ''
              AND c.estado = 'activo'";
    
    $result = $conn->query($query);
    
    if ($result && $result->num_rows > 0) {
        while($row = $result->fetch_assoc()) {
            $fecha_fin = $row['fecha_fin'];
            $dias_restantes = (strtotime($fecha_fin) - strtotime($fecha_actual)) / (60 * 60 * 24);
            $dias_restantes = round($dias_restantes);
            
            $check_query = "SELECT tipo_notificacion FROM notificaciones_vencimiento_historial 
                           WHERE inscripcion_id = {$row['id']} 
                           AND tipo_notificacion IN ('3_dias', 'vencido')";
            $check_result = $conn->query($check_query);
            $notificaciones_enviadas = array();
            if ($check_result) {
                while($c = $check_result->fetch_assoc()) {
                    $notificaciones_enviadas[] = $c['tipo_notificacion'];
                }
            }
            
            if ($dias_restantes == 3 && !in_array('3_dias', $notificaciones_enviadas)) {
                $envio = enviarNotificacionVencimiento($row['email'], $row['nombre'] . ' ' . $row['apellido'], 3, $fecha_fin, $row['plan_nombre']);
                $estado = $envio ? 'enviado' : 'fallido';
                $insert = "INSERT INTO notificaciones_vencimiento_historial 
                          (inscripcion_id, cliente_id, cliente_nombre, cliente_email, plan_nombre, tipo_notificacion, dias_restantes, fecha_vencimiento, fecha_envio, estado) 
                          VALUES ({$row['id']}, {$row['cliente_id']}, '{$row['nombre']} {$row['apellido']}', '{$row['email']}', '{$row['plan_nombre']}', '3_dias', 3, '$fecha_fin', NOW(), '$estado')";
                $conn->query($insert);
                if ($envio) {
                    $resultados['enviados_3_dias']++;
                } else {
                    $resultados['errores']++;
                }
            }
            
            if ($dias_restantes == 0 && !in_array('vencido', $notificaciones_enviadas)) {
                $envio = enviarNotificacionVencimiento($row['email'], $row['nombre'] . ' ' . $row['apellido'], 0, $fecha_fin, $row['plan_nombre']);
                $estado = $envio ? 'enviado' : 'fallido';
                $insert = "INSERT INTO notificaciones_vencimiento_historial 
                          (inscripcion_id, cliente_id, cliente_nombre, cliente_email, plan_nombre, tipo_notificacion, dias_restantes, fecha_vencimiento, fecha_envio, estado) 
                          VALUES ({$row['id']}, {$row['cliente_id']}, '{$row['nombre']} {$row['apellido']}', '{$row['email']}', '{$row['plan_nombre']}', 'vencido', 0, '$fecha_fin', NOW(), '$estado')";
                $conn->query($insert);
                if ($envio) {
                    $resultados['enviados_vencidos']++;
                } else {
                    $resultados['errores']++;
                }
            }
        }
    }
    
    return $resultados;
}

// Procesar notificaciones automaticas
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'procesar_vencimientos') {
        $resultados = procesarNotificacionesVencimiento($conn);
        echo json_encode(array(
            'success' => true,
            'message' => 'Notificaciones procesadas',
            'detalles' => $resultados
        ));
        exit;
    }
}

// Obtener estadisticas
$stats = array();

$result = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE estado = 'activo'");
$stats['total_clientes_activos'] = ($result && $result->num_rows > 0) ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query("SELECT COUNT(DISTINCT c.id) as total 
    FROM clientes c 
    INNER JOIN inscripciones i ON c.id = i.cliente_id 
    WHERE c.estado = 'activo' AND i.estado = 'activa' AND i.fecha_fin >= CURDATE()");
$stats['clientes_con_membresia'] = ($result && $result->num_rows > 0) ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE estado = 'activo'");
$stats['total_usuarios_activos'] = ($result && $result->num_rows > 0) ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query("SELECT COUNT(*) as total FROM notificaciones");
$stats['total_notificaciones'] = ($result && $result->num_rows > 0) ? $result->fetch_assoc()['total'] : 0;

// Obtener notificaciones manuales con paginacion
$query_manual = "SELECT n.*, u.nombre as usuario_envio, 
    (SELECT COUNT(*) FROM notificaciones_enviadas WHERE notificacion_id = n.id) as total_enviados
    FROM notificaciones n 
    LEFT JOIN usuarios u ON n.enviado_por = u.id 
    ORDER BY n.fecha_envio DESC 
    LIMIT $registros_por_pagina OFFSET $offset_manual";
$result_manual = $conn->query($query_manual);
$stats['notificaciones_manuales'] = ($result_manual && $result_manual->num_rows > 0) ? $result_manual : null;

// Obtener notificaciones automaticas con paginacion
$query_automatica = "SELECT * FROM notificaciones_vencimiento_historial 
    ORDER BY fecha_envio DESC 
    LIMIT $registros_por_pagina OFFSET $offset_automatica";
$result_automatica = $conn->query($query_automatica);
$stats['notificaciones_automaticas'] = ($result_automatica && $result_automatica->num_rows > 0) ? $result_automatica : null;
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Notificaciones por Correo - Sistema Gimnasio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/css/bootstrap.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { background: #f5f7fa; font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }
        .main-content { margin-left: 280px; transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1); min-height: 100vh; padding: 20px; background: #f4f6f9; }
        body.sidebar-collapsed .main-content { margin-left: 70px; }
        @media (max-width: 768px) { .main-content { margin-left: 0 !important; padding: 80px 15px 15px 15px; } }
        .content-header { padding: 15px 0; }
        .content-header h1 { font-size: 1.8rem; font-weight: 600; color: #1e293b; }
        .stats-card { text-align: center; padding: 25px 15px; background: white; border-radius: 20px; box-shadow: 0 4px 12px rgba(0,0,0,0.08); transition: all 0.3s ease; margin-bottom: 20px; position: relative; overflow: hidden; }
        .stats-card:hover { transform: translateY(-5px); box-shadow: 0 8px 25px rgba(0,0,0,0.15); }
        .stats-card::before { content: ''; position: absolute; top: -50%; right: -50%; width: 200%; height: 200%; background: radial-gradient(circle, rgba(255,255,255,0.1) 0%, rgba(255,255,255,0) 70%); opacity: 0; transition: opacity 0.3s ease; }
        .stats-card:hover::before { opacity: 1; }
        .stats-icon { width: 90px; height: 90px; margin: 0 auto 20px; display: flex; align-items: center; justify-content: center; border-radius: 50%; font-size: 40px; color: white; box-shadow: 0 4px 10px rgba(0,0,0,0.2); }
        .stats-icon.bg-info { background: #17a2b8; }
        .stats-icon.bg-success { background: #28a745; }
        .stats-icon.bg-warning { background: #ffc107; }
        .stats-icon.bg-danger { background: #dc3545; }
        .stats-card.info-bg { background: linear-gradient(135deg, #17a2b8 0%, #138496 100%); }
        .stats-card.success-bg { background: linear-gradient(135deg, #28a745 0%, #1e7e34 100%); }
        .stats-card.warning-bg { background: linear-gradient(135deg, #ffc107 0%, #e0a800 100%); }
        .stats-card.danger-bg { background: linear-gradient(135deg, #dc3545 0%, #bd2130 100%); }
        .stats-card.info-bg .stats-number, .stats-card.info-bg .stats-label,
        .stats-card.success-bg .stats-number, .stats-card.success-bg .stats-label,
        .stats-card.warning-bg .stats-number, .stats-card.warning-bg .stats-label,
        .stats-card.danger-bg .stats-number, .stats-card.danger-bg .stats-label { color: white; }
        .stats-number { font-size: 2.5rem; font-weight: bold; margin-bottom: 8px; }
        .stats-label { font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500; }
        .card { border-radius: 0.25rem; box-shadow: 0 0 1px rgba(0,0,0,.125), 0 1px 3px rgba(0,0,0,.2); margin-bottom: 20px; }
        .card-header { padding: 0.75rem 1.25rem; border-bottom: 1px solid rgba(0,0,0,0.125); }
        .card-header h3 { font-size: 1.1rem; font-weight: 600; margin: 0; color: white; }
        .card-header i { margin-right: 8px; }
        .card-body { padding: 1.25rem; }
        .text-right { text-align: right; }
        .card-header.primary { background-color: #007bff; }
        .card-header.warning { background-color: #ffc107; }
        .card-header.dark { background-color: #343a40; }
        .notificacion-item { border-left: 3px solid; margin-bottom: 15px; padding: 15px; background: white; border-radius: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
        .notificacion-item.info { border-left-color: #17a2b8; }
        .notificacion-item.aviso { border-left-color: #ffc107; }
        .notificacion-item.alerta { border-left-color: #dc3545; }
        .notificacion-item.promocion { border-left-color: #28a745; }
        .notificacion-item.danger { border-left-color: #dc3545; }
        .notificacion-item .titulo { font-weight: 600; font-size: 1rem; margin-bottom: 5px; }
        .notificacion-item .mensaje { color: #475569; font-size: 0.85rem; margin-bottom: 8px; }
        .notificacion-item .meta { font-size: 0.7rem; color: #94a3b8; display: flex; gap: 15px; flex-wrap: wrap; }
        .badge-custom { padding: 3px 10px; border-radius: 15px; font-size: 0.7rem; font-weight: 600; }
        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-aviso { background: #fed7aa; color: #92400e; }
        .badge-alerta { background: #fee2e2; color: #991b1b; }
        .badge-promocion { background: #d1fae5; color: #065f46; }
        .badge-success { background: #d1fae5; color: #065f46; }
        .badge-danger { background: #fee2e2; color: #991b1b; }
        textarea { resize: vertical; min-height: 100px; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { font-weight: 600; margin-bottom: 0.5rem; display: block; color: #1e293b; }
        .form-control, .form-select { border-radius: 6px; border: 1px solid #e2e8f0; padding: 8px 12px; transition: all 0.2s; }
        .form-control:focus, .form-select:focus { border-color: #007bff; box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.1); outline: none; }
        .btn-primary { background: #007bff; border: none; border-radius: 6px; padding: 10px 24px; font-weight: 600; color: white; cursor: pointer; transition: all 0.2s; }
        .btn-primary:hover { background: #0056b3; }
        .btn-danger { background: #dc3545; border: none; border-radius: 6px; padding: 10px 24px; font-weight: 600; color: white; cursor: pointer; transition: all 0.2s; }
        .btn-danger:hover { background: #c82333; transform: translateY(-2px); }
        .destinatario-card { background: #f8fafc; border-radius: 8px; padding: 12px 15px; margin-bottom: 10px; cursor: pointer; transition: all 0.2s; border: 1px solid #e2e8f0; }
        .destinatario-card:hover { background: #f1f5f9; transform: translateX(3px); }
        .destinatario-card.selected { border-color: #007bff; background: #e8f0fe; }
        .destinatario-card .nombre { font-weight: 600; color: #1e293b; font-size: 0.9rem; }
        .destinatario-card .email { font-size: 0.75rem; color: #64748b; }
        .nav-tabs .nav-link { color: #1e293b; font-weight: 500; cursor: pointer; }
        .nav-tabs .nav-link.active { color: #007bff; font-weight: 600; }
        .pagination .page-link { color: #007bff; cursor: pointer; }
        .pagination .active .page-link { background-color: #007bff; border-color: #007bff; color: white; }
        .search-box { margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #e9ecef; }
        .search-box .input-group { max-width: 400px; }
        .loading-spinner { display: inline-block; width: 20px; height: 20px; border: 2px solid #f3f3f3; border-top: 2px solid #007bff; border-radius: 50%; animation: spin 1s linear infinite; margin-left: 10px; }
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        .result-count { font-size: 0.85rem; color: #6c757d; margin-bottom: 15px; }
        .clear-search-btn { margin-left: 10px; padding: 6px 12px; background: #dc3545; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 12px; }
        .clear-search-btn:hover { background: #c82333; }
    </style>
</head>
<body class="hold-transition sidebar-mini">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">Notificaciones por Correo</h1>
                    </div>
                </div>
            </div>
        </div>

        <!-- Estadisticas -->
        <div class="row">
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stats-card info-bg">
                    <div class="stats-icon bg-info"><i class="fas fa-users"></i></div>
                    <div class="stats-number"><?php echo $stats['total_clientes_activos']; ?></div>
                    <div class="stats-label">Clientes Activos</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stats-card success-bg">
                    <div class="stats-icon bg-success"><i class="fas fa-id-card"></i></div>
                    <div class="stats-number"><?php echo $stats['clientes_con_membresia']; ?></div>
                    <div class="stats-label">Con Membresia Activa</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stats-card warning-bg">
                    <div class="stats-icon bg-warning"><i class="fas fa-user-shield"></i></div>
                    <div class="stats-number"><?php echo $stats['total_usuarios_activos']; ?></div>
                    <div class="stats-label">Usuarios Activos</div>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stats-card danger-bg">
                    <div class="stats-icon bg-danger"><i class="fas fa-envelope"></i></div>
                    <div class="stats-number"><?php echo $stats['total_notificaciones']; ?></div>
                    <div class="stats-label">Notificaciones Enviadas</div>
                </div>
            </div>
        </div>

        <!-- Formulario de envio -->
        <div class="card">
            <div class="card-header primary">
                <h3><i class="fas fa-paper-plane"></i> Nueva Notificacion por Correo</h3>
            </div>
            <div class="card-body">
                <form id="formNotificacion">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="form-group">
                                <label>Titulo</label>
                                <input type="text" class="form-control" name="titulo" id="titulo" required placeholder="Ej: Horario especial por festividad">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Tipo</label>
                                <select class="form-control" name="tipo" id="tipo" required>
                                    <option value="info">Informativo</option>
                                    <option value="aviso">Aviso</option>
                                    <option value="alerta">Alerta</option>
                                    <option value="promocion">Promocion</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-12">
                            <div class="form-group">
                                <label>Mensaje</label>
                                <textarea class="form-control" name="mensaje" id="mensaje" required placeholder="Escribe el mensaje que deseas enviar por correo a los destinatarios..."></textarea>
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label>Selecciona los Destinatarios</label>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="destinatario-card" data-destinatario="todos_clientes_activos">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div><div class="nombre"><i class="fas fa-users"></i> Todos los clientes activos</div><div class="email">Clientes con estado activo</div></div>
                                                <span class="badge-custom badge-info"><?php echo $stats['total_clientes_activos']; ?> clientes</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="destinatario-card" data-destinatario="clientes_membresia_activa">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div><div class="nombre"><i class="fas fa-id-card"></i> Clientes con membresia activa</div><div class="email">Inscripcion activa y no vencida</div></div>
                                                <span class="badge-custom badge-info"><?php echo $stats['clientes_con_membresia']; ?> clientes</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="destinatario-card" data-destinatario="todos_usuarios">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div><div class="nombre"><i class="fas fa-user-shield"></i> Todos los usuarios del sistema</div><div class="email">Usuarios activos del sistema</div></div>
                                                <span class="badge-custom badge-aviso"><?php echo $stats['total_usuarios_activos']; ?> usuarios</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="destinatario-card" data-destinatario="todos_membresia_usuarios">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div><div class="nombre"><i class="fas fa-globe"></i> Clientes membresia activa + Usuarios activos</div><div class="email">Clientes con inscripcion activa y usuarios del sistema</div></div>
                                                <span class="badge-custom badge-promocion"><?php echo $stats['clientes_con_membresia'] + $stats['total_usuarios_activos']; ?> personas</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="destinatarios" id="destinatarios" required>
                            </div>
                        </div>
                    </div>
                    <div class="text-right mt-3">
                        <button type="submit" class="btn-primary"><i class="fas fa-paper-plane"></i> Enviar Notificacion por Correo</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Boton para notificaciones de vencimiento (EMERGENCIA) -->
        <div class="card">
            <div class="card-header warning">
                <h3><i class="fas fa-calendar-alt"></i> Notificaciones Automaticas de Vencimiento</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-8">
                        <p>Este sistema enviara notificaciones automaticas DIARIAMENTE a los clientes con membresia proxima a vencer:</p>
                        <ul>
                            <li><i class="fas fa-envelope"></i> <strong>3 dias antes</strong> del vencimiento</li>
                            <li><i class="fas fa-exclamation-triangle"></i> <strong>El dia del vencimiento</strong></li>
                        </ul>
                        <p class="text-muted small">Nota: Los clientes con plan "Visita" no recibiran estas notificaciones.</p>
                        <hr>
                        <p class="text-danger"><strong> BOTON DE EMERGENCIA </strong><br>
                        <small>Use este boton SOLO si el sistema automatico falla. En condiciones normales, las notificaciones se envian automaticamente cada dia.</small></p>
                    </div>
                    <div class="col-md-4 text-right">
                        <button type="button" id="btnProcesarVencimientos" class="btn-danger"><i class="fas fa-exclamation-triangle"></i> Forzar Envio</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Historial de notificaciones con tabs -->
        <div class="card">
            <div class="card-header dark">
                <h3><i class="fas fa-history"></i> Historial de Notificaciones</h3>
            </div>
            <div class="card-body">
                <ul class="nav nav-tabs mb-3" id="historialTabs" role="tablist">
                    <li class="nav-item"><a class="nav-link active" id="manual-tab" data-toggle="tab" href="#manual" role="tab"><i class="fas fa-paper-plane"></i> Notificaciones Manuales</a></li>
                    <li class="nav-item"><a class="nav-link" id="automatica-tab" data-toggle="tab" href="#automatica" role="tab"><i class="fas fa-calendar-alt"></i> Notificaciones Automaticas (Vencimiento)</a></li>
                </ul>
                
                <div class="tab-content" id="historialTabsContent">
                    <!-- Tab Notificaciones Manuales con buscador en tiempo real -->
                    <div class="tab-pane fade show active" id="manual" role="tabpanel">
                        <div class="search-box">
                            <div class="form-inline">
                                <div class="input-group">
                                    <input type="text" id="searchManualInput" class="form-control" placeholder="Buscar por titulo, mensaje o usuario..." autocomplete="off">
                                    <div class="input-group-append">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    </div>
                                </div>
                                <div id="manualLoading" class="loading-spinner" style="display: none;"></div>
                                <button type="button" id="clearManualSearch" class="clear-search-btn" style="display: none;"><i class="fas fa-times"></i> Limpiar</button>
                            </div>
                        </div>
                        <div id="manualResultCount" class="result-count"></div>
                        <div id="manualResultados">
                            <?php if ($stats['notificaciones_manuales'] && $stats['notificaciones_manuales']->num_rows > 0): ?>
                                <?php while($notif = $stats['notificaciones_manuales']->fetch_assoc()): 
                                    $tipo_clase = '';
                                    $tipo_texto = '';
                                    switch($notif['tipo']) {
                                        case 'info': $tipo_clase = 'info'; $tipo_texto = 'Informativo'; break;
                                        case 'aviso': $tipo_clase = 'aviso'; $tipo_texto = 'Aviso'; break;
                                        case 'alerta': $tipo_clase = 'alerta'; $tipo_texto = 'Alerta'; break;
                                        case 'promocion': $tipo_clase = 'promocion'; $tipo_texto = 'Promocion'; break;
                                    }
                                    $destinatarios_texto = array(
                                        'todos_clientes_activos' => 'Todos los clientes activos',
                                        'clientes_membresia_activa' => 'Clientes con membresia activa',
                                        'todos_usuarios' => 'Todos los usuarios',
                                        'todos_membresia_usuarios' => 'Clientes membresia activa + Usuarios activos'
                                    );
                                    $destinatario_texto = isset($destinatarios_texto[$notif['destinatarios']]) ? $destinatarios_texto[$notif['destinatarios']] : $notif['destinatarios'];
                                ?>
                                    <div class="notificacion-item <?php echo $tipo_clase; ?>">
                                        <div class="titulo"><?php echo htmlspecialchars($notif['titulo']); ?><span class="badge-custom badge-<?php echo $tipo_clase; ?> float-right"><?php echo $tipo_texto; ?></span></div>
                                        <div class="mensaje"><?php echo nl2br(htmlspecialchars($notif['mensaje'])); ?></div>
                                        <div class="meta">
                                            <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y h:i A', strtotime($notif['fecha_envio'])); ?></span>
                                            <span><i class="fas fa-user"></i> Enviado por: <?php echo htmlspecialchars($notif['usuario_envio']); ?></span>
                                            <span><i class="fas fa-users"></i> <?php echo $destinatario_texto; ?></span>
                                            <span><i class="fas fa-envelope"></i> Enviados: <?php echo $notif['total_enviados']; ?> correos</span>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                                <div class="text-center text-muted py-5"><i class="fas fa-envelope-open fa-3x mb-3"></i><p>No hay notificaciones manuales enviadas aun</p></div>
                            <?php endif; ?>
                        </div>
                        <div id="manualPagination" class="pagination-container">
                            <?php if($total_paginas_manual > 1): ?>
                            <nav><ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $pagina_manual <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?pagina_manual=<?php echo $pagina_manual-1; ?>&pagina_automatica=<?php echo $pagina_automatica; ?>#manual">Anterior</a></li>
                                <?php for($i = 1; $i <= $total_paginas_manual; $i++): ?>
                                    <li class="page-item <?php echo $pagina_manual == $i ? 'active' : ''; ?>"><a class="page-link" href="?pagina_manual=<?php echo $i; ?>&pagina_automatica=<?php echo $pagina_automatica; ?>#manual"><?php echo $i; ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $pagina_manual >= $total_paginas_manual ? 'disabled' : ''; ?>"><a class="page-link" href="?pagina_manual=<?php echo $pagina_manual+1; ?>&pagina_automatica=<?php echo $pagina_automatica; ?>#manual">Siguiente</a></li>
                            </ul></nav>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <!-- Tab Notificaciones Automaticas con buscador en tiempo real -->
                    <div class="tab-pane fade" id="automatica" role="tabpanel">
                        <div class="search-box">
                            <div class="form-inline">
                                <div class="input-group">
                                    <input type="text" id="searchAutomaticaInput" class="form-control" placeholder="Buscar por cliente, email, plan o tipo..." autocomplete="off">
                                    <div class="input-group-append">
                                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    </div>
                                </div>
                                <div id="automaticaLoading" class="loading-spinner" style="display: none;"></div>
                                <button type="button" id="clearAutomaticaSearch" class="clear-search-btn" style="display: none;"><i class="fas fa-times"></i> Limpiar</button>
                            </div>
                        </div>
                        <div id="automaticaResultCount" class="result-count"></div>
                        <div id="automaticaResultados">
                            <?php if ($stats['notificaciones_automaticas'] && $stats['notificaciones_automaticas']->num_rows > 0): ?>
                                <?php while($notif = $stats['notificaciones_automaticas']->fetch_assoc()): 
                                    $tipo_clase = $notif['tipo_notificacion'] == '3_dias' ? 'info' : 'danger';
                                    $tipo_texto = $notif['tipo_notificacion'] == '3_dias' ? '3 dias antes' : 'Dia del vencimiento';
                                    $estado_clase = $notif['estado'] == 'enviado' ? 'success' : 'danger';
                                    $estado_texto = $notif['estado'] == 'enviado' ? 'Enviado' : 'Fallido';
                                ?>
                                    <div class="notificacion-item <?php echo $tipo_clase; ?>">
                                        <div class="titulo"><i class="fas fa-bell"></i> Notificacion de Vencimiento - <?php echo $tipo_texto; ?><span class="badge-custom badge-<?php echo $estado_clase; ?> float-right"><?php echo $estado_texto; ?></span></div>
                                        <div class="mensaje">
                                            <strong>Cliente:</strong> <?php echo htmlspecialchars($notif['cliente_nombre']); ?><br>
                                            <strong>Email:</strong> <?php echo htmlspecialchars($notif['cliente_email']); ?><br>
                                            <strong>Plan:</strong> <?php echo htmlspecialchars($notif['plan_nombre']); ?><br>
                                            <strong>Fecha vencimiento:</strong> <?php echo date('d/m/Y', strtotime($notif['fecha_vencimiento'])); ?>
                                        </div>
                                        <div class="meta">
                                            <span><i class="fas fa-calendar"></i> Enviado: <?php echo date('d/m/Y h:i A', strtotime($notif['fecha_envio'])); ?></span>
                                            <?php if($notif['dias_restantes'] > 0): ?>
                                                <span><i class="fas fa-hourglass-half"></i> Dias restantes: <?php echo $notif['dias_restantes']; ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endwhile; ?>
                            <?php else: ?>
                        <?php endif; ?>
                        </div>
                        <div id="automaticaPagination" class="pagination-container">
                            <?php if($total_paginas_automatica > 1): ?>
                            <nav><ul class="pagination justify-content-center">
                                <li class="page-item <?php echo $pagina_automatica <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?pagina_manual=<?php echo $pagina_manual; ?>&pagina_automatica=<?php echo $pagina_automatica-1; ?>#automatica">Anterior</a></li>
                                <?php for($i = 1; $i <= $total_paginas_automatica; $i++): ?>
                                    <li class="page-item <?php echo $pagina_automatica == $i ? 'active' : ''; ?>"><a class="page-link" href="?pagina_manual=<?php echo $pagina_manual; ?>&pagina_automatica=<?php echo $i; ?>#automatica"><?php echo $i; ?></a></li>
                                <?php endfor; ?>
                                <li class="page-item <?php echo $pagina_automatica >= $total_paginas_automatica ? 'disabled' : ''; ?>"><a class="page-link" href="?pagina_manual=<?php echo $pagina_manual; ?>&pagina_automatica=<?php echo $pagina_automatica+1; ?>#automatica">Siguiente</a></li>
                            </ul></nav>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.11.6/dist/umd/popper.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@4.6.2/dist/js/bootstrap.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>

    <script>
        let manualSearchTimeout;
        let automaticaSearchTimeout;
        let currentManualPage = 1;
        let currentAutomaticaPage = 1;
        
        // Cargar notificaciones manuales con busqueda
        function cargarManuales(search = '', page = 1) {
            $('#manualLoading').show();
            $.ajax({
                url: 'notificaciones.php',
                method: 'POST',
                data: { action: 'buscar_manuales', search: search, page: page },
                dataType: 'json',
                success: function(response) {
                    $('#manualResultados').html(response.html);
                    $('#manualResultCount').html(response.total > 0 ? 'Mostrando ' + response.total + ' registros' : '');
                    $('#manualLoading').hide();
                    currentManualPage = response.pagina_actual;
                    
                    // Mostrar/ocultar boton limpiar
                    if (search !== '') {
                        $('#clearManualSearch').show();
                    } else {
                        $('#clearManualSearch').hide();
                    }
                    
                    // Generar paginacion
                    if (response.total_paginas > 1) {
                        let pagHtml = '<nav><ul class="pagination justify-content-center">';
                        pagHtml += '<li class="page-item ' + (response.pagina_actual <= 1 ? 'disabled' : '') + '"><a class="page-link" href="#" data-page="' + (response.pagina_actual - 1) + '">Anterior</a></li>';
                        for (let i = 1; i <= response.total_paginas; i++) {
                            pagHtml += '<li class="page-item ' + (response.pagina_actual == i ? 'active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
                        }
                        pagHtml += '<li class="page-item ' + (response.pagina_actual >= response.total_paginas ? 'disabled' : '') + '"><a class="page-link" href="#" data-page="' + (response.pagina_actual + 1) + '">Siguiente</a></li>';
                        pagHtml += '</ul></nav>';
                        $('#manualPagination').html(pagHtml);
                        
                        $('.pagination .page-link').off('click').on('click', function(e) {
                            e.preventDefault();
                            let page = $(this).data('page');
                            if (page && page !== currentManualPage) {
                                cargarManuales($('#searchManualInput').val(), page);
                            }
                        });
                    } else {
                        $('#manualPagination').html('');
                    }
                }
            });
        }
        
        // Cargar notificaciones automaticas con busqueda
        function cargarAutomaticas(search = '', page = 1) {
            $('#automaticaLoading').show();
            $.ajax({
                url: 'notificaciones.php',
                method: 'POST',
                data: { action: 'buscar_automaticas', search: search, page: page },
                dataType: 'json',
                success: function(response) {
                    $('#automaticaResultados').html(response.html);
                    $('#automaticaResultCount').html(response.total > 0 ? 'Mostrando ' + response.total + ' registros' : '');
                    $('#automaticaLoading').hide();
                    currentAutomaticaPage = response.pagina_actual;
                    
                    // Mostrar/ocultar boton limpiar
                    if (search !== '') {
                        $('#clearAutomaticaSearch').show();
                    } else {
                        $('#clearAutomaticaSearch').hide();
                    }
                    
                    // Generar paginacion
                    if (response.total_paginas > 1) {
                        let pagHtml = '<nav><ul class="pagination justify-content-center">';
                        pagHtml += '<li class="page-item ' + (response.pagina_actual <= 1 ? 'disabled' : '') + '"><a class="page-link" href="#" data-page="' + (response.pagina_actual - 1) + '">Anterior</a></li>';
                        for (let i = 1; i <= response.total_paginas; i++) {
                            pagHtml += '<li class="page-item ' + (response.pagina_actual == i ? 'active' : '') + '"><a class="page-link" href="#" data-page="' + i + '">' + i + '</a></li>';
                        }
                        pagHtml += '<li class="page-item ' + (response.pagina_actual >= response.total_paginas ? 'disabled' : '') + '"><a class="page-link" href="#" data-page="' + (response.pagina_actual + 1) + '">Siguiente</a></li>';
                        pagHtml += '</ul></nav>';
                        $('#automaticaPagination').html(pagHtml);
                        
                        $('.pagination .page-link').off('click').on('click', function(e) {
                            e.preventDefault();
                            let page = $(this).data('page');
                            if (page && page !== currentAutomaticaPage) {
                                cargarAutomaticas($('#searchAutomaticaInput').val(), page);
                            }
                        });
                    } else {
                        $('#automaticaPagination').html('');
                    }
                }
            });
        }
        
        // Buscadores en tiempo real
        $('#searchManualInput').on('input', function() {
            clearTimeout(manualSearchTimeout);
            manualSearchTimeout = setTimeout(() => {
                cargarManuales($(this).val(), 1);
            }, 500);
        });
        
        $('#searchAutomaticaInput').on('input', function() {
            clearTimeout(automaticaSearchTimeout);
            automaticaSearchTimeout = setTimeout(() => {
                cargarAutomaticas($(this).val(), 1);
            }, 500);
        });
        
        // Botones para limpiar busqueda
        $('#clearManualSearch').on('click', function() {
            $('#searchManualInput').val('');
            cargarManuales('', 1);
        });
        
        $('#clearAutomaticaSearch').on('click', function() {
            $('#searchAutomaticaInput').val('');
            cargarAutomaticas('', 1);
        });
        
        // Seleccion de destinatarios
        var destinatarioSeleccionado = null;
        $('.destinatario-card').on('click', function() {
            $('.destinatario-card').removeClass('selected');
            $(this).addClass('selected');
            destinatarioSeleccionado = $(this).data('destinatario');
            $('#destinatarios').val(destinatarioSeleccionado);
        });
        
        // Envio de formulario
        $('#formNotificacion').on('submit', function(e) {
            e.preventDefault();
            var titulo = $('#titulo').val();
            var mensaje = $('#mensaje').val();
            var destinatarios = $('#destinatarios').val();
            
            if (!titulo || !mensaje) {
                Swal.fire({ icon: 'error', title: 'Campos incompletos', text: 'Por favor completa el titulo y el mensaje' });
                return;
            }
            if (!destinatarios) {
                Swal.fire({ icon: 'error', title: 'Selecciona destinatarios', text: 'Por favor selecciona un grupo de destinatarios' });
                return;
            }
            
            Swal.fire({
                title: 'Enviar notificacion por correo?',
                html: '<p><strong>Destinatarios:</strong> ' + $('.destinatario-card.selected .nombre').text() + '</p>',
                icon: 'question', showCancelButton: true, confirmButtonText: 'Si, enviar', cancelButtonText: 'Cancelar'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({ title: 'Enviando...', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                    $.ajax({
                        url: 'notificaciones.php',
                        method: 'POST',
                        data: $(this).serialize() + '&action=enviar_notificacion',
                        dataType: 'json',
                        success: (response) => {
                            if (response.success) {
                                Swal.fire({ icon: 'success', title: 'Notificaciones enviadas', html: '<strong>' + response.enviados + '</strong> enviados, <strong>' + response.fallidos + '</strong> fallidos', confirmButtonText: 'Aceptar' }).then(() => location.reload());
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.error });
                            }
                        },
                        error: () => Swal.fire({ icon: 'error', title: 'Error', text: 'Ocurrio un error' })
                    });
                }
            });
        });
        
        // Procesar vencimientos (EMERGENCIA)
        function procesarVencimientos() {
            Swal.fire({
                title: ' BOTON DE EMERGENCIA ',
                html: '<p><strong>Este boton es SOLO para uso en caso de emergencia</strong></p>' +
                    '<p>Las notificaciones automaticas deberian enviarse diariamente sin intervencion manual.</p>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Si, forzar envio (EMERGENCIA)',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc3545'
            }).then((result) => {
                if (result.isConfirmed) {
                    Swal.fire({ title: 'Procesando...', text: 'Enviando notificaciones de vencimiento (modo emergencia)', allowOutsideClick: false, didOpen: () => Swal.showLoading() });
                    $.ajax({
                        url: 'notificaciones.php',
                        method: 'POST',
                        data: { action: 'procesar_vencimientos' },
                        dataType: 'json',
                        success: (response) => {
                            if (response.success) {
                                var mensajeHtml = '<strong>Resultado del proceso de emergencia:</strong><br><br>';
                                mensajeHtml += '<i class="fas fa-calendar-day"></i> 3 dias antes: <strong>' + response.detalles.enviados_3_dias + '</strong> notificaciones<br>';
                                mensajeHtml += '<i class="fas fa-calendar-times"></i> Dia vencimiento: <strong>' + response.detalles.enviados_vencidos + '</strong> notificaciones<br>';
                                mensajeHtml += '<i class="fas fa-exclamation-circle"></i> Errores: <strong>' + response.detalles.errores + '</strong><br>';
                                
                                if (response.detalles.enviados_3_dias === 0 && response.detalles.enviados_vencidos === 0) {
                                    mensajeHtml += '<br><div class="alert alert-warning"> No se encontraron inscripciones que cumplan las condiciones.<br>';
                                    mensajeHtml += '<small>Requisitos: Inscripcion activa, plan que no sea "Visita", cliente con email, fecha de vencimiento = hoy o en 3 dias.</small></div>';
                                } else {
                                    mensajeHtml += '<br><div class="alert alert-success"> Proceso de emergencia completado. Se han enviado las notificaciones.</div>';
                                }
                                
                                Swal.fire({ icon: 'success', title: 'Proceso de emergencia completado', html: mensajeHtml, confirmButtonText: 'Aceptar' }).then(() => location.reload());
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.message || 'No se pudieron procesar las notificaciones' });
                            }
                        },
                        error: () => Swal.fire({ icon: 'error', title: 'Error', text: 'Ocurrio un error en el proceso de emergencia' })
                    });
                }
            });
        }
        
        $('#btnProcesarVencimientos').on('click', procesarVencimientos);
        $('#btnProcesarVencimientosEmpty').on('click', procesarVencimientos);
        
        // Mantener el tab activo
        $(document).ready(function() {
            var hash = window.location.hash;
            if (hash === '#automatica') {
                $('#automatica-tab').tab('show');
            } else if (hash === '#manual') {
                $('#manual-tab').tab('show');
            }
            
            // Cargar datos iniciales de los tabs cuando se muestran
            $('#manual-tab').on('shown.bs.tab', function() {
                if ($('#manualResultados').children('.notificacion-item').length === 0 && $('#manualResultados').text().trim() === '') {
                    cargarManuales('', 1);
                }
            });
            $('#automatica-tab').on('shown.bs.tab', function() {
                if ($('#automaticaResultados').children('.notificacion-item').length === 0 && $('#automaticaResultados').text().trim() === '') {
                    cargarAutomaticas('', 1);
                }
            });
        });
    </script>
</body>
</html>