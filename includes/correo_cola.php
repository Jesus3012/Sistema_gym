<?php

declare(strict_types=1);

require_once __DIR__ . '/correo_sistema.php';

/**
 * Cola persistente para correo.
 *
 * El alta únicamente inserta el trabajo y conserva un token temporal en la
 * sesión. El sistema intenta un PHP CLI separado y conserva un respaldo en
 * el navegador, por lo que la pantalla nunca espera la conexión SMTP.
 */

function correo_cola_tabla_existe(mysqli $conn): bool
{
    $resultado = $conn->query("SHOW TABLES LIKE 'cola_correos_sistema'");
    return $resultado instanceof mysqli_result && $resultado->num_rows > 0;
}

function correo_cola_asegurar_tabla(mysqli $conn): void
{
    $conn->query(
        "CREATE TABLE IF NOT EXISTS cola_correos_sistema (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            tipo varchar(60) NOT NULL,
            payload_json longtext NOT NULL,
            token_hash char(64) NOT NULL,
            estado enum('pendiente','procesando','enviado','fallido') NOT NULL DEFAULT 'pendiente',
            intentos tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
            ultimo_error text DEFAULT NULL,
            disponible_desde datetime NOT NULL DEFAULT current_timestamp(),
            ultimo_intento datetime DEFAULT NULL,
            enviado_en datetime DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT current_timestamp(),
            updated_at timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
            PRIMARY KEY (id),
            UNIQUE KEY uq_cola_correo_token (token_hash),
            KEY idx_cola_correo_estado_disponible (estado, disponible_desde),
            KEY idx_cola_correo_tipo (tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

/** @param array<string,mixed> $payload
 *  @return array{id:int,token:string}
 */
function correo_cola_encolar(mysqli $conn, string $tipo, array $payload): array
{
    correo_cola_asegurar_tabla($conn);

    $permitidos = [
        'inscripcion_bienvenida',
        'inscripcion_renovacion',
        'expediente_invitacion',
        'expediente_completado',
    ];

    if (!in_array($tipo, $permitidos, true)) {
        throw new InvalidArgumentException('Tipo de correo no permitido.');
    }

    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    if (!is_string($json)) {
        throw new RuntimeException('No fue posible preparar el correo.');
    }

    $stmt = $conn->prepare(
        "INSERT INTO cola_correos_sistema
            (tipo, payload_json, token_hash, estado, intentos, disponible_desde)
         VALUES (?, ?, ?, 'pendiente', 0, NOW())"
    );
    $stmt->bind_param('sss', $tipo, $json, $hash);
    $stmt->execute();
    $id = (int) $conn->insert_id;
    $stmt->close();

    return ['id' => $id, 'token' => $token];
}


function correo_cola_funcion_habilitada(string $nombre): bool
{
    if (!function_exists($nombre)) {
        return false;
    }

    $deshabilitadas = array_filter(array_map(
        'trim',
        explode(',', (string) ini_get('disable_functions'))
    ));

    return !in_array($nombre, $deshabilitadas, true);
}

function correo_cola_php_cli(): string
{
    $esWindows = DIRECTORY_SEPARATOR === '\\';
    $nombre = $esWindows ? 'php.exe' : 'php';
    $candidatos = [];

    if (defined('PHP_BINDIR')) {
        $candidatos[] = rtrim((string) PHP_BINDIR, '/\\')
            . DIRECTORY_SEPARATOR . $nombre;
    }

    if (defined('PHP_BINARY') && PHP_BINARY !== '') {
        $binarioActual = (string) PHP_BINARY;
        if (basename($binarioActual) === $nombre) {
            $candidatos[] = $binarioActual;
        }
        $candidatos[] = dirname($binarioActual)
            . DIRECTORY_SEPARATOR . $nombre;
    }

    if ($esWindows) {
        $candidatos[] = 'C:\\xampp\\php\\php.exe';
    } else {
        $candidatos[] = '/usr/bin/php';
        $candidatos[] = '/usr/local/bin/php';
    }

    foreach (array_unique($candidatos) as $candidato) {
        if (is_file($candidato) && ($esWindows || is_executable($candidato))) {
            return $candidato;
        }
    }

    return '';
}

/**
 * Intenta lanzar un PHP CLI separado. Es la vía principal en XAMPP y evita
 * depender de que el navegador mantenga abierta una petición SMTP larga.
 */
function correo_cola_lanzar_worker_cli(string $token): bool
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return false;
    }

    $php = correo_cola_php_cli();
    $worker = dirname(__DIR__)
        . DIRECTORY_SEPARATOR . 'api'
        . DIRECTORY_SEPARATOR . 'correo'
        . DIRECTORY_SEPARATOR . 'worker_token_cli.php';

    if ($php === '' || !is_file($worker)) {
        return false;
    }

    $phpArg = escapeshellarg($php);
    $workerArg = escapeshellarg($worker);
    $tokenArg = escapeshellarg($token);
    $esWindows = DIRECTORY_SEPARATOR === '\\';

    if ($esWindows) {
        $comando = 'cmd.exe /C start "" /B '
            . $phpArg . ' ' . $workerArg . ' ' . $tokenArg
            . ' >NUL 2>&1';

        if (correo_cola_funcion_habilitada('popen')) {
            $proceso = @popen($comando, 'r');
            if (is_resource($proceso)) {
                @pclose($proceso);
                return true;
            }
        }

        if (correo_cola_funcion_habilitada('proc_open')) {
            $descriptores = [
                0 => ['pipe', 'r'],
                1 => ['file', 'NUL', 'a'],
                2 => ['file', 'NUL', 'a'],
            ];
            $pipes = [];
            $proceso = @proc_open($comando, $descriptores, $pipes);
            if (is_resource($proceso)) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
                @proc_close($proceso);
                return true;
            }
        }

        return false;
    }

    $comando = $phpArg . ' ' . $workerArg . ' ' . $tokenArg
        . ' > /dev/null 2>&1 &';

    if (correo_cola_funcion_habilitada('exec')) {
        $salida = [];
        $codigo = 1;
        @exec($comando, $salida, $codigo);
        if ($codigo === 0) {
            return true;
        }
    }

    if (correo_cola_funcion_habilitada('popen')) {
        $proceso = @popen($comando, 'r');
        if (is_resource($proceso)) {
            @pclose($proceso);
            return true;
        }
    }

    return false;
}

/**
 * Registra el token para lanzarlo después de la redirección.
 * No abre sockets, no llama a Apache y no conecta con SMTP.
 */
function correo_cola_disparar_async(string $token): bool
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return false;
    }

    // En XAMPP intenta primero un proceso PHP CLI totalmente separado.
    // La llamada tarda milisegundos y el POST no espera a Gmail.
    $lanzadoCli = correo_cola_lanzar_worker_cli($token);

    // Conserva el token en la sesión como respaldo. Después de redirigir,
    // JavaScript verificará el estado y reintentará mediante fetch si el CLI
    // estuviera deshabilitado por el servidor.
    if (session_status() === PHP_SESSION_ACTIVE) {
        $tokens = $_SESSION['correo_tokens_async'] ?? [];
        if (!is_array($tokens)) {
            $tokens = [];
        }

        $tokens[] = $token;
        $tokens = array_values(array_unique(array_filter(
            $tokens,
            static function ($valor): bool {
                return is_string($valor)
                    && preg_match('/^[a-f0-9]{64}$/', $valor) === 1;
            }
        )));

        $_SESSION['correo_tokens_async'] = array_slice($tokens, -8);
        return true;
    }

    return $lanzadoCli;
}

/** @return array<int,string> */
function correo_cola_extraer_tokens_async(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }

    $tokens = $_SESSION['correo_tokens_async'] ?? [];
    unset($_SESSION['correo_tokens_async']);

    if (!is_array($tokens)) {
        return [];
    }

    return array_values(array_unique(array_filter(
        $tokens,
        static function ($valor): bool {
            return is_string($valor)
                && preg_match('/^[a-f0-9]{64}$/', $valor) === 1;
        }
    )));
}

function correo_cola_reactivar_atascados(mysqli $conn, int $minutos = 2): int
{
    $minutos = max(1, min(30, $minutos));
    $sql = "UPDATE cola_correos_sistema
            SET estado = 'pendiente',
                ultimo_error = CONCAT(
                    COALESCE(ultimo_error, ''),
                    CASE WHEN COALESCE(ultimo_error, '') = '' THEN '' ELSE '\\n' END,
                    'Trabajo reactivado automáticamente.'
                ),
                disponible_desde = NOW()
            WHERE estado = 'procesando'
              AND enviado_en IS NULL
              AND ultimo_intento IS NOT NULL
              AND ultimo_intento < DATE_SUB(NOW(), INTERVAL {$minutos} MINUTE)";

    $conn->query($sql);
    return max(0, (int) $conn->affected_rows);
}

/** @return array<string,mixed> */
function correo_cola_procesar_fila(mysqli $conn, array $fila): array
{
    $id = (int) ($fila['id'] ?? 0);
    $tipo = (string) ($fila['tipo'] ?? '');
    $payload = json_decode((string) ($fila['payload_json'] ?? ''), true);

    if (!is_array($payload)) {
        throw new RuntimeException('El contenido del correo no es válido.');
    }

    $enviado = false;

    if ($tipo === 'inscripcion_bienvenida') {
        $enviado = correo_sistema_enviar_inscripcion($conn, $payload);
    } elseif ($tipo === 'inscripcion_renovacion') {
        $enviado = correo_sistema_enviar_renovacion($conn, $payload);
    } elseif ($tipo === 'expediente_invitacion') {
        require_once __DIR__ . '/correo_expediente_salud.php';
        $enviado = enviarCorreoInvitacionExpedienteSalud(
            $conn,
            (string) ($payload['email'] ?? ''),
            (string) ($payload['nombre'] ?? ''),
            (string) ($payload['url'] ?? ''),
            (string) ($payload['vence_en'] ?? ''),
            (array) ($payload['datos_inscripcion'] ?? []),
            (int) ($payload['invitacion_id'] ?? 0)
        );

        if ($enviado && !empty($payload['invitacion_id'])) {
            require_once __DIR__ . '/expediente_salud_invitaciones.php';
            expediente_marcar_invitacion_enviada(
                $conn,
                (int) $payload['invitacion_id']
            );
        }
    } elseif ($tipo === 'expediente_completado') {
        require_once __DIR__ . '/correo_expediente_salud.php';
        $enviado = enviarCorreoExpedienteSaludCompletado(
            $conn,
            (int) ($payload['expediente_id'] ?? 0),
            (string) ($payload['email'] ?? ''),
            (string) ($payload['nombre'] ?? '')
        );
    }

    $error = correo_sistema_ultimo_error();
    if ($error === '' && function_exists('expediente_correo_ultimo_error')) {
        $error = expediente_correo_ultimo_error();
    }
    if (!$enviado && $error === '') {
        $error = 'PHPMailer no confirmó el envío.';
    }

    return [
        'id' => $id,
        'tipo' => $tipo,
        'enviado' => $enviado,
        'error' => $error,
    ];
}

/** @return array<string,mixed> */
function correo_cola_procesar_id(mysqli $conn, int $id): array
{
    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "SELECT *
             FROM cola_correos_sistema
             WHERE id = ?
               AND estado IN ('pendiente','fallido')
               AND disponible_desde <= NOW()
               AND intentos < 3
             LIMIT 1
             FOR UPDATE"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $fila = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$fila) {
            $conn->rollback();
            return [
                'id' => $id,
                'omitido' => true,
                'mensaje' => 'El correo ya fue procesado o todavía no está disponible.',
            ];
        }

        $stmt = $conn->prepare(
            "UPDATE cola_correos_sistema
             SET estado = 'procesando',
                 intentos = intentos + 1,
                 ultimo_intento = NOW()
             WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    try {
        $resultado = correo_cola_procesar_fila($conn, $fila);
    } catch (Throwable $e) {
        $resultado = [
            'id' => $id,
            'tipo' => (string) ($fila['tipo'] ?? ''),
            'enviado' => false,
            'error' => $e->getMessage(),
        ];
    }

    if (!empty($resultado['enviado'])) {
        $stmt = $conn->prepare(
            "UPDATE cola_correos_sistema
             SET estado = 'enviado',
                 enviado_en = NOW(),
                 ultimo_error = NULL
             WHERE id = ?"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        return $resultado;
    }

    $intentos = (int) ($fila['intentos'] ?? 0) + 1;
    $estado = $intentos >= 3 ? 'fallido' : 'pendiente';
    $minutos = $intentos <= 1 ? 1 : 5;
    $errorCompleto = (string) ($resultado['error'] ?? 'Error desconocido');
    $error = function_exists('mb_substr')
        ? mb_substr($errorCompleto, 0, 2000, 'UTF-8')
        : substr($errorCompleto, 0, 2000);

    $stmt = $conn->prepare(
        "UPDATE cola_correos_sistema
         SET estado = ?,
             ultimo_error = ?,
             disponible_desde = DATE_ADD(NOW(), INTERVAL ? MINUTE)
         WHERE id = ?"
    );
    $stmt->bind_param('ssii', $estado, $error, $minutos, $id);
    $stmt->execute();
    $stmt->close();

    return $resultado;
}


/** @return array<string,mixed> */
function correo_cola_estado_token(mysqli $conn, string $token): array
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        throw new InvalidArgumentException('Token de correo no válido.');
    }

    correo_cola_asegurar_tabla($conn);
    $hash = hash('sha256', $token);

    $stmt = $conn->prepare(
        "SELECT id, tipo, estado, intentos, ultimo_error,
                disponible_desde, ultimo_intento, enviado_en, created_at
         FROM cola_correos_sistema
         WHERE token_hash = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$fila) {
        throw new RuntimeException('El trabajo de correo no existe.');
    }

    return [
        'id' => (int) $fila['id'],
        'tipo' => (string) $fila['tipo'],
        'estado' => (string) $fila['estado'],
        'intentos' => (int) $fila['intentos'],
        'ultimo_error' => (string) ($fila['ultimo_error'] ?? ''),
        'disponible_desde' => $fila['disponible_desde'],
        'ultimo_intento' => $fila['ultimo_intento'],
        'enviado_en' => $fila['enviado_en'],
        'created_at' => $fila['created_at'],
    ];
}

/** @return array<string,mixed> */
function correo_cola_procesar_token(mysqli $conn, string $token): array
{
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        throw new InvalidArgumentException('Token de correo no válido.');
    }

    correo_cola_asegurar_tabla($conn);
    correo_cola_reactivar_atascados($conn, 2);

    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT id
         FROM cola_correos_sistema
         WHERE token_hash = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$fila) {
        throw new RuntimeException('El trabajo de correo no existe.');
    }

    return correo_cola_procesar_id($conn, (int) $fila['id']);
}

/** @return array<int,array<string,mixed>> */
function correo_cola_procesar_pendientes(mysqli $conn, int $limite = 1): array
{
    $limite = max(1, min(2, $limite));
    correo_cola_asegurar_tabla($conn);
    correo_cola_reactivar_atascados($conn, 2);

    $ids = [];
    $resultado = $conn->query(
        "SELECT id
         FROM cola_correos_sistema
         WHERE estado IN ('pendiente','fallido')
           AND disponible_desde <= NOW()
           AND intentos < 3
         ORDER BY id ASC
         LIMIT {$limite}"
    );

    while ($resultado && $fila = $resultado->fetch_assoc()) {
        $ids[] = (int) $fila['id'];
    }

    $salida = [];
    foreach ($ids as $id) {
        $salida[] = correo_cola_procesar_id($conn, $id);
    }

    return $salida;
}
