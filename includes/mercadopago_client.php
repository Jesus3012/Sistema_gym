<?php

require_once __DIR__ . '/../config/mercadopago_config.php';

function mp_assert_configured(): void
{
    if (
        MP_ACCESS_TOKEN === '' ||
        strpos(MP_ACCESS_TOKEN, 'REEMPLAZA_AQUI') !== false
    ) {
        throw new RuntimeException('Configura MP_ACCESS_TOKEN antes de cobrar.');
    }

    if (
        MP_TERMINAL_ID === '' ||
        strpos(MP_TERMINAL_ID, 'REEMPLAZA_AQUI') !== false
    ) {
        throw new RuntimeException('Configura MP_TERMINAL_ID antes de cobrar.');
    }
}

function mp_uuid_v4(): string
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * @return array<string,mixed>
 */
function mp_request(
    string $method,
    string $endpoint,
    ?array $body = null,
    ?string $idempotencyKey = null
): array {
    mp_assert_configured();

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer ' . MP_ACCESS_TOKEN,
    ];

    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init('https://api.mercadopago.com' . $endpoint);
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
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if ($encoded === false) {
            curl_close($ch);
            throw new RuntimeException('No se pudo codificar la solicitud de Mercado Pago.');
        }

        curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($raw === false) {
        throw new RuntimeException('Error CURL Mercado Pago: ' . $curlError);
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $json = ['raw_response' => $raw];
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        $message = $json['message'] ?? $json['error'] ?? 'Error desconocido';

        if (!empty($json['errors']) && is_array($json['errors'])) {
            $message .= ' | ' . json_encode(
                $json['errors'],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        $exception = new RuntimeException(
            'Mercado Pago HTTP ' . $httpCode . ': ' . $message
        );
        $exception->mp_response = $json;
        $exception->mp_http_code = $httpCode;
        throw $exception;
    }

    return $json;
}

/**
 * @return array<string,mixed>
 */
function mp_create_point_order(
    float $amount,
    string $paymentType,
    string $externalReference,
    string $description
): array {
    if (!in_array($paymentType, ['debit_card', 'credit_card'], true)) {
        throw new InvalidArgumentException('Tipo de tarjeta no válido.');
    }

    if ($amount <= 0) {
        throw new InvalidArgumentException('El monto debe ser mayor que cero.');
    }

    $body = [
        'type' => 'point',
        'external_reference' => substr($externalReference, 0, 64),
        'description' => substr($description, 0, 160),
        'expiration_time' => MP_ORDER_EXPIRATION,
        'config' => [
            'point' => [
                'terminal_id' => MP_TERMINAL_ID,
                'print_on_terminal' => MP_PRINT_ON_TERMINAL,
            ],
            'payment_method' => [
                'default_type' => $paymentType,
            ],
        ],
        'transactions' => [
            'payments' => [
                [
                    'amount' => number_format($amount, 2, '.', ''),
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
    return mp_request('GET', '/v1/orders/' . rawurlencode($orderId));
}

/** @return array<string,mixed> */
function mp_cancel_order(string $orderId): array
{
    return mp_request(
        'POST',
        '/v1/orders/' . rawurlencode($orderId) . '/cancel',
        null,
        mp_uuid_v4()
    );
}

/**
 * @return array{response: array<string,mixed>, idempotency_key: string}
 */
function mp_refund_order(
    string $orderId,
    string $paymentId,
    ?float $amount = null
): array {
    $body = null;

    if ($amount !== null) {
        if ($amount <= 0) {
            throw new InvalidArgumentException('El monto del reembolso debe ser mayor que cero.');
        }

        $body = [
            'transactions' => [
                [
                    'id' => $paymentId,
                    'amount' => number_format($amount, 2, '.', ''),
                ],
            ],
        ];
    }

    $key = mp_uuid_v4();

    return [
        'response' => mp_request(
            'POST',
            '/v1/orders/' . rawurlencode($orderId) . '/refund',
            $body,
            $key
        ),
        'idempotency_key' => $key,
    ];
}

/** @return array<string,mixed> */
function mp_first_payment(array $order): array
{
    $payments = $order['transactions']['payments'] ?? [];
    return is_array($payments) && isset($payments[0]) && is_array($payments[0])
        ? $payments[0]
        : [];
}

function mp_money(float $amount): string
{
    return number_format($amount, 2, '.', '');
}