<?php
// Archivo: servicio_plataforma.php
// Módulo exclusivo del superadministrador para controlar la vigencia de EGO.

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/servicio_plataforma_helper.php';
require_once __DIR__ . '/includes/correo_servicio_plataforma.php';

if (!servicio_plataforma_es_superadministrador()) {
    $_SESSION['mensaje_acceso'] =
        'Solo el superadministrador puede administrar el servicio de la plataforma.';
    header('Location: dashboard.php?error=acceso_denegado');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db instanceof mysqli) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

$db->set_charset('utf8mb4');

if (empty($_SESSION['servicio_plataforma_csrf'])) {
    $_SESSION['servicio_plataforma_csrf'] = bin2hex(random_bytes(32));
}

$csrf = (string) $_SESSION['servicio_plataforma_csrf'];
$userId = (int) ($_SESSION['user_id'] ?? 0);
$userName = trim((string) ($_SESSION['user_name'] ?? 'Superadministrador'));

function servicioPlataformaEscapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function servicioPlataformaPost(
    string $clave,
    string $predeterminado = ''
): string {
    return trim((string) ($_POST[$clave] ?? $predeterminado));
}

function servicioPlataformaRedirigir(string $tab = 'periodo'): void
{
    $tabs = ['periodo', 'renovar', 'historial'];

    if (!in_array($tab, $tabs, true)) {
        $tab = 'periodo';
    }

    header('Location: servicio_plataforma.php?tab=' . rawurlencode($tab));
    exit();
}

function servicioPlataformaGuardarFlash(
    string $tipo,
    string $titulo,
    string $mensaje
): void {
    $_SESSION['servicio_plataforma_flash'] = [
        'tipo' => $tipo,
        'titulo' => $titulo,
        'mensaje' => $mensaje,
    ];
}

function servicioPlataformaZona(): DateTimeZone
{
    return new DateTimeZone(
        (string) (
            $_SESSION['sucursal_zona_horaria']
            ?? 'America/Mexico_City'
        )
    );
}

function servicioPlataformaDiasEntre(
    DateTimeImmutable $desde,
    DateTimeImmutable $hasta
): int {
    return (int) $desde->diff($hasta)->format('%r%a');
}

function servicioPlataformaFechaMayor(
    DateTimeImmutable $primera,
    DateTimeImmutable $segunda
): DateTimeImmutable {
    return $primera >= $segunda ? $primera : $segunda;
}

$instalado = servicio_plataforma_instalado($db);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$instalado) {
        servicioPlataformaGuardarFlash(
            'error',
            'Módulo no instalado',
            'Primero ejecuta database/migracion_servicio_plataforma.sql.'
        );
        servicioPlataformaRedirigir();
    }

    $csrfRecibido = servicioPlataformaPost('csrf');

    if (!hash_equals($csrf, $csrfRecibido)) {
        servicioPlataformaGuardarFlash(
            'error',
            'Sesión no válida',
            'Actualiza la página e intenta nuevamente.'
        );
        servicioPlataformaRedirigir();
    }

    $accion = servicioPlataformaPost('accion');

    try {
        if ($accion === 'guardar_periodo') {
            $configuracionAnterior = servicio_plataforma_obtener($db);
            $yaExistePeriodo = is_array($configuracionAnterior);

            $proveedor = servicioPlataformaPost(
                'proveedor_nombre',
                'GGFit'
            );
            $contactoEmail = servicioPlataformaPost('contacto_email');
            $contactoTelefono = servicioPlataformaPost('contacto_telefono');
            $fechaInicio = servicioPlataformaPost('fecha_inicio');
            $meses = (int) servicioPlataformaPost('meses', '1');
            $precioMensual = (float) servicioPlataformaPost(
                'precio_mensual',
                '0'
            );
            $diasAviso = (int) servicioPlataformaPost(
                'dias_aviso',
                '7'
            );
            $activo = isset($_POST['activo']) ? 1 : 0;
            $bloquear = isset($_POST['bloquear_al_vencer']) ? 1 : 0;
            $referencia = servicioPlataformaPost('referencia_pago');
            $notas = servicioPlataformaPost('notas');

            if ($proveedor === '' || mb_strlen($proveedor) > 120) {
                throw new InvalidArgumentException(
                    'Escribe un nombre de proveedor válido.'
                );
            }

            if (
                $contactoEmail !== ''
                && !filter_var($contactoEmail, FILTER_VALIDATE_EMAIL)
            ) {
                throw new InvalidArgumentException(
                    'El correo de contacto no es válido.'
                );
            }

            if (!servicio_plataforma_fecha_valida($fechaInicio)) {
                throw new InvalidArgumentException(
                    'Selecciona una fecha de inicio válida.'
                );
            }

            if ($meses < 1 || $meses > 120) {
                throw new InvalidArgumentException(
                    'Los meses contratados deben estar entre 1 y 120.'
                );
            }

            if ($precioMensual < 0 || $precioMensual > 9999999999) {
                throw new InvalidArgumentException(
                    'El precio mensual no es válido.'
                );
            }

            if ($diasAviso < 1 || $diasAviso > 90) {
                throw new InvalidArgumentException(
                    'Los días de aviso deben estar entre 1 y 90.'
                );
            }

            if ($yaExistePeriodo) {
                $fechaOriginal = trim((string) (
                    $configuracionAnterior['fecha_inicio'] ?? ''
                ));

                if (
                    servicio_plataforma_fecha_valida($fechaOriginal)
                    && $fechaInicio < $fechaOriginal
                ) {
                    throw new InvalidArgumentException(
                        'El periodo actual no puede comenzar antes del inicio original del servicio ('
                        . servicio_plataforma_formatear_fecha($fechaOriginal)
                        . ').'
                    );
                }
            }

            $fechaVencimiento = servicio_plataforma_calcular_vencimiento(
                $fechaInicio,
                $meses
            );
            $importe = round($precioMensual * $meses, 2);
            $fechaAnterior = $configuracionAnterior
                ? (string) (
                    $configuracionAnterior['fecha_vencimiento'] ?? ''
                )
                : '';
            $tipoHistorial = $yaExistePeriodo ? 'ajuste' : 'alta';
            $periodoCambio = !$configuracionAnterior
                || (string) (
                    $configuracionAnterior['periodo_actual_inicio'] ?? ''
                ) !== $fechaInicio
                || (string) (
                    $configuracionAnterior['fecha_vencimiento'] ?? ''
                ) !== $fechaVencimiento
                || (int) (
                    $configuracionAnterior['meses_ultimo_pago'] ?? 0
                ) !== $meses
                || abs(
                    (float) (
                        $configuracionAnterior['precio_mensual'] ?? 0
                    ) - $precioMensual
                ) > 0.0001;

            $db->begin_transaction();

            try {
                $stmt = $db->prepare(
                    "INSERT INTO servicio_plataforma
                        (id, proveedor_nombre, contacto_email,
                         contacto_telefono, fecha_inicio,
                         periodo_actual_inicio, fecha_vencimiento,
                         precio_mensual, meses_ultimo_pago,
                         importe_ultimo_pago, dias_aviso, activo,
                         bloquear_al_vencer, notas, actualizado_por)
                     VALUES
                        (1, ?, NULLIF(?, ''), NULLIF(?, ''), ?, ?, ?,
                         ?, ?, ?, ?, ?, ?, NULLIF(?, ''), ?)
                     ON DUPLICATE KEY UPDATE
                        proveedor_nombre = VALUES(proveedor_nombre),
                        contacto_email = VALUES(contacto_email),
                        contacto_telefono = VALUES(contacto_telefono),
                        periodo_actual_inicio = VALUES(periodo_actual_inicio),
                        fecha_vencimiento = VALUES(fecha_vencimiento),
                        precio_mensual = VALUES(precio_mensual),
                        meses_ultimo_pago = VALUES(meses_ultimo_pago),
                        importe_ultimo_pago = VALUES(importe_ultimo_pago),
                        dias_aviso = VALUES(dias_aviso),
                        activo = VALUES(activo),
                        bloquear_al_vencer = VALUES(bloquear_al_vencer),
                        notas = VALUES(notas),
                        actualizado_por = VALUES(actualizado_por),
                        updated_at = NOW()"
                );

                if (!$stmt) {
                    throw new RuntimeException(
                        'No fue posible preparar la configuración: '
                        . $db->error
                    );
                }

                $stmt->bind_param(
                    'ssssssdidiiisi',
                    $proveedor,
                    $contactoEmail,
                    $contactoTelefono,
                    $fechaInicio,
                    $fechaInicio,
                    $fechaVencimiento,
                    $precioMensual,
                    $meses,
                    $importe,
                    $diasAviso,
                    $activo,
                    $bloquear,
                    $notas,
                    $userId
                );

                if (!$stmt->execute()) {
                    throw new RuntimeException(
                        'No fue posible guardar la configuración: '
                        . $stmt->error
                    );
                }

                $stmt->close();

                if ($periodoCambio) {
                    $stmtHistorial = $db->prepare(
                        "INSERT INTO servicio_plataforma_historial
                            (tipo, fecha_vencimiento_anterior,
                             periodo_inicio, periodo_fin, meses,
                             precio_mensual, importe_total,
                             referencia_pago, notas, registrado_por)
                         VALUES
                            (?, NULLIF(?, ''), ?, ?, ?, ?, ?,
                             NULLIF(?, ''), NULLIF(?, ''), ?)"
                    );

                    if (!$stmtHistorial) {
                        throw new RuntimeException(
                            'No fue posible preparar el historial: '
                            . $db->error
                        );
                    }

                    $stmtHistorial->bind_param(
                        'ssssiddssi',
                        $tipoHistorial,
                        $fechaAnterior,
                        $fechaInicio,
                        $fechaVencimiento,
                        $meses,
                        $precioMensual,
                        $importe,
                        $referencia,
                        $notas,
                        $userId
                    );

                    if (!$stmtHistorial->execute()) {
                        throw new RuntimeException(
                            'No fue posible registrar el historial: '
                            . $stmtHistorial->error
                        );
                    }

                    $stmtHistorial->close();
                }

                $db->commit();
            } catch (Throwable $error) {
                $db->rollback();
                throw $error;
            }

            servicioPlataformaGuardarFlash(
                'success',
                $yaExistePeriodo
                    ? 'Cambios guardados'
                    : 'Primer periodo registrado',
                $yaExistePeriodo
                    ? 'Los datos del periodo actual y su configuración fueron actualizados.'
                    : 'El servicio quedó vigente hasta el '
                        . servicio_plataforma_formatear_fecha(
                            $fechaVencimiento
                        )
                        . '.'
            );
            servicioPlataformaRedirigir('periodo');
        }

        if ($accion === 'renovar') {
            $configuracion = servicio_plataforma_obtener($db);

            if (!$configuracion) {
                throw new RuntimeException(
                    'Primero registra el periodo inicial del servicio.'
                );
            }

            $fechaVencimientoActual = trim((string) (
                $configuracion['fecha_vencimiento'] ?? ''
            ));

            if (!servicio_plataforma_fecha_valida(
                $fechaVencimientoActual
            )) {
                throw new RuntimeException(
                    'La fecha de vencimiento actual no es válida.'
                );
            }

            $zona = servicioPlataformaZona();
            $hoy = new DateTimeImmutable('today', $zona);
            $vencimientoActual = new DateTimeImmutable(
                $fechaVencimientoActual,
                $zona
            );
            $diasRestantes = servicioPlataformaDiasEntre(
                $hoy,
                $vencimientoActual
            );
            $fechaHabilitacion = $vencimientoActual->modify('-3 days');

            if ($diasRestantes > 3) {
                throw new RuntimeException(
                    'La renovación estará disponible a partir del '
                    . $fechaHabilitacion->format('d/m/Y')
                    . ', cuando falten 3 días para vencer.'
                );
            }

            $meses = (int) servicioPlataformaPost(
                'meses_renovacion',
                '1'
            );
            $precioMensual = (float) servicioPlataformaPost(
                'precio_mensual_renovacion',
                (string) ($configuracion['precio_mensual'] ?? '0')
            );
            $fechaInicioRenovacion = servicioPlataformaPost(
                'fecha_inicio_renovacion'
            );
            $referencia = servicioPlataformaPost(
                'referencia_pago_renovacion'
            );
            $notas = servicioPlataformaPost('notas_renovacion');

            if ($meses < 1 || $meses > 120) {
                throw new InvalidArgumentException(
                    'Los meses de renovación deben estar entre 1 y 120.'
                );
            }

            if ($precioMensual < 0 || $precioMensual > 9999999999) {
                throw new InvalidArgumentException(
                    'El precio mensual de la renovación no es válido.'
                );
            }

            $inicioPosterior = $vencimientoActual->modify('+1 day');
            $inicioMinimo = servicioPlataformaFechaMayor(
                $inicioPosterior,
                $hoy
            );

            if ($fechaInicioRenovacion === '') {
                $fechaInicioRenovacion = $inicioMinimo->format('Y-m-d');
            }

            if (!servicio_plataforma_fecha_valida(
                $fechaInicioRenovacion
            )) {
                throw new InvalidArgumentException(
                    'La fecha de inicio de renovación no es válida.'
                );
            }

            $inicioElegido = new DateTimeImmutable(
                $fechaInicioRenovacion,
                $zona
            );

            if ($inicioElegido < $inicioMinimo) {
                throw new InvalidArgumentException(
                    'El nuevo periodo no puede comenzar antes del '
                    . $inicioMinimo->format('d/m/Y')
                    . '. Las fechas anteriores están bloqueadas.'
                );
            }

            $fechaNueva = servicio_plataforma_calcular_vencimiento(
                $fechaInicioRenovacion,
                $meses
            );
            $importe = round($precioMensual * $meses, 2);
            $fechaAnterior = $fechaVencimientoActual;

            $db->begin_transaction();

            try {
                $stmt = $db->prepare(
                    "UPDATE servicio_plataforma
                     SET periodo_actual_inicio = ?,
                         fecha_vencimiento = ?,
                         precio_mensual = ?,
                         meses_ultimo_pago = ?,
                         importe_ultimo_pago = ?,
                         activo = 1,
                         actualizado_por = ?,
                         updated_at = NOW()
                     WHERE id = 1"
                );

                if (!$stmt) {
                    throw new RuntimeException(
                        'No fue posible preparar la renovación: '
                        . $db->error
                    );
                }

                $stmt->bind_param(
                    'ssdidi',
                    $fechaInicioRenovacion,
                    $fechaNueva,
                    $precioMensual,
                    $meses,
                    $importe,
                    $userId
                );

                if (!$stmt->execute()) {
                    throw new RuntimeException(
                        'No fue posible aplicar la renovación: '
                        . $stmt->error
                    );
                }

                $stmt->close();

                $tipo = 'renovacion';
                $stmtHistorial = $db->prepare(
                    "INSERT INTO servicio_plataforma_historial
                        (tipo, fecha_vencimiento_anterior,
                         periodo_inicio, periodo_fin, meses,
                         precio_mensual, importe_total,
                         referencia_pago, notas, registrado_por)
                     VALUES
                        (?, ?, ?, ?, ?, ?, ?,
                         NULLIF(?, ''), NULLIF(?, ''), ?)"
                );

                if (!$stmtHistorial) {
                    throw new RuntimeException(
                        'No fue posible preparar el historial: '
                        . $db->error
                    );
                }

                $stmtHistorial->bind_param(
                    'ssssiddssi',
                    $tipo,
                    $fechaAnterior,
                    $fechaInicioRenovacion,
                    $fechaNueva,
                    $meses,
                    $precioMensual,
                    $importe,
                    $referencia,
                    $notas,
                    $userId
                );

                if (!$stmtHistorial->execute()) {
                    throw new RuntimeException(
                        'No fue posible guardar el historial: '
                        . $stmtHistorial->error
                    );
                }

                $stmtHistorial->close();
                $db->commit();
            } catch (Throwable $error) {
                $db->rollback();
                throw $error;
            }

            $nombreGimnasio = servicio_correo_nombre_gimnasio($db);
            $resultadoCorreo = servicio_correo_enviar_renovacion(
                $db,
                [
                    'gimnasio' => $nombreGimnasio,
                    'proveedor' => (string) (
                        $configuracion['proveedor_nombre'] ?? 'GGFit'
                    ),
                    'periodo_inicio' => $fechaInicioRenovacion,
                    'periodo_fin' => $fechaNueva,
                    'meses' => $meses,
                    'precio_mensual' => $precioMensual,
                    'importe_total' => $importe,
                    'referencia' => $referencia,
                    'notas' => $notas,
                    'registrado_por' => $userName,
                    'fecha_registro' => date('d/m/Y H:i'),
                ]
            );

            $correoEnviados = (int) (
                $resultadoCorreo['enviados'] ?? 0
            );
            $correoTotal = (int) (
                $resultadoCorreo['total'] ?? 0
            );

            if (!empty($resultadoCorreo['ok'])) {
                servicioPlataformaGuardarFlash(
                    'success',
                    'Renovación registrada',
                    'El servicio quedó vigente hasta el '
                    . servicio_plataforma_formatear_fecha($fechaNueva)
                    . '. El comprobante se envió a '
                    . $correoEnviados
                    . ($correoEnviados === 1
                        ? ' administrador.'
                        : ' administradores.')
                );
            } else {
                $detalleCorreo = trim((string) (
                    $resultadoCorreo['mensaje']
                    ?? 'No fue posible enviar el comprobante.'
                ));

                servicioPlataformaGuardarFlash(
                    'warning',
                    'Renovación guardada',
                    'El servicio quedó vigente hasta el '
                    . servicio_plataforma_formatear_fecha($fechaNueva)
                    . '. '
                    . $detalleCorreo
                    . ($correoTotal > 0
                        ? ' Enviados: '
                            . $correoEnviados
                            . ' de '
                            . $correoTotal
                            . '.'
                        : '')
                );
            }

            servicioPlataformaRedirigir('historial');
        }

        throw new InvalidArgumentException(
            'La acción solicitada no es válida.'
        );
    } catch (Throwable $error) {
        error_log('[Servicio plataforma] ' . $error->getMessage());
        servicioPlataformaGuardarFlash(
            'error',
            'No se guardaron los cambios',
            $error->getMessage()
        );
        servicioPlataformaRedirigir(
            $accion === 'renovar' ? 'renovar' : 'periodo'
        );
    }
}

$flash = $_SESSION['servicio_plataforma_flash'] ?? null;
unset($_SESSION['servicio_plataforma_flash']);

$resumen = $instalado
    ? servicio_plataforma_resumen($db)
    : [
        'estado' => 'sin_instalar',
        'nivel' => 'danger',
        'titulo' => 'Módulo pendiente de instalar',
        'mensaje' =>
            'Ejecuta la migración SQL incluida antes de utilizar esta pantalla.',
        'configuracion' => null,
        'dias_restantes' => null,
        'fecha_inicio_formateada' => 'Sin definir',
        'fecha_vencimiento_formateada' => 'Sin definir',
    ];

$configuracion = $resumen['configuracion'] ?? null;
$tienePeriodo = is_array($configuracion);
$historial = [];

if ($instalado) {
    $resultadoHistorial = $db->query(
        "SELECT
            h.id,
            h.tipo,
            h.fecha_vencimiento_anterior,
            h.periodo_inicio,
            h.periodo_fin,
            h.meses,
            h.precio_mensual,
            h.importe_total,
            h.referencia_pago,
            h.notas,
            h.created_at,
            u.nombre AS usuario_nombre
         FROM servicio_plataforma_historial h
         LEFT JOIN usuarios u
            ON u.id = h.registrado_por
         ORDER BY h.id DESC
         LIMIT 50"
    );

    if ($resultadoHistorial instanceof mysqli_result) {
        while ($fila = $resultadoHistorial->fetch_assoc()) {
            $historial[] = $fila;
        }
    }
}

$zona = servicioPlataformaZona();
$hoy = new DateTimeImmutable('today', $zona);
$fechaInicioFormulario = (string) (
    $configuracion['periodo_actual_inicio']
    ?? $configuracion['fecha_inicio']
    ?? $hoy->format('Y-m-d')
);
$fechaInicioOriginal = (string) (
    $configuracion['fecha_inicio'] ?? $fechaInicioFormulario
);
$mesesFormulario = (int) (
    $configuracion['meses_ultimo_pago'] ?? 1
);
$precioFormulario = (float) (
    $configuracion['precio_mensual'] ?? 0
);
$diasAvisoFormulario = (int) (
    $configuracion['dias_aviso'] ?? 7
);

$renovacionDisponible = false;
$diasRestantesRenovacion = null;
$fechaHabilitacionRenovacion = null;
$fechaRenovacionMinima = $hoy;
$fechaRenovacionPredeterminada = $hoy->format('Y-m-d');

if (
    $tienePeriodo
    && servicio_plataforma_fecha_valida(
        (string) ($configuracion['fecha_vencimiento'] ?? '')
    )
) {
    $vencimientoActual = new DateTimeImmutable(
        (string) $configuracion['fecha_vencimiento'],
        $zona
    );
    $diasRestantesRenovacion = servicioPlataformaDiasEntre(
        $hoy,
        $vencimientoActual
    );
    $renovacionDisponible = $diasRestantesRenovacion <= 3;
    $fechaHabilitacionRenovacion = $vencimientoActual->modify('-3 days');
    $fechaRenovacionMinima = servicioPlataformaFechaMayor(
        $vencimientoActual->modify('+1 day'),
        $hoy
    );
    $fechaRenovacionPredeterminada =
        $fechaRenovacionMinima->format('Y-m-d');
}

$administradoresCorreo = [];
$errorAdministradoresCorreo = '';

if ($instalado) {
    try {
        $administradoresCorreo =
            servicio_correo_obtener_administradores($db);
    } catch (Throwable $errorCorreo) {
        $errorAdministradoresCorreo = $errorCorreo->getMessage();
    }
}

$tabSolicitado = strtolower(trim((string) ($_GET['tab'] ?? 'periodo')));
$tabsValidos = ['periodo', 'renovar', 'historial'];
$tabActivo = in_array($tabSolicitado, $tabsValidos, true)
    ? $tabSolicitado
    : 'periodo';

if (!$tienePeriodo && $tabActivo === 'renovar') {
    $tabActivo = 'periodo';
}

$estado = (string) ($resumen['estado'] ?? '');
$iconoEstado = in_array(
    $estado,
    ['vencido', 'suspendido', 'configuracion_invalida', 'sin_instalar'],
    true
)
    ? 'fa-triangle-exclamation'
    : (in_array($estado, ['por_vencer', 'vence_hoy'], true)
        ? 'fa-clock'
        : 'fa-circle-check');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Servicio de plataforma - EGO</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr/dist/l10n/es.js"></script>
    <link rel="stylesheet" href="css/servicio_plataforma.css?v=2">
</head>
<body>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content service-page">
        <header class="service-heading">
            <div>
                <h1>Servicio de plataforma</h1>
                <p>
                    Administra el periodo contratado, registra renovaciones y controla los avisos de vencimiento.
                </p>
            </div>
        </header>

        <section class="service-status service-status--<?php echo servicioPlataformaEscapar((string) ($resumen['nivel'] ?? 'neutral')); ?>">
            <div class="service-status-icon">
                <i class="fas <?php echo $iconoEstado; ?>"></i>
            </div>
            <div class="service-status-copy">
                <span>Estado actual</span>
                <h2><?php echo servicioPlataformaEscapar((string) ($resumen['titulo'] ?? 'Servicio')); ?></h2>
                <p><?php echo servicioPlataformaEscapar((string) ($resumen['mensaje'] ?? '')); ?></p>
            </div>
            <?php if ($configuracion): ?>
                <div class="service-status-days">
                    <strong>
                        <?php
                        $dias = $resumen['dias_restantes'] ?? null;
                        echo $dias === null ? '—' : (int) $dias;
                        ?>
                    </strong>
                    <span>Días restantes</span>
                </div>
            <?php endif; ?>
        </section>

        <?php if (!$instalado): ?>
            <section class="service-install-card">
                <i class="fas fa-database"></i>
                <div>
                    <h2>Falta instalar las tablas</h2>
                    <p>
                        Ejecuta <code>database/migracion_servicio_plataforma.sql</code> en phpMyAdmin y vuelve a cargar esta página.
                    </p>
                </div>
            </section>
        <?php else: ?>
            <?php if ($tienePeriodo): ?>
            <section class="service-summary">
                <article>
                    <span class="service-summary-icon blue">
                        <i class="fas fa-calendar-day"></i>
                    </span>
                    <div>
                        <small>Periodo actual</small>
                        <strong>
                            <?php echo servicioPlataformaEscapar(
                                servicio_plataforma_formatear_fecha(
                                    (string) ($configuracion['periodo_actual_inicio'] ?? '')
                                )
                            ); ?>
                            <span>—</span>
                            <?php echo servicioPlataformaEscapar((string) ($resumen['fecha_vencimiento_formateada'] ?? 'Sin definir')); ?>
                        </strong>
                    </div>
                </article>
                <article>
                    <span class="service-summary-icon green">
                        <i class="fas fa-coins"></i>
                    </span>
                    <div>
                        <small>Precio mensual</small>
                        <strong>$<?php echo number_format((float) ($configuracion['precio_mensual'] ?? 0), 2); ?></strong>
                    </div>
                </article>
                <article>
                    <span class="service-summary-icon amber">
                        <i class="fas fa-bell"></i>
                    </span>
                    <div>
                        <small>Aviso anticipado</small>
                        <strong><?php echo (int) ($configuracion['dias_aviso'] ?? 7); ?> días</strong>
                    </div>
                </article>
            </section>
            <?php endif; ?>

            <nav class="service-tabs" aria-label="Secciones del servicio">
                <button
                    type="button"
                    class="service-tab <?php echo $tabActivo === 'periodo' ? 'active' : ''; ?>"
                    data-service-tab="periodo"
                >
                    <i class="fas fa-pen-to-square"></i>
                    <span><?php echo $tienePeriodo ? 'Periodo actual' : 'Primer periodo'; ?></span>
                </button>
                <button
                    type="button"
                    class="service-tab <?php echo $tabActivo === 'renovar' ? 'active' : ''; ?> <?php echo !$tienePeriodo ? 'disabled' : ''; ?>"
                    data-service-tab="renovar"
                    <?php echo !$tienePeriodo ? 'disabled' : ''; ?>
                >
                    <i class="fas fa-rotate"></i>
                    <span>Renovar</span>
                    <?php if ($tienePeriodo && !$renovacionDisponible): ?>
                        <i class="fas fa-lock service-tab-lock"></i>
                    <?php endif; ?>
                </button>
                <button
                    type="button"
                    class="service-tab <?php echo $tabActivo === 'historial' ? 'active' : ''; ?>"
                    data-service-tab="historial"
                >
                    <i class="fas fa-clock-rotate-left"></i>
                    <span>Historial</span>
                    <em><?php echo count($historial); ?></em>
                </button>
            </nav>

            <section
                class="service-tab-panel <?php echo $tabActivo === 'periodo' ? 'active' : ''; ?>"
                data-service-panel="periodo"
            >
                <form method="post" class="service-form" id="serviceConfigForm">
                    <input type="hidden" name="csrf" value="<?php echo servicioPlataformaEscapar($csrf); ?>">
                    <input type="hidden" name="accion" value="guardar_periodo">

                    <section class="service-card service-card-main">
                        <div class="service-card-head">
                            <span class="service-card-icon">
                                <i class="fas <?php echo $tienePeriodo ? 'fa-pen-to-square' : 'fa-calendar-plus'; ?>"></i>
                            </span>
                            <div>
                                <h2>
                                    <?php echo $tienePeriodo
                                        ? 'Editar periodo actual'
                                        : 'Registrar el primer periodo'; ?>
                                </h2>
                                <p>
                                    <?php echo $tienePeriodo
                                        ? 'Modifica únicamente los datos que necesites corregir. Esto no registra una renovación.'
                                        : 'Define desde qué fecha comienza el uso del sistema y cuántos meses fueron contratados.'; ?>
                                </p>
                            </div>
                        </div>

                        <div class="service-step">
                            <div class="service-step-number">1</div>
                            <div class="service-step-content">
                                <div class="service-step-title">
                                    <div>
                                        <h3>Datos del periodo</h3>
                                        <p>La fecha final y el importe se calculan automáticamente.</p>
                                    </div>
                                </div>

                                <div class="service-form-grid service-form-grid-period">
                                    <label class="service-field">
                                        <span>Inicio del periodo actual</span>
                                        <div class="service-date-field">
                                            <i class="fas fa-calendar-days"></i>
                                            <input
                                                type="text"
                                                name="fecha_inicio"
                                                id="configStart"
                                                class="service-date-picker"
                                                required
                                                value="<?php echo servicioPlataformaEscapar($fechaInicioFormulario); ?>"
                                                data-default-date="<?php echo servicioPlataformaEscapar($fechaInicioFormulario); ?>"
                                                data-min-date="<?php echo $tienePeriodo ? servicioPlataformaEscapar($fechaInicioOriginal) : ''; ?>"
                                                autocomplete="off"
                                            >
                                        </div>
                                        <?php if ($tienePeriodo): ?>
                                            <small>No puede ser anterior al inicio original: <?php echo servicioPlataformaEscapar(servicio_plataforma_formatear_fecha($fechaInicioOriginal)); ?>.</small>
                                        <?php endif; ?>
                                    </label>

                                    <label class="service-field">
                                        <span>Meses contratados</span>
                                        <div class="service-number-field">
                                            <input
                                                type="number"
                                                name="meses"
                                                id="configMonths"
                                                min="1"
                                                max="120"
                                                required
                                                value="<?php echo $mesesFormulario; ?>"
                                            >
                                            <span>meses</span>
                                        </div>
                                    </label>

                                    <label class="service-field">
                                        <span>Precio por mes</span>
                                        <div class="service-money-input">
                                            <span>$</span>
                                            <input
                                                type="number"
                                                name="precio_mensual"
                                                id="configPrice"
                                                min="0"
                                                max="9999999999"
                                                step="0.01"
                                                required
                                                value="<?php echo number_format($precioFormulario, 2, '.', ''); ?>"
                                            >
                                            <em>MXN</em>
                                        </div>
                                    </label>
                                </div>

                                <div class="service-calculation">
                                    <div>
                                        <small>Vencimiento calculado</small>
                                        <strong id="configEnd">—</strong>
                                    </div>
                                    <div>
                                        <small>Importe del periodo</small>
                                        <strong id="configTotal">$0.00</strong>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <details class="service-advanced" <?php echo !$tienePeriodo ? 'open' : ''; ?>>
                            <summary>
                                <span>
                                    <i class="fas fa-sliders"></i>
                                    Contacto y reglas del servicio
                                </span>
                                <small>Proveedor, avisos y bloqueo</small>
                                <i class="fas fa-chevron-down"></i>
                            </summary>

                            <div class="service-advanced-body">
                                <div class="service-form-grid">
                                    <label class="service-field">
                                        <span>Proveedor del servicio</span>
                                        <input
                                            type="text"
                                            name="proveedor_nombre"
                                            maxlength="120"
                                            required
                                            value="<?php echo servicioPlataformaEscapar((string) ($configuracion['proveedor_nombre'] ?? 'GGFit')); ?>"
                                        >
                                    </label>
                                    <label class="service-field">
                                        <span>Correo de contacto</span>
                                        <input
                                            type="email"
                                            name="contacto_email"
                                            maxlength="190"
                                            value="<?php echo servicioPlataformaEscapar((string) ($configuracion['contacto_email'] ?? '')); ?>"
                                            placeholder="correo@proveedor.com"
                                        >
                                    </label>
                                    <label class="service-field">
                                        <span>Teléfono o WhatsApp</span>
                                        <input
                                            type="text"
                                            name="contacto_telefono"
                                            maxlength="30"
                                            value="<?php echo servicioPlataformaEscapar((string) ($configuracion['contacto_telefono'] ?? '')); ?>"
                                            placeholder="Ej. 222 000 0000"
                                        >
                                    </label>
                                    <label class="service-field">
                                        <span>Mostrar aviso con anticipación</span>
                                        <div class="service-number-field">
                                            <input
                                                type="number"
                                                name="dias_aviso"
                                                min="1"
                                                max="90"
                                                required
                                                value="<?php echo $diasAvisoFormulario; ?>"
                                            >
                                            <span>días</span>
                                        </div>
                                    </label>
                                    <label class="service-field">
                                        <span>Referencia del periodo</span>
                                        <input
                                            type="text"
                                            name="referencia_pago"
                                            maxlength="120"
                                            placeholder="Folio o referencia opcional"
                                        >
                                    </label>
                                </div>

                                <div class="service-switches">
                                    <label class="service-switch-row">
                                        <input
                                            type="checkbox"
                                            name="activo"
                                            value="1"
                                            <?php echo !$configuracion || (int) ($configuracion['activo'] ?? 1) === 1 ? 'checked' : ''; ?>
                                        >
                                        <span class="service-switch"></span>
                                        <span>
                                            <strong>Servicio habilitado</strong>
                                            <small>Desactivarlo suspende el acceso de los usuarios, excepto el superadministrador.</small>
                                        </span>
                                    </label>
                                    <label class="service-switch-row">
                                        <input
                                            type="checkbox"
                                            name="bloquear_al_vencer"
                                            value="1"
                                            <?php echo (int) ($configuracion['bloquear_al_vencer'] ?? 0) === 1 ? 'checked' : ''; ?>
                                        >
                                        <span class="service-switch"></span>
                                        <span>
                                            <strong>Bloquear automáticamente al vencer</strong>
                                            <small>El superadministrador siempre conserva acceso para registrar la renovación.</small>
                                        </span>
                                    </label>
                                </div>

                                <label class="service-field">
                                    <span>Notas internas</span>
                                    <textarea
                                        name="notas"
                                        maxlength="500"
                                        rows="3"
                                        placeholder="Información interna del acuerdo o del cliente"
                                    ><?php echo servicioPlataformaEscapar((string) ($configuracion['notas'] ?? '')); ?></textarea>
                                </label>
                            </div>
                        </details>

                        <div class="service-form-actions">
                            <p>
                                <i class="fas fa-circle-info"></i>
                                <?php echo $tienePeriodo
                                    ? 'Guardar aquí corrige el periodo actual. Para extenderlo utiliza la pestaña Renovar.'
                                    : 'Después de guardar podrás renovar cuando falten 3 días o menos.'; ?>
                            </p>
                            <button type="submit" class="service-primary-button">
                                <i class="fas fa-floppy-disk"></i>
                                <?php echo $tienePeriodo
                                    ? 'Guardar cambios'
                                    : 'Registrar primer periodo'; ?>
                            </button>
                        </div>
                    </section>
                </form>
            </section>

            <section
                class="service-tab-panel <?php echo $tabActivo === 'renovar' ? 'active' : ''; ?>"
                data-service-panel="renovar"
            >
                <?php if (!$tienePeriodo): ?>
                    <section class="service-empty-state">
                        <i class="fas fa-calendar-plus"></i>
                        <h2>Primero registra el periodo inicial</h2>
                        <p>Después podrás agregar renovaciones por uno o varios meses.</p>
                        <button type="button" data-go-tab="periodo">Registrar periodo</button>
                    </section>
                <?php else: ?>
                    <section class="service-card service-renew-card">
                        <div class="service-card-head">
                            <span class="service-card-icon renew">
                                <i class="fas fa-rotate"></i>
                            </span>
                            <div>
                                <h2>Registrar renovación</h2>
                                <p>Extiende la vigencia y envía automáticamente el comprobante PDF a los administradores activos.</p>
                            </div>
                        </div>

                        <?php if ($renovacionDisponible): ?>
                            <div class="service-renew-availability available">
                                <i class="fas fa-circle-check"></i>
                                <div>
                                    <strong>Renovación disponible</strong>
                                    <span>
                                        <?php if ((int) $diasRestantesRenovacion < 0): ?>
                                            El periodo venció hace <?php echo abs((int) $diasRestantesRenovacion); ?> día(s).
                                        <?php elseif ((int) $diasRestantesRenovacion === 0): ?>
                                            El servicio vence hoy.
                                        <?php else: ?>
                                            Faltan <?php echo (int) $diasRestantesRenovacion; ?> día(s) para el vencimiento.
                                        <?php endif; ?>
                                    </span>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="service-renew-availability locked">
                                <i class="fas fa-lock"></i>
                                <div>
                                    <strong>La renovación todavía está bloqueada</strong>
                                    <span>
                                        Podrás renovarla desde el <?php echo servicioPlataformaEscapar($fechaHabilitacionRenovacion ? $fechaHabilitacionRenovacion->format('d/m/Y') : '—'); ?>, cuando falten 3 días para vencer.
                                    </span>
                                </div>
                            </div>
                        <?php endif; ?>

                        <form method="post" class="service-form" id="renewForm">
                            <input type="hidden" name="csrf" value="<?php echo servicioPlataformaEscapar($csrf); ?>">
                            <input type="hidden" name="accion" value="renovar">

                            <div class="service-renew-current">
                                <div>
                                    <small>Vencimiento actual</small>
                                    <strong><?php echo servicioPlataformaEscapar((string) ($resumen['fecha_vencimiento_formateada'] ?? 'Sin definir')); ?></strong>
                                </div>
                                <i class="fas fa-arrow-right-long"></i>
                                <div>
                                    <small>Inicio mínimo permitido</small>
                                    <strong><?php echo servicioPlataformaEscapar($fechaRenovacionMinima->format('d/m/Y')); ?></strong>
                                </div>
                            </div>

                            <div class="service-step">
                                <div class="service-step-number">1</div>
                                <div class="service-step-content">
                                    <div class="service-step-title">
                                        <div>
                                            <h3>Nuevo periodo</h3>
                                            <p>El calendario bloquea todas las fechas anteriores al inicio permitido.</p>
                                        </div>
                                    </div>

                                    <div class="service-form-grid service-form-grid-renew">
                                        <label class="service-field">
                                            <span>Inicio del nuevo periodo</span>
                                            <div class="service-date-field">
                                                <i class="fas fa-calendar-check"></i>
                                                <input
                                                    type="text"
                                                    name="fecha_inicio_renovacion"
                                                    id="renewStart"
                                                    class="service-date-picker"
                                                    required
                                                    value="<?php echo servicioPlataformaEscapar($fechaRenovacionPredeterminada); ?>"
                                                    data-default-date="<?php echo servicioPlataformaEscapar($fechaRenovacionPredeterminada); ?>"
                                                    data-min-date="<?php echo servicioPlataformaEscapar($fechaRenovacionMinima->format('Y-m-d')); ?>"
                                                    autocomplete="off"
                                                    <?php echo !$renovacionDisponible ? 'disabled' : ''; ?>
                                                >
                                            </div>
                                            <small>No puede ser menor al <?php echo servicioPlataformaEscapar($fechaRenovacionMinima->format('d/m/Y')); ?>.</small>
                                        </label>

                                        <label class="service-field">
                                            <span>Meses pagados</span>
                                            <div class="service-number-field">
                                                <input
                                                    type="number"
                                                    name="meses_renovacion"
                                                    id="renewMonths"
                                                    min="1"
                                                    max="120"
                                                    required
                                                    value="1"
                                                    <?php echo !$renovacionDisponible ? 'disabled' : ''; ?>
                                                >
                                                <span>meses</span>
                                            </div>
                                        </label>

                                        <label class="service-field">
                                            <span>Precio por mes</span>
                                            <div class="service-money-input">
                                                <span>$</span>
                                                <input
                                                    type="number"
                                                    name="precio_mensual_renovacion"
                                                    id="renewPrice"
                                                    min="0"
                                                    max="9999999999"
                                                    step="0.01"
                                                    required
                                                    value="<?php echo number_format($precioFormulario, 2, '.', ''); ?>"
                                                    <?php echo !$renovacionDisponible ? 'disabled' : ''; ?>
                                                >
                                                <em>MXN</em>
                                            </div>
                                        </label>
                                    </div>

                                    <div class="service-calculation renew-calculation">
                                        <div>
                                            <small>Nuevo vencimiento</small>
                                            <strong id="renewEnd">—</strong>
                                        </div>
                                        <div>
                                            <small>Total de renovación</small>
                                            <strong id="renewTotal">$0.00</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="service-step">
                                <div class="service-step-number">2</div>
                                <div class="service-step-content">
                                    <div class="service-step-title">
                                        <div>
                                            <h3>Pago y comprobante</h3>
                                            <p>Estos datos aparecerán dentro del PDF enviado por correo.</p>
                                        </div>
                                    </div>

                                    <div class="service-form-grid">
                                        <label class="service-field">
                                            <span>Referencia de pago</span>
                                            <input
                                                type="text"
                                                name="referencia_pago_renovacion"
                                                maxlength="120"
                                                placeholder="Transferencia, folio o comprobante"
                                                <?php echo !$renovacionDisponible ? 'disabled' : ''; ?>
                                            >
                                        </label>
                                        <label class="service-field service-field-wide">
                                            <span>Notas de la renovación</span>
                                            <textarea
                                                name="notas_renovacion"
                                                maxlength="500"
                                                rows="3"
                                                placeholder="Observaciones opcionales"
                                                <?php echo !$renovacionDisponible ? 'disabled' : ''; ?>
                                            ></textarea>
                                        </label>
                                    </div>

                                    <div class="service-email-notice <?php echo $administradoresCorreo === [] ? 'warning' : ''; ?>">
                                        <i class="fas <?php echo $administradoresCorreo === [] ? 'fa-triangle-exclamation' : 'fa-envelope-circle-check'; ?>"></i>
                                        <div>
                                            <?php if ($administradoresCorreo !== []): ?>
                                                <strong>Comprobante por correo</strong>
                                                <span>
                                                    Se enviará un PDF a <?php echo count($administradoresCorreo); ?> administrador(es) activo(s):
                                                    <?php echo servicioPlataformaEscapar(implode(', ', array_map(
                                                        static function (array $administrador): string {
                                                            return (string) $administrador['nombre'];
                                                        },
                                                        $administradoresCorreo
                                                    ))); ?>.
                                                </span>
                                            <?php else: ?>
                                                <strong>No hay destinatarios disponibles</strong>
                                                <span>
                                                    <?php echo servicioPlataformaEscapar(
                                                        $errorAdministradoresCorreo !== ''
                                                            ? $errorAdministradoresCorreo
                                                            : 'Crea o activa al menos un usuario con rol Administrador y un correo válido.'
                                                    ); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div class="service-form-actions renew-actions">
                                <?php if (!$renovacionDisponible): ?>
                                    <p>
                                        <i class="fas fa-lock"></i>
                                        El botón se habilitará automáticamente 3 días antes del vencimiento.
                                    </p>
                                <?php else: ?>
                                    <p>
                                        <i class="fas fa-file-pdf"></i>
                                        Al confirmar se actualizará la vigencia y se generará el comprobante PDF.
                                    </p>
                                <?php endif; ?>
                                <button
                                    type="submit"
                                    class="service-renew-button"
                                    <?php echo !$renovacionDisponible ? 'disabled' : ''; ?>
                                >
                                    <i class="fas <?php echo $renovacionDisponible ? 'fa-calendar-check' : 'fa-lock'; ?>"></i>
                                    <?php echo $renovacionDisponible
                                        ? 'Confirmar renovación'
                                        : 'Renovación no disponible'; ?>
                                </button>
                            </div>
                        </form>
                    </section>
                <?php endif; ?>
            </section>

            <section
                class="service-tab-panel <?php echo $tabActivo === 'historial' ? 'active' : ''; ?>"
                data-service-panel="historial"
            >
                <section class="service-card service-history">
                    <div class="service-card-head service-history-head">
                        <span class="service-card-icon">
                            <i class="fas fa-clock-rotate-left"></i>
                        </span>
                        <div>
                            <h2>Historial de movimientos</h2>
                            <p>Consulta altas, correcciones y renovaciones registradas.</p>
                        </div>
                        <span class="service-history-count"><?php echo count($historial); ?> movimientos</span>
                    </div>

                    <?php if ($historial === []): ?>
                        <div class="service-history-empty">
                            <i class="fas fa-receipt"></i>
                            <h3>Sin movimientos todavía</h3>
                            <p>El primer registro aparecerá cuando guardes el periodo inicial.</p>
                        </div>
                    <?php else: ?>
                        <div class="service-history-list">
                            <?php foreach ($historial as $movimiento): ?>
                                <?php
                                $tipoMovimiento = (string) $movimiento['tipo'];
                                $etiquetasMovimiento = [
                                    'alta' => 'Primer periodo',
                                    'renovacion' => 'Renovación',
                                    'ajuste' => 'Corrección',
                                ];
                                ?>
                                <article class="service-history-item">
                                    <div class="service-history-marker service-history-marker--<?php echo servicioPlataformaEscapar($tipoMovimiento); ?>">
                                        <i class="fas <?php echo $tipoMovimiento === 'renovacion' ? 'fa-rotate' : ($tipoMovimiento === 'alta' ? 'fa-calendar-plus' : 'fa-pen'); ?>"></i>
                                    </div>
                                    <div class="service-history-main">
                                        <div class="service-history-title">
                                            <span class="service-history-type service-history-type--<?php echo servicioPlataformaEscapar($tipoMovimiento); ?>">
                                                <?php echo servicioPlataformaEscapar($etiquetasMovimiento[$tipoMovimiento] ?? ucfirst($tipoMovimiento)); ?>
                                            </span>
                                            <time><?php echo date('d/m/Y H:i', strtotime((string) $movimiento['created_at'])); ?></time>
                                        </div>
                                        <strong>
                                            <?php echo servicioPlataformaEscapar(servicio_plataforma_formatear_fecha((string) $movimiento['periodo_inicio'])); ?>
                                            al
                                            <?php echo servicioPlataformaEscapar(servicio_plataforma_formatear_fecha((string) $movimiento['periodo_fin'])); ?>
                                        </strong>
                                        <div class="service-history-meta">
                                            <span><i class="fas fa-calendar"></i> <?php echo (int) $movimiento['meses']; ?> mes(es)</span>
                                            <span><i class="fas fa-user"></i> <?php echo servicioPlataformaEscapar((string) ($movimiento['usuario_nombre'] ?: 'Usuario')); ?></span>
                                            <?php if (trim((string) ($movimiento['referencia_pago'] ?? '')) !== ''): ?>
                                                <span><i class="fas fa-hashtag"></i> <?php echo servicioPlataformaEscapar((string) $movimiento['referencia_pago']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="service-history-amount">
                                        <small>Total</small>
                                        <strong>$<?php echo number_format((float) $movimiento['importe_total'], 2); ?></strong>
                                        <span>$<?php echo number_format((float) $movimiento['precio_mensual'], 2); ?> / mes</span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </section>
        <?php endif; ?>
    </main>

    <?php if (is_array($flash)): ?>
        <script>
            document.addEventListener('DOMContentLoaded', function () {
                Swal.fire({
                    icon: <?php echo json_encode((string) ($flash['tipo'] ?? 'info')); ?>,
                    title: <?php echo json_encode((string) ($flash['titulo'] ?? 'Aviso'), JSON_UNESCAPED_UNICODE); ?>,
                    text: <?php echo json_encode((string) ($flash['mensaje'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
                    confirmButtonText: 'Entendido',
                    confirmButtonColor: '#1e3a8a'
                });
            });
        </script>
    <?php endif; ?>

    <script>
    (function () {
        const tabs = Array.from(document.querySelectorAll('[data-service-tab]'));
        const panels = Array.from(document.querySelectorAll('[data-service-panel]'));

        function activateTab(name, updateUrl) {
            const selected = tabs.find(function (tab) {
                return tab.dataset.serviceTab === name && !tab.disabled;
            });

            if (!selected) {
                return;
            }

            tabs.forEach(function (tab) {
                tab.classList.toggle('active', tab === selected);
            });

            panels.forEach(function (panel) {
                panel.classList.toggle(
                    'active',
                    panel.dataset.servicePanel === name
                );
            });

            if (updateUrl && window.history && window.history.replaceState) {
                const url = new URL(window.location.href);
                url.searchParams.set('tab', name);
                window.history.replaceState({}, '', url.toString());
            }
        }

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                activateTab(tab.dataset.serviceTab, true);
            });
        });

        document.querySelectorAll('[data-go-tab]').forEach(function (button) {
            button.addEventListener('click', function () {
                activateTab(button.dataset.goTab, true);
            });
        });

        function formatDate(date) {
            if (!(date instanceof Date) || Number.isNaN(date.getTime())) {
                return '—';
            }

            return new Intl.DateTimeFormat('es-MX', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric'
            }).format(date);
        }

        function parseIsoDate(value) {
            if (!/^\d{4}-\d{2}-\d{2}$/.test(String(value || ''))) {
                return null;
            }

            const parts = value.split('-').map(Number);
            const date = new Date(Date.UTC(parts[0], parts[1] - 1, parts[2]));

            if (
                date.getUTCFullYear() !== parts[0]
                || date.getUTCMonth() !== parts[1] - 1
                || date.getUTCDate() !== parts[2]
            ) {
                return null;
            }

            return date;
        }

        function calculateExpiration(startValue, monthsValue) {
            const start = parseIsoDate(startValue);
            const months = Math.max(0, Number(monthsValue) || 0);

            if (!start || months < 1) {
                return null;
            }

            const startDay = start.getUTCDate();
            const targetFirst = new Date(Date.UTC(
                start.getUTCFullYear(),
                start.getUTCMonth() + months,
                1
            ));
            const daysInTargetMonth = new Date(Date.UTC(
                targetFirst.getUTCFullYear(),
                targetFirst.getUTCMonth() + 1,
                0
            )).getUTCDate();
            const anniversaryDay = Math.min(startDay, daysInTargetMonth);
            const anniversary = new Date(Date.UTC(
                targetFirst.getUTCFullYear(),
                targetFirst.getUTCMonth(),
                anniversaryDay
            ));

            anniversary.setUTCDate(anniversary.getUTCDate() - 1);
            return anniversary;
        }

        function money(value) {
            return new Intl.NumberFormat('es-MX', {
                style: 'currency',
                currency: 'MXN'
            }).format(Math.max(0, Number(value) || 0));
        }

        function bindCalculation(options) {
            const start = document.getElementById(options.startId);
            const months = document.getElementById(options.monthsId);
            const price = document.getElementById(options.priceId);
            const end = document.getElementById(options.endId);
            const total = document.getElementById(options.totalId);

            if (!start || !months || !price || !end || !total) {
                return;
            }

            function refresh() {
                const expiration = calculateExpiration(
                    start.value,
                    months.value
                );
                end.textContent = formatDate(expiration);
                total.textContent = money(
                    (Number(months.value) || 0)
                    * (Number(price.value) || 0)
                );
            }

            ['input', 'change'].forEach(function (eventName) {
                start.addEventListener(eventName, refresh);
                months.addEventListener(eventName, refresh);
                price.addEventListener(eventName, refresh);
            });

            start.addEventListener('service-date-change', refresh);
            refresh();
        }

        document.querySelectorAll('.service-date-picker').forEach(function (input) {
            if (typeof flatpickr !== 'function') {
                input.type = 'date';
                input.min = input.dataset.minDate || '';
                return;
            }

            flatpickr(input, {
                locale: 'es',
                dateFormat: 'Y-m-d',
                altInput: true,
                altFormat: 'd/m/Y',
                defaultDate: input.dataset.defaultDate || input.value || null,
                minDate: input.dataset.minDate || null,
                disableMobile: true,
                allowInput: false,
                monthSelectorType: 'static',
                onChange: function (selectedDates, dateStr) {
                    input.value = dateStr;
                    input.dispatchEvent(new Event('service-date-change'));
                }
            });
        });

        bindCalculation({
            startId: 'configStart',
            monthsId: 'configMonths',
            priceId: 'configPrice',
            endId: 'configEnd',
            totalId: 'configTotal'
        });

        bindCalculation({
            startId: 'renewStart',
            monthsId: 'renewMonths',
            priceId: 'renewPrice',
            endId: 'renewEnd',
            totalId: 'renewTotal'
        });

        const configForm = document.getElementById('serviceConfigForm');

        if (configForm) {
            configForm.addEventListener('submit', function (event) {
                const title = <?php echo json_encode(
                    $tienePeriodo
                        ? '¿Guardar cambios del periodo actual?'
                        : '¿Registrar el primer periodo?',
                    JSON_UNESCAPED_UNICODE
                ); ?>;

                if (configForm.dataset.confirmed === '1') {
                    return;
                }

                event.preventDefault();

                Swal.fire({
                    icon: 'question',
                    title: title,
                    text: <?php echo json_encode(
                        $tienePeriodo
                            ? 'Esto corregirá los datos actuales, pero no contará como renovación.'
                            : 'Verifica que la fecha, los meses y el precio sean correctos.',
                        JSON_UNESCAPED_UNICODE
                    ); ?>,
                    showCancelButton: true,
                    confirmButtonText: 'Sí, guardar',
                    cancelButtonText: 'Revisar',
                    confirmButtonColor: '#1e3a8a'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        configForm.dataset.confirmed = '1';
                        configForm.submit();
                    }
                });
            });
        }

        const renewForm = document.getElementById('renewForm');

        if (renewForm) {
            renewForm.addEventListener('submit', function (event) {
                if (renewForm.dataset.confirmed === '1') {
                    return;
                }

                event.preventDefault();

                Swal.fire({
                    icon: 'question',
                    title: '¿Confirmar renovación?',
                    text: 'Se actualizará la vigencia y se enviará el comprobante PDF a los administradores activos.',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, renovar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: '#059669'
                }).then(function (result) {
                    if (result.isConfirmed) {
                        renewForm.dataset.confirmed = '1';
                        renewForm.submit();
                    }
                });
            });
        }
    })();
    </script>
</body>
</html>
