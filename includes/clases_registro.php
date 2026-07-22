<?php
declare(strict_types=1);

/**
 * Funciones de negocio para registrar accesos a clases.
 */

function clase_registro_fecha_valida(string $fecha): bool
{
    $obj = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);

    return $obj instanceof DateTimeImmutable
        && $obj->format('Y-m-d') === $fecha;
}

function clase_registro_obtener_clase(
    mysqli $conn,
    int $claseId,
    int $sucursalId,
    bool $forUpdate = false
): array {
    $sql = "SELECT
                c.id,
                c.sucursal_id,
                c.nombre,
                c.descripcion,
                c.precio_clase,
                c.horario,
                c.instructor,
                c.cupo_maximo,
                c.duracion_minutos,
                c.estado,
                s.nombre AS sucursal_nombre,
                s.clave AS sucursal_clave,
                s.email AS sucursal_email,
                s.telefono AS sucursal_telefono,
                s.direccion AS sucursal_direccion,
                s.logo AS sucursal_logo
            FROM clases c
            INNER JOIN sucursales s
                ON s.id = c.sucursal_id
            WHERE c.id = ?
              AND c.sucursal_id = ?
            LIMIT 1";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $claseId, $sucursalId);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($fila)) {
        throw new RuntimeException(
            'La clase seleccionada no pertenece a la sucursal activa.'
        );
    }

    if (($fila['estado'] ?? '') !== 'activa') {
        throw new RuntimeException('La clase seleccionada está inactiva.');
    }

    return $fila;
}

function clase_registro_obtener_horario(
    mysqli $conn,
    int $claseId,
    int $horarioId,
    string $fechaClase
): ?array {
    if (!clase_registro_fecha_valida($fechaClase)) {
        throw new InvalidArgumentException('La fecha de la clase no es válida.');
    }

    if ($horarioId <= 0) {
        return null;
    }

    $stmt = $conn->prepare(
        "SELECT id, clase_id, dia_semana, hora_inicio, hora_fin
         FROM clases_horarios
         WHERE id = ?
           AND clase_id = ?
           AND estado = 'activo'
         LIMIT 1"
    );
    $stmt->bind_param('ii', $horarioId, $claseId);
    $stmt->execute();
    $horario = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($horario)) {
        throw new RuntimeException('El horario seleccionado ya no está disponible.');
    }

    $diaFecha = (int) (new DateTimeImmutable($fechaClase))->format('N');

    if ($diaFecha !== (int) $horario['dia_semana']) {
        throw new RuntimeException(
            'La fecha elegida no corresponde al día del horario seleccionado.'
        );
    }

    return $horario;
}

function clase_registro_membresia_activa(
    mysqli $conn,
    int $clienteId,
    string $fechaClase
): ?array {
    $stmt = $conn->prepare(
        "SELECT
            i.id,
            i.fecha_inicio,
            i.fecha_fin,
            p.nombre AS plan_nombre
         FROM inscripciones i
         INNER JOIN planes p
            ON p.id = i.plan_id
         WHERE i.cliente_id = ?
           AND i.estado = 'activa'
           AND i.fecha_inicio <= ?
           AND i.fecha_fin >= ?
         ORDER BY i.fecha_fin DESC, i.id DESC
         LIMIT 1"
    );
    $stmt->bind_param('iss', $clienteId, $fechaClase, $fechaClase);
    $stmt->execute();
    $membresia = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($membresia) ? $membresia : null;
}

function clase_registro_ultima_membresia(
    mysqli $conn,
    int $clienteId
): ?array {
    $stmt = $conn->prepare(
        "SELECT
            i.id,
            i.estado,
            i.fecha_inicio,
            i.fecha_fin,
            p.nombre AS plan_nombre
         FROM inscripciones i
         INNER JOIN planes p
            ON p.id = i.plan_id
         WHERE i.cliente_id = ?
         ORDER BY i.fecha_fin DESC, i.id DESC
         LIMIT 1"
    );
    $stmt->bind_param('i', $clienteId);
    $stmt->execute();
    $membresia = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($membresia) ? $membresia : null;
}

function clase_registro_calcular_cobro(
    mysqli $conn,
    int $claseId,
    int $sucursalId,
    ?int $clienteId,
    string $fechaClase
): array {
    $clase = clase_registro_obtener_clase(
        $conn,
        $claseId,
        $sucursalId,
        false
    );

    $precio = round((float) ($clase['precio_clase'] ?? 0), 2);

    if ($clienteId === null || $clienteId <= 0) {
        return [
            'clase' => $clase,
            'cliente' => null,
            'membresia' => null,
            'ultima_membresia' => null,
            'precio_clase' => $precio,
            'monto_cobrar' => $precio,
            'cubierto_membresia' => false,
            'motivo' => 'Visitante sin membresía: se cobra el acceso individual.',
        ];
    }

    $stmt = $conn->prepare(
        "SELECT id, nombre, apellido, telefono, email, estado
         FROM clientes
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->bind_param('i', $clienteId);
    $stmt->execute();
    $cliente = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($cliente)) {
        throw new RuntimeException('El socio seleccionado no existe.');
    }

    if (($cliente['estado'] ?? '') !== 'activo') {
        throw new RuntimeException('El socio está inactivo y no puede inscribirse.');
    }

    $membresia = clase_registro_membresia_activa(
        $conn,
        $clienteId,
        $fechaClase
    );

    $cubierto = is_array($membresia);
    $ultimaMembresia = $cubierto
        ? $membresia
        : clase_registro_ultima_membresia(
            $conn,
            $clienteId
        );

    if ($cubierto) {
        $motivo = 'La membresía vigente cubre esta clase.';
    } elseif (!is_array($ultimaMembresia)) {
        $motivo = 'El socio no tiene una membresía registrada. Se cobra el acceso individual.';
    } else {
        $estadoUltimo = trim((string) ($ultimaMembresia['estado'] ?? ''));
        $fechaFinUltima = trim((string) ($ultimaMembresia['fecha_fin'] ?? ''));
        $planUltimo = trim((string) ($ultimaMembresia['plan_nombre'] ?? ''));

        if ($estadoUltimo === 'cancelada') {
            $motivo = 'La membresía'
                . ($planUltimo !== '' ? ' ' . $planUltimo : '')
                . ' está cancelada. Se cobra el acceso individual.';
        } elseif ($estadoUltimo === 'vencida') {
            $motivo = 'La membresía'
                . ($planUltimo !== '' ? ' ' . $planUltimo : '')
                . ' está vencida. Se cobra el acceso individual.';
        } elseif (
            $fechaFinUltima !== ''
            && $fechaFinUltima < $fechaClase
        ) {
            $motivo = 'La membresía'
                . ($planUltimo !== '' ? ' ' . $planUltimo : '')
                . ' venció antes de la fecha seleccionada. Se cobra el acceso individual.';
        } else {
            $motivo = 'El socio no tiene una membresía activa y vigente para esta fecha. Se cobra el acceso individual.';
        }
    }

    return [
        'clase' => $clase,
        'cliente' => $cliente,
        'membresia' => $membresia,
        'ultima_membresia' => $ultimaMembresia,
        'precio_clase' => $precio,
        'monto_cobrar' => $cubierto ? 0.00 : $precio,
        'cubierto_membresia' => $cubierto,
        'motivo' => $motivo,
    ];
}

function clase_registro_contar_cupo(
    mysqli $conn,
    int $claseId,
    string $fechaClase,
    ?int $horarioId,
    bool $forUpdate = false
): int {
    $sql = "SELECT COUNT(*) AS total
            FROM inscripciones_clases
            WHERE clase_id = ?
              AND fecha_clase = ?
              AND estado = 'activa'
              AND (
                    (? IS NULL AND horario_id IS NULL)
                    OR horario_id = ?
              )";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        'isii',
        $claseId,
        $fechaClase,
        $horarioId,
        $horarioId
    );
    $stmt->execute();
    $total = (int) (($stmt->get_result()->fetch_assoc()['total'] ?? 0));
    $stmt->close();

    return $total;
}

function clase_registro_folio(int $sucursalId, int $claseId): string
{
    return sprintf(
        'CLS-S%d-C%d-%s-%s',
        $sucursalId,
        $claseId,
        date('YmdHis'),
        strtoupper(substr(bin2hex(random_bytes(4)), 0, 8))
    );
}

function clase_registro_nombre_dia(int $dia): string
{
    $dias = [
        1 => 'Lunes',
        2 => 'Martes',
        3 => 'Miércoles',
        4 => 'Jueves',
        5 => 'Viernes',
        6 => 'Sábado',
        7 => 'Domingo',
    ];

    return $dias[$dia] ?? 'Día';
}

function clase_registro_formatear_hora(string $hora): string
{
    return substr($hora, 0, 5);
}

/**
 * Normaliza el celular para búsquedas y prevención de duplicados.
 */
function clase_registro_normalizar_telefono(string $telefono): string
{
    return preg_replace('/\D+/', '', $telefono) ?? '';
}

/**
 * Construye una identidad estable para reutilizar visitantes sin impedir
 * que dos personas distintas compartan el mismo número celular.
 */
function clase_registro_hash_visitante(
    string $nombre,
    string $apellido,
    string $telefono
): string {
    $normalizado = clase_registro_normalizar_telefono($telefono);
    $nombreLlave = mb_strtolower(trim($nombre), 'UTF-8');
    $apellidoLlave = mb_strtolower(trim($apellido), 'UTF-8');

    return hash(
        'sha256',
        $normalizado . '|' . $nombreLlave . '|' . $apellidoLlave
    );
}

/**
 * Obtiene un visitante del registro reutilizable.
 */
function clase_registro_obtener_visitante(
    mysqli $conn,
    int $visitanteId,
    bool $forUpdate = false
): array {
    if ($visitanteId <= 0) {
        throw new InvalidArgumentException('El visitante seleccionado no es válido.');
    }

    $sql = "SELECT
                id,
                sucursal_registro_id,
                ultima_sucursal_id,
                nombre,
                apellido,
                telefono,
                telefono_normalizado,
                identidad_hash,
                email,
                estado,
                total_visitas,
                fecha_ultima_visita
            FROM visitantes_clases
            WHERE id = ?
            LIMIT 1";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('i', $visitanteId);
    $stmt->execute();
    $visitante = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!is_array($visitante)) {
        throw new RuntimeException(
            'El visitante seleccionado ya no existe en el registro.'
        );
    }

    if (($visitante['estado'] ?? '') !== 'activo') {
        throw new RuntimeException(
            'El visitante seleccionado está inactivo.'
        );
    }

    return $visitante;
}

/**
 * Registra un visitante nuevo o reutiliza/actualiza uno existente.
 *
 * El teléfono no es único por sí solo porque dos personas pueden compartirlo.
 * La identidad se calcula con teléfono + nombre + apellidos.
 */
function clase_registro_guardar_visitante(
    mysqli $conn,
    int $visitanteId,
    int $sucursalId,
    string $nombre,
    string $apellido,
    string $telefono,
    string $email,
    int $usuarioId
): array {
    $nombre = trim($nombre);
    $apellido = trim($apellido);
    $telefono = trim($telefono);
    $email = trim($email);
    $telefonoNormalizado = clase_registro_normalizar_telefono($telefono);

    if ($nombre === '' || mb_strlen($nombre, 'UTF-8') > 100) {
        throw new InvalidArgumentException(
            'El nombre del visitante es obligatorio y no debe exceder 100 caracteres.'
        );
    }

    if ($apellido === '' || mb_strlen($apellido, 'UTF-8') > 120) {
        throw new InvalidArgumentException(
            'Los apellidos del visitante son obligatorios y no deben exceder 120 caracteres.'
        );
    }

    if (
        strlen($telefonoNormalizado) < 7
        || strlen($telefonoNormalizado) > 15
    ) {
        throw new InvalidArgumentException(
            'Escribe un número celular válido de entre 7 y 15 dígitos.'
        );
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException(
            'El correo del visitante no es válido.'
        );
    }

    $identidadHash = clase_registro_hash_visitante(
        $nombre,
        $apellido,
        $telefono
    );

    $visitante = null;

    if ($visitanteId > 0) {
        $visitante = clase_registro_obtener_visitante(
            $conn,
            $visitanteId,
            true
        );

        $identidadActual = (string) (
            $visitante['identidad_hash']
            ?? clase_registro_hash_visitante(
                (string) $visitante['nombre'],
                (string) $visitante['apellido'],
                (string) $visitante['telefono']
            )
        );

        /*
         * Protección contra selecciones anteriores que quedaron en el campo
         * oculto. Si la persona capturada ya no corresponde al visitante que
         * se había seleccionado, se trata como un visitante nuevo y nunca se
         * sobrescribe el registro anterior.
         */
        if (!hash_equals($identidadActual, $identidadHash)) {
            $visitanteId = 0;
            $visitante = null;
        } else {
            $stmtConflicto = $conn->prepare(
                "SELECT id
                 FROM visitantes_clases
                 WHERE identidad_hash = ?
                   AND id <> ?
                 LIMIT 1
                 FOR UPDATE"
            );
            $stmtConflicto->bind_param(
                'si',
                $identidadHash,
                $visitanteId
            );
            $stmtConflicto->execute();
            $conflicto = $stmtConflicto->get_result()->fetch_assoc();
            $stmtConflicto->close();

            if (is_array($conflicto)) {
                $visitanteId = (int) $conflicto['id'];
                $visitante = clase_registro_obtener_visitante(
                    $conn,
                    $visitanteId,
                    true
                );
            }
        }
    }

    if ($visitanteId <= 0 && !is_array($visitante)) {
        $stmtBuscar = $conn->prepare(
            "SELECT id
             FROM visitantes_clases
             WHERE identidad_hash = ?
             LIMIT 1
             FOR UPDATE"
        );
        $stmtBuscar->bind_param('s', $identidadHash);
        $stmtBuscar->execute();
        $existente = $stmtBuscar->get_result()->fetch_assoc();
        $stmtBuscar->close();

        if (is_array($existente)) {
            $visitanteId = (int) $existente['id'];
            $visitante = clase_registro_obtener_visitante(
                $conn,
                $visitanteId,
                true
            );
        }
    }

    if (is_array($visitante)) {
        $stmtUpdate = $conn->prepare(
            "UPDATE visitantes_clases
             SET nombre = ?,
                 apellido = ?,
                 telefono = ?,
                 telefono_normalizado = ?,
                 identidad_hash = ?,
                 email = ?,
                 estado = 'activo',
                 ultima_sucursal_id = ?,
                 usuario_actualizacion_id = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        $stmtUpdate->bind_param(
            'ssssssiii',
            $nombre,
            $apellido,
            $telefono,
            $telefonoNormalizado,
            $identidadHash,
            $email,
            $sucursalId,
            $usuarioId,
            $visitanteId
        );
        $stmtUpdate->execute();
        $stmtUpdate->close();
    } else {
        $stmtInsert = $conn->prepare(
            "INSERT INTO visitantes_clases (
                sucursal_registro_id,
                ultima_sucursal_id,
                nombre,
                apellido,
                telefono,
                telefono_normalizado,
                identidad_hash,
                email,
                estado,
                total_visitas,
                usuario_registro_id,
                usuario_actualizacion_id,
                created_at,
                updated_at
             ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?,
                'activo', 0, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )"
        );
        $stmtInsert->bind_param(
            'iissssssii',
            $sucursalId,
            $sucursalId,
            $nombre,
            $apellido,
            $telefono,
            $telefonoNormalizado,
            $identidadHash,
            $email,
            $usuarioId,
            $usuarioId
        );
        $stmtInsert->execute();
        $visitanteId = (int) $conn->insert_id;
        $stmtInsert->close();
    }

    return clase_registro_obtener_visitante(
        $conn,
        $visitanteId,
        false
    );
}

/**
 * Actualiza métricas del visitante después de reservar una clase.
 */
function clase_registro_marcar_visita_visitante(
    mysqli $conn,
    int $visitanteId,
    int $sucursalId,
    string $fechaClase,
    int $usuarioId
): void {
    if ($visitanteId <= 0) {
        return;
    }

    $stmt = $conn->prepare(
        "UPDATE visitantes_clases
         SET total_visitas = total_visitas + 1,
             fecha_ultima_visita = CASE
                WHEN fecha_ultima_visita IS NULL
                  OR fecha_ultima_visita < ?
                    THEN ?
                ELSE fecha_ultima_visita
             END,
             ultima_sucursal_id = ?,
             usuario_actualizacion_id = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?
           AND estado = 'activo'"
    );
    $stmt->bind_param(
        'ssiii',
        $fechaClase,
        $fechaClase,
        $sucursalId,
        $usuarioId,
        $visitanteId
    );
    $stmt->execute();
    $stmt->close();
}

/**
 * Recalcula el historial del visitante después de una cancelación.
 */
function clase_registro_recalcular_visitante(
    mysqli $conn,
    int $visitanteId,
    int $sucursalId,
    int $usuarioId
): void {
    if ($visitanteId <= 0) {
        return;
    }

    $stmtResumen = $conn->prepare(
        "SELECT
            SUM(
                CASE
                    WHEN estado <> 'cancelada' THEN 1
                    ELSE 0
                END
            ) AS total,
            MAX(
                CASE
                    WHEN estado <> 'cancelada' THEN fecha_clase
                    ELSE NULL
                END
            ) AS ultima_fecha
         FROM inscripciones_clases
         WHERE visitante_id = ?"
    );
    $stmtResumen->bind_param('i', $visitanteId);
    $stmtResumen->execute();
    $resumen = $stmtResumen->get_result()->fetch_assoc() ?: [];
    $stmtResumen->close();

    $total = (int) ($resumen['total'] ?? 0);
    $ultimaFecha = $resumen['ultima_fecha'] !== null
        ? (string) $resumen['ultima_fecha']
        : null;

    $stmtUpdate = $conn->prepare(
        "UPDATE visitantes_clases
         SET total_visitas = ?,
             fecha_ultima_visita = ?,
             ultima_sucursal_id = ?,
             usuario_actualizacion_id = ?,
             updated_at = CURRENT_TIMESTAMP
         WHERE id = ?"
    );
    $stmtUpdate->bind_param(
        'isiii',
        $total,
        $ultimaFecha,
        $sucursalId,
        $usuarioId,
        $visitanteId
    );
    $stmtUpdate->execute();
    $stmtUpdate->close();
}