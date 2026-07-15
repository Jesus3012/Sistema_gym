<?php
date_default_timezone_set('America/Mexico_City');

// inscripciones.php
session_start();
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/qr_helper.php'; // Incluir el helper de QR
require_once __DIR__ . '/includes/correo_inscripciones.php'; // Correos de bienvenida y renovación
require_once __DIR__ . '/includes/mercadopago_inscripciones.php'; // Validación y vínculo de pagos Point


// ==================== FIN FUNCIÓN QR ====================

// Crear instancia de la base de datos y obtener la conexión
$database = new Database();
$conn = $database->getConnection();

// Verificar que la conexión existe
if (!$conn) {
    die("Error: No se pudo establecer la conexión a la base de datos");
}

// Verificar si el usuario está logueado
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

// Obtener datos del usuario actual
$usuario_id = $_SESSION['user_id'];
$usuario_nombre = $_SESSION['user_name'];
$usuario_rol = $_SESSION['user_rol'];

// Procesar acciones
$mensaje = '';
$error = '';

// Verificar si se debe abrir el modal automáticamente
$abrir_modal_nuevo = isset($_GET['action']) && $_GET['action'] == 'nuevo_cliente';


// Crear nuevo cliente e inscripción

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'crear_cliente_inscripcion') {
    // Verificar token CSRF
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $_SESSION['error'] = 'Token de seguridad inválido. Por favor, intente nuevamente.';
        header('Location: inscripciones.php');
        exit;
    } else {
        try {
            $nombre = trim($_POST['nombre']);
            $apellido = trim($_POST['apellido']);
            $telefono = trim($_POST['telefono']);
            $email = trim($_POST['email']);
            $plan_id = (int) $_POST['plan_id'];
            $fecha_inicio = trim((string) $_POST['fecha_inicio']);
            $precio_pagado = round((float) $_POST['precio_pagado'], 2);
            $metodo_pago_solicitado = trim((string) $_POST['metodo_pago']);
            $metodo_pago = $metodo_pago_solicitado;
            $metodo_pago_descripcion = mp_inscripcion_etiqueta_pago($metodo_pago_solicitado);
            $referencia = isset($_POST['referencia']) && trim((string) $_POST['referencia']) !== ''
                ? trim((string) $_POST['referencia'])
                : null;
            $mp_pago_data = null;
            
            if (empty($nombre) || empty($apellido) || empty($telefono) || empty($plan_id)) {
                throw new Exception('Por favor complete todos los campos requeridos');
            }
            
            // Validar que no exista cliente con mismo teléfono o email
            $stmt = $conn->prepare("SELECT id FROM clientes WHERE telefono = ? OR (email = ? AND email != '')");
            $stmt->bind_param("ss", $telefono, $email);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                throw new Exception('Ya existe un cliente con ese teléfono o email');
            }
            
            // Obtener datos del plan
            $stmt = $conn->prepare("SELECT duracion_dias, precio, nombre as plan_nombre FROM planes WHERE id = ? AND estado = 'activo'");
            $stmt->bind_param("i", $plan_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $plan = $result->fetch_assoc();
            
            if (!$plan) {
                throw new Exception('Plan no válido');
            }

            // El precio siempre se toma de la base de datos, nunca del navegador.
            $precio_plan = round((float) $plan['precio'], 2);
            if ($precio_plan <= 0 || abs($precio_plan - $precio_pagado) > 0.01) {
                throw new Exception('El precio del plan cambió. Actualiza el formulario e intenta nuevamente.');
            }
            $precio_pagado = $precio_plan;

            if (mp_inscripcion_es_tarjeta($metodo_pago_solicitado)) {
                $mp_pago_data = mp_validar_pago_inscripcion(
                    $conn,
                    $_POST,
                    $precio_pagado,
                    $metodo_pago_solicitado,
                    'inscripcion'
                );

                // Conserva "tarjeta" en la tabla local para no romper enums existentes.
                $metodo_pago = 'tarjeta';
                $mensualidades = max(1, (int) ($mp_pago_data['installments'] ?? 1));
                $metodo_pago_descripcion = mp_inscripcion_etiqueta_pago(
                    $metodo_pago_solicitado,
                    $mensualidades
                );
                $referencia = $mp_pago_data['payment_reference_id']
                    ?? $mp_pago_data['external_reference']
                    ?? $mp_pago_data['order_id'];
            } elseif (!in_array($metodo_pago_solicitado, ['efectivo', 'transferencia'], true)) {
                throw new Exception('Método de pago no válido.');
            }
            
            // Calcular fecha fin
            if ($plan['duracion_dias'] > 0) {
                if ($plan['duracion_dias'] == 1) {
                    $fecha_fin = $fecha_inicio;
                } else {
                    $fecha_fin = date('Y-m-d', strtotime($fecha_inicio . ' + ' . $plan['duracion_dias'] . ' days'));
                }
            } else {
                $fecha_fin = null;
            }
            
            // ========== GENERAR CÓDIGO QR ÚNICO ==========
            // Generar código único
            $codigo_qr = generarCodigoQRUnico($conn);
            if (!$codigo_qr) {
                throw new Exception('No se pudo generar un código QR único. Intente nuevamente.');
            }
            
            // Crear directorio para QR si no existe
            $qr_dir = 'qrcodes/';
            if (!file_exists($qr_dir)) {
                mkdir($qr_dir, 0777, true);
            }
            
            // Generar el archivo de imagen QR
            $nombre_archivo_qr = preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigo_qr) . '.png';
            $ruta_qr_completa = $qr_dir . $nombre_archivo_qr;
            
            // Generar el QR
            $qr_generado = generarCodigoQR($codigo_qr, $ruta_qr_completa);
            
            if (!$qr_generado) {
                // Si falla la generación del QR, igual creamos el cliente pero con advertencia
                error_log("Error al generar QR para código: " . $codigo_qr);
            }
            
            $conn->begin_transaction();
            
            // Insertar cliente (guardamos el código QR)
            $stmt = $conn->prepare("INSERT INTO clientes (nombre, apellido, telefono, email, codigo_qr, estado) VALUES (?, ?, ?, ?, ?, 'activo')");
            $stmt->bind_param("sssss", $nombre, $apellido, $telefono, $email, $codigo_qr);
            $stmt->execute();
            $cliente_id = $conn->insert_id;
            
            // Insertar inscripción
            $stmt = $conn->prepare("INSERT INTO inscripciones (cliente_id, plan_id, fecha_inicio, fecha_fin, precio_pagado, estado) VALUES (?, ?, ?, ?, ?, 'activa')");
            $stmt->bind_param("iisss", $cliente_id, $plan_id, $fecha_inicio, $fecha_fin, $precio_pagado);
            $stmt->execute();
            $inscripcion_id = $conn->insert_id;
            
            // Insertar pago
            $fecha_actual_db = date('Y-m-d');
            $stmt = $conn->prepare("INSERT INTO pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, estado) VALUES (?, ?, ?, ?, ?, ?, 'completado')");
            $stmt->bind_param("iidsss", $inscripcion_id, $cliente_id, $precio_pagado, $fecha_actual_db, $metodo_pago, $referencia);
            $stmt->execute();
            $pago_id = (int) $conn->insert_id;
            
            // Registrar en historial_pagos
            $stmt = $conn->prepare("INSERT INTO historial_pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, periodo_inicio, periodo_fin, plan_nombre, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iidssssssi", $inscripcion_id, $cliente_id, $precio_pagado, $fecha_actual_db, $metodo_pago, $referencia, $fecha_inicio, $fecha_fin, $plan['plan_nombre'], $usuario_id);
            $stmt->execute();

            if (is_array($mp_pago_data)) {
                mp_vincular_pago_inscripcion(
                    $conn,
                    (string) $mp_pago_data['order_id'],
                    (int) $inscripcion_id,
                    (int) $pago_id,
                    'inscripcion'
                );
            }
            
            $conn->commit();

// ========== ENVIAR CORREO DE BIENVENIDA CON QR ==========
$envio_correo = false;
if (!empty($email)) {
    $nombre_completo = $nombre . ' ' . $apellido;
    
    // Llamar a la función con todos los parámetros
    $envio_correo = enviarCorreoBienvenidaInscripcion(
        $conn,
        $email,                    // email del cliente
        $nombre_completo,          // nombre completo
        $plan['plan_nombre'],      // plan
        $fecha_inicio,             // fecha inicio
        $fecha_fin,                // fecha fin
        $precio_pagado,            // monto
        $metodo_pago_descripcion,  // método de pago mostrado al socio
        $referencia,               // referencia
        $codigo_qr,                // ← código QR
        $ruta_qr_completa          // ← ruta del archivo QR
    );
    
    if (!$envio_correo) {
        error_log("Error al enviar correo a: " . $email);
    }
}
// ========== FIN ENVÍO DE CORREO ==========

// Mensaje de éxito con información del QR
$mensaje_exito = "Cliente e inscripción creados exitosamente. ";
$mensaje_exito .= "Código QR: <strong>{$codigo_qr}</strong><br>";
if ($qr_generado && file_exists($ruta_qr_completa)) {
    $mensaje_exito .= "El QR quedó disponible en el botón <strong>QR</strong> del listado.";
} else {
    $mensaje_exito .= "<span class='text-warning'>No se pudo generar la imagen del código QR. El código es: {$codigo_qr}</span>";
}

if (is_array($mp_pago_data)) {
    $mensaje_exito .= '<br><span class="text-success">✓ Pago confirmado en terminal: ' .
        htmlspecialchars($metodo_pago_descripcion, ENT_QUOTES, 'UTF-8') . '</span>';
}

// Agregar información del correo al mensaje
if (!empty($email)) {
    if ($envio_correo) {
        $mensaje_exito .= "<br><span class='text-success'>✓ Se ha enviado un correo de confirmación a {$email}</span>";
    } else {
        $mensaje_exito .= "<br><span class='text-warning'>⚠ No se pudo enviar el correo a {$email}. Verifique la configuración SMTP.</span>";
    }
}

$_SESSION['mensaje_exito'] = $mensaje_exito;

            // Limpiar token
            unset($_SESSION['csrf_token']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            header('Location: inscripciones.php');
            exit;
            
        } catch (Exception $e) {
            if (isset($conn)) $conn->rollback();
            $_SESSION['error'] = $e->getMessage();
            header('Location: inscripciones.php');
            exit;
        }
    }
}

// Generar token CSRF para el formulario
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Renovar inscripción
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'renovar_inscripcion') {
    try {
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], (string) $_POST['csrf_token'])) {
            throw new Exception('Token de seguridad inválido. Actualiza la página e intenta nuevamente.');
        }
        $inscripcion_id = (int) $_POST['inscripcion_id'];
        $cliente_id = (int) $_POST['cliente_id'];
        $plan_id = (int) $_POST['plan_id'];
        $fecha_inicio = trim((string) $_POST['fecha_inicio']);
        $precio_pagado = round((float) $_POST['precio_pagado'], 2);
        $metodo_pago_solicitado = trim((string) $_POST['metodo_pago']);
        $metodo_pago = $metodo_pago_solicitado;
        $metodo_pago_descripcion = mp_inscripcion_etiqueta_pago($metodo_pago_solicitado);
        $referencia = isset($_POST['referencia']) && trim((string) $_POST['referencia']) !== ''
            ? trim((string) $_POST['referencia'])
            : null;
        $mp_pago_data = null;
        
        // Verificar si ya se procesó esta renovación (prevenir doble clic)
        $clave_renovacion = 'last_renewal_' . $inscripcion_id;
        if (isset($_SESSION[$clave_renovacion]) && $_SESSION[$clave_renovacion] > time() - 10) {
            throw new Exception('Ya se está procesando una renovación para esta inscripción. Por favor espere.');
        }
        $_SESSION[$clave_renovacion] = time();
        
        // Validar que la fecha de inicio no sea anterior a hoy
        if (strtotime($fecha_inicio) < strtotime(date('Y-m-d'))) {
            throw new Exception('La fecha de inicio no puede ser anterior a hoy');
        }
        
        // Obtener datos del plan seleccionado
        $stmt = $conn->prepare("SELECT duracion_dias, precio, nombre as plan_nombre FROM planes WHERE id = ? AND estado = 'activo'");
        $stmt->bind_param("i", $plan_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $plan = $result->fetch_assoc();
        
        if (!$plan) {
            throw new Exception('Plan no válido');
        }

        $precio_plan = round((float) $plan['precio'], 2);
        if ($precio_plan <= 0 || abs($precio_plan - $precio_pagado) > 0.01) {
            throw new Exception('El precio del plan cambió. Actualiza el formulario e intenta nuevamente.');
        }
        $precio_pagado = $precio_plan;
        
        // Obtener la inscripción y el estado real del cliente.
        // Esta validación del servidor evita renovar aunque alguien manipule el botón o el formulario.
        $stmt_ins = $conn->prepare(
            "SELECT i.*, p.nombre AS plan_actual, p.duracion_dias AS duracion_actual,
                    c.estado AS cliente_estado
             FROM inscripciones i
             INNER JOIN planes p ON i.plan_id = p.id
             INNER JOIN clientes c ON i.cliente_id = c.id
             WHERE i.id = ?
             LIMIT 1"
        );
        $stmt_ins->bind_param("i", $inscripcion_id);
        $stmt_ins->execute();
        $inscripcion_actual = $stmt_ins->get_result()->fetch_assoc();

        if (!$inscripcion_actual) {
            throw new Exception('La inscripción seleccionada no existe.');
        }

        // Usar siempre el cliente asociado a la inscripción y no confiar en el campo oculto del formulario.
        $cliente_id = (int) $inscripcion_actual['cliente_id'];

        if (($inscripcion_actual['cliente_estado'] ?? '') !== 'activo') {
            throw new Exception('No se puede renovar la inscripción porque el socio está inactivo. Actívelo primero desde la configuración de socios.');
        }

        if ($inscripcion_actual['estado'] === 'cancelada') {
            throw new Exception('No se puede renovar una inscripción cancelada.');
        }
        
        // VERIFICACIÓN: Solo permitir renovar si la inscripción está VENCIDA o es un plan de 1 día VENCIDO
        if ($inscripcion_actual['estado'] == 'activa') {
            // Para planes de 1 día, verificar si ya pasó la fecha
            if ($inscripcion_actual['duracion_actual'] == 1) {
                $fecha_fin_actual = $inscripcion_actual['fecha_fin'];
                $hoy = new DateTime();
                $hoy->setTime(0, 0, 0);
                $fecha_fin_obj = new DateTime($fecha_fin_actual);
                $fecha_fin_obj->setTime(0, 0, 0);
                
                if ($hoy <= $fecha_fin_obj) {
                    throw new Exception('No se puede renovar un plan de 1 día mientras está activo el día de hoy. Espere hasta mañana.');
                }
            } else {
                // Para planes normales, NO permitir renovar mientras esté activo
                throw new Exception('No se puede renovar mientras la inscripción está activa. Espere a que venza.');
            }
        }
        
        if (mp_inscripcion_es_tarjeta($metodo_pago_solicitado)) {
            $mp_pago_data = mp_validar_pago_inscripcion(
                $conn,
                $_POST,
                $precio_pagado,
                $metodo_pago_solicitado,
                'renovacion'
            );

            $metodo_pago = 'tarjeta';
            $mensualidades = max(1, (int) ($mp_pago_data['installments'] ?? 1));
            $metodo_pago_descripcion = mp_inscripcion_etiqueta_pago(
                $metodo_pago_solicitado,
                $mensualidades
            );
            $referencia = $mp_pago_data['payment_reference_id']
                ?? $mp_pago_data['external_reference']
                ?? $mp_pago_data['order_id'];
        } elseif (!in_array($metodo_pago_solicitado, ['efectivo', 'transferencia'], true)) {
            throw new Exception('Método de pago no válido.');
        }

        // Calcular fecha fin según el plan
        if ($plan['duracion_dias'] > 0) {
            // Para plan de 1 día, la fecha fin es la misma fecha de inicio
            if ($plan['duracion_dias'] == 1) {
                $fecha_fin = $fecha_inicio;
            } else {
                $fecha_fin = date('Y-m-d', strtotime($fecha_inicio . ' + ' . $plan['duracion_dias'] . ' days'));
            }
        } else {
            $fecha_fin = null;
        }
        
        $conn->begin_transaction();
        
        // ACTUALIZAR la inscripción existente con el NUEVO PLAN
        $stmt = $conn->prepare("UPDATE inscripciones SET plan_id = ?, fecha_inicio = ?, fecha_fin = ?, precio_pagado = ?, estado = 'activa' WHERE id = ?");
        $stmt->bind_param("isssi", $plan_id, $fecha_inicio, $fecha_fin, $precio_pagado, $inscripcion_id);
        $stmt->execute();
        
        // Registrar el pago en la tabla pagos
        $fecha_actual = date('Y-m-d');
        $stmt = $conn->prepare("INSERT INTO pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, estado) VALUES (?, ?, ?, ?, ?, ?, 'completado')");
        $stmt->bind_param("iidsss", $inscripcion_id, $cliente_id, $precio_pagado, $fecha_actual, $metodo_pago, $referencia);
        $stmt->execute();
        $pago_id = (int) $conn->insert_id;

        // Registrar en historial_pagos
        $stmt = $conn->prepare("INSERT INTO historial_pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, periodo_inicio, periodo_fin, plan_nombre, usuario_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iidssssssi", $inscripcion_id, $cliente_id, $precio_pagado, $fecha_actual, $metodo_pago, $referencia, $fecha_inicio, $fecha_fin, $plan['plan_nombre'], $usuario_id);
        $stmt->execute();

        if (is_array($mp_pago_data)) {
            mp_vincular_pago_inscripcion(
                $conn,
                (string) $mp_pago_data['order_id'],
                (int) $inscripcion_id,
                (int) $pago_id,
                'renovacion'
            );
        }
        
        $conn->commit();

        // Obtener el email y QR del cliente
        $stmt_email = $conn->prepare("SELECT email, nombre, apellido, codigo_qr FROM clientes WHERE id = ?");
        $stmt_email->bind_param("i", $cliente_id);
        $stmt_email->execute();
        $result_email = $stmt_email->get_result();
        $cliente_data = $result_email->fetch_assoc();
        $email_cliente = $cliente_data['email'];
        $nombre_completo = $cliente_data['nombre'] . ' ' . $cliente_data['apellido'];
        $codigo_qr_cliente = trim((string)($cliente_data['codigo_qr'] ?? ''));
        $ruta_qr_cliente = '';

        if ($codigo_qr_cliente !== '') {
            $qr_dir = 'qrcodes/';
            if (!file_exists($qr_dir)) {
                mkdir($qr_dir, 0777, true);
            }

            $nombre_archivo_qr = preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigo_qr_cliente) . '.png';
            $ruta_qr_cliente = $qr_dir . $nombre_archivo_qr;

            if (!file_exists($ruta_qr_cliente)) {
                generarCodigoQR($codigo_qr_cliente, $ruta_qr_cliente);
            }
        }

// Enviar correo solo si el cliente proporcionó un email
$envio_correo = false;
if (!empty($email_cliente)) {
    $nombre_completo = $cliente_data['nombre'] . ' ' . $cliente_data['apellido'];
    
    $envio_correo = enviarCorreoRenovacionInscripcion(
        $conn,
        $email_cliente,
        $nombre_completo,
        $plan['plan_nombre'],
        $fecha_inicio,
        $fecha_fin,
        $precio_pagado,
        $metodo_pago_descripcion,
        $referencia,
        $codigo_qr_cliente,
        $ruta_qr_cliente
    );
    
    if (!$envio_correo) {
        error_log("Error al enviar correo a: " . $email_cliente . ' | ' . obtenerUltimoErrorCorreoInscripciones());
        // Agregar advertencia visible
        $_SESSION['warning_correo'] = "No se pudo enviar el correo electrónico, pero la inscripción se guardó correctamente.";
    }
}

        // Limpiar la marca de tiempo después de procesar
        unset($_SESSION[$clave_renovacion]);

        // Guardar mensaje en sesión
        if (!empty($email_cliente) && $envio_correo) {
            $_SESSION['mensaje_exito'] = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. Se ha enviado un ticket a su correo electrónico.';
        } elseif (!empty($email_cliente) && !$envio_correo) {
            $_SESSION['mensaje_exito'] = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. No se pudo enviar el correo electrónico.';
        } else {
            $_SESSION['mensaje_exito'] = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. No se envió correo porque el cliente no tiene email registrado.';
        }

        header('Location: inscripciones.php');
        exit;
        
    } catch (Exception $e) {
        if (isset($conn)) $conn->rollback();
        if (isset($inscripcion_id)) {
            unset($_SESSION['last_renewal_' . $inscripcion_id]);
        }
        $_SESSION['error'] = $e->getMessage();
        header('Location: inscripciones.php');
        exit;
    }
}

// Cancelar inscripción
if (isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $id = $_GET['cancelar'];
    
    // Verificar si ya se procesó esta cancelación
    if (isset($_SESSION['last_cancel_' . $id]) && $_SESSION['last_cancel_' . $id] > time() - 5) {
        $_SESSION['error'] = 'Ya se está procesando esta cancelación';
        header('Location: inscripciones.php');
        exit;
    }
    $_SESSION['last_cancel_' . $id] = time();
    
    try {
        // Obtener el cliente_id antes de cancelar
        $stmt = $conn->prepare("SELECT cliente_id FROM inscripciones WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $inscripcion = $result->fetch_assoc();
        $cliente_id = $inscripcion['cliente_id'];
        
        $stmt = $conn->prepare("UPDATE inscripciones SET estado = 'cancelada' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Registrar cancelación en historial_pagos
        $stmt = $conn->prepare("INSERT INTO historial_pagos (inscripcion_id, cliente_id, monto, fecha_pago, metodo_pago, referencia, periodo_inicio, periodo_fin, plan_nombre, usuario_id) VALUES (?, ?, 0, NOW(), NULL, NULL, NULL, NULL, 'CANCELACION', ?)");
        $stmt->bind_param("iii", $id, $cliente_id, $usuario_id);
        $stmt->execute();
        
        $_SESSION['mensaje_exito'] = 'Inscripción cancelada exitosamente';
        
        unset($_SESSION['last_cancel_' . $id]);
        
        header('Location: inscripciones.php');
        exit;
        
    } catch (Exception $e) {
        unset($_SESSION['last_cancel_' . $id]);
        $_SESSION['error'] = 'Error al cancelar la inscripción: ' . $e->getMessage();
        header('Location: inscripciones.php');
        exit;
    }
}

// Actualizar estados de inscripciones vencidas
$update_vencidas = "UPDATE inscripciones i 
                    INNER JOIN planes p ON i.plan_id = p.id 
                    SET i.estado = 'vencida' 
                    WHERE i.estado = 'activa' 
                    AND i.fecha_fin IS NOT NULL 
                    AND i.fecha_fin < CURDATE()";
$conn->query($update_vencidas);

// Obtener listado de inscripciones
$search = isset($_GET['search']) ? $_GET['search'] : '';
$estado = isset($_GET['estado']) ? $_GET['estado'] : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$sort = isset($_GET['sort']) ? $_GET['sort'] : 'id';
$order = isset($_GET['order']) ? $_GET['order'] : 'DESC';
$limit = 10;
$offset = ($page - 1) * $limit;

// Mapeo de columnas para ordenamiento
$sort_columns = [
    'cliente' => 'c.nombre',
    'telefono' => 'c.telefono',
    'plan' => 'p.nombre',
    'fecha_inicio' => 'i.fecha_inicio',
    'fecha_fin' => 'i.fecha_fin',
    'precio' => 'i.precio_pagado',
    'estado' => 'i.estado'
];

$order_by = isset($sort_columns[$sort]) ? $sort_columns[$sort] : 'i.id';
$order_dir = ($order == 'ASC') ? 'ASC' : 'DESC';

$query = "SELECT i.*, c.nombre as cliente_nombre, c.apellido as cliente_apellido, c.telefono as cliente_telefono,
          c.codigo_qr as cliente_codigo_qr, c.estado as cliente_estado,
          p.nombre as plan_nombre, p.duracion_dias
          FROM inscripciones i 
          INNER JOIN clientes c ON i.cliente_id = c.id 
          INNER JOIN planes p ON i.plan_id = p.id 
          WHERE 1=1";
$count_query = "SELECT COUNT(*) as total FROM inscripciones i 
                INNER JOIN clientes c ON i.cliente_id = c.id 
                INNER JOIN planes p ON i.plan_id = p.id 
                WHERE 1=1";
$params = [];
$types = "";

if (!empty($search)) {
    $query .= " AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)";
    $count_query .= " AND (c.nombre LIKE ? OR c.apellido LIKE ? OR c.telefono LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= "sss";
}

if (!empty($estado)) {
    $query .= " AND i.estado = ?";
    $count_query .= " AND i.estado = ?";
    $params[] = $estado;
    $types .= "s";
}

$query .= " ORDER BY $order_by $order_dir LIMIT ? OFFSET ?";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($query);
if (!empty($params)) {
    $bind_params = array_values($params);
    $stmt->bind_param($types, ...$bind_params);
}
$stmt->execute();
$result = $stmt->get_result();
$inscripciones = $result->fetch_all(MYSQLI_ASSOC);

// Obtener total de registros para paginación
$count_params = array_slice($params, 0, count($params) - 2);
$count_types = substr($types, 0, -2);
$stmt_count = $conn->prepare($count_query);
if (!empty($count_params)) {
    $bind_count_params = array_values($count_params);
    $stmt_count->bind_param($count_types, ...$bind_count_params);
}
$stmt_count->execute();
$total_result = $stmt_count->get_result();
$total_rows = $total_result->fetch_assoc()['total'];
$total_pages = ceil($total_rows / $limit);

// Obtener planes activos
$result = $conn->query("SELECT * FROM planes WHERE estado = 'activo' ORDER BY duracion_dias ASC");
$planes = $result->fetch_all(MYSQLI_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inscripciones - Sistema Gimnasio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="css/inscripciones.css?v=<?php echo file_exists(__DIR__ . '/css/inscripciones.css') ? filemtime(__DIR__ . '/css/inscripciones.css') : time(); ?>">
    <style>
        .point-payment-help {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-top: 8px;
            padding: 9px 10px;
            border: 1px solid #bfdbfe;
            border-radius: 9px;
            background: #eff6ff;
            color: #1e40af;
            font-size: .76rem;
            line-height: 1.4;
        }

        .point-payment-help i {
            margin-top: 2px;
        }

        .point-order-card {
            margin: 14px 0 8px;
            padding: 10px 12px;
            border: 1px solid #dbeafe;
            border-radius: 9px;
            background: #f8fafc;
            color: #1e3a8a;
            font-size: .75rem;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .point-status-live {
            color: #64748b;
            font-size: .82rem;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <header class="page-header">
            <div>
                <h1>Gestión de Inscripciones</h1>
                <p>Administra clientes, planes, renovaciones y pagos registrados.</p>
            </div>
            <button class="btn-custom-primary page-primary-action" data-bs-toggle="modal" data-bs-target="#modalNuevoCliente">
                <i class="fas fa-user-plus"></i>
                <span>Nueva inscripción</span>
            </button>
        </header>
        
        <?php if(isset($_SESSION['mensaje_exito'])): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['mensaje_exito'];
            unset($_SESSION['mensaje_exito']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if(isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?php 
            echo $_SESSION['error'];
            unset($_SESSION['error']);
            ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <div class="card-custom">
            <div class="card-header-custom card-header-between">
                <div class="card-header-title">
                    <i class="fas fa-filter"></i>
                    <span>Filtros de búsqueda</span>
                </div>
                <button type="button" class="btn-header-clear" id="limpiarFiltros">
                    <i class="fas fa-rotate-left"></i>
                    <span>Limpiar</span>
                </button>
            </div>
            <div class="card-body-custom">
                <div class="filters-grid">
                    <div class="filter-field filter-search">
                        <label class="form-label" for="searchInput">Buscar</label>
                        <div class="input-icon-wrap">
                            <i class="fas fa-magnifying-glass"></i>
                            <input type="text" class="form-control" id="searchInput" placeholder="Nombre, apellido o teléfono..." value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                    </div>
                    <div class="filter-field">
                        <label class="form-label" for="estadoSelect">Estado</label>
                        <select class="form-select" id="estadoSelect">
                            <option value="">Todos</option>
                            <option value="activa" <?php echo $estado == 'activa' ? 'selected' : ''; ?>>Activa</option>
                            <option value="vencida" <?php echo $estado == 'vencida' ? 'selected' : ''; ?>>Vencida</option>
                            <option value="cancelada" <?php echo $estado == 'cancelada' ? 'selected' : ''; ?>>Cancelada</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="card-custom">
            <div class="card-header-custom card-header-between">
                <div class="card-header-title">
                    <i class="fas fa-list"></i>
                    <span>Listado de inscripciones</span>
                </div>
                <span class="records-count"><?php echo number_format($total_rows); ?> <?php echo $total_rows == 1 ? 'registro' : 'registros'; ?></span>
            </div>
            <div class="card-body-custom table-card-body">
                <div class="table-responsive-custom">
                    <table class="tabla-simple">
                        <thead>
                            <tr>
                                <th><a href="?sort=cliente&order=<?php echo ($sort == 'cliente' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Cliente <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=telefono&order=<?php echo ($sort == 'telefono' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Teléfono <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=plan&order=<?php echo ($sort == 'plan' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Plan <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=fecha_inicio&order=<?php echo ($sort == 'fecha_inicio' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Fecha Inicio <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=fecha_fin&order=<?php echo ($sort == 'fecha_fin' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Fecha Fin <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=precio&order=<?php echo ($sort == 'precio' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">$ Precio <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=estado&order=<?php echo ($sort == 'estado' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Estado <i class="fas fa-sort"></i></a></th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($inscripciones as $ins): 
                                // ========== LÓGICA PARA EL BOTÓN RENOVAR ==========
                                $renovar_disabled = false;
                                $renovar_title = "Renovar inscripción";
                                $mensaje_renovar = "";
                                
                                // Caso 1: Cliente inactivo - NO se puede renovar bajo ninguna circunstancia
                                if(($ins['cliente_estado'] ?? '') !== 'activo') {
                                    $renovar_disabled = true;
                                    $renovar_title = "Socio inactivo: actívelo primero para poder renovar";
                                    $mensaje_renovar = $renovar_title;
                                }
                                // Caso 2: Inscripción cancelada - NO se puede renovar
                                elseif($ins['estado'] == 'cancelada') {
                                    $renovar_disabled = true;
                                    $renovar_title = "No se puede renovar una inscripción cancelada";
                                    $mensaje_renovar = $renovar_title;
                                } 
                                // Caso 3: Plan sin vencimiento (duracion_dias = 0) - NO se puede renovar
                                elseif($ins['duracion_dias'] == 0) {
                                    $renovar_disabled = true;
                                    $renovar_title = "Este plan no requiere renovación (sin vencimiento)";
                                    $mensaje_renovar = $renovar_title;
                                }
                                // Caso 4: Planes de 1 día (Visita o cualquier plan de 1 día)
                                elseif($ins['duracion_dias'] == 1) {
                                    if($ins['estado'] == 'activa') {
                                        // Verificar si la fecha de hoy es igual o menor a la fecha_fin
                                        $fecha_fin_plan = new DateTime($ins['fecha_fin']);
                                        $hoy = new DateTime();
                                        $hoy->setTime(0, 0, 0);
                                        $fecha_fin_plan->setTime(0, 0, 0);
                                        
                                        if($hoy <= $fecha_fin_plan) {
                                            $renovar_disabled = true;
                                            $renovar_title = "No se puede renovar un plan de 1 día mientras está activo. Espere hasta mañana.";
                                            $mensaje_renovar = $renovar_title;
                                        } else {
                                            $renovar_disabled = false;
                                            $renovar_title = "Renovar plan (día siguiente)";
                                        }
                                    } elseif($ins['estado'] == 'vencida') {
                                        $renovar_disabled = false;
                                        $renovar_title = "Renovar plan (vencido)";
                                    }
                                }
                                // Caso 5: Planes normales (duración > 1 día)
                                else {
                                    if($ins['estado'] == 'vencida') {
                                        $renovar_disabled = false;
                                        $renovar_title = "Renovar inscripción (vencida)";
                                    } else {
                                        $renovar_disabled = true;
                                        $renovar_title = "No se puede renovar mientras la inscripción está activa. Espere a que venza.";
                                        $mensaje_renovar = $renovar_title;
                                    }
                                }
                            $nombre_cliente_qr = trim($ins['cliente_nombre'] . ' ' . $ins['cliente_apellido']);
                            $codigo_cliente_qr = trim((string)($ins['cliente_codigo_qr'] ?? ''));
                            $archivo_cliente_qr = $codigo_cliente_qr !== ''
                                ? 'qrcodes/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigo_cliente_qr) . '.png'
                                : '';
                            ?>
                            <tr>
                                <td data-label="Cliente"><strong><?php echo htmlspecialchars($ins['cliente_nombre'] . ' ' . $ins['cliente_apellido']); ?></strong></td>
                                <td data-label="Teléfono"><?php echo htmlspecialchars($ins['cliente_telefono']); ?></td>
                                <td data-label="Plan">
                                    <?php if($ins['duracion_dias'] == 1): ?>
                                        <span class="badge-visita"><?php echo htmlspecialchars($ins['plan_nombre']); ?></span>
                                    <?php else: ?>
                                        <?php echo htmlspecialchars($ins['plan_nombre']); ?>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Fecha inicio"><?php echo date('d/m/Y', strtotime($ins['fecha_inicio'])); ?></td>
                                <td data-label="Fecha fin">
                                    <?php 
                                    if($ins['duracion_dias'] == 1) {
                                        echo '<span class="text-warning">' . date('d/m/Y', strtotime($ins['fecha_fin'])) . ' (Solo hoy)</span>';
                                    } else {
                                        echo $ins['duracion_dias'] > 0 ? date('d/m/Y', strtotime($ins['fecha_fin'])) : 'Sin vencimiento';
                                    }
                                    ?>
                                </td>
                                <td data-label="Precio"><strong class="price-value">$<?php echo number_format($ins['precio_pagado'], 2); ?></strong></td>
                                <td data-label="Estado">
                                    <?php if($ins['estado'] == 'activa'): ?>
                                        <span class="badge-activa">Activa</span>
                                    <?php elseif($ins['estado'] == 'vencida'): ?>
                                        <span class="badge-vencida">Vencida</span>
                                    <?php else: ?>
                                        <span class="badge-cancelada">Cancelada</span>
                                    <?php endif; ?>
                                </td>
                                <td class="acciones-cell" data-label="Acciones">
                                    <div class="acciones-container">
                                        <button class="btn-accion btn-detalle" onclick="verDetalle(<?php echo $ins['id']; ?>)" title="Ver detalles completos">
                                            <i class="fas fa-eye"></i> <span>Ver</span>
                                        </button>
                                        
                                        <button
                                            type="button"
                                            class="btn-accion btn-qr"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalQr"
                                            data-cliente="<?php echo htmlspecialchars($nombre_cliente_qr, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-codigo="<?php echo htmlspecialchars($codigo_cliente_qr, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-ruta="<?php echo htmlspecialchars($archivo_cliente_qr, ENT_QUOTES, 'UTF-8'); ?>"
                                            title="Ver código QR"
                                            <?php echo $codigo_cliente_qr === '' ? 'disabled' : ''; ?>
                                        >
                                            <i class="fas fa-qrcode"></i> <span>QR</span>
                                        </button>
                                        
                                        <button class="btn-accion btn-renovar" 
                                                onclick="abrirRenovar(<?php echo $ins['id']; ?>, <?php echo $ins['cliente_id']; ?>, <?php echo $renovar_disabled ? 'true' : 'false'; ?>, '<?php echo addslashes($mensaje_renovar ?: $renovar_title); ?>')"
                                                <?php echo $renovar_disabled ? 'disabled' : ''; ?>
                                                title="<?php echo $renovar_title; ?>">
                                            <i class="fas fa-sync-alt"></i> <span>Renovar</span>
                                        </button>
                                        
                                        <?php if($ins['estado'] == 'activa'): ?>
                                            <button class="btn-accion btn-cancelar" onclick="cancelarInscripcion(<?php echo $ins['id']; ?>)" title="Cancelar inscripción">
                                                <i class="fas fa-times-circle"></i> <span>Cancelar</span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php if(empty($inscripciones)): ?>
                            <tr>
                                <td colspan="8" class="empty-state">
                                    <i class="fas fa-inbox"></i>
                                    <p class="mt-2">No hay inscripciones registradas</p>
                                </td>
                            </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if($total_pages > 1): ?>
                <div class="pagination">
                    <ul class="pagination">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page-1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>">Anterior</a>
                        </li>
                        <?php for($i = 1; $i <= $total_pages; $i++): ?>
                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>"><?php echo $i; ?></a>
                        </li>
                        <?php endfor; ?>
                        <li class="page-item <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?page=<?php echo $page+1; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>&sort=<?php echo $sort; ?>&order=<?php echo $order; ?>">Siguiente</a>
                        </li>
                    </ul>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </main>
    
    <!-- Modal Nuevo Cliente (MODIFICADO: Reemplazar huella por QR) -->
    <div class="modal fade" id="modalNuevoCliente" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title"><i class="fas fa-user-plus"></i> Nuevo Cliente e Inscripción</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formNuevoCliente" method="POST">
                    <input type="hidden" name="action" value="crear_cliente_inscripcion">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="mp_order_id" value="">
                    <input type="hidden" name="mp_payment_id" value="">
                    <input type="hidden" name="mp_external_reference" value="">
                    <input type="hidden" name="mp_payment_reference_id" value="">
                    <input type="hidden" name="mp_installments" value="1">
                    
                    <div class="modal-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nombre *</label>
                                <input type="text" class="form-control" name="nombre" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Apellido *</label>
                                <input type="text" class="form-control" name="apellido" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Teléfono *</label>
                                <input type="tel" class="form-control" name="telefono" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Email</label>
                                <input type="email" class="form-control" name="email">
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Plan *</label>
                                <select class="form-select" name="plan_id" id="plan_id_nuevo" required onchange="actualizarPrecioNuevo()">
                                    <option value="">Seleccionar plan</option>
                                    <?php foreach($planes as $plan): ?>
                                    <option value="<?php echo $plan['id']; ?>" data-precio="<?php echo $plan['precio']; ?>">
                                        <?php echo htmlspecialchars($plan['nombre'] . ' - $' . number_format($plan['precio'], 2)); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Fecha Inicio *</label>
                                <input type="date" class="form-control" name="fecha_inicio" value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Precio Pagado *</label>
                                <input type="number" class="form-control precio-disabled" name="precio_pagado" id="precio_pagado_nuevo" step="0.01" readonly required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="metodo_pago_nuevo">Método Pago *</label>
                                <select class="form-select" name="metodo_pago" id="metodo_pago_nuevo" required onchange="actualizarAyudaPago(this)">
                                    <option value="efectivo">Efectivo</option>
                                    <option value="tarjeta_debito">Tarjeta de débito · Point</option>
                                    <option value="tarjeta_credito">Tarjeta de crédito · Point</option>
                                    <option value="transferencia">Transferencia</option>
                                </select>
                                <div class="point-payment-help d-none" data-point-help>
                                    <i class="fas fa-credit-card"></i>
                                    <span>El cobro se enviará a la terminal Mercado Pago Point.</span>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Referencia</label>
                            <input type="text" class="form-control" name="referencia" id="referencia_nuevo" placeholder="Número de referencia (opcional)">
                        </div>
                        
                        <!-- Área de información de QR (reemplazo de huella) -->
                        <div class="qr-area">
                            <i class="fas fa-qrcode"></i>
                            <div class="mt-2">Código QR para el Socio</div>
                            <div class="small text-muted">Se generará automáticamente al registrar</div>
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-primary" id="btnGuardarNuevo">Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal Renovar -->
    <div class="modal fade" id="modalRenovar" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title"><i class="fas fa-sync-alt"></i> Renovar Inscripción</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form id="formRenovar" method="POST">
                    <input type="hidden" name="action" value="renovar_inscripcion">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    <input type="hidden" name="inscripcion_id" id="renovar_inscripcion_id">
                    <input type="hidden" name="cliente_id" id="renovar_cliente_id">
                    <input type="hidden" name="mp_order_id" value="">
                    <input type="hidden" name="mp_payment_id" value="">
                    <input type="hidden" name="mp_external_reference" value="">
                    <input type="hidden" name="mp_payment_reference_id" value="">
                    <input type="hidden" name="mp_installments" value="1">
                    
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Cliente</label>
                            <input type="text" class="form-control" id="renovar_cliente_nombre" readonly disabled>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Plan *</label>
                            <select class="form-select" name="plan_id" id="renovar_plan_id" required onchange="actualizarPrecioRenovar()">
                                <option value="">Seleccionar plan</option>
                                <?php foreach($planes as $plan): ?>
                                <option value="<?php echo $plan['id']; ?>" data-precio="<?php echo $plan['precio']; ?>">
                                    <?php echo htmlspecialchars($plan['nombre'] . ' - $' . number_format($plan['precio'], 2)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Fecha Inicio *</label>
                            <input type="date" class="form-control" name="fecha_inicio" id="renovar_fecha_inicio" required>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Precio Pagado *</label>
                            <input type="number" class="form-control precio-readonly" name="precio_pagado" id="renovar_precio_pagado" step="0.01" readonly required>
                            <small class="text-muted">El precio se carga automáticamente según el plan seleccionado</small>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label" for="metodo_pago_renovar">Método Pago *</label>
                            <select class="form-select" name="metodo_pago" id="metodo_pago_renovar" required onchange="actualizarAyudaPago(this)">
                                <option value="efectivo">Efectivo</option>
                                <option value="tarjeta_debito">Tarjeta de débito · Point</option>
                                <option value="tarjeta_credito">Tarjeta de crédito · Point</option>
                                <option value="transferencia">Transferencia</option>
                            </select>
                            <div class="point-payment-help d-none" data-point-help>
                                <i class="fas fa-credit-card"></i>
                                <span>En crédito, las mensualidades disponibles se eligen en la terminal.</span>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Referencia</label>
                            <input type="text" class="form-control" name="referencia" id="referencia_renovar" placeholder="Número de referencia (opcional)">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
                        <button type="submit" class="btn btn-success" id="btnRenovar">Renovar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Modal QR -->
    <div class="modal fade" id="modalQr" tabindex="-1" aria-labelledby="modalQrTitulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered qr-modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title" id="modalQrTitulo">
                        <i class="fas fa-qrcode"></i> Código QR del socio
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>
                <div class="modal-body qr-modal-body">
                    <div class="qr-modal-member" id="qrModalCliente">Socio</div>
                    <div class="qr-modal-frame">
                        <img id="qrModalImage" class="qr-modal-image" alt="Código QR del socio">
                        <div id="qrModalFallback" class="qr-modal-fallback d-none">
                            <i class="fas fa-triangle-exclamation"></i>
                            <span>No se encontró la imagen del QR.</span>
                        </div>
                    </div>
                    <div class="qr-modal-code-label">Código</div>
                    <code class="qr-modal-code" id="qrModalCodigo">—</code>
                </div>
                <div class="modal-footer qr-modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" id="btnImprimirQr">
                        <i class="fas fa-print"></i> Imprimir
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal Detalle -->
    <div class="modal fade" id="modalDetalle" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title"><i class="fas fa-info-circle"></i> Detalle de Inscripción e Historial de Pagos</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="detalleContenido">
                    <div class="text-center">
                        <div class="spinner-border text-primary"></div>
                        <p>Cargando...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Mantener un solo scroll cuando cualquier modal esté abierto.
        // El documento se bloquea y Bootstrap conserva únicamente el scroll del modal.
        $(document).on('show.bs.modal', '.modal', function() {
            document.documentElement.classList.add('modal-visible');
        });

        $(document).on('hidden.bs.modal', '.modal', function() {
            if (!document.querySelector('.modal.show')) {
                document.documentElement.classList.remove('modal-visible');
            }
        });

        let formularioEnviando = false;

        const modalQrElement = document.getElementById('modalQr');
        const qrModalImage = document.getElementById('qrModalImage');
        const qrModalFallback = document.getElementById('qrModalFallback');
        const qrModalCliente = document.getElementById('qrModalCliente');
        const qrModalCodigo = document.getElementById('qrModalCodigo');
        const btnImprimirQr = document.getElementById('btnImprimirQr');

        function escaparHtml(valor) {
            return String(valor ?? '').replace(/[&<>'"]/g, function(caracter) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    "'": '&#039;',
                    '"': '&quot;'
                }[caracter];
            });
        }

        if (modalQrElement) {
            modalQrElement.addEventListener('show.bs.modal', function(event) {
                const boton = event.relatedTarget;
                const cliente = boton ? boton.getAttribute('data-cliente') : '';
                const codigo = boton ? boton.getAttribute('data-codigo') : '';
                const ruta = boton ? boton.getAttribute('data-ruta') : '';

                qrModalCliente.textContent = cliente || 'Socio';
                qrModalCodigo.textContent = codigo || 'Sin código QR';
                qrModalFallback.classList.add('d-none');
                qrModalImage.classList.remove('d-none');

                if (ruta) {
                    qrModalImage.alt = 'Código QR de ' + (cliente || 'socio');
                    qrModalImage.src = ruta + '?v=' + Date.now();
                } else {
                    qrModalImage.removeAttribute('src');
                    qrModalImage.classList.add('d-none');
                    qrModalFallback.classList.remove('d-none');
                }
            });

            qrModalImage.addEventListener('error', function() {
                qrModalImage.classList.add('d-none');
                qrModalFallback.classList.remove('d-none');
            });
        }

        if (btnImprimirQr) {
            btnImprimirQr.addEventListener('click', function() {
                const ruta = qrModalImage.getAttribute('src') || '';
                const cliente = qrModalCliente.textContent || 'Socio';
                const codigo = qrModalCodigo.textContent || '';

                if (!ruta || qrModalImage.classList.contains('d-none')) {
                    Swal.fire('QR no disponible', 'No se encontró una imagen para imprimir.', 'warning');
                    return;
                }

                const ventana = window.open('', '_blank', 'width=520,height=650');
                if (!ventana) {
                    Swal.fire('Ventana bloqueada', 'Permite ventanas emergentes para imprimir el QR.', 'info');
                    return;
                }

                ventana.document.write(`
                    <!doctype html>
                    <html lang="es">
                    <head>
                        <meta charset="utf-8">
                        <title>QR - ${escaparHtml(cliente)}</title>
                        <style>
                            body{margin:0;padding:32px;font-family:Arial,sans-serif;text-align:center;color:#172033}
                            h1{margin:0 0 8px;font-size:24px}
                            p{margin:0 0 24px;color:#667085}
                            img{display:block;width:320px;max-width:100%;height:auto;margin:0 auto 20px}
                            code{display:inline-block;padding:8px 12px;border:1px solid #dfe5ee;border-radius:6px;background:#f8fafc;font-size:15px}
                            @media print{body{padding:10mm}}
                        </style>
                    </head>
                    <body>
                        <h1>${escaparHtml(cliente)}</h1>
                        <p>Código de acceso del socio</p>
                        <img src="${escaparHtml(ruta)}" alt="Código QR">
                        <code>${escaparHtml(codigo)}</code>
                        <script>
                            window.addEventListener('load', function(){
                                window.print();
                                window.onafterprint = function(){ window.close(); };
                            });
                        <\/script>
                    </body>
                    </html>
                `);
                ventana.document.close();
            });
        }
        
        function actualizarPrecioNuevo() {
            const planSelect = document.getElementById('plan_id_nuevo');
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            const precio = selectedOption.getAttribute('data-precio');
            if (precio) {
                document.getElementById('precio_pagado_nuevo').value = precio;
            }
        }
        
        function actualizarPrecioRenovar() {
            const planSelect = document.getElementById('renovar_plan_id');
            const selectedOption = planSelect.options[planSelect.selectedIndex];
            const precio = selectedOption.getAttribute('data-precio');
            if (precio) {
                document.getElementById('renovar_precio_pagado').value = precio;
            } else {
                document.getElementById('renovar_precio_pagado').value = '';
            }
        }
        
        // Eliminada la función capturarHuella() ya que no se usa más
        
        function actualizarAyudaPago(select) {
            const contenedor = select.closest('.mb-3') || select.parentElement;
            const ayuda = contenedor ? contenedor.querySelector('[data-point-help]') : null;
            if (!ayuda) return;

            const esTarjeta = select.value === 'tarjeta_debito' || select.value === 'tarjeta_credito';
            ayuda.classList.toggle('d-none', !esTarjeta);

            if (select.value === 'tarjeta_credito') {
                ayuda.querySelector('span').textContent =
                    'La terminal mostrará las mensualidades y MSI disponibles para esa tarjeta y monto.';
            } else if (select.value === 'tarjeta_debito') {
                ayuda.querySelector('span').textContent =
                    'El cobro se enviará como tarjeta de débito a la terminal Point.';
            }
        }

        function esMetodoPoint(metodo) {
            return metodo === 'tarjeta_debito' || metodo === 'tarjeta_credito';
        }

        function sleep(ms) {
            return new Promise(function(resolve) {
                setTimeout(resolve, ms);
            });
        }

        async function fetchJsonPoint(url, options) {
            const response = await fetch(url, options || {});
            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error('El servidor devolvió una respuesta no válida.');
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'Ocurrió un error al comunicarse con Mercado Pago.');
            }

            return data;
        }

        async function crearOrdenInscripcionPoint(form, operation) {
            const formData = new FormData(form);
            const metodo = String(formData.get('metodo_pago') || '');
            const paymentType = metodo === 'tarjeta_credito' ? 'credit_card' : 'debit_card';

            return fetchJsonPoint('api/mercadopago/crear_orden_inscripcion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    operation: operation,
                    plan_id: Number(formData.get('plan_id') || 0),
                    total: Number(formData.get('precio_pagado') || 0),
                    payment_type: paymentType,
                    inscripcion_id: Number(formData.get('inscripcion_id') || 0),
                    fecha_inicio: String(formData.get('fecha_inicio') || ''),
                    nombre: String(formData.get('nombre') || ''),
                    apellido: String(formData.get('apellido') || ''),
                    telefono: String(formData.get('telefono') || ''),
                    email: String(formData.get('email') || '')
                })
            });
        }

        async function consultarOrdenPoint(orderId) {
            return fetchJsonPoint('api/mercadopago/consultar_orden_inscripcion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: orderId })
            });
        }

        async function cancelarOrdenPoint(orderId) {
            return fetchJsonPoint('api/mercadopago/cancelar_orden_inscripcion.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ order_id: orderId })
            });
        }

        async function esperarPagoPoint(order) {
            let settled = false;
            let polling = true;
            const startedAt = Date.now();
            const maxWaitMs = 190000;

            return new Promise(function(resolve, reject) {
                function finish(value) {
                    if (settled) return;
                    settled = true;
                    polling = false;
                    Swal.close();
                    resolve(value);
                }

                function fail(error) {
                    if (settled) return;
                    settled = true;
                    polling = false;
                    Swal.close();
                    reject(error);
                }

                Swal.fire({
                    title: 'Esperando pago en terminal',
                    html: `
                        <div>Completa el cobro en la terminal Point. No cierres esta ventana.</div>
                        <div class="point-order-card">Orden: ${escaparHtml(order.order_id)}</div>
                        <div id="mp-status-live" class="point-status-live">Estado: creada</div>
                    `,
                    showConfirmButton: false,
                    showCancelButton: true,
                    cancelButtonText: 'Cancelar cobro',
                    cancelButtonColor: '#dc2626',
                    allowOutsideClick: false,
                    allowEscapeKey: false,
                    didOpen: async function() {
                        while (polling && !settled) {
                            try {
                                const latest = await consultarOrdenPoint(order.order_id);
                                const statusNode = document.getElementById('mp-status-live');

                                if (statusNode) {
                                    statusNode.textContent =
                                        'Orden: ' + (latest.order_status || '-') +
                                        ' · Pago: ' + (latest.payment_status || '-');
                                }

                                if (latest.paid) {
                                    finish(latest);
                                    return;
                                }

                                if (latest.final_failure) {
                                    fail(new Error(
                                        'El pago terminó en estado ' +
                                        (latest.payment_status || latest.order_status || 'desconocido') + '.'
                                    ));
                                    return;
                                }
                            } catch (error) {
                                console.error('Consulta Point:', error);
                            }

                            if (Date.now() - startedAt >= maxWaitMs) {
                                fail(new Error('Terminó el tiempo de espera para completar el pago.'));
                                return;
                            }

                            await sleep(2200);
                        }
                    }
                }).then(async function(result) {
                    if (settled || result.isConfirmed) return;

                    polling = false;
                    try {
                        const canceled = await cancelarOrdenPoint(order.order_id);
                        if (canceled.requires_terminal) {
                            await Swal.fire({
                                icon: 'warning',
                                title: 'Cancela en la terminal',
                                text: canceled.message || 'La orden debe cancelarse desde la Point.'
                            });
                        }
                        finish(null);
                    } catch (error) {
                        fail(error);
                    }
                });
            });
        }

        function guardarDatosPointEnFormulario(form, payment) {
            form.elements.mp_order_id.value = payment.order_id || '';
            form.elements.mp_payment_id.value = payment.payment_id || '';
            form.elements.mp_external_reference.value = payment.external_reference || '';
            form.elements.mp_payment_reference_id.value = payment.payment_reference_id || '';
            form.elements.mp_installments.value = Math.max(1, Number(payment.installments || 1));

            const referencia = form.elements.referencia;
            if (referencia) {
                referencia.value = payment.payment_reference_id ||
                    payment.external_reference ||
                    payment.order_id || '';
            }
        }

        function limpiarDatosPoint(form) {
            ['mp_order_id', 'mp_payment_id', 'mp_external_reference', 'mp_payment_reference_id'].forEach(function(name) {
                if (form.elements[name]) form.elements[name].value = '';
            });
            if (form.elements.mp_installments) form.elements.mp_installments.value = '1';
        }

        async function procesarFormularioInscripcion(event, form, operation, button, loadingText, normalText) {
            event.preventDefault();

            if (formularioEnviando) {
                Swal.fire('Procesando', 'Ya se está procesando la solicitud.', 'warning');
                return false;
            }

            if (!form.checkValidity()) {
                form.reportValidity();
                return false;
            }

            const metodo = String(form.elements.metodo_pago.value || '');
            const total = Number(form.elements.precio_pagado.value || 0);

            if (!Number.isFinite(total) || total <= 0) {
                Swal.fire('Precio inválido', 'Selecciona un plan válido antes de continuar.', 'warning');
                return false;
            }

            formularioEnviando = true;
            button.disabled = true;
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + loadingText;

            try {
                limpiarDatosPoint(form);

                if (esMetodoPoint(metodo)) {
                    const esCredito = metodo === 'tarjeta_credito';
                    const confirmation = await Swal.fire({
                        icon: 'question',
                        title: 'Enviar cobro a terminal',
                        html: `
                            <div style="font-size:.95rem">Total a cobrar</div>
                            <div style="font-size:1.75rem;font-weight:800;color:#1e3a8a;margin:4px 0 12px">$${total.toFixed(2)}</div>
                            <div style="font-size:.84rem;color:#64748b">
                                ${esCredito
                                    ? 'La terminal mostrará las mensualidades y MSI disponibles.'
                                    : 'El cobro se procesará como tarjeta de débito.'}
                            </div>
                        `,
                        showCancelButton: true,
                        confirmButtonText: 'Enviar a Point',
                        cancelButtonText: 'Cancelar',
                        confirmButtonColor: '#1e3a8a',
                        cancelButtonColor: '#6b7280'
                    });

                    if (!confirmation.isConfirmed) {
                        formularioEnviando = false;
                        button.disabled = false;
                        button.innerHTML = normalText;
                        return false;
                    }

                    Swal.fire({
                        title: 'Creando orden',
                        text: 'Enviando el cobro a la terminal Point.',
                        allowOutsideClick: false,
                        didOpen: function() { Swal.showLoading(); }
                    });

                    const order = await crearOrdenInscripcionPoint(form, operation);
                    const payment = await esperarPagoPoint(order);

                    if (!payment) {
                        formularioEnviando = false;
                        button.disabled = false;
                        button.innerHTML = normalText;
                        return false;
                    }

                    guardarDatosPointEnFormulario(form, payment);

                    const mensualidades = Math.max(1, Number(payment.installments || 1));
                    const mensajePago = metodo === 'tarjeta_credito' && mensualidades > 1
                        ? 'Pago aprobado en ' + mensualidades + ' mensualidades. Mercado Pago no informa aquí si fueron sin intereses.'
                        : 'Pago aprobado correctamente en la terminal.';

                    await Swal.fire({
                        icon: 'success',
                        title: 'Pago aprobado',
                        text: mensajePago,
                        timer: 1700,
                        showConfirmButton: false
                    });
                }

                // Envío nativo: evita ejecutar otra vez este mismo listener.
                HTMLFormElement.prototype.submit.call(form);
                return true;
            } catch (error) {
                formularioEnviando = false;
                button.disabled = false;
                button.innerHTML = normalText;

                await Swal.fire({
                    icon: 'error',
                    title: 'No se pudo procesar el pago',
                    text: error.message || 'Ocurrió un error inesperado.',
                    confirmButtonColor: '#1e3a8a'
                });

                return false;
            }
        }

        $('#formNuevoCliente').on('submit', function(e) {
            return procesarFormularioInscripcion(
                e,
                this,
                'new',
                document.getElementById('btnGuardarNuevo'),
                'Guardando...',
                'Guardar'
            );
        });

        $('#formRenovar').on('submit', function(e) {
            return procesarFormularioInscripcion(
                e,
                this,
                'renewal',
                document.getElementById('btnRenovar'),
                'Renovando...',
                'Renovar'
            );
        });

        $('#modalNuevoCliente').on('hidden.bs.modal', function() {
            formularioEnviando = false;
            limpiarDatosPoint(document.getElementById('formNuevoCliente'));
            $('#btnGuardarNuevo').prop('disabled', false).html('Guardar');
        });
        
        $('#modalRenovar').on('hidden.bs.modal', function() {
            formularioEnviando = false;
            limpiarDatosPoint(document.getElementById('formRenovar'));
            $('#btnRenovar').prop('disabled', false).html('Renovar');
            $('#renovar_plan_id').val('');
            $('#renovar_precio_pagado').val('');
        });
        
        function abrirRenovar(inscripcionId, clienteId, isDisabled, message) {
            if (isDisabled === true || isDisabled === 'true') {
                Swal.fire({
                    title: 'No se puede renovar',
                    text: message,
                    icon: 'info',
                    confirmButtonColor: '#1e3a8a'
                });
                return;
            }
            
            $.ajax({
                url: 'includes/obtener_cliente.php',
                method: 'POST',
                data: { id: clienteId },
                dataType: 'json',
                success: function(data) {
                    if (data.success) {
                        document.getElementById('renovar_inscripcion_id').value = inscripcionId;
                        document.getElementById('renovar_cliente_id').value = clienteId;
                        document.getElementById('renovar_cliente_nombre').value = data.nombre;
                        document.getElementById('renovar_fecha_inicio').value = new Date().toISOString().split('T')[0];
                        $('#modalRenovar').modal('show');
                    } else {
                        Swal.fire('Error', 'No se pudo obtener los datos del cliente', 'error');
                    }
                },
                error: function() {
                    Swal.fire('Error', 'Error al cargar los datos del cliente', 'error');
                }
            });
        }
        
        let currentPageHistorial = 1;
        let currentSortHistorial = 'fecha_pago';
        let currentOrderHistorial = 'DESC';
        let currentSearchHistorial = '';
        
        function verDetalle(id) {
            currentPageHistorial = 1;
            currentSortHistorial = 'fecha_pago';
            currentOrderHistorial = 'DESC';
            currentSearchHistorial = '';
            
            $('#modalDetalle').modal('show');
            $('#detalleContenido').html('<div class="text-center"><div class="spinner-border text-primary"></div><p>Cargando...</p></div>');
            
            $.ajax({
                url: 'includes/inscripcion_detalle.php',
                method: 'POST',
                data: { id: id },
                success: function(response) {
                    $('#detalleContenido').html(response);
                    inicializarEventosHistorial(id);
                },
                error: function() {
                    $('#detalleContenido').html('<div class="alert alert-danger">Error al cargar los detalles</div>');
                }
            });
        }

        function inicializarEventosHistorial(id) {
            window.currentPageHistorial = 1;
            window.currentSortHistorial = 'fecha_pago';
            window.currentOrderHistorial = 'DESC';
            window.currentSearchHistorial = '';
            window.inscripcionIdActual = id;
            window.timeoutHistorial = null;
            
            window.cargarHistorialPagos = function() {
                $('#tablaHistorialBody').html('<tr><td colspan="6" class="text-center"><div class="spinner-border text-primary"></div><p class="mt-2">Cargando...</p></td></tr>');
                
                $.ajax({
                    url: 'includes/inscripcion_detalle_historial.php',
                    method: 'POST',
                    data: {
                        id: window.inscripcionIdActual,
                        page: window.currentPageHistorial,
                        sort: window.currentSortHistorial,
                        order: window.currentOrderHistorial,
                        search: window.currentSearchHistorial
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.error) {
                            $('#tablaHistorialBody').html('<tr><td colspan="6" class="text-center text-danger">' + response.error + '</td></tr>');
                            return;
                        }
                        $('#tablaHistorialBody').html(response.tbody);
                        $('#paginacionHistorial').html(response.pagination);
                        $('#totalPagadoSpan').html('$' + response.total_pagado);
                    },
                    error: function() {
                        $('#tablaHistorialBody').html('<tr><td colspan="6" class="text-center text-danger">Error al cargar los datos</td></tr>');
                    }
                });
            };
            
            window.buscarHistorial = function() {
                window.currentSearchHistorial = $('#searchHistorialInput').val();
                window.currentPageHistorial = 1;
                window.cargarHistorialPagos();
            };
            
            window.ordenarHistorial = function(columna) {
                if (window.currentSortHistorial === columna) {
                    window.currentOrderHistorial = window.currentOrderHistorial === 'ASC' ? 'DESC' : 'ASC';
                } else {
                    window.currentSortHistorial = columna;
                    window.currentOrderHistorial = 'ASC';
                }
                window.currentPageHistorial = 1;
                window.cargarHistorialPagos();
            };
            
            window.cambiarPaginaHistorial = function(page) {
                window.currentPageHistorial = page;
                window.cargarHistorialPagos();
            };
            
            const searchInput = document.getElementById('searchHistorialInput');
            if (searchInput) {
                searchInput.addEventListener('input', function() {
                    clearTimeout(window.timeoutHistorial);
                    window.timeoutHistorial = setTimeout(function() {
                        window.buscarHistorial();
                    }, 500);
                });
            }
            
            window.cargarHistorialPagos();
        }
        
        function cancelarInscripcion(id) {
            Swal.fire({
                title: '¿Cancelar inscripción?',
                text: "Esta acción no se puede deshacer",
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Sí, cancelar',
                cancelButtonText: 'No'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = '?cancelar=' + id;
                }
            });
        }
        
        let timeoutBusqueda;
        $('#searchInput').on('input', function() {
            clearTimeout(timeoutBusqueda);
            timeoutBusqueda = setTimeout(function() {
                const search = $('#searchInput').val();
                const estado = $('#estadoSelect').val();
                window.location.href = '?search=' + encodeURIComponent(search) + '&estado=' + encodeURIComponent(estado);
            }, 500);
        });
        
        $('#estadoSelect').on('change', function() {
            const search = $('#searchInput').val();
            const estado = $(this).val();
            window.location.href = '?search=' + encodeURIComponent(search) + '&estado=' + encodeURIComponent(estado);
        });
        
        $('#limpiarFiltros').on('click', function() {
            window.location.href = '?';
        });
        
        <?php if ($abrir_modal_nuevo): ?>
        $(document).ready(function() {
            $('#modalNuevoCliente').modal('show');
            
            const url = new URL(window.location.href);
            if (url.searchParams.has('action')) {
                url.searchParams.delete('action');
                window.history.replaceState({}, document.title, url.pathname + url.search);
            }
        });
        <?php endif; ?>
    </script>
</body>
</html>