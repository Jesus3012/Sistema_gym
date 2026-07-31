<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/super_admin_helper.php';
require_once __DIR__ . '/includes/expediente_salud_helper.php';

if (!expediente_es_administrativo()) {
    http_response_code(403);
    exit('Acceso restringido.');
}

$expedienteId = (int) ($_GET['id'] ?? 0);
if ($expedienteId <= 0) {
    http_response_code(400);
    exit('Expediente no válido.');
}

$database = new Database();
$conn = $database->getConnection();
if (!$conn instanceof mysqli) {
    throw new RuntimeException('No fue posible conectar con la base de datos.');
}
$conn->set_charset('utf8mb4');

$sql = "
    SELECT
        e.*,
        c.nombre,
        c.apellido,
        c.telefono,
        c.email,
        c.contacto_emergencia_nombre,
        c.contacto_emergencia_telefono,
        s.nombre AS sucursal_nombre,
        u.nombre AS administrador_nombre,
        g.nombre AS gimnasio_nombre,
        g.logo AS gimnasio_logo,
        g.telefono AS gimnasio_telefono,
        g.email AS gimnasio_email,
        g.direccion AS gimnasio_direccion
    FROM expedientes_salud e
    INNER JOIN clientes c ON c.id = e.cliente_id
    INNER JOIN sucursales s ON s.id = e.sucursal_id
    INNER JOIN usuarios u ON u.id = e.aplicado_por
    LEFT JOIN configuracion_gimnasio g ON g.id = 1
    WHERE e.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
if (!$stmt) {
    throw new RuntimeException('No fue posible preparar el expediente.');
}
$stmt->bind_param('i', $expedienteId);
$stmt->execute();
$resultado = $stmt->get_result();
$expediente = $resultado ? $resultado->fetch_assoc() : null;
$stmt->close();

if (!$expediente) {
    http_response_code(404);
    exit('El expediente solicitado no existe.');
}

$respuestas = [];
$stmt = $conn->prepare("SELECT * FROM expedientes_salud_respuestas WHERE expediente_id = ? ORDER BY orden_snapshot ASC, id ASC");
if ($stmt) {
    $stmt->bind_param('i', $expedienteId);
    $stmt->execute();
    $resultado = $stmt->get_result();
    while ($resultado && $fila = $resultado->fetch_assoc()) {
        $respuestas[] = $fila;
    }
    $stmt->close();
}

$logo = trim((string) ($expediente['gimnasio_logo'] ?? ''));
$logoExiste = $logo !== '' && is_file(__DIR__ . DIRECTORY_SEPARATOR . ltrim($logo, '/\\'));

/**
 * Convierte el valor técnico guardado en la base de datos en una respuesta
 * entendible para el documento impreso.
 */
function expediente_respuesta_impresa(array $respuesta): string
{
    $valor = trim((string) ($respuesta['respuesta_texto'] ?? ''));
    $tipo = trim((string) ($respuesta['tipo_respuesta_snapshot'] ?? ''));

    if ($valor === '') {
        return 'Sin respuesta';
    }

    if ($tipo === 'si_no') {
        if (in_array(strtolower($valor), ['1', 'si', 'sí', 'true'], true)) {
            return 'Sí';
        }

        if (in_array(strtolower($valor), ['0', 'no', 'false'], true)) {
            return 'No';
        }
    }

    return $valor;
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Expediente de salud #<?php echo (int) $expedienteId; ?></title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; color: #1f2937; background: #eef2f7; font-family: Arial, Helvetica, sans-serif; }
        .actions { position: sticky; top: 0; z-index: 2; display: flex; justify-content: center; gap: 10px; padding: 12px; background: rgba(238,242,247,.96); }
        .actions button, .actions a { display: inline-flex; align-items: center; justify-content: center; min-height: 40px; padding: 0 15px; border: 1px solid #cbd5e1; border-radius: 9px; color: #1e3a8a; background: #fff; font-weight: 700; text-decoration: none; cursor: pointer; }
        .actions button { color: #fff; border-color: #1e3a8a; background: #1e3a8a; }
        .sheet { width: min(920px, calc(100% - 28px)); margin: 0 auto 28px; padding: 34px 38px; background: #fff; box-shadow: 0 18px 55px rgba(15,23,42,.13); }
        .header { display: flex; align-items: center; justify-content: space-between; gap: 24px; padding-bottom: 18px; border-bottom: 2px solid #172554; }
        .brand { display: flex; align-items: center; gap: 14px; }
        .brand img { width: 58px; height: 58px; object-fit: contain; }
        .logo-fallback { display: grid; width: 58px; height: 58px; place-items: center; border-radius: 12px; color: #1e3a8a; background: #eef4ff; font-size: 22px; font-weight: 900; }
        .brand h1 { margin: 0; color: #172554; font-size: 22px; }
        .brand p { margin: 4px 0 0; color: #64748b; font-size: 11px; }
        .folio { text-align: right; }
        .folio span { display: block; color: #64748b; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; }
        .folio strong { display: block; margin-top: 4px; color: #172554; font-size: 17px; }
        .title { margin: 24px 0 16px; }
        .title h2 { margin: 0; color: #172554; font-size: 20px; }
        .title p { margin: 5px 0 0; color: #64748b; font-size: 11px; line-height: 1.5; }
        .meta { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; margin-bottom: 18px; }
        .meta div { padding: 10px 11px; border: 1px solid #dbe4f0; border-radius: 9px; background: #f8fafc; }
        .meta span { display: block; color: #64748b; font-size: 9px; text-transform: uppercase; letter-spacing: .06em; }
        .meta strong { display: block; margin-top: 4px; color: #334155; font-size: 11px; line-height: 1.35; }
        .section { margin-top: 22px; }
        .section h3 { margin: 0 0 9px; padding-bottom: 6px; border-bottom: 1px solid #cbd5e1; color: #172554; font-size: 14px; }
        .answer { display: grid; grid-template-columns: minmax(0, 1fr) minmax(160px, .45fr); gap: 12px; padding: 8px 0; border-bottom: 1px solid #edf2f7; }
        .answer strong { font-size: 10.5px; line-height: 1.45; }
        .answer span { color: #475569; font-size: 10.5px; line-height: 1.45; }
        .answer.alert { margin: 4px 0; padding: 8px; border: 1px solid #fcd34d; border-radius: 7px; background: #fffbeb; }
        .document { margin-top: 20px; padding: 16px; border: 1px solid #bfdbfe; border-radius: 10px; background: #f8fbff; }
        .document h3 { margin: 0 0 10px; color: #172554; font-size: 14px; }
        .document p { margin: 0; color: #475569; font-size: 10.5px; line-height: 1.65; white-space: pre-line; }
        .signature { display: grid; grid-template-columns: minmax(0, 1fr) 260px; gap: 24px; align-items: end; margin-top: 24px; }
        .signature-info { color: #475569; font-size: 10.5px; line-height: 1.55; }
        .signature-info strong { color: #172554; }
        .signature-image { min-height: 90px; border-bottom: 1px solid #334155; text-align: center; }
        .signature-image img { max-width: 240px; max-height: 80px; object-fit: contain; }
        .signature-label { margin-top: 5px; color: #64748b; font-size: 9px; text-align: center; }
        .followup { margin-top: 18px; padding: 12px; border: 1px solid #dbe4f0; border-radius: 9px; }
        .followup strong { color: #172554; font-size: 11px; }
        .followup p { margin: 5px 0 0; color: #64748b; font-size: 10px; line-height: 1.5; }
        .integrity { margin-top: 20px; color: #94a3b8; font-size: 8px; word-break: break-all; }
        .footer { margin-top: 18px; padding-top: 10px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 8.5px; text-align: center; }
        @media print {
            @page { size: A4; margin: 11mm; }
            body { background: #fff; }
            .actions { display: none; }
            .sheet { width: 100%; margin: 0; padding: 0; box-shadow: none; }
            .answer, .document, .followup { break-inside: avoid; }
        }
        @media (max-width: 650px) {
            .sheet { padding: 22px 18px; }
            .header, .signature { display: block; }
            .folio { margin-top: 14px; text-align: left; }
            .meta { grid-template-columns: 1fr; }
            .answer { grid-template-columns: 1fr; gap: 4px; }
            .signature-image { margin-top: 18px; }
        }

        .acceptance-record { align-items: center; }
        .acceptance-checkmark {
            display: grid;
            width: 58px;
            height: 58px;
            place-items: center;
            border: 2px solid #86efac;
            border-radius: 999px;
            color: #047857;
            background: #f0fdf4;
            font-size: 1.65rem;
            font-weight: 900;
        }
    </style>
</head>
<body>
<div class="actions">
    <a href="expediente_salud.php?tab=expedientes&ver=<?php echo (int) $expedienteId; ?>">Volver</a>
    <button type="button" onclick="window.print()">Imprimir o guardar PDF</button>
</div>

<article class="sheet">
    <header class="header">
        <div class="brand">
            <?php if ($logoExiste): ?>
                <img src="<?php echo expediente_h($logo); ?>" alt="Logo">
            <?php else: ?>
                <div class="logo-fallback">EGO</div>
            <?php endif; ?>
            <div>
                <h1><?php echo expediente_h($expediente['gimnasio_nombre'] ?: 'Gimnasio'); ?></h1>
                <p>Expediente de salud y aceptación de responsabilidad</p>
            </div>
        </div>
        <div class="folio">
            <span>Folio interno</span>
            <strong>MED-<?php echo str_pad((string) $expedienteId, 8, '0', STR_PAD_LEFT); ?></strong>
        </div>
    </header>

    <section class="title">
        <h2><?php echo expediente_h($expediente['cuestionario_nombre']); ?></h2>
        <p>Registro histórico no editable</p>
    </section>

    <section class="meta">
        <div><span>Socio</span><strong><?php echo expediente_h(trim($expediente['nombre'] . ' ' . $expediente['apellido'])); ?></strong></div>
        <div><span>Teléfono</span><strong><?php echo expediente_h($expediente['telefono'] ?: 'No registrado'); ?></strong></div>
        <div><span>Correo</span><strong><?php echo expediente_h($expediente['email'] ?: 'No registrado'); ?></strong></div>
        <div><span>Sucursal</span><strong><?php echo expediente_h($expediente['sucursal_nombre']); ?></strong></div>
        <div><span>Fecha de aplicación</span><strong><?php echo expediente_h(expediente_formatear_fecha($expediente['fecha_aplicacion'], true)); ?></strong></div>
        <div><span>Vigente hasta</span><strong><?php echo expediente_h(expediente_formatear_fecha($expediente['vigente_hasta'])); ?></strong></div>
        <div><span>Administrador</span><strong><?php echo expediente_h($expediente['administrador_nombre']); ?></strong></div>
        <div><span>Seguimiento</span><strong><?php echo expediente_h(expediente_estado_etiqueta($expediente['estado_seguimiento'])); ?></strong></div>
        <div><span>Respuestas para revisión</span><strong><?php echo (int) $expediente['total_alertas']; ?></strong></div>
    </section>

    <?php $seccion = ''; ?>
    <?php foreach ($respuestas as $respuesta): ?>
        <?php if ($seccion !== $respuesta['seccion_snapshot']): ?>
            <?php if ($seccion !== ''): ?></section><?php endif; ?>
            <?php $seccion = (string) $respuesta['seccion_snapshot']; ?>
            <section class="section">
                <h3><?php echo expediente_h($seccion); ?></h3>
        <?php endif; ?>
        <div class="answer <?php echo (int) $respuesta['genera_alerta'] === 1 ? 'alert' : ''; ?>">
            <strong><?php echo expediente_h($respuesta['pregunta_snapshot']); ?></strong>
            <span><?php echo expediente_h(expediente_respuesta_impresa($respuesta)); ?></span>
        </div>
    <?php endforeach; ?>
    <?php if ($seccion !== ''): ?></section><?php endif; ?>

    <section class="document">
        <h3><?php echo expediente_h($expediente['documento_titulo_snapshot']); ?></h3>
        <p><?php echo nl2br(expediente_h($expediente['documento_texto_snapshot'])); ?></p>
    </section>

    <section class="signature acceptance-record">
        <div class="signature-info">
            <strong>Aceptación del documento:</strong> Registrada<br>
            <strong>Aceptado por:</strong> <?php echo expediente_h($expediente['nombre_firmante']); ?><br>
            <strong>Relación con el socio:</strong> <?php echo expediente_h($expediente['parentesco_firmante']); ?><br>
            <strong>Fecha:</strong> <?php echo expediente_h(expediente_formatear_fecha($expediente['fecha_aplicacion'], true)); ?><br>
        </div>
        <div class="acceptance-checkmark">✓</div>
    </section>

    <section class="followup">
        <strong>Observaciones administrativas</strong>
        <p><?php echo expediente_h($expediente['observaciones_admin'] ?: 'Sin observaciones adicionales.'); ?></p>
    </section>

    <div class="integrity">Huella de integridad: <?php echo expediente_h($expediente['hash_integridad'] ?: 'No disponible'); ?></div>
    <footer class="footer">
        Documento generado desde el sistema de gestión. El cuestionario es un registro administrativo y no sustituye una valoración médica.
    </footer>
</article>
</body>
</html>