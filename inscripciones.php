<?php
declare(strict_types=1);

// Debe cargar primero la sesión, permisos y la sucursal operativa.
require_once __DIR__ . '/includes/auth_guard.php';

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
date_default_timezone_set(
    (string) ($_SESSION['sucursal_zona_horaria'] ?? 'America/Mexico_City')
);

// inscripciones.php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/sucursal_context.php';
require_once __DIR__ . '/includes/qr_helper.php'; // Incluir el helper de QR
require_once __DIR__ . '/includes/correo_inscripciones.php'; // Correos de bienvenida y renovación
require_once __DIR__ . '/includes/documentos_inscripciones.php'; // PDF persistente por inscripción y renovación
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
$usuario_id = (int) $_SESSION['user_id'];
$usuario_nombre = (string) ($_SESSION['user_name'] ?? '');
$usuario_rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));
$usuario_rol_base = strtolower(trim((string) (
    $_SESSION['user_rol_base'] ?? $usuario_rol
)));

$sucursal_id = (int) ($_SESSION['sucursal_id'] ?? 0);
$sucursal_nombre = trim((string) (
    $_SESSION['sucursal_nombre'] ?? 'Sucursal'
));

$puede_ver_inscripciones_globales = in_array(
    $usuario_rol_base,
    ['admin', 'administrador'],
    true
);

$vista_solicitada = strtolower(trim((string) (
    $_GET['vista'] ?? ''
)));

/*
 * La vista global conserva la sucursal operativa de la sesión.
 * Únicamente cambia el alcance del listado de inscripciones.
 */
if (
    $vista_solicitada === 'global'
    && $puede_ver_inscripciones_globales
    && function_exists('sucursal_activar_vista_global')
) {
    sucursal_activar_vista_global($conn, $usuario_id);
} elseif (
    $vista_solicitada === 'sucursal'
    && function_exists('sucursal_desactivar_vista_global')
) {
    sucursal_desactivar_vista_global();
}

$vista_global_inscripciones =
    $puede_ver_inscripciones_globales
    && function_exists('sucursal_dashboard_vista_global')
    && sucursal_dashboard_vista_global();

if ($sucursal_id <= 0) {
    $_SESSION['error'] =
        'Selecciona una sucursal operativa antes de administrar inscripciones.';
    header('Location: dashboard.php');
    exit;
}

$stmtSucursal = $conn->prepare(
    "SELECT estado
     FROM sucursales
     WHERE id = ?
     LIMIT 1"
);
$stmtSucursal->bind_param('i', $sucursal_id);
$stmtSucursal->execute();
$sucursalActual = $stmtSucursal->get_result()->fetch_assoc();
$stmtSucursal->close();

if (!$sucursalActual || ($sucursalActual['estado'] ?? '') !== 'activa') {
    $_SESSION['error'] =
        'La sucursal seleccionada está inactiva y no permite nuevas operaciones.';
    header('Location: dashboard.php');
    exit;
}

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
            $telefono = trim((string) ($_POST['telefono'] ?? ''));
            $email = trim((string) ($_POST['email'] ?? ''));
            $contacto_emergencia_nombre = trim((string) (
                $_POST['contacto_emergencia_nombre'] ?? ''
            ));
            $contacto_emergencia_telefono = trim((string) (
                $_POST['contacto_emergencia_telefono'] ?? ''
            ));
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
            
            if (
                $nombre === ''
                || $apellido === ''
                || $telefono === ''
                || $plan_id <= 0
            ) {
                throw new Exception(
                    'Completa todos los campos obligatorios.'
                );
            }

            if (
                $contacto_emergencia_nombre !== ''
                && (
                    strlen($contacto_emergencia_nombre) < 3
                    || strlen($contacto_emergencia_nombre) > 150
                )
            ) {
                throw new Exception(
                    'El nombre del contacto de emergencia debe tener entre 3 y 150 caracteres.'
                );
            }

            if (
                $contacto_emergencia_telefono !== ''
                && !preg_match(
                    '/^[0-9+()\-\s]{7,25}$/',
                    $contacto_emergencia_telefono
                )
            ) {
                throw new Exception(
                    'El teléfono de emergencia debe contener entre 7 y 25 caracteres válidos.'
                );
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
            $stmt = $conn->prepare(
                "SELECT
                    p.duracion_dias,
                    ps.precio,
                    p.nombre AS plan_nombre
                 FROM planes p
                 INNER JOIN planes_sucursales ps
                    ON ps.plan_id = p.id
                   AND ps.sucursal_id = ?
                 WHERE p.id = ?
                   AND p.estado = 'activo'
                   AND ps.estado = 'activo'
                 LIMIT 1"
            );
            $stmt->bind_param("ii", $sucursal_id, $plan_id);
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
            $stmt = $conn->prepare(
                "INSERT INTO clientes (
                    sucursal_registro_id,
                    nombre,
                    apellido,
                    telefono,
                    email,
                    contacto_emergencia_nombre,
                    contacto_emergencia_telefono,
                    codigo_qr,
                    estado
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'activo')"
            );
            $stmt->bind_param(
                "isssssss",
                $sucursal_id,
                $nombre,
                $apellido,
                $telefono,
                $email,
                $contacto_emergencia_nombre,
                $contacto_emergencia_telefono,
                $codigo_qr
            );
            $stmt->execute();
            $cliente_id = $conn->insert_id;
            
            // Insertar inscripción
            $stmt = $conn->prepare(
                "INSERT INTO inscripciones (
                    sucursal_id,
                    cliente_id,
                    plan_id,
                    fecha_inicio,
                    fecha_fin,
                    precio_pagado,
                    estado
                 ) VALUES (?, ?, ?, ?, ?, ?, 'activa')"
            );
            $stmt->bind_param(
                "iiissd",
                $sucursal_id,
                $cliente_id,
                $plan_id,
                $fecha_inicio,
                $fecha_fin,
                $precio_pagado
            );
            $stmt->execute();
            $inscripcion_id = (int) $conn->insert_id;

            $stmt = $conn->prepare(
            "INSERT IGNORE INTO inscripciones_sucursales (
                inscripcion_id,
                sucursal_id
             )
             SELECT ?, s.id
             FROM sucursales s
             WHERE s.estado = 'activa'"
        );
        $stmt->bind_param("i", $inscripcion_id);
        $stmt->execute();
            
            // Insertar pago
            $fecha_actual_db = date('Y-m-d');
            $stmt = $conn->prepare(
                "INSERT INTO pagos (
                    sucursal_id,
                    inscripcion_id,
                    cliente_id,
                    monto,
                    fecha_pago,
                    metodo_pago,
                    referencia,
                    estado
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, 'completado')"
            );
            $stmt->bind_param(
                "iiidsss",
                $sucursal_id,
                $inscripcion_id,
                $cliente_id,
                $precio_pagado,
                $fecha_actual_db,
                $metodo_pago,
                $referencia
            );
            $stmt->execute();
            $pago_id = (int) $conn->insert_id;
            
            // Registrar en historial_pagos
            $stmt = $conn->prepare(
                "INSERT INTO historial_pagos (
                    sucursal_id,
                    inscripcion_id,
                    cliente_id,
                    monto,
                    fecha_pago,
                    metodo_pago,
                    referencia,
                    periodo_inicio,
                    periodo_fin,
                    plan_nombre,
                    usuario_id
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param(
                "iiidssssssi",
                $sucursal_id,
                $inscripcion_id,
                $cliente_id,
                $precio_pagado,
                $fecha_actual_db,
                $metodo_pago,
                $referencia,
                $fecha_inicio,
                $fecha_fin,
                $plan['plan_nombre'],
                $usuario_id
            );
            $stmt->execute();
            $historial_pago_id = (int) $conn->insert_id;

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

// ========== GENERAR Y CONSERVAR DOCUMENTO PDF ==========
$documento_membresia = asegurarDocumentoHistorialInscripcion(
    $conn,
    $historial_pago_id
);

if (!empty($documento_membresia['success'])) {
    $_SESSION['abrir_documento_membresia_url'] =
        (string) $documento_membresia['url'];
} else {
    $_SESSION['cerrar_ventana_documento_membresia'] = true;
    error_log(
        '[Inscripciones] No se pudo generar el PDF de inscripción: ' .
        (string) ($documento_membresia['error'] ?? 'Error desconocido')
    );
}
// ========== FIN DOCUMENTO PDF ==========

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

if (!empty($documento_membresia['success'])) {
    $url_documento = htmlspecialchars(
        (string) $documento_membresia['url'],
        ENT_QUOTES,
        'UTF-8'
    );
    $mensaje_exito .= '<br><a class="document-success-link" href="' .
        $url_documento .
        '" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Abrir documento de inscripción</a>';
} else {
    $mensaje_exito .= '<br><span class="text-warning">⚠ La inscripción se guardó, pero no fue posible generar el documento PDF.</span>';
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
            
        } catch (Throwable $e) {
            if (isset($conn)) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                }
            }
            $_SESSION['cerrar_ventana_documento_membresia'] = true;
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
        $stmt = $conn->prepare(
            "SELECT
                p.duracion_dias,
                ps.precio,
                p.nombre AS plan_nombre
             FROM planes p
             INNER JOIN planes_sucursales ps
                ON ps.plan_id = p.id
               AND ps.sucursal_id = ?
             WHERE p.id = ?
               AND p.estado = 'activo'
               AND ps.estado = 'activo'
             LIMIT 1"
        );
        $stmt->bind_param("ii", $sucursal_id, $plan_id);
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
            "SELECT
                i.*,
                p.nombre AS plan_actual,
                p.duracion_dias AS duracion_actual,
                c.estado AS cliente_estado
             FROM inscripciones i
             INNER JOIN planes p ON i.plan_id = p.id
             INNER JOIN clientes c ON i.cliente_id = c.id
             LEFT JOIN inscripciones_sucursales acceso
                ON acceso.inscripcion_id = i.id
               AND acceso.sucursal_id = ?
             WHERE i.id = ?
               AND (
                    i.sucursal_id = ?
                    OR acceso.sucursal_id IS NOT NULL
               )
             LIMIT 1"
        );
        $stmt_ins->bind_param(
            "iii",
            $sucursal_id,
            $inscripcion_id,
            $sucursal_id
        );
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
        $stmt = $conn->prepare(
            "UPDATE inscripciones
             SET plan_id = ?,
                 fecha_inicio = ?,
                 fecha_fin = ?,
                 precio_pagado = ?,
                 estado = 'activa'
             WHERE id = ?"
        );
        $stmt->bind_param(
            "issdi",
            $plan_id,
            $fecha_inicio,
            $fecha_fin,
            $precio_pagado,
            $inscripcion_id
        );
        $stmt->execute();

        $stmt = $conn->prepare(
            "INSERT IGNORE INTO inscripciones_sucursales (
                inscripcion_id,
                sucursal_id
             )
             SELECT ?, s.id
             FROM sucursales s
             WHERE s.estado = 'activa'"
        );
        $stmt->bind_param("i", $inscripcion_id);
        $stmt->execute();
        
        // Registrar el pago en la tabla pagos
        $fecha_actual = date('Y-m-d');
        $stmt = $conn->prepare(
            "INSERT INTO pagos (
                sucursal_id,
                inscripcion_id,
                cliente_id,
                monto,
                fecha_pago,
                metodo_pago,
                referencia,
                estado
             ) VALUES (?, ?, ?, ?, ?, ?, ?, 'completado')"
        );
        $stmt->bind_param(
            "iiidsss",
            $sucursal_id,
            $inscripcion_id,
            $cliente_id,
            $precio_pagado,
            $fecha_actual,
            $metodo_pago,
            $referencia
        );
        $stmt->execute();
        $pago_id = (int) $conn->insert_id;

        // Registrar en historial_pagos
        $stmt = $conn->prepare(
            "INSERT INTO historial_pagos (
                sucursal_id,
                inscripcion_id,
                cliente_id,
                monto,
                fecha_pago,
                metodo_pago,
                referencia,
                periodo_inicio,
                periodo_fin,
                plan_nombre,
                usuario_id
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            "iiidssssssi",
            $sucursal_id,
            $inscripcion_id,
            $cliente_id,
            $precio_pagado,
            $fecha_actual,
            $metodo_pago,
            $referencia,
            $fecha_inicio,
            $fecha_fin,
            $plan['plan_nombre'],
            $usuario_id
        );
        $stmt->execute();
        $historial_pago_id = (int) $conn->insert_id;

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

// Generar el documento aunque el socio no tenga email.
$documento_membresia = asegurarDocumentoHistorialInscripcion(
    $conn,
    $historial_pago_id
);

if (!empty($documento_membresia['success'])) {
    $_SESSION['abrir_documento_membresia_url'] =
        (string) $documento_membresia['url'];
} else {
    $_SESSION['cerrar_ventana_documento_membresia'] = true;
    error_log(
        '[Inscripciones] No se pudo generar el PDF de renovación: ' .
        (string) ($documento_membresia['error'] ?? 'Error desconocido')
    );
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
            $mensaje_renovacion = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. Se ha enviado un ticket a su correo electrónico.';
        } elseif (!empty($email_cliente) && !$envio_correo) {
            $mensaje_renovacion = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. No se pudo enviar el correo electrónico.';
        } else {
            $mensaje_renovacion = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. No se envió correo porque el cliente no tiene email registrado.';
        }

        if (!empty($documento_membresia['success'])) {
            $url_documento = htmlspecialchars(
                (string) $documento_membresia['url'],
                ENT_QUOTES,
                'UTF-8'
            );
            $mensaje_renovacion .= '<br><a class="document-success-link" href="' .
                $url_documento .
                'documento de renovación</a>';
        } else {
            $mensaje_renovacion .= '<br><span class="text-warning">⚠ La renovación se guardó, pero no fue posible generar el documento PDF.</span>';
        }

        $_SESSION['mensaje_exito'] = $mensaje_renovacion;

        header('Location: inscripciones.php');
        exit;
        
    } catch (Throwable $e) {
        if (isset($conn)) {
            try {
                $conn->rollback();
            } catch (Throwable $rollbackError) {
            }
        }
        if (isset($inscripcion_id)) {
            unset($_SESSION['last_renewal_' . $inscripcion_id]);
        }
        $_SESSION['cerrar_ventana_documento_membresia'] = true;
        $_SESSION['error'] = $e->getMessage();
        header('Location: inscripciones.php');
        exit;
    }
}

// Cancelar inscripción
if (isset($_GET['cancelar']) && is_numeric($_GET['cancelar'])) {
    $id = (int) $_GET['cancelar'];

    if (
        isset($_SESSION['last_cancel_' . $id]) &&
        $_SESSION['last_cancel_' . $id] > time() - 5
    ) {
        $_SESSION['error'] = 'Ya se está procesando esta cancelación.';
        header('Location: inscripciones.php');
        exit;
    }

    $_SESSION['last_cancel_' . $id] = time();

    try {
        $conn->begin_transaction();

        $stmt = $conn->prepare(
            "SELECT i.id
             FROM inscripciones i
             LEFT JOIN inscripciones_sucursales acceso
                ON acceso.inscripcion_id = i.id
               AND acceso.sucursal_id = ?
             WHERE i.id = ?
               AND (
                    i.sucursal_id = ?
                    OR acceso.sucursal_id IS NOT NULL
               )
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->bind_param("iii", $sucursal_id, $id, $sucursal_id);
        $stmt->execute();
        $inscripcion = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$inscripcion) {
            throw new RuntimeException(
                'La inscripción no pertenece a la sucursal activa.'
            );
        }

        $stmt = $conn->prepare(
            "UPDATE inscripciones
             SET estado = 'cancelada'
             WHERE id = ?"
        );
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();

        $conn->commit();

        $_SESSION['mensaje_exito'] =
            'Inscripción cancelada exitosamente.';
        unset($_SESSION['last_cancel_' . $id]);

        header('Location: inscripciones.php');
        exit;
    } catch (Throwable $e) {
        try {
            $conn->rollback();
        } catch (Throwable $rollbackError) {
        }

        unset($_SESSION['last_cancel_' . $id]);
        $_SESSION['error'] =
            'Error al cancelar la inscripción: ' . $e->getMessage();

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
    'sucursal' => 's.nombre',
    'fecha_inicio' => 'i.fecha_inicio',
    'fecha_fin' => 'i.fecha_fin',
    'precio' => 'i.precio_pagado',
    'estado' => 'i.estado'
];

$order_by = isset($sort_columns[$sort]) ? $sort_columns[$sort] : 'i.id';
$order_dir = ($order == 'ASC') ? 'ASC' : 'DESC';

$query = "SELECT
              i.*,
              c.nombre AS cliente_nombre,
              c.apellido AS cliente_apellido,
              c.telefono AS cliente_telefono,
              c.codigo_qr AS cliente_codigo_qr,
              c.estado AS cliente_estado,
              p.nombre AS plan_nombre,
              p.duracion_dias,
              s.nombre AS sucursal_nombre,
              s.clave AS sucursal_clave,
              s.es_matriz AS sucursal_es_matriz
          FROM inscripciones i
          INNER JOIN clientes c ON i.cliente_id = c.id
          INNER JOIN planes p ON i.plan_id = p.id
          INNER JOIN sucursales s ON s.id = i.sucursal_id
          WHERE 1 = 1";

$count_query = "SELECT COUNT(*) AS total
                FROM inscripciones i
                INNER JOIN clientes c ON i.cliente_id = c.id
                INNER JOIN planes p ON i.plan_id = p.id
                INNER JOIN sucursales s ON s.id = i.sucursal_id
                WHERE 1 = 1";

$params = [];
$types = "";

if (!$vista_global_inscripciones) {
    /*
     * La sede del sidebar filtra por la sucursal donde fue registrada
     * la inscripción. El acceso físico del socio continúa siendo global.
     */
    $query .= " AND i.sucursal_id = ?";
    $count_query .= " AND i.sucursal_id = ?";
    $params[] = $sucursal_id;
    $types .= "i";
}

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
$total_rows = (int) ($total_result->fetch_assoc()['total'] ?? 0);
$total_pages = (int) ceil($total_rows / $limit);

// Obtener planes y precios habilitados en la sucursal activa.
$stmtPlanes = $conn->prepare(
    "SELECT
        p.id,
        p.nombre,
        p.duracion_dias,
        p.descripcion,
        ps.precio
     FROM planes p
     INNER JOIN planes_sucursales ps
        ON ps.plan_id = p.id
       AND ps.sucursal_id = ?
     WHERE p.estado = 'activo'
       AND ps.estado = 'activo'
     ORDER BY p.duracion_dias ASC"
);
$stmtPlanes->bind_param('i', $sucursal_id);
$stmtPlanes->execute();
$planes = $stmtPlanes->get_result()->fetch_all(MYSQLI_ASSOC);
$stmtPlanes->close();

/*
 * La terminal Point se toma de config/mercadopago_config.php.
 * mercadopago_inscripciones.php carga el cliente y éste carga ese archivo.
 * La tabla mercadopago_terminales puede seguir existiendo para administración,
 * pero no bloquea el cobro mientras la configuración PHP sea válida.
 */
$terminal_point_id = defined('MP_TERMINAL_ID')
    ? trim((string) MP_TERMINAL_ID)
    : '';

$terminal_point_disponible =
    defined('MP_ACCESS_TOKEN')
    && trim((string) MP_ACCESS_TOKEN) !== ''
    && $terminal_point_id !== '';

$documento_membresia_auto_url = trim((string) (
    $_SESSION['abrir_documento_membresia_url'] ?? ''
));
$cerrar_ventana_documento_membresia = !empty(
    $_SESSION['cerrar_ventana_documento_membresia']
);
unset(
    $_SESSION['abrir_documento_membresia_url'],
    $_SESSION['cerrar_ventana_documento_membresia']
);
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

        .document-success-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 10px;
            padding: 8px 12px;
            border: 1px solid #fecaca;
            border-radius: 9px;
            background: #fff1f2;
            color: #b91c1c;
            font-weight: 800;
            text-decoration: none;
        }

        .document-success-link:hover {
            background: #ffe4e6;
            color: #991b1b;
        }

        /* Sucursal compacta en el listado global */
        .tabla-simple th.col-sucursal,
        .tabla-simple td.col-sucursal {
            width: 112px;
            min-width: 112px;
            white-space: nowrap;
        }

        .sucursal-chip {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 76px;
            max-width: 108px;
            padding: 7px 9px;
            border: 1px solid #c7d7fe;
            border-radius: 8px;
            background: #eef4ff;
            color: #1e3a8a;
            font-size: .72rem;
            font-weight: 800;
            line-height: 1;
            letter-spacing: .025em;
            text-transform: uppercase;
            vertical-align: middle;
        }

        .sucursal-chip i {
            flex: 0 0 auto;
            font-size: .72rem;
        }

        .sucursal-chip-text {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .sucursal-chip.is-matriz {
            border-color: #bfdbfe;
            background: #eff6ff;
            color: #1d4ed8;
        }

        .sucursal-chip.is-sucursal {
            border-color: #a7f3d0;
            background: #ecfdf5;
            color: #047857;
        }

        @media (max-width: 767.98px) {
            .tabla-simple td.col-sucursal {
                width: auto;
                min-width: 0;
                white-space: normal;
            }

            .sucursal-chip {
                max-width: 150px;
            }
        }

        /*
         * Tabla sin scroll horizontal en escritorio.
         * Las acciones se muestran como iconos compactos con tooltip.
         */
        @media (min-width: 992px) {
            .table-card-body,
            .table-responsive-custom {
                width: 100%;
                max-width: 100%;
                overflow-x: hidden !important;
            }

            .tabla-inscripciones {
                width: 100% !important;
                min-width: 0 !important;
                max-width: 100% !important;
                table-layout: fixed;
            }

            .tabla-inscripciones th,
            .tabla-inscripciones td {
                min-width: 0 !important;
                padding-left: 9px;
                padding-right: 9px;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .tabla-inscripciones th a {
                white-space: normal;
                line-height: 1.15;
            }

            .tabla-inscripciones td:nth-last-child(n+2) {
                overflow-wrap: anywhere;
            }

            .tabla-inscripciones .acciones-cell {
                overflow: visible;
                white-space: nowrap;
            }

            .tabla-inscripciones .acciones-container {
                display: flex;
                align-items: center;
                justify-content: flex-start;
                flex-wrap: nowrap;
                gap: 6px;
                min-width: 0;
            }

            .tabla-inscripciones .btn-accion {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                flex: 0 0 36px;
                width: 36px;
                min-width: 36px;
                height: 36px;
                padding: 0;
                border-radius: 8px;
            }

            .tabla-inscripciones .btn-accion span {
                display: none;
            }

            .tabla-inscripciones.is-global th:nth-child(1),
            .tabla-inscripciones.is-global td:nth-child(1) { width: 11%; }

            .tabla-inscripciones.is-global th:nth-child(2),
            .tabla-inscripciones.is-global td:nth-child(2) { width: 10%; }

            .tabla-inscripciones.is-global th:nth-child(3),
            .tabla-inscripciones.is-global td:nth-child(3) { width: 9%; }

            .tabla-inscripciones.is-global th:nth-child(4),
            .tabla-inscripciones.is-global td:nth-child(4) { width: 8%; }

            .tabla-inscripciones.is-global th:nth-child(5),
            .tabla-inscripciones.is-global td:nth-child(5),
            .tabla-inscripciones.is-global th:nth-child(6),
            .tabla-inscripciones.is-global td:nth-child(6) { width: 10%; }

            .tabla-inscripciones.is-global th:nth-child(7),
            .tabla-inscripciones.is-global td:nth-child(7) { width: 9%; }

            .tabla-inscripciones.is-global th:nth-child(8),
            .tabla-inscripciones.is-global td:nth-child(8) { width: 8%; }

            .tabla-inscripciones.is-global th:nth-child(9),
            .tabla-inscripciones.is-global td:nth-child(9) { width: 17%; }

            .tabla-inscripciones.is-branch th:nth-child(1),
            .tabla-inscripciones.is-branch td:nth-child(1) { width: 14%; }

            .tabla-inscripciones.is-branch th:nth-child(2),
            .tabla-inscripciones.is-branch td:nth-child(2) { width: 12%; }

            .tabla-inscripciones.is-branch th:nth-child(3),
            .tabla-inscripciones.is-branch td:nth-child(3) { width: 11%; }

            .tabla-inscripciones.is-branch th:nth-child(4),
            .tabla-inscripciones.is-branch td:nth-child(4),
            .tabla-inscripciones.is-branch th:nth-child(5),
            .tabla-inscripciones.is-branch td:nth-child(5) { width: 11%; }

            .tabla-inscripciones.is-branch th:nth-child(6),
            .tabla-inscripciones.is-branch td:nth-child(6) { width: 10%; }

            .tabla-inscripciones.is-branch th:nth-child(7),
            .tabla-inscripciones.is-branch td:nth-child(7) { width: 9%; }

            .tabla-inscripciones.is-branch th:nth-child(8),
            .tabla-inscripciones.is-branch td:nth-child(8) { width: 22%; }
        }

        @media (max-width: 991.98px) {
            .table-responsive-custom {
                overflow-x: visible !important;
            }
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="main-content">
        <header class="page-header">
            <div>
                <h1>Gestión de Inscripciones</h1>
                <p>
                    <?php if ($vista_global_inscripciones): ?>
                        Consulta las inscripciones registradas en todas las sucursales.
                    <?php else: ?>
                        Consulta las inscripciones registradas en
                        <?php echo htmlspecialchars(
                            $sucursal_nombre,
                            ENT_QUOTES,
                            'UTF-8'
                        ); ?>.
                    <?php endif; ?>
                </p>
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
                <span class="records-count"><?php echo number_format((int) $total_rows); ?> <?php echo $total_rows == 1 ? 'registro' : 'registros'; ?></span>
            </div>
            <div class="card-body-custom table-card-body">
                <div class="table-responsive-custom">
                    <table class="tabla-simple tabla-inscripciones <?php echo $vista_global_inscripciones ? 'is-global' : 'is-branch'; ?>">
                        <thead>
                            <tr>
                                <th><a href="?sort=cliente&order=<?php echo ($sort == 'cliente' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Cliente <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=telefono&order=<?php echo ($sort == 'telefono' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Teléfono <i class="fas fa-sort"></i></a></th>
                                <th><a href="?sort=plan&order=<?php echo ($sort == 'plan' && $order == 'ASC') ? 'DESC' : 'ASC'; ?>&search=<?php echo urlencode($search); ?>&estado=<?php echo urlencode($estado); ?>">Plan <i class="fas fa-sort"></i></a></th>
                                <?php if ($vista_global_inscripciones): ?>
                                    <th class="col-sucursal">Sede</th>
                                <?php endif; ?>
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
                                <?php if ($vista_global_inscripciones): ?>
                                    <?php
                                    $sucursalEsMatriz =
                                        (int) ($ins['sucursal_es_matriz'] ?? 0) === 1;

                                    $sucursalClave = trim((string) (
                                        $ins['sucursal_clave'] ?? ''
                                    ));

                                    if ($sucursalClave === '') {
                                        $sucursalClave = $sucursalEsMatriz
                                            ? 'MATRIZ'
                                            : 'SEDE';
                                    }

                                    $sucursalNombreCompleto = trim(
                                        (string) ($ins['sucursal_nombre'] ?? '')
                                    );

                                    $sucursalTooltip =
                                        $sucursalNombreCompleto
                                        . (
                                            $sucursalEsMatriz
                                                ? ' · Matriz'
                                                : ' · Sucursal'
                                        );
                                    ?>
                                    <td
                                        class="col-sucursal"
                                        data-label="Sede"
                                    >
                                        <span
                                            class="sucursal-chip <?php echo $sucursalEsMatriz
                                                ? 'is-matriz'
                                                : 'is-sucursal'; ?>"
                                            title="<?php echo htmlspecialchars(
                                                $sucursalTooltip,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>"
                                            aria-label="<?php echo htmlspecialchars(
                                                $sucursalTooltip,
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ); ?>"
                                        >
                                            <i class="fas <?php echo $sucursalEsMatriz
                                                ? 'fa-building'
                                                : 'fa-location-dot'; ?>"></i>

                                            <span class="sucursal-chip-text">
                                                <?php echo htmlspecialchars(
                                                    $sucursalClave,
                                                    ENT_QUOTES,
                                                    'UTF-8'
                                                ); ?>
                                            </span>
                                        </span>
                                    </td>
                                <?php endif; ?>
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
                                <td data-label="Precio"><strong class="price-value">$<?php echo number_format((float) $ins['precio_pagado'], 2); ?></strong></td>
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
                                <td colspan="<?php echo $vista_global_inscripciones ? 9 : 8; ?>" class="empty-state">
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
                                <label class="form-label" for="telefono_nuevo">Teléfono *</label>
                                <input
                                    type="tel"
                                    class="form-control"
                                    name="telefono"
                                    id="telefono_nuevo"
                                    maxlength="20"
                                    autocomplete="tel"
                                    required
                                >
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label" for="email_nuevo">Email</label>
                                <input
                                    type="email"
                                    class="form-control"
                                    name="email"
                                    id="email_nuevo"
                                    maxlength="100"
                                    autocomplete="email"
                                >
                            </div>
                        </div>

                        <section class="emergency-contact-section" aria-labelledby="tituloContactoEmergencia">
                            <div class="emergency-contact-heading">
                                <span class="emergency-contact-icon">
                                    <i class="fas fa-phone-volume"></i>
                                </span>
                                <div>
                                    <h6 id="tituloContactoEmergencia">Contacto de emergencia</h6>
                                    <p>Datos opcionales para contactar a alguien en caso de una emergencia.</p>
                                </div>
                            </div>

                            <div class="row">
                                <div class="col-md-7 mb-3 mb-md-0">
                                    <label class="form-label" for="contacto_emergencia_nombre">
                                        Nombre del contacto <span class="text-muted fw-normal">(opcional)</span>
                                    </label>
                                    <input
                                        type="text"
                                        class="form-control"
                                        name="contacto_emergencia_nombre"
                                        id="contacto_emergencia_nombre"
                                        minlength="3"
                                        maxlength="150"
                                        placeholder="Nombre completo"
                                    >
                                </div>
                                <div class="col-md-5">
                                    <label class="form-label" for="contacto_emergencia_telefono">
                                        Teléfono de emergencia <span class="text-muted fw-normal">(opcional)</span>
                                    </label>
                                    <input
                                        type="tel"
                                        class="form-control"
                                        name="contacto_emergencia_telefono"
                                        id="contacto_emergencia_telefono"
                                        minlength="7"
                                        maxlength="25"
                                        pattern="[0-9+()\- ]{7,25}"
                                        title="Usa entre 7 y 25 caracteres: números, espacios, +, paréntesis o guiones."
                                        placeholder="Ej. 222 123 4567"
                                    >
                                </div>
                            </div>
                        </section>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Plan *</label>
                                <select class="form-select" name="plan_id" id="plan_id_nuevo" required onchange="actualizarPrecioNuevo()">
                                    <option value="">Seleccionar plan</option>
                                    <?php foreach($planes as $plan): ?>
                                    <option value="<?php echo $plan['id']; ?>" data-precio="<?php echo $plan['precio']; ?>">
                                        <?php echo htmlspecialchars($plan['nombre'] . ' - $' . number_format((float) $plan['precio'], 2)); ?>
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
                                    <?php if ($terminal_point_disponible): ?>
                                        <option value="tarjeta_debito">Tarjeta de débito · Point</option>
                                        <option value="tarjeta_credito">Tarjeta de crédito · Point</option>
                                    <?php else: ?>
                                        <option value="" disabled>Point no configurada en esta sucursal</option>
                                    <?php endif; ?>
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
                                    <?php echo htmlspecialchars($plan['nombre'] . ' - $' . number_format((float) $plan['precio'], 2)); ?>
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
                                <?php if ($terminal_point_disponible): ?>
                                    <option value="tarjeta_debito">Tarjeta de débito · Point</option>
                                    <option value="tarjeta_credito">Tarjeta de crédito · Point</option>
                                <?php else: ?>
                                    <option value="" disabled>Point no configurada en esta sucursal</option>
                                <?php endif; ?>
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
        let ventanaDocumentoMembresia = null;

        function prepararVentanaDocumentoMembresia() {
            if (ventanaDocumentoMembresia && !ventanaDocumentoMembresia.closed) {
                return ventanaDocumentoMembresia;
            }

            ventanaDocumentoMembresia = window.open(
                '',
                'documento_membresia_generado'
            );

            if (ventanaDocumentoMembresia) {
                ventanaDocumentoMembresia.document.open();
                ventanaDocumentoMembresia.document.write(`
                    <!doctype html>
                    <html lang="es">
                    <head>
                        <meta charset="utf-8">
                        <title>Generando documento</title>
                        <style>
                            body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f4f6f9;font-family:Arial,sans-serif;color:#1f2937}
                            .box{text-align:center;padding:32px}
                            .icon{font-size:42px;color:#dc2626;margin-bottom:15px}
                            h1{margin:0 0 8px;font-size:22px}
                            p{margin:0;color:#6b7280}
                        </style>
                    </head>
                    <body>
                        <div class="box">
                            <div class="icon">PDF</div>
                            <h1>Generando documento de membresía</h1>
                            <p>Esta ventana mostrará el comprobante al finalizar.</p>
                        </div>
                    </body>
                    </html>
                `);
                ventanaDocumentoMembresia.document.close();
            }

            return ventanaDocumentoMembresia;
        }

        function cerrarVentanaDocumentoMembresia() {
            if (ventanaDocumentoMembresia && !ventanaDocumentoMembresia.closed) {
                ventanaDocumentoMembresia.close();
            }
            ventanaDocumentoMembresia = null;
        }

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
            let data = null;

            try {
                data = JSON.parse(text);
            } catch (error) {
                console.error(
                    'Respuesta no JSON de ' + url,
                    {
                        status: response.status,
                        contentType: response.headers.get('content-type'),
                        response: text
                    }
                );

                const statusText = response.status > 0
                    ? 'HTTP ' + response.status
                    : 'sin código HTTP';

                throw new Error(
                    'El endpoint de Mercado Pago devolvió ' +
                    statusText +
                    ' en lugar de JSON. Verifica que exista ' +
                    url +
                    ' y revisa el registro de errores de PHP.'
                );
            }

            if (!response.ok || !data.success) {
                const requestError = new Error(
                    data.message ||
                    'Ocurrió un error al comunicarse con Mercado Pago.'
                );
                requestError.code = data.code || '';
                requestError.orderId = data.order_id || '';
                requestError.requiresTerminal = Boolean(
                    data.requires_terminal
                );
                requestError.httpStatus = response.status;
                throw requestError;
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

            prepararVentanaDocumentoMembresia();

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
                        cerrarVentanaDocumentoMembresia();
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
                        cerrarVentanaDocumentoMembresia();
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
                cerrarVentanaDocumentoMembresia();
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
                error: function(xhr) {
                    const respuesta = String(xhr.responseText || '').trim();
                    const mensaje = respuesta !== ''
                        ? respuesta
                        : '<div class="alert alert-danger">Error al cargar los detalles.</div>';

                    $('#detalleContenido').html(mensaje);
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
                    error: function(xhr) {
                        let mensaje = 'Error al cargar los datos.';

                        try {
                            const respuesta = JSON.parse(xhr.responseText || '{}');
                            if (respuesta.error) {
                                mensaje = respuesta.error;
                            }
                        } catch (error) {
                            const texto = String(xhr.responseText || '').trim();
                            if (texto !== '') {
                                mensaje = texto.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
                            }
                        }

                        $('#tablaHistorialBody').html(
                            '<tr><td colspan="6" class="text-center text-danger">' +
                            escaparHtml(mensaje) +
                            '</td></tr>'
                        );
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

        <?php if ($documento_membresia_auto_url !== ''): ?>
        $(document).ready(function() {
            const documentoUrl = <?php echo json_encode(
                $documento_membresia_auto_url,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
            ); ?>;
            const ventanaPdf = window.open(
                documentoUrl,
                'documento_membresia_generado'
            );

            if (!ventanaPdf) {
                Swal.fire({
                    icon: 'success',
                    title: 'Documento generado',
                    html: '<p>El navegador bloqueó la ventana automática.</p>' +
                        '<a class="document-success-link" href="' + escaparHtml(documentoUrl) + '" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Abrir documento PDF</a>',
                    confirmButtonColor: '#1e3a8a'
                });
            }
        });
        <?php elseif ($cerrar_ventana_documento_membresia): ?>
        $(document).ready(function() {
            const ventanaPendiente = window.open(
                '',
                'documento_membresia_generado'
            );
            if (ventanaPendiente) {
                ventanaPendiente.close();
            }
        });
        <?php endif; ?>
        
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