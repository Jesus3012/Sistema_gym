<?php
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

// Funcion para enviar correo con PHPMailer
function enviarCorreo($email, $nombre, $titulo, $mensaje, $tipo) {
    if (empty($email)) {
        return false;
    }
    
    $mail = new PHPMailer(true);
    
    try {
        // Configuracion SMTP
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
        
        // Configuracion del correo
        $mail->setFrom('jesusgabrielmtz78@gmail.com', 'Gimnasio System');
        $mail->addAddress($email, $nombre);
        $mail->isHTML(true);
        
        $asunto = "Notificacion Gimnasio - " . $titulo;
        $mail->Subject = $asunto;
        
        // Colores segun el tipo
        $color = '#3b82f6';
        if ($tipo == 'aviso') $color = '#f59e0b';
        if ($tipo == 'alerta') $color = '#ef4444';
        if ($tipo == 'promocion') $color = '#10b981';
        
        // HTML del correo
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
                        ' . nl2br(htmlspecialchars($mensaje)) . '
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
        $mail->AltBody = strip_tags($mensaje);
        
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
        $mensaje = $conn->real_escape_string($_POST['mensaje']);
        $tipo = $conn->real_escape_string($_POST['tipo']);
        $destinatarios = $conn->real_escape_string($_POST['destinatarios']);
        $fecha_envio = date('Y-m-d H:i:s');
        
        // Insertar la notificacion
        $query = "INSERT INTO notificaciones (titulo, mensaje, tipo, destinatarios, fecha_envio, enviado_por, estado) 
                  VALUES ('$titulo', '$mensaje', '$tipo', '$destinatarios', '$fecha_envio', $usuario_id, 'enviado')";
        
        if ($conn->query($query)) {
            $notificacion_id = $conn->insert_id;
            
            // Obtener destinatarios segun la seleccion
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
                    // Clientes con inscripcion activa y fecha_fin >= hoy
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
                    
                case 'todos':
                    // Clientes activos
                    $query_clientes = "SELECT id, nombre, apellido, email FROM clientes WHERE estado = 'activo' AND email IS NOT NULL AND email != ''";
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
                    // Usuarios activos
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
            
            // Enviar correos y registrar
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
                    // Registrar en la tabla notificaciones_enviadas
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
}

// Obtener estadisticas
$stats = array();

$result = $conn->query("SELECT COUNT(*) as total FROM clientes WHERE estado = 'activo'");
$stats['total_clientes_activos'] = $result ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query("SELECT COUNT(DISTINCT c.id) as total 
    FROM clientes c 
    INNER JOIN inscripciones i ON c.id = i.cliente_id 
    WHERE c.estado = 'activo' AND i.estado = 'activa' AND i.fecha_fin >= CURDATE()");
$stats['clientes_con_membresia'] = $result ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query("SELECT COUNT(*) as total FROM usuarios WHERE estado = 'activo'");
$stats['total_usuarios_activos'] = $result ? $result->fetch_assoc()['total'] : 0;

$result = $conn->query("SELECT COUNT(*) as total FROM notificaciones");
$stats['total_notificaciones'] = $result ? $result->fetch_assoc()['total'] : 0;

// Obtener ultimas notificaciones con conteo de enviados
$stats['ultimas_notificaciones'] = $conn->query("SELECT n.*, u.nombre as usuario_envio, 
    (SELECT COUNT(*) FROM notificaciones_enviadas WHERE notificacion_id = n.id) as total_enviados
    FROM notificaciones n 
    LEFT JOIN usuarios u ON n.enviado_por = u.id 
    ORDER BY n.fecha_envio DESC LIMIT 15");
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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: #f5f7fa;
            font-family: 'Source Sans Pro', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
        }

        .main-content {
            margin-left: 280px;
            transition: margin-left 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            min-height: 100vh;
            padding: 20px;
            background: #f4f6f9;
        }

        body.sidebar-collapsed .main-content {
            margin-left: 70px;
        }

        @media (max-width: 768px) {
            .main-content {
                margin-left: 0 !important;
                padding: 80px 15px 15px 15px;
            }
        }

        .content-header {
            padding: 15px 0;
        }

        .content-header h1 {
            font-size: 1.8rem;
            font-weight: 600;
            color: #1e293b;
        }

        .card {
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            background: white;
        }

        .card-header {
            background: #2563eb;
            color: white;
            border-radius: 8px 8px 0 0;
            padding: 15px 20px;
        }

        .card-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
        }

        .card-header i {
            margin-right: 8px;
        }

        .stat-box {
            background: white;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }

        .stat-box:hover {
            transform: translateY(-3px);
        }

        .stat-box .number {
            font-size: 2.2rem;
            font-weight: bold;
            color: #2563eb;
        }

        .stat-box .label {
            color: #64748b;
            font-size: 0.85rem;
            margin-top: 8px;
        }

        .notificacion-item {
            border-left: 3px solid;
            margin-bottom: 15px;
            padding: 15px;
            background: white;
            border-radius: 6px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }

        .notificacion-item.info { border-left-color: #3b82f6; }
        .notificacion-item.aviso { border-left-color: #f59e0b; }
        .notificacion-item.alerta { border-left-color: #ef4444; }
        .notificacion-item.promocion { border-left-color: #10b981; }

        .notificacion-item .titulo {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 5px;
        }

        .notificacion-item .mensaje {
            color: #475569;
            font-size: 0.85rem;
            margin-bottom: 8px;
        }

        .notificacion-item .meta {
            font-size: 0.7rem;
            color: #94a3b8;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
        }

        .badge-custom {
            padding: 3px 10px;
            border-radius: 15px;
            font-size: 0.7rem;
            font-weight: 600;
        }

        .badge-info { background: #dbeafe; color: #1e40af; }
        .badge-aviso { background: #fed7aa; color: #92400e; }
        .badge-alerta { background: #fee2e2; color: #991b1b; }
        .badge-promocion { background: #d1fae5; color: #065f46; }

        textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            font-weight: 600;
            margin-bottom: 0.5rem;
            display: block;
            color: #1e293b;
        }

        .form-control, .form-select {
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            padding: 8px 12px;
            transition: all 0.2s;
        }

        .form-control:focus, .form-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .btn-primary {
            background: #2563eb;
            border: none;
            border-radius: 6px;
            padding: 10px 24px;
            font-weight: 600;
            color: white;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary:hover {
            background: #1d4ed8;
        }

        .destinatario-card {
            background: #f8fafc;
            border-radius: 8px;
            padding: 12px 15px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
            border: 1px solid #e2e8f0;
        }

        .destinatario-card:hover {
            background: #f1f5f9;
            transform: translateX(3px);
        }

        .destinatario-card.selected {
            border-color: #2563eb;
            background: #eff6ff;
        }

        .destinatario-card .nombre {
            font-weight: 600;
            color: #1e293b;
            font-size: 0.9rem;
        }

        .destinatario-card .email {
            font-size: 0.75rem;
            color: #64748b;
        }
    </style>
</head>
<body class="hold-transition sidebar-mini">
    <?php include 'includes/sidebar.php'; ?>

    <div class="main-content">
        <div class="content-header">
            <div class="container-fluid">
                <div class="row mb-2">
                    <div class="col-sm-6">
                        <h1 class="m-0">
                            <i class="fas fa-envelope"></i> Notificaciones por Correo
                        </h1>
                    </div>
                </div>
            </div>
        </div>

        <!-- Estadisticas -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="number"><?php echo $stats['total_clientes_activos']; ?></div>
                    <div class="label">Clientes Activos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="number"><?php echo $stats['clientes_con_membresia']; ?></div>
                    <div class="label">Con Membresia Activa</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="number"><?php echo $stats['total_usuarios_activos']; ?></div>
                    <div class="label">Usuarios Activos</div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-box">
                    <div class="number"><?php echo $stats['total_notificaciones']; ?></div>
                    <div class="label">Notificaciones Enviadas</div>
                </div>
            </div>
        </div>

        <!-- Formulario de envio -->
        <div class="card">
            <div class="card-header">
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
                                <label>Destinatarios</label>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="destinatario-card" data-destinatario="todos_clientes_activos">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div class="nombre">Todos los clientes activos</div>
                                                    <div class="email">Clientes con estado activo</div>
                                                </div>
                                                <span class="badge-custom badge-info"><?php echo $stats['total_clientes_activos']; ?> clientes</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="destinatario-card" data-destinatario="clientes_membresia_activa">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div class="nombre">Clientes con membresia activa</div>
                                                    <div class="email">Inscripcion activa y no vencida</div>
                                                </div>
                                                <span class="badge-custom badge-info"><?php echo $stats['clientes_con_membresia']; ?> clientes</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="destinatario-card" data-destinatario="todos_usuarios">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div class="nombre">Todos los usuarios del sistema</div>
                                                    <div class="email">Usuarios activos del sistema</div>
                                                </div>
                                                <span class="badge-custom badge-aviso"><?php echo $stats['total_usuarios_activos']; ?> usuarios</span>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="destinatario-card" data-destinatario="todos">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <div class="nombre">Todos (Clientes + Usuarios)</div>
                                                    <div class="email">Clientes activos + Usuarios activos</div>
                                                </div>
                                                <span class="badge-custom badge-promocion"><?php echo $stats['total_clientes_activos'] + $stats['total_usuarios_activos']; ?> personas</span>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <input type="hidden" name="destinatarios" id="destinatarios" required>
                            </div>
                        </div>
                    </div>
                    <div class="text-right mt-3">
                        <button type="submit" class="btn-primary" style="border: none; cursor: pointer;">
                            <i class="fas fa-paper-plane"></i> Enviar Notificacion por Correo
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Historial de notificaciones -->
        <div class="card">
            <div class="card-header">
                <h3><i class="fas fa-history"></i> Historial de Notificaciones Enviadas</h3>
            </div>
            <div class="card-body">
                <?php if ($stats['ultimas_notificaciones'] && $stats['ultimas_notificaciones']->num_rows > 0): ?>
                    <?php while($notif = $stats['ultimas_notificaciones']->fetch_assoc()): 
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
                            'todos' => 'Todos (Clientes + Usuarios)'
                        );
                        $destinatario_texto = isset($destinatarios_texto[$notif['destinatarios']]) ? $destinatarios_texto[$notif['destinatarios']] : $notif['destinatarios'];
                    ?>
                        <div class="notificacion-item <?php echo $tipo_clase; ?>">
                            <div class="titulo">
                                <?php echo htmlspecialchars($notif['titulo']); ?>
                                <span class="badge-custom badge-<?php echo $tipo_clase; ?> float-right"><?php echo $tipo_texto; ?></span>
                            </div>
                            <div class="mensaje"><?php echo nl2br(htmlspecialchars($notif['mensaje'])); ?></div>
                            <div class="meta">
                                <span><i class="fas fa-calendar"></i> <?php echo date('d/m/Y H:i', strtotime($notif['fecha_envio'])); ?></span>
                                <span><i class="fas fa-user"></i> Enviado por: <?php echo htmlspecialchars($notif['usuario_envio']); ?></span>
                                <span><i class="fas fa-users"></i> <?php echo $destinatario_texto; ?></span>
                                <span><i class="fas fa-envelope"></i> Enviados: <?php echo $notif['total_enviados']; ?> correos</span>
                            </div>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-envelope-open fa-3x mb-3"></i>
                        <p>No hay notificaciones enviadas aun</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/js/adminlte.min.js"></script>

    <script>
        // Seleccion de destinatarios
        var destinatarioSeleccionado = null;
        
        $('.destinatario-card').on('click', function() {
            $('.destinatario-card').removeClass('selected');
            $(this).addClass('selected');
            destinatarioSeleccionado = $(this).data('destinatario');
            $('#destinatarios').val(destinatarioSeleccionado);
        });
        
        $('#formNotificacion').on('submit', function(e) {
            e.preventDefault();
            
            var titulo = $('#titulo').val();
            var mensaje = $('#mensaje').val();
            var destinatarios = $('#destinatarios').val();
            
            if (!titulo || !mensaje) {
                Swal.fire({
                    icon: 'error',
                    title: 'Campos incompletos',
                    text: 'Por favor completa el titulo y el mensaje'
                });
                return;
            }
            
            if (!destinatarios) {
                Swal.fire({
                    icon: 'error',
                    title: 'Selecciona destinatarios',
                    text: 'Por favor selecciona un grupo de destinatarios'
                });
                return;
            }
            
            var destinatarioTexto = $('.destinatario-card.selected .nombre').text();
            
            Swal.fire({
                title: 'Enviar notificacion por correo?',
                html: '<p><strong>Destinatarios:</strong> ' + destinatarioTexto + '</p>' +
                       '<p><strong>Titulo:</strong> ' + titulo + '</p>' +
                       '<p class="text-muted small">Se enviara por correo electronico a todos los destinatarios seleccionados.</p>',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Si, enviar',
                cancelButtonText: 'Cancelar'
            }).then(function(result) {
                if (result.isConfirmed) {
                    Swal.fire({
                        title: 'Enviando...',
                        text: 'Por favor espera, esto puede tomar unos momentos',
                        allowOutsideClick: false,
                        didOpen: function() {
                            Swal.showLoading();
                        }
                    });
                    
                    var formData = $(this).serialize();
                    formData += '&action=enviar_notificacion';
                    
                    $.ajax({
                        url: 'notificaciones.php',
                        method: 'POST',
                        data: formData,
                        dataType: 'json',
                        timeout: 300000,
                        success: function(response) {
                            if (response.success) {
                                var mensajeHtml = '<strong>' + response.enviados + '</strong> correos enviados correctamente<br>';
                                mensajeHtml += '<strong>' + response.fallidos + '</strong> correos fallidos<br>';
                                mensajeHtml += '<strong>Total:</strong> ' + response.total + ' destinatarios';
                                
                                if (response.fallidos > 0 && response.errores && response.errores.length > 0) {
                                    mensajeHtml += '<br><br><strong>Correos con error:</strong><br>';
                                    mensajeHtml += response.errores.slice(0, 5).join('<br>');
                                    if (response.errores.length > 5) {
                                        mensajeHtml += '<br>... y ' + (response.errores.length - 5) + ' mas';
                                    }
                                }
                                
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Notificaciones enviadas',
                                    html: mensajeHtml,
                                    confirmButtonText: 'Aceptar'
                                }).then(function() {
                                    location.reload();
                                });
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text: response.error || 'No se pudo enviar la notificacion'
                                });
                            }
                        },
                        error: function(xhr, status, error) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: 'Ocurrio un error al enviar la notificacion: ' + error
                            });
                        }
                    });
                }
            }.bind(this));
        });
    </script>
</body>
</html>