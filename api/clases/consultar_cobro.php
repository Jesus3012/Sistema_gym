<?php
declare(strict_types=1);

/*
 * Endpoint JSON exclusivo para consultar si una membresía cubre una clase.
 */

if (ob_get_level() === 0) {
    ob_start();
}

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

$GLOBALS['clase_api_response_sent'] = false;

function clase_api_limpiar_salida(): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
}

function clase_api_responder(array $payload, int $status = 200): void
{
    $GLOBALS['clase_api_response_sent'] = true;
    clase_api_limpiar_salida();

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }

    http_response_code($status);

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    echo $json !== false
        ? $json
        : '{"success":false,"message":"No se pudo generar la respuesta JSON."}';

    exit;
}

function clase_api_error(
    string $message,
    int $status = 422,
    string $code = 'class_validation_error'
): void {
    clase_api_responder(
        [
            'success' => false,
            'message' => $message,
            'code' => $code,
        ],
        $status
    );
}

set_error_handler(
    static function (
        int $severity,
        string $message,
        string $file,
        int $line
    ): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        throw new ErrorException(
            $message,
            0,
            $severity,
            $file,
            $line
        );
    }
);

register_shutdown_function(
    static function (): void {
        if (!empty($GLOBALS['clase_api_response_sent'])) {
            return;
        }

        $last = error_get_last();

        if (!is_array($last)) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        ];

        if (!in_array((int) ($last['type'] ?? 0), $fatalTypes, true)) {
            return;
        }

        error_log(
            '[Clases consultar cobro][fatal] '
            . (string) ($last['message'] ?? 'Error fatal')
            . ' en '
            . (string) ($last['file'] ?? '')
            . ':'
            . (int) ($last['line'] ?? 0)
        );

        clase_api_limpiar_salida();

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        http_response_code(500);

        echo json_encode(
            [
                'success' => false,
                'message' => 'Ocurrió un error interno al consultar la membresía.',
                'code' => 'class_api_fatal_error',
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_INVALID_UTF8_SUBSTITUTE
        );
    }
);

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        clase_api_error(
            'Método no permitido.',
            405,
            'method_not_allowed'
        );
    }

    if (empty($_SESSION['user_id'])) {
        clase_api_error(
            'Tu sesión terminó. Inicia sesión nuevamente.',
            401,
            'session_expired'
        );
    }

    mysqli_report(
        MYSQLI_REPORT_ERROR
        | MYSQLI_REPORT_STRICT
    );

    $databaseFile = __DIR__ . '/../../config/database.php';
    $helperFile = __DIR__ . '/../../includes/clases_registro.php';

    if (!is_file($databaseFile) || !is_file($helperFile)) {
        throw new RuntimeException(
            'No se encontraron los archivos requeridos del módulo de clases.'
        );
    }

    require_once $databaseFile;
    require_once $helperFile;

    $raw = (string) file_get_contents('php://input');
    $input = json_decode($raw, true);

    if (!is_array($input)) {
        $input = $_POST;
    }

    $csrfRecibido = trim((string) ($input['csrf_token'] ?? ''));
    $csrfSesion = trim((string) ($_SESSION['csrf_clases_registro'] ?? ''));

    if (
        $csrfSesion === ''
        || $csrfRecibido === ''
        || !hash_equals($csrfSesion, $csrfRecibido)
    ) {
        clase_api_error(
            'La sesión del formulario venció. Actualiza la página.',
            419,
            'csrf_expired'
        );
    }

    $claseId = (int) ($input['clase_id'] ?? 0);
    $clienteId = (int) ($input['cliente_id'] ?? 0);
    $fechaClase = trim((string) ($input['fecha_clase'] ?? ''));
    $sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);

    if ($claseId <= 0 || $clienteId <= 0) {
        clase_api_error(
            'Selecciona un socio y una clase válidos.',
            422,
            'invalid_member_or_class'
        );
    }

    if ($sucursalId <= 0) {
        clase_api_error(
            'Selecciona una sucursal operativa.',
            409,
            'branch_required'
        );
    }

    if (!clase_registro_fecha_valida($fechaClase)) {
        clase_api_error(
            'Selecciona una fecha válida para la clase.',
            422,
            'invalid_class_date'
        );
    }

    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn instanceof mysqli) {
        throw new RuntimeException(
            'No se pudo establecer la conexión con la base de datos.'
        );
    }

    $conn->set_charset('utf8mb4');

    $stmtSucursal = $conn->prepare(
        "SELECT id
         FROM sucursales
         WHERE id = ?
           AND estado = 'activa'
         LIMIT 1"
    );
    $stmtSucursal->bind_param('i', $sucursalId);
    $stmtSucursal->execute();
    $sucursalActiva = $stmtSucursal->get_result()->fetch_assoc();
    $stmtSucursal->close();

    if (!is_array($sucursalActiva)) {
        clase_api_error(
            'La sucursal seleccionada está inactiva.',
            409,
            'inactive_branch'
        );
    }

    $cobro = clase_registro_calcular_cobro(
        $conn,
        $claseId,
        $sucursalId,
        $clienteId,
        $fechaClase
    );

    clase_api_responder([
        'success' => true,
        'cliente' => $cobro['cliente'],
        'membresia' => $cobro['membresia'],
        'ultima_membresia' => $cobro['ultima_membresia'] ?? null,
        'precio_clase' => $cobro['precio_clase'],
        'monto_cobrar' => $cobro['monto_cobrar'],
        'cubierto_membresia' => $cobro['cubierto_membresia'],
        'motivo' => $cobro['motivo'],
    ]);
} catch (Throwable $error) {
    error_log(
        '[Clases consultar cobro] '
        . get_class($error)
        . ': '
        . $error->getMessage()
        . ' en '
        . $error->getFile()
        . ':'
        . $error->getLine()
    );

    clase_api_error(
        $error->getMessage() !== ''
            ? $error->getMessage()
            : 'No fue posible consultar la membresía.',
        500,
        'class_api_exception'
    );
}
