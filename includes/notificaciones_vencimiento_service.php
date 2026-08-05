<?php
declare(strict_types=1);

/**
 * Servicio compartido para enviar avisos de vencimiento.
 *
 * - Con $context procesa únicamente el contexto abierto en notificaciones.php.
 * - Con null procesa todas las sucursales y es el modo usado por Hostinger Cron.
 */
function notif_process_expirations(
    mysqli $db,
    ?array $context = null
): array {
    $summary = [
        'encontradas' => 0,
        'omitidas_ya_enviadas' => 0,
        'enviados_3_dias' => 0,
        'enviados_vencidos' => 0,
        'errores' => 0,
        'errores_detalle' => [],
        'proceso_en_ejecucion' => false,
    ];

    /*
     * Impide que el botón manual y el Cron de Hostinger ejecuten
     * el mismo proceso simultáneamente.
     */
    $lockName = 'gym_notificaciones_vencimiento';
    $lockStmt = $db->prepare('SELECT GET_LOCK(?, 0) AS obtenido');

    if (!$lockStmt) {
        throw new RuntimeException(
            'No fue posible preparar el bloqueo del proceso: ' . $db->error
        );
    }

    $lockStmt->bind_param('s', $lockName);
    $lockStmt->execute();
    $lockRow = $lockStmt->get_result()->fetch_assoc();
    $lockStmt->close();

    if ((int) ($lockRow['obtenido'] ?? 0) !== 1) {
        $summary['proceso_en_ejecucion'] = true;
        return $summary;
    }

    $stmt = null;
    $checkStmt = null;
    $insertStmt = null;

    try {
        /*
         * Calculamos la fecha en PHP después de establecer
         * America/Mexico_City. Así no dependemos de CURDATE()
         * del servidor MySQL, que puede trabajar en UTC.
         */
        $today = date('Y-m-d');

        $types = 'ss';
        $params = [$today, $today];
        $scope = '1 = 1';

        if ($context !== null) {
            $scopeTypes = '';
            $scopeParams = [];

            $scope = notif_scope(
                'i.sucursal_id',
                $context,
                false,
                $scopeTypes,
                $scopeParams
            );

            $types .= $scopeTypes;
            $params = array_merge($params, $scopeParams);
        }

        $sql = "
            SELECT
                i.id,
                i.sucursal_id,
                i.cliente_id,
                i.fecha_fin,
                c.nombre,
                c.apellido,
                c.email,
                p.nombre AS plan_nombre,
                s.nombre AS sucursal_nombre,
                DATEDIFF(i.fecha_fin, ?) AS dias_restantes
            FROM inscripciones i
            INNER JOIN clientes c
                ON c.id = i.cliente_id
            INNER JOIN planes p
                ON p.id = i.plan_id
            INNER JOIN sucursales s
                ON s.id = i.sucursal_id
            WHERE i.estado = 'activa'
              AND DATEDIFF(i.fecha_fin, ?) IN (3, 0)
              AND LOWER(TRIM(p.nombre)) <> 'visita'
              AND c.estado = 'activo'
              AND c.email IS NOT NULL
              AND TRIM(c.email) <> ''
              AND $scope
            ORDER BY i.fecha_fin ASC, i.id ASC
        ";

        $stmt = $db->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible preparar las membresías por vencer: '
                . $db->error
            );
        }

        notif_bind($stmt, $types, $params);
        $stmt->execute();
        $result = $stmt->get_result();

        /*
         * Incluimos la fecha de vencimiento para que una futura renovación
         * no quede bloqueada por un aviso enviado en una vigencia anterior.
         */
        $checkStmt = $db->prepare(
            "SELECT id
             FROM notificaciones_vencimiento_historial
             WHERE inscripcion_id = ?
               AND tipo_notificacion = ?
               AND fecha_vencimiento = ?
               AND estado = 'enviado'
             LIMIT 1"
        );

        $insertStmt = $db->prepare(
            "INSERT INTO notificaciones_vencimiento_historial (
                sucursal_id,
                inscripcion_id,
                cliente_id,
                cliente_nombre,
                cliente_email,
                plan_nombre,
                tipo_notificacion,
                dias_restantes,
                fecha_vencimiento,
                fecha_envio,
                estado
             ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );

        if (!$checkStmt || !$insertStmt) {
            throw new RuntimeException(
                'No fue posible preparar el historial de vencimientos: '
                . $db->error
            );
        }

        while ($row = $result->fetch_assoc()) {
            $summary['encontradas']++;

            $days = (int) $row['dias_restantes'];
            $type = $days === 3 ? '3_dias' : 'vencido';
            $inscriptionId = (int) $row['id'];
            $expiration = (string) $row['fecha_fin'];

            $checkStmt->bind_param(
                'iss',
                $inscriptionId,
                $type,
                $expiration
            );
            $checkStmt->execute();

            if ($checkStmt->get_result()->fetch_assoc()) {
                $summary['omitidas_ya_enviadas']++;
                continue;
            }

            $fullName = trim(
                (string) $row['nombre']
                . ' '
                . (string) $row['apellido']
            );

            try {
                $send = notif_send_expiration(
                    $db,
                    (string) $row['email'],
                    $fullName,
                    $days,
                    $expiration,
                    (string) $row['plan_nombre'],
                    (string) $row['sucursal_nombre']
                );
            } catch (Throwable $mailError) {
                $send = [
                    'ok' => false,
                    'error' => $mailError->getMessage(),
                ];
            }

            $status = !empty($send['ok']) ? 'enviado' : 'fallido';
            $rowBranchId = (int) $row['sucursal_id'];
            $clientId = (int) $row['cliente_id'];
            $email = (string) $row['email'];
            $plan = (string) $row['plan_nombre'];
            $sentAt = date('Y-m-d H:i:s');

            $insertStmt->bind_param(
                'iiissssisss',
                $rowBranchId,
                $inscriptionId,
                $clientId,
                $fullName,
                $email,
                $plan,
                $type,
                $days,
                $expiration,
                $sentAt,
                $status
            );

            if (!$insertStmt->execute()) {
                $summary['errores']++;

                if (count($summary['errores_detalle']) < 12) {
                    $summary['errores_detalle'][] =
                        $fullName
                        . ': no se pudo guardar el historial ('
                        . $insertStmt->error
                        . ').';
                }

                continue;
            }

            if (!empty($send['ok'])) {
                if ($type === '3_dias') {
                    $summary['enviados_3_dias']++;
                } else {
                    $summary['enviados_vencidos']++;
                }

                continue;
            }

            $summary['errores']++;

            if (count($summary['errores_detalle']) < 12) {
                $summary['errores_detalle'][] =
                    $fullName
                    . ' · '
                    . $email
                    . ': '
                    . trim(
                        (string) (
                            $send['error']
                            ?? 'No se recibió detalle del error SMTP.'
                        )
                    );
            }
        }

        return $summary;
    } finally {
        if ($stmt instanceof mysqli_stmt) {
            $stmt->close();
        }

        if ($checkStmt instanceof mysqli_stmt) {
            $checkStmt->close();
        }

        if ($insertStmt instanceof mysqli_stmt) {
            $insertStmt->close();
        }

        $releaseStmt = $db->prepare('SELECT RELEASE_LOCK(?)');

        if ($releaseStmt) {
            $releaseStmt->bind_param('s', $lockName);
            $releaseStmt->execute();
            $releaseStmt->close();
        }
    }
}
