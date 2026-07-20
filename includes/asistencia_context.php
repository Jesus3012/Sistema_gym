<?php
declare(strict_types=1);

/*
 * Contexto común para Asistencias.
 *
 * Regla de negocio:
 * - El socio y su membresía son globales.
 * - Cada entrada, salida y acceso denegado pertenece a la sucursal
 *   donde ocurrió físicamente.
 * - En la vista "Todas las sucursales" solo se consulta; para registrar
 *   se debe seleccionar una sede concreta.
 */

final class AsistenciaOperacionException extends RuntimeException
{
}

function asistencia_limpiar_salida(): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
}

function asistencia_responder(
    array $payload,
    int $status = 200
): void {
    asistencia_limpiar_salida();

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
    }

    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES |
        JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

function asistencia_ok(array $payload = []): void
{
    asistencia_responder(
        array_merge(
            ['success' => true],
            $payload
        )
    );
}

function asistencia_error(
    string $message,
    int $status = 422,
    array $extra = []
): void {
    asistencia_responder(
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

/**
 * @return array{
 *   conn: mysqli,
 *   usuario_id: int,
 *   sucursal_id: int,
 *   sucursal_nombre: string,
 *   sucursal_clave: string,
 *   vista_global: bool,
 *   es_admin: bool
 * }
 */
function asistencia_contexto(): array
{
    if (ob_get_level() === 0) {
        ob_start();
    }

    ini_set('display_errors', '0');
    ini_set('html_errors', '0');
    error_reporting(E_ALL);

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/sucursal_context.php';

    if (empty($_SESSION['user_id'])) {
        asistencia_error(
            'Tu sesión terminó. Inicia sesión nuevamente.',
            401
        );
    }

    mysqli_report(
        MYSQLI_REPORT_ERROR |
        MYSQLI_REPORT_STRICT
    );

    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn instanceof mysqli) {
        asistencia_error(
            'No se pudo conectar con la base de datos.',
            500
        );
    }

    $conn->set_charset('utf8mb4');

    try {
        sucursal_inicializar_sesion($conn);
    } catch (Throwable $error) {
        asistencia_error(
            $error->getMessage(),
            409
        );
    }

    $usuarioId = (int) ($_SESSION['user_id'] ?? 0);
    $sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);

    if ($sucursalId <= 0) {
        asistencia_error(
            'Selecciona una sucursal operativa.',
            409
        );
    }

    $rolBase = strtolower(trim((string) (
        $_SESSION['user_rol_base']
        ?? $_SESSION['user_rol']
        ?? ''
    )));

    $esAdmin = in_array(
        $rolBase,
        ['admin', 'administrador'],
        true
    );

    $vistaGlobal =
        $esAdmin
        && function_exists('sucursal_dashboard_vista_global')
        && sucursal_dashboard_vista_global();

    date_default_timezone_set(
        (string) (
            $_SESSION['sucursal_zona_horaria']
            ?? 'America/Mexico_City'
        )
    );

    return [
        'conn' => $conn,
        'usuario_id' => $usuarioId,
        'sucursal_id' => $sucursalId,
        'sucursal_nombre' => trim((string) (
            $_SESSION['sucursal_nombre'] ?? 'Sucursal'
        )),
        'sucursal_clave' => trim((string) (
            $_SESSION['sucursal_clave'] ?? ''
        )),
        'vista_global' => $vistaGlobal,
        'es_admin' => $esAdmin,
    ];
}

function asistencia_exigir_sede_concreta(
    array $contexto
): void {
    if (!empty($contexto['vista_global'])) {
        asistencia_error(
            'Selecciona una sucursal concreta para registrar la asistencia.',
            409,
            ['code' => 'sucursal_operativa_requerida']
        );
    }
}

/**
 * La membresía se busca globalmente: no se filtra por sucursal.
 *
 * @return array<string,mixed>|null
 */
function asistencia_obtener_membresia_activa(
    mysqli $conn,
    int $clienteId
): ?array {
    $stmt = $conn->prepare(
        "SELECT
            i.id AS inscripcion_id,
            i.fecha_inicio,
            i.fecha_fin,
            p.nombre AS plan_nombre,
            p.duracion_dias,
            CASE
                WHEN i.fecha_fin IS NULL THEN NULL
                ELSE GREATEST(
                    DATEDIFF(i.fecha_fin, CURDATE()),
                    0
                )
            END AS dias_restantes
         FROM inscripciones i
         INNER JOIN planes p
            ON p.id = i.plan_id
         WHERE i.cliente_id = ?
           AND i.estado = 'activa'
           AND i.fecha_inicio <= CURDATE()
           AND (
                i.fecha_fin IS NULL
                OR i.fecha_fin >= CURDATE()
           )
         ORDER BY
            CASE
                WHEN i.fecha_fin IS NULL THEN 1
                ELSE 0
            END DESC,
            i.fecha_fin DESC,
            i.id DESC
         LIMIT 1"
    );

    $stmt->bind_param('i', $clienteId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

/**
 * Devuelve un mensaje específico cuando no existe una membresía vigente.
 */
function asistencia_mensaje_membresia_no_valida(
    mysqli $conn,
    int $clienteId
): string {
    $stmt = $conn->prepare(
        "SELECT
            i.estado,
            i.fecha_inicio,
            i.fecha_fin,
            p.nombre AS plan_nombre
         FROM inscripciones i
         INNER JOIN planes p
            ON p.id = i.plan_id
         WHERE i.cliente_id = ?
         ORDER BY
            i.fecha_fin DESC,
            i.id DESC
         LIMIT 1"
    );

    $stmt->bind_param('i', $clienteId);
    $stmt->execute();

    $ultima = $stmt
        ->get_result()
        ->fetch_assoc();

    $stmt->close();

    if (!is_array($ultima)) {
        return 'El socio no tiene una inscripción registrada.';
    }

    $estado = strtolower(trim((string) (
        $ultima['estado'] ?? ''
    )));

    $fechaInicio = trim((string) (
        $ultima['fecha_inicio'] ?? ''
    ));

    $fechaFin = trim((string) (
        $ultima['fecha_fin'] ?? ''
    ));

    $planNombre = trim((string) (
        $ultima['plan_nombre'] ?? 'membresía'
    ));

    $hoy = new DateTimeImmutable('today');

    if ($estado === 'cancelada') {
        return 'La inscripción del socio está cancelada.';
    }

    if (
        $fechaInicio !== '' &&
        new DateTimeImmutable($fechaInicio) > $hoy
    ) {
        return sprintf(
            'La membresía %s todavía no inicia. Estará disponible a partir del %s.',
            $planNombre,
            date('d/m/Y', strtotime($fechaInicio))
        );
    }

    if (
        $estado === 'vencida' ||
        (
            $fechaFin !== '' &&
            new DateTimeImmutable($fechaFin) < $hoy
        )
    ) {
        return sprintf(
            'La membresía %s venció el %s. Debe renovarse para permitir el acceso.',
            $planNombre,
            $fechaFin !== ''
                ? date('d/m/Y', strtotime($fechaFin))
                : 'día anterior'
        );
    }

    return 'El socio no tiene una membresía activa vigente.';
}

/** @return array<string,mixed>|null */
function asistencia_obtener_cliente_id(
    mysqli $conn,
    int $clienteId
): ?array {
    $stmt = $conn->prepare(
        "SELECT
            id,
            nombre,
            apellido,
            telefono,
            estado
         FROM clientes
         WHERE id = ?
         LIMIT 1"
    );

    $stmt->bind_param('i', $clienteId);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

/** @return array<string,mixed>|null */
function asistencia_obtener_cliente_qr(
    mysqli $conn,
    string $codigoQr
): ?array {
    $stmt = $conn->prepare(
        "SELECT
            id,
            nombre,
            apellido,
            telefono,
            estado
         FROM clientes
         WHERE codigo_qr = ?
         LIMIT 1"
    );

    $stmt->bind_param('s', $codigoQr);
    $stmt->execute();

    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row) ? $row : null;
}

/*
 * Algunas instalaciones antiguas solo aceptaban huella/manual en el enum.
 * Se detecta si "qr" está permitido para evitar que falle el INSERT.
 */
function asistencia_metodo_denegado_db(
    mysqli $conn,
    string $metodo
): string {
    static $metodosPermitidos = null;

    if (!is_array($metodosPermitidos)) {
        $metodosPermitidos = [
            'huella' => true,
            'manual' => true,
        ];

        try {
            $result = $conn->query(
                "SHOW COLUMNS
                 FROM asistencias_denegadas
                 LIKE 'metodo'"
            );

            $column = $result
                ? $result->fetch_assoc()
                : null;

            $type = strtolower((string) (
                $column['Type'] ?? ''
            ));

            if (str_contains($type, "'qr'")) {
                $metodosPermitidos['qr'] = true;
            }
        } catch (Throwable $error) {
            error_log(
                '[Asistencias enum método] ' .
                $error->getMessage()
            );
        }
    }

    if (!empty($metodosPermitidos[$metodo])) {
        return $metodo;
    }

    return $metodo === 'qr'
        ? 'huella'
        : 'manual';
}

function asistencia_registrar_denegacion(
    mysqli $conn,
    int $sucursalId,
    int $clienteId,
    string $motivo,
    string $metodo
): void {
    if ($clienteId <= 0) {
        return;
    }

    try {
        $metodoDb = asistencia_metodo_denegado_db(
            $conn,
            $metodo
        );

        $stmt = $conn->prepare(
            "INSERT INTO asistencias_denegadas (
                sucursal_id,
                cliente_id,
                fecha,
                hora,
                motivo,
                metodo
             ) VALUES (
                ?,
                ?,
                CURDATE(),
                CURTIME(),
                ?,
                ?
             )"
        );

        $stmt->bind_param(
            'iiss',
            $sucursalId,
            $clienteId,
            $motivo,
            $metodoDb
        );

        $stmt->execute();
        $stmt->close();
    } catch (Throwable $error) {
        /*
         * La denegación no debe ocultar el mensaje principal del socio.
         */
        error_log(
            '[Asistencia denegada] ' .
            $error->getMessage()
        );
    }
}

/**
 * @return array<string,mixed>
 */
function asistencia_registrar(
    mysqli $conn,
    int $sucursalId,
    int $usuarioId,
    array $cliente,
    string $metodo,
    string $tipoSolicitado = 'auto'
): array {
    $clienteId = (int) ($cliente['id'] ?? 0);

    if ($clienteId <= 0) {
        throw new AsistenciaOperacionException(
            'No se pudo identificar al socio.'
        );
    }

    if (($cliente['estado'] ?? '') !== 'activo') {
        $motivo = 'El socio está inactivo.';

        asistencia_registrar_denegacion(
            $conn,
            $sucursalId,
            $clienteId,
            $motivo,
            $metodo
        );

        throw new AsistenciaOperacionException($motivo);
    }

    $membresia = asistencia_obtener_membresia_activa(
        $conn,
        $clienteId
    );

    if ($membresia === null) {
        $motivo = asistencia_mensaje_membresia_no_valida(
            $conn,
            $clienteId
        );

        asistencia_registrar_denegacion(
            $conn,
            $sucursalId,
            $clienteId,
            $motivo,
            $metodo
        );

        throw new AsistenciaOperacionException(
            $motivo
        );
    }

    if (!in_array(
        $tipoSolicitado,
        ['auto', 'entrada', 'salida'],
        true
    )) {
        throw new AsistenciaOperacionException(
            'Tipo de registro no válido.'
        );
    }

    $conn->begin_transaction();

    try {
        /*
         * La entrada/salida se consulta por socio + fecha + sucursal.
         * Así el movimiento queda ligado a la sede donde ocurrió.
         */
        $stmt = $conn->prepare(
            "SELECT
                id,
                hora_entrada,
                hora_salida
             FROM asistencias
             WHERE sucursal_id = ?
               AND cliente_id = ?
               AND fecha = CURDATE()
             ORDER BY id DESC
             LIMIT 1
             FOR UPDATE"
        );

        $stmt->bind_param(
            'ii',
            $sucursalId,
            $clienteId
        );

        $stmt->execute();

        $registro = $stmt
            ->get_result()
            ->fetch_assoc();

        $stmt->close();

        $tipo = $tipoSolicitado;

        if ($tipo === 'auto') {
            if (!is_array($registro)) {
                $tipo = 'entrada';
            } elseif (empty($registro['hora_salida'])) {
                $tipo = 'salida';
            } else {
                throw new AsistenciaOperacionException(
                    'El socio ya registró entrada y salida hoy en esta sucursal.'
                );
            }
        }

        if ($tipo === 'entrada') {
            if (is_array($registro)) {
                if (empty($registro['hora_salida'])) {
                    throw new AsistenciaOperacionException(
                        'El socio ya tiene una entrada abierta en esta sucursal.'
                    );
                }

                throw new AsistenciaOperacionException(
                    'El socio ya completó su asistencia de hoy en esta sucursal.'
                );
            }

            $inscripcionId = (int) (
                $membresia['inscripcion_id'] ?? 0
            );

            $diasRestantes =
                $membresia['dias_restantes'] === null
                    ? null
                    : (int) $membresia['dias_restantes'];

            $planNombre = (string) (
                $membresia['plan_nombre'] ?? 'Plan'
            );

            if ($metodo === 'manual') {
                $stmt = $conn->prepare(
                    "INSERT INTO asistencias (
                        sucursal_id,
                        cliente_id,
                        fecha,
                        hora_entrada,
                        hora_salida,
                        metodo_registro,
                        verificado_por,
                        inscripcion_id,
                        dias_restantes,
                        plan_nombre
                     ) VALUES (
                        ?,
                        ?,
                        CURDATE(),
                        CURTIME(),
                        NULL,
                        'manual',
                        ?,
                        ?,
                        ?,
                        ?
                     )"
                );

                $stmt->bind_param(
                    'iiiiis',
                    $sucursalId,
                    $clienteId,
                    $usuarioId,
                    $inscripcionId,
                    $diasRestantes,
                    $planNombre
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO asistencias (
                        sucursal_id,
                        cliente_id,
                        fecha,
                        hora_entrada,
                        hora_salida,
                        metodo_registro,
                        verificado_por,
                        inscripcion_id,
                        dias_restantes,
                        plan_nombre
                     ) VALUES (
                        ?,
                        ?,
                        CURDATE(),
                        CURTIME(),
                        NULL,
                        'qr',
                        NULL,
                        ?,
                        ?,
                        ?
                     )"
                );

                $stmt->bind_param(
                    'iiiis',
                    $sucursalId,
                    $clienteId,
                    $inscripcionId,
                    $diasRestantes,
                    $planNombre
                );
            }

            $stmt->execute();
            $stmt->close();

            $horaRegistro = date('H:i:s');
        } else {
            if (!is_array($registro)) {
                throw new AsistenciaOperacionException(
                    'No existe una entrada abierta para registrar la salida.'
                );
            }

            if (!empty($registro['hora_salida'])) {
                throw new AsistenciaOperacionException(
                    'La salida ya había sido registrada.'
                );
            }

            $registroId = (int) $registro['id'];

            $stmt = $conn->prepare(
                "UPDATE asistencias
                 SET hora_salida = CURTIME()
                 WHERE id = ?
                   AND sucursal_id = ?
                   AND hora_salida IS NULL"
            );

            $stmt->bind_param(
                'ii',
                $registroId,
                $sucursalId
            );

            $stmt->execute();

            if ($stmt->affected_rows !== 1) {
                $stmt->close();

                throw new AsistenciaOperacionException(
                    'La asistencia cambió mientras se procesaba.'
                );
            }

            $stmt->close();
            $horaRegistro = date('H:i:s');
        }

        $conn->commit();

        return [
            'tipo' => $tipo,
            'cliente_nombre' => trim(
                (string) ($cliente['nombre'] ?? '')
                . ' '
                . (string) ($cliente['apellido'] ?? '')
            ),
            'hora_entrada' =>
                $tipo === 'entrada'
                    ? $horaRegistro
                    : (string) (
                        $registro['hora_entrada'] ?? ''
                    ),
            'hora_salida' =>
                $tipo === 'salida'
                    ? $horaRegistro
                    : null,
            'plan_nombre' => (string) (
                $membresia['plan_nombre'] ?? 'Plan'
            ),
            'dias_restantes' =>
                $membresia['dias_restantes'] ?? null,
        ];
    } catch (Throwable $error) {
        $conn->rollback();
        throw $error;
    }
}
