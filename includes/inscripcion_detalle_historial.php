<?php
declare(strict_types=1);

// includes/inscripcion_detalle_historial.php
require_once __DIR__ . '/auth_guard.php';
require_once dirname(__DIR__) . '/config/database.php';

header('Content-Type: application/json; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function historialEscapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}

function historialFecha($fecha): string
{
    if (!$fecha) {
        return '—';
    }

    $timestamp = strtotime((string) $fecha);
    return $timestamp ? date('d/m/Y', $timestamp) : (string) $fecha;
}

try {
    $id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
    if (!$id || $id <= 0) {
        throw new RuntimeException('ID no proporcionado o no válido.');
    }

    $page = max(1, (int) ($_POST['page'] ?? 1));
    $sort = (string) ($_POST['sort'] ?? 'fecha_pago');
    $order = strtoupper((string) ($_POST['order'] ?? 'DESC')) === 'ASC'
        ? 'ASC'
        : 'DESC';
    $search = trim((string) ($_POST['search'] ?? ''));

    $database = new Database();
    $conn = $database->getConnection();

    if (!$conn) {
        throw new RuntimeException('No fue posible conectar con la base de datos.');
    }

    $sucursalId = (int) ($_SESSION['sucursal_id'] ?? 0);
    $rolBase = strtolower(trim((string) (
        $_SESSION['user_rol_base'] ?? $_SESSION['user_rol'] ?? ''
    )));
    $adminFlag = in_array($rolBase, ['admin', 'administrador'], true) ? 1 : 0;

    // Confirmar que el usuario puede consultar esta inscripción.
    $stmtAcceso = $conn->prepare(
        "SELECT i.id
         FROM inscripciones i
         LEFT JOIN inscripciones_sucursales acceso
            ON acceso.inscripcion_id = i.id
           AND acceso.sucursal_id = ?
         WHERE i.id = ?
           AND (
                i.sucursal_id = ?
                OR acceso.sucursal_id IS NOT NULL
                OR ? = 1
           )
         LIMIT 1"
    );
    $stmtAcceso->bind_param('iiii', $sucursalId, $id, $sucursalId, $adminFlag);
    $stmtAcceso->execute();
    $permitido = $stmtAcceso->get_result()->fetch_assoc();
    $stmtAcceso->close();

    if (!$permitido) {
        throw new RuntimeException(
            'No tienes acceso al historial de esta inscripción.'
        );
    }

    $limit = 10;
    $offset = ($page - 1) * $limit;

    $sort_columns = [
        'fecha_pago' => 'h.fecha_pago',
        'monto' => 'h.monto',
        'metodo_pago' => 'h.metodo_pago',
        'referencia' => 'h.referencia',
        'periodo_inicio' => 'h.periodo_inicio',
        'plan_nombre' => 'h.plan_nombre',
    ];

    $order_by = $sort_columns[$sort] ?? 'h.fecha_pago';

    $query = "SELECT
                h.id,
                h.monto,
                h.fecha_pago,
                h.metodo_pago,
                h.referencia,
                h.periodo_inicio,
                h.periodo_fin,
                h.plan_nombre,
                DATE_FORMAT(h.fecha_pago, '%d/%m/%Y') AS fecha_pago_formateada,
                DATE_FORMAT(h.periodo_inicio, '%d/%m/%Y') AS periodo_inicio_formateado,
                DATE_FORMAT(h.periodo_fin, '%d/%m/%Y') AS periodo_fin_formateado
              FROM historial_pagos h
              WHERE h.inscripcion_id = ?";

    $count_query = "SELECT COUNT(*) AS total
                    FROM historial_pagos h
                    WHERE h.inscripcion_id = ?";

    $params = [$id];
    $types = 'i';

    if ($search !== '') {
        $searchSql = " AND (
            h.metodo_pago LIKE ?
            OR COALESCE(h.referencia, '') LIKE ?
            OR h.plan_nombre LIKE ?
            OR CAST(h.monto AS CHAR) LIKE ?
            OR DATE_FORMAT(h.fecha_pago, '%d/%m/%Y') LIKE ?
        )";

        $query .= $searchSql;
        $count_query .= $searchSql;

        $search_param = '%' . $search . '%';
        array_push(
            $params,
            $search_param,
            $search_param,
            $search_param,
            $search_param,
            $search_param
        );
        $types .= 'sssss';
    }

    $query .= " ORDER BY {$order_by} {$order}, h.id {$order}
                LIMIT ? OFFSET ?";

    $queryParams = array_merge($params, [$limit, $offset]);
    $queryTypes = $types . 'ii';

    $stmt = $conn->prepare($query);
    $stmt->bind_param($queryTypes, ...$queryParams);
    $stmt->execute();
    $historial = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    $stmt_count = $conn->prepare($count_query);
    $stmt_count->bind_param($types, ...$params);
    $stmt_count->execute();
    $total_rows = (int) (
        $stmt_count->get_result()->fetch_assoc()['total'] ?? 0
    );
    $stmt_count->close();

    $total_pages = max(1, (int) ceil($total_rows / $limit));

    $stmt_total = $conn->prepare(
        "SELECT COALESCE(SUM(monto), 0) AS total
         FROM historial_pagos
         WHERE inscripcion_id = ?
           AND monto > 0"
    );
    $stmt_total->bind_param('i', $id);
    $stmt_total->execute();
    $total_pagado = (float) (
        $stmt_total->get_result()->fetch_assoc()['total'] ?? 0
    );
    $stmt_total->close();

    ob_start();
    if (empty($historial)): ?>
        <tr>
            <td colspan="6" style="text-align:center;padding:60px 20px;">
                <i class="fas fa-receipt" style="font-size:48px;color:#ccc;"></i>
                <p class="mt-3" style="color:#999;">No hay pagos registrados</p>
            </td>
        </tr>
    <?php else: ?>
        <?php foreach ($historial as $pago): ?>
            <?php
            $metodo = strtolower(trim((string) $pago['metodo_pago']));
            $esPagoMembresia = (float) $pago['monto'] > 0;
            ?>
            <tr>
                <td><?php echo historialEscapar($pago['fecha_pago_formateada']); ?></td>
                <td>
                    <strong style="color:#10b981;">
                        $<?php echo number_format((float) $pago['monto'], 2); ?>
                    </strong>
                </td>
                <td>
                    <?php if ($metodo === 'efectivo'): ?>
                        <span class="badge-metodo badge-efectivo">
                            <i class="fas fa-money-bill"></i> Efectivo
                        </span>
                    <?php elseif ($metodo === 'tarjeta'): ?>
                        <span class="badge-metodo badge-tarjeta">
                            <i class="fas fa-credit-card"></i> Tarjeta
                        </span>
                    <?php elseif ($metodo === 'transferencia'): ?>
                        <span class="badge-metodo badge-transferencia">
                            <i class="fas fa-exchange-alt"></i> Transferencia
                        </span>
                    <?php else: ?>
                        <span class="badge-metodo">
                            <i class="fas fa-times-circle"></i> Cancelación
                        </span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php echo trim((string) $pago['referencia']) !== ''
                        ? historialEscapar($pago['referencia'])
                        : '—'; ?>
                </td>
                <td>
                    <?php if ($pago['periodo_inicio'] && $pago['periodo_fin']): ?>
                        <?php echo historialEscapar(
                            $pago['periodo_inicio_formateado']
                            . ' - '
                            . $pago['periodo_fin_formateado']
                        ); ?>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <td>
                    <span class="plan-historial-documento">
                        <?php if ($esPagoMembresia): ?>
                            <a
                                class="btn-documento-pdf is-small"
                                href="includes/ver_documento_inscripcion.php?id=<?php echo (int) $pago['id']; ?>"
                                target="_blank"
                                rel="noopener"
                                title="Abrir documento PDF de este pago"
                                aria-label="Abrir documento PDF de este pago"
                            >
                                <i class="fas fa-file-pdf"></i>
                            </a>
                        <?php endif; ?>
                        <span><?php echo historialEscapar($pago['plan_nombre']); ?></span>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
    <?php endif;
    $tbody = (string) ob_get_clean();

    ob_start();
    if ($total_pages > 1): ?>
        <div class="page-item-modern <?php echo $page <= 1 ? 'disabled' : ''; ?>">
            <?php if ($page > 1): ?>
                <a class="page-link-modern" onclick="cambiarPaginaHistorial(<?php echo $page - 1; ?>)">« Anterior</a>
            <?php else: ?>
                <span class="page-link-modern">« Anterior</span>
            <?php endif; ?>
        </div>
        <?php
        $startPage = max(1, $page - 2);
        $endPage = min($total_pages, $page + 2);
        ?>

        <?php if ($startPage > 1): ?>
            <div class="page-item-modern">
                <a class="page-link-modern" onclick="cambiarPaginaHistorial(1)">1</a>
            </div>
            <?php if ($startPage > 2): ?>
                <div class="page-item-modern disabled">
                    <span class="page-link-modern">...</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
            <div class="page-item-modern <?php echo $i === $page ? 'active' : ''; ?>">
                <a class="page-link-modern" onclick="cambiarPaginaHistorial(<?php echo $i; ?>)"><?php echo $i; ?></a>
            </div>
        <?php endfor; ?>

        <?php if ($endPage < $total_pages): ?>
            <?php if ($endPage < $total_pages - 1): ?>
                <div class="page-item-modern disabled">
                    <span class="page-link-modern">...</span>
                </div>
            <?php endif; ?>
            <div class="page-item-modern">
                <a class="page-link-modern" onclick="cambiarPaginaHistorial(<?php echo $total_pages; ?>)"><?php echo $total_pages; ?></a>
            </div>
        <?php endif; ?>

        <div class="page-item-modern <?php echo $page >= $total_pages ? 'disabled' : ''; ?>">
            <?php if ($page < $total_pages): ?>
                <a class="page-link-modern" onclick="cambiarPaginaHistorial(<?php echo $page + 1; ?>)">Siguiente »</a>
            <?php else: ?>
                <span class="page-link-modern">Siguiente »</span>
            <?php endif; ?>
        </div>
    <?php endif;
    $pagination = (string) ob_get_clean();

    echo json_encode(
        [
            'tbody' => $tbody,
            'pagination' => $pagination,
            'total_pagado' => number_format($total_pagado, 2),
        ],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(
        ['error' => $e->getMessage()],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
}
