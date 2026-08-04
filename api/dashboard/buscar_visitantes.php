<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/dashboard_visitas_helper.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'Método no permitido.',
    ], 405);
}

$contexto = dashboard_visitas_contexto();
/** @var mysqli $db */
$db = $contexto['db'];

$q = dashboard_visitas_texto((string) ($_GET['q'] ?? ''), 100);
$longitud = function_exists('mb_strlen')
    ? mb_strlen($q, 'UTF-8')
    : strlen($q);

if ($longitud < 2) {
    dashboard_visitas_responder([
        'success' => true,
        'personas' => [],
    ]);
}

$patron = '%' . $q . '%';

try {
    /*
     * La búsqueda muestra el estado de acceso actual antes de elegir a la
     * persona. El endpoint de registro vuelve a verificarlo dentro de una
     * transacción, por lo que esta información es solo una ayuda visual.
     */
    $stmt = $db->prepare(
        "SELECT
            c.id,
            c.nombre,
            c.apellido,
            COALESCE(c.telefono, '') AS telefono,
            COALESCE(c.email, '') AS email,
            COALESCE(c.contacto_emergencia_nombre, '') AS contacto_emergencia_nombre,
            COALESCE(c.contacto_emergencia_telefono, '') AS contacto_emergencia_telefono,
            COALESCE(c.codigo_qr, '') AS codigo_qr,
            COALESCE(s.nombre, 'Sin sucursal') AS sucursal,
            (
                SELECT MAX(iv.fecha_registro)
                FROM inscripciones iv
                INNER JOIN planes pv
                    ON pv.id = iv.plan_id
                WHERE iv.cliente_id = c.id
                  AND pv.duracion_dias = 1
            ) AS ultima_visita,
            ia.id AS membresia_activa_id,
            COALESCE(pa.nombre, '') AS membresia_activa_plan,
            COALESCE(ia.fecha_fin, '') AS membresia_activa_fecha_fin,
            COALESCE(pa.duracion_dias, 0) AS membresia_activa_duracion,
            (
                SELECT MAX(ix.fecha_fin)
                FROM inscripciones ix
                INNER JOIN planes px
                    ON px.id = ix.plan_id
                WHERE ix.cliente_id = c.id
                  AND ix.estado IN ('activa', 'vencida')
                  AND px.duracion_dias > 1
                  AND ix.fecha_fin < CURDATE()
            ) AS ultima_membresia_vencida
         FROM clientes c
         LEFT JOIN sucursales s
            ON s.id = c.sucursal_registro_id
         LEFT JOIN inscripciones ia
            ON ia.id = (
                SELECT ia2.id
                FROM inscripciones ia2
                WHERE ia2.cliente_id = c.id
                  AND ia2.estado = 'activa'
                  AND CURDATE() BETWEEN ia2.fecha_inicio AND ia2.fecha_fin
                ORDER BY ia2.fecha_fin DESC, ia2.id DESC
                LIMIT 1
            )
         LEFT JOIN planes pa
            ON pa.id = ia.plan_id
         WHERE c.estado = 'activo'
           AND (
                LOWER(CONCAT_WS(' ', TRIM(c.nombre), TRIM(c.apellido)))
                    LIKE LOWER(?)
                OR LOWER(TRIM(c.nombre)) LIKE LOWER(?)
                OR LOWER(TRIM(c.apellido)) LIKE LOWER(?)
                OR COALESCE(c.telefono, '') LIKE ?
                OR LOWER(COALESCE(c.email, '')) LIKE LOWER(?)
                OR COALESCE(c.codigo_qr, '') LIKE ?
           )
         ORDER BY
            CASE
                WHEN COALESCE(c.codigo_qr, '') = ? THEN 0
                WHEN COALESCE(c.telefono, '') = ? THEN 1
                WHEN LOWER(CONCAT_WS(' ', TRIM(c.nombre), TRIM(c.apellido)))
                    = LOWER(TRIM(?)) THEN 2
                ELSE 3
            END,
            CASE WHEN ia.id IS NULL THEN 0 ELSE 1 END,
            ultima_visita DESC,
            c.nombre ASC,
            c.apellido ASC
         LIMIT 8"
    );

    if (!$stmt) {
        throw new RuntimeException('No fue posible preparar la búsqueda.');
    }

    $stmt->bind_param(
        'sssssssss',
        $patron,
        $patron,
        $patron,
        $patron,
        $patron,
        $patron,
        $q,
        $q,
        $q
    );
    $stmt->execute();
    $resultado = $stmt->get_result();
    $personas = [];

    while ($fila = $resultado->fetch_assoc()) {
        $membresiaActivaId = (int) ($fila['membresia_activa_id'] ?? 0);

        $personas[] = [
            'id' => (int) $fila['id'],
            'nombre' => (string) $fila['nombre'],
            'apellido' => (string) $fila['apellido'],
            'telefono' => (string) $fila['telefono'],
            'email' => (string) $fila['email'],
            'contacto_emergencia_nombre' =>
                (string) $fila['contacto_emergencia_nombre'],
            'contacto_emergencia_telefono' =>
                (string) $fila['contacto_emergencia_telefono'],
            'codigo_qr' => (string) $fila['codigo_qr'],
            'sucursal' => (string) $fila['sucursal'],
            'ultima_visita' => (string) ($fila['ultima_visita'] ?? ''),
            'tiene_membresia_activa' => $membresiaActivaId > 0,
            'membresia_activa_id' => $membresiaActivaId,
            'membresia_activa_plan' =>
                (string) ($fila['membresia_activa_plan'] ?? ''),
            'membresia_activa_fecha_fin' =>
                (string) ($fila['membresia_activa_fecha_fin'] ?? ''),
            'membresia_activa_es_visita' =>
                (int) ($fila['membresia_activa_duracion'] ?? 0) === 1,
            'ultima_membresia_vencida' =>
                (string) ($fila['ultima_membresia_vencida'] ?? ''),
        ];
    }

    $stmt->close();
} catch (Throwable $e) {
    error_log('[Dashboard buscar visitantes] ' . $e->getMessage());
    dashboard_visitas_responder([
        'success' => false,
        'message' => 'No fue posible consultar las personas registradas.',
    ], 500);
}

dashboard_visitas_responder([
    'success' => true,
    'personas' => $personas,
]);
