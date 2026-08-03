<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/super_admin_helper.php';
require_once __DIR__ . '/includes/expediente_salud_helper.php';
require_once __DIR__ . '/includes/expediente_salud_pdf_helper.php';

$rolExpedienteSesion = rol_normalizar_sistema((string) (
    $_SESSION['user_rol'] ?? ''
));
$rolExpedienteBase = rol_base_real_sesion();

$esAdministradorExpediente =
    rol_es_administrativo($rolExpedienteSesion)
    || rol_es_administrativo($rolExpedienteBase);

$esRecepcionistaExpediente =
    $rolExpedienteSesion === 'recepcionista'
    || $rolExpedienteBase === 'recepcionista';

if (!$esAdministradorExpediente && !$esRecepcionistaExpediente) {
    http_response_code(403);
    exit('Acceso restringido.');
}

$expedienteId = (int) ($_GET['id'] ?? 0);

if ($expedienteId <= 0) {
    http_response_code(400);
    exit('Expediente no válido.');
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn instanceof mysqli) {
        throw new RuntimeException(
            'No fue posible conectar con la base de datos.'
        );
    }

    $conn->set_charset('utf8mb4');

    /*
     * Recepción solo puede abrir documentos de la sucursal activa.
     * La validación se realiza antes de generar el PDF para impedir que
     * un cambio manual del ID exponga otro expediente.
     */
    if (!$esAdministradorExpediente) {
        $sucursalActual = (int) ($_SESSION['sucursal_id'] ?? 0);

        $stmtAcceso = $conn->prepare(
            "SELECT id
             FROM expedientes_salud
             WHERE id = ?
               AND sucursal_id = ?
             LIMIT 1"
        );

        if (!$stmtAcceso) {
            throw new RuntimeException(
                'No fue posible validar el acceso al documento.'
            );
        }

        $stmtAcceso->bind_param(
            'ii',
            $expedienteId,
            $sucursalActual
        );
        $stmtAcceso->execute();
        $permitido = $stmtAcceso
            ->get_result()
            ->fetch_assoc();
        $stmtAcceso->close();

        if (!$permitido) {
            http_response_code(403);
            exit(
                'No tienes permiso para consultar este expediente '
                . 'desde la sucursal activa.'
            );
        }
    }

    $resultado = expediente_generar_pdf_memoria(
        $conn,
        $expedienteId
    );

    $modo = strtolower(trim((string) ($_GET['modo'] ?? 'ver')));
    $disposicion = $modo === 'descargar'
        ? 'attachment'
        : 'inline';

    $nombre = str_replace(
        ['"', "\r", "\n"],
        '',
        (string) $resultado['nombre']
    );

    $contenido = (string) $resultado['contenido'];

    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: application/pdf');
    header(
        'Content-Disposition: '
        . $disposicion
        . '; filename="'
        . $nombre
        . '"'
    );
    header('Content-Length: ' . strlen($contenido));
    header(
        'Cache-Control: private, no-store, no-cache, '
        . 'must-revalidate, max-age=0'
    );
    header('Pragma: no-cache');

    echo $contenido;
    exit;
} catch (Throwable $e) {
    http_response_code(500);
    exit(
        'No fue posible generar el PDF: '
        . $e->getMessage()
    );
}
