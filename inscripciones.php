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
require_once __DIR__ . '/includes/expediente_salud_invitaciones.php'; // Enlaces seguros del cuestionario médico
require_once __DIR__ . '/includes/correo_expediente_salud.php'; // Invitación y copia PDF del expediente
require_once __DIR__ . '/includes/correo_cola.php'; // Envío en segundo plano, sin bloquear el alta
require_once __DIR__ . '/includes/mercadopago_inscripciones.php'; // Validación y vínculo de pagos Point


// ==================== FIN FUNCIÓN QR ====================

// ==================== CREDENCIAL DEL SOCIO ====================
/*
 * Tamaño físico CR80/PVC: 85.60 x 53.98 mm.
 * `clientes.foto` es opcional: si no existe, se utiliza el logo.
 */
function inscripcionesClientesTieneFoto(mysqli $conn): bool
{
    try {
        $resultado = $conn->query("SHOW COLUMNS FROM clientes LIKE 'foto'");
        return $resultado instanceof mysqli_result
            && $resultado->num_rows > 0;
    } catch (Throwable $error) {
        return false;
    }
}

function inscripcionesRutaImagenPublica(string $ruta): string
{
    $ruta = trim(str_replace('\\', '/', $ruta));

    if ($ruta === '') {
        return '';
    }

    if (
        preg_match('#^(?:https?:)?//#i', $ruta) === 1
        || strpos($ruta, "\0") !== false
    ) {
        return '';
    }

    $ruta = ltrim($ruta, '/');
    $absoluta = __DIR__ . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $ruta);

    return is_file($absoluta) ? $ruta : '';
}

function inscripcionesGuardarFotoSocio(array $archivo): string
{
    $error = (int) ($archivo['error'] ?? UPLOAD_ERR_NO_FILE);

    if ($error === UPLOAD_ERR_NO_FILE) {
        return '';
    }

    if ($error !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No fue posible recibir la foto del socio.');
    }

    $tamano = (int) ($archivo['size'] ?? 0);
    if ($tamano <= 0 || $tamano > 5 * 1024 * 1024) {
        throw new RuntimeException('La foto del socio debe pesar máximo 5 MB.');
    }

    $temporal = (string) ($archivo['tmp_name'] ?? '');
    if ($temporal === '' || !is_uploaded_file($temporal)) {
        throw new RuntimeException('La foto recibida no es un archivo válido.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string) $finfo->file($temporal);

    $extensiones = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    if (!isset($extensiones[$mime])) {
        throw new RuntimeException('La foto debe estar en formato JPG, PNG o WEBP.');
    }

    $directorioRelativo = 'uploads/socios';
    $directorioAbsoluto = __DIR__
        . DIRECTORY_SEPARATOR . 'uploads'
        . DIRECTORY_SEPARATOR . 'socios';

    if (
        !is_dir($directorioAbsoluto)
        && !@mkdir($directorioAbsoluto, 0775, true)
        && !is_dir($directorioAbsoluto)
    ) {
        throw new RuntimeException('No fue posible crear la carpeta para fotos de socios.');
    }

    $nombreArchivo = 'socio_'
        . date('Ymd_His')
        . '_'
        . bin2hex(random_bytes(8))
        . '.'
        . $extensiones[$mime];

    $destinoAbsoluto = $directorioAbsoluto
        . DIRECTORY_SEPARATOR
        . $nombreArchivo;

    if (!move_uploaded_file($temporal, $destinoAbsoluto)) {
        throw new RuntimeException('No fue posible guardar la foto del socio.');
    }

    return $directorioRelativo . '/' . $nombreArchivo;
}
// ================== FIN CREDENCIAL DEL SOCIO ==================

// Crear instancia de la base de datos y obtener la conexión
$database = new Database();
$conn = $database->getConnection();

// Verificar que la conexión existe
if (!$conn) {
    die("Error: No se pudo establecer la conexión a la base de datos");
}

$clientes_tiene_foto = inscripcionesClientesTieneFoto($conn);

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

/*
 * Permite llegar desde el dashboard directamente al formulario de renovación.
 * Los datos únicamente se utilizan para abrir la interfaz; el servidor vuelve
 * a validar la inscripción, el socio, la sucursal y las fechas al guardar.
 */
$abrir_modal_renovar_dashboard =
    strtolower(trim((string) ($_GET['action'] ?? ''))) === 'renovar'
    && (int) ($_GET['inscripcion_id'] ?? 0) > 0
    && (int) ($_GET['cliente_id'] ?? 0) > 0;

$renovar_dashboard_inscripcion_id = (int) (
    $_GET['inscripcion_id'] ?? 0
);
$renovar_dashboard_cliente_id = (int) (
    $_GET['cliente_id'] ?? 0
);
$renovar_dashboard_fecha_inicio = trim((string) (
    $_GET['fecha_inicio'] ?? ''
));

if (
    $renovar_dashboard_fecha_inicio !== ''
    && preg_match(
        '/^\d{4}-\d{2}-\d{2}$/',
        $renovar_dashboard_fecha_inicio
    ) !== 1
) {
    $renovar_dashboard_fecha_inicio = '';
}



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
            $solicitar_cuestionario_salud = isset($_POST['solicitar_cuestionario_salud']);
            $modo_cuestionario_salud = trim((string) ($_POST['modo_cuestionario_salud'] ?? 'recepcion'));
            if (!in_array($modo_cuestionario_salud, ['recepcion', 'correo'], true)) {
                $modo_cuestionario_salud = 'recepcion';
            }
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
                $solicitar_cuestionario_salud
                && $modo_cuestionario_salud === 'correo'
                && !filter_var($email, FILTER_VALIDATE_EMAIL)
            ) {
                throw new Exception(
                    'Para enviar el cuestionario médico, captura un correo electrónico válido.'
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
            
            // Validar si el socio ya existe sin fallar todavía.
            /*
             * Una petición anterior pudo guardar al socio y quedar detenida
             * antes de crear la invitación del expediente. Más adelante se
             * verifica si se trata de un reintento recuperable.
             */
            $cliente_existente_id = 0;
            $cliente_existente = null;

            $stmt = $conn->prepare(
                "SELECT id, nombre, apellido, email, codigo_qr
                 FROM clientes
                 WHERE telefono = ?
                    OR (email = ? AND email != '')
                 ORDER BY id DESC
                 LIMIT 1"
            );
            $stmt->bind_param("ss", $telefono, $email);
            $stmt->execute();
            $cliente_existente = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($cliente_existente) {
                $cliente_existente_id = (int) $cliente_existente['id'];
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

            /*
             * Recuperación idempotente:
             * si el primer POST ya guardó la inscripción pero se cortó antes
             * de crear la invitación o encolar el correo, el segundo intento
             * completa lo pendiente sin duplicar al socio.
             */
            if ($cliente_existente_id > 0) {
                $stmt = $conn->prepare(
                    "SELECT
                        i.id AS inscripcion_id,
                        i.fecha_fin,
                        i.fecha_registro,
                        hp.id AS historial_pago_id,
                        hp.metodo_pago AS metodo_pago_guardado,
                        hp.referencia AS referencia_guardada,
                        c.codigo_qr
                     FROM inscripciones i
                     INNER JOIN clientes c ON c.id = i.cliente_id
                     LEFT JOIN historial_pagos hp
                        ON hp.id = (
                            SELECT MAX(hp2.id)
                            FROM historial_pagos hp2
                            WHERE hp2.inscripcion_id = i.id
                        )
                     WHERE i.cliente_id = ?
                       AND i.plan_id = ?
                       AND i.fecha_inicio = ?
                       AND ABS(i.precio_pagado - ?) <= 0.01
                       AND i.fecha_registro >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                     ORDER BY i.id DESC
                     LIMIT 1"
                );
                $stmt->bind_param(
                    'iisd',
                    $cliente_existente_id,
                    $plan_id,
                    $fecha_inicio,
                    $precio_pagado
                );
                $stmt->execute();
                $alta_reciente = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (
                    $alta_reciente
                    && $solicitar_cuestionario_salud
                    && $modo_cuestionario_salud === 'correo'
                ) {
                    correo_cola_asegurar_tabla($conn);

                    $invitacion_recuperada = expediente_crear_invitacion(
                        $conn,
                        $cliente_existente_id,
                        (int) $alta_reciente['inscripcion_id'],
                        $sucursal_id,
                        $usuario_id,
                        $email,
                        'correo',
                        7
                    );

                    $codigo_qr_recuperado = trim((string) (
                        $alta_reciente['codigo_qr']
                        ?? $cliente_existente['codigo_qr']
                        ?? ''
                    ));

                    $ruta_qr_recuperada = '';
                    if ($codigo_qr_recuperado !== '') {
                        $directorio_qr_recuperado = __DIR__
                            . DIRECTORY_SEPARATOR
                            . 'qrcodes';
                        @mkdir($directorio_qr_recuperado, 0775, true);
                        $archivo_qr_recuperado = preg_replace(
                            '/[^a-zA-Z0-9_-]/',
                            '_',
                            $codigo_qr_recuperado
                        ) . '.png';
                        $ruta_qr_recuperada = $directorio_qr_recuperado
                            . DIRECTORY_SEPARATOR
                            . $archivo_qr_recuperado;

                        if (!is_file($ruta_qr_recuperada)) {
                            generarCodigoQR(
                                $codigo_qr_recuperado,
                                $ruta_qr_recuperada
                            );
                        }
                    }

                    $metodo_guardado = trim((string) (
                        $alta_reciente['metodo_pago_guardado'] ?? ''
                    ));
                    $metodo_mostrado = $metodo_guardado !== ''
                        ? ucfirst($metodo_guardado)
                        : $metodo_pago_descripcion;

                    $correo_recuperado_job = correo_cola_encolar(
                        $conn,
                        'expediente_invitacion',
                        [
                            'email' => $email,
                            'nombre' => trim(
                                (string) ($cliente_existente['nombre'] ?? $nombre)
                                . ' '
                                . (string) ($cliente_existente['apellido'] ?? $apellido)
                            ),
                            'url' => (string) $invitacion_recuperada['url'],
                            'vence_en' => (string) $invitacion_recuperada['vence_en'],
                            'invitacion_id' => (int) $invitacion_recuperada['id'],
                            'datos_inscripcion' => [
                                'plan' => (string) $plan['plan_nombre'],
                                'fecha_inicio' => date(
                                    'd/m/Y',
                                    strtotime($fecha_inicio) ?: time()
                                ),
                                'fecha_fin' => !empty($alta_reciente['fecha_fin'])
                                    ? date(
                                        'd/m/Y',
                                        strtotime((string) $alta_reciente['fecha_fin']) ?: time()
                                    )
                                    : 'Sin vencimiento',
                                'monto' => $precio_pagado,
                                'metodo_pago' => $metodo_mostrado,
                                'codigo_qr' => $codigo_qr_recuperado,
                                'ruta_qr' => $ruta_qr_recuperada,
                                'historial_pago_id' => (int) ($alta_reciente['historial_pago_id'] ?? 0),
                                'documento_pdf' => '',
                            ],
                        ]
                    );
                    correo_cola_disparar_async(
                        (string) $correo_recuperado_job['token']
                    );

                    $_SESSION['mensaje_exito'] =
                        'La inscripción ya había quedado guardada. '
                        . 'Se recuperó el proceso y el cuestionario médico quedó '
                        . 'preparado para enviarse a '
                        . htmlspecialchars($email, ENT_QUOTES, 'UTF-8')
                        . '.';

                    unset($_SESSION['csrf_token']);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

                    header('Location: inscripciones.php');
                    exit;
                }

                if ($alta_reciente) {
                    throw new Exception(
                        'Esta inscripción ya había quedado guardada. '
                        . 'No vuelvas a registrar al socio; localízalo en el '
                        . 'listado para continuar con su expediente o renovación.'
                    );
                }

                throw new Exception(
                    'El socio ya existe con ese teléfono o correo. '
                    . 'Utiliza su registro actual desde Socios o Inscripciones.'
                );
            }

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
            
            // Crear el QR usando una ruta absoluta estable. No depende del
            // directorio actual desde el que Apache ejecute este archivo.
            $qr_dir_absoluto = __DIR__ . DIRECTORY_SEPARATOR . 'qrcodes';
            $qr_dir_relativo = 'qrcodes/';

            if (
                !is_dir($qr_dir_absoluto)
                && !@mkdir($qr_dir_absoluto, 0775, true)
                && !is_dir($qr_dir_absoluto)
            ) {
                throw new RuntimeException(
                    'No fue posible crear la carpeta de códigos QR.'
                );
            }

            $nombre_archivo_qr = preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '_',
                $codigo_qr
            ) . '.png';
            $ruta_qr_absoluta = $qr_dir_absoluto
                . DIRECTORY_SEPARATOR
                . $nombre_archivo_qr;
            $ruta_qr_completa = $qr_dir_relativo . $nombre_archivo_qr;

            $qr_generado = generarCodigoQR(
                $codigo_qr,
                $ruta_qr_absoluta
            );

            if (!$qr_generado) {
                error_log(
                    '[Inscripciones QR] Código ' . $codigo_qr . ': '
                    . obtenerUltimoErrorQR()
                );
            }
            
            $foto_cliente_nueva = '';
            $alta_cliente_confirmada = false;

            if (
                $clientes_tiene_foto
                && isset($_FILES['foto_socio'])
            ) {
                $foto_cliente_nueva = inscripcionesGuardarFotoSocio(
                    $_FILES['foto_socio']
                );
            }

            $conn->begin_transaction();
            
            // Insertar cliente (guardamos QR y foto opcional para credencial)
            if ($clientes_tiene_foto) {
                $stmt = $conn->prepare(
                    "INSERT INTO clientes (
                        sucursal_registro_id,
                        nombre,
                        apellido,
                        telefono,
                        email,
                        foto,
                        contacto_emergencia_nombre,
                        contacto_emergencia_telefono,
                        codigo_qr,
                        estado
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'activo')"
                );
                $stmt->bind_param(
                    "issssssss",
                    $sucursal_id,
                    $nombre,
                    $apellido,
                    $telefono,
                    $email,
                    $foto_cliente_nueva,
                    $contacto_emergencia_nombre,
                    $contacto_emergencia_telefono,
                    $codigo_qr
                );
            } else {
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
            }

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
            $alta_cliente_confirmada = true;

// ========== PREPARAR CUESTIONARIO MÉDICO ==========
$invitacion_cuestionario_salud = null;
$error_cuestionario_salud = '';

if ($solicitar_cuestionario_salud) {
    try {
        $invitacion_cuestionario_salud = expediente_crear_invitacion(
            $conn,
            (int) $cliente_id,
            (int) $inscripcion_id,
            (int) $sucursal_id,
            (int) $usuario_id,
            $email,
            $modo_cuestionario_salud,
            7
        );
    } catch (Throwable $cuestionarioError) {
        $error_cuestionario_salud = $cuestionarioError->getMessage();
        error_log('[Inscripciones cuestionario salud] ' . $error_cuestionario_salud);
    }
}
// ========== FIN CUESTIONARIO MÉDICO ==========

// ========== DOCUMENTO DE MEMBRESÍA ==========
/*
 * Cuando existe un correo válido, el PDF se genera dentro del worker y se
 * adjunta al mensaje. Así FPDF no bloquea el alta y el socio conserva su
 * comprobante de inscripción o renovación.
 */
$diferir_documento_membresia =
    filter_var($email, FILTER_VALIDATE_EMAIL) !== false;

$documento_membresia = [
    'success' => false,
    'deferred' => $diferir_documento_membresia,
];

if (!$diferir_documento_membresia) {
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
            '[Inscripciones] No se pudo generar el PDF de inscripción: '
            . (string) ($documento_membresia['error'] ?? 'Error desconocido')
        );
    }
}
// ========== FIN DOCUMENTO DE MEMBRESÍA ==========

// ========== PROGRAMAR CORREO SIN BLOQUEAR EL ALTA ==========
/*
 * El correo ya no se envía dentro de esta petición. Primero se guarda en una
 * cola persistente y después un worker independiente realiza la conexión SMTP.
 * Así la inscripción responde inmediatamente aunque Gmail tarde o falle.
 */
$correo_programado = false;
$correo_job = null;
$es_correo_cuestionario =
    $solicitar_cuestionario_salud
    && $modo_cuestionario_salud === 'correo'
    && is_array($invitacion_cuestionario_salud);

if (!empty($email)) {
    $nombre_completo = trim($nombre . ' ' . $apellido);

    try {
        if ($es_correo_cuestionario) {
            $correo_job = correo_cola_encolar(
                $conn,
                'expediente_invitacion',
                [
                    'email' => $email,
                    'nombre' => $nombre_completo,
                    'url' => (string) $invitacion_cuestionario_salud['url'],
                    'vence_en' => (string) $invitacion_cuestionario_salud['vence_en'],
                    'invitacion_id' => (int) $invitacion_cuestionario_salud['id'],
                    'datos_inscripcion' => [
                        'plan' => (string) $plan['plan_nombre'],
                        'fecha_inicio' => date('d/m/Y', strtotime($fecha_inicio) ?: time()),
                        'fecha_fin' => $fecha_fin !== null && $fecha_fin !== ''
                            ? date('d/m/Y', strtotime((string) $fecha_fin) ?: time())
                            : 'Sin vencimiento',
                        'monto' => $precio_pagado,
                        'metodo_pago' => $metodo_pago_descripcion,
                        'codigo_qr' => $codigo_qr,
                        'ruta_qr' => $ruta_qr_absoluta,
                        'historial_pago_id' => (int) $historial_pago_id,
                        'documento_pdf' => !empty($documento_membresia['success'])
                            ? (string) ($documento_membresia['path'] ?? '')
                            : '',
                    ],
                ]
            );
        } else {
            $correo_job = correo_cola_encolar(
                $conn,
                'inscripcion_bienvenida',
                [
                    'email' => $email,
                    'nombre' => $nombre_completo,
                    'plan' => (string) $plan['plan_nombre'],
                    'fecha_inicio' => date('d/m/Y', strtotime($fecha_inicio) ?: time()),
                    'fecha_fin' => $fecha_fin !== null && $fecha_fin !== ''
                        ? date('d/m/Y', strtotime((string) $fecha_fin) ?: time())
                        : 'Sin vencimiento',
                    'monto' => $precio_pagado,
                    'metodo_pago' => $metodo_pago_descripcion,
                    'referencia' => (string) ($referencia ?? ''),
                    'codigo_qr' => $codigo_qr,
                    'ruta_qr' => $ruta_qr_absoluta,
                    'historial_pago_id' => (int) $historial_pago_id,
                    'documento_pdf' => !empty($documento_membresia['success'])
                        ? (string) ($documento_membresia['path'] ?? '')
                        : '',
                ]
            );
        }

        /*
         * Solo se encola. El worker del navegador se ejecuta después de que
         * inscripciones.php ya cargó; nunca se llama al propio Apache desde
         * este POST.
         */
        $correo_programado = is_array($correo_job);
        if ($correo_programado && !empty($correo_job['token'])) {
            correo_cola_disparar_async((string) $correo_job['token']);
        }
    } catch (Throwable $correoQueueError) {
        $error_cuestionario_salud = $correoQueueError->getMessage();
        error_log('[Inscripciones cola correo] ' . $correoQueueError->getMessage());
    }
}
// ========== FIN PROGRAMACIÓN DE CORREO ==========

// Mensaje de éxito con información del QR
$mensaje_exito = "Cliente e inscripción creados exitosamente. ";
$mensaje_exito .= "Código QR: <strong>{$codigo_qr}</strong><br>";
if ($qr_generado && is_file($ruta_qr_absoluta)) {
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
} elseif (!empty($documento_membresia['deferred'])) {
    $mensaje_exito .= '<br><span class="text-muted">El comprobante PDF se generará en segundo plano y se adjuntará al correo.</span>';
} else {
    $mensaje_exito .= '<br><span class="text-warning">⚠ La inscripción se guardó, pero no fue posible generar el documento PDF.</span>';
}

// Estado real: preparado en cola. El worker confirmará el envío después.
if (!empty($email)) {
    if ($correo_programado) {
        $mensaje_exito .= $es_correo_cuestionario
            ? "<br><span class='text-success'>✓ La confirmación y el cuestionario quedaron preparados para enviarse a {$email}.</span>"
            : "<br><span class='text-success'>✓ El correo de confirmación quedó preparado para enviarse a {$email}.</span>";
    } else {
        $mensaje_exito .= "<br><span class='text-warning'>⚠ La inscripción se guardó, pero el correo no pudo agregarse a la cola"
            . ($error_cuestionario_salud !== ''
                ? ': ' . htmlspecialchars($error_cuestionario_salud, ENT_QUOTES, 'UTF-8')
                : '.')
            . "</span>";
    }
}

if ($solicitar_cuestionario_salud) {
    if (!is_array($invitacion_cuestionario_salud)) {
        $mensaje_exito .= '<br><span class="text-warning">⚠ La inscripción se guardó, pero no fue posible preparar el cuestionario médico'
            . ($error_cuestionario_salud !== ''
                ? ': ' . htmlspecialchars($error_cuestionario_salud, ENT_QUOTES, 'UTF-8')
                : '.')
            . '</span>';
    } elseif ($modo_cuestionario_salud === 'correo') {
        if ($correo_programado) {
            $mensaje_exito .= '<br><span class="text-success">✓ El enlace privado quedó en la cola de envío.</span>';
        } else {
            $mensaje_exito .= '<br><span class="text-warning">⚠ El enlace fue creado, pero no pudo programarse el correo.</span>';
        }
    } else {
        $mensaje_exito .= '<br><span class="text-success">✓ El cuestionario médico está listo para contestarse ahora.</span>';
    }
}

$_SESSION['mensaje_exito'] = $mensaje_exito;

            // Limpiar token
            unset($_SESSION['csrf_token']);
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            
            if (
                $solicitar_cuestionario_salud
                && $modo_cuestionario_salud === 'recepcion'
                && is_array($invitacion_cuestionario_salud)
            ) {
                header('Location: ' . (string) $invitacion_cuestionario_salud['url']);
                exit;
            }

            header('Location: inscripciones.php');
            exit;
            
        } catch (Throwable $e) {
            if (isset($conn)) {
                try {
                    $conn->rollback();
                } catch (Throwable $rollbackError) {
                }
            }

            if (
                isset($foto_cliente_nueva)
                && $foto_cliente_nueva !== ''
                && empty($alta_cliente_confirmada)
            ) {
                $fotoAbsoluta = __DIR__
                    . DIRECTORY_SEPARATOR
                    . str_replace(
                        '/',
                        DIRECTORY_SEPARATOR,
                        ltrim($foto_cliente_nueva, '/')
                    );

                if (is_file($fotoAbsoluta)) {
                    @unlink($fotoAbsoluta);
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
        
        /*
         * RENOVACIÓN ANTICIPADA.
         *
         * Los planes normales pueden renovarse durante sus últimos siete días.
         * El periodo nuevo comienza al día siguiente del vencimiento actual,
         * por lo que el socio no pierde los días que ya pagó. La fecha inicial
         * histórica de la inscripción se conserva y únicamente se extiende el
         * final de la membresía.
         */
        $fecha_inicio_periodo = $fecha_inicio;
        $fecha_inicio_inscripcion = $fecha_inicio;

        if ($inscripcion_actual['estado'] === 'activa') {
            $hoyRenovacion = new DateTime('today');
            $fechaFinActual = new DateTime(
                (string) $inscripcion_actual['fecha_fin']
            );
            $fechaFinActual->setTime(0, 0, 0);
            $diasParaVencer = (int) $hoyRenovacion
                ->diff($fechaFinActual)
                ->format('%r%a');

            if ((int) $inscripcion_actual['duracion_actual'] === 1) {
                if ($diasParaVencer >= 0) {
                    throw new Exception(
                        'El plan de un día todavía está vigente. Registra la siguiente visita cuando termine el día actual.'
                    );
                }
            } elseif ($diasParaVencer >= 0) {
                if ($diasParaVencer > 7) {
                    throw new Exception(
                        'Esta inscripción todavía no puede renovarse. La renovación se habilita durante sus últimos 7 días.'
                    );
                }

                $fechaInicioSiguiente = clone $fechaFinActual;
                $fechaInicioSiguiente->modify('+1 day');
                $fecha_inicio_periodo =
                    $fechaInicioSiguiente->format('Y-m-d');
                $fecha_inicio = $fecha_inicio_periodo;
                $fecha_inicio_inscripcion = (string) (
                    $inscripcion_actual['fecha_inicio']
                    ?? $fecha_inicio_periodo
                );
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
            $fecha_inicio_inscripcion,
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
            $qr_dir_cliente = __DIR__ . DIRECTORY_SEPARATOR . 'qrcodes';
            @mkdir($qr_dir_cliente, 0775, true);

            $nombre_archivo_qr = preg_replace(
                '/[^a-zA-Z0-9_-]/',
                '_',
                $codigo_qr_cliente
            ) . '.png';
            $ruta_qr_cliente = $qr_dir_cliente
                . DIRECTORY_SEPARATOR
                . $nombre_archivo_qr;

            if (!is_file($ruta_qr_cliente)) {
                generarCodigoQR($codigo_qr_cliente, $ruta_qr_cliente);
            }
        }

// Generar el comprobante local antes de encolar el correo.
// Esta operación usa FPDF y archivos locales; no abre conexión SMTP y suele
// completarse rápidamente. Así la ventana que el navegador abrió al enviar
// el formulario recibe inmediatamente la URL real del comprobante, mientras
// el correo continúa enviándose en un proceso separado.
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

// Programar el correo de renovación sin bloquear la respuesta.
$correo_renovacion_programado = false;
if (!empty($email_cliente)) {
    $nombre_completo = trim($cliente_data['nombre'] . ' ' . $cliente_data['apellido']);
    try {
        $correo_renovacion_job = correo_cola_encolar(
            $conn,
            'inscripcion_renovacion',
            [
                'email' => $email_cliente,
                'nombre' => $nombre_completo,
                'plan' => (string) $plan['plan_nombre'],
                'fecha_inicio' => date('d/m/Y', strtotime($fecha_inicio) ?: time()),
                'fecha_fin' => $fecha_fin !== null && $fecha_fin !== ''
                    ? date('d/m/Y', strtotime((string) $fecha_fin) ?: time())
                    : 'Sin vencimiento',
                'monto' => $precio_pagado,
                'metodo_pago' => $metodo_pago_descripcion,
                'referencia' => (string) ($referencia ?? ''),
                'codigo_qr' => $codigo_qr_cliente,
                'ruta_qr' => $ruta_qr_cliente,
                'historial_pago_id' => (int) $historial_pago_id,
                'documento_pdf' => !empty($documento_membresia['success'])
                    ? (string) ($documento_membresia['path'] ?? '')
                    : '',
            ]
        );
        $correo_renovacion_programado = true;
        correo_cola_disparar_async(
            (string) $correo_renovacion_job['token']
        );
        // El worker se ejecutará después de la redirección.
    } catch (Throwable $correoRenovacionError) {
        error_log('[Renovación cola correo] ' . $correoRenovacionError->getMessage());
        $_SESSION['warning_correo'] = 'La renovación se guardó, pero el correo no pudo programarse: ' . $correoRenovacionError->getMessage();
    }
}

        // Limpiar la marca de tiempo después de procesar
        unset($_SESSION[$clave_renovacion]);

        // Guardar mensaje en sesión
        if (!empty($email_cliente) && $correo_renovacion_programado) {
            $mensaje_renovacion = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. El correo quedó preparado para enviarse en segundo plano.';
        } elseif (!empty($email_cliente) && !$correo_renovacion_programado) {
            $mensaje_renovacion = 'Inscripción renovada exitosamente con el plan ' . $plan['plan_nombre'] . '. El correo no pudo agregarse a la cola.';
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
                '" target="_blank" rel="noopener"><i class="fas fa-file-pdf"></i> Abrir documento de renovación</a>';
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

$campo_foto_cliente = $clientes_tiene_foto
    ? "c.foto AS cliente_foto,"
    : "NULL AS cliente_foto,";

$query = "SELECT
              i.*,
              c.nombre AS cliente_nombre,
              c.apellido AS cliente_apellido,
              c.telefono AS cliente_telefono,
              c.codigo_qr AS cliente_codigo_qr,
              {$campo_foto_cliente}
              c.estado AS cliente_estado,
              p.nombre AS plan_nombre,
              p.duracion_dias,
              s.nombre AS sucursal_nombre,
              s.clave AS sucursal_clave,
              s.logo AS sucursal_logo,
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
 * Identidad visual para la credencial.
 * Prioridad: logo propio de sucursal -> logo corporativo -> logo por defecto.
 */
$credencial_config_gym = [
    'nombre' => 'EGO',
    'logo' => '',
];

try {
    $resultadoCredencialGym = $conn->query(
        "SELECT nombre, logo
         FROM configuracion_gimnasio
         WHERE id = 1
         LIMIT 1"
    );

    if (
        $resultadoCredencialGym instanceof mysqli_result
        && ($filaCredencialGym = $resultadoCredencialGym->fetch_assoc())
    ) {
        $credencial_config_gym = array_merge(
            $credencial_config_gym,
            $filaCredencialGym
        );
    }
} catch (Throwable $credencialConfigError) {
    error_log(
        '[Credencial socio] No se pudo leer configuracion_gimnasio: '
        . $credencialConfigError->getMessage()
    );
}

$credencial_gym_nombre = trim((string) (
    $credencial_config_gym['nombre'] ?? 'EGO'
));

if ($credencial_gym_nombre === '') {
    $credencial_gym_nombre = 'EGO';
}

$credencial_logo_corporativo = inscripcionesRutaImagenPublica(
    (string) ($credencial_config_gym['logo'] ?? '')
);

if ($credencial_logo_corporativo === '') {
    $credencial_logo_corporativo = inscripcionesRutaImagenPublica(
        'img/logo-gym.png'
    );
}

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

// Tokens de los correos creados en el POST anterior. Se consumen una sola vez
// y se procesan después de que esta página ya fue entregada al navegador.
$correo_tokens_async = correo_cola_extraer_tokens_async();
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
    
        /* ===== Credencial socio CR80: 85.60 x 53.98 mm ===== */
        .credencial-modal-dialog{max-width:1040px}
        .credencial-modal-body{padding:22px 18px 14px;background:#f5f7fb}
        .credencial-preview-shell{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:18px;align-items:start;width:100%}
        .credencial-side-preview{display:grid;gap:8px;justify-items:center;min-width:0}
        .credencial-side-label{margin:0;color:#667085;font-size:.72rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
        .credencial-socio-card{
            position:relative;display:flex;flex-direction:column;width:85.60mm;height:53.98mm;max-width:100%;overflow:hidden;
            border:.28mm solid #d7deea;border-radius:3.18mm;background:radial-gradient(circle at 12% 18%,rgba(37,99,235,.08),transparent 28%),linear-gradient(135deg,#fff 0%,#f8faff 100%);
            box-shadow:0 12px 28px rgba(28,45,80,.14);isolation:isolate
        }
        .credencial-socio-card:after{content:"";position:absolute;right:-16mm;bottom:-20mm;width:44mm;height:44mm;border:7mm solid rgba(30,64,175,.045);border-radius:50%;pointer-events:none;z-index:-1}
        .credencial-top-line{flex:0 0 3.6mm;background:linear-gradient(90deg,#17366f,#2f66b3)}
        .credencial-head{display:flex;align-items:center;justify-content:space-between;gap:3mm;min-height:10.5mm;padding:1.8mm 4mm 1.4mm;border-bottom:.25mm solid #e4e9f1}
        .credencial-brand{display:flex;align-items:center;min-width:0;gap:2.2mm}
        .credencial-brand-logo-wrap{display:grid;place-items:center;flex:0 0 8.8mm;width:8.8mm;height:8.8mm;overflow:hidden;border-radius:2mm;background:#fff}
        .credencial-brand-logo{width:100%;height:100%;object-fit:contain}
        .credencial-brand-logo-fallback{display:none;align-items:center;justify-content:center;width:100%;height:100%;color:#254a8e;font-size:4.2mm}
        .credencial-brand-copy{min-width:0}
        .credencial-gym-name{display:block;max-width:45mm;overflow:hidden;color:#17366f;font-size:3.65mm;font-weight:900;line-height:1.05;text-overflow:ellipsis;white-space:nowrap}
        .credencial-gym-caption{display:block;margin-top:.65mm;color:#7a8597;font-size:2.15mm;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
        .credencial-type-chip{flex:0 0 auto;padding:1.25mm 2.2mm;border:.25mm solid #cbd9f4;border-radius:999px;background:#eef4ff;color:#244a91;font-size:2.1mm;font-weight:900;letter-spacing:.1em;text-transform:uppercase}
        .credencial-main{display:grid;grid-template-columns:19.5mm minmax(0,1fr) 23.5mm;align-items:center;gap:2.8mm;flex:1 1 auto;min-height:0;padding:2.3mm 4mm 1.9mm}
        .credencial-person-wrap{display:grid;place-items:center;width:19.5mm;height:22.5mm;overflow:hidden;border:.3mm solid #d7dfec;border-radius:2.4mm;background:#edf2f8}
        .credencial-person-image{display:block;width:100%;height:100%;object-fit:cover}
        .credencial-person-image.is-logo{padding:2.3mm;object-fit:contain;background:#fff}
        .credencial-person-fallback{display:none;align-items:center;justify-content:center;width:100%;height:100%;color:#31548f;font-size:8mm}
        .credencial-member-copy{min-width:0;align-self:center}
        .credencial-member-label{margin-bottom:.85mm;color:#8993a3;font-size:2mm;font-weight:800;letter-spacing:.12em;text-transform:uppercase}
        .credencial-member-name{display:-webkit-box;overflow:hidden;margin:0;color:#172033;font-size:4.05mm;font-weight:900;line-height:1.05;-webkit-box-orient:vertical;-webkit-line-clamp:2}
        .credencial-member-branch{display:flex;align-items:center;gap:1.1mm;max-width:28mm;margin-top:2mm;color:#607087;font-size:2.35mm;font-weight:700;line-height:1.15}
        .credencial-member-branch i{flex:0 0 auto;color:#2f66b3}
        .credencial-member-branch span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .credencial-qr-wrap{display:grid;place-items:center;width:23.5mm;height:23.5mm;padding:1.25mm;overflow:hidden;border:.28mm solid #dce3ee;border-radius:2.3mm;background:#fff}
        .credencial-qr-image{width:100%;height:100%;object-fit:contain}
        .credencial-qr-fallback{display:none;place-items:center;width:100%;height:100%;color:#9aa5b5;text-align:center;font-size:4.5mm}
        .credencial-footer{display:flex;align-items:center;justify-content:space-between;gap:2mm;flex:0 0 6mm;padding:0 4mm;border-top:.25mm solid #e4e9f1;background:rgba(248,250,252,.86);color:#68758a;font-size:2mm}
        .credencial-code{max-width:47mm;overflow:hidden;color:#26354d;font-family:"Courier New",monospace;font-size:2.15mm;font-weight:800;letter-spacing:.025em;text-overflow:ellipsis;white-space:nowrap}
        .credencial-footer-note{flex:0 0 auto;font-weight:700}
        .credencial-back-card{
            background:
                radial-gradient(circle at 88% 18%,rgba(47,102,179,.08),transparent 20%),
                radial-gradient(circle at 12% 88%,rgba(47,102,179,.05),transparent 24%),
                linear-gradient(135deg,#ffffff 0%,#f8faff 100%);
            color:#172033;
            border-color:#d7deea;
        }
        .credencial-back-card:before{
            content:"";
            position:absolute;
            right:-15mm;
            bottom:-19mm;
            width:40mm;
            height:40mm;
            border:6mm solid rgba(30,64,175,.035);
            border-radius:50%;
            pointer-events:none;
        }
        .credencial-back-card:after{
            content:"";
            position:absolute;
            left:-12mm;
            top:14mm;
            width:28mm;
            height:28mm;
            border:5mm solid rgba(47,102,179,.025);
            border-radius:50%;
            pointer-events:none;
        }
        .credencial-back-head{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:3mm;
            flex:0 0 10mm;
            min-height:0;
            padding:1.45mm 4mm 1.15mm;
            border-bottom:.25mm solid #e4e9f1;
        }
        .credencial-back-brand{display:flex;align-items:center;gap:2.2mm;min-width:0}
        .credencial-back-logo-wrap{display:grid;place-items:center;flex:0 0 8mm;width:8mm;height:8mm;overflow:hidden;border-radius:1.8mm;background:#fff}
        .credencial-back-logo{width:100%;height:100%;object-fit:contain}
        .credencial-back-logo-fallback{display:none;align-items:center;justify-content:center;width:100%;height:100%;color:#244a91;font-size:3.3mm;font-weight:900}
        .credencial-back-gym{min-width:0}
        .credencial-back-gym strong{display:block;max-width:45mm;overflow:hidden;color:#17366f;font-size:3.35mm;font-weight:900;line-height:1;text-overflow:ellipsis;white-space:nowrap}
        .credencial-back-gym span{display:block;margin-top:.45mm;color:#7a8597;font-size:1.8mm;font-weight:700;letter-spacing:.08em;text-transform:uppercase}
        .credencial-back-chip{flex:0 0 auto;padding:1.05mm 2mm;border:.25mm solid #cbd9f4;border-radius:999px;background:#eef4ff;color:#244a91;font-size:1.8mm;font-weight:900;letter-spacing:.1em;text-transform:uppercase}

        .credencial-back-main{
            display:flex;
            align-items:center;
            justify-content:center;
            flex:1 1 auto;
            min-height:0;
            padding:2.4mm 5mm 2mm;
            overflow:hidden;
        }
        .credencial-back-simple{
            display:grid;
            grid-template-columns:12mm minmax(0,1fr);
            align-items:center;
            gap:3mm;
            width:100%;
            min-width:0;
        }
        .credencial-back-symbol{
            display:grid;
            place-items:center;
            width:12mm;
            height:12mm;
            border:.3mm solid #cfe0fa;
            border-radius:50%;
            background:#eef5ff;
            color:#2f66b3;
            font-size:5mm;
        }
        .credencial-back-simple-copy{min-width:0}
        .credencial-back-simple-copy h6{
            margin:0;
            color:#172033;
            font-size:3.15mm;
            font-weight:900;
            line-height:1.05;
        }
        .credencial-back-simple-copy > p{
            margin:1.1mm 0 0;
            color:#66758a;
            font-size:2mm;
            font-weight:700;
            line-height:1.3;
        }
        .credencial-back-simple-rules{
            display:flex;
            align-items:center;
            flex-wrap:wrap;
            gap:1mm 2.4mm;
            margin-top:2.2mm;
        }
        .credencial-back-simple-rule{
            display:inline-flex;
            align-items:center;
            gap:1mm;
            color:#42526a;
            font-size:1.85mm;
            font-weight:800;
            line-height:1.15;
        }
        .credencial-back-simple-rule i{
            flex:0 0 auto;
            color:#2f66b3;
            font-size:1.9mm;
        }
        .credencial-back-footer{
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:2mm;
            flex:0 0 5.3mm;
            min-height:0;
            padding:0 4mm;
            border-top:.25mm solid #e4e9f1;
            background:rgba(248,250,252,.92);
            color:#68758a;
            font-size:1.75mm;
        }
        .credencial-back-footer-note{
            display:flex;
            align-items:center;
            gap:1mm;
            min-width:0;
            font-weight:700;
        }
        .credencial-back-footer-note i{flex:0 0 auto;color:#2f66b3;font-size:1.9mm}
        .credencial-back-footer-note span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
        .credencial-back-footer-status{flex:0 0 auto;color:#244a91;font-size:1.9mm;font-weight:900;letter-spacing:.09em;text-transform:uppercase}
        .credencial-size-hint{grid-column:1/-1;margin:0;color:#758196;font-size:.74rem;text-align:center}
        .credencial-modal-footer{display:flex;justify-content:space-between;gap:10px}

        .member-photo-upload{display:flex;align-items:center;gap:13px;margin:2px 0 16px;padding:12px 14px;border:1px solid #dce3ee;border-radius:10px;background:#f8fafc}
        .member-photo-preview{display:grid;place-items:center;flex:0 0 72px;width:72px;height:82px;overflow:hidden;border:1px solid #d6deea;border-radius:11px;background:#eef3f9;color:#5d6c82}
        .member-photo-preview img{display:none;width:100%;height:100%;object-fit:cover}
        .member-photo-preview i{font-size:1.35rem}
        .member-photo-upload-copy{flex:1 1 auto;min-width:0}
        .member-photo-upload-copy .form-label{margin-bottom:5px}
        .member-photo-upload-copy small{display:block;margin-top:5px;color:#768195;line-height:1.35}
        .member-photo-input-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:7px}
        .member-photo-input-actions .btn{padding:6px 10px;font-size:.76rem}
        @media(max-width:991.98px){.credencial-modal-dialog{max-width:620px}.credencial-preview-shell{grid-template-columns:1fr}.credencial-size-hint{grid-column:auto}}
        @media(max-width:420px){.credencial-modal-body{padding-left:8px;padding-right:8px}.credencial-side-preview{justify-items:start}.credencial-preview-shell{justify-items:start;overflow-x:auto}.member-photo-upload{align-items:flex-start}.member-photo-preview{flex-basis:62px;width:62px;height:72px}}
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
                            $nombre_cliente_qr = trim(
                                $ins['cliente_nombre'] . ' ' . $ins['cliente_apellido']
                            );
                            $codigo_cliente_qr = trim((string) (
                                $ins['cliente_codigo_qr'] ?? ''
                            ));
                            $archivo_cliente_qr = $codigo_cliente_qr !== ''
                                ? 'qrcodes/' . preg_replace(
                                    '/[^a-zA-Z0-9_-]/',
                                    '_',
                                    $codigo_cliente_qr
                                ) . '.png'
                                : '';

                            $foto_cliente_credencial = inscripcionesRutaImagenPublica(
                                (string) ($ins['cliente_foto'] ?? '')
                            );
                            $logo_sucursal_credencial = inscripcionesRutaImagenPublica(
                                (string) ($ins['sucursal_logo'] ?? '')
                            );
                            $logo_credencial = $logo_sucursal_credencial !== ''
                                ? $logo_sucursal_credencial
                                : $credencial_logo_corporativo;
                            $sucursal_credencial = trim((string) (
                                $ins['sucursal_nombre'] ?? $sucursal_nombre
                            ));
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
                                            class="btn-accion btn-qr btn-credencial"
                                            data-bs-toggle="modal"
                                            data-bs-target="#modalCredencial"
                                            data-cliente="<?php echo htmlspecialchars($nombre_cliente_qr, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-codigo="<?php echo htmlspecialchars($codigo_cliente_qr, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-ruta="<?php echo htmlspecialchars($archivo_cliente_qr, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-foto="<?php echo htmlspecialchars($foto_cliente_credencial, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-logo="<?php echo htmlspecialchars($logo_credencial, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-gym="<?php echo htmlspecialchars($credencial_gym_nombre, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-sucursal="<?php echo htmlspecialchars($sucursal_credencial, ENT_QUOTES, 'UTF-8'); ?>"
                                            title="Ver credencial del socio"
                                            <?php echo $codigo_cliente_qr === '' ? 'disabled' : ''; ?>
                                        >
                                            <i class="fas fa-id-card"></i> <span>Credencial</span>
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
                <form id="formNuevoCliente" method="POST" enctype="multipart/form-data">
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
                        
                        <?php if ($clientes_tiene_foto): ?>
                        <div class="member-photo-upload">
                            <div class="member-photo-preview" id="fotoSocioPreviewWrap" aria-hidden="true">
                                <img id="fotoSocioPreview" alt="Vista previa de la foto del socio">
                                <i class="fas fa-user" id="fotoSocioPreviewIcon"></i>
                            </div>
                            <div class="member-photo-upload-copy">
                                <label class="form-label" for="foto_socio">
                                    Foto para credencial
                                    <span class="text-muted fw-normal">(opcional)</span>
                                </label>
                                <input
                                    type="file"
                                    class="form-control"
                                    name="foto_socio"
                                    id="foto_socio"
                                    accept="image/jpeg,image/png,image/webp"
                                    capture="user"
                                >
                                <div class="member-photo-input-actions">
                                    <button type="button" class="btn btn-outline-secondary btn-sm" id="btnQuitarFotoSocio" hidden>
                                        <i class="fas fa-trash-can"></i> Quitar foto
                                    </button>
                                </div>
                                <small>
                                    Si no agregas foto,
                                    la credencial mostrará automáticamente el logo de la sucursal o del gimnasio.
                                </small>
                            </div>
                        </div>
                        <?php endif; ?>

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


                        <section class="health-enrollment-section" aria-labelledby="healthEnrollmentTitle">
                            <label class="health-enrollment-toggle" for="solicitar_cuestionario_salud">
                                <input
                                    type="checkbox"
                                    name="solicitar_cuestionario_salud"
                                    id="solicitar_cuestionario_salud"
                                    value="1"
                                >
                                <span class="health-enrollment-check"><i class="fas fa-check"></i></span>
                                <span class="health-enrollment-copy">
                                    <strong id="healthEnrollmentTitle">Completar expediente de salud</strong>
                                    <small>Activa esta opción para responder el cuestionario médico y aceptar el documento de responsabilidad.</small>
                                </span>
                                <span class="health-enrollment-icon"><i class="fas fa-heart-pulse"></i></span>
                            </label>

                            <div class="health-enrollment-options" id="healthEnrollmentOptions" hidden>
                                <div class="health-enrollment-options-title">¿Cómo deseas completarlo?</div>
                                <div class="health-enrollment-methods">
                                    <label class="health-enrollment-method">
                                        <input type="radio" name="modo_cuestionario_salud" value="recepcion" checked>
                                        <span>
                                            <i class="fas fa-tablet-screen-button"></i>
                                            <strong>Contestar ahora</strong>
                                            <small>Al terminar la inscripción se abrirá el cuestionario en este equipo.</small>
                                        </span>
                                    </label>
                                    <label class="health-enrollment-method" id="healthEmailMethod">
                                        <input type="radio" name="modo_cuestionario_salud" value="correo">
                                        <span>
                                            <i class="fas fa-envelope"></i>
                                            <strong>Enviar por correo</strong>
                                            <small>El socio recibirá un enlace privado y, al finalizar, su PDF completo.</small>
                                        </span>
                                    </label>
                                </div>
                                <div class="health-enrollment-note" id="healthEnrollmentEmailNote">
                                    <i class="fas fa-circle-info"></i>
                                    <span>El correo del formulario se utilizará para enviar el enlace y la copia final del expediente.</span>
                                </div>
                            </div>
                        </section>
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
    
    <!-- Modal Credencial del socio -->
    <div class="modal fade" id="modalCredencial" tabindex="-1" aria-labelledby="modalCredencialTitulo" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered credencial-modal-dialog">
            <div class="modal-content">
                <div class="modal-header modal-header-brand">
                    <h5 class="modal-title" id="modalCredencialTitulo">
                        <i class="fas fa-id-card"></i> Credencial del socio
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
                </div>

                <div class="modal-body credencial-modal-body">
                    <div class="credencial-preview-shell">
                        <section class="credencial-side-preview" aria-label="Frente de la credencial">
                            <p class="credencial-side-label">Frente</p>
                            <article class="credencial-socio-card" id="credencialSocio">
                                <div class="credencial-top-line"></div>

                                <header class="credencial-head">
                                    <div class="credencial-brand">
                                        <div class="credencial-brand-logo-wrap">
                                            <img id="credencialBrandLogo" class="credencial-brand-logo" alt="Logo del gimnasio">
                                            <div id="credencialBrandFallback" class="credencial-brand-logo-fallback" aria-hidden="true">
                                                <i class="fas fa-dumbbell"></i>
                                            </div>
                                        </div>
                                        <div class="credencial-brand-copy">
                                            <strong class="credencial-gym-name" id="credencialGymNombre">EGO</strong>
                                            <span class="credencial-gym-caption">Credencial de acceso</span>
                                        </div>
                                    </div>
                                    <span class="credencial-type-chip">Socio</span>
                                </header>

                                <div class="credencial-main">
                                    <div class="credencial-person-wrap">
                                        <img id="credencialFoto" class="credencial-person-image" alt="Foto del socio">
                                        <div id="credencialFotoFallback" class="credencial-person-fallback" aria-hidden="true">
                                            <i class="fas fa-user"></i>
                                        </div>
                                    </div>

                                    <div class="credencial-member-copy">
                                        <div class="credencial-member-label">Nombre del socio</div>
                                        <h6 class="credencial-member-name" id="credencialCliente">Socio</h6>
                                        <div class="credencial-member-branch">
                                            <i class="fas fa-location-dot"></i>
                                            <span id="credencialSucursal">Sucursal</span>
                                        </div>
                                    </div>

                                    <div class="credencial-qr-wrap">
                                        <img id="credencialQr" class="credencial-qr-image" alt="Código QR del socio">
                                        <div id="credencialQrFallback" class="credencial-qr-fallback" aria-hidden="true">
                                            <i class="fas fa-qrcode"></i>
                                        </div>
                                    </div>
                                </div>

                                <footer class="credencial-footer">
                                    <code class="credencial-code" id="credencialCodigo">—</code>
                                    <span class="credencial-footer-note">Acceso mediante QR</span>
                                </footer>
                            </article>
                        </section>

                        <section class="credencial-side-preview" aria-label="Reverso de la credencial">
                            <p class="credencial-side-label">Reverso</p>
                            <article class="credencial-socio-card credencial-back-card" id="credencialReverso">
                                <div class="credencial-top-line"></div>

                                <header class="credencial-back-head">
                                    <div class="credencial-back-brand">
                                        <div class="credencial-back-logo-wrap">
                                            <img id="credencialBackLogo" class="credencial-back-logo" alt="Logo del gimnasio">
                                            <div id="credencialBackLogoFallback" class="credencial-back-logo-fallback" aria-hidden="true">GYM</div>
                                        </div>
                                        <div class="credencial-back-gym">
                                            <strong id="credencialBackGymNombre">EGO</strong>
                                            <span>Información de la credencial</span>
                                        </div>
                                    </div>
                                    <span class="credencial-back-chip">Acceso</span>
                                </header>

                                <div class="credencial-back-main">
                                    <div class="credencial-back-simple">
                                        <div class="credencial-back-symbol" aria-hidden="true">
                                            <i class="fas fa-shield-halved"></i>
                                        </div>

                                        <div class="credencial-back-simple-copy">
                                            <h6>Credencial de acceso</h6>
                                            <p>Personal e intransferible. Preséntala al ingresar al gimnasio.</p>

                                            <div class="credencial-back-simple-rules">
                                                <span class="credencial-back-simple-rule">
                                                    <i class="fas fa-circle-check"></i>
                                                    Vigencia validada en sistema
                                                </span>
                                                <span class="credencial-back-simple-rule">
                                                    <i class="fas fa-rotate"></i>
                                                    Reposición en recepción
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <footer class="credencial-back-footer">
                                    <div class="credencial-back-footer-note">
                                        <i class="fas fa-circle-info"></i>
                                        <span>En caso de extravío, repórtala en recepción.</span>
                                    </div>
                                    <span class="credencial-back-footer-status">Acceso</span>
                                </footer>
                            </article>
                        </section>

                        <p class="credencial-size-hint">
                            Cada cara mide 85.60 × 53.98 mm (CR80/PVC). La impresión coloca frente y reverso al tamaño real para recortar y enmicar.
                        </p>
                    </div>
                </div>

                <div class="modal-footer credencial-modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cerrar</button>
                    <button type="button" class="btn btn-primary" id="btnImprimirCredencial">
                        <i class="fas fa-print"></i> Imprimir frente y reverso
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

        const modalCredencialElement = document.getElementById('modalCredencial');
        const credencialQr = document.getElementById('credencialQr');
        const credencialQrFallback = document.getElementById('credencialQrFallback');
        const credencialFoto = document.getElementById('credencialFoto');
        const credencialFotoFallback = document.getElementById('credencialFotoFallback');
        const credencialBrandLogo = document.getElementById('credencialBrandLogo');
        const credencialBrandFallback = document.getElementById('credencialBrandFallback');
        const credencialBackLogo = document.getElementById('credencialBackLogo');
        const credencialBackLogoFallback = document.getElementById('credencialBackLogoFallback');
        const credencialCliente = document.getElementById('credencialCliente');
        const credencialCodigo = document.getElementById('credencialCodigo');
        const credencialGymNombre = document.getElementById('credencialGymNombre');
        const credencialSucursal = document.getElementById('credencialSucursal');
        const credencialBackGymNombre = document.getElementById('credencialBackGymNombre');
        const btnImprimirCredencial = document.getElementById('btnImprimirCredencial');

        const fotoSocioInput = document.getElementById('foto_socio');
        const fotoSocioPreview = document.getElementById('fotoSocioPreview');
        const fotoSocioPreviewIcon = document.getElementById('fotoSocioPreviewIcon');
        const btnQuitarFotoSocio = document.getElementById('btnQuitarFotoSocio');
        let fotoSocioPreviewUrl = '';

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

        function urlAbsolutaCredencial(ruta) {
            const valor = String(ruta || '').trim();
            if (!valor) return '';

            try {
                return new URL(valor, window.location.href).href;
            } catch (error) {
                return '';
            }
        }

        function mostrarFallbackImagen(imagen, fallback) {
            if (imagen) {
                imagen.classList.add('d-none');
                imagen.removeAttribute('src');
            }
            if (fallback) {
                fallback.style.display = 'flex';
            }
        }

        function limpiarVistaPreviaFotoSocio() {
            if (fotoSocioPreviewUrl) {
                URL.revokeObjectURL(fotoSocioPreviewUrl);
                fotoSocioPreviewUrl = '';
            }

            if (fotoSocioPreview) {
                fotoSocioPreview.removeAttribute('src');
                fotoSocioPreview.style.display = 'none';
            }

            if (fotoSocioPreviewIcon) {
                fotoSocioPreviewIcon.style.display = '';
            }

            if (btnQuitarFotoSocio) {
                btnQuitarFotoSocio.hidden = true;
            }
        }

        if (fotoSocioInput) {
            fotoSocioInput.addEventListener('change', function() {
                limpiarVistaPreviaFotoSocio();

                const archivo = this.files && this.files[0] ? this.files[0] : null;
                if (!archivo) return;

                const formatosPermitidos = ['image/jpeg', 'image/png', 'image/webp'];
                if (!formatosPermitidos.includes(archivo.type)) {
                    this.value = '';
                    Swal.fire('Formato no válido', 'La foto debe ser JPG, PNG o WEBP.', 'warning');
                    return;
                }

                if (archivo.size > 5 * 1024 * 1024) {
                    this.value = '';
                    Swal.fire('Foto demasiado grande', 'La foto debe pesar máximo 5 MB.', 'warning');
                    return;
                }

                fotoSocioPreviewUrl = URL.createObjectURL(archivo);
                fotoSocioPreview.src = fotoSocioPreviewUrl;
                fotoSocioPreview.style.display = 'block';
                fotoSocioPreviewIcon.style.display = 'none';
                btnQuitarFotoSocio.hidden = false;
            });
        }

        if (btnQuitarFotoSocio) {
            btnQuitarFotoSocio.addEventListener('click', function() {
                if (fotoSocioInput) fotoSocioInput.value = '';
                limpiarVistaPreviaFotoSocio();
            });
        }

        function cargarLogoMarcaCredencial(logo) {
            const ruta = urlAbsolutaCredencial(logo);
            const configuraciones = [
                [credencialBrandLogo, credencialBrandFallback],
                [credencialBackLogo, credencialBackLogoFallback]
            ];

            configuraciones.forEach(function(par) {
                const imagen = par[0];
                const fallback = par[1];
                if (!imagen || !fallback) return;

                fallback.style.display = 'none';
                imagen.classList.remove('d-none');

                if (!ruta) {
                    mostrarFallbackImagen(imagen, fallback);
                    return;
                }

                imagen.src = ruta;
            });
        }

        function cargarFotoCredencial(foto, logo) {
            const fotoAbsoluta = urlAbsolutaCredencial(foto);
            const logoAbsoluto = urlAbsolutaCredencial(logo);

            credencialFotoFallback.style.display = 'none';
            credencialFoto.classList.remove('d-none', 'is-logo');
            credencialFoto.dataset.logoFallback = logoAbsoluto;
            credencialFoto.dataset.esFoto = fotoAbsoluta ? '1' : '0';

            if (fotoAbsoluta) {
                credencialFoto.src = fotoAbsoluta;
                credencialFoto.alt = 'Foto del socio';
                return;
            }

            if (logoAbsoluto) {
                credencialFoto.classList.add('is-logo');
                credencialFoto.src = logoAbsoluto;
                credencialFoto.alt = 'Logo del gimnasio';
                return;
            }

            mostrarFallbackImagen(credencialFoto, credencialFotoFallback);
        }

        [
            [credencialBrandLogo, credencialBrandFallback],
            [credencialBackLogo, credencialBackLogoFallback]
        ].forEach(function(par) {
            const imagen = par[0];
            const fallback = par[1];
            if (!imagen) return;
            imagen.addEventListener('error', function() {
                mostrarFallbackImagen(imagen, fallback);
            });
        });

        if (credencialFoto) {
            credencialFoto.addEventListener('error', function() {
                const logoFallback = String(credencialFoto.dataset.logoFallback || '');
                const veniaDeFoto = credencialFoto.dataset.esFoto === '1';

                if (veniaDeFoto && logoFallback) {
                    credencialFoto.dataset.esFoto = '0';
                    credencialFoto.classList.add('is-logo');
                    credencialFoto.src = logoFallback;
                    credencialFoto.alt = 'Logo del gimnasio';
                    return;
                }

                mostrarFallbackImagen(credencialFoto, credencialFotoFallback);
            });
        }

        if (credencialQr) {
            credencialQr.addEventListener('error', function() {
                credencialQr.classList.add('d-none');
                credencialQrFallback.style.display = 'grid';
            });
        }

        if (modalCredencialElement) {
            modalCredencialElement.addEventListener('show.bs.modal', function(event) {
                const boton = event.relatedTarget;
                const cliente = boton ? boton.getAttribute('data-cliente') : '';
                const codigo = boton ? boton.getAttribute('data-codigo') : '';
                const rutaQr = boton ? boton.getAttribute('data-ruta') : '';
                const foto = boton ? boton.getAttribute('data-foto') : '';
                const logo = boton ? boton.getAttribute('data-logo') : '';
                const gym = boton ? boton.getAttribute('data-gym') : '';
                const sucursal = boton ? boton.getAttribute('data-sucursal') : '';

                const clienteMostrar = cliente || 'Socio';
                const codigoMostrar = codigo || 'Sin código';
                const gymMostrar = gym || 'EGO';
                const sucursalMostrar = sucursal || 'Sucursal';

                credencialCliente.textContent = clienteMostrar;
                credencialCodigo.textContent = codigoMostrar;
                credencialGymNombre.textContent = gymMostrar;
                credencialSucursal.textContent = sucursalMostrar;
                credencialBackGymNombre.textContent = gymMostrar;

                cargarLogoMarcaCredencial(logo);
                cargarFotoCredencial(foto, logo);

                credencialQrFallback.style.display = 'none';
                credencialQr.classList.remove('d-none');

                const qrAbsoluto = urlAbsolutaCredencial(rutaQr);
                if (qrAbsoluto) {
                    credencialQr.src = qrAbsoluto
                        + (qrAbsoluto.indexOf('?') >= 0 ? '&' : '?')
                        + 'v=' + Date.now();
                } else {
                    credencialQr.classList.add('d-none');
                    credencialQr.removeAttribute('src');
                    credencialQrFallback.style.display = 'grid';
                }
            });
        }

        function construirCredencialImpresion() {
            const cliente = credencialCliente.textContent || 'Socio';
            const codigo = credencialCodigo.textContent || '';
            const gym = credencialGymNombre.textContent || 'EGO';
            const sucursal = credencialSucursal.textContent || 'Sucursal';

            const qrDisponible = credencialQr
                && !credencialQr.classList.contains('d-none')
                && credencialQr.src;

            if (!qrDisponible) {
                Swal.fire('QR no disponible', 'No se encontró la imagen del QR para imprimir la credencial.', 'warning');
                return '';
            }

            const logoMarcaDisponible = credencialBrandLogo
                && !credencialBrandLogo.classList.contains('d-none')
                && credencialBrandLogo.src;

            const fotoDisponible = credencialFoto
                && !credencialFoto.classList.contains('d-none')
                && credencialFoto.src;

            const personaEsLogo = fotoDisponible && credencialFoto.classList.contains('is-logo');

            const logoMarcaHtml = logoMarcaDisponible
                ? `<img class="brand-logo" src="${escaparHtml(credencialBrandLogo.src)}" alt="Logo">`
                : `<div class="brand-fallback">GYM</div>`;

            const personaHtml = fotoDisponible
                ? `<img class="person-image${personaEsLogo ? ' is-logo' : ''}" src="${escaparHtml(credencialFoto.src)}" alt="Identidad visual">`
                : `<div class="person-fallback">SOCIO</div>`;

            const frente = `
                <article class="card card-front">
                    <div class="top-line"></div>
                    <header class="head">
                        <div class="brand">
                            <div class="brand-logo-wrap">${logoMarcaHtml}</div>
                            <div class="brand-copy">
                                <strong class="gym-name">${escaparHtml(gym)}</strong>
                                <span class="gym-caption">Credencial de acceso</span>
                            </div>
                        </div>
                        <span class="type-chip">Socio</span>
                    </header>
                    <div class="main">
                        <div class="person-wrap">${personaHtml}</div>
                        <div class="member-copy">
                            <div class="member-label">Nombre del socio</div>
                            <h1 class="member-name">${escaparHtml(cliente)}</h1>
                            <span class="branch">${escaparHtml(sucursal)}</span>
                        </div>
                        <div class="qr-wrap">
                            <img class="qr" src="${escaparHtml(credencialQr.src)}" alt="QR">
                        </div>
                    </div>
                    <footer class="footer">
                        <code class="code">${escaparHtml(codigo)}</code>
                        <span class="footer-note">Acceso mediante QR</span>
                    </footer>
                </article>`;
            const reverso = `
                <article class="card card-back">
                    <div class="top-line"></div>
                    <header class="back-head">
                        <div class="back-brand">
                            <div class="back-logo-wrap">${logoMarcaHtml}</div>
                            <div class="back-gym">
                                <strong>${escaparHtml(gym)}</strong>
                                <span>Información de la credencial</span>
                            </div>
                        </div>
                        <span class="back-chip">Acceso</span>
                    </header>

                    <div class="back-main">
                        <div class="back-simple">
                            <div class="back-symbol">✓</div>
                            <div class="back-simple-copy">
                                <h2>Credencial de acceso</h2>
                                <p>Personal e intransferible. Preséntala al ingresar al gimnasio.</p>
                                <div class="back-simple-rules">
                                    <span><b>✓</b> Vigencia validada en sistema</span>
                                    <span><b>↻</b> Reposición en recepción</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <footer class="back-footer">
                        <div class="back-footer-note">En caso de extravío, repórtala en recepción.</div>
                        <span class="back-footer-status">Acceso</span>
                    </footer>
                </article>`;


            return `<!doctype html>
                <html lang="es">
                <head>
                    <meta charset="utf-8">
                    <title>Credencial - ${escaparHtml(cliente)}</title>
                    <style>
                        @page { size: A4 landscape; margin: 12mm; }
                        *{box-sizing:border-box;-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
                        html,body{margin:0;padding:0;background:#fff}
                        body{font-family:Arial,Helvetica,sans-serif;color:#172033}
                        .sheet{display:flex;align-items:flex-start;justify-content:center;gap:12mm;width:100%;padding-top:5mm}
                        .side{display:grid;gap:3mm;justify-items:center}
                        .label{margin:0;color:#667085;font-size:3mm;font-weight:800;letter-spacing:.08em;text-transform:uppercase}
                        .card{position:relative;display:flex;flex-direction:column;width:85.60mm;height:53.98mm;overflow:hidden;border:.28mm solid #d7deea;border-radius:3.18mm;isolation:isolate}
                        .card-front{background:radial-gradient(circle at 12% 18%,rgba(37,99,235,.08),transparent 28%),linear-gradient(135deg,#fff 0%,#f8faff 100%)}
                        .card-front:after{content:"";position:absolute;right:-16mm;bottom:-20mm;width:44mm;height:44mm;border:7mm solid rgba(30,64,175,.045);border-radius:50%;z-index:-1}
                        .top-line{flex:0 0 3.6mm;background:linear-gradient(90deg,#17366f,#2f66b3)}
                        .head{display:flex;align-items:center;justify-content:space-between;gap:3mm;min-height:10.5mm;padding:1.8mm 4mm 1.4mm;border-bottom:.25mm solid #e4e9f1}
                        .brand{display:flex;align-items:center;min-width:0;gap:2.2mm}.brand-logo-wrap{display:grid;place-items:center;flex:0 0 8.8mm;width:8.8mm;height:8.8mm;overflow:hidden;border-radius:2mm;background:#fff}.brand-logo{width:100%;height:100%;object-fit:contain}.brand-fallback{display:grid;place-items:center;width:100%;height:100%;color:#244a91;font-size:2.4mm;font-weight:900}.brand-copy{min-width:0}.gym-name{display:block;max-width:45mm;overflow:hidden;color:#17366f;font-size:3.65mm;font-weight:900;line-height:1.05;text-overflow:ellipsis;white-space:nowrap}.gym-caption{display:block;margin-top:.65mm;color:#7a8597;font-size:2.15mm;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.type-chip{flex:0 0 auto;padding:1.25mm 2.2mm;border:.25mm solid #cbd9f4;border-radius:999px;background:#eef4ff;color:#244a91;font-size:2.1mm;font-weight:900;letter-spacing:.1em;text-transform:uppercase}
                        .main{display:grid;grid-template-columns:19.5mm minmax(0,1fr) 23.5mm;align-items:center;gap:2.8mm;flex:1 1 auto;min-height:0;padding:2.3mm 4mm 1.9mm}.person-wrap{display:grid;place-items:center;width:19.5mm;height:22.5mm;overflow:hidden;border:.3mm solid #d7dfec;border-radius:2.4mm;background:#edf2f8}.person-image{width:100%;height:100%;object-fit:cover}.person-image.is-logo{padding:2.3mm;object-fit:contain;background:#fff}.person-fallback{display:grid;place-items:center;width:100%;height:100%;color:#31548f;font-size:2.6mm;font-weight:900}.member-copy{min-width:0}.member-label{margin-bottom:.85mm;color:#8993a3;font-size:2mm;font-weight:800;letter-spacing:.12em;text-transform:uppercase}.member-name{overflow:hidden;margin:0;max-height:8.8mm;color:#172033;font-size:4.05mm;font-weight:900;line-height:1.05}.branch{display:block;max-width:28mm;overflow:hidden;margin-top:2mm;color:#607087;font-size:2.35mm;font-weight:700;text-overflow:ellipsis;white-space:nowrap}.qr-wrap{display:grid;place-items:center;width:23.5mm;height:23.5mm;padding:1.25mm;overflow:hidden;border:.28mm solid #dce3ee;border-radius:2.3mm;background:#fff}.qr{width:100%;height:100%;object-fit:contain}.footer{display:flex;align-items:center;justify-content:space-between;gap:2mm;flex:0 0 6mm;padding:0 4mm;border-top:.25mm solid #e4e9f1;background:rgba(248,250,252,.86);color:#68758a;font-size:2mm}.code{max-width:47mm;overflow:hidden;color:#26354d;font-family:"Courier New",monospace;font-size:2.15mm;font-weight:800;letter-spacing:.025em;text-overflow:ellipsis;white-space:nowrap}.footer-note{flex:0 0 auto;font-weight:700}
                        .card-back{background:radial-gradient(circle at 88% 18%,rgba(47,102,179,.08),transparent 20%),radial-gradient(circle at 12% 88%,rgba(47,102,179,.05),transparent 24%),linear-gradient(135deg,#ffffff 0%,#f8faff 100%);color:#172033;border-color:#d7deea}
                        .card-back:before{content:"";position:absolute;right:-15mm;bottom:-19mm;width:40mm;height:40mm;border:6mm solid rgba(30,64,175,.035);border-radius:50%}
                        .card-back:after{content:"";position:absolute;left:-12mm;top:14mm;width:28mm;height:28mm;border:5mm solid rgba(47,102,179,.025);border-radius:50%}
                        .back-head{display:flex;align-items:center;justify-content:space-between;gap:3mm;flex:0 0 10mm;min-height:0;padding:1.45mm 4mm 1.15mm;border-bottom:.25mm solid #e4e9f1}.back-brand{display:flex;align-items:center;gap:2.2mm;min-width:0}.back-logo-wrap{display:grid;place-items:center;flex:0 0 8mm;width:8mm;height:8mm;overflow:hidden;border-radius:1.8mm;background:#fff}.back-logo-wrap .brand-logo{width:100%;height:100%;object-fit:contain}.back-gym{min-width:0}.back-gym strong{display:block;max-width:45mm;overflow:hidden;color:#17366f;font-size:3.35mm;font-weight:900;line-height:1;text-overflow:ellipsis;white-space:nowrap}.back-gym span{display:block;margin-top:.45mm;color:#7a8597;font-size:1.8mm;font-weight:700;letter-spacing:.08em;text-transform:uppercase}.back-chip{flex:0 0 auto;padding:1.05mm 2mm;border:.25mm solid #cbd9f4;border-radius:999px;background:#eef4ff;color:#244a91;font-size:1.8mm;font-weight:900;letter-spacing:.1em;text-transform:uppercase}
                        .back-main{display:flex;align-items:center;justify-content:center;flex:1 1 auto;min-height:0;padding:2.4mm 5mm 2mm;overflow:hidden}.back-simple{display:grid;grid-template-columns:12mm minmax(0,1fr);align-items:center;gap:3mm;width:100%;min-width:0}.back-symbol{display:grid;place-items:center;width:12mm;height:12mm;border:.3mm solid #cfe0fa;border-radius:50%;background:#eef5ff;color:#2f66b3;font-size:5mm;font-weight:900}.back-simple-copy{min-width:0}.back-simple-copy h2{margin:0;color:#172033;font-size:3.15mm;font-weight:900;line-height:1.05}.back-simple-copy p{margin:1.1mm 0 0;color:#66758a;font-size:2mm;font-weight:700;line-height:1.3}.back-simple-rules{display:flex;align-items:center;flex-wrap:wrap;gap:1mm 2.4mm;margin-top:2.2mm}.back-simple-rules span{display:inline-flex;align-items:center;gap:1mm;color:#42526a;font-size:1.85mm;font-weight:800;line-height:1.15}.back-simple-rules b{color:#2f66b3}
                        .back-footer{display:flex;align-items:center;justify-content:space-between;gap:2mm;flex:0 0 5.3mm;min-height:0;padding:0 4mm;border-top:.25mm solid #e4e9f1;background:rgba(248,250,252,.92);color:#68758a;font-size:1.75mm}.back-footer-note{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:700}.back-footer-status{flex:0 0 auto;color:#244a91;font-size:1.9mm;font-weight:900;letter-spacing:.09em;text-transform:uppercase}
                        .cut-frame{position:relative;display:grid;place-items:center;width:97.6mm;height:65.98mm}
                        .crop{position:absolute;background:#111}
                        .crop-h{width:4.2mm;height:.35mm}
                        .crop-v{width:.35mm;height:4.2mm}
                        .crop.tl-h{top:3.6mm;left:0}.crop.tl-v{top:0;left:3.6mm}
                        .crop.tr-h{top:3.6mm;right:0}.crop.tr-v{top:0;right:3.6mm}
                        .crop.bl-h{bottom:3.6mm;left:0}.crop.bl-v{bottom:0;left:3.6mm}
                        .crop.br-h{bottom:3.6mm;right:0}.crop.br-v{bottom:0;right:3.6mm}
                        .print-note{margin:8mm auto 0;color:#667085;font-size:3mm;text-align:center}.print-note strong{color:#344054}
                        @media print{.print-note{display:none}.label{display:none}}
                    </style>
                </head>
                <body>
                                        <main class="sheet">
                        <section class="side">
                            <p class="label">Frente</p>
                            <div class="cut-frame">
                                <span class="crop crop-h tl-h"></span><span class="crop crop-v tl-v"></span>
                                <span class="crop crop-h tr-h"></span><span class="crop crop-v tr-v"></span>
                                <span class="crop crop-h bl-h"></span><span class="crop crop-v bl-v"></span>
                                <span class="crop crop-h br-h"></span><span class="crop crop-v br-v"></span>
                                ${frente}
                            </div>
                        </section>
                        <section class="side">
                            <p class="label">Reverso</p>
                            <div class="cut-frame">
                                <span class="crop crop-h tl-h"></span><span class="crop crop-v tl-v"></span>
                                <span class="crop crop-h tr-h"></span><span class="crop crop-v tr-v"></span>
                                <span class="crop crop-h bl-h"></span><span class="crop crop-v bl-v"></span>
                                <span class="crop crop-h br-h"></span><span class="crop crop-v br-v"></span>
                                ${reverso}
                            </div>
                        </section>
                    </main>
                    <p class="print-note"><strong>Imprime al 100 % / Tamaño real.</strong> Recorta ambas caras y colócalas espalda con espalda antes de enmicar.</p>
                    <script>
                        window.addEventListener('load', function(){
                            window.setTimeout(function(){ window.print(); }, 250);
                            window.onafterprint = function(){ window.close(); };
                        });
                    <\/script>
                </body>
                </html>`;
        }

        if (btnImprimirCredencial) {
            btnImprimirCredencial.addEventListener('click', function() {
                const contenido = construirCredencialImpresion();
                if (!contenido) return;

                const ventana = window.open('', '_blank', 'width=1100,height=720');
                if (!ventana) {
                    Swal.fire('Ventana bloqueada', 'Permite ventanas emergentes para imprimir la credencial.', 'info');
                    return;
                }

                ventana.document.open();
                ventana.document.write(contenido);
                ventana.document.close();
            });
        }

        function actualizarOpcionesCuestionarioSalud() {
            const checkbox = document.getElementById('solicitar_cuestionario_salud');
            const options = document.getElementById('healthEnrollmentOptions');
            if (!checkbox || !options) return;

            options.hidden = !checkbox.checked;
            options.classList.toggle('is-visible', checkbox.checked);
        }

        function validarCuestionarioSaludInscripcion(form) {
            const checkbox = form.elements.solicitar_cuestionario_salud;
            if (!checkbox || !checkbox.checked) return true;

            const modoSeleccionado = form.querySelector('input[name="modo_cuestionario_salud"]:checked');
            const modo = modoSeleccionado ? modoSeleccionado.value : 'recepcion';
            const email = String(form.elements.email.value || '').trim();

            if (modo === 'correo') {
                const emailValido = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
                if (!emailValido) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Correo necesario',
                        text: 'Captura un correo válido para enviar el cuestionario médico.',
                        confirmButtonColor: '#244292'
                    });
                    form.elements.email.focus();
                    return false;
                }
            }

            return true;
        }

        const healthToggle = document.getElementById('solicitar_cuestionario_salud');
        if (healthToggle) {
            healthToggle.addEventListener('change', actualizarOpcionesCuestionarioSalud);
            actualizarOpcionesCuestionarioSalud();
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

            if (operation === 'new' && !validarCuestionarioSaludInscripcion(form)) {
                return false;
            }

            const metodo = String(form.elements.metodo_pago.value || '');
            const total = Number(form.elements.precio_pagado.value || 0);

            if (!Number.isFinite(total) || total <= 0) {
                Swal.fire('Precio inválido', 'Selecciona un plan válido antes de continuar.', 'warning');
                return false;
            }

            const modoCuestionarioSeleccionado = operation === 'new'
                && form.querySelector('input[name="modo_cuestionario_salud"]:checked')
                ? form.querySelector('input[name="modo_cuestionario_salud"]:checked').value
                : '';

            const cuestionarioSolicitado = operation === 'new'
                && form.elements.solicitar_cuestionario_salud
                && form.elements.solicitar_cuestionario_salud.checked;

            const cuestionarioAhora = cuestionarioSolicitado
                && modoCuestionarioSeleccionado === 'recepcion';

            const cuestionarioPorCorreo = cuestionarioSolicitado
                && modoCuestionarioSeleccionado === 'correo';

            // En el flujo por correo no se abre una ventana vacía mientras SMTP responde.
            if (!cuestionarioAhora && !cuestionarioPorCorreo) {
                prepararVentanaDocumentoMembresia();
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
            if (fotoSocioInput) fotoSocioInput.value = '';
            limpiarVistaPreviaFotoSocio();
            const healthCheckbox = document.getElementById('solicitar_cuestionario_salud');
            if (healthCheckbox) {
                healthCheckbox.checked = false;
                const recepcion = document.querySelector('input[name="modo_cuestionario_salud"][value="recepcion"]');
                if (recepcion) recepcion.checked = true;
                actualizarOpcionesCuestionarioSalud();
            }
            $('#btnGuardarNuevo').prop('disabled', false).html('Guardar');
        });
        
        $('#modalRenovar').on('hidden.bs.modal', function() {
            formularioEnviando = false;
            limpiarDatosPoint(document.getElementById('formRenovar'));
            $('#btnRenovar').prop('disabled', false).html('Renovar');
            $('#renovar_plan_id').val('');
            $('#renovar_precio_pagado').val('');
        });
        
        function abrirRenovar(
            inscripcionId,
            clienteId,
            isDisabled,
            message,
            fechaInicioSugerida
        ) {
            if (isDisabled === true || isDisabled === 'true') {
                Swal.fire({
                    title: 'No se puede renovar',
                    text: message,
                    icon: 'info',
                    confirmButtonColor: '#1e3a8a'
                });
                return;
            }

            const obtenerFechaLocal = function() {
                const fecha = new Date();
                const year = fecha.getFullYear();
                const month = String(fecha.getMonth() + 1).padStart(2, '0');
                const day = String(fecha.getDate()).padStart(2, '0');
                return year + '-' + month + '-' + day;
            };

            const fechaSugeridaValida = /^\d{4}-\d{2}-\d{2}$/
                .test(String(fechaInicioSugerida || ''));
            
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
                        document.getElementById('renovar_fecha_inicio').value =
                            fechaSugeridaValida
                                ? fechaInicioSugerida
                                : obtenerFechaLocal();
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

        // El proceso CLI se lanza desde PHP. Este bloque es un respaldo
        // confiable: verifica el estado y, si hace falta, ejecuta el worker
        // mediante fetch después de que la página ya cargó. La interfaz nunca
        // espera a Gmail y no se utiliza sendBeacon para tareas SMTP largas.
        const correoTokensPendientes = <?php echo json_encode(
            $correo_tokens_async,
            JSON_UNESCAPED_SLASHES
            | JSON_HEX_TAG
            | JSON_HEX_APOS
            | JSON_HEX_AMP
            | JSON_HEX_QUOT
        ); ?>;

        const correosNotificados = new Set();

        function esperarCorreo(ms) {
            return new Promise(function(resolve) {
                window.setTimeout(resolve, ms);
            });
        }

        async function consultarEstadoCorreo(token) {
            const response = await fetch(
                'api/correo/estado_token.php?token=' + encodeURIComponent(token),
                {
                    method: 'GET',
                    credentials: 'same-origin',
                    cache: 'no-store'
                }
            );

            const texto = await response.text();
            let data = null;
            try {
                data = JSON.parse(texto);
            } catch (error) {
                throw new Error('El servidor no devolvió un estado de correo válido.');
            }

            if (!response.ok || !data.success) {
                throw new Error(data.message || 'No fue posible consultar el correo.');
            }

            return data;
        }

        async function ejecutarWorkerCorreo(token) {
            const contenido = 'token=' + encodeURIComponent(token);
            const response = await fetch('api/correo/procesar_token.php', {
                method: 'POST',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'Content-Type':
                        'application/x-www-form-urlencoded;charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: contenido
            });

            const texto = await response.text();
            let data = null;
            try {
                data = JSON.parse(texto);
            } catch (error) {
                throw new Error(
                    texto.trim() !== ''
                        ? texto.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim()
                        : 'El worker de correo no devolvió JSON.'
                );
            }

            if (!response.ok && !data.omitido) {
                throw new Error(data.message || data.error || 'No fue posible procesar el correo.');
            }

            return data;
        }

        function notificarCorreoEnviado(token) {
            if (correosNotificados.has(token)) return;
            correosNotificados.add(token);

            Swal.fire({
                toast: true,
                position: 'top-end',
                icon: 'success',
                title: 'Correo enviado correctamente',
                showConfirmButton: false,
                timer: 3800
            });
        }

        function notificarCorreoFallido(token, mensaje) {
            if (correosNotificados.has(token)) return;
            correosNotificados.add(token);

            Swal.fire({
                icon: 'warning',
                title: 'El correo no pudo enviarse',
                text: mensaje || 'El trabajo quedó registrado para reintento.',
                confirmButtonColor: '#244292'
            });
        }

        async function vigilarCorreoEnSegundoPlano(token) {
            if (!/^[a-f0-9]{64}$/.test(String(token || ''))) return;

            let workerSolicitado = false;

            for (let intento = 0; intento < 14; intento++) {
                try {
                    const estado = await consultarEstadoCorreo(token);

                    if (estado.estado === 'enviado') {
                        notificarCorreoEnviado(token);
                        return;
                    }

                    if (estado.estado === 'fallido') {
                        notificarCorreoFallido(
                            token,
                            estado.ultimo_error || 'Se agotaron los intentos de envío.'
                        );
                        return;
                    }

                    if (
                        !workerSolicitado
                        && (estado.estado === 'pendiente' || intento >= 2)
                    ) {
                        workerSolicitado = true;
                        ejecutarWorkerCorreo(token).catch(function(error) {
                            console.error('Worker de correo:', error);
                        });
                    }
                } catch (error) {
                    console.error('Estado del correo:', error);
                }

                await esperarCorreo(intento < 4 ? 1100 : 1800);
            }

            // No muestra un falso éxito. El correo continúa en la cola y el
            // procesador general podrá retomarlo en la siguiente carga.
            console.warn('El correo sigue en proceso o pendiente de reintento.');
        }

        async function procesarUnCorreoPendienteGeneral() {
            try {
                await fetch('api/correo/procesar_pendientes.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                });
            } catch (error) {
                console.error('Procesador general de correo:', error);
            }
        }

        $(document).ready(function() {
            correoTokensPendientes.forEach(function(token, indice) {
                window.setTimeout(function() {
                    vigilarCorreoEnSegundoPlano(token);
                }, 220 + (indice * 180));
            });

            // También recupera trabajos antiguos que hayan quedado pendientes.
            window.setTimeout(procesarUnCorreoPendienteGeneral, 1800);
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


        <?php if ($abrir_modal_renovar_dashboard): ?>
        $(document).ready(function() {
            abrirRenovar(
                <?php echo (int) $renovar_dashboard_inscripcion_id; ?>,
                <?php echo (int) $renovar_dashboard_cliente_id; ?>,
                false,
                '',
                <?php echo json_encode(
                    $renovar_dashboard_fecha_inicio,
                    JSON_HEX_TAG
                    | JSON_HEX_APOS
                    | JSON_HEX_AMP
                    | JSON_HEX_QUOT
                ); ?>
            );

            const url = new URL(window.location.href);
            [
                'action',
                'inscripcion_id',
                'cliente_id',
                'fecha_inicio',
                'origen'
            ].forEach(function(parametro) {
                url.searchParams.delete(parametro);
            });
            window.history.replaceState(
                {},
                document.title,
                url.pathname + url.search
            );
        });
        <?php endif; ?>
    </script>
</body>
</html>