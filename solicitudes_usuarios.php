<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/*
 * Se utiliza el mismo guard del resto del sistema cuando está disponible.
 * El control interno se conserva como respaldo de seguridad.
 */
$auth_guard = __DIR__ . '/includes/auth_guard.php';
if (file_exists($auth_guard)) {
    require_once $auth_guard;
}

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$rol_actual = strtolower(trim((string) ($_SESSION['user_rol'] ?? '')));

if (!in_array($rol_actual, ['administrador', 'admin'], true)) {
    $_SESSION['alerta_acceso_denegado'] = [
        'titulo' => 'Acceso restringido',
        'mensaje' => 'Solo un administrador puede revisar y autorizar solicitudes de usuarios.',
        'rol' => ucfirst($rol_actual ?: 'Sin rol'),
        'modulo' => 'Solicitudes de usuarios',
    ];

    header('Location: dashboard.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

if (empty($_SESSION['solicitudes_csrf'])) {
    $_SESSION['solicitudes_csrf'] = bin2hex(random_bytes(32));
}

/*
 * Mensajes flash para evitar que el navegador repita la operación
 * cuando el administrador actualiza la página.
 */
$mensaje = $_SESSION['solicitudes_mensaje'] ?? '';
$tipo_mensaje = $_SESSION['solicitudes_tipo'] ?? '';

unset(
    $_SESSION['solicitudes_mensaje'],
    $_SESSION['solicitudes_tipo']
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = (string) ($_POST['csrf_token'] ?? '');
    $usuario_id = filter_input(INPUT_POST, 'usuario_id', FILTER_VALIDATE_INT);
    $accion = strtolower(trim((string) ($_POST['accion'] ?? '')));

    $mensaje_post = '';
    $tipo_post = 'error';

    if (
        $csrf === ''
        || !hash_equals((string) $_SESSION['solicitudes_csrf'], $csrf)
    ) {
        $mensaje_post = 'La solicitud no es válida. Actualiza la página e inténtalo nuevamente.';
    } elseif (!$usuario_id || !in_array($accion, ['aprobar', 'rechazar'], true)) {
        $mensaje_post = 'No fue posible identificar la solicitud seleccionada.';
    } else {
        $nuevo_estado = $accion === 'aprobar' ? 'activo' : 'rechazado';

        $stmt = $db->prepare(
            "UPDATE usuarios
             SET estado = ?
             WHERE id = ?
               AND estado = 'pendiente'
               AND rol IN ('recepcionista', 'entrenador')"
        );

        if (!$stmt) {
            $mensaje_post = 'No fue posible preparar la actualización de la solicitud.';
        } else {
            $stmt->bind_param('si', $nuevo_estado, $usuario_id);

            if (!$stmt->execute()) {
                $mensaje_post = 'Ocurrió un problema al procesar la solicitud.';
            } elseif ($stmt->affected_rows === 1) {
                if ($accion === 'aprobar') {
                    $mensaje_post = 'La cuenta fue aprobada y ya puede iniciar sesión.';
                    $tipo_post = 'success';
                } else {
                    $mensaje_post = 'La solicitud fue rechazada correctamente.';
                    $tipo_post = 'info';
                }
            } else {
                $mensaje_post = 'La solicitud ya había sido procesada o dejó de estar disponible.';
                $tipo_post = 'warning';
            }

            $stmt->close();
        }
    }

    $_SESSION['solicitudes_mensaje'] = $mensaje_post;
    $_SESSION['solicitudes_tipo'] = $tipo_post;

    header('Location: solicitudes_usuarios.php');
    exit();
}

$solicitudes = [];

$query = "
    SELECT id, nombre, email, rol
    FROM usuarios
    WHERE estado = 'pendiente'
      AND rol IN ('recepcionista', 'entrenador')
    ORDER BY id ASC
";

$result = $db->query($query);

if ($result) {
    while ($fila = $result->fetch_assoc()) {
        $solicitudes[] = $fila;
    }
}

$total_solicitudes = count($solicitudes);

function e(string $valor): string
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function etiquetaRol(string $rol): string
{
    return strtolower(trim($rol)) === 'entrenador'
        ? 'Entrenador'
        : 'Recepcionista';
}

function inicialUsuario(string $nombre): string
{
    $nombre = trim($nombre);

    if ($nombre === '') {
        return 'U';
    }

    if (function_exists('mb_substr')) {
        return mb_strtoupper(mb_substr($nombre, 0, 1, 'UTF-8'), 'UTF-8');
    }

    return strtoupper(substr($nombre, 0, 1));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#101f3d">

    <title>Solicitudes de usuarios</title>

    <link
        rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css"
    >
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

    <style>
        :root {
            --sol-azul: #1e3a8a;
            --sol-azul-oscuro: #172f73;
            --sol-azul-suave: #eef4ff;
            --sol-fondo: #f5f7fa;
            --sol-blanco: #ffffff;
            --sol-texto: #1f2937;
            --sol-suave: #64748b;
            --sol-borde: #e2e8f0;
            --sol-verde: #059669;
            --sol-verde-hover: #047857;
            --sol-rojo: #dc2626;
            --sol-rojo-hover: #b91c1c;
            --sol-sombra: 0 8px 24px rgba(15, 23, 42, .065);
        }

        .solicitudes-page,
        .solicitudes-page * {
            box-sizing: border-box;
        }

        .solicitudes-page {
            width: min(1160px, 100%);
            margin: 0 auto;
            color: var(--sol-texto);
        }

        .solicitudes-header {
            margin-bottom: 20px;
        }

        .solicitudes-heading {
            min-width: 0;
        }

        .solicitudes-eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 7px;
            color: var(--sol-azul);
            font-size: .72rem;
            font-weight: 850;
            letter-spacing: .07em;
            text-transform: uppercase;
        }

        .solicitudes-heading h1 {
            margin: 0 0 7px;
            color: var(--sol-azul-oscuro);
            font-size: clamp(1.7rem, 3vw, 2.3rem);
            line-height: 1.12;
            letter-spacing: -.035em;
        }

        .solicitudes-heading p {
            max-width: 760px;
            margin: 0;
            color: var(--sol-suave);
            font-size: .91rem;
            line-height: 1.55;
        }

        .solicitudes-summary {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
            padding: 15px 17px;
            border: 1px solid #dbe5f5;
            border-radius: 15px;
            background: var(--sol-blanco);
            box-shadow: 0 5px 18px rgba(30, 58, 138, .05);
        }

        .solicitudes-summary-icon {
            display: grid;
            flex: 0 0 44px;
            width: 44px;
            height: 44px;
            place-items: center;
            border-radius: 12px;
            color: #ffffff;
            background: linear-gradient(135deg, #1e3a8a, #3154a5);
            font-size: .96rem;
        }

        .solicitudes-summary-copy {
            min-width: 0;
            flex: 1;
        }

        .solicitudes-summary-copy strong {
            display: block;
            margin-bottom: 3px;
            color: var(--sol-azul-oscuro);
            font-size: .95rem;
        }

        .solicitudes-summary-copy span {
            display: block;
            color: var(--sol-suave);
            font-size: .79rem;
            line-height: 1.45;
        }

        .solicitudes-count {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 38px;
            height: 34px;
            padding: 0 10px;
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            color: var(--sol-azul);
            background: var(--sol-azul-suave);
            font-size: .88rem;
            font-weight: 850;
        }

        .solicitudes-grid {
            display: grid;
            gap: 11px;
        }

        .solicitud-card {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 20px;
            min-width: 0;
            padding: 16px 18px;
            border: 1px solid var(--sol-borde);
            border-radius: 15px;
            background: var(--sol-blanco);
            box-shadow: var(--sol-sombra);
            transition:
                border-color .18s ease,
                box-shadow .18s ease,
                transform .18s ease;
        }

        .solicitud-card:hover {
            border-color: #cbd8eb;
            box-shadow: 0 11px 28px rgba(15, 23, 42, .085);
            transform: translateY(-1px);
        }

        .solicitud-card-header {
            display: flex;
            align-items: center;
            min-width: 0;
            gap: 13px;
            margin: 0;
        }

        .solicitud-avatar {
            display: grid;
            flex: 0 0 46px;
            width: 46px;
            height: 46px;
            place-items: center;
            border: 1px solid #d7e4fb;
            border-radius: 13px;
            color: var(--sol-azul);
            background: var(--sol-azul-suave);
            font-size: .95rem;
            font-weight: 850;
        }

        .solicitud-persona {
            min-width: 0;
            flex: 1;
        }

        .solicitud-persona h2 {
            margin: 0 0 5px;
            overflow-wrap: anywhere;
            color: var(--sol-texto);
            font-size: .96rem;
            line-height: 1.25;
        }

        .solicitud-meta {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 7px 11px;
        }

        .solicitud-email {
            display: inline-flex;
            align-items: center;
            min-width: 0;
            max-width: 100%;
            gap: 6px;
            margin: 0;
            color: var(--sol-suave);
            font-size: .76rem;
        }

        .solicitud-email i {
            flex: 0 0 auto;
            color: #94a3b8;
        }

        .solicitud-email span {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .solicitud-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            margin: 0;
            padding: 5px 8px;
            border: 1px solid #fde68a;
            border-radius: 999px;
            color: #92400e;
            background: #fffbeb;
            font-size: .63rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .solicitud-actions {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            min-width: 250px;
        }

        .solicitud-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            min-width: 116px;
            min-height: 40px;
            padding: 8px 14px;
            border: 0;
            border-radius: 10px;
            color: #ffffff;
            cursor: pointer;
            font: inherit;
            font-size: .77rem;
            font-weight: 800;
            transition:
                background .18s ease,
                box-shadow .18s ease,
                transform .18s ease;
        }

        .solicitud-button:hover {
            transform: translateY(-1px);
        }

        .solicitud-button:focus-visible {
            outline: 3px solid rgba(59, 130, 246, .24);
            outline-offset: 2px;
        }

        .solicitud-button:disabled {
            cursor: wait;
            opacity: .72;
            transform: none;
        }

        .solicitud-button.aprobar {
            background: var(--sol-verde);
            box-shadow: 0 5px 12px rgba(5, 150, 105, .15);
        }

        .solicitud-button.aprobar:hover {
            background: var(--sol-verde-hover);
        }

        .solicitud-button.rechazar {
            background: var(--sol-rojo);
            box-shadow: 0 5px 12px rgba(220, 38, 38, .13);
        }

        .solicitud-button.rechazar:hover {
            background: var(--sol-rojo-hover);
        }

        .solicitudes-empty {
            display: grid;
            min-height: 280px;
            place-items: center;
            padding: 40px 22px;
            border: 1px dashed #cbd5e1;
            border-radius: 17px;
            background: rgba(255, 255, 255, .78);
            text-align: center;
        }

        .solicitudes-empty-content {
            max-width: 430px;
        }

        .solicitudes-empty-icon {
            display: grid;
            width: 60px;
            height: 60px;
            margin: 0 auto 14px;
            place-items: center;
            border: 1px solid #bbf7d0;
            border-radius: 17px;
            color: #047857;
            background: #ecfdf5;
            font-size: 1.35rem;
        }

        .solicitudes-empty strong {
            display: block;
            margin-bottom: 6px;
            color: var(--sol-texto);
            font-size: .98rem;
        }

        .solicitudes-empty span {
            color: var(--sol-suave);
            font-size: .8rem;
            line-height: 1.5;
        }

        .swal2-popup.solicitudes-swal {
            width: min(430px, calc(100vw - 28px));
            border-radius: 18px;
            padding: 18px;
        }

        .swal2-popup.solicitudes-swal .swal2-title {
            color: var(--sol-azul-oscuro);
            font-size: 1.3rem;
        }

        .swal2-popup.solicitudes-swal .swal2-html-container {
            color: var(--sol-suave);
            font-size: .88rem;
            line-height: 1.55;
        }

        @media (max-width: 900px) {
            .solicitud-card {
                grid-template-columns: 1fr;
                gap: 14px;
            }

            .solicitud-actions {
                width: 100%;
                min-width: 0;
                justify-content: stretch;
            }

            .solicitud-button {
                flex: 1;
            }
        }

        @media (max-width: 520px) {
            .solicitudes-summary {
                align-items: flex-start;
                padding: 14px;
            }

            .solicitudes-count {
                margin-left: auto;
            }

            .solicitud-card {
                padding: 15px;
            }

            .solicitud-card-header {
                align-items: flex-start;
            }

            .solicitud-meta {
                align-items: flex-start;
                flex-direction: column;
                gap: 7px;
            }

            .solicitud-actions {
                flex-direction: column;
            }

            .solicitud-button {
                width: 100%;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .solicitud-card,
            .solicitud-button {
                transition: none;
            }
        }
    </style>
</head>
<body>

<?php require __DIR__ . '/includes/sidebar.php'; ?>

<main class="main-content">
    <div class="solicitudes-page">
        <header class="solicitudes-header">
            <div class="solicitudes-heading">
                <h1>Solicitudes de usuarios</h1>

                <p>
                    Ningún usuario podrá acceder hasta que un administrador lo autorice.
                </p>
            </div>
        </header>

        <section class="solicitudes-summary" aria-label="Resumen de solicitudes">
            <div class="solicitudes-summary-icon">
                <i class="fas fa-user-clock" aria-hidden="true"></i>
            </div>

            <div class="solicitudes-summary-copy">
                <strong>
                    <?php echo $total_solicitudes; ?>
                    solicitud<?php echo $total_solicitudes === 1 ? '' : 'es'; ?>
                    pendiente<?php echo $total_solicitudes === 1 ? '' : 's'; ?>
                </strong>

                <span>
                    Aprueba únicamente al personal que reconozcas y esté autorizado para utilizar el sistema.
                </span>
            </div>

            <span class="solicitudes-count" aria-hidden="true">
                <?php echo $total_solicitudes > 99 ? '99+' : $total_solicitudes; ?>
            </span>
        </section>

        <section class="solicitudes-grid" aria-label="Listado de solicitudes pendientes">
            <?php if (!$solicitudes): ?>
                <div class="solicitudes-empty">
                    <div class="solicitudes-empty-content">
                        <div class="solicitudes-empty-icon">
                            <i class="fas fa-check" aria-hidden="true"></i>
                        </div>

                        <strong>No hay solicitudes pendientes</strong>

                        <span>
                            Cuando un recepcionista o entrenador solicite acceso,
                            su cuenta aparecerá automáticamente en esta sección.
                        </span>
                    </div>
                </div>
            <?php else: ?>
                <?php foreach ($solicitudes as $solicitud): ?>
                    <?php
                    $nombre_solicitud = (string) ($solicitud['nombre'] ?? '');
                    $email_solicitud = (string) ($solicitud['email'] ?? '');
                    $rol_solicitud = etiquetaRol((string) ($solicitud['rol'] ?? ''));
                    ?>
                    <article class="solicitud-card">
                        <div class="solicitud-card-header">
                            <div class="solicitud-avatar" aria-hidden="true">
                                <?php echo e(inicialUsuario($nombre_solicitud)); ?>
                            </div>

                            <div class="solicitud-persona">
                                <h2><?php echo e($nombre_solicitud); ?></h2>

                                <div class="solicitud-meta">
                                    <p class="solicitud-email" title="<?php echo e($email_solicitud); ?>">
                                        <i class="fas fa-envelope" aria-hidden="true"></i>
                                        <span><?php echo e($email_solicitud); ?></span>
                                    </p>

                                    <span class="solicitud-badge">
                                        <i class="fas fa-clock" aria-hidden="true"></i>
                                        <?php echo e($rol_solicitud); ?> pendiente
                                    </span>
                                </div>
                            </div>
                        </div>

                        <form method="POST" class="solicitud-actions solicitud-form">
                            <input
                                type="hidden"
                                name="csrf_token"
                                value="<?php echo e((string) $_SESSION['solicitudes_csrf']); ?>"
                            >

                            <input
                                type="hidden"
                                name="usuario_id"
                                value="<?php echo (int) $solicitud['id']; ?>"
                            >

                            <button
                                type="submit"
                                name="accion"
                                value="aprobar"
                                class="solicitud-button aprobar"
                                data-action="aprobar"
                                data-user="<?php echo e($nombre_solicitud); ?>"
                            >
                                <i class="fas fa-check" aria-hidden="true"></i>
                                Aprobar
                            </button>

                            <button
                                type="submit"
                                name="accion"
                                value="rechazar"
                                class="solicitud-button rechazar"
                                data-action="rechazar"
                                data-user="<?php echo e($nombre_solicitud); ?>"
                            >
                                <i class="fas fa-xmark" aria-hidden="true"></i>
                                Rechazar
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</main>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const forms = document.querySelectorAll('.solicitud-form');

    forms.forEach(function (form) {
        const buttons = form.querySelectorAll('button[type="submit"]');

        buttons.forEach(function (button) {
            button.addEventListener('click', async function (event) {
                event.preventDefault();

                const action = button.dataset.action;
                const userName = button.dataset.user || 'este usuario';
                const approving = action === 'aprobar';

                const result = await Swal.fire({
                    icon: approving ? 'question' : 'warning',
                    title: approving ? '¿Aprobar esta cuenta?' : '¿Rechazar esta solicitud?',
                    html: approving
                        ? `Se permitirá que <strong>${escapeHtml(userName)}</strong> inicie sesión en el sistema.`
                        : `La solicitud de <strong>${escapeHtml(userName)}</strong> será marcada como rechazada.`,
                    showCancelButton: true,
                    confirmButtonText: approving ? 'Sí, aprobar' : 'Sí, rechazar',
                    cancelButtonText: 'Cancelar',
                    confirmButtonColor: approving ? '#059669' : '#dc2626',
                    cancelButtonColor: '#64748b',
                    reverseButtons: true,
                    focusCancel: true,
                    customClass: {
                        popup: 'solicitudes-swal'
                    }
                });

                if (!result.isConfirmed) {
                    return;
                }

                // Guardar la acción en un campo oculto antes de desactivar
                // los botones. Los controles desactivados no se envían en POST.
                let actionInput = form.querySelector('input[name="accion"][data-generated="true"]');

                if (!actionInput) {
                    actionInput = document.createElement('input');
                    actionInput.type = 'hidden';
                    actionInput.name = 'accion';
                    actionInput.dataset.generated = 'true';
                    form.appendChild(actionInput);
                }

                actionInput.value = action;

                buttons.forEach(function (item) {
                    item.disabled = true;
                });

                // Usar submit() para enviar el valor oculto sin volver a disparar
                // el evento click ni depender del botón que actuó como submitter.
                form.submit();
            });
        });
    });

    <?php if ($mensaje !== ''): ?>
    Swal.fire({
        icon: <?php echo json_encode(
            in_array($tipo_mensaje, ['success', 'info', 'warning', 'error'], true)
                ? $tipo_mensaje
                : 'info',
            JSON_UNESCAPED_UNICODE
        ); ?>,
        title: <?php echo json_encode(
            $tipo_mensaje === 'success'
                ? 'Solicitud aprobada'
                : ($tipo_mensaje === 'info'
                    ? 'Solicitud rechazada'
                    : ($tipo_mensaje === 'warning'
                        ? 'Solicitud no disponible'
                        : 'No fue posible procesarla')),
            JSON_UNESCAPED_UNICODE
        ); ?>,
        text: <?php echo json_encode($mensaje, JSON_UNESCAPED_UNICODE); ?>,
        confirmButtonText: 'Entendido',
        confirmButtonColor: '#1e3a8a',
        customClass: {
            popup: 'solicitudes-swal'
        }
    });
    <?php endif; ?>
});

function escapeHtml(value) {
    const element = document.createElement('div');
    element.textContent = value;
    return element.innerHTML;
}
</script>

</body>
</html>