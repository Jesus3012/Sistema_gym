<?php
// Archivo: includes/mercadopago_terminal_config.php
// Credenciales y configuración Point por sucursal/terminal.

declare(strict_types=1);

require_once __DIR__ . '/../config/mercadopago_config.php';

function mp_terminal_clave_cifrado(): string
{
    $fuente = defined('MP_CREDENTIALS_KEY')
        ? trim((string) MP_CREDENTIALS_KEY)
        : '';

    if ($fuente === '') {
        throw new RuntimeException(
            'Configura MP_CREDENTIALS_KEY antes de guardar credenciales Point.'
        );
    }

    if (strpos($fuente, 'base64:') === 0) {
        $decodificada = base64_decode(substr($fuente, 7), true);

        if ($decodificada === false || strlen($decodificada) !== 32) {
            throw new RuntimeException(
                'MP_CREDENTIALS_KEY debe contener exactamente 32 bytes en Base64.'
            );
        }

        return $decodificada;
    }

    return hash('sha256', $fuente, true);
}

function mp_terminal_cifrar_token(string $token): string
{
    $token = trim($token);

    if ($token === '') {
        throw new InvalidArgumentException(
            'El Access Token de Mercado Pago está vacío.'
        );
    }

    if (!function_exists('openssl_encrypt')) {
        throw new RuntimeException(
            'La extensión OpenSSL de PHP es necesaria para cifrar el Access Token.'
        );
    }

    $iv = random_bytes(12);
    $tag = '';
    $cifrado = openssl_encrypt(
        $token,
        'aes-256-gcm',
        mp_terminal_clave_cifrado(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag,
        '',
        16
    );

    if ($cifrado === false || strlen($tag) !== 16) {
        throw new RuntimeException(
            'No fue posible cifrar el Access Token de Mercado Pago.'
        );
    }

    return base64_encode('MP1' . $iv . $tag . $cifrado);
}

function mp_terminal_descifrar_token(string $valor): string
{
    $valor = trim($valor);

    if ($valor === '') {
        return '';
    }

    $binario = base64_decode($valor, true);

    if (
        $binario === false
        || strlen($binario) < 32
        || substr($binario, 0, 3) !== 'MP1'
    ) {
        throw new RuntimeException(
            'La credencial Point guardada tiene un formato inválido.'
        );
    }

    $iv = substr($binario, 3, 12);
    $tag = substr($binario, 15, 16);
    $cifrado = substr($binario, 31);

    $token = openssl_decrypt(
        $cifrado,
        'aes-256-gcm',
        mp_terminal_clave_cifrado(),
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );

    if ($token === false || trim($token) === '') {
        throw new RuntimeException(
            'No fue posible descifrar el Access Token. Verifica MP_CREDENTIALS_KEY.'
        );
    }

    return trim($token);
}

function mp_terminal_validar_token_formato(string $token): string
{
    $token = trim($token);

    if ($token === '' || strlen($token) < 30 || strlen($token) > 500) {
        throw new InvalidArgumentException(
            'El Access Token de Mercado Pago no tiene una longitud válida.'
        );
    }

    if (preg_match('/\s/', $token)) {
        throw new InvalidArgumentException(
            'El Access Token no puede contener espacios.'
        );
    }

    return $token;
}

function mp_terminal_validar_id(string $terminalId): string
{
    $terminalId = trim($terminalId);

    if (
        $terminalId === ''
        || strlen($terminalId) > 120
        || !preg_match('/^[A-Za-z0-9_-]+$/', $terminalId)
    ) {
        throw new InvalidArgumentException(
            'El Terminal ID contiene caracteres no válidos.'
        );
    }

    return $terminalId;
}

function mp_terminal_validar_impresion(string $valor): string
{
    $valor = trim($valor);
    $permitidos = ['no_ticket', 'seller_ticket'];

    if (!in_array($valor, $permitidos, true)) {
        return 'no_ticket';
    }

    return $valor;
}

function mp_terminal_validar_expiracion(string $valor): string
{
    $valor = strtoupper(trim($valor));

    if (!preg_match('/^PT([1-9]|[1-5][0-9]|60)M$/', $valor)) {
        throw new InvalidArgumentException(
            'La expiración debe expresarse entre PT1M y PT60M.'
        );
    }

    return $valor;
}

function mp_terminal_validar_costo_cuotas(string $valor): string
{
    $valor = strtolower(trim($valor));

    return in_array($valor, ['terminal', 'seller', 'buyer'], true)
        ? $valor
        : 'terminal';
}

function mp_terminal_token_respaldo(): string
{
    return defined('MP_ACCESS_TOKEN')
        ? trim((string) MP_ACCESS_TOKEN)
        : '';
}

/**
 * @return array<string,mixed>
 */
function mp_terminal_configuracion_desde_fila(array $fila): array
{
    $tokenCifrado = trim((string) (
        $fila['access_token_cifrado'] ?? ''
    ));

    $accessToken = $tokenCifrado !== ''
        ? mp_terminal_descifrar_token($tokenCifrado)
        : mp_terminal_token_respaldo();

    if ($accessToken === '') {
        throw new RuntimeException(
            'La terminal no tiene un Access Token configurado.'
        );
    }

    return [
        'registro_id' => (int) ($fila['id'] ?? 0),
        'sucursal_id' => (int) ($fila['sucursal_id'] ?? 0),
        'terminal_id' => mp_terminal_validar_id(
            (string) ($fila['terminal_id'] ?? '')
        ),
        'nombre' => trim((string) ($fila['nombre'] ?? 'Terminal Point')),
        'access_token' => $accessToken,
        'print_on_terminal' => mp_terminal_validar_impresion(
            (string) ($fila['print_on_terminal'] ?? 'no_ticket')
        ),
        'order_expiration' => mp_terminal_validar_expiracion(
            (string) ($fila['order_expiration'] ?? 'PT3M')
        ),
        'installments_cost' => mp_terminal_validar_costo_cuotas(
            (string) ($fila['installments_cost'] ?? 'terminal')
        ),
    ];
}

/**
 * @return array<string,mixed>
 */
function mp_terminal_obtener_configuracion(
    mysqli $db,
    int $sucursalId,
    ?string $terminalId = null,
    bool $requiereActiva = true
): array {
    if ($sucursalId <= 0) {
        throw new InvalidArgumentException(
            'La sucursal para Mercado Pago no es válida.'
        );
    }

    if ($terminalId !== null && trim($terminalId) !== '') {
        $terminalId = mp_terminal_validar_id($terminalId);
        $stmt = $db->prepare(
            "SELECT
                id,
                sucursal_id,
                terminal_id,
                nombre,
                access_token_cifrado,
                print_on_terminal,
                order_expiration,
                installments_cost,
                predeterminada,
                activo
             FROM mercadopago_terminales
             WHERE sucursal_id = ?
               AND terminal_id = ?
               AND (
                    ? = 0
                    OR (
                        activo = 1
                        AND validacion_estado = 'valida'
                    )
               )
             LIMIT 1"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible preparar la configuración de la terminal.'
            );
        }

        $requiere = $requiereActiva ? 1 : 0;
        $stmt->bind_param('isi', $sucursalId, $terminalId, $requiere);
    } else {
        $stmt = $db->prepare(
            "SELECT
                id,
                sucursal_id,
                terminal_id,
                nombre,
                access_token_cifrado,
                print_on_terminal,
                order_expiration,
                installments_cost,
                predeterminada,
                activo
             FROM mercadopago_terminales
             WHERE sucursal_id = ?
               AND activo = 1
               AND validacion_estado = 'valida'
             ORDER BY predeterminada DESC, id ASC
             LIMIT 1"
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible preparar la configuración Point de la sucursal.'
            );
        }

        $stmt->bind_param('i', $sucursalId);
    }

    $stmt->execute();
    $resultado = $stmt->get_result();
    $fila = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    if (!is_array($fila)) {
        throw new RuntimeException(
            'La sucursal no tiene una terminal Point activa.'
        );
    }

    return mp_terminal_configuracion_desde_fila($fila);
}

/**
 * Carga en memoria la terminal que usarán mp_request y mp_create_point_order.
 *
 * @return array<string,mixed>
 */
function mp_terminal_configurar_sucursal(
    mysqli $db,
    int $sucursalId
): array {
    $configuracion = mp_terminal_obtener_configuracion($db, $sucursalId);

    if (!function_exists('mp_set_runtime_config')) {
        throw new RuntimeException(
            'mercadopago_client.php no cargó el contexto dinámico Point.'
        );
    }

    mp_set_runtime_config($configuracion);

    if (function_exists('mp_set_runtime_database')) {
        mp_set_runtime_database($db);
    }

    return $configuracion;
}

/**
 * Configura la misma credencial con la que se creó una operación anterior.
 * Se usa especialmente para consultas, cancelaciones y reembolsos.
 *
 * @return array<string,mixed>
 */
function mp_terminal_configurar_operacion(
    mysqli $db,
    int $sucursalId,
    string $terminalId
): array {
    $configuracion = mp_terminal_obtener_configuracion(
        $db,
        $sucursalId,
        $terminalId,
        false
    );

    if (!function_exists('mp_set_runtime_config')) {
        throw new RuntimeException(
            'mercadopago_client.php no cargó el contexto dinámico Point.'
        );
    }

    mp_set_runtime_config($configuracion);

    if (function_exists('mp_set_runtime_database')) {
        mp_set_runtime_database($db);
    }

    return $configuracion;
}

/**
 * Consulta las terminales activas de la cuenta y verifica pertenencia.
 * No crea cargos ni orders.
 *
 * @return array<string,mixed>
 */
function mp_terminal_probar_credenciales(
    string $accessToken,
    string $terminalId
): array {
    $accessToken = mp_terminal_validar_token_formato($accessToken);
    $terminalId = mp_terminal_validar_id($terminalId);

    if (!function_exists('curl_init')) {
        throw new RuntimeException(
            'La extensión CURL de PHP es necesaria para validar la terminal.'
        );
    }

    $offset = 0;
    $limit = 50;
    $maximo = 500;

    while ($offset < $maximo) {
        $url = 'https://api.mercadopago.com/terminals/v1/list'
            . '?limit=' . $limit
            . '&offset=' . $offset;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 35,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        $raw = curl_exec($ch);
        $errorCurl = curl_error($ch);
        $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException(
                'No fue posible conectar con Mercado Pago: ' . $errorCurl
            );
        }

        $json = json_decode($raw, true);

        if (!is_array($json)) {
            throw new RuntimeException(
                'Mercado Pago devolvió una respuesta no válida.'
            );
        }

        if ($codigo < 200 || $codigo >= 300) {
            $mensaje = trim((string) (
                $json['message']
                ?? $json['error']
                ?? 'No fue posible validar las credenciales.'
            ));

            throw new RuntimeException(
                'Mercado Pago HTTP ' . $codigo . ': ' . $mensaje
            );
        }

        $terminales = $json['data']['terminals'] ?? [];

        if (!is_array($terminales)) {
            $terminales = [];
        }

        foreach ($terminales as $terminal) {
            if (
                is_array($terminal)
                && trim((string) ($terminal['id'] ?? '')) === $terminalId
            ) {
                return [
                    'ok' => true,
                    'terminal' => $terminal,
                    'total' => (int) (
                        $json['paging']['total'] ?? count($terminales)
                    ),
                    'mensaje' =>
                        'La terminal pertenece a la cuenta de Mercado Pago configurada.',
                ];
            }
        }

        $total = (int) ($json['paging']['total'] ?? count($terminales));
        $offset += $limit;

        if ($offset >= $total || $terminales === []) {
            break;
        }
    }

    throw new RuntimeException(
        'El Terminal ID no aparece entre las terminales activas de esta cuenta de Mercado Pago.'
    );
}
