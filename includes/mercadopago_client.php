<?php
// Archivo: includes/mercadopago_client.php
// Cliente HTTP de Mercado Pago con credenciales dinámicas por terminal.

declare(strict_types=1);

require_once __DIR__ . '/../config/mercadopago_config.php';
require_once __DIR__ . '/mercadopago_terminal_config.php';

class MpHttpException extends RuntimeException
{
    /** @var array<string,mixed> */
    public $mp_response = [];

    /** @var int */
    public $mp_http_code = 0;
}

/** @var array<string,mixed> */
$GLOBALS['mp_runtime_config'] = [];

/** @var mysqli|null */
$GLOBALS['mp_runtime_database'] = null;

/**
 * @param array<string,mixed> $configuracion
 */
function mp_set_runtime_config(array $configuracion): void
{
    $GLOBALS['mp_runtime_config'] = $configuracion;
}

/**
 * @return array<string,mixed>
 */
function mp_get_runtime_config(): array
{
    $configuracion = $GLOBALS['mp_runtime_config'] ?? [];

    return is_array($configuracion) ? $configuracion : [];
}

function mp_set_runtime_database(mysqli $db): void
{
    $GLOBALS['mp_runtime_database'] = $db;
}

function mp_configurar_contexto_orden_local(string $orderId): void
{
    $orderId = trim($orderId);
    $db = $GLOBALS['mp_runtime_database'] ?? null;

    if ($orderId === '' || !$db instanceof mysqli) {
        return;
    }

    try {
        $stmt = $db->prepare(
            "SELECT sucursal_id, terminal_id
             FROM mercadopago_operaciones
             WHERE order_id = ?
             LIMIT 1"
        );

        if (!$stmt) {
            return;
        }

        $stmt->bind_param('s', $orderId);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (
            is_array($fila)
            && (int) ($fila['sucursal_id'] ?? 0) > 0
            && trim((string) ($fila['terminal_id'] ?? '')) !== ''
        ) {
            mp_terminal_configurar_operacion(
                $db,
                (int) $fila['sucursal_id'],
                (string) $fila['terminal_id']
            );
        }
    } catch (Throwable $error) {
        error_log(
            '[Mercado Pago contexto orden] '
            . $orderId
            . ': '
            . $error->getMessage()
        );
    }
}

function mp_runtime_value(string $clave, string $respaldo = ''): string
{
    $configuracion = mp_get_runtime_config();
    $valor = trim((string) ($configuracion[$clave] ?? ''));

    return $valor !== '' ? $valor : $respaldo;
}

function mp_runtime_access_token(?string $explicito = null): string
{
    $explicito = trim((string) $explicito);

    if ($explicito !== '') {
        return $explicito;
    }

    $respaldo = defined('MP_ACCESS_TOKEN')
        ? trim((string) MP_ACCESS_TOKEN)
        : '';

    return mp_runtime_value('access_token', $respaldo);
}

function mp_runtime_terminal_id(?string $explicito = null): string
{
    $explicito = trim((string) $explicito);

    if ($explicito !== '') {
        return $explicito;
    }

    $respaldo = defined('MP_TERMINAL_ID')
        ? trim((string) MP_TERMINAL_ID)
        : '';

    return mp_runtime_value('terminal_id', $respaldo);
}

function mp_assert_access_token(?string $accessToken = null): void
{
    $accessToken = mp_runtime_access_token($accessToken);

    if (
        $accessToken === ''
        || strpos($accessToken, 'REEMPLAZA_AQUI') !== false
    ) {
        throw new RuntimeException(
            'La terminal seleccionada no tiene un Access Token configurado.'
        );
    }
}

function mp_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf(
        '%s%s-%s-%s-%s-%s%s%s',
        str_split(bin2hex($data), 4)
    );
}

/**
 * @return array<string,mixed>
 */
function mp_request(
    string $method,
    string $endpoint,
    ?array $body = null,
    ?string $idempotencyKey = null,
    ?string $accessToken = null
): array {
    $accessToken = mp_runtime_access_token($accessToken);
    mp_assert_access_token($accessToken);

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . $accessToken,
    ];

    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init(
        'https://api.mercadopago.com' . $endpoint
    );

    if ($ch === false) {
        throw new RuntimeException(
            'No fue posible inicializar CURL para Mercado Pago.'
        );
    }

    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if ($body !== null) {
        $encoded = json_encode(
            $body,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            curl_close($ch);
            throw new RuntimeException(
                'No se pudo codificar la solicitud de Mercado Pago.'
            );
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo(
        $ch,
        CURLINFO_HTTP_CODE
    );
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException(
            'Error CURL Mercado Pago: ' . $curlError
        );
    }

    $json = json_decode($raw, true);

    if (!is_array($json)) {
        $json = ['raw_response' => $raw];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = (string) (
            $json['message']
            ?? $json['error']
            ?? 'Error desconocido'
        );

        if (
            !empty($json['errors'])
            && is_array($json['errors'])
        ) {
            $message .= ' | ' . json_encode(
                $json['errors'],
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
            );
        }

        $exception = new MpHttpException(
            'Mercado Pago HTTP '
            . $httpCode
            . ': '
            . $message
        );
        $exception->mp_response = $json;
        $exception->mp_http_code = $httpCode;

        throw $exception;
    }

    return $json;
}

/**
 * Compatible con las firmas usadas previamente.
 * Las cuotas no se envían en la order; se eligen en la terminal.
 *
 * @param mixed $tercero
 * @param mixed $cuarto
 * @param mixed $quinto
 * @param mixed $sexto
 * @return array<string,mixed>
 */
function mp_create_point_order(
    float $amount,
    string $paymentType,
    $tercero,
    $cuarto = null,
    $quinto = null,
    $sexto = null
): array {
    if (!in_array(
        $paymentType,
        ['debit_card', 'credit_card'],
        true
    )) {
        throw new InvalidArgumentException(
            'Tipo de tarjeta no válido.'
        );
    }

    if ($amount <= 0) {
        throw new InvalidArgumentException(
            'El monto debe ser mayor que cero.'
        );
    }

    if (is_int($tercero) || is_float($tercero)) {
        $externalReference = trim((string) $cuarto);
        $description = trim((string) $quinto);
        $terminalId = trim((string) $sexto);
    } else {
        $externalReference = trim((string) $tercero);
        $description = trim((string) $cuarto);
        $terminalId = trim((string) $quinto);
    }

    if ($externalReference === '') {
        throw new InvalidArgumentException(
            'Falta la referencia externa de la orden.'
        );
    }

    if ($description === '') {
        $description = 'Cobro en terminal Point';
    }

    $terminalId = mp_runtime_terminal_id($terminalId);

    if ($terminalId === '') {
        throw new RuntimeException(
            'La sucursal no tiene una terminal Point configurada.'
        );
    }

    $printOnTerminal = mp_runtime_value(
        'print_on_terminal',
        defined('MP_PRINT_ON_TERMINAL')
            ? (string) MP_PRINT_ON_TERMINAL
            : 'no_ticket'
    );
    $printOnTerminal = mp_terminal_validar_impresion(
        $printOnTerminal
    );

    $expiration = mp_runtime_value(
        'order_expiration',
        defined('MP_ORDER_EXPIRATION')
            ? (string) MP_ORDER_EXPIRATION
            : 'PT3M'
    );
    $expiration = mp_terminal_validar_expiracion($expiration);

    $body = [
        'type' => 'point',
        'external_reference' => substr(
            $externalReference,
            0,
            64
        ),
        'description' => substr($description, 0, 150),
        'expiration_time' => $expiration,
        'config' => [
            'point' => [
                'terminal_id' => $terminalId,
                'print_on_terminal' => $printOnTerminal,
            ],
            'payment_method' => [
                'default_type' => $paymentType,
            ],
        ],
        'transactions' => [
            'payments' => [
                [
                    'amount' => number_format(
                        $amount,
                        2,
                        '.',
                        ''
                    ),
                ],
            ],
        ],
    ];

    return mp_request(
        'POST',
        '/v1/orders',
        $body,
        mp_uuid_v4()
    );
}

/** @return array<string,mixed> */
function mp_get_order(string $orderId): array
{
    mp_configurar_contexto_orden_local($orderId);

    return mp_request(
        'GET',
        '/v1/orders/' . rawurlencode($orderId)
    );
}

/** @return array<string,mixed> */
function mp_cancel_order(string $orderId): array
{
    mp_configurar_contexto_orden_local($orderId);

    return mp_request(
        'POST',
        '/v1/orders/'
        . rawurlencode($orderId)
        . '/cancel',
        null,
        mp_uuid_v4()
    );
}

/**
 * @return array<string,mixed>
 */
function mp_refund_order(
    string $orderId,
    string $paymentId,
    ?float $amount = null
): array {
    mp_configurar_contexto_orden_local($orderId);

    $body = null;

    if ($amount !== null) {
        if ($amount <= 0) {
            throw new InvalidArgumentException(
                'El monto del reembolso debe ser mayor que cero.'
            );
        }

        $body = [
            'transactions' => [
                [
                    'id' => $paymentId,
                    'amount' => number_format(
                        $amount,
                        2,
                        '.',
                        ''
                    ),
                ],
            ],
        ];
    }

    $key = mp_uuid_v4();

    return [
        'response' => mp_request(
            'POST',
            '/v1/orders/'
            . rawurlencode($orderId)
            . '/refund',
            $body,
            $key
        ),
        'idempotency_key' => $key,
    ];
}

/**
 * @param array<string,mixed> $order
 * @return array<string,mixed>
 */
function mp_first_payment(array $order): array
{
    $payments = $order['transactions']['payments'] ?? [];

    return is_array($payments)
        && isset($payments[0])
        && is_array($payments[0])
            ? $payments[0]
            : [];
}

function mp_money(float $amount): string
{
    return number_format($amount, 2, '.', '');
}
