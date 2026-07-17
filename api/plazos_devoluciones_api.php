<?php
// Archivo: api/plazos_devoluciones_api.php
// Consulta la política configurada y calcula plazos por venta.

session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function responderPlazos(int $codigo, array $respuesta): void
{
    http_response_code($codigo);

    echo json_encode(
        $respuesta,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit();
}

if (!isset($_SESSION['user_id'])) {
    responderPlazos(401, [
        'success' => false,
        'message' => 'No autorizado.',
    ]);
}

require_once __DIR__ . '/../config/database.php';

const LIMITE_TECNICO_TARJETA_DIAS = 90;

/**
 * @return array<string,mixed>
 */
function obtenerPoliticaPlazos(mysqli $conn): array
{
    $resultado = $conn->query(
        "SELECT *
         FROM configuracion_devoluciones
         WHERE id = 1
         LIMIT 1"
    );

    if (!$resultado) {
        throw new RuntimeException(
            'No se pudo consultar configuracion_devoluciones: ' .
            $conn->error
        );
    }

    $politica = $resultado->fetch_assoc();

    if (!$politica) {
        throw new RuntimeException(
            'No existe la configuración de devoluciones con id = 1.'
        );
    }

    $camposNumericos = [
        'activo',
        'permitir_cancelaciones',
        'permitir_devoluciones',
        'dias_cancelacion_efectivo',
        'dias_devolucion_efectivo',
        'dias_cancelacion_tarjeta',
        'dias_devolucion_tarjeta',
        'dias_cancelacion_transferencia',
        'dias_devolucion_transferencia',
    ];

    foreach ($camposNumericos as $campo) {
        $politica[$campo] = (int) ($politica[$campo] ?? 0);
    }

    $politica['observaciones'] = (string) (
        $politica['observaciones'] ?? ''
    );

    return $politica;
}

/**
 * @return array{configurados:int,efectivos:int}
 */
function obtenerLimiteMetodo(
    array $politica,
    string $accion,
    string $metodo
): array {
    $campo = 'dias_' . $accion . '_' . $metodo;
    $configurados = max(
        0,
        (int) ($politica[$campo] ?? 0)
    );

    $efectivos = $metodo === 'tarjeta'
        ? min($configurados, LIMITE_TECNICO_TARJETA_DIAS)
        : $configurados;

    return [
        'configurados' => $configurados,
        'efectivos' => $efectivos,
    ];
}

try {
    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn instanceof mysqli) {
        throw new RuntimeException(
            'No fue posible conectar con la base de datos.'
        );
    }

    $conn->set_charset('utf8mb4');

    $accion = trim((string) ($_GET['action'] ?? ''));
    $politica = obtenerPoliticaPlazos($conn);

    if ($accion === 'politica') {
        $metodos = [];

        foreach (
            ['efectivo', 'tarjeta', 'transferencia'] as $metodo
        ) {
            $cancelacion = obtenerLimiteMetodo(
                $politica,
                'cancelacion',
                $metodo
            );

            $devolucion = obtenerLimiteMetodo(
                $politica,
                'devolucion',
                $metodo
            );

            $metodos[$metodo] = [
                'dias_cancelacion_configurados' =>
                    $cancelacion['configurados'],
                'dias_cancelacion_efectivos' =>
                    $cancelacion['efectivos'],
                'dias_devolucion_configurados' =>
                    $devolucion['configurados'],
                'dias_devolucion_efectivos' =>
                    $devolucion['efectivos'],
            ];
        }

        responderPlazos(200, [
            'success' => true,
            'politica' => [
                'activo' => (bool) $politica['activo'],
                'permitir_cancelaciones' =>
                    (bool) $politica['permitir_cancelaciones'],
                'permitir_devoluciones' =>
                    (bool) $politica['permitir_devoluciones'],
                'limite_tecnico_tarjeta' =>
                    LIMITE_TECNICO_TARJETA_DIAS,
                'observaciones' =>
                    $politica['observaciones'],
                'fecha_actualizacion' =>
                    $politica['fecha_actualizacion'] ?? null,
                'metodos' => $metodos,
            ],
        ]);
    }

    if ($accion !== 'ventas') {
        responderPlazos(400, [
            'success' => false,
            'message' => 'Acción no válida.',
        ]);
    }

    $idsTexto = (string) ($_GET['venta_ids'] ?? '');

    $ventaIds = array_values(array_unique(array_filter(
        array_map(
            'intval',
            preg_split('/\s*,\s*/', $idsTexto) ?: []
        ),
        static fn (int $id): bool => $id > 0
    )));

    if (count($ventaIds) === 0) {
        responderPlazos(200, [
            'success' => true,
            'plazos' => [],
        ]);
    }

    if (count($ventaIds) > 100) {
        responderPlazos(400, [
            'success' => false,
            'message' =>
                'Solo se pueden validar hasta 100 ventas por solicitud.',
        ]);
    }

    /*
     * Todos los valores se convierten previamente a enteros positivos.
     */
    $listaIds = implode(',', $ventaIds);

    $sql = "SELECT
                v.id,
                v.estado,
                v.metodo_pago,
                v.fecha_venta,
                mp.order_id,
                mp.payment_id,
                mp.created_at AS mp_created_at,
                CASE
                    WHEN v.metodo_pago = 'tarjeta'
                        THEN mp.created_at
                    ELSE v.fecha_venta
                END AS fecha_base,
                TIMESTAMPDIFF(
                    DAY,
                    CASE
                        WHEN v.metodo_pago = 'tarjeta'
                            THEN mp.created_at
                        ELSE v.fecha_venta
                    END,
                    NOW()
                ) AS dias_transcurridos
            FROM ventas v
            LEFT JOIN mercadopago_operaciones mp
                ON mp.id = (
                    SELECT mo.id
                    FROM mercadopago_operaciones mo
                    WHERE mo.venta_id = v.id
                    ORDER BY mo.id DESC
                    LIMIT 1
                )
            WHERE v.id IN ($listaIds)";

    $resultado = $conn->query($sql);

    if (!$resultado) {
        throw new RuntimeException(
            'No se pudieron calcular los plazos: ' .
            $conn->error
        );
    }

    $plazos = [];

    while ($venta = $resultado->fetch_assoc()) {
        $ventaId = (int) $venta['id'];
        $metodo = strtolower(trim(
            (string) $venta['metodo_pago']
        ));
        $estado = strtolower(trim(
            (string) $venta['estado']
        ));

        $cancelacion = obtenerLimiteMetodo(
            $politica,
            'cancelacion',
            $metodo
        );

        $devolucion = obtenerLimiteMetodo(
            $politica,
            'devolucion',
            $metodo
        );

        $dias = $venta['dias_transcurridos'] !== null
            ? (int) $venta['dias_transcurridos']
            : null;

        $puedeCancelar = true;
        $puedeDevolver = true;
        $motivoCancelar = '';
        $motivoDevolver = '';

        if ((int) $politica['activo'] !== 1) {
            $puedeCancelar = false;
            $puedeDevolver = false;
            $motivoCancelar =
                'Las cancelaciones y devoluciones están desactivadas.';
            $motivoDevolver = $motivoCancelar;
        } elseif ($estado !== 'completada') {
            $puedeCancelar = false;
            $puedeDevolver = false;
            $motivoCancelar =
                'La venta ya no está en estado completada.';
            $motivoDevolver = $motivoCancelar;
        } elseif (
            !in_array(
                $metodo,
                ['efectivo', 'tarjeta', 'transferencia'],
                true
            )
        ) {
            $puedeCancelar = false;
            $puedeDevolver = false;
            $motivoCancelar =
                'El método de pago no admite esta validación.';
            $motivoDevolver = $motivoCancelar;
        } elseif (
            $metodo === 'tarjeta' &&
            (
                empty($venta['mp_created_at']) ||
                empty($venta['order_id']) ||
                empty($venta['payment_id'])
            )
        ) {
            $puedeCancelar = false;
            $puedeDevolver = false;
            $motivoCancelar =
                'La venta de tarjeta no tiene una operación de Mercado Pago vinculada.';
            $motivoDevolver = $motivoCancelar;
        } elseif ($dias === null || $dias < 0) {
            $puedeCancelar = false;
            $puedeDevolver = false;
            $motivoCancelar =
                'No se pudo determinar la antigüedad de la venta.';
            $motivoDevolver = $motivoCancelar;
        } else {
            if (
                (int) $politica['permitir_cancelaciones'] !== 1
            ) {
                $puedeCancelar = false;
                $motivoCancelar =
                    'Las cancelaciones están desactivadas por configuración.';
            } elseif ($dias > $cancelacion['efectivos']) {
                $puedeCancelar = false;
                $motivoCancelar =
                    'Han transcurrido ' . $dias .
                    ' día(s) y el límite para cancelar es de ' .
                    $cancelacion['efectivos'] . ' día(s).';
            }

            if (
                (int) $politica['permitir_devoluciones'] !== 1
            ) {
                $puedeDevolver = false;
                $motivoDevolver =
                    'Las devoluciones parciales están desactivadas por configuración.';
            } elseif ($dias > $devolucion['efectivos']) {
                $puedeDevolver = false;
                $motivoDevolver =
                    'Han transcurrido ' . $dias .
                    ' día(s) y el límite para devolver es de ' .
                    $devolucion['efectivos'] . ' día(s).';
            }
        }

        $plazos[(string) $ventaId] = [
            'venta_id' => $ventaId,
            'metodo_pago' => $metodo,
            'fecha_base' => $venta['fecha_base'],
            'dias_transcurridos' => $dias,
            'dias_cancelacion_configurados' =>
                $cancelacion['configurados'],
            'dias_cancelacion_efectivos' =>
                $cancelacion['efectivos'],
            'dias_devolucion_configurados' =>
                $devolucion['configurados'],
            'dias_devolucion_efectivos' =>
                $devolucion['efectivos'],
            'dias_restantes_cancelacion' =>
                $dias !== null
                    ? max(0, $cancelacion['efectivos'] - $dias)
                    : 0,
            'dias_restantes_devolucion' =>
                $dias !== null
                    ? max(0, $devolucion['efectivos'] - $dias)
                    : 0,
            'puede_cancelar' => $puedeCancelar,
            'puede_devolver' => $puedeDevolver,
            'motivo_cancelacion' => $motivoCancelar,
            'motivo_devolucion' => $motivoDevolver,
        ];
    }

    responderPlazos(200, [
        'success' => true,
        'plazos' => $plazos,
    ]);
} catch (Throwable $error) {
    error_log(
        'Error plazos_devoluciones_api: ' .
        $error->getMessage()
    );

    responderPlazos(500, [
        'success' => false,
        'message' => $error->getMessage(),
    ]);
}
