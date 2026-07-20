<?php
declare(strict_types=1);

require_once __DIR__ . '/asistencia_context.php';

try {
    $contexto = asistencia_contexto();

    $conn = $contexto['conn'];
    $sucursalId = (int) $contexto['sucursal_id'];
    $vistaGlobal = (bool) $contexto['vista_global'];

    $sql = "SELECT
                a.id,
                a.cliente_id,
                a.hora_entrada,
                a.hora_salida,
                a.metodo_registro,
                a.dias_restantes,
                a.plan_nombre,
                c.nombre,
                c.apellido,
                c.telefono,
                s.nombre AS sucursal_nombre,
                s.clave AS sucursal_clave,
                s.es_matriz AS sucursal_es_matriz
            FROM asistencias a
            INNER JOIN clientes c
                ON c.id = a.cliente_id
            INNER JOIN sucursales s
                ON s.id = a.sucursal_id
            WHERE a.fecha = CURDATE()";

    if (!$vistaGlobal) {
        $sql .=
            " AND a.sucursal_id = ?";
    }

    $sql .= "
            ORDER BY
                a.hora_entrada DESC,
                a.id DESC
            LIMIT 250";

    $stmt = $conn->prepare($sql);

    if (!$vistaGlobal) {
        $stmt->bind_param('i', $sucursalId);
    }

    $stmt->execute();

    $rows = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    $asistencias = [];

    foreach ($rows as $row) {
        $asistencias[] = [
            'id' => (int) $row['id'],
            'cliente_id' =>
                (int) $row['cliente_id'],
            'nombre' =>
                (string) $row['nombre'],
            'apellido' =>
                (string) $row['apellido'],
            'telefono' =>
                (string) (
                    $row['telefono'] ?? ''
                ),
            'hora_entrada' =>
                (string) (
                    $row['hora_entrada'] ?? ''
                ),
            'hora_salida' =>
                $row['hora_salida'] === null
                    ? null
                    : (string) $row['hora_salida'],
            'metodo_registro' =>
                (string) (
                    $row['metodo_registro']
                    ?? 'manual'
                ),
            'plan_nombre' =>
                (string) (
                    $row['plan_nombre']
                    ?? 'Sin plan'
                ),
            'dias_restantes' =>
                $row['dias_restantes'] === null
                    ? null
                    : (int) $row['dias_restantes'],
            'sucursal_nombre' =>
                (string) (
                    $row['sucursal_nombre']
                    ?? 'Sucursal'
                ),
            'sucursal_clave' =>
                (string) (
                    $row['sucursal_clave']
                    ?? 'SEDE'
                ),
            'sucursal_es_matriz' =>
                (int) (
                    $row['sucursal_es_matriz']
                    ?? 0
                ),
        ];
    }

    asistencia_ok([
        'data' => $asistencias,
        'vista_global' => $vistaGlobal,
    ]);
} catch (Throwable $error) {
    error_log(
        '[Listado asistencia] ' .
        $error->getMessage()
    );

    asistencia_error(
        'No se pudieron cargar las asistencias.',
        500
    );
}
