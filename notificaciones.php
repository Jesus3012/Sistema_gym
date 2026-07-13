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


/**
 * Devuelve etiquetas legibles de los grupos seleccionados.
 * También conserva compatibilidad con registros antiguos.
 */
function obtenerEtiquetasDestinatarios($valor)
{
    $mapa = array(
        'socios_membresia_activa' => 'Socios con membresía activa',
        'socios_membresia_activa_vencida' => 'Socios con membresía activa o vencida',
        'usuarios_sistema' => 'Usuarios del sistema',

        // Valores anteriores.
        'todos_clientes_activos' => 'Clientes activos del sistema',
        'clientes_membresia_activa' => 'Clientes con membresía activa',
        'todos_usuarios' => 'Usuarios del sistema',
        'todos_membresia_usuarios' => 'Clientes con membresía activa y usuarios',
        'todos' => 'Todos los destinatarios'
    );

    $valores = array();

    if (is_array($valor)) {
        $valores = $valor;
    } else {
        $texto = trim((string) $valor);

        if ($texto !== '') {
            $json = json_decode($texto, true);
            $valores = is_array($json)
                ? $json
                : explode(',', $texto);
        }
    }

    $etiquetas = array();

    foreach ($valores as $item) {
        $clave = trim((string) $item);

        if ($clave === '') {
            continue;
        }

        $etiquetas[] = isset($mapa[$clave])
            ? $mapa[$clave]
            : $clave;
    }

    return array_values(array_unique($etiquetas));
}

function textoDestinatariosNotificacion($valor)
{
    $etiquetas = obtenerEtiquetasDestinatarios($valor);

    return empty($etiquetas)
        ? 'Sin destinatarios registrados'
        : implode(' + ', $etiquetas);
}

function agregarDestinatarioUnico(
    &$lista,
    $email,
    $nombre,
    $tipo
) {
    $email = trim((string) $email);

    if (
        $email === '' ||
        !filter_var($email, FILTER_VALIDATE_EMAIL)
    ) {
        return;
    }

    $clave = strtolower($email);

    if (!isset($lista[$clave])) {
        $lista[$clave] = array(
            'email' => $email,
            'nombre' => trim((string) $nombre),
            'tipo' => $tipo
        );
    }
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
        $mensaje_html = nl2br(
            htmlspecialchars(
                trim($mensaje_limpio),
                ENT_QUOTES,
                'UTF-8'
            )
        );
        
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
        header('Content-Type: application/json; charset=utf-8');

        $titulo = isset($_POST['titulo'])
            ? trim((string) $_POST['titulo'])
            : '';

        $mensaje_raw = isset($_POST['mensaje'])
            ? (string) $_POST['mensaje']
            : '';

        $mensaje_limpio = str_replace(
            array(
                '\r\n',
                '\r',
                '\n',
                "\r\n",
                "\r",
                "\n",
                '\\r\\n',
                '\\n'
            ),
            "\n",
            $mensaje_raw
        );

        $tipo = isset($_POST['tipo'])
            ? strtolower(trim((string) $_POST['tipo']))
            : 'info';

        $seleccionRecibida = isset($_POST['destinatarios'])
            ? $_POST['destinatarios']
            : array();

        if (!is_array($seleccionRecibida)) {
            $seleccionRecibida = array($seleccionRecibida);
        }

        $gruposPermitidos = array(
            'socios_membresia_activa',
            'socios_membresia_activa_vencida',
            'usuarios_sistema'
        );

        $tiposPermitidos = array(
            'info',
            'aviso',
            'alerta',
            'promocion'
        );

        $seleccionados = array();

        foreach ($seleccionRecibida as $grupo) {
            $grupo = trim((string) $grupo);

            if (
                in_array($grupo, $gruposPermitidos, true) &&
                !in_array($grupo, $seleccionados, true)
            ) {
                $seleccionados[] = $grupo;
            }
        }

        if ($titulo === '' || trim($mensaje_limpio) === '') {
            echo json_encode(array(
                'success' => false,
                'error' => 'El título y el mensaje son obligatorios.'
            ));
            exit;
        }

        if (!in_array($tipo, $tiposPermitidos, true)) {
            echo json_encode(array(
                'success' => false,
                'error' => 'El tipo de notificación no es válido.'
            ));
            exit;
        }

        if (count($seleccionados) < 1) {
            echo json_encode(array(
                'success' => false,
                'error' => 'Selecciona por lo menos un grupo de destinatarios.'
            ));
            exit;
        }

        if (count($seleccionados) > 2) {
            echo json_encode(array(
                'success' => false,
                'error' => 'Solo puedes seleccionar hasta dos grupos.'
            ));
            exit;
        }

        $seleccionActivos = in_array(
            'socios_membresia_activa',
            $seleccionados,
            true
        );

        $seleccionActivosVencidos = in_array(
            'socios_membresia_activa_vencida',
            $seleccionados,
            true
        );

        if ($seleccionActivos && $seleccionActivosVencidos) {
            echo json_encode(array(
                'success' => false,
                'error' =>
                    'No puedes combinar socios con membresía activa ' .
                    'con socios de membresía activa o vencida.'
            ));
            exit;
        }

        $destinatariosPorCorreo = array();

        foreach ($seleccionados as $grupoSeleccionado) {
            if ($grupoSeleccionado === 'socios_membresia_activa') {
                $sqlDestinatarios = "
                    SELECT DISTINCT
                        c.id,
                        c.nombre,
                        c.apellido,
                        c.email
                    FROM clientes c
                    INNER JOIN inscripciones i
                        ON i.cliente_id = c.id
                    WHERE c.estado = 'activo'
                      AND i.estado = 'activa'
                      AND i.fecha_fin >= CURDATE()
                      AND c.email IS NOT NULL
                      AND TRIM(c.email) <> ''
                ";

                $resultadoDestinatarios =
                    $conn->query($sqlDestinatarios);

                if ($resultadoDestinatarios) {
                    while (
                        $fila =
                            $resultadoDestinatarios->fetch_assoc()
                    ) {
                        agregarDestinatarioUnico(
                            $destinatariosPorCorreo,
                            $fila['email'],
                            $fila['nombre'] . ' ' . $fila['apellido'],
                            'cliente'
                        );
                    }
                }
            }

            if (
                $grupoSeleccionado ===
                'socios_membresia_activa_vencida'
            ) {
                $sqlDestinatarios = "
                    SELECT DISTINCT
                        c.id,
                        c.nombre,
                        c.apellido,
                        c.email
                    FROM clientes c
                    INNER JOIN inscripciones i
                        ON i.cliente_id = c.id
                    WHERE c.estado = 'activo'
                      AND i.estado IN ('activa', 'vencida')
                      AND c.email IS NOT NULL
                      AND TRIM(c.email) <> ''
                ";

                $resultadoDestinatarios =
                    $conn->query($sqlDestinatarios);

                if ($resultadoDestinatarios) {
                    while (
                        $fila =
                            $resultadoDestinatarios->fetch_assoc()
                    ) {
                        agregarDestinatarioUnico(
                            $destinatariosPorCorreo,
                            $fila['email'],
                            $fila['nombre'] . ' ' . $fila['apellido'],
                            'cliente'
                        );
                    }
                }
            }

            if ($grupoSeleccionado === 'usuarios_sistema') {
                $sqlDestinatarios = "
                    SELECT
                        id,
                        nombre,
                        email
                    FROM usuarios
                    WHERE estado = 'activo'
                      AND email IS NOT NULL
                      AND TRIM(email) <> ''
                ";

                $resultadoDestinatarios =
                    $conn->query($sqlDestinatarios);

                if ($resultadoDestinatarios) {
                    while (
                        $fila =
                            $resultadoDestinatarios->fetch_assoc()
                    ) {
                        agregarDestinatarioUnico(
                            $destinatariosPorCorreo,
                            $fila['email'],
                            $fila['nombre'],
                            'usuario'
                        );
                    }
                }
            }
        }

        $destinatariosLista = array_values(
            $destinatariosPorCorreo
        );

        if (count($destinatariosLista) === 0) {
            echo json_encode(array(
                'success' => false,
                'error' =>
                    'Los grupos seleccionados no tienen correos válidos.'
            ));
            exit;
        }

        $fecha_envio = date('Y-m-d H:i:s');
        $destinatariosGuardados = implode(',', $seleccionados);

        $stmtNotificacion = $conn->prepare(
            "INSERT INTO notificaciones
                (
                    titulo,
                    mensaje,
                    tipo,
                    destinatarios,
                    fecha_envio,
                    enviado_por,
                    estado
                )
             VALUES (?, ?, ?, ?, ?, ?, 'enviado')"
        );

        if (!$stmtNotificacion) {
            echo json_encode(array(
                'success' => false,
                'error' =>
                    'No se pudo preparar la notificación: ' .
                    $conn->error
            ));
            exit;
        }

        $stmtNotificacion->bind_param(
            'sssssi',
            $titulo,
            $mensaje_limpio,
            $tipo,
            $destinatariosGuardados,
            $fecha_envio,
            $usuario_id
        );

        if (!$stmtNotificacion->execute()) {
            $detalleError = $stmtNotificacion->error;
            $stmtNotificacion->close();

            echo json_encode(array(
                'success' => false,
                'error' =>
                    'No se pudo guardar la notificación: ' .
                    $detalleError
            ));
            exit;
        }

        $notificacion_id = (int) $conn->insert_id;
        $stmtNotificacion->close();

        $stmtDetalle = $conn->prepare(
            "INSERT INTO notificaciones_enviadas
                (
                    notificacion_id,
                    destinatario_email,
                    destinatario_nombre,
                    tipo_destinatario,
                    fecha_envio
                )
             VALUES (?, ?, ?, ?, ?)"
        );

        $enviados = 0;
        $fallidos = 0;
        $errores = array();

        foreach ($destinatariosLista as $destinatario) {
            $envioExitoso = enviarCorreo(
                $destinatario['email'],
                $destinatario['nombre'],
                $titulo,
                $mensaje_limpio,
                $tipo
            );

            if ($envioExitoso) {
                $enviados++;

                if ($stmtDetalle) {
                    $emailDetalle = $destinatario['email'];
                    $nombreDetalle = $destinatario['nombre'];
                    $tipoDetalle = $destinatario['tipo'];

                    $stmtDetalle->bind_param(
                        'issss',
                        $notificacion_id,
                        $emailDetalle,
                        $nombreDetalle,
                        $tipoDetalle,
                        $fecha_envio
                    );

                    $stmtDetalle->execute();
                }
            } else {
                $fallidos++;
                $errores[] = $destinatario['email'];
            }
        }

        if ($stmtDetalle) {
            $stmtDetalle->close();
        }

        echo json_encode(array(
            'success' => true,
            'enviados' => $enviados,
            'fallidos' => $fallidos,
            'total' => count($destinatariosLista),
            'errores' => $errores,
            'grupos' => obtenerEtiquetasDestinatarios(
                $seleccionados
            )
        ));
        exit;
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
                $destinatario_texto = textoDestinatariosNotificacion($notif['destinatarios']);
                
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

$result = $conn->query(
    "SELECT COUNT(DISTINCT c.id) AS total
     FROM clientes c
     INNER JOIN inscripciones i
        ON i.cliente_id = c.id
     WHERE c.estado = 'activo'
       AND i.estado = 'activa'
       AND i.fecha_fin >= CURDATE()"
);

$stats['socios_membresia_activa'] =
    ($result && $result->num_rows > 0)
        ? (int) $result->fetch_assoc()['total']
        : 0;

$result = $conn->query(
    "SELECT COUNT(DISTINCT c.id) AS total
     FROM clientes c
     INNER JOIN inscripciones i
        ON i.cliente_id = c.id
     WHERE c.estado = 'activo'
       AND i.estado IN ('activa', 'vencida')"
);

$stats['socios_membresia_activa_vencida'] =
    ($result && $result->num_rows > 0)
        ? (int) $result->fetch_assoc()['total']
        : 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM usuarios
     WHERE estado = 'activo'"
);

$stats['total_usuarios_activos'] =
    ($result && $result->num_rows > 0)
        ? (int) $result->fetch_assoc()['total']
        : 0;

$result = $conn->query(
    "SELECT COUNT(*) AS total
     FROM notificaciones"
);

$stats['total_notificaciones'] =
    ($result && $result->num_rows > 0)
        ? (int) $result->fetch_assoc()['total']
        : 0;

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

        .destinatarios-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 16px;
            margin-bottom: 12px;
        }

        .destinatarios-header label {
            margin-bottom: 3px;
        }

        .destinatarios-ayuda {
            margin: 0;
            color: #64748b;
            font-size: 0.78rem;
            line-height: 1.45;
        }

        .seleccion-contador {
            display: inline-flex;
            align-items: center;
            min-height: 30px;
            padding: 5px 10px;
            border: 1px solid #d7e0ea;
            border-radius: 999px;
            color: #526176;
            background: #f8fafc;
            font-size: 0.72rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .destinatarios-grid > div {
            display: flex;
            margin-bottom: 12px;
        }

        .destinatario-card {
            position: relative;
            width: 100%;
            min-height: 185px;
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            margin: 0;
            padding: 17px;
            cursor: pointer;
            border: 1px solid #dce4ed;
            border-radius: 12px;
            background: #ffffff;
            transition:
                border-color 0.18s ease,
                box-shadow 0.18s ease,
                background 0.18s ease,
                opacity 0.18s ease;
        }

        .destinatario-card:hover {
            transform: none;
            border-color: #aebfd3;
            background: #fbfdff;
            box-shadow: 0 5px 15px rgba(30, 55, 82, 0.08);
        }

        .destinatario-card.selected {
            border-color: #3478c7;
            background: #f3f8fe;
            box-shadow: 0 0 0 3px rgba(52, 120, 199, 0.1);
        }

        .destinatario-card.disabled {
            cursor: not-allowed;
            opacity: 0.46;
            background: #f5f6f8;
            box-shadow: none;
        }

        .destinatario-card.disabled:hover {
            border-color: #dce4ed;
            background: #f5f6f8;
        }

        .destinatario-checkbox {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
        }

        .destinatario-check {
            position: absolute;
            top: 13px;
            right: 13px;
            width: 24px;
            height: 24px;
            display: grid;
            place-items: center;
            border: 1px solid #cfd9e4;
            border-radius: 50%;
            color: transparent;
            background: #ffffff;
            font-size: 0.68rem;
        }

        .destinatario-card.selected .destinatario-check {
            color: #ffffff;
            border-color: #3478c7;
            background: #3478c7;
        }

        .destinatario-icono {
            width: 39px;
            height: 39px;
            display: grid;
            place-items: center;
            margin-bottom: 12px;
            border-radius: 10px;
            color: #3478c7;
            background: #edf4fc;
            font-size: 1rem;
        }

        .destinatario-card .nombre {
            padding-right: 27px;
            font-size: 0.91rem;
            line-height: 1.35;
        }

        .destinatario-card .email {
            flex: 1;
            margin-top: 6px;
            margin-bottom: 12px;
            font-size: 0.74rem;
            line-height: 1.45;
        }

        .destinatario-card .badge-custom {
            margin-top: auto;
        }

        .destinatarios-regla {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-top: 2px;
            padding: 9px 11px;
            border: 1px solid #dce5ef;
            border-radius: 8px;
            color: #566579;
            background: #f7f9fb;
            font-size: 0.74rem;
            line-height: 1.4;
        }

        .destinatarios-regla i {
            margin-top: 2px;
            color: #3478c7;
        }

        @media (max-width: 768px) {
            .destinatarios-header {
                flex-direction: column;
            }

            .seleccion-contador {
                align-self: flex-start;
            }
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
                        <h1 class="m-0">Notificaciones por Correo</h1>
                    </div>
                </div>
            </div>
        </div>

        <!-- Estadisticas -->
        <div class="row">
            <div class="col-lg-3 col-md-6 col-12">
                <div class="stats-card info-bg">
                    <div class="stats-icon bg-info">
                        <i class="fas fa-id-card"></i>
                    </div>
                    <div class="stats-number">
                        <?php echo $stats['socios_membresia_activa']; ?>
                    </div>
                    <div class="stats-label">
                        Socios con membresía activa
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 col-12">
                <div class="stats-card success-bg">
                    <div class="stats-icon bg-success">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="stats-number">
                        <?php
                        echo $stats[
                            'socios_membresia_activa_vencida'
                        ];
                        ?>
                    </div>
                    <div class="stats-label">
                        Socios activos y vencidos
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 col-12">
                <div class="stats-card warning-bg">
                    <div class="stats-icon bg-warning">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="stats-number">
                        <?php echo $stats['total_usuarios_activos']; ?>
                    </div>
                    <div class="stats-label">
                        Usuarios del sistema
                    </div>
                </div>
            </div>

            <div class="col-lg-3 col-md-6 col-12">
                <div class="stats-card danger-bg">
                    <div class="stats-icon bg-danger">
                        <i class="fas fa-envelope"></i>
                    </div>
                    <div class="stats-number">
                        <?php echo $stats['total_notificaciones']; ?>
                    </div>
                    <div class="stats-label">
                        Notificaciones enviadas
                    </div>
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
                                <div class="destinatarios-header">
                                    <div>
                                        <label>
                                            Selecciona los destinatarios
                                        </label>
                                        <p class="destinatarios-ayuda">
                                            Puedes elegir uno o dos grupos.
                                            Las dos opciones de socios no
                                            pueden seleccionarse juntas.
                                        </p>
                                    </div>

                                    <span
                                        class="seleccion-contador"
                                        id="seleccionContador"
                                    >
                                        0 de 2 seleccionados
                                    </span>
                                </div>

                                <div class="row destinatarios-grid">
                                    <div class="col-lg-4 col-md-6">
                                        <label
                                            class="destinatario-card"
                                            data-destinatario=
                                                "socios_membresia_activa"
                                        >
                                            <input
                                                type="checkbox"
                                                class=
                                                    "destinatario-checkbox"
                                                name="destinatarios[]"
                                                value=
                                                    "socios_membresia_activa"
                                            >

                                            <span
                                                class=
                                                    "destinatario-check"
                                            >
                                                <i class="fas fa-check"></i>
                                            </span>

                                            <div
                                                class=
                                                    "destinatario-icono"
                                            >
                                                <i
                                                    class=
                                                        "fas fa-id-card"
                                                ></i>
                                            </div>

                                            <div class="nombre">
                                                Socios con membresía activa
                                            </div>

                                            <div class="email">
                                                Inscripción activa y fecha de
                                                vigencia actual.
                                            </div>

                                            <span
                                                class=
                                                    "badge-custom badge-info"
                                            >
                                                <?php
                                                echo $stats[
                                                    'socios_membresia_activa'
                                                ];
                                                ?>
                                                socios
                                            </span>
                                        </label>
                                    </div>

                                    <div class="col-lg-4 col-md-6">
                                        <label
                                            class="destinatario-card"
                                            data-destinatario=
                                                "socios_membresia_activa_vencida"
                                        >
                                            <input
                                                type="checkbox"
                                                class=
                                                    "destinatario-checkbox"
                                                name="destinatarios[]"
                                                value=
                                                    "socios_membresia_activa_vencida"
                                            >

                                            <span
                                                class=
                                                    "destinatario-check"
                                            >
                                                <i class="fas fa-check"></i>
                                            </span>

                                            <div
                                                class=
                                                    "destinatario-icono"
                                            >
                                                <i class="fas fa-users"></i>
                                            </div>

                                            <div class="nombre">
                                                Socios activos y vencidos
                                            </div>

                                            <div class="email">
                                                Incluye membresías vigentes y
                                                membresías vencidas.
                                            </div>

                                            <span
                                                class=
                                                    "badge-custom badge-success"
                                            >
                                                <?php
                                                echo $stats[
                                                    'socios_membresia_activa_vencida'
                                                ];
                                                ?>
                                                socios
                                            </span>
                                        </label>
                                    </div>

                                    <div class="col-lg-4 col-md-6">
                                        <label
                                            class="destinatario-card"
                                            data-destinatario=
                                                "usuarios_sistema"
                                        >
                                            <input
                                                type="checkbox"
                                                class=
                                                    "destinatario-checkbox"
                                                name="destinatarios[]"
                                                value="usuarios_sistema"
                                            >

                                            <span
                                                class=
                                                    "destinatario-check"
                                            >
                                                <i class="fas fa-check"></i>
                                            </span>

                                            <div
                                                class=
                                                    "destinatario-icono"
                                            >
                                                <i
                                                    class=
                                                        "fas fa-user-shield"
                                                ></i>
                                            </div>

                                            <div class="nombre">
                                                Usuarios del sistema
                                            </div>

                                            <div class="email">
                                                Administradores,
                                                recepcionistas y entrenadores
                                                activos.
                                            </div>

                                            <span
                                                class=
                                                    "badge-custom badge-aviso"
                                            >
                                                <?php
                                                echo $stats[
                                                    'total_usuarios_activos'
                                                ];
                                                ?>
                                                usuarios
                                            </span>
                                        </label>
                                    </div>
                                </div>

                                <div
                                    class="destinatarios-regla"
                                    id="destinatariosRegla"
                                >
                                    <i class="fas fa-circle-info"></i>
                                    Puedes combinar una opción de socios con
                                    “Usuarios del sistema”.
                                </div>
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
                $destinatario_texto = textoDestinatariosNotificacion($notif['destinatarios']);
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
        
        // Selección de destinatarios
        const GRUPO_SOCIOS_ACTIVOS =
            'socios_membresia_activa';

        const GRUPO_SOCIOS_ACTIVOS_VENCIDOS =
            'socios_membresia_activa_vencida';

        const MAXIMO_GRUPOS = 2;

        function obtenerDestinatariosSeleccionados() {
            return $('.destinatario-checkbox:checked')
                .map(function() {
                    return $(this).val();
                })
                .get();
        }

        function actualizarEstadoDestinatarios() {
            const seleccionados =
                obtenerDestinatariosSeleccionados();

            const seleccionoActivos =
                seleccionados.includes(
                    GRUPO_SOCIOS_ACTIVOS
                );

            const seleccionoActivosVencidos =
                seleccionados.includes(
                    GRUPO_SOCIOS_ACTIVOS_VENCIDOS
                );

            $('.destinatario-card').each(function() {
                const $card = $(this);
                const $checkbox =
                    $card.find('.destinatario-checkbox');

                const valor = $checkbox.val();
                const seleccionado =
                    $checkbox.is(':checked');

                let bloquear = false;

                if (
                    seleccionoActivos &&
                    valor ===
                        GRUPO_SOCIOS_ACTIVOS_VENCIDOS &&
                    !seleccionado
                ) {
                    bloquear = true;
                }

                if (
                    seleccionoActivosVencidos &&
                    valor === GRUPO_SOCIOS_ACTIVOS &&
                    !seleccionado
                ) {
                    bloquear = true;
                }

                if (
                    seleccionados.length >= MAXIMO_GRUPOS &&
                    !seleccionado
                ) {
                    bloquear = true;
                }

                $checkbox.prop('disabled', bloquear);
                $card.toggleClass('selected', seleccionado);
                $card.toggleClass('disabled', bloquear);
            });

            $('#seleccionContador').text(
                seleccionados.length +
                ' de ' +
                MAXIMO_GRUPOS +
                ' seleccionados'
            );

            let mensaje =
                'Puedes combinar una opción de socios con ' +
                '“Usuarios del sistema”.';

            if (seleccionoActivos) {
                mensaje =
                    '“Socios activos y vencidos” se bloqueó ' +
                    'porque seleccionaste socios con membresía activa.';
            }

            if (seleccionoActivosVencidos) {
                mensaje =
                    '“Socios con membresía activa” se bloqueó ' +
                    'porque seleccionaste socios activos y vencidos.';
            }

            if (seleccionados.length >= MAXIMO_GRUPOS) {
                mensaje =
                    'Alcanzaste el máximo de dos grupos. ' +
                    'Deselecciona uno para cambiarlo.';
            }

            $('#destinatariosRegla').html(
                '<i class="fas fa-circle-info"></i>' +
                mensaje
            );
        }

        $('.destinatario-checkbox').on(
            'change',
            function() {
                let seleccionados =
                    obtenerDestinatariosSeleccionados();

                if (
                    seleccionados.length >
                    MAXIMO_GRUPOS
                ) {
                    $(this).prop('checked', false);

                    Swal.fire({
                        icon: 'info',
                        title: 'Máximo dos grupos',
                        text:
                            'Solo puedes seleccionar hasta ' +
                            'dos grupos de destinatarios.',
                        confirmButtonColor: '#3478c7'
                    });
                }

                seleccionados =
                    obtenerDestinatariosSeleccionados();

                if (
                    seleccionados.includes(
                        GRUPO_SOCIOS_ACTIVOS
                    ) &&
                    seleccionados.includes(
                        GRUPO_SOCIOS_ACTIVOS_VENCIDOS
                    )
                ) {
                    $(this).prop('checked', false);

                    Swal.fire({
                        icon: 'info',
                        title: 'Opciones incompatibles',
                        text:
                            'Las dos opciones de socios no ' +
                            'pueden seleccionarse al mismo tiempo.',
                        confirmButtonColor: '#3478c7'
                    });
                }

                actualizarEstadoDestinatarios();
            }
        );

        actualizarEstadoDestinatarios();

        // Envio de formulario
        $('#formNotificacion').on('submit', function(e) {
            e.preventDefault();
            var titulo = $('#titulo').val();
            var mensaje = $('#mensaje').val();

            var destinatarios =
                obtenerDestinatariosSeleccionados();
            
            if (!titulo || !mensaje) {
                Swal.fire({ icon: 'error', title: 'Campos incompletos', text: 'Por favor completa el titulo y el mensaje' });
                return;
            }
            if (destinatarios.length === 0) {
                Swal.fire({
                    icon: 'error',
                    title: 'Selecciona destinatarios',
                    text:
                        'Selecciona por lo menos un grupo ' +
                        'de destinatarios.'
                });
                return;
            }

            if (destinatarios.length > 2) {
                Swal.fire({
                    icon: 'error',
                    title: 'Demasiados grupos',
                    text:
                        'Solo puedes seleccionar hasta dos grupos.'
                });
                return;
            }

            const nombresDestinatarios =
                $('.destinatario-checkbox:checked')
                    .map(function() {
                        return $(this)
                            .closest('.destinatario-card')
                            .find('.nombre')
                            .text()
                            .trim();
                    })
                    .get();

            const listaDestinatarios =
                nombresDestinatarios
                    .map(function(nombre) {
                        return '<li>' +
                            $('<div>')
                                .text(nombre)
                                .html() +
                            '</li>';
                    })
                    .join('');

            Swal.fire({
                title: '¿Enviar notificación por correo?',
                html:
                    '<div style="text-align:left;">' +
                        '<p style="margin-bottom:7px;">' +
                            '<strong>Destinatarios:</strong>' +
                        '</p>' +
                        '<ul style="margin:0;padding-left:20px;">' +
                            listaDestinatarios +
                        '</ul>' +
                    '</div>',
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