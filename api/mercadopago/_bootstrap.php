<?php
// Archivo: api/mercadopago/_bootstrap.php
// Contexto JSON compartido para ventas y clases con Point por sucursal.

declare(strict_types=1);

if (ob_get_level() === 0) {
    ob_start();
}

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL);

$GLOBALS['mp_api_response_sent'] = false;

function mp_api_clean_buffer(): void
{
    if (ob_get_level() > 0) {
        ob_clean();
    }
}

function mp_api_json(array $payload, int $status = 200): void
{
    $GLOBALS['mp_api_response_sent'] = true;
    mp_api_clean_buffer();

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    http_response_code($status);

    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    echo $json === false
        ? '{"success":false,"message":"No se pudo generar la respuesta JSON."}'
        : $json;
    exit;
}

function mp_api_ok(array $payload = []): void
{
    mp_api_json(array_merge(['success' => true], $payload));
}

function mp_api_error(
    string $message,
    int $status = 422,
    array $extra = []
): void {
    mp_api_json(
        array_merge(
            [
                'success' => false,
                'message' => $message,
            ],
            $extra
        ),
        $status
    );
}

function mp_api_input(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return $_POST;
    }

    $decoded = json_decode($raw, true);

    if (!is_array($decoded)) {
        mp_api_error(
            'El cuerpo enviado no contiene JSON válido.',
            400,
            ['code' => 'invalid_request_json']
        );
    }

    return $decoded;
}

function mp_api_exception(Throwable $error): void
{
    error_log(
        '[Mercado Pago Point] '
        . get_class($error)
        . ': '
        . $error->getMessage()
        . ' en '
        . $error->getFile()
        . ':'
        . $error->getLine()
    );

    $properties = get_object_vars($error);
    $httpCode = (int) ($properties['mp_http_code'] ?? 0);
    $mpResponse = isset($properties['mp_response'])
        && is_array($properties['mp_response'])
            ? $properties['mp_response']
            : [];

    $errors = $mpResponse['errors'] ?? [];
    $firstCode = '';

    if (
        is_array($errors)
        && isset($errors[0])
        && is_array($errors[0])
    ) {
        $firstCode = trim((string) ($errors[0]['code'] ?? ''));
    }

    if (
        $httpCode === 409
        && $firstCode === 'already_queued_order_on_terminal'
    ) {
        mp_api_error(
            'La terminal ya tiene un cobro pendiente. Termínalo o cancélalo directamente en la Point.',
            409,
            [
                'code' => $firstCode,
                'requires_terminal' => true,
            ]
        );
    }

    if ($httpCode >= 400 && $httpCode <= 599) {
        mp_api_error(
            $error->getMessage(),
            $httpCode,
            ['code' => $firstCode]
        );
    }

    mp_api_error(
        $error->getMessage() !== ''
            ? $error->getMessage()
            : 'Error interno del endpoint Point.',
        500,
        ['code' => 'point_endpoint_exception']
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
        if (!empty($GLOBALS['mp_api_response_sent'])) {
            return;
        }

        $last = error_get_last();
        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        ];

        if (
            !is_array($last)
            || !in_array((int) ($last['type'] ?? 0), $fatalTypes, true)
        ) {
            return;
        }

        error_log(
            '[Mercado Pago Point][fatal] '
            . (string) ($last['message'] ?? 'Error fatal')
        );

        mp_api_clean_buffer();

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }

        http_response_code(500);
        echo json_encode(
            [
                'success' => false,
                'message' => 'Ocurrió un error fatal en el endpoint Point.',
                'code' => 'php_fatal_error',
            ],
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );
    }
);

/** @var mysqli|null $conn */
$conn = null;
/** @var int $usuarioId */
$usuarioId = 0;
/** @var int $sucursalId */
$sucursalId = 0;
/** @var string $terminalId */
$terminalId = '';
/** @var array<string,mixed> $terminalConfig */
$terminalConfig = [];

try {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

    require_once __DIR__ . '/../../config/database.php';
    require_once __DIR__ . '/../../includes/mercadopago_client.php';
    require_once __DIR__ . '/../../includes/mercadopago_service.php';

    if (empty($_SESSION['user_id'])) {
        mp_api_error(
            'Tu sesión terminó. Inicia sesión nuevamente.',
            401,
            ['code' => 'session_expired']
        );
    }

    $rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));

    if (!in_array(
        $rol,
        ['super_administrador', 'admin', 'administrador', 'recepcionista'],
        true
    )) {
        mp_api_error('No tienes permiso para realizar cobros.', 403);
    }

    $usuarioId = (int) $_SESSION['user_id'];
    $sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);

    if ($sucursalId <= 0) {
        mp_api_error(
            'Selecciona una sucursal operativa antes de cobrar.',
            409,
            ['code' => 'branch_required']
        );
    }

    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn instanceof mysqli) {
        throw new RuntimeException(
            'No fue posible conectar con la base de datos.'
        );
    }

    $conn->set_charset('utf8mb4');
    mp_set_runtime_database($conn);

    $stmtSucursal = $conn->prepare(
        "SELECT id, nombre, estado
         FROM sucursales
         WHERE id = ?
         LIMIT 1"
    );
    $stmtSucursal->bind_param('i', $sucursalId);
    $stmtSucursal->execute();
    $sucursalApi = $stmtSucursal->get_result()->fetch_assoc();
    $stmtSucursal->close();

    if (
        !is_array($sucursalApi)
        || (string) ($sucursalApi['estado'] ?? '') !== 'activa'
    ) {
        mp_api_error(
            'La sucursal seleccionada está inactiva.',
            409,
            ['code' => 'inactive_branch']
        );
    }

    $terminalConfig = mp_terminal_configurar_sucursal(
        $conn,
        $sucursalId
    );
    $terminalId = trim((string) (
        $terminalConfig['terminal_id'] ?? ''
    ));

    if ($terminalId === '') {
        mp_api_error(
            'La sucursal no tiene una terminal Point validada y activa.',
            409,
            ['code' => 'terminal_not_configured']
        );
    }
} catch (Throwable $error) {
    mp_api_exception($error);
}
