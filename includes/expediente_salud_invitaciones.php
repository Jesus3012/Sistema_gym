<?php

declare(strict_types=1);

/**
 * Invitaciones seguras para que el socio responda el cuestionario médico.
 * El token real solamente viaja en la URL; la base guarda SHA-256.
 */

function expediente_invitaciones_tabla_existe(mysqli $conn): bool
{
    $resultado = $conn->query("SHOW TABLES LIKE 'expedientes_salud_invitaciones'");

    return $resultado instanceof mysqli_result && $resultado->num_rows > 0;
}

function expediente_url_base_sistema(): string
{
    $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $https = $forwardedProto === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $protocolo = $https ? 'https' : 'http';
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    $host = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $host) ?: 'localhost';
    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    $directorio = rtrim(str_replace('\\', '/', dirname($script)), '/');

    if ($directorio === '.' || $directorio === '/') {
        $directorio = '';
    }

    return $protocolo . '://' . $host . $directorio;
}

function expediente_url_publica_invitacion(string $token): string
{
    return expediente_url_base_sistema()
        . '/cuestionario_salud.php?token=' . rawurlencode($token);
}

/**
 * @return array{token:string,url:string,id:int,vence_en:string}
 */
function expediente_crear_invitacion(
    mysqli $conn,
    int $clienteId,
    int $inscripcionId,
    int $sucursalId,
    int $creadoPor,
    string $email,
    string $modo,
    int $vigenciaDias = 7
): array {
    if (!expediente_invitaciones_tabla_existe($conn)) {
        throw new RuntimeException(
            'Falta ejecutar database/instalar_invitaciones_expediente_salud.sql.'
        );
    }

    if ($clienteId <= 0 || $sucursalId <= 0 || $creadoPor <= 0) {
        throw new InvalidArgumentException('Los datos de la invitación no son válidos.');
    }

    if (!in_array($modo, ['recepcion', 'correo'], true)) {
        $modo = 'recepcion';
    }

    $vigenciaDias = max(1, min(30, $vigenciaDias));
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $token);
    $venceEn = date('Y-m-d H:i:s', strtotime('+' . $vigenciaDias . ' days'));
    $email = trim($email);

    $conn->begin_transaction();

    try {
        $stmt = $conn->prepare(
            "UPDATE expedientes_salud_invitaciones
             SET estado = 'revocada'
             WHERE cliente_id = ?
               AND estado = 'pendiente'"
        );
        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $stmt->close();

        $stmt = $conn->prepare(
            "INSERT INTO expedientes_salud_invitaciones (
                cliente_id,
                inscripcion_id,
                sucursal_id,
                creado_por,
                token_hash,
                email_destino,
                modo,
                estado,
                vence_en
             ) VALUES (?, ?, ?, ?, ?, ?, ?, 'pendiente', ?)"
        );
        $stmt->bind_param(
            'iiiissss',
            $clienteId,
            $inscripcionId,
            $sucursalId,
            $creadoPor,
            $tokenHash,
            $email,
            $modo,
            $venceEn
        );
        $stmt->execute();
        $id = (int) $conn->insert_id;
        $stmt->close();

        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        throw $e;
    }

    return [
        'token' => $token,
        'url' => expediente_url_publica_invitacion($token),
        'id' => $id,
        'vence_en' => $venceEn,
    ];
}

function expediente_marcar_invitacion_enviada(mysqli $conn, int $invitacionId): void
{
    $stmt = $conn->prepare(
        "UPDATE expedientes_salud_invitaciones
         SET fecha_envio = NOW()
         WHERE id = ?"
    );
    $stmt->bind_param('i', $invitacionId);
    $stmt->execute();
    $stmt->close();
}

function expediente_obtener_invitacion(mysqli $conn, string $token): ?array
{
    if (!expediente_invitaciones_tabla_existe($conn)) {
        throw new RuntimeException(
            'Falta ejecutar database/instalar_invitaciones_expediente_salud.sql.'
        );
    }

    $token = trim($token);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }

    $hash = hash('sha256', $token);
    $stmt = $conn->prepare(
        "SELECT
            inv.*,
            c.nombre,
            c.apellido,
            c.email AS cliente_email,
            c.telefono,
            c.estado AS cliente_estado,
            s.nombre AS sucursal_nombre,
            u.nombre AS administrador_nombre,
            g.nombre AS gimnasio_nombre,
            g.logo AS gimnasio_logo
         FROM expedientes_salud_invitaciones inv
         INNER JOIN clientes c ON c.id = inv.cliente_id
         INNER JOIN sucursales s ON s.id = inv.sucursal_id
         INNER JOIN usuarios u ON u.id = inv.creado_por
         LEFT JOIN configuracion_gimnasio g ON g.id = 1
         WHERE inv.token_hash = ?
         LIMIT 1"
    );
    $stmt->bind_param('s', $hash);
    $stmt->execute();
    $resultado = $stmt->get_result();
    $fila = $resultado ? $resultado->fetch_assoc() : null;
    $stmt->close();

    if (!$fila) {
        return null;
    }

    if (
        (string) $fila['estado'] === 'pendiente'
        && strtotime((string) $fila['vence_en']) < time()
    ) {
        $id = (int) $fila['id'];
        $stmt = $conn->prepare(
            "UPDATE expedientes_salud_invitaciones
             SET estado = 'vencida'
             WHERE id = ? AND estado = 'pendiente'"
        );
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        $fila['estado'] = 'vencida';
    }

    return $fila;
}

function expediente_incrementar_intentos_invitacion(mysqli $conn, int $invitacionId): void
{
    $stmt = $conn->prepare(
        "UPDATE expedientes_salud_invitaciones
         SET intentos = intentos + 1
         WHERE id = ?"
    );
    $stmt->bind_param('i', $invitacionId);
    $stmt->execute();
    $stmt->close();
}

function expediente_completar_invitacion(
    mysqli $conn,
    int $invitacionId,
    int $expedienteId
): void {
    $stmt = $conn->prepare(
        "UPDATE expedientes_salud_invitaciones
         SET estado = 'completada',
             completado_en = NOW(),
             expediente_id = ?
         WHERE id = ?
           AND estado = 'pendiente'"
    );
    $stmt->bind_param('ii', $expedienteId, $invitacionId);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        $stmt->close();
        throw new RuntimeException(
            'La invitación ya fue utilizada, venció o dejó de estar disponible.'
        );
    }

    $stmt->close();
}
