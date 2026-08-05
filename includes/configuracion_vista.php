<?php
// Archivo: includes/configuracion_vista.php
// Interfaz, modales y JavaScript del módulo de Configuración.

declare(strict_types=1);

if (!defined('CONFIGURACION_MODULO_CARGADO')) {
    http_response_code(403);
    exit('Acceso no permitido.');
}

if (
    !isset($configuracionVista)
    || !is_array($configuracionVista)
) {
    throw new RuntimeException(
        'No se recibió el contexto de la vista de Configuración.'
    );
}

$connVista = $configuracionVista['conn'] ?? null;

if (!$connVista instanceof mysqli) {
    throw new RuntimeException(
        'La conexión de la vista de Configuración no es válida.'
    );
}

/*
 * Variables locales de la plantilla.
 * Estas asignaciones permiten que Intelephense conozca su origen y tipo.
 */
$conn = $connVista;
$usuario_id = (int) ($configuracionVista['usuario_id'] ?? 0);
$vistaGlobalConfiguracion = (bool) (
    $configuracionVista['vista_global'] ?? false
);
$sucursalIdConfiguracion = (int) (
    $configuracionVista['sucursal_id'] ?? 0
);
$sucursalNombreConfiguracion = (string) (
    $configuracionVista['sucursal_nombre'] ?? 'Sucursal'
);
$seccion = (string) (
    $configuracionVista['seccion'] ?? 'clientes'
);
$esSuperAdministradorActual = (bool) (
    $configuracionVista['es_super_administrador'] ?? false
);
$configSecurityCsrf = (string) (
    $configuracionVista['security_csrf'] ?? ''
);

/** @var array<string, mixed>|null $config_2fa */
$config_2fa = is_array(
    $configuracionVista['config_2fa'] ?? null
)
    ? $configuracionVista['config_2fa']
    : null;

/** @var array<string, mixed> $config_acceso */
$config_acceso = is_array(
    $configuracionVista['config_acceso'] ?? null
)
    ? $configuracionVista['config_acceso']
    : array(
        'table_exists' => false,
        'configured' => false,
        'updated_at' => null,
        'updated_by_name' => null,
        'uses_legacy_default' => true,
    );

/** @var array<string, mixed> $config_gimnasio */
$config_gimnasio = is_array(
    $configuracionVista['config_gimnasio'] ?? null
)
    ? $configuracionVista['config_gimnasio']
    : array();

/** @var array<string, mixed>|null $config_correo */
$config_correo = is_array(
    $configuracionVista['config_correo'] ?? null
)
    ? $configuracionVista['config_correo']
    : null;

$logo_path = (string) (
    $configuracionVista['logo_path'] ?? 'img/logo-gym.png'
);
$logo_es_propio = (bool) (
    $configuracionVista['logo_es_propio'] ?? false
);
$ultima_actualizacion = (string) (
    $configuracionVista['ultima_actualizacion'] ?? ''
);

/** @var array{total: int} $total_clientes */
$total_clientes = is_array(
    $configuracionVista['total_clientes'] ?? null
)
    ? $configuracionVista['total_clientes']
    : array('total' => 0);

/** @var array{total: int} $total_planes */
$total_planes = is_array(
    $configuracionVista['total_planes'] ?? null
)
    ? $configuracionVista['total_planes']
    : array('total' => 0);

/** @var array{total: int} $total_productos */
$total_productos = is_array(
    $configuracionVista['total_productos'] ?? null
)
    ? $configuracionVista['total_productos']
    : array('total' => 0);

/** @var array{total: int} $total_usuarios */
$total_usuarios = is_array(
    $configuracionVista['total_usuarios'] ?? null
)
    ? $configuracionVista['total_usuarios']
    : array('total' => 0);

/** @var array{total: int} $total_clases */
$total_clases = is_array(
    $configuracionVista['total_clases'] ?? null
)
    ? $configuracionVista['total_clases']
    : array('total' => 0);

/** @var array{total: int} $total_proveedores */
$total_proveedores = is_array(
    $configuracionVista['total_proveedores'] ?? null
)
    ? $configuracionVista['total_proveedores']
    : array('total' => 0);

/** @var array{total: int} $total_categorias */
$total_categorias = is_array(
    $configuracionVista['total_categorias'] ?? null
)
    ? $configuracionVista['total_categorias']
    : array('total' => 0);

$clientesVista = $configuracionVista['clientes'] ?? null;
$planesVista = $configuracionVista['planes'] ?? null;
$productosVista = $configuracionVista['productos'] ?? null;
$clasesVista = $configuracionVista['clases'] ?? null;
$usuariosVista = $configuracionVista['usuarios'] ?? null;

if (
    !$clientesVista instanceof mysqli_result
    || !$planesVista instanceof mysqli_result
    || !$productosVista instanceof mysqli_result
    || !$clasesVista instanceof mysqli_result
    || !$usuariosVista instanceof mysqli_result
) {
    throw new RuntimeException(
        'Uno o más listados de Configuración no son válidos.'
    );
}

$clientes = $clientesVista;
$planes = $planesVista;
$productos = $productosVista;
$clases = $clasesVista;
$usuarios = $usuariosVista;

$categoriasVista = $configuracionVista['categorias'] ?? null;
$proveedoresVista = $configuracionVista['proveedores'] ?? null;

/** @var mysqli_result|null $categorias */
$categorias = $categoriasVista instanceof mysqli_result
    ? $categoriasVista
    : null;

/** @var mysqli_result|null $proveedores */
$proveedores = $proveedoresVista instanceof mysqli_result
    ? $proveedoresVista
    : null;

/** @var array<int, string> $entrenadoresSucursal */
$entrenadoresSucursal = is_array(
    $configuracionVista['entrenadores_sucursal'] ?? null
)
    ? array_values(
        array_map(
            'strval',
            $configuracionVista['entrenadores_sucursal']
        )
    )
    : array();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Configuración - Sistema Gimnasio</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/admin-lte@3.2/dist/css/adminlte.min.css">
    <?php
    $configuracionCss = dirname(__DIR__)
        . '/css/configuracion.css';
    ?>
    <link
        rel="stylesheet"
        href="css/configuracion.css?v=<?php echo is_file($configuracionCss) ? (int) filemtime($configuracionCss) : time(); ?>"
    >

</head>
<body class="hold-transition sidebar-mini configuracion-page">
    <?php
    require dirname(__DIR__) . '/includes/sidebar.php';
    ?>

    <div class="main-content">
        <header class="config-context-header">
            <div class="config-context-heading">
                <span class="config-context-icon">
                    <i class="fas fa-gears"></i>
                </span>

                <div>
                    <span class="config-context-kicker">
                        Administración del sistema
                    </span>

                    <h1>Configuración</h1>

                    <p>
                        <?php echo $vistaGlobalConfiguracion
                            ? 'Catálogos y ajustes corporativos de todas las sucursales.'
                            : 'Datos y disponibilidad operativa de la sucursal seleccionada.'; ?>
                    </p>
                </div>
            </div>

            <span class="config-context-badge <?php echo $vistaGlobalConfiguracion ? 'global' : 'branch'; ?>">
                <i class="fas <?php echo $vistaGlobalConfiguracion ? 'fa-layer-group' : 'fa-building'; ?>"></i>
                <span>
                    <small><?php echo $vistaGlobalConfiguracion ? 'Vista consolidada' : 'Sucursal activa'; ?></small>
                    <strong><?php echo configuracion_h($sucursalNombreConfiguracion); ?></strong>
                </span>
            </span>
        </header>

        <div class="config-scope-note <?php echo $vistaGlobalConfiguracion ? 'global' : 'branch'; ?>">
            <i class="fas <?php echo $vistaGlobalConfiguracion ? 'fa-circle-info' : 'fa-location-dot'; ?>"></i>
            <span>
                <?php if ($vistaGlobalConfiguracion): ?>
                    Los catálogos corporativos se comparten entre sedes. Cambia a una sucursal para modificar precios, existencias, clases y accesos locales.
                <?php else: ?>
                    Los cambios de esta vista se aplican únicamente a <strong><?php echo configuracion_h($sucursalNombreConfiguracion); ?></strong>, salvo los datos personales generales del socio o usuario.
                <?php endif; ?>
            </span>
        </div>

        <div class="config-nav">
            <ul>
                <?php if ($vistaGlobalConfiguracion): ?>
                    <li>
                        <a
                            href="<?php echo configuracion_h(
                                configuracion_url('general', true)
                            ); ?>"
                            class="<?php echo $seccion === 'general'
                                ? 'active'
                                : ''; ?>"
                        >
                            <i class="fas fa-sliders-h"></i>
                            General
                        </a>
                    </li>

                    <li>
                        <a
                            href="<?php echo configuracion_h(
                                configuracion_url('correo', true)
                            ); ?>"
                            class="<?php echo $seccion === 'correo'
                                ? 'active'
                                : ''; ?>"
                        >
                            <i class="fas fa-envelope-open-text"></i>
                            Correo
                        </a>
                    </li>

                    <?php if ($esSuperAdministradorActual): ?>
                        <li>
                            <a
                                href="<?php echo configuracion_h(
                                    configuracion_url('seguridad', true)
                                ); ?>"
                                class="<?php echo $seccion === 'seguridad'
                                    ? 'active'
                                    : ''; ?>"
                            >
                                <i class="fas fa-shield-halved"></i>
                                Seguridad
                            </a>
                        </li>
                    <?php endif; ?>
                <?php endif; ?>
                <li><a href="<?php echo configuracion_h(configuracion_url('clientes', $vistaGlobalConfiguracion)); ?>" class="<?php echo $seccion === 'clientes' ? 'active' : ''; ?>"><i class="fas fa-users"></i> Socios</a></li>
                <li><a href="<?php echo configuracion_h(configuracion_url('planes', $vistaGlobalConfiguracion)); ?>" class="<?php echo $seccion === 'planes' ? 'active' : ''; ?>"><i class="fas fa-tags"></i> Planes</a></li>
                <li><a href="<?php echo configuracion_h(configuracion_url('productos', $vistaGlobalConfiguracion)); ?>" class="<?php echo $seccion === 'productos' ? 'active' : ''; ?>"><i class="fas fa-box"></i> Productos</a></li>
                <?php if ($vistaGlobalConfiguracion): ?>
                    <li><a href="<?php echo configuracion_h(configuracion_url('categorias', true)); ?>" class="<?php echo $seccion === 'categorias' ? 'active' : ''; ?>"><i class="fas fa-folder"></i> Categorías</a></li>
                    <li><a href="<?php echo configuracion_h(configuracion_url('proveedores', true)); ?>" class="<?php echo $seccion === 'proveedores' ? 'active' : ''; ?>"><i class="fas fa-truck"></i> Proveedores</a></li>
                <?php endif; ?>
                <li><a href="<?php echo configuracion_h(configuracion_url('clases', $vistaGlobalConfiguracion)); ?>" class="<?php echo $seccion === 'clases' ? 'active' : ''; ?>"><i class="fas fa-chalkboard-user"></i> Clases</a></li>
                <li><a href="<?php echo configuracion_h(configuracion_url('usuarios', $vistaGlobalConfiguracion)); ?>" class="<?php echo $seccion === 'usuarios' ? 'active' : ''; ?>"><i class="fas fa-user-shield"></i> Usuarios</a></li>
            </ul>
        </div>

        <?php if ($seccion == 'general'): ?>
        <div class="row">
            <div class="col-lg-3 col-6">
                <div class="small-box bg-info">
                    <div class="inner">
                        <h3><?php echo $total_clientes['total']; ?></h3>
                        <p><?php echo $vistaGlobalConfiguracion ? 'Socios activos' : 'Socios de la sede'; ?></p>
                    </div>
                    <div class="icon"><i class="fas fa-users"></i></div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-success">
                    <div class="inner">
                        <h3><?php echo $total_planes['total']; ?></h3>
                        <p>Planes disponibles</p>
                    </div>
                    <div class="icon"><i class="fas fa-tags"></i></div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-warning">
                    <div class="inner">
                        <h3><?php echo $total_productos['total']; ?></h3>
                        <p>Productos disponibles</p>
                    </div>
                    <div class="icon"><i class="fas fa-box"></i></div>
                </div>
            </div>
            <div class="col-lg-3 col-6">
                <div class="small-box bg-danger">
                    <div class="inner">
                        <h3><?php echo $total_usuarios['total']; ?></h3>
                        <p><?php echo $vistaGlobalConfiguracion ? 'Usuarios activos' : 'Personal con acceso'; ?></p>
                    </div>
                    <div class="icon"><i class="fas fa-user-shield"></i></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-building"></i> <?php echo $vistaGlobalConfiguracion ? 'Información corporativa' : 'Datos de la sucursal'; ?></h3>
            </div>
            <div class="card-body">
                <form id="formInfoGimnasio" enctype="multipart/form-data">
                    <div class="row">
                        <!-- Sección Logo - Versión mejorada y más bonita -->
                        <div class="col-md-12">
                            <div class="form-group">
                                <label><i class="fas fa-image"></i> <?php echo $vistaGlobalConfiguracion ? 'Logo corporativo' : 'Logo propio de la sucursal'; ?></label>
                                <div class="card bg-light">
                                    <div class="card-body">
                                        <div class="row align-items-center">
                                            <div class="col-md-3 text-center">

                                                <div class="text-center mb-3">
                                                    <img id="preview_logo" src="<?php echo htmlspecialchars($logo_path); ?>"
                                                        alt="<?php echo $vistaGlobalConfiguracion ? 'Logo del gimnasio' : 'Logo de la sucursal'; ?>"
                                                        style="max-width: 150px; max-height: 150px; border: 1px solid #ddd; padding: 5px; border-radius: 5px; object-fit: contain;">
                                                </div>
                                            </div>
                                            <div class="col-md-9">
                                                <div class="custom-file">
                                                    <input type="file" class="custom-file-input" id="logo" name="logo" accept="image/*">
                                                    <label class="custom-file-label" for="logo">Seleccionar logo (PNG, JPG o WEBP)</label>
                                                </div>

                                                <!-- Alerta ocultable con todas las recomendaciones -->
                                                <div class="alert alert-info alert-ocultable mt-3" data-alerta-id="logo_info" style="position: relative;">
                                                    <i class="fas fa-info-circle"></i>
                                                    <strong>Recomendaciones para el logo:</strong>
                                                    <ul class="mb-0 mt-1">
                                                        <li>Formatos permitidos: PNG, JPG y WEBP</li>
                                                        <li>Tamaño máximo: 2MB</li>
                                                        <li>Dimensión recomendada: 200x200px</li>
                                                        <li>Fondo transparente para mejor integración</li>
                                                        <li>El logo se mostrará en facturas, reportes y en la interfaz del sistema</li>
                                                    </ul>
                                                    <button type="button" class="btn-ocultar" onclick="event.preventDefault(); event.stopPropagation(); ocultarAlerta('logo_info')" title="Ocultar recomendaciones">
                                                        <i class="fas fa-times"></i>
                                                    </button>
                                                </div>

                                                <?php if ($logo_es_propio): ?>
                                                    <button type="button" class="btn btn-danger btn-sm mt-2" onclick="eliminarLogo()">
                                                        <i class="fas fa-trash"></i> Eliminar logo actual
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-building"></i> <?php echo $vistaGlobalConfiguracion ? 'Nombre del gimnasio' : 'Nombre de la sucursal'; ?></label>
                                <input type="text" class="form-control" name="nombre_gimnasio" value="<?php echo htmlspecialchars($config_gimnasio['nombre'] ?? 'EGO'); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-phone"></i> Teléfono</label>
                                <input type="text" class="form-control" name="telefono" value="<?php echo htmlspecialchars($config_gimnasio['telefono'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-envelope"></i> Email</label>
                                <input type="email" class="form-control" name="email" value="<?php echo htmlspecialchars($config_gimnasio['email'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-group">
                                <label><i class="fas fa-map-marker-alt"></i> Dirección</label>
                                <input type="text" class="form-control" name="direccion" value="<?php echo htmlspecialchars($config_gimnasio['direccion'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="col-md-12">
                            <div class="form-group">
                                <label><i class="fas fa-clock"></i> Horario de Atención</label>
                                <input type="text" class="form-control" name="horario" value="<?php echo htmlspecialchars($config_gimnasio['horario'] ?? ''); ?>">
                                <small class="text-muted">Ejemplo: Lun-Vie 6am-10pm, Sáb 8am-6pm</small>
                            </div>
                        </div>
                    </div>
                    <div class="text-right">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-info-circle"></i> Acerca del Sistema</h3>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <ul class="list-unstyled">
                            <li><strong>Desarrollado por:</strong> Jesus Martinez</li>
                            <li><strong>Última actualización:</strong> <?php echo $ultima_actualizacion; ?></li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <div class="alert alert-info alert-ocultable" data-alerta-id="info_gimnasio">
                            <i class="fas fa-lightbulb"></i> <strong>Consejo:</strong> <?php echo $vistaGlobalConfiguracion ? 'La información corporativa se utiliza en reportes, correos y elementos compartidos.' : 'Los datos de esta sede aparecen en el selector, reportes y documentos vinculados a la sucursal.'; ?>
                            <button type="button" class="btn-ocultar" onclick="event.preventDefault(); event.stopPropagation(); ocultarAlerta('info_gimnasio')" title="Ocultar alerta">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'correo'): ?>
        <div class="card config-mail-card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-envelope-open-text"></i>
                    Configuración de Correo
                </h3>

                <span class="mail-config-status <?php
                echo $config_correo ? 'ready' : 'missing';
                ?>">
                    <i class="fas <?php
                    echo $config_correo
                        ? 'fa-circle-check'
                        : 'fa-triangle-exclamation';
                    ?>"></i>
                    <?php
                    echo $config_correo
                        ? 'Configurado'
                        : 'Falta ejecutar SQL';
                    ?>
                </span>
            </div>

            <div class="card-body">
                <?php if (!$config_correo): ?>
                    <div class="config-inline-notice warning">
                        <i class="fas fa-database"></i>
                        <div>
                            <strong>Configuración pendiente</strong>
                            <span>
                                Ejecuta primero
                                <b>configuracion_correo.sql</b>
                                para crear la tabla y cargar los
                                datos SMTP iniciales.
                            </span>
                        </div>
                    </div>
                <?php endif; ?>

                <form id="formCorreo">
                    <div class="config-form-modern">
                        <div class="form-group">
                            <label>Servidor SMTP</label>
                            <input
                                type="text"
                                class="form-control"
                                name="host"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $config_correo
                                        ? $config_correo['host']
                                        : 'smtp.gmail.com'
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Puerto</label>
                            <input
                                type="number"
                                class="form-control"
                                name="puerto"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $config_correo
                                        ? $config_correo['puerto']
                                        : '587'
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Usuario SMTP</label>
                            <input
                                type="email"
                                class="form-control"
                                name="usuario"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $config_correo
                                        ? $config_correo['usuario']
                                        : ''
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Contraseña o App Password</label>
                            <input
                                type="password"
                                class="form-control"
                                name="password_smtp"
                                placeholder="<?php
                                echo $config_correo
                                    ? 'Dejar en blanco para conservar'
                                    : 'Contraseña SMTP';
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Cifrado</label>
                            <select
                                class="form-control"
                                name="cifrado"
                            >
                                <?php
                                $cifrado_actual =
                                    $config_correo
                                        ? $config_correo['cifrado']
                                        : 'tls';
                                ?>
                                <option
                                    value="tls"
                                    <?php
                                    echo $cifrado_actual === 'tls'
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    TLS
                                </option>
                                <option
                                    value="ssl"
                                    <?php
                                    echo $cifrado_actual === 'ssl'
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    SSL
                                </option>
                                <option
                                    value="ninguno"
                                    <?php
                                    echo $cifrado_actual === 'ninguno'
                                        ? 'selected'
                                        : '';
                                    ?>
                                >
                                    Sin cifrado
                                </option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label>Correo remitente</label>
                            <input
                                type="email"
                                class="form-control"
                                name="remitente_email"
                                required
                                value="<?php
                                echo htmlspecialchars(
                                    $config_correo
                                        ? $config_correo[
                                            'remitente_email'
                                        ]
                                        : ''
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group">
                            <label>Nombre remitente</label>
                            <input
                                type="text"
                                class="form-control"
                                name="remitente_nombre"
                                readonly
                                value="<?php
                                echo htmlspecialchars(
                                    $config_gimnasio['nombre']
                                    ?? 'EGO'
                                );
                                ?>"
                            >
                        </div>

                        <div class="form-group full-width">
                            <div class="config-checks">
                                <label>
                                    <input
                                        type="checkbox"
                                        name="smtp_auth"
                                        <?php
                                        echo !$config_correo ||
                                            (int) $config_correo[
                                                'smtp_auth'
                                            ] === 1
                                                ? 'checked'
                                                : '';
                                        ?>
                                    >
                                    Autenticación SMTP
                                </label>

                                <label>
                                    <input
                                        type="checkbox"
                                        name="verificar_ssl"
                                        <?php
                                        echo $config_correo &&
                                            (int) $config_correo[
                                                'verificar_ssl'
                                            ] === 1
                                                ? 'checked'
                                                : '';
                                        ?>
                                    >
                                    Verificar certificado SSL
                                </label>

                                <label>
                                    <input
                                        type="checkbox"
                                        name="activo"
                                        <?php
                                        echo !$config_correo ||
                                            (int) $config_correo[
                                                'activo'
                                            ] === 1
                                                ? 'checked'
                                                : '';
                                        ?>
                                    >
                                    Envío de correo activo
                                </label>
                            </div>
                        </div>
                    </div>

                    <div class="config-inline-notice">
                        <i class="fas fa-shield-halved"></i>
                        <div>
                            <strong>Contraseña de aplicación</strong>
                            <span>
                                Para Gmail utiliza una App Password.
                                No uses la contraseña normal de la cuenta.
                            </span>
                        </div>
                    </div>

                    <div class="config-form-actions-modern">

                        <button
                            type="submit"
                            class="btn btn-primary"
                        >
                            <i class="fas fa-save"></i>
                            Guardar configuración
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion === 'seguridad' && $esSuperAdministradorActual): ?>
        <div class="card config-security-card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-shield-halved"></i>
                    Verificación en dos pasos
                </h3>
                <span class="mail-config-status <?php echo !empty($config_2fa['activo']) ? 'ready' : 'missing'; ?>">
                    <i class="fas <?php echo !empty($config_2fa['activo']) ? 'fa-circle-check' : 'fa-circle-pause'; ?>"></i>
                    <?php echo !empty($config_2fa['activo']) ? 'Protección activa' : 'Protección pausada'; ?>
                </span>
            </div>

            <div class="card-body">
                <div class="config-inline-notice">
                    <i class="fas fa-mobile-screen-button"></i>
                    <div>
                        <strong>Códigos desde una aplicación autenticadora</strong>
                        <span>Los usuarios validan su acceso con Google Authenticator, Microsoft Authenticator, Authy u otra aplicación TOTP. El correo no interviene en el inicio de sesión.</span>
                    </div>
                </div>

                <form id="formSeguridad2fa">
                    <input type="hidden" name="security_csrf" value="<?php echo configuracion_h($configSecurityCsrf); ?>">
                    <div class="config-security-toggle-main">
                        <label>
                            <input type="checkbox" name="activo" <?php echo !empty($config_2fa['activo']) ? 'checked' : ''; ?>>
                            <span>
                                <strong>Activar verificación en dos pasos</strong>
                                <small>Al desactivarla, las cuentas conservarán su configuración, pero el acceso no la exigirá.</small>
                            </span>
                        </label>
                    </div>

                    <div class="config-security-section">
                        <div class="config-security-section-heading">
                            <h4>Roles obligatorios</h4>
                            <p>La cuenta deberá configurar el autenticador antes de completar su acceso.</p>
                        </div>

                        <div class="config-role-security-grid">
                            <?php
                            $roles2fa = array(
                                'requerir_super_administrador' => array('Superadministrador', 'fa-user-shield'),
                                'requerir_admin' => array('Administrador', 'fa-user-gear'),
                                'requerir_recepcionista' => array('Recepcionista', 'fa-user-check'),
                                'requerir_entrenador' => array('Entrenador', 'fa-dumbbell')
                            );
                            foreach ($roles2fa as $campo2fa => $datosRol2fa):
                            ?>
                                <label class="config-role-security-option">
                                    <input
                                        type="checkbox"
                                        name="<?php echo configuracion_h($campo2fa); ?>"
                                        <?php echo !empty($config_2fa[$campo2fa]) ? 'checked' : ''; ?>
                                    >
                                    <span class="config-role-security-icon">
                                        <i class="fas <?php echo configuracion_h($datosRol2fa[1]); ?>"></i>
                                    </span>
                                    <span>
                                        <strong><?php echo configuracion_h($datosRol2fa[0]); ?></strong>
                                        <small>Exigir segundo factor al iniciar sesión</small>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="config-security-section">
                        <div class="config-security-section-heading">
                            <h4>Dispositivos confiables y bloqueos</h4>
                            <p>Controla la comodidad del acceso sin reducir la protección ante intentos repetidos.</p>
                        </div>

                        <div class="config-form-modern">
                            <div class="form-group full-width">
                                <label class="config-security-inline-check">
                                    <input type="checkbox" name="permitir_dispositivo_confiable" <?php echo !empty($config_2fa['permitir_dispositivo_confiable']) ? 'checked' : ''; ?>>
                                    <span>Permitir confiar en un dispositivo</span>
                                </label>
                            </div>

                            <div class="form-group">
                                <label>Días de confianza</label>
                                <input type="number" class="form-control" name="dias_dispositivo_confiable" min="1" max="90" value="<?php echo (int) ($config_2fa['dias_dispositivo_confiable'] ?? 30); ?>">
                            </div>

                            <div class="form-group">
                                <label>Intentos antes del bloqueo</label>
                                <input type="number" class="form-control" name="max_intentos" min="3" max="10" value="<?php echo (int) ($config_2fa['max_intentos'] ?? 5); ?>">
                            </div>

                            <div class="form-group">
                                <label>Minutos de bloqueo</label>
                                <input type="number" class="form-control" name="minutos_bloqueo" min="1" max="120" value="<?php echo (int) ($config_2fa['minutos_bloqueo'] ?? 15); ?>">
                            </div>

                            <div class="form-group">
                                <label>Nombre mostrado en el autenticador</label>
                                <input type="text" class="form-control" name="emisor" maxlength="120" value="<?php echo configuracion_h($config_2fa['emisor'] ?? 'Gym System'); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="config-form-actions-modern">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i>
                            Guardar política de seguridad
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'clientes'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-users"></i> Gestión de Socios</h3>
                <div class="card-tools">
                    <?php if (!$vistaGlobalConfiguracion): ?>
                        <button class="btn btn-primary btn-sm" onclick="abrirModal('modalCliente')">
                            <i class="fas fa-plus"></i> Nuevo Socio
                        </button>
                    <?php else: ?>
                        <span class="config-action-hint">
                            <i class="fas fa-location-dot"></i>
                            Elige una sucursal para registrar
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <?php if ($vistaGlobalConfiguracion): ?><th>Sucursal de registro</th><?php endif; ?>
                                <th>Teléfono</th>
                                <th>Email</th>
                                <th>Código QR</th>
                                <th>Estado</th>
                                <th>Fecha Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // El QR válido del socio se obtiene exclusivamente de clientes.codigo_qr.
                            // El listado ya fue preparado con el alcance multisucursal.
                            while($cliente = $clientes->fetch_assoc()):
                            ?>
                            <tr>
                                <td style="display: none;"><?php echo $cliente['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($cliente['nombre'] . ' ' . $cliente['apellido']); ?></strong></td>
                                <?php if ($vistaGlobalConfiguracion): ?><td><span class="config-branch-chip"><i class="fas fa-building"></i><?php echo configuracion_h($cliente['sucursal_nombre'] ?? 'Sin sucursal'); ?></span></td><?php endif; ?>
                                <td><?php echo htmlspecialchars($cliente['telefono'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($cliente['email'] ?? '-'); ?></td>
                                <td>
                                    <div class="qr-list-cell">
                                        <div
                                            class="qr-mini"
                                            data-qr="<?php echo htmlspecialchars($cliente['codigo_qr'] ?? ''); ?>"
                                        ></div>
                                        <span>
                                            <?php echo !empty($cliente['codigo_qr']) ? 'QR asignado' : 'Sin QR'; ?>
                                        </span>
                                    </div>
                                </td>
                                <td><span class="badge <?php echo $cliente['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $cliente['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td><?php echo date('d/m/Y', strtotime($cliente['fecha_registro'])); ?></td>
                                <td class="acciones-cliente">
                                    <button class="btn btn-warning btn-sm" onclick="editarCliente(<?php echo $cliente['id']; ?>)" title="Editar cliente"><i class="fas fa-edit"></i> Editar</button>
                                    <button
                                        class="btn btn-info btn-sm"
                                        onclick='verQrSocio(
                                            <?php echo (int) $cliente["id"]; ?>,
                                            <?php echo htmlspecialchars(json_encode($cliente["nombre"] . " " . $cliente["apellido"]), ENT_QUOTES, "UTF-8"); ?>,
                                            <?php echo htmlspecialchars(json_encode($cliente["codigo_qr"] ?? ""), ENT_QUOTES, "UTF-8"); ?>
                                        )'
                                        title="Ver código QR"
                                    >
                                        <i class="fas fa-qrcode"></i>
                                        Ver QR
                                    </button>
                                    <?php if ($vistaGlobalConfiguracion): ?>
                                        <button
                                            class="btn btn-danger btn-sm"
                                            onclick="eliminarCliente(<?php echo $cliente['id']; ?>)"
                                            title="Desactivar al socio en todo el sistema"
                                        >
                                            <i class="fas fa-user-slash"></i>
                                            Desactivar
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalCliente" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h4><i class="fas fa-user-plus"></i> Nuevo Cliente</h4>
                    <button class="modal-close">&times;</button>
                </div>
                <form id="formCliente">
                    <input type="hidden" name="contexto_sucursal_id" value="<?php echo (int) $sucursalIdConfiguracion; ?>">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="clienteId">
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="clienteNombre" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Apellido</label><input type="text" class="form-control" name="apellido" id="clienteApellido" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Teléfono</label><input type="text" class="form-control" name="telefono" id="clienteTelefono"></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Email</label><input type="email" class="form-control" name="email" id="clienteEmail"></div></div>
                            <div class="col-md-12">
                                <div class="form-group">
                                    <label>
                                        <?php echo $vistaGlobalConfiguracion
                                            ? 'Estado general del socio'
                                            : 'Estado general del socio (solo lectura)'; ?>
                                    </label>

                                    <select
                                        class="form-control"
                                        name="estado"
                                        id="clienteEstado"
                                        <?php echo $vistaGlobalConfiguracion ? '' : 'disabled'; ?>
                                    >
                                        <option value="activo">Activo</option>
                                        <option value="inactivo">Inactivo</option>
                                    </select>

                                    <?php if (!$vistaGlobalConfiguracion): ?>
                                        <small class="text-muted">
                                            El estado afecta todas las sucursales y solo puede
                                            cambiarse desde la vista consolidada.
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalCliente')">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Cliente</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="modalQr" class="modal">
            <div class="modal-content qr-modal-content">
                <div class="modal-header">
                    <h4>
                        <i class="fas fa-qrcode"></i>
                        Código QR del Socio
                    </h4>
                    <button class="modal-close">&times;</button>
                </div>

                <div class="modal-body">
                    <input type="hidden" id="qrClienteId">

                    <div class="qr-modal-layout">
                        <div id="qrGrande" class="qr-grande"></div>

                        <div class="qr-modal-info">
                            <h3 id="qrClienteNombre"></h3>

                            <p>
                                Este código identifica al socio en
                                los módulos de acceso y asistencia.
                            </p>

                            <code id="qrCodigoTexto"></code>

                            <div class="qr-modal-actions">
                                <button
                                    type="button"
                                    class="btn btn-light"
                                    onclick="copiarQr()"
                                >
                                    <i class="fas fa-copy"></i>
                                    Copiar
                                </button>

                                <button
                                    type="button"
                                    class="btn btn-dark"
                                    onclick="imprimirQr()"
                                >
                                    <i class="fas fa-print"></i>
                                    Imprimir
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button
                        type="button"
                        class="btn btn-secondary"
                        onclick="cerrarModal('modalQr')"
                    >
                        Cerrar
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'planes'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-tags"></i> <?php echo $vistaGlobalConfiguracion ? 'Catálogo de planes' : 'Planes disponibles en la sucursal'; ?></h3>
                <div class="card-tools">
                    <?php if ($vistaGlobalConfiguracion): ?><button class="btn btn-primary btn-sm" onclick="abrirModal('modalPlan')"><i class="fas fa-plus"></i> Nuevo Plan</button><?php else: ?><span class="config-action-hint"><i class="fas fa-circle-info"></i>Edita precio y disponibilidad</span><?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Duración</th>
                                <th>Precio</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($plan = $planes->fetch_assoc()): ?>
                            <tr>
                                <td style="display: none;"><?php echo $plan['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($plan['nombre']); ?></strong></td>
                                <td><?php echo $plan['duracion_dias']; ?> días</td>
                                <td>
                                    $<?php echo number_format(
                                        (float) ($plan['precio'] ?? 0),
                                        2,
                                        '.',
                                        ','
                                    ); ?>
                                </td>
                                <td><?php echo htmlspecialchars($plan['descripcion'] ?? '-'); ?></td>
                                <td><span class="badge <?php echo $plan['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $plan['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editarRegistro('planes', <?php echo $plan['id']; ?>, 'modalPlan')" title="Editar plan"><i class="fas fa-edit"></i> Editar</button>
                                    <?php if ($vistaGlobalConfiguracion): ?><button class="btn btn-danger btn-sm" onclick="eliminarRegistro('planes', <?php echo $plan['id']; ?>, 'plan')" title="Desactivar plan"><i class="fas fa-ban"></i> Desactivar</button><?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalPlan" class="modal">
            <div class="modal-content">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nuevo Plan</h4><button class="modal-close">&times;</button></div>
                <form id="formPlan">
                    <input type="hidden" name="contexto_sucursal_id" value="<?php echo (int) $sucursalIdConfiguracion; ?>">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="planId">
                        <div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="planNombre" <?php echo $vistaGlobalConfiguracion ? 'required' : 'readonly'; ?>></div>
                        <div class="form-group"><label>Duración (días)</label><input type="number" class="form-control" name="duracion_dias" id="planDuracion" <?php echo $vistaGlobalConfiguracion ? 'required' : 'readonly'; ?>></div>
                        <div class="form-group"><label><?php echo $vistaGlobalConfiguracion ? 'Precio base' : 'Precio en esta sucursal'; ?></label><input type="number" step="0.01" class="form-control" name="precio" id="planPrecio" required></div>
                        <div class="form-group"><label>Descripción</label><textarea class="form-control" name="descripcion" id="planDescripcion" <?php echo $vistaGlobalConfiguracion ? '' : 'readonly'; ?>></textarea></div>
                        <div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="planEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalPlan')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'productos'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-box"></i> <?php echo $vistaGlobalConfiguracion ? 'Catálogo de productos' : 'Inventario de la sucursal'; ?></h3>
                <div class="card-tools">
                    <?php if ($vistaGlobalConfiguracion): ?><button class="btn btn-primary btn-sm" onclick="abrirModal('modalProducto')"><i class="fas fa-plus"></i> Nuevo Producto</button><?php else: ?><span class="config-action-hint"><i class="fas fa-circle-info"></i>Edita precios y existencias locales</span><?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Categoría</th>
                                <th>Proveedor</th>
                                <th>Precio Venta</th>
                                <th>Stock</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($prod = $productos->fetch_assoc()): ?>
                            <tr>
                                <td style="display: none;"><?php echo $prod['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($prod['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($prod['categoria_nombre'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($prod['proveedor_nombre'] ?? '-'); ?></td>
                                <td>
                                    $<?php echo number_format(
                                        (float) ($prod['precio_venta'] ?? 0),
                                        2,
                                        '.',
                                        ','
                                    ); ?>
                                </td>
                                <td><?php echo $prod['stock']; ?> unidades</td>
                                <td><span class="badge <?php echo $prod['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $prod['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editarProducto(<?php echo $prod['id']; ?>)" title="Editar producto"><i class="fas fa-edit"></i> Editar</button>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('productos', <?php echo $prod['id']; ?>, 'producto')" title="Desactivar producto"><i class="fas fa-ban"></i> <?php echo $vistaGlobalConfiguracion ? 'Desactivar' : 'Desactivar en sede'; ?></button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalProducto" class="modal">
            <div class="modal-content" style="max-width: 700px;">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nuevo Producto</h4><button class="modal-close">&times;</button></div>
                <form id="formProducto">
                    <input type="hidden" name="contexto_sucursal_id" value="<?php echo (int) $sucursalIdConfiguracion; ?>">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="productoId">
                        <div class="row">
                            <div class="col-md-6"><div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="productoNombre" <?php echo $vistaGlobalConfiguracion ? 'required' : 'readonly'; ?>></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Categoría</label><select class="form-control" name="categoria_id" id="productoCategoria" <?php echo $vistaGlobalConfiguracion ? 'required' : 'disabled'; ?>><?php $catsProducto = $conn->query("SELECT id, nombre FROM categorias_productos WHERE estado='activo' ORDER BY nombre"); while ($cat = $catsProducto->fetch_assoc()): ?><option value="<?php echo $cat['id']; ?>"><?php echo htmlspecialchars($cat['nombre']); ?></option><?php endwhile; ?></select></div></div>
                            <div class="col-md-6"><div class="form-group"><label>Proveedor</label><select class="form-control" name="proveedor_id" id="productoProveedor" <?php echo $vistaGlobalConfiguracion ? '' : 'disabled'; ?>><option value="">Seleccionar</option><?php $provsProducto = $conn->query("SELECT id, nombre FROM proveedores WHERE estado='activo' ORDER BY nombre"); while ($prov = $provsProducto->fetch_assoc()): ?><option value="<?php echo $prov['id']; ?>"><?php echo htmlspecialchars($prov['nombre']); ?></option><?php endwhile; ?></select></div></div>
                            <div class="col-md-6"><div class="form-group"><label><?php echo $vistaGlobalConfiguracion ? 'Precio base de compra' : 'Precio de compra en esta sucursal'; ?></label><input type="number" step="0.01" class="form-control" name="precio_compra" id="productoPrecioCompra" required></div></div>
                            <div class="col-md-6"><div class="form-group"><label><?php echo $vistaGlobalConfiguracion ? 'Precio base de venta' : 'Precio de venta en esta sucursal'; ?></label><input type="number" step="0.01" class="form-control" name="precio_venta" id="productoPrecioVenta" required></div></div>
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>
                                        <?php echo $vistaGlobalConfiguracion
                                            ? 'Existencias totales (solo lectura)'
                                            : 'Existencias en esta sucursal'; ?>
                                    </label>

                                    <input
                                        type="number"
                                        class="form-control"
                                        name="stock"
                                        id="productoStock"
                                        value="<?php echo $vistaGlobalConfiguracion ? '0' : ''; ?>"
                                        <?php echo $vistaGlobalConfiguracion ? 'readonly' : 'required'; ?>
                                    >

                                    <?php if ($vistaGlobalConfiguracion): ?>
                                        <small class="text-muted">
                                            El stock se captura y modifica desde cada sucursal.
                                        </small>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="col-md-6"><div class="form-group"><label><?php echo $vistaGlobalConfiguracion ? 'Stock mínimo base' : 'Stock mínimo en esta sucursal'; ?></label><input type="number" class="form-control" name="stock_minimo" id="productoStockMinimo" value="10"></div></div>
                            <div class="col-12"><div class="form-group"><label>Descripción</label><textarea class="form-control" name="descripcion" id="productoDescripcion" <?php echo $vistaGlobalConfiguracion ? '' : 'readonly'; ?>></textarea></div></div>
                            <div class="col-12"><div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="productoEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div></div>
                        </div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalProducto')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'categorias'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-folder"></i> Categorías de Productos</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalCategoria')"><i class="fas fa-plus"></i> Nueva Categoría</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Descripción</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while (
                                $categorias instanceof mysqli_result
                                && $cat = $categorias->fetch_assoc()
                            ): ?>
                            <tr>
                                <td style="display: none;"><?php echo $cat['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($cat['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($cat['descripcion'] ?? '-'); ?></td>
                                <td><span class="badge <?php echo $cat['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $cat['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editarRegistro('categorias_productos', <?php echo $cat['id']; ?>, 'modalCategoria')" title="Editar categoría"><i class="fas fa-edit"></i> Editar</button>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('categorias_productos', <?php echo $cat['id']; ?>, 'categoria')" title="Eliminar categoría"><i class="fas fa-trash"></i> Eliminar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalCategoria" class="modal">
            <div class="modal-content">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nueva Categoría</h4><button class="modal-close">&times;</button></div>
                <form id="formCategoria">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="categoriaId">
                        <div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="categoriaNombre" required></div>
                        <div class="form-group"><label>Descripción</label><textarea class="form-control" name="descripcion" id="categoriaDescripcion"></textarea></div>
                        <div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="categoriaEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalCategoria')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'proveedores'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-truck"></i> Proveedores</h3>
                <div class="card-tools">
                    <button class="btn btn-primary btn-sm" onclick="abrirModal('modalProveedor')"><i class="fas fa-plus"></i> Nuevo Proveedor</button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Contacto</th>
                                <th>Teléfono</th>
                                <th>Email</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while (
                                $proveedores instanceof mysqli_result
                                && $prov = $proveedores->fetch_assoc()
                            ): ?>
                            <tr>
                                <td style="display: none;"><?php echo $prov['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($prov['nombre']); ?></strong></td>
                                <td><?php echo htmlspecialchars($prov['contacto'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($prov['telefono'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($prov['email'] ?? '-'); ?></td>
                                <td><span class="badge <?php echo $prov['estado'] == 'activo' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $prov['estado'] == 'activo' ? 'Activo' : 'Inactivo'; ?></span></td>
                                <td>
                                    <button class="btn btn-warning btn-sm" onclick="editarRegistro('proveedores', <?php echo $prov['id']; ?>, 'modalProveedor')" title="Editar proveedor"><i class="fas fa-edit"></i> Editar</button>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('proveedores', <?php echo $prov['id']; ?>, 'proveedor')" title="Eliminar proveedor"><i class="fas fa-trash"></i> Eliminar</button>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalProveedor" class="modal">
            <div class="modal-content">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nuevo Proveedor</h4><button class="modal-close">&times;</button></div>
                <form id="formProveedor">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="proveedorId">
                        <div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="proveedorNombre" required></div>
                        <div class="form-group"><label>Contacto</label><input type="text" class="form-control" name="contacto" id="proveedorContacto"></div>
                        <div class="form-group"><label>Teléfono</label><input type="text" class="form-control" name="telefono" id="proveedorTelefono"></div>
                        <div class="form-group"><label>Email</label><input type="email" class="form-control" name="email" id="proveedorEmail"></div>
                        <div class="form-group"><label>Dirección</label><textarea class="form-control" name="direccion" id="proveedorDireccion"></textarea></div>
                        <div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="proveedorEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalProveedor')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'clases'): ?>
        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-chalkboard-user"></i> <?php echo $vistaGlobalConfiguracion ? 'Clases de todas las sucursales' : 'Clases de la sucursal'; ?></h3>
                <div class="card-tools">
                    <?php if (!$vistaGlobalConfiguracion && $entrenadoresSucursal !== array()): ?>
                        <button class="btn btn-primary btn-sm" onclick="abrirModal('modalClase')">
                            <i class="fas fa-plus"></i> Nueva Clase
                        </button>
                    <?php elseif (!$vistaGlobalConfiguracion): ?>
                        <span class="config-action-hint">
                            <i class="fas fa-user-plus"></i>
                            Asigna primero un entrenador a esta sucursal
                        </span>
                    <?php else: ?>
                        <span class="config-action-hint">
                            <i class="fas fa-location-dot"></i>
                            Elige una sucursal para modificar clases
                        </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <?php if ($vistaGlobalConfiguracion): ?><th>Sucursal</th><?php endif; ?>
                                <th>Horario</th>
                                <th>Instructor</th>
                                <th>Cupo</th>
                                <th>Duración</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while ($clase = $clases->fetch_assoc()): ?>
                            <tr>
                                <td style="display: none;"><?php echo $clase['id']; ?></td>
                                <td><strong><?php echo htmlspecialchars($clase['nombre']); ?></strong></td>
                                <?php if ($vistaGlobalConfiguracion): ?><td><span class="config-branch-chip"><i class="fas fa-building"></i><?php echo configuracion_h($clase['sucursal_nombre']); ?></span></td><?php endif; ?>
                                <td><?php echo htmlspecialchars($clase['horario']); ?></td>
                                <td><?php echo htmlspecialchars($clase['instructor']); ?></td>
                                <td><?php echo $clase['cupo_actual']; ?>/<?php echo $clase['cupo_maximo']; ?></td>
                                <td><?php echo $clase['duracion_minutos']; ?> min</td>
                                <td><span class="badge <?php echo $clase['estado'] == 'activa' ? 'badge-success' : 'badge-danger'; ?>"><?php echo $clase['estado'] == 'activa' ? 'Activa' : 'Inactiva'; ?></span></td>
                                <td>
                                    <?php if (!$vistaGlobalConfiguracion): ?>
                                        <button class="btn btn-warning btn-sm" onclick="editarRegistro('clases', <?php echo $clase['id']; ?>, 'modalClase')" title="Editar clase"><i class="fas fa-edit"></i> Editar</button>
                                        <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('clases', <?php echo $clase['id']; ?>, 'clase')" title="Desactivar clase"><i class="fas fa-ban"></i> Desactivar</button>
                                    <?php else: ?>
                                        <span class="config-readonly-label"><i class="fas fa-eye"></i> Solo consulta</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalClase" class="modal">
            <div class="modal-content">
                <div class="modal-header"><h4><i class="fas fa-plus"></i> Nueva Clase</h4><button class="modal-close">&times;</button></div>
                <form id="formClase">
                    <input type="hidden" name="contexto_sucursal_id" value="<?php echo (int) $sucursalIdConfiguracion; ?>">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="claseId">
                        <div class="form-group"><label>Nombre</label><input type="text" class="form-control" name="nombre" id="claseNombre" required></div>
                        <div class="form-group"><label>Descripción</label><textarea class="form-control" name="descripcion" id="claseDescripcion"></textarea></div>
                        <div class="form-group"><label>Horario</label><input type="text" class="form-control" name="horario" id="claseHorario" placeholder="Ej: Lunes y Miércoles 7pm-8pm" required></div>
                        <div class="form-group"><label>Instructor</label><select class="form-control" name="instructor" id="claseInstructor" required><option value="">Selecciona un entrenador</option><?php foreach ($entrenadoresSucursal as $nombreEntrenador): ?><option value="<?php echo configuracion_h($nombreEntrenador); ?>"><?php echo configuracion_h($nombreEntrenador); ?></option><?php endforeach; ?></select><small class="text-muted">Solo aparecen entrenadores activos asignados a esta sucursal.</small></div>
                        <div class="form-group"><label>Cupo Máximo</label><input type="number" class="form-control" name="cupo_maximo" id="claseCupo" required></div>
                        <div class="form-group"><label>Duración (minutos)</label><input type="number" class="form-control" name="duracion_minutos" id="claseDuracion" value="60"></div>
                        <div class="form-group"><label>Estado</label><select class="form-control" name="estado" id="claseEstado"><option value="activa">Activa</option><option value="inactiva">Inactiva</option></select></div>
                    </div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" onclick="cerrarModal('modalClase')">Cancelar</button><button type="submit" class="btn btn-primary">Guardar</button></div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($seccion == 'usuarios'): ?>
        <div class="card config-system-password-card">
            <div class="card-header">
                <h3 class="card-title">
                    <i class="fas fa-key"></i>
                    Contraseña temporal del sistema
                </h3>

                <?php
                $tablaPasswordExiste = !empty(
                    $config_acceso['table_exists']
                );
                $passwordSistemaConfigurada = !empty(
                    $config_acceso['configured']
                );
                ?>

                <span class="mail-config-status <?php echo $tablaPasswordExiste ? 'ready' : 'missing'; ?>">
                    <i class="fas <?php echo $tablaPasswordExiste ? 'fa-circle-check' : 'fa-triangle-exclamation'; ?>"></i>
                    <?php
                    echo !$tablaPasswordExiste
                        ? 'Falta ejecutar SQL'
                        : ($passwordSistemaConfigurada
                            ? 'Personalizada'
                            : 'Usando ego1');
                    ?>
                </span>
            </div>

            <div class="card-body">
                <div class="config-system-password-intro">
                    <span class="config-system-password-icon">
                        <i class="fas fa-key"></i>
                    </span>
                    <div>
                        <strong>Una contraseña predeterminada por instalación</strong>
                        <p>
                            Esta contraseña se utilizará para todos los usuarios
                            nuevos y para los restablecimientos posteriores. El
                            usuario deberá cambiarla durante su primer acceso.
                        </p>
                    </div>
                </div>

                <?php if (!$tablaPasswordExiste): ?>
                    <div class="config-inline-notice warning">
                        <i class="fas fa-database"></i>
                        <div>
                            <strong>Configuración pendiente</strong>
                            <span>
                                Ejecuta
                                <b>database/instalar_password_temporal_sistema.sql</b>.
                                Mientras no se ejecute, el sistema conservará
                                <b>ego1</b> por compatibilidad.
                            </span>
                        </div>
                    </div>
                <?php else: ?>
                    <form id="formPasswordTemporalSistema" autocomplete="off">
                        <input
                            type="hidden"
                            name="security_csrf"
                            value="<?php echo configuracion_h($configSecurityCsrf); ?>"
                        >

                        <div class="config-system-password-grid">
                            <div class="form-group">
                                <label for="passwordTemporalSistema">
                                    Nueva contraseña temporal
                                </label>
                                <div class="config-password-control">
                                    <input
                                        type="password"
                                        class="form-control"
                                        name="password_temporal"
                                        id="passwordTemporalSistema"
                                        minlength="4"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        required
                                    >
                                    <button
                                        type="button"
                                        onclick="alternarPasswordSistema('passwordTemporalSistema', this)"
                                        aria-label="Mostrar u ocultar contraseña"
                                        title="Mostrar u ocultar contraseña"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label for="passwordTemporalSistemaConfirmacion">
                                    Confirmar contraseña
                                </label>
                                <div class="config-password-control">
                                    <input
                                        type="password"
                                        class="form-control"
                                        name="password_temporal_confirmacion"
                                        id="passwordTemporalSistemaConfirmacion"
                                        minlength="4"
                                        maxlength="72"
                                        autocomplete="new-password"
                                        required
                                    >
                                    <button
                                        type="button"
                                        onclick="alternarPasswordSistema('passwordTemporalSistemaConfirmacion', this)"
                                        aria-label="Mostrar u ocultar contraseña"
                                        title="Mostrar u ocultar contraseña"
                                    >
                                        <i class="fas fa-eye"></i>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <div class="config-password-feedback" id="passwordSistemaFeedback">
                            <span class="config-password-feedback-dot"></span>
                            <span>Mínimo 4 caracteres; se recomiendan 8 o más.</span>
                        </div>

                        <div class="config-system-password-meta">
                            <span>
                                <i class="fas fa-lock"></i>
                                La contraseña actual nunca se muestra en pantalla.
                            </span>
                            <?php if (!empty($config_acceso['updated_at'])): ?>
                                <span>
                                    <i class="fas fa-clock-rotate-left"></i>
                                    Actualizada el
                                    <?php echo date(
                                        'd/m/Y H:i',
                                        strtotime((string) $config_acceso['updated_at'])
                                    ); ?>
                                    <?php if (!empty($config_acceso['updated_by_name'])): ?>
                                        por
                                        <strong><?php echo configuracion_h(
                                            (string) $config_acceso['updated_by_name']
                                        ); ?></strong>
                                    <?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </div>

                        <div class="config-form-actions-modern config-system-password-actions">
                            <button
                                type="button"
                                class="btn btn-light"
                                onclick="generarPasswordSistema()"
                            >
                                <i class="fas fa-wand-magic-sparkles"></i>
                                Generar segura
                            </button>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i>
                                Guardar contraseña predeterminada
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header">
                <h3 class="card-title"><i class="fas fa-user-shield"></i> <?php echo $vistaGlobalConfiguracion ? 'Usuarios del sistema' : 'Personal asignado a la sucursal'; ?></h3>
                <div class="card-tools">
                    <?php if (!$vistaGlobalConfiguracion): ?>
                        <button class="btn btn-primary btn-sm" onclick="abrirModal('modalUsuario')">
                            <i class="fas fa-plus"></i> Nuevo Usuario
                        </button>
                    <?php else: ?>
                        <span class="config-action-hint"><i class="fas fa-location-dot"></i>Elige una sucursal para crear y asignar</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive config-users-table-wrap">
                    <table class="table table-bordered table-hover config-users-table">
                        <thead class="thead-light">
                            <tr>
                                <th style="display: none;">ID</th>
                                <th>Nombre</th>
                                <th>Email</th>
                                <th>Rol</th>
                                <th>Estado</th>
                                <th>Acceso</th>
                                <th>Fecha Registro</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            // El listado ya fue preparado con el alcance multisucursal.
                            $roles_map = ['super_administrador' => 'Super administrador', 'admin' => 'Administrador', 'recepcionista' => 'Recepcionista', 'entrenador' => 'Entrenador'];
                            while($user = $usuarios->fetch_assoc()):
                            ?>
                            <tr>
                                <td style="display: none;"><?php echo $user['id']; ?></td>
                                <td data-label="Nombre">
                                    <strong>
                                        <?php echo htmlspecialchars(
                                            $user['nombre']
                                        ); ?>
                                    </strong>
                                </td>
                                <td data-label="Email">
                                    <span class="config-user-email">
                                        <?php echo htmlspecialchars(
                                            $user['email']
                                        ); ?>
                                    </span>
                                </td>
                                <td data-label="Rol">
                                    <span class="badge badge-info config-user-role-badge">
                                        <?php echo $roles_map[$user['rol']]
                                            ?? $user['rol']; ?>
                                    </span>

                                    <?php if ($vistaGlobalConfiguracion): ?>
                                        <small class="config-sedes-count">
                                            <?php echo (int) (
                                                $user['sedes_activas'] ?? 0
                                            ); ?>
                                            sede(s)
                                        </small>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Estado">
                                    <span class="badge <?php echo $user['estado'] === 'activo' ? 'badge-success' : 'badge-danger'; ?>">
                                        <?php echo $user['estado'] === 'activo' ? ($vistaGlobalConfiguracion ? 'Activo' : 'Acceso activo') : ($vistaGlobalConfiguracion ? 'Inactivo' : 'Acceso suspendido'); ?>
                                    </span>
                                </td>
                                <td data-label="Acceso">
                                    <div class="config-access-statuses">
                                        <?php if ($user['estado'] !== 'activo'): ?>
                                            <span class="badge badge-secondary">
                                                <i class="fas fa-ban"></i> Sin acceso
                                            </span>
                                        <?php elseif ((int) $user['password_change_required'] === 1): ?>
                                            <span class="badge badge-warning">
                                                <i class="fas fa-clock"></i> Debe cambiar contraseña
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-success">
                                                <i class="fas fa-check-circle"></i> Contraseña lista
                                            </span>
                                        <?php endif; ?>

                                        <?php if ((int) ($user['two_factor_enabled'] ?? 0) === 1): ?>
                                            <span class="badge badge-2fa-active">
                                                <i class="fas fa-shield-halved"></i> 2FA activo
                                            </span>
                                        <?php else: ?>
                                            <span class="badge badge-2fa-pending">
                                                <i class="fas fa-mobile-screen-button"></i> Configurar 2FA
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td data-label="Fecha registro">
                                    <?php echo date(
                                        'd/m/Y',
                                        strtotime(
                                            $user['fecha_registro']
                                        )
                                    ); ?>
                                </td>

                                <td
                                    data-label="Acciones"
                                    class="config-users-actions-cell"
                                >
                                    <div class="config-users-actions">
                                        <button
                                            class="btn btn-warning btn-sm"
                                            onclick="editarUsuario(<?php echo $user['id']; ?>)"
                                            title="Editar información del usuario"
                                        >
                                            <i class="fas fa-edit"></i>
                                            Editar
                                        </button>
                                    <?php if(
                                        (int) $user['id'] !== 1
                                        && (int) $user['id'] !== $usuario_id
                                        && $user['estado'] === 'activo'
                                    ): ?>
                                    <button class="btn btn-danger btn-sm" onclick="eliminarRegistro('usuarios', <?php echo $user['id']; ?>, 'usuario')" title="<?php echo $vistaGlobalConfiguracion ? 'Desactivar usuario' : 'Quitar acceso de esta sucursal'; ?>"><i class="fas fa-user-slash"></i> <?php echo $vistaGlobalConfiguracion ? 'Desactivar' : 'Quitar acceso'; ?></button>
                                    <?php endif; ?>

                                    <?php
                                    $restablecimientoPendiente =
                                        (int) $user['password_change_required'] === 1;
                                    $usuarioSinAcceso =
                                        $user['estado'] !== 'activo';
                                    $bloquearRestablecer =
                                        $restablecimientoPendiente ||
                                        $usuarioSinAcceso;
                                    ?>

                                    <button
                                        class="btn btn-secondary btn-sm"
                                        <?php if (!$bloquearRestablecer): ?>
                                            onclick='restablecerPassword(
                                                <?php echo (int) $user["id"]; ?>,
                                                <?php echo htmlspecialchars(
                                                    json_encode($user["nombre"]),
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ); ?>
                                            )'
                                        <?php else: ?>
                                            disabled
                                        <?php endif; ?>
                                        title="<?php
                                        echo $usuarioSinAcceso
                                            ? 'El usuario no tiene acceso al sistema'
                                            : ($restablecimientoPendiente
                                                ? 'El usuario ya tiene un cambio de contraseña pendiente'
                                                : 'Restablecer con la contraseña temporal configurada para el sistema');
                                        ?>"
                                    >
                                        <i class="fas <?php echo $restablecimientoPendiente ? 'fa-clock' : 'fa-key'; ?>"></i>
                                        <?php echo $restablecimientoPendiente ? 'Pendiente' : 'Restablecer'; ?>
                                    </button>

                                    <?php if (
                                        (int) ($user['two_factor_enabled'] ?? 0) === 1
                                        && (int) $user['id'] !== $usuario_id
                                    ): ?>
                                        <button
                                            class="btn btn-info btn-sm"
                                            onclick='restablecerDosPasos(
                                                <?php echo (int) $user["id"]; ?>,
                                                <?php echo htmlspecialchars(
                                                    json_encode($user["nombre"]),
                                                    ENT_QUOTES,
                                                    "UTF-8"
                                                ); ?>
                                            )'
                                            title="Restablecer verificación en dos pasos"
                                        >
                                            <i class="fas fa-shield-halved"></i>
                                            Restablecer 2FA
                                        </button>
                                    <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div id="modalUsuario" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h4><i class="fas fa-user-plus"></i> Nuevo Usuario</h4>
                    <button class="modal-close">&times;</button>
                </div>
                <form id="formUsuario">
                    <input type="hidden" name="contexto_sucursal_id" value="<?php echo (int) $sucursalIdConfiguracion; ?>">
                    <div class="modal-body">
                        <input type="hidden" name="id" id="usuarioId">
                        <div class="form-group"><label><i class="fas fa-user"></i> Nombre Completo</label><input type="text" class="form-control" name="nombre" id="usuarioNombre" required></div>
                        <div class="form-group"><label><i class="fas fa-envelope"></i> Email</label><input type="email" class="form-control" name="email" id="usuarioEmail" required></div>
                        <div class="form-group"><label><i class="fas fa-user-tag"></i> <?php echo $vistaGlobalConfiguracion ? 'Rol general' : 'Función en esta sucursal'; ?></label><select class="form-control" name="rol" id="usuarioRol" required><option value="recepcionista">Recepcionista</option><option value="entrenador">Entrenador</option><option value="admin">Administrador</option></select></div>
                        <div class="form-group"><label><i class="fas fa-circle"></i> <?php echo $vistaGlobalConfiguracion ? 'Estado de la cuenta' : 'Acceso a esta sucursal'; ?></label><select class="form-control" name="estado" id="usuarioEstado"><option value="activo">Activo</option><option value="inactivo">Inactivo</option></select></div>
                        <div class="alert alert-info alert-ocultable" data-alerta-id="usuario_info">
                            <i class="fas fa-info-circle"></i> <strong>Información:</strong> Los nuevos usuarios recibirán la contraseña temporal configurada para este sistema. Deberán cambiarla durante el primer inicio de sesión.
                            <button type="button" class="btn-ocultar" onclick="event.preventDefault(); event.stopPropagation(); ocultarAlerta('usuario_info')" title="Ocultar alerta">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalUsuario')">Cancelar</button>
                        <button type="submit" class="btn btn-primary">Guardar Usuario</button>
                    </div>
                </form>
            </div>
        </div>

        <div id="modalRestablecer" class="modal">
            <div class="modal-content">
                <div class="modal-header">
                    <h4><i class="fas fa-key"></i> Restablecer Contraseña</h4>
                    <button class="modal-close">&times;</button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="resetUsuarioId">
                    <input type="hidden" id="resetUsuarioNombre">
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>¿Está seguro?</strong>
                        <p class="mt-2 mb-0">
                            La contraseña de <strong id="resetNombreMostrar"></strong>
                            se restablecerá con la contraseña temporal predeterminada
                            configurada para este sistema y se enviará por correo.
                        </p>
                        <p class="mt-2 mb-0 text-muted small">
                            El usuario deberá cambiarla durante su próximo inicio de sesión.
                        </p>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="cerrarModal('modalRestablecer')">Cancelar</button>
                    <button type="button" class="btn btn-primary" onclick="confirmarRestablecer()">Sí, restablecer</button>
                </div>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>

    <script>
        const CONFIG_VISTA_GLOBAL = <?php echo $vistaGlobalConfiguracion ? 'true' : 'false'; ?>;
        const CONFIG_SUCURSAL_NOMBRE = <?php echo json_encode($sucursalNombreConfiguracion, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;
        const CONFIG_SECURITY_CSRF = <?php echo json_encode($configSecurityCsrf, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>;

        function alternarPasswordSistema(inputId, boton) {
            const input = document.getElementById(inputId);

            if (!input) {
                return;
            }

            const mostrar = input.type === 'password';
            input.type = mostrar ? 'text' : 'password';

            const icono = boton
                ? boton.querySelector('i')
                : null;

            if (icono) {
                icono.className = mostrar
                    ? 'fas fa-eye-slash'
                    : 'fas fa-eye';
            }
        }

        function evaluarPasswordSistema() {
            const password = document.getElementById(
                'passwordTemporalSistema'
            );
            const confirmacion = document.getElementById(
                'passwordTemporalSistemaConfirmacion'
            );
            const feedback = document.getElementById(
                'passwordSistemaFeedback'
            );

            if (!password || !confirmacion || !feedback) {
                return;
            }

            const valor = password.value;
            const coincide = valor !== ''
                && valor === confirmacion.value;
            let nivel = 'neutral';
            let texto = 'Mínimo 4 caracteres; se recomiendan 8 o más.';

            if (valor.length > 0 && valor.length < 4) {
                nivel = 'weak';
                texto = 'La contraseña todavía es demasiado corta.';
            } else if (valor.length >= 4 && valor.length < 8) {
                nivel = coincide ? 'medium' : 'weak';
                texto = coincide
                    ? 'Contraseña válida, aunque se recomienda una más larga.'
                    : 'Confirma exactamente la misma contraseña.';
            } else if (valor.length >= 8) {
                const categorias = [
                    /[a-z]/.test(valor),
                    /[A-Z]/.test(valor),
                    /[0-9]/.test(valor),
                    /[^a-zA-Z0-9]/.test(valor)
                ].filter(Boolean).length;

                nivel = coincide && categorias >= 3
                    ? 'strong'
                    : (coincide ? 'medium' : 'weak');
                texto = !coincide
                    ? 'Confirma exactamente la misma contraseña.'
                    : (categorias >= 3
                        ? 'Contraseña segura y lista para guardar.'
                        : 'Contraseña válida; combina letras, números y símbolos para mejorarla.');
            }

            feedback.className =
                'config-password-feedback ' + nivel;
            feedback.querySelector('span:last-child').textContent = texto;
        }

        function generarPasswordSistema() {
            const password = document.getElementById(
                'passwordTemporalSistema'
            );
            const confirmacion = document.getElementById(
                'passwordTemporalSistemaConfirmacion'
            );

            if (!password || !confirmacion) {
                return;
            }

            const mayusculas = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
            const minusculas = 'abcdefghijkmnopqrstuvwxyz';
            const numeros = '23456789';
            const simbolos = '!@#$%*-_';
            const todos = mayusculas + minusculas + numeros + simbolos;
            const seleccionar = function (fuente) {
                const valores = new Uint32Array(1);
                window.crypto.getRandomValues(valores);
                return fuente[valores[0] % fuente.length];
            };
            let generado = seleccionar(mayusculas)
                + seleccionar(minusculas)
                + seleccionar(numeros)
                + seleccionar(simbolos);

            while (generado.length < 12) {
                generado += seleccionar(todos);
            }

            generado = generado
                .split('')
                .sort(function () {
                    const valores = new Uint32Array(1);
                    window.crypto.getRandomValues(valores);
                    return (valores[0] % 3) - 1;
                })
                .join('');

            password.value = generado;
            confirmacion.value = generado;
            password.type = 'text';
            confirmacion.type = 'text';
            evaluarPasswordSistema();
        }

        // Funciones de Modal
        function abrirModal(modalId) {
            $('#' + modalId).addClass('active');
        }

        function cerrarModal(modalId) {
            $('#' + modalId).removeClass('active');
            $('#' + modalId + ' form')[0]?.reset();
            $('#' + modalId + ' input[name="id"]').val('');
            // Restaurar título del modal a su estado original
            let tituloOriginal = '';
            switch(modalId) {
                case 'modalPlan': tituloOriginal = 'Nuevo Plan'; break;
                case 'modalCategoria': tituloOriginal = 'Nueva Categoría'; break;
                case 'modalProveedor': tituloOriginal = 'Nuevo Proveedor'; break;
                case 'modalProducto': tituloOriginal = 'Nuevo Producto'; break;
                case 'modalClase': tituloOriginal = 'Nueva Clase'; break;
                case 'modalUsuario': tituloOriginal = 'Nuevo Usuario'; break;
                case 'modalCliente': tituloOriginal = 'Nuevo Cliente'; break;
                default: tituloOriginal = 'Nuevo Registro';
            }
            $('#' + modalId + ' .modal-header h4').html('<i class="fas fa-plus"></i> ' + tituloOriginal);
        }

        $(document).ready(function() {
            $('.modal-close').on('click', function() {
                $(this).closest('.modal').removeClass('active');
            });

            $('.modal').on('click', function(e) {
                if (e.target === this) {
                    $(this).removeClass('active');
                }
            });
            cargarEstadoAlertas();
        });

        // Funciones genéricas para editar y eliminar
        function editarRegistro(tabla, id, modalId) {
            console.log('Editando registro - Tabla:', tabla, 'ID:', id, 'Modal:', modalId);

            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'get_registro', tabla: tabla, id: id },
                dataType: 'json',
                success: function(data) {
                    console.log('Datos recibidos:', data);

                    let form = $('#' + modalId + ' form');
                    let modal = $('#' + modalId);

                    // Limpiar el formulario primero
                    if (form[0]) form[0].reset();

                    // Asignar el ID
                    form.find('input[name="id"]').val(data.id);

                    // Llenar los demás campos
                    for(let key in data) {
                        let input = form.find('[name="' + key + '"]');
                        if(input.length) {
                            input.val(data[key]);
                            console.log('Campo ' + key + ' asignado con valor:', data[key]);
                        }
                    }

                    // Cambiar el título del modal
                    let titulo = '';
                    switch(tabla) {
                        case 'planes': titulo = 'Editar Plan'; break;
                        case 'categorias_productos': titulo = 'Editar Categoría'; break;
                        case 'proveedores': titulo = 'Editar Proveedor'; break;
                        case 'productos': titulo = 'Editar Producto'; break;
                        case 'clases': titulo = 'Editar Clase'; break;
                        case 'usuarios': titulo = 'Editar Usuario'; break;
                        default: titulo = 'Editar Registro';
                    }
                    modal.find('.modal-header h4').html('<i class="fas fa-edit"></i> ' + titulo);

                    // Abrir el modal
                    abrirModal(modalId);
                },
                error: function(xhr, status, error) {
                    console.error('Error:', error);
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: 'No se pudo cargar el registro. Detalles: ' + error,
                        target: document.body
                    });
                }
            });
        }

        function eliminarRegistro(tabla, id, tipo) {
            const esUsuario = tipo === 'usuario';

            Swal.fire({
                title: esUsuario
                    ? (CONFIG_VISTA_GLOBAL ? '¿Desactivar usuario?' : '¿Quitar acceso de la sucursal?')
                    : '¿Desactivar registro?',
                html: esUsuario
                    ? (CONFIG_VISTA_GLOBAL ? '<p>El usuario dejará de tener acceso a todo el sistema.</p>' : '<p>El usuario dejará de tener acceso únicamente a <strong>' + $('<div>').text(CONFIG_SUCURSAL_NOMBRE).html() + '</strong>.</p>') +
                      '<p style="margin-bottom:0;font-size:13px;color:#667085;">' +
                      'Su historial de ventas, movimientos y registros se ' +
                      'conservará para mantener la integridad de la información.' +
                      '</p>'
                    : 'El registro se conservará, pero dejará de estar disponible.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, desactivar',
                cancelButtonText: 'Cancelar',
                target: document.body
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                let action = '';
                if (tipo === 'plan') action = 'delete_plan';
                else if (tipo === 'categoria') action = 'delete_categoria';
                else if (tipo === 'proveedor') action = 'delete_proveedor';
                else if (tipo === 'producto') action = 'delete_producto';
                else if (tipo === 'clase') action = 'delete_clase';
                else if (tipo === 'usuario') action = 'delete_usuario';

                $.ajax({
                    url: 'configuracion.php',
                    method: 'POST',
                    dataType: 'json',
                    data: { action: action, id: id },
                    success: function(response) {
                        if (!response.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text: response.message || 'No se pudo completar la acción.',
                                target: document.body
                            });
                            return;
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Actualizado',
                            text: response.message || 'Registro desactivado correctamente.',
                            target: document.body,
                            timer: 1500,
                            showConfirmButton: false
                        });

                        setTimeout(() => location.reload(), 1500);
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: 'No se pudo completar la acción.',
                            target: document.body
                        });
                    }
                });
            });
        }

        // Funciones para Clientes
        function editarCliente(id) {
            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'get_registro', tabla: 'clientes', id: id },
                dataType: 'json',
                success: function(data) {
                    $('#clienteId').val(data.id);
                    $('#clienteNombre').val(data.nombre);
                    $('#clienteApellido').val(data.apellido);
                    $('#clienteTelefono').val(data.telefono);
                    $('#clienteEmail').val(data.email);
                    $('#clienteEstado').val(data.estado);
                    $('#modalCliente .modal-header h4').html('<i class="fas fa-edit"></i> Editar Cliente');
                    abrirModal('modalCliente');
                }
            });
        }

        function eliminarCliente(id) {
            Swal.fire({
                title: '¿Desactivar socio?',
                text: 'El socio quedará inactivo en todas las sucursales.',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, desactivar',
                cancelButtonText: 'Cancelar',
                target: document.body
            }).then((result) => {
                if (result.isConfirmed) {
                    $.ajax({
                        url: 'configuracion.php',
                        method: 'POST',
                        data: { action: 'delete_cliente', id: id },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({ icon: 'success', title: 'Socio desactivado', text: 'El socio quedó inactivo correctamente', target: document.body, timer: 1500, showConfirmButton: false });
                                setTimeout(() => location.reload(), 1500);
                            } else {
                                Swal.fire({ icon: 'error', title: 'Error', text: response.message || response.error || 'No se pudo desactivar', target: document.body });
                            }
                        }
                    });
                }
            });
        }

        let qrActual = '';
        let qrNombreActual = '';

        function renderQr(elemento, valor, tamano) {
            const contenedor =
                typeof elemento === 'string'
                    ? document.getElementById(elemento)
                    : elemento;

            if (!contenedor) return;

            contenedor.innerHTML = '';

            if (!valor) {
                contenedor.innerHTML =
                    '<i class="fas fa-qrcode qr-empty-icon"></i>';
                return;
            }

            new QRCode(contenedor, {
                text: valor,
                width: tamano,
                height: tamano,
                colorDark: '#15263a',
                colorLight: '#ffffff',
                correctLevel: QRCode.CorrectLevel.M
            });
        }

        function renderQrMiniaturas() {
            document
                .querySelectorAll('.qr-mini')
                .forEach(function(elemento) {
                    renderQr(
                        elemento,
                        elemento.dataset.qr || '',
                        64
                    );
                });
        }

        function verQrSocio(id, nombre, codigo) {
            qrActual = codigo || '';
            qrNombreActual = nombre || '';

            $('#qrClienteId').val(id);
            $('#qrClienteNombre').text(nombre);
            $('#qrCodigoTexto').text(
                qrActual || 'Sin código QR'
            );

            renderQr('qrGrande', qrActual, 210);
            abrirModal('modalQr');
        }

        async function copiarQr() {
            if (!qrActual) return;

            try {
                await navigator.clipboard.writeText(qrActual);

                Swal.fire({
                    icon: 'success',
                    title: 'Código copiado',
                    timer: 1200,
                    showConfirmButton: false
                });
            } catch (error) {
                Swal.fire({
                    icon: 'error',
                    title: 'No se pudo copiar'
                });
            }
        }

        function imprimirQr() {
            if (!qrActual) return;

            const contenedor =
                document.getElementById('qrGrande');

            const canvas =
                contenedor.querySelector('canvas');

            const imagen =
                contenedor.querySelector('img');

            let dataUrl = '';

            if (canvas) {
                dataUrl = canvas.toDataURL('image/png');
            } else if (imagen) {
                dataUrl = imagen.src;
            }

            if (!dataUrl) return;

            const ventana = window.open(
                '',
                '_blank',
                'width=520,height=650'
            );

            ventana.document.write(
                '<!DOCTYPE html>' +
                '<html lang="es">' +
                '<head>' +
                '<meta charset="UTF-8">' +
                '<title>QR del socio</title>' +
                '<style>' +
                'body{font-family:Arial,sans-serif;' +
                'margin:0;padding:30px;text-align:center;' +
                'color:#15263a;}' +
                '.qr-ticket{width:320px;margin:auto;' +
                'padding:24px;border:1px solid #dce3eb;' +
                'border-radius:12px;}' +
                'img{width:240px;height:240px;}' +
                'h2{margin:16px 0 6px;}' +
                'p{font-family:monospace;font-size:11px;' +
                'color:#667085;word-break:break-all;}' +
                '@media print{body{padding:0;}' +
                '.qr-ticket{border:0;}}' +
                '</style>' +
                '</head>' +
                '<body>' +
                '<div class="qr-ticket">' +
                '<img src="' + dataUrl + '" alt="QR">' +
                '<h2>' + $('<div>').text(qrNombreActual).html() +
                '</h2>' +
                '<p>' + $('<div>').text(qrActual).html() +
                '</p>' +
                '</div>' +
                '<script>window.onload=function(){window.print();};' +
                '<\/script>' +
                '</body>' +
                '</html>'
            );

            ventana.document.close();
        }

        // Funciones para Productos
        function editarProducto(id) {
            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'get_registro', tabla: 'productos', id: id },
                dataType: 'json',
                success: function(data) {
                    $('#productoId').val(data.id);
                    $('#productoNombre').val(data.nombre);
                    $('#productoDescripcion').val(data.descripcion);
                    $('#productoCategoria').val(data.categoria_id);
                    $('#productoProveedor').val(data.proveedor_id);
                    $('#productoPrecioCompra').val(data.precio_compra);
                    $('#productoPrecioVenta').val(data.precio_venta);
                    $('#productoStock').val(data.stock);
                    $('#productoStockMinimo').val(data.stock_minimo);
                    $('#productoEstado').val(data.estado);
                    $('#modalProducto .modal-header h4').html('<i class="fas fa-edit"></i> Editar Producto');
                    abrirModal('modalProducto');
                }
            });
        }

        // Funciones para Usuarios
        function editarUsuario(id) {
            $.ajax({
                url: 'configuracion.php',
                method: 'POST',
                data: { action: 'get_registro', tabla: 'usuarios', id: id },
                dataType: 'json',
                success: function(data) {
                    $('#usuarioId').val(data.id);
                    $('#usuarioNombre').val(data.nombre);
                    $('#usuarioEmail').val(data.email);
                    $('#usuarioRol').val(data.rol);
                    $('#usuarioEstado').val(data.estado);
                    $('#modalUsuario .modal-header h4').html('<i class="fas fa-edit"></i> Editar Usuario');
                    abrirModal('modalUsuario');
                }
            });
        }

        function restablecerPassword(id, nombre) {
            $('#resetUsuarioId').val(id);
            $('#resetUsuarioNombre').val(nombre);
            $('#resetNombreMostrar').text(nombre);
            abrirModal('modalRestablecer');
        }

        function confirmarRestablecer() {
            let id = $('#resetUsuarioId').val();
            let nombre = $('#resetUsuarioNombre').val();

            Swal.fire({
                title: 'Restablecer contraseña',
                html:
                    'La contraseña de <strong>' +
                    $('<div>').text(nombre).html() +
                    '</strong> se restablecerá a la contraseña ' +
                    'predeterminada configurada para este sistema y se enviará ' +
                    'por correo. El usuario deberá cambiarla en su ' +
                    'próximo inicio de sesión.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Sí, restablecer',
                cancelButtonText: 'Cancelar',
                target: document.body
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Actualizando contraseña...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading(),
                    target: document.body
                });

                $.ajax({
                    url: 'configuracion.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'cambiar_password',
                        id: id,
                        security_csrf: CONFIG_SECURITY_CSRF
                    },
                    success: function(response) {
                        if (!response.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text:
                                    response.message ||
                                    'No se pudo restablecer.',
                                target: document.body
                            });
                            return;
                        }

                        cerrarModal('modalRestablecer');

                        if (response.correo_enviado) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Contraseña restablecida',
                                text:
                                    'La contraseña predeterminada del sistema fue asignada. ' +
                                    'Las credenciales fueron enviadas por correo.',
                                target: document.body
                            });
                        } else {
                            Swal.fire({
                                icon: 'warning',
                                title: 'Contraseña restablecida',
                                html:
                                    '<p>El correo no pudo enviarse.</p>' +
                                    '<p><strong>Contraseña temporal:' +
                                    '</strong></p>' +
                                    '<code style="font-size:16px;">' +
                                    $('<div>')
                                        .text(
                                            response.password_temporal ||
                                            ''
                                        )
                                        .html() +
                                    '</code>' +
                                    '<p style="' +
                                    'margin-top:12px;font-size:12px;' +
                                    'color:#667085;">' +
                                    $('<div>')
                                        .text(
                                            response.correo_error || ''
                                        )
                                        .html() +
                                    '</p>',
                                target: document.body
                            });
                        }
                    },
                    error: function() {
                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text:
                                'No se pudo restablecer la contraseña.',
                            target: document.body
                        });
                    }
                });
            });
        }

        function restablecerDosPasos(id, nombre) {
            Swal.fire({
                icon: 'warning',
                title: '¿Restablecer verificación en dos pasos?',
                html:
                    '<p>La configuración de <strong>' +
                    $('<div>').text(nombre).html() +
                    '</strong> será eliminada.</p>' +
                    '<p style="margin-bottom:0;color:#667085;font-size:13px;">' +
                    'Se cerrarán sus sesiones actuales, se revocarán los ' +
                    'dispositivos confiables y deberá vincular una nueva ' +
                    'aplicación en el próximo acceso.</p>',
                showCancelButton: true,
                confirmButtonText: 'Sí, restablecer 2FA',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc2626',
                target: document.body
            }).then((result) => {
                if (!result.isConfirmed) {
                    return;
                }

                Swal.fire({
                    title: 'Restableciendo seguridad...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading(),
                    target: document.body
                });

                $.ajax({
                    url: 'configuracion.php',
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'reset_2fa',
                        id: id,
                        security_csrf: CONFIG_SECURITY_CSRF
                    },
                    success: function(response) {
                        if (!response.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'No se pudo restablecer',
                                text: response.message || 'Ocurrió un error.',
                                target: document.body
                            });
                            return;
                        }

                        Swal.fire({
                            icon: 'success',
                            title: '2FA restablecido',
                            text: response.message,
                            confirmButtonText: 'Aceptar',
                            target: document.body
                        }).then(() => location.reload());
                    },
                    error: function(xhr) {
                        Swal.fire({
                            icon: 'error',
                            title: 'No se pudo restablecer',
                            text: xhr.responseJSON?.message || 'Ocurrió un error al restablecer la seguridad.',
                            target: document.body
                        });
                    }
                });
            });
        }

        // ==================== FUNCIONES PARA ALERTAS OCULTABLES ====================
        function ocultarAlerta(alertaId) {
            localStorage.setItem('alerta_oculta_' + alertaId, 'true');
            let $alerta = $('[data-alerta-id="' + alertaId + '"]');
            $alerta.addClass('oculto');

            let textoBoton = '';
            let iconoBoton = '';
            switch(alertaId) {
                case 'info_gimnasio': textoBoton = 'Ver consejo'; iconoBoton = 'fa-lightbulb'; break;
                case 'huella_info': textoBoton = 'Ver instrucciones'; iconoBoton = 'fa-fingerprint'; break;
                case 'usuario_info': textoBoton = 'Más información'; iconoBoton = 'fa-info-circle'; break;
                case 'logo_info': textoBoton = 'Mostrar recomendaciones'; iconoBoton = 'fa-image'; break;
                default: textoBoton = 'Mostrar alerta'; iconoBoton = 'fa-eye';
            }

            if ($alerta.next('.alert-boton-container').length === 0) {
                let $contenedor = $('<div class="alert-boton-container"></div>');
                let $botonMostrar = $('<button class="btn-mostrar-alerta" onclick="mostrarAlertaEspecifica(\'' + alertaId + '\')" title="Mostrar esta alerta nuevamente"><i class="fas ' + iconoBoton + '"></i> ' + textoBoton + '</button>');
                $contenedor.append($botonMostrar);
                $alerta.after($contenedor);
            }
        }

        function mostrarAlertaEspecifica(alertaId) {
            let $alerta = $('[data-alerta-id="' + alertaId + '"]');
            $alerta.removeClass('oculto');
            $alerta.next('.alert-boton-container').remove();
            localStorage.removeItem('alerta_oculta_' + alertaId);
        }

        function cargarEstadoAlertas() {
            $('.alert-ocultable').each(function() {
                let alertaId = $(this).data('alerta-id');
                if (alertaId) {
                    let estaOculta = localStorage.getItem('alerta_oculta_' + alertaId) === 'true';
                    if (estaOculta) {
                        $(this).addClass('oculto');

                        let textoBoton = '';
                        let iconoBoton = '';
                        switch(alertaId) {
                            case 'info_gimnasio': textoBoton = 'Ver consejo'; iconoBoton = 'fa-lightbulb'; break;
                            case 'huella_info': textoBoton = 'Ver instrucciones'; iconoBoton = 'fa-fingerprint'; break;
                            case 'usuario_info': textoBoton = 'Más información'; iconoBoton = 'fa-info-circle'; break;
                            case 'logo_info': textoBoton = 'Mostrar recomendaciones'; iconoBoton = 'fa-image'; break;
                            default: textoBoton = 'Mostrar alerta'; iconoBoton = 'fa-eye';
                        }

                        if ($(this).next('.alert-boton-container').length === 0) {
                            let $contenedor = $('<div class="alert-boton-container"></div>');
                            let $botonMostrar = $('<button class="btn-mostrar-alerta" onclick="mostrarAlertaEspecifica(\'' + alertaId + '\')" title="Mostrar esta alerta nuevamente"><i class="fas ' + iconoBoton + '"></i> ' + textoBoton + '</button>');
                            $contenedor.append($botonMostrar);
                            $(this).after($contenedor);
                        }
                    } else {
                        $(this).removeClass('oculto');
                        $(this).next('.alert-boton-container').remove();
                    }
                }
            });
        }

        // ==================== ENVÍO DE FORMULARIOS (UN SOLO MANEJADOR) ====================
        $(document).ready(function() {
            // Vista previa del logo
            $('#logo').on('change', function(e) {
                const file = e.target.files[0];
                if (file) {
                    const tiposPermitidos = ['image/jpeg', 'image/jpg', 'image/png', 'image/webp'];
                    if (!tiposPermitidos.includes(file.type)) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Solo se permiten archivos JPG, PNG y WEBP', target: document.body });
                        $(this).val('');
                        return;
                    }
                    if (file.size > 2 * 1024 * 1024) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'El archivo no puede superar los 2MB', target: document.body });
                        $(this).val('');
                        return;
                    }
                    const reader = new FileReader();
                    reader.onload = function(e) { $('#preview_logo').attr('src', e.target.result); }
                    reader.readAsDataURL(file);
                    $(this).next('.custom-file-label').html(file.name);
                } else {
                    $(this).next('.custom-file-label').html('Seleccionar logo (PNG, JPG o WEBP)');
                }
            });

            // Formulario de Información del Gimnasio (con logo)
            $('#formInfoGimnasio').on('submit', function(e) {
                e.preventDefault();
                const formData = new FormData(this);
                formData.append('action', 'save_config');

                $.ajax({
                    url: 'configuracion.php',
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({ icon: 'success', title: '¡Éxito!', text: response.message, target: document.body, showConfirmButton: false, timer: 2000 })
                                .then(() => { location.reload(); });
                        } else {
                            Swal.fire({ icon: 'error', title: 'Error', text: response.message, target: document.body });
                        }
                    },
                    error: function(xhr, status, error) {
                        Swal.fire({ icon: 'error', title: 'Error', text: 'Ocurrió un error al guardar la configuración: ' + error, target: document.body });
                    }
                });
            });

            // UN SOLO MANEJADOR PARA TODOS LOS DEMÁS FORMULARIOS
            $('#formPlan, #formCategoria, #formProveedor, #formProducto, #formClase, #formUsuario, #formCliente').on('submit', function(e) {
                e.preventDefault();

                let action = '';
                const formId = $(this).attr('id');

                if (formId === 'formPlan') action = 'save_plan';
                else if (formId === 'formCategoria') action = 'save_categoria';
                else if (formId === 'formProveedor') action = 'save_proveedor';
                else if (formId === 'formProducto') action = 'save_producto';
                else if (formId === 'formClase') action = 'save_clase';
                else if (formId === 'formUsuario') action = 'save_usuario';
                else if (formId === 'formCliente') action = 'save_cliente';

                let data =
                    $(this).serialize() +
                    '&action=' +
                    action;

                Swal.fire({
                    title: 'Guardando...',
                    text: 'Por favor espere',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading(),
                    target: document.body
                });

                $.ajax({
                    url: 'configuracion.php',
                    method: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(res) {
                        if (!res.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text:
                                    res.message ||
                                    res.error ||
                                    'No se pudo guardar.',
                                target: document.body
                            });
                            return;
                        }

                        if (
                            formId === 'formUsuario' &&
                            res.usuario_nuevo
                        ) {
                            if (res.correo_enviado) {
                                Swal.fire({
                                    icon: 'success',
                                    title: 'Usuario creado',
                                    text:
                                        'Las credenciales fueron enviadas ' +
                                        'al correo registrado.',
                                    target: document.body,
                                    confirmButtonText: 'Aceptar'
                                }).then(() => location.reload());
                            } else {
                                Swal.fire({
                                    icon: 'warning',
                                    title: 'Usuario creado sin correo',
                                    html:
                                        '<p>No fue posible enviar las ' +
                                        'credenciales.</p>' +
                                        '<p><strong>Contraseña temporal:' +
                                        '</strong></p>' +
                                        '<code style="font-size:16px;">' +
                                        $('<div>')
                                            .text(
                                                res.password_temporal || ''
                                            )
                                            .html() +
                                        '</code>' +
                                        '<p style="' +
                                        'margin-top:12px;font-size:12px;' +
                                        'color:#667085;">' +
                                        $('<div>')
                                            .text(
                                                res.correo_error || ''
                                            )
                                            .html() +
                                        '</p>',
                                    target: document.body,
                                    confirmButtonText: 'Aceptar'
                                }).then(() => location.reload());
                            }

                            return;
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Guardado',
                            text:
                                res.message ||
                                'Registro guardado correctamente.',
                            target: document.body,
                            timer: 1500,
                            showConfirmButton: false
                        });

                        setTimeout(
                            () => location.reload(),
                            1500
                        );
                    },
                    error: function(xhr, status, error) {
                        let message = 'Ocurrió un error: ' + error;

                        if (
                            xhr.responseJSON &&
                            xhr.responseJSON.message
                        ) {
                            message = xhr.responseJSON.message;
                        }

                        Swal.fire({
                            icon: 'error',
                            title: 'Error',
                            text: message,
                            target: document.body
                        });
                    }
                });
            });

            renderQrMiniaturas();
            inicializarListadosPaginados();
            inicializarCorreo();
            inicializarSeguridad2fa();
            inicializarPasswordTemporalSistema();
        });

        function inicializarListadosPaginados() {
            const tablas = document.querySelectorAll(
                '.card .table-responsive > table.table'
            );

            tablas.forEach(function(tabla, indiceTabla) {
                if (
                    tabla.dataset.managedList === 'true'
                ) {
                    return;
                }

                tabla.dataset.managedList = 'true';
                tabla.classList.add(
                    'responsive-card-table'
                );

                const headers = Array.from(
                    tabla.querySelectorAll('thead th')
                ).map(function(th) {
                    return th.textContent.trim();
                });

                const filas = Array.from(
                    tabla.querySelectorAll('tbody tr')
                );

                filas.forEach(function(fila) {
                    Array.from(fila.children)
                        .forEach(function(celda, indice) {
                            celda.setAttribute(
                                'data-label',
                                headers[indice] || ''
                            );
                        });
                });

                const wrapper =
                    tabla.closest('.table-responsive');

                const body = wrapper.parentElement;
                const toolbar =
                    document.createElement('div');

                toolbar.className =
                    'managed-list-toolbar';

                toolbar.innerHTML =
                    '<div class="managed-list-search">' +
                        '<i class="fas fa-magnifying-glass">' +
                        '</i>' +
                        '<input type="search" ' +
                        'placeholder="Buscar en esta sección...">' +
                    '</div>' +
                    '<span class="managed-list-count"></span>';

                body.insertBefore(toolbar, wrapper);

                const paginacion =
                    document.createElement('div');

                paginacion.className =
                    'managed-pagination';

                body.appendChild(paginacion);

                const input =
                    toolbar.querySelector('input');

                const count =
                    toolbar.querySelector(
                        '.managed-list-count'
                    );

                const porPagina = 9;
                let paginaActual = 1;
                let filtradas = filas.slice();

                function renderLista() {
                    const totalPaginas = Math.max(
                        1,
                        Math.ceil(
                            filtradas.length /
                            porPagina
                        )
                    );

                    paginaActual = Math.min(
                        paginaActual,
                        totalPaginas
                    );

                    filas.forEach(function(fila) {
                        fila.classList.add(
                            'list-hidden'
                        );
                    });

                    const inicio =
                        (paginaActual - 1) *
                        porPagina;

                    filtradas
                        .slice(
                            inicio,
                            inicio + porPagina
                        )
                        .forEach(function(fila) {
                            fila.classList.remove(
                                'list-hidden'
                            );
                        });

                    count.textContent =
                        filtradas.length +
                        (
                            filtradas.length === 1
                                ? ' registro'
                                : ' registros'
                        );

                    renderPaginacion(totalPaginas);

                    let empty =
                        body.querySelector(
                            '.managed-list-empty'
                        );

                    if (filtradas.length === 0) {
                        if (!empty) {
                            empty =
                                document.createElement(
                                    'div'
                                );

                            empty.className =
                                'managed-list-empty';

                            empty.innerHTML =
                                '<i class="fas ' +
                                'fa-folder-open fa-2x ' +
                                'mb-2"></i>' +
                                '<p>No hay resultados ' +
                                'para la búsqueda.</p>';

                            body.insertBefore(
                                empty,
                                paginacion
                            );
                        }

                        wrapper.style.display = 'none';
                        paginacion.style.display = 'none';
                    } else {
                        if (empty) {
                            empty.remove();
                        }

                        wrapper.style.display = '';
                        paginacion.style.display =
                            totalPaginas > 1
                                ? 'flex'
                                : 'none';
                    }
                }

                function renderPaginacion(
                    totalPaginas
                ) {
                    paginacion.innerHTML = '';

                    function agregarBoton(
                        contenido,
                        pagina,
                        activo,
                        deshabilitado
                    ) {
                        const boton =
                            document.createElement(
                                'button'
                            );

                        boton.type = 'button';
                        boton.className =
                            'managed-page-btn' +
                            (activo ? ' active' : '');

                        boton.innerHTML = contenido;
                        boton.disabled =
                            Boolean(deshabilitado);

                        boton.addEventListener(
                            'click',
                            function() {
                                paginaActual = pagina;
                                renderLista();

                                tabla.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start'
                                });
                            }
                        );

                        paginacion.appendChild(boton);
                    }

                    agregarBoton(
                        '<i class="fas ' +
                        'fa-chevron-left"></i>',
                        Math.max(
                            1,
                            paginaActual - 1
                        ),
                        false,
                        paginaActual === 1
                    );

                    const inicio = Math.max(
                        1,
                        paginaActual - 2
                    );

                    const fin = Math.min(
                        totalPaginas,
                        paginaActual + 2
                    );

                    for (
                        let pagina = inicio;
                        pagina <= fin;
                        pagina++
                    ) {
                        agregarBoton(
                            String(pagina),
                            pagina,
                            pagina === paginaActual,
                            false
                        );
                    }

                    agregarBoton(
                        '<i class="fas ' +
                        'fa-chevron-right"></i>',
                        Math.min(
                            totalPaginas,
                            paginaActual + 1
                        ),
                        false,
                        paginaActual === totalPaginas
                    );
                }

                let timeoutBusqueda;

                input.addEventListener(
                    'input',
                    function() {
                        clearTimeout(
                            timeoutBusqueda
                        );

                        timeoutBusqueda =
                            setTimeout(function() {
                                const termino =
                                    input.value
                                        .trim()
                                        .toLowerCase();

                                filtradas =
                                    filas.filter(
                                        function(fila) {
                                            return fila
                                                .textContent
                                                .toLowerCase()
                                                .includes(
                                                    termino
                                                );
                                        }
                                    );

                                paginaActual = 1;
                                renderLista();
                            }, 220);
                    }
                );

                renderLista();
            });
        }

        function inicializarPasswordTemporalSistema() {
            const $form = $('#formPasswordTemporalSistema');

            if (!$form.length) {
                return;
            }

            $('#passwordTemporalSistema, #passwordTemporalSistemaConfirmacion')
                .on('input', evaluarPasswordSistema);

            $form.on('submit', function(event) {
                event.preventDefault();

                const password = $('#passwordTemporalSistema').val();
                const confirmation = $('#passwordTemporalSistemaConfirmacion').val();

                if (String(password).length < 4) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Contraseña demasiado corta',
                        text: 'La contraseña temporal debe tener al menos 4 caracteres.',
                        target: document.body
                    });
                    return;
                }

                if (password !== confirmation) {
                    Swal.fire({
                        icon: 'warning',
                        title: 'Las contraseñas no coinciden',
                        text: 'Escribe exactamente la misma contraseña en ambos campos.',
                        target: document.body
                    });
                    return;
                }

                Swal.fire({
                    icon: 'question',
                    title: '¿Actualizar contraseña temporal?',
                    html:
                        '<p>Se aplicará a <strong>usuarios nuevos</strong> y a ' +
                        '<strong>restablecimientos futuros</strong>.</p>' +
                        '<p style="margin-bottom:0;color:#667085;font-size:13px;">' +
                        'Las contraseñas actuales de los usuarios no cambiarán.' +
                        '</p>',
                    showCancelButton: true,
                    confirmButtonText: 'Sí, actualizar',
                    cancelButtonText: 'Cancelar',
                    target: document.body
                }).then(function(result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    Swal.fire({
                        title: 'Guardando contraseña...',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                        didOpen: function() {
                            Swal.showLoading();
                        },
                        target: document.body
                    });

                    $.ajax({
                        url: 'configuracion.php',
                        method: 'POST',
                        dataType: 'json',
                        data: $form.serialize()
                            + '&action=save_password_temporal_config',
                        success: function(response) {
                            if (!response.success) {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'No se pudo guardar',
                                    text: response.message || 'Ocurrió un error.',
                                    target: document.body
                                });
                                return;
                            }

                            Swal.fire({
                                icon: 'success',
                                title: 'Contraseña actualizada',
                                text: response.message,
                                confirmButtonText: 'Aceptar',
                                target: document.body
                            }).then(function() {
                                window.location.reload();
                            });
                        },
                        error: function(xhr) {
                            Swal.fire({
                                icon: 'error',
                                title: 'No se pudo guardar',
                                text: xhr.responseJSON?.message
                                    || 'Ocurrió un error al actualizar la contraseña.',
                                target: document.body
                            });
                        }
                    });
                });
            });
        }

        function inicializarSeguridad2fa() {
            const $form = $('#formSeguridad2fa');

            if (!$form.length) {
                return;
            }

            $form.on('submit', function(event) {
                event.preventDefault();

                const data = $form.serialize() + '&action=save_2fa_config';

                Swal.fire({
                    title: 'Guardando política de seguridad...',
                    allowOutsideClick: false,
                    didOpen: () => Swal.showLoading(),
                    target: document.body
                });

                $.ajax({
                    url: 'configuracion.php',
                    method: 'POST',
                    data: data,
                    dataType: 'json',
                    success: function(response) {
                        if (!response.success) {
                            Swal.fire({
                                icon: 'error',
                                title: 'No se pudo guardar',
                                text: response.message || 'Ocurrió un error.',
                                target: document.body
                            });
                            return;
                        }

                        Swal.fire({
                            icon: 'success',
                            title: 'Política actualizada',
                            text: response.message,
                            timer: 1700,
                            showConfirmButton: false,
                            target: document.body
                        }).then(() => location.reload());
                    },
                    error: function(xhr) {
                        Swal.fire({
                            icon: 'error',
                            title: 'No se pudo guardar',
                            text: xhr.responseJSON?.message || 'Ocurrió un error al guardar la política.',
                            target: document.body
                        });
                    }
                });
            });
        }

        function inicializarCorreo() {
            const $form = $('#formCorreo');

            if ($form.length) {
                $form.on('submit', function(e) {
                    e.preventDefault();

                    const data =
                        $form.serialize() +
                        '&action=save_email_config';

                    Swal.fire({
                        title:
                            'Guardando configuración...',
                        allowOutsideClick: false,
                        didOpen: () =>
                            Swal.showLoading()
                    });

                    $.ajax({
                        url: 'configuracion.php',
                        method: 'POST',
                        data: data,
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.fire({
                                    icon: 'success',
                                    title:
                                        'Configuración guardada',
                                    text:
                                        response.message,
                                    timer: 1600,
                                    showConfirmButton: false
                                }).then(
                                    () =>
                                        location.reload()
                                );
                            } else {
                                Swal.fire({
                                    icon: 'error',
                                    title: 'Error',
                                    text:
                                        response.message ||
                                        'No se pudo guardar.'
                                });
                            }
                        },
                        error: function() {
                            Swal.fire({
                                icon: 'error',
                                title: 'Error',
                                text:
                                    'No se pudo guardar la ' +
                                    'configuración.'
                            });
                        }
                    });
                });
            }
        }

        // Elimina el logo del contexto actual sin afectar otras sedes.
        function eliminarLogo() {
            Swal.fire({
                title: '¿Eliminar logo?',
                text: <?php echo json_encode(
                    $vistaGlobalConfiguracion
                        ? 'Se eliminará el logo corporativo actual.'
                        : 'Se eliminará el logo propio de esta sede y se usará el corporativo.',
                    JSON_UNESCAPED_UNICODE
                ); ?>,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Sí, eliminar',
                cancelButtonText: 'Cancelar',
                confirmButtonColor: '#dc2626',
                target: document.body
            }).then((result) => {
                if (!result.isConfirmed) return;

                $.ajax({
                    url: 'configuracion.php',
                    type: 'POST',
                    dataType: 'json',
                    data: { action: 'delete_logo' },
                    success: function(response) {
                        if (response.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'Logo eliminado',
                                text: response.message,
                                timer: 1500,
                                showConfirmButton: false,
                                target: document.body
                            }).then(() => location.reload());
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'No se pudo eliminar',
                                text: response.message,
                                target: document.body
                            });
                        }
                    },
                    error: function(xhr) {
                        Swal.fire({
                            icon: 'error',
                            title: 'No se pudo eliminar',
                            text: xhr.responseJSON?.message || 'Ocurrió un error al eliminar el logo.',
                            target: document.body
                        });
                    }
                });
            });
        }
    </script>
</body>
</html>