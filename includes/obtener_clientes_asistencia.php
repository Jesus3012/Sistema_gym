<?php
declare(strict_types=1);

require_once __DIR__ . '/asistencia_context.php';

try {
    $contexto = asistencia_contexto();
    asistencia_exigir_sede_concreta($contexto);

    $conn = $contexto['conn'];
    $sucursalId = (int) $contexto['sucursal_id'];

    $tipo = strtolower(trim((string) (
        $_POST['tipo'] ?? 'todos'
    )));

    $filtro = trim((string) (
        $_POST['filtro'] ?? ''
    ));

    if (!in_array(
        $tipo,
        ['todos', 'recientes', 'vencer', 'buscar'],
        true
    )) {
        $tipo = 'todos';
    }

    /*
     * Se obtiene la mejor membresía activa del socio sin filtrar por sede.
     */
    $sql = "SELECT
                c.id,
                c.nombre,
                c.apellido,
                c.telefono,
                i.id AS inscripcion_id,
                i.fecha_fin,
                p.nombre AS plan_nombre,
                CASE
                    WHEN i.fecha_fin IS NULL THEN NULL
                    ELSE GREATEST(
                        DATEDIFF(i.fecha_fin, CURDATE()),
                        0
                    )
                END AS dias_restantes
            FROM clientes c
            LEFT JOIN inscripciones i
                ON i.id = (
                    SELECT i2.id
                    FROM inscripciones i2
                    WHERE i2.cliente_id = c.id
                      AND i2.estado = 'activa'
                      AND i2.fecha_inicio <= CURDATE()
                      AND (
                           i2.fecha_fin IS NULL
                           OR i2.fecha_fin >= CURDATE()
                      )
                    ORDER BY
                        CASE
                            WHEN i2.fecha_fin IS NULL
                            THEN 1
                            ELSE 0
                        END DESC,
                        i2.fecha_fin DESC,
                        i2.id DESC
                    LIMIT 1
                )
            LEFT JOIN planes p
                ON p.id = i.plan_id
            WHERE c.estado = 'activo'";

    $params = [];
    $types = '';

    if ($tipo === 'recientes') {
        /*
         * "Asistieron hoy" sí se limita a la sucursal operativa.
         */
        $sql .= "
            AND EXISTS (
                SELECT 1
                FROM asistencias a
                WHERE a.cliente_id = c.id
                  AND a.sucursal_id = ?
                  AND a.fecha = CURDATE()
            )";

        $params[] = $sucursalId;
        $types .= 'i';
    }

    if ($tipo === 'vencer') {
        $sql .= "
            AND i.id IS NOT NULL
            AND i.fecha_fin BETWEEN
                CURDATE()
                AND DATE_ADD(
                    CURDATE(),
                    INTERVAL 7 DAY
                )";
    }

    if ($tipo === 'buscar' || $filtro !== '') {
        $like = '%' . $filtro . '%';

        $sql .= "
            AND (
                c.nombre LIKE ?
                OR c.apellido LIKE ?
                OR c.telefono LIKE ?
            )";

        array_push(
            $params,
            $like,
            $like,
            $like
        );

        $types .= 'sss';
    }

    $sql .= "
            ORDER BY
                c.nombre ASC,
                c.apellido ASC
            LIMIT 150";

    $stmt = $conn->prepare($sql);

    if ($params !== []) {
        $stmt->bind_param(
            $types,
            ...$params
        );
    }

    $stmt->execute();

    $rows = $stmt
        ->get_result()
        ->fetch_all(MYSQLI_ASSOC);

    $stmt->close();

    $clientes = [];

    foreach ($rows as $row) {
        $tienePlan =
            (int) ($row['inscripcion_id'] ?? 0) > 0;

        $clientes[] = [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
            'apellido' => (string) $row['apellido'],
            'telefono' => (string) (
                $row['telefono'] ?? ''
            ),
            'plan_nombre' =>
                $tienePlan
                    ? (string) (
                        $row['plan_nombre']
                        ?? 'Plan activo'
                    )
                    : null,
            'dias_restantes' =>
                $row['dias_restantes'] === null
                    ? null
                    : (int) $row['dias_restantes'],
            'tiene_plan' => $tienePlan,
        ];
    }

    asistencia_ok([
        'clientes' => $clientes,
    ]);
} catch (Throwable $error) {
    error_log(
        '[Clientes asistencia] ' .
        $error->getMessage()
    );

    asistencia_error(
        $error->getMessage(),
        500
    );
}
