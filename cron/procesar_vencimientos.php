<?php
declare(strict_types=1);

/**
 * Cron automático de avisos de vencimiento para Hostinger.
 *
 * Este archivo:
 * - No usa sesión.
 * - No usa auth_guard.php.
 * - No usa CSRF.
 * - Procesa todas las sucursales.
 * - Solo puede ejecutarse mediante PHP CLI/Cron.
 */

date_default_timezone_set('America/Mexico_City');

ignore_user_abort(true);

if (function_exists('set_time_limit')) {
    @set_time_limit(0);
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Ejecución disponible únicamente desde Cron.');
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/super_admin_helper.php';
require_once __DIR__ . '/../includes/notificaciones_context.php';
require_once __DIR__ . '/../includes/notificaciones_mailer.php';
require_once __DIR__ . '/../includes/notificaciones_vencimiento_service.php';

$startedAt = microtime(true);

echo "========================================\n";
echo "CRON DE NOTIFICACIONES DE VENCIMIENTO\n";
echo 'Inicio: ' . date('Y-m-d H:i:s') . "\n";
echo "Zona horaria PHP: " . date_default_timezone_get() . "\n";
echo "========================================\n";

try {
    $database = new Database();
    $db = $database->getConnection();

    if (!$db instanceof mysqli) {
        throw new RuntimeException(
            'No fue posible establecer la conexión con la base de datos.'
        );
    }

    $db->set_charset('utf8mb4');

    /*
     * null significa: procesar todas las sucursales.
     */
    $result = notif_process_expirations($db, null);

    if (!empty($result['proceso_en_ejecucion'])) {
        echo "Resultado: omitido porque ya existe otra ejecución activa.\n";
        $db->close();
        exit(0);
    }

    echo 'Membresías encontradas: '
        . (int) $result['encontradas']
        . "\n";

    echo 'Avisos enviados a 3 días: '
        . (int) $result['enviados_3_dias']
        . "\n";

    echo 'Avisos enviados el día del vencimiento: '
        . (int) $result['enviados_vencidos']
        . "\n";

    echo 'Omitidas porque ya fueron enviadas: '
        . (int) $result['omitidas_ya_enviadas']
        . "\n";

    echo 'Errores: '
        . (int) $result['errores']
        . "\n";

    if (!empty($result['errores_detalle'])) {
        echo "Detalle de errores:\n";

        foreach ($result['errores_detalle'] as $error) {
            echo '- ' . $error . "\n";
        }
    }

    echo 'Fin: ' . date('Y-m-d H:i:s') . "\n";
    echo 'Duración: '
        . number_format(microtime(true) - $startedAt, 2)
        . " segundos\n";
    echo "========================================\n";

    $exitCode = (int) $result['errores'] > 0 ? 1 : 0;

    $db->close();
    exit($exitCode);
} catch (Throwable $error) {
    fwrite(
        STDERR,
        '[ERROR GENERAL] '
        . date('Y-m-d H:i:s')
        . ' · '
        . $error->getMessage()
        . "\n"
    );

    exit(1);
}
