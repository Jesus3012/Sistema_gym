<?php
declare(strict_types=1);

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/sucursal_context.php';
require_once __DIR__ . '/../includes/ventas_operaciones_multisucursal.php';

function responderPlazos(int $status, array $data): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_INVALID_UTF8_SUBSTITUTE
    );

    exit;
}

/**
 * Repara operaciones Point aprobadas que quedaron sin venta_id.
 *
 * La reparación solo se realiza cuando existe una coincidencia única:
 * - misma sucursal;
 * - mismo usuario;
 * - origen venta;
 * - mismo importe;
 * - pago y orden procesados;
 * - diferencia máxima de tres minutos;
 * - una sola venta candidata para esa operación.
 *
 * Nunca se vincula únicamente por monto.
 *
 * @param int[] $ventaIds
 */
function repararVinculosMercadoPagoVentas(
    mysqli $conn,
    array $ventaIds,
    bool $vistaGlobal,
    bool $esAdmin,
    int $sucursalId,
    int $usuarioId
): int {
    if ($ventaIds === []) {
        return 0;
    }

    $lista = implode(
        ',',
        array_map(
            static fn (int $id): string => (string) $id,
            $ventaIds
        )
    );

    $condiciones = [
        "v.id IN ({$lista})",
        "v.metodo_pago = 'tarjeta'",
        "v.estado = 'completada'",
        "NOT EXISTS (
            SELECT 1
            FROM mercadopago_operaciones vinculada
            WHERE vinculada.venta_id = v.id
        )",
    ];

    if (!$vistaGlobal) {
        $condiciones[] = 'v.sucursal_id = ' . max(0, $sucursalId);
    }

    if (!$esAdmin) {
        $condiciones[] = 'v.usuario_id = ' . max(0, $usuarioId);
    }

    $sqlVentas = "SELECT
            v.id,
            v.sucursal_id,
            v.usuario_id,
            v.fecha_venta,
            v.total
        FROM ventas v
        WHERE " . implode(' AND ', $condiciones);

    $resultadoVentas = $conn->query($sqlVentas);

    if (!$resultadoVentas instanceof mysqli_result) {
        throw new RuntimeException(
            'No fue posible consultar ventas pendientes de vincular: '
            . $conn->error
        );
    }

    $ventas = $resultadoVentas->fetch_all(MYSQLI_ASSOC);
    $resultadoVentas->free();

    if ($ventas === []) {
        return 0;
    }

    $sqlCandidata = "SELECT
            mo.id,
            mo.order_id,
            mo.payment_id,
            ABS(
                TIMESTAMPDIFF(
                    SECOND,
                    mo.created_at,
                    ?
                )
            ) AS diferencia_segundos
        FROM mercadopago_operaciones mo
        WHERE mo.venta_id IS NULL
          AND mo.sucursal_id = ?
          AND mo.usuario_id = ?
          AND mo.origen = 'venta'
          AND mo.order_status = 'processed'
          AND mo.payment_status = 'processed'
          AND NULLIF(TRIM(mo.order_id), '') IS NOT NULL
          AND NULLIF(TRIM(mo.payment_id), '') IS NOT NULL
          AND ABS(mo.amount - ?) <= 0.01
          AND mo.paid_amount + 0.01 >= ?
          AND mo.created_at BETWEEN
              DATE_SUB(?, INTERVAL 3 MINUTE)
              AND DATE_ADD(?, INTERVAL 3 MINUTE)
          AND (
              SELECT COUNT(*)
              FROM ventas posible
              WHERE posible.metodo_pago = 'tarjeta'
                AND posible.estado = 'completada'
                AND posible.sucursal_id = mo.sucursal_id
                AND posible.usuario_id = mo.usuario_id
                AND ABS(posible.total - mo.amount) <= 0.01
                AND posible.fecha_venta BETWEEN
                    DATE_SUB(mo.created_at, INTERVAL 3 MINUTE)
                    AND DATE_ADD(mo.created_at, INTERVAL 3 MINUTE)
                AND NOT EXISTS (
                    SELECT 1
                    FROM mercadopago_operaciones ya_vinculada
                    WHERE ya_vinculada.venta_id = posible.id
                )
          ) = 1
        ORDER BY diferencia_segundos ASC, mo.id DESC
        LIMIT 2";

    $stmtCandidata = $conn->prepare($sqlCandidata);

    if (!$stmtCandidata) {
        throw new RuntimeException(
            'No fue posible preparar la recuperación de pagos Point: '
            . $conn->error
        );
    }

    $stmtVincular = $conn->prepare(
        "UPDATE mercadopago_operaciones
         SET
            venta_id = ?,
            origen = 'venta',
            updated_at = NOW()
         WHERE id = ?
           AND venta_id IS NULL
           AND order_status = 'processed'
           AND payment_status = 'processed'"
    );

    if (!$stmtVincular) {
        $stmtCandidata->close();

        throw new RuntimeException(
            'No fue posible preparar el vínculo de Mercado Pago: '
            . $conn->error
        );
    }

    $reparados = 0;

    foreach ($ventas as $venta) {
        $ventaId = (int) ($venta['id'] ?? 0);
        $branchId = (int) ($venta['sucursal_id'] ?? 0);
        $sellerId = (int) ($venta['usuario_id'] ?? 0);
        $fechaVenta = trim((string) ($venta['fecha_venta'] ?? ''));
        $total = round((float) ($venta['total'] ?? 0), 2);

        if (
            $ventaId <= 0
            || $branchId <= 0
            || $sellerId <= 0
            || $fechaVenta === ''
            || $total <= 0
        ) {
            continue;
        }

        $stmtCandidata->bind_param(
            'siiddss',
            $fechaVenta,
            $branchId,
            $sellerId,
            $total,
            $total,
            $fechaVenta,
            $fechaVenta
        );

        $stmtCandidata->execute();

        $candidatas = $stmtCandidata
            ->get_result()
            ->fetch_all(MYSQLI_ASSOC);

        /*
         * Debe existir exactamente una coincidencia.
         * Con cero o más de una no se modifica nada.
         */
        if (count($candidatas) !== 1) {
            continue;
        }

        $operacionId = (int) ($candidatas[0]['id'] ?? 0);

        if ($operacionId <= 0) {
            continue;
        }

        $stmtVincular->bind_param(
            'ii',
            $ventaId,
            $operacionId
        );

        $stmtVincular->execute();

        if ($stmtVincular->affected_rows === 1) {
            $reparados++;

            error_log(
                sprintf(
                    '[Mercado Pago] Operación #%d vinculada automáticamente con venta #%d.',
                    $operacionId,
                    $ventaId
                )
            );
        }
    }

    $stmtCandidata->close();
    $stmtVincular->close();

    return $reparados;
}

if (empty($_SESSION['user_id'])) {
    responderPlazos(
        401,
        [
            'success' => false,
            'message' => 'No autorizado.',
        ]
    );
}

$database = new Database();
$conn = $database->getConnection();

if (!$conn instanceof mysqli) {
    responderPlazos(
        500,
        [
            'success' => false,
            'message' => 'No fue posible conectar con la base de datos.',
        ]
    );
}

$conn->set_charset('utf8mb4');

$action = trim((string) ($_GET['action'] ?? ''));
$userId = (int) $_SESSION['user_id'];
$isAdmin = ventas_multi_es_admin();
$isGlobal = ventas_multi_vista_global();
$sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);

try {
    if ($action === 'politica') {
        $politica = ventas_multi_obtener_politica(
            $conn,
            $sucursalId
        );

        $metodos = [];

        foreach (
            ['efectivo', 'tarjeta', 'transferencia']
            as $metodo
        ) {
            $cancelar = max(
                0,
                (int) (
                    $politica[
                        'dias_cancelacion_' . $metodo
                    ] ?? 0
                )
            );

            $devolver = max(
                0,
                (int) (
                    $politica[
                        'dias_devolucion_' . $metodo
                    ] ?? 0
                )
            );

            if ($metodo === 'tarjeta') {
                $cancelar = min(
                    $cancelar,
                    VENTAS_LIMITE_TECNICO_TARJETA_DIAS
                );

                $devolver = min(
                    $devolver,
                    VENTAS_LIMITE_TECNICO_TARJETA_DIAS
                );
            }

            $metodos[$metodo] = [
                'dias_cancelacion_efectivos' =>
                    $cancelar,
                'dias_devolucion_efectivos' =>
                    $devolver,
            ];
        }

        responderPlazos(
            200,
            [
                'success' => true,
                'scope' => $isGlobal
                    ? 'global'
                    : 'sucursal',
                'politica' => [
                    'activo' => (bool) (
                        $politica['activo'] ?? false
                    ),
                    'permitir_cancelaciones' =>
                        (bool) (
                            $politica[
                                'permitir_cancelaciones'
                            ] ?? false
                        ),
                    'permitir_devoluciones' =>
                        (bool) (
                            $politica[
                                'permitir_devoluciones'
                            ] ?? false
                        ),
                    'metodos' => $metodos,
                ],
            ]
        );
    }

    if ($action !== 'ventas') {
        responderPlazos(
            400,
            [
                'success' => false,
                'message' => 'Acción no válida.',
            ]
        );
    }

    $ids = array_values(
        array_unique(
            array_filter(
                array_map(
                    'intval',
                    preg_split(
                        '/\s*,\s*/',
                        (string) (
                            $_GET['venta_ids'] ?? ''
                        )
                    ) ?: []
                ),
                static fn (int $id): bool => $id > 0
            )
        )
    );

    if ($ids === []) {
        responderPlazos(
            200,
            [
                'success' => true,
                'plazos' => [],
            ]
        );
    }

    if (count($ids) > 100) {
        responderPlazos(
            400,
            [
                'success' => false,
                'message' =>
                    'Solo se permiten 100 ventas por solicitud.',
            ]
        );
    }

    /*
     * Recupera vínculos huérfanos antes de calcular los plazos.
     * Solo modifica registros cuando la coincidencia es única.
     */
    $vinculosReparados =
        repararVinculosMercadoPagoVentas(
            $conn,
            $ids,
            $isGlobal,
            $isAdmin,
            $sucursalId,
            $userId
        );

    $list = implode(',', $ids);
    $conditions = ["v.id IN ({$list})"];

    if (!$isGlobal) {
        $conditions[] =
            'v.sucursal_id = ' . $sucursalId;
    }

    if (!$isAdmin) {
        $conditions[] =
            'v.usuario_id = ' . $userId;
    }

    $sql = "SELECT
                v.id,
                v.sucursal_id,
                v.usuario_id,
                v.estado,
                v.metodo_pago,
                v.fecha_venta,
                mp.order_id,
                mp.payment_id,
                mp.order_status,
                mp.payment_status,
                mp.created_at AS mp_created_at
            FROM ventas v
            LEFT JOIN mercadopago_operaciones mp
              ON mp.id = (
                  SELECT mo.id
                  FROM mercadopago_operaciones mo
                  WHERE mo.venta_id = v.id
                  ORDER BY mo.id DESC
                  LIMIT 1
              )
            WHERE " . implode(' AND ', $conditions);

    $result = $conn->query($sql);
    $plazos = [];
    $politicas = [];
    $now = new DateTimeImmutable('now');

    while ($venta = $result->fetch_assoc()) {
        $branch = (int) $venta['sucursal_id'];

        if (!isset($politicas[$branch])) {
            $politicas[$branch] =
                ventas_multi_obtener_politica(
                    $conn,
                    $branch
                );
        }

        $politica = $politicas[$branch];
        $metodo = strtolower(
            (string) $venta['metodo_pago']
        );
        $estado = strtolower(
            (string) $venta['estado']
        );

        $limCancel = max(
            0,
            (int) (
                $politica[
                    'dias_cancelacion_' . $metodo
                ] ?? 0
            )
        );

        $limReturn = max(
            0,
            (int) (
                $politica[
                    'dias_devolucion_' . $metodo
                ] ?? 0
            )
        );

        if ($metodo === 'tarjeta') {
            $limCancel = min(
                $limCancel,
                VENTAS_LIMITE_TECNICO_TARJETA_DIAS
            );

            $limReturn = min(
                $limReturn,
                VENTAS_LIMITE_TECNICO_TARJETA_DIAS
            );
        }

        $fechaBase = $metodo === 'tarjeta'
            ? ($venta['mp_created_at'] ?? null)
            : $venta['fecha_venta'];

        $dias = null;

        if ($fechaBase) {
            $start = new DateTimeImmutable(
                (string) $fechaBase
            );

            if ($start <= $now) {
                $dias = (int) $start
                    ->diff($now)
                    ->format('%a');
            }
        }

        $baseOk =
            (int) ($politica['activo'] ?? 0) === 1
            && $estado === 'completada'
            && in_array(
                $metodo,
                [
                    'efectivo',
                    'tarjeta',
                    'transferencia',
                ],
                true
            )
            && $dias !== null;

        $cardOk = $metodo !== 'tarjeta'
            || (
                !empty($venta['order_id'])
                && !empty($venta['payment_id'])
                && (string) (
                    $venta['order_status'] ?? ''
                ) === 'processed'
                && (string) (
                    $venta['payment_status'] ?? ''
                ) === 'processed'
            );

        $puedeCancelar =
            $baseOk
            && $cardOk
            && (int) (
                $politica[
                    'permitir_cancelaciones'
                ] ?? 0
            ) === 1
            && $dias <= $limCancel;

        $puedeDevolver =
            $baseOk
            && $cardOk
            && (int) (
                $politica[
                    'permitir_devoluciones'
                ] ?? 0
            ) === 1
            && $dias <= $limReturn;

        $motivoBase = '';

        if (
            (int) ($politica['activo'] ?? 0)
            !== 1
        ) {
            $motivoBase =
                'Las cancelaciones y devoluciones están desactivadas en esta sucursal.';
        } elseif ($estado !== 'completada') {
            $motivoBase =
                'La venta ya no está en estado completada.';
        } elseif (!$cardOk) {
            $motivoBase =
                'La venta de tarjeta no tiene un pago Point procesado y vinculado.';
        } elseif ($dias === null) {
            $motivoBase =
                'No se pudo determinar la antigüedad de la venta.';
        }

        $motivoCancelar = $puedeCancelar
            ? ''
            : (
                $motivoBase
                ?: (
                    (int) (
                        $politica[
                            'permitir_cancelaciones'
                        ] ?? 0
                    ) !== 1
                        ? 'Las cancelaciones están desactivadas en esta sucursal.'
                        : 'El plazo de cancelación de '
                            . $limCancel
                            . ' día(s) ya venció.'
                )
            );

        $motivoDevolver = $puedeDevolver
            ? ''
            : (
                $motivoBase
                ?: (
                    (int) (
                        $politica[
                            'permitir_devoluciones'
                        ] ?? 0
                    ) !== 1
                        ? 'Las devoluciones están desactivadas en esta sucursal.'
                        : 'El plazo de devolución de '
                            . $limReturn
                            . ' día(s) ya venció.'
                )
            );

        $plazos[(string) $venta['id']] = [
            'venta_id' => (int) $venta['id'],
            'sucursal_id' => $branch,
            'metodo_pago' => $metodo,
            'dias_transcurridos' => $dias,
            'dias_restantes_cancelacion' =>
                $dias !== null
                    ? max(0, $limCancel - $dias)
                    : 0,
            'dias_restantes_devolucion' =>
                $dias !== null
                    ? max(0, $limReturn - $dias)
                    : 0,
            'puede_cancelar' => $puedeCancelar,
            'puede_devolver' => $puedeDevolver,
            'motivo_cancelacion' => $motivoCancelar,
            'motivo_devolucion' => $motivoDevolver,
        ];
    }

    responderPlazos(
        200,
        [
            'success' => true,
            'plazos' => $plazos,
            'vinculos_mp_reparados' =>
                $vinculosReparados,
        ]
    );
} catch (Throwable $error) {
    error_log(
        '[plazos_devoluciones_api] '
        . $error->getMessage()
    );

    responderPlazos(
        500,
        [
            'success' => false,
            'message' => $error->getMessage(),
        ]
    );
}
