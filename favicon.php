<?php
// Archivo: favicon.php
// Devuelve el logo configurado como favicon dinámico.

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

$projectRoot = realpath(__DIR__);
$logoRuta = '';

try {
    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn instanceof mysqli) {
        throw new RuntimeException(
            'No fue posible conectar con la base de datos.'
        );
    }

    $conn->set_charset('utf8mb4');

    $resultado = $conn->query(
        "SELECT logo
         FROM configuracion_gimnasio
         WHERE id = 1
         LIMIT 1"
    );

    if ($resultado && $fila = $resultado->fetch_assoc()) {
        $logoConfigurado = trim(
            (string) ($fila['logo'] ?? '')
        );

        if (
            $logoConfigurado !== ''
            && !preg_match('#^https?://#i', $logoConfigurado)
        ) {
            $rutaRelativa = str_replace(
                ['/', '\\'],
                DIRECTORY_SEPARATOR,
                ltrim($logoConfigurado, '/\\')
            );

            $posibleRuta = realpath(
                __DIR__
                . DIRECTORY_SEPARATOR
                . $rutaRelativa
            );

            if (
                $projectRoot !== false
                && $posibleRuta !== false
                && is_file($posibleRuta)
                && is_readable($posibleRuta)
            ) {
                $prefijoPermitido =
                    rtrim($projectRoot, DIRECTORY_SEPARATOR)
                    . DIRECTORY_SEPARATOR;

                if (
                    strncmp(
                        $posibleRuta,
                        $prefijoPermitido,
                        strlen($prefijoPermitido)
                    ) === 0
                ) {
                    $logoRuta = $posibleRuta;
                }
            }
        }
    }
} catch (Throwable $error) {
    error_log(
        '[Favicon dinámico] ' . $error->getMessage()
    );
}

/*
 * Logo de respaldo en caso de que el configurado
 * no exista o tenga una ruta incorrecta.
 */
if ($logoRuta === '') {
    $respaldos = [
        __DIR__ . '/img/favicon.png',
        __DIR__ . '/img/logo-gym.png',
        __DIR__ . '/img/logo-gym.jpg',
        __DIR__ . '/img/logo-gym.webp',
    ];

    foreach ($respaldos as $respaldo) {
        if (is_file($respaldo) && is_readable($respaldo)) {
            $logoRuta = $respaldo;
            break;
        }
    }
}

if ($logoRuta === '') {
    http_response_code(404);
    exit;
}

$extension = strtolower(
    pathinfo($logoRuta, PATHINFO_EXTENSION)
);

$tiposMime = [
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
    'ico' => 'image/x-icon',
];

$tipoMime = $tiposMime[$extension] ?? '';

if (function_exists('mime_content_type')) {
    $tipoDetectado = mime_content_type($logoRuta);

    if (
        is_string($tipoDetectado)
        && strpos($tipoDetectado, 'image/') === 0
    ) {
        $tipoMime = $tipoDetectado;
    }
}

if ($tipoMime === '') {
    http_response_code(415);
    exit;
}

$tamano = filesize($logoRuta);

header('Content-Type: ' . $tipoMime);
header('Content-Disposition: inline');
header('X-Content-Type-Options: nosniff');

/*
 * Evita conservar el logo anterior cuando se cambia
 * desde Configuración.
 */
header(
    'Cache-Control: no-store, no-cache, must-revalidate, max-age=0'
);
header('Pragma: no-cache');
header('Expires: 0');

if ($tamano !== false) {
    header('Content-Length: ' . $tamano);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile($logoRuta);
exit;