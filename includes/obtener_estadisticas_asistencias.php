<?php
declare(strict_types=1);

require_once __DIR__ . '/asistencia_context.php';

try {
    $contexto = asistencia_contexto();

    $conn = $contexto['conn'];
    $sucursalId = (int) $contexto['sucursal_id'];
    $vistaGlobal = (bool) $contexto['vista_global'];

    $sqlAsistencias =
        "SELECT COUNT(*) AS total
         FROM asistencias
         WHERE fecha = CURDATE()";

    if (!$vistaGlobal) {
        $sqlAsistencias .=
            " AND sucursal_id = ?";
    }

    $stmt = $conn->prepare($sqlAsistencias);

    if (!$vistaGlobal) {
        $stmt->bind_param('i', $sucursalId);
    }

    $stmt->execute();

    $totalAsistencias = (int) (
        $stmt->get_result()->fetch_assoc()['total']
        ?? 0
    );

    $stmt->close();

    /*
     * Los socios con acceso son globales porque la membresía vigente
     * permite entrar a cualquier sucursal activa.
     */
    $resultActivos = $conn->query(
        "SELECT COUNT(DISTINCT c.id) AS total
         FROM clientes c
         INNER JOIN inscripciones i
            ON i.cliente_id = c.id
         WHERE c.estado = 'activo'
           AND i.estado = 'activa'
           AND i.fecha_inicio <= CURDATE()
           AND (
                i.fecha_fin IS NULL
                OR i.fecha_fin >= CURDATE()
           )"
    );

    $sociosConAcceso = (int) (
        $resultActivos->fetch_assoc()['total']
        ?? 0
    );

    $sqlDenegadas =
        "SELECT COUNT(*) AS total
         FROM asistencias_denegadas
         WHERE fecha = CURDATE()";

    if (!$vistaGlobal) {
        $sqlDenegadas .=
            " AND sucursal_id = ?";
    }

    $stmt = $conn->prepare($sqlDenegadas);

    if (!$vistaGlobal) {
        $stmt->bind_param('i', $sucursalId);
    }

    $stmt->execute();

    $denegadas = (int) (
        $stmt->get_result()->fetch_assoc()['total']
        ?? 0
    );

    $stmt->close();

    asistencia_ok([
        'total_asistencias' =>
            $totalAsistencias,
        'clientes_activos' =>
            $sociosConAcceso,
        'asistencias_denegadas' =>
            $denegadas,
        'fecha' => date('Y-m-d'),
        'hora_servidor' => date('H:i:s'),
        'vista_global' => $vistaGlobal,
    ]);
} catch (Throwable $error) {
    error_log(
        '[Estadísticas asistencia] ' .
        $error->getMessage()
    );

    asistencia_error(
        'No se pudieron cargar las estadísticas.',
        500
    );
}
