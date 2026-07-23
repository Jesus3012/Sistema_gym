<?php
// Archivo: servicio_vencido.php
// Pantalla mostrada cuando el servicio fue suspendido o venció con bloqueo activo.

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_guard.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/servicio_plataforma_helper.php';

$database = new Database();
$db = $database->getConnection();

if (!$db instanceof mysqli) {
    http_response_code(500);
    exit('No fue posible conectar con la base de datos.');
}

$db->set_charset('utf8mb4');
$resumen = servicio_plataforma_resumen($db);
$configuracion = $resumen['configuracion'] ?? [];

if (servicio_plataforma_es_superadministrador()) {
    header('Location: servicio_plataforma.php');
    exit();
}

if (empty($resumen['debe_bloquear'])) {
    header('Location: dashboard.php');
    exit();
}

$proveedor = trim((string) (
    $configuracion['proveedor_nombre'] ?? 'GGFit'
));
$email = trim((string) ($configuracion['contacto_email'] ?? ''));
$telefono = trim((string) ($configuracion['contacto_telefono'] ?? ''));
$telefonoEnlace = preg_replace('/[^0-9+]/', '', $telefono);

function servicioVencidoEscapar($valor): string
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Servicio no disponible - EGO</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/servicio_vencido.css?v=1">
</head>
<body>
    <main class="expired-shell">
        <section class="expired-card">
            <div class="expired-brand">
                <span class="expired-logo"><i class="fas fa-dumbbell"></i></span>
                <span>EGO</span>
            </div>

            <div class="expired-icon">
                <i class="fas fa-calendar-xmark"></i>
            </div>

            <span class="expired-kicker">Servicio temporalmente no disponible</span>
            <h1><?php echo servicioVencidoEscapar((string) ($resumen['titulo'] ?? 'Servicio vencido')); ?></h1>
            <p class="expired-message">
                <?php echo servicioVencidoEscapar((string) ($resumen['mensaje'] ?? 'Contacta al proveedor para renovar.')); ?>
            </p>

            <div class="expired-details">
                <div>
                    <span>Fecha de vencimiento</span>
                    <strong><?php echo servicioVencidoEscapar((string) ($resumen['fecha_vencimiento_formateada'] ?? 'Sin definir')); ?></strong>
                </div>
                <div>
                    <span>Proveedor</span>
                    <strong><?php echo servicioVencidoEscapar($proveedor !== '' ? $proveedor : 'GGFit'); ?></strong>
                </div>
            </div>

            <?php if ($email !== '' || $telefono !== ''): ?>
                <div class="expired-contact">
                    <span>Datos para renovación</span>
                    <div>
                        <?php if ($email !== ''): ?>
                            <a href="mailto:<?php echo servicioVencidoEscapar($email); ?>">
                                <i class="fas fa-envelope"></i>
                                <?php echo servicioVencidoEscapar($email); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($telefono !== ''): ?>
                            <a href="tel:<?php echo servicioVencidoEscapar((string) $telefonoEnlace); ?>">
                                <i class="fas fa-phone"></i>
                                <?php echo servicioVencidoEscapar($telefono); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <a href="logout.php" class="expired-logout">
                <i class="fas fa-right-from-bracket"></i>
                Cerrar sesión
            </a>
        </section>
    </main>
</body>
</html>
