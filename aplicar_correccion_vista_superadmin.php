<?php
// Archivo temporal: aplicar_correccion_vista_superadmin.php
// Colócalo en la raíz de Sistema_gym y ejecútalo una sola vez por CLI.

declare(strict_types=1);

$archivoVista = __DIR__ . '/includes/configuracion_vista.php';

function parcheVistaReemplazar(
    string $contenido,
    string $anterior,
    string $nuevo,
    string $descripcion,
    bool $permitirYaAplicado = true
): string {
    if ($permitirYaAplicado && strpos($contenido, $nuevo) !== false) {
        echo "[OK] {$descripcion}: ya estaba aplicado.\n";
        return $contenido;
    }

    $coincidencias = substr_count($contenido, $anterior);

    if ($coincidencias !== 1) {
        throw new RuntimeException(
            "No se encontró de forma única {$descripcion}. "
            . "Coincidencias: {$coincidencias}."
        );
    }

    echo "[OK] {$descripcion}: corregido.\n";
    return str_replace($anterior, $nuevo, $contenido);
}

try {
    if (!is_file($archivoVista)) {
        throw new RuntimeException(
            'No se encontró includes/configuracion_vista.php. '
            . 'Ejecuta este archivo desde la raíz de Sistema_gym.'
        );
    }

    $vista = file_get_contents($archivoVista);

    if ($vista === false) {
        throw new RuntimeException(
            'No fue posible leer includes/configuracion_vista.php.'
        );
    }

    $respaldo = $archivoVista . '.bak-' . date('Ymd-His');

    if (!copy($archivoVista, $respaldo)) {
        throw new RuntimeException(
            'No fue posible crear el respaldo de configuracion_vista.php.'
        );
    }

    echo "Respaldo creado: {$respaldo}\n";

    $bloqueGuardAnterior = <<<'OLD'
if (!defined('CONFIGURACION_MODULO_CARGADO')) {
    http_response_code(403);
    exit('Acceso no permitido.');
}
?>
OLD;

    $bloqueGuardNuevo = <<<'NEW'
if (!defined('CONFIGURACION_MODULO_CARGADO')) {
    http_response_code(403);
    exit('Acceso no permitido.');
}

/* ALTA_GLOBAL_SUPERADMIN_APLICADA */
$esSuperAdministradorActual = (bool) (
    $configuracionVista['es_super_administrador']
    ?? $esSuperAdministradorActual
    ?? false
);

if (
    !$esSuperAdministradorActual
    && function_exists('rol_es_super_administrador')
) {
    $esSuperAdministradorActual = rol_es_super_administrador();
}
?>
NEW;

    if (strpos($vista, 'ALTA_GLOBAL_SUPERADMIN_APLICADA') === false) {
        $vista = parcheVistaReemplazar(
            $vista,
            $bloqueGuardAnterior,
            $bloqueGuardNuevo,
            'la detección del superadministrador',
            false
        );
    } else {
        echo "[OK] La detección del superadministrador ya estaba aplicada.\n";
    }

    $botonAnterior = <<<'OLD'
                    <?php if (!$vistaGlobalConfiguracion): ?>
                        <button class="btn btn-primary btn-sm" onclick="abrirModal('modalUsuario')">
                            <i class="fas fa-plus"></i> Nuevo Usuario
                        </button>
                    <?php else: ?>
                        <span class="config-action-hint"><i class="fas fa-location-dot"></i>Elige una sucursal para crear y asignar</span>
                    <?php endif; ?>
OLD;

    $botonNuevo = <<<'NEW'
                    <?php if (!$vistaGlobalConfiguracion): ?>
                        <button class="btn btn-primary btn-sm" onclick="abrirModal('modalUsuario')">
                            <i class="fas fa-plus"></i> Nuevo Usuario
                        </button>
                    <?php elseif ($esSuperAdministradorActual): ?>
                        <button
                            class="btn btn-primary btn-sm"
                            onclick="abrirModal('modalUsuario')"
                        >
                            <i class="fas fa-user-shield"></i>
                            Nuevo superadministrador
                        </button>
                    <?php else: ?>
                        <span class="config-action-hint">
                            <i class="fas fa-location-dot"></i>
                            Elige una sucursal para crear y asignar
                        </span>
                    <?php endif; ?>
NEW;

    $vista = parcheVistaReemplazar(
        $vista,
        $botonAnterior,
        $botonNuevo,
        'el botón Nuevo superadministrador'
    );

    $mapaAnterior = <<<'OLD'
                            $roles_map = ['admin' => 'Administrador', 'recepcionista' => 'Recepcionista', 'entrenador' => 'Entrenador'];
OLD;

    $mapaNuevo = <<<'NEW'
                            $roles_map = [
                                'super_administrador' => 'Superadministrador',
                                'admin' => 'Administrador',
                                'recepcionista' => 'Recepcionista',
                                'entrenador' => 'Entrenador',
                            ];
NEW;

    $vista = parcheVistaReemplazar(
        $vista,
        $mapaAnterior,
        $mapaNuevo,
        'la etiqueta visual del rol'
    );

    $whileAnterior = <<<'OLD'
                            while($user = $usuarios->fetch_assoc()):
                            ?>
OLD;

    $whileNuevo = <<<'NEW'
                            while($user = $usuarios->fetch_assoc()):
                                $esFilaSuperAdministrador =
                                    rol_normalizar_sistema((string) (
                                        $user['rol'] ?? ''
                                    )) === 'super_administrador';
                            ?>
NEW;

    $vista = parcheVistaReemplazar(
        $vista,
        $whileAnterior,
        $whileNuevo,
        'la identificación de filas superadministradoras'
    );

    if (strpos($vista, 'Cuenta global protegida') === false) {
        $inicio = strpos(
            $vista,
            '                                    <div class="config-users-actions">'
        );

        if ($inicio === false) {
            throw new RuntimeException(
                'No se encontró el bloque de acciones de usuarios.'
            );
        }

        $inicioContenido = $inicio
            + strlen('                                    <div class="config-users-actions">');

        $cierre = "                                    </div>\n"
            . "                                </td>";

        $fin = strpos($vista, $cierre, $inicioContenido);

        if ($fin === false) {
            throw new RuntimeException(
                'No se encontró el cierre del bloque de acciones de usuarios.'
            );
        }

        $accionesActuales = substr(
            $vista,
            $inicioContenido,
            $fin - $inicioContenido
        );

        $accionesNuevas = "\n"
            . "                                        <?php if (\$esFilaSuperAdministrador): ?>\n"
            . "                                            <span class=\"badge badge-primary\">\n"
            . "                                                <i class=\"fas fa-shield-halved\"></i>\n"
            . "                                                Cuenta global protegida\n"
            . "                                            </span>\n"
            . "                                        <?php else: ?>"
            . $accionesActuales
            . "                                        <?php endif; ?>\n";

        $vista = substr_replace(
            $vista,
            $accionesNuevas,
            $inicioContenido,
            $fin - $inicioContenido
        );

        echo "[OK] Las acciones de cuentas superadministradoras quedaron protegidas.\n";
    } else {
        echo "[OK] Las acciones protegidas ya estaban aplicadas.\n";
    }

    $tituloAnterior = '<h4><i class="fas fa-user-plus"></i> Nuevo Usuario</h4>';
    $tituloNuevo = <<<'NEW'
<h4>
                        <i class="fas fa-user-plus"></i>
                        <?php echo $vistaGlobalConfiguracion
                            && $esSuperAdministradorActual
                                ? 'Nuevo superadministrador'
                                : 'Nuevo Usuario'; ?>
                    </h4>
NEW;

    $vista = parcheVistaReemplazar(
        $vista,
        $tituloAnterior,
        $tituloNuevo,
        'el título del formulario de usuario'
    );

    $selectAnterior = <<<'OLD'
                        <div class="form-group"><label><i class="fas fa-user-tag"></i> <?php echo $vistaGlobalConfiguracion ? 'Rol general' : 'Función en esta sucursal'; ?></label><select class="form-control" name="rol" id="usuarioRol" required><option value="recepcionista">Recepcionista</option><option value="entrenador">Entrenador</option><option value="admin">Administrador</option></select></div>
OLD;

    $selectNuevo = <<<'NEW'
                        <div class="form-group">
                            <label>
                                <i class="fas fa-user-tag"></i>
                                <?php echo $vistaGlobalConfiguracion
                                    ? 'Rol general'
                                    : 'Función en esta sucursal'; ?>
                            </label>
                            <select
                                class="form-control"
                                name="rol"
                                id="usuarioRol"
                                required
                            >
                                <?php if (
                                    $vistaGlobalConfiguracion
                                    && $esSuperAdministradorActual
                                ): ?>
                                    <option value="super_administrador">
                                        Superadministrador
                                    </option>
                                <?php else: ?>
                                    <option value="recepcionista">
                                        Recepcionista
                                    </option>
                                    <option value="entrenador">
                                        Entrenador
                                    </option>
                                    <option value="admin">
                                        Administrador
                                    </option>
                                <?php endif; ?>
                            </select>
                        </div>
NEW;

    $vista = parcheVistaReemplazar(
        $vista,
        $selectAnterior,
        $selectNuevo,
        'el selector del rol global'
    );

    $infoAnterior = <<<'OLD'
                            <i class="fas fa-info-circle"></i> <strong>Información:</strong> Los nuevos usuarios recibirán por correo la contraseña temporal <strong>ego1</strong>. Deberán cambiarla durante el primer inicio de sesión.
OLD;

    $infoNuevo = <<<'NEW'
                            <i class="fas fa-info-circle"></i>
                            <strong>Información:</strong>
                            <?php if (
                                $vistaGlobalConfiguracion
                                && $esSuperAdministradorActual
                            ): ?>
                                La nueva cuenta tendrá acceso administrativo
                                a todas las sucursales activas y recibirá la
                                contraseña temporal <strong>ego1</strong>.
                            <?php else: ?>
                                Los nuevos usuarios recibirán por correo la
                                contraseña temporal <strong>ego1</strong>.
                                Deberán cambiarla durante el primer inicio de sesión.
                            <?php endif; ?>
NEW;

    $vista = parcheVistaReemplazar(
        $vista,
        $infoAnterior,
        $infoNuevo,
        'el mensaje del formulario'
    );

    $caseAnterior = "case 'modalUsuario': tituloOriginal = 'Nuevo Usuario'; break;";
    $caseNuevo = <<<'NEW'
case 'modalUsuario':
                    tituloOriginal = CONFIG_VISTA_GLOBAL
                        ? 'Nuevo superadministrador'
                        : 'Nuevo Usuario';
                    break;
NEW;

    if (strpos($vista, $caseAnterior) !== false) {
        $vista = str_replace($caseAnterior, $caseNuevo, $vista);
        echo "[OK] El título del modal al cerrarse quedó corregido.\n";
    }

    if (file_put_contents($archivoVista, $vista, LOCK_EX) === false) {
        throw new RuntimeException(
            'No fue posible guardar includes/configuracion_vista.php.'
        );
    }

    echo "\nCorrección aplicada correctamente.\n";
    echo "Elimina este archivo temporal después de comprobar el botón.\n";
} catch (Throwable $error) {
    fwrite(STDERR, "ERROR: {$error->getMessage()}\n");
    exit(1);
}
