<?php
// Archivo: includes/configuracion_context.php
// Contexto y utilidades multisucursal del módulo Configuración.

if (!function_exists('configuracion_h')) {
    function configuracion_h($value)
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('configuracion_json')) {
    function configuracion_json($payload, $status = 200)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        echo json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
}

if (!function_exists('configuracion_rol_base')) {
    function configuracion_rol_base()
    {
        $rol = strtolower(trim((string) (
            $_SESSION['user_rol_base']
            ?? $_SESSION['user_rol']
            ?? ''
        )));

        return $rol === 'administrador' ? 'admin' : $rol;
    }
}

if (!function_exists('configuracion_contexto')) {
    function configuracion_contexto($db, $usuarioId)
    {
        if (function_exists('sucursal_inicializar_sesion')) {
            sucursal_inicializar_sesion($db);
        }

        $rolBase = configuracion_rol_base();
        $puedeGlobal = $rolBase === 'admin';
        $vistaSolicitada = strtolower(trim((string) (
            $_GET['vista'] ?? ''
        )));

        if ($vistaSolicitada === 'global' && $puedeGlobal) {
            sucursal_activar_vista_global($db, (int) $usuarioId);
        } elseif ($vistaSolicitada === 'sucursal') {
            sucursal_desactivar_vista_global();
        }

        $vistaGlobal = $puedeGlobal
            && function_exists('sucursal_dashboard_vista_global')
            && sucursal_dashboard_vista_global();

        $sucursales = sucursal_obtener_asignadas(
            $db,
            (int) $usuarioId
        );

        /*
         * El administrador global debe poder consolidar todas las sedes
         * activas aunque alguna todavía no esté en su asignación personal.
         */
        if ($puedeGlobal) {
            $porId = array();

            foreach ($sucursales as $sucursal) {
                $id = (int) ($sucursal['id'] ?? 0);
                if ($id > 0) {
                    $porId[$id] = $sucursal;
                }
            }

            $resultado = $db->query(
                "SELECT id, clave, nombre, telefono, email, direccion,
                        horario, logo, zona_horaria, es_matriz
                 FROM sucursales
                 WHERE estado = 'activa'
                 ORDER BY es_matriz DESC, nombre ASC"
            );

            if ($resultado) {
                while ($fila = $resultado->fetch_assoc()) {
                    $id = (int) ($fila['id'] ?? 0);
                    if ($id <= 0) {
                        continue;
                    }

                    if (!isset($porId[$id])) {
                        $fila['id'] = $id;
                        $fila['es_principal'] = 0;
                        $fila['puede_operar_caja'] = 1;
                        $fila['rol_efectivo'] = 'admin';
                        $porId[$id] = $fila;
                    }
                }
            }

            $sucursales = array_values($porId);

            usort($sucursales, function ($a, $b) {
                $matrizA = (int) ($a['es_matriz'] ?? 0);
                $matrizB = (int) ($b['es_matriz'] ?? 0);

                if ($matrizA !== $matrizB) {
                    return $matrizB <=> $matrizA;
                }

                return strcasecmp(
                    (string) ($a['nombre'] ?? ''),
                    (string) ($b['nombre'] ?? '')
                );
            });
        }

        if ($sucursales === array()) {
            throw new RuntimeException(
                'No existen sucursales activas disponibles.'
            );
        }

        $sucursalSesion = (int) ($_SESSION['sucursal_id'] ?? 0);
        $actual = null;
        $ids = array();

        foreach ($sucursales as $sucursal) {
            $id = (int) ($sucursal['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $ids[] = $id;

            if ($id === $sucursalSesion) {
                $actual = $sucursal;
            }
        }

        $ids = array_values(array_unique($ids));

        if (!$vistaGlobal && !$actual) {
            throw new RuntimeException(
                'Selecciona una sucursal válida antes de abrir Configuración.'
            );
        }

        $zona = $vistaGlobal
            ? 'America/Mexico_City'
            : trim((string) (
                $actual['zona_horaria']
                ?? $_SESSION['sucursal_zona_horaria']
                ?? 'America/Mexico_City'
            ));

        return array(
            'vista_global' => $vistaGlobal,
            'puede_global' => $puedeGlobal,
            'sucursal_id' => $vistaGlobal
                ? 0
                : (int) $actual['id'],
            'sucursal_nombre' => $vistaGlobal
                ? 'Todas las sucursales'
                : trim((string) ($actual['nombre'] ?? 'Sucursal')),
            'sucursal_clave' => $vistaGlobal
                ? 'GLOBAL'
                : trim((string) ($actual['clave'] ?? '')),
            'sucursal_actual' => $actual,
            'sucursales' => $sucursales,
            'sucursales_ids' => $ids,
            'total_sedes' => count($ids),
            'zona_horaria' => $zona !== ''
                ? $zona
                : 'America/Mexico_City'
        );
    }
}

if (!function_exists('configuracion_url')) {
    function configuracion_url($seccion, $vistaGlobal)
    {
        return 'configuracion.php?vista='
            . ($vistaGlobal ? 'global' : 'sucursal')
            . '&section='
            . rawurlencode((string) $seccion);
    }
}

if (!function_exists('configuracion_preparar')) {
    function configuracion_preparar($db, $sql, $tipos = '', $parametros = array())
    {
        $stmt = $db->prepare($sql);

        if (!$stmt) {
            throw new RuntimeException($db->error ?: 'No se pudo preparar la consulta.');
        }

        if ($tipos !== '' && $parametros !== array()) {
            $referencias = array($tipos);

            foreach ($parametros as $indice => $valor) {
                $referencias[] = &$parametros[$indice];
            }

            call_user_func_array(
                array($stmt, 'bind_param'),
                $referencias
            );
        }

        return $stmt;
    }
}

if (!function_exists('configuracion_fila')) {
    function configuracion_fila($db, $sql, $tipos = '', $parametros = array())
    {
        $stmt = configuracion_preparar($db, $sql, $tipos, $parametros);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $fila = $resultado ? $resultado->fetch_assoc() : null;
        $stmt->close();

        return $fila ?: null;
    }
}

if (!function_exists('configuracion_ejecutar')) {
    function configuracion_ejecutar($db, $sql, $tipos = '', $parametros = array())
    {
        $stmt = configuracion_preparar($db, $sql, $tipos, $parametros);
        $ok = $stmt->execute();
        $error = $stmt->error;
        $stmt->close();

        if (!$ok) {
            throw new RuntimeException($error ?: 'No se pudo completar la operación.');
        }

        return true;
    }
}

if (!function_exists('configuracion_contar')) {
    function configuracion_contar($db, $sql, $tipos = '', $parametros = array())
    {
        $fila = configuracion_fila($db, $sql, $tipos, $parametros);
        return (int) ($fila['total'] ?? 0);
    }
}

if (!function_exists('configuracion_sincronizar_catalogos')) {
    function configuracion_sincronizar_catalogos($db, $sucursalId)
    {
        configuracion_ejecutar(
            $db,
            "INSERT IGNORE INTO planes_sucursales
                (sucursal_id, plan_id, precio, estado)
             SELECT ?, p.id, p.precio, p.estado
             FROM planes p",
            'i',
            array((int) $sucursalId)
        );

        configuracion_ejecutar(
            $db,
            "INSERT IGNORE INTO inventario_sucursales
                (
                    sucursal_id,
                    producto_id,
                    precio_compra,
                    precio_venta,
                    stock,
                    stock_minimo,
                    estado
                )
             SELECT
                ?,
                p.id,
                p.precio_compra,
                p.precio_venta,
                0,
                p.stock_minimo,
                p.estado
             FROM productos p",
            'i',
            array((int) $sucursalId)
        );
    }
}

if (!function_exists('configuracion_sincronizar_todas')) {
    function configuracion_sincronizar_todas($db)
    {
        $resultado = $db->query(
            "SELECT id FROM sucursales WHERE estado = 'activa'"
        );

        if (!$resultado) {
            return;
        }

        while ($fila = $resultado->fetch_assoc()) {
            configuracion_sincronizar_catalogos(
                $db,
                (int) $fila['id']
            );
        }
    }
}

if (!function_exists('configuracion_cliente_en_sucursal')) {
    function configuracion_cliente_en_sucursal($db, $clienteId, $sucursalId)
    {
        return configuracion_fila(
            $db,
            "SELECT c.id
             FROM clientes c
             WHERE c.id = ?
               AND (
                    c.sucursal_registro_id = ?
                    OR EXISTS (
                        SELECT 1
                        FROM inscripciones i
                        WHERE i.cliente_id = c.id
                          AND (
                              i.sucursal_id = ?
                              OR EXISTS (
                                  SELECT 1
                                  FROM inscripciones_sucursales isc
                                  WHERE isc.inscripcion_id = i.id
                                    AND isc.sucursal_id = ?
                              )
                          )
                    )
               )
             LIMIT 1",
            'iiii',
            array(
                (int) $clienteId,
                (int) $sucursalId,
                (int) $sucursalId,
                (int) $sucursalId
            )
        ) !== null;
    }
}

if (!function_exists('configuracion_usuario_en_sucursal')) {
    function configuracion_usuario_en_sucursal($db, $usuarioId, $sucursalId)
    {
        return configuracion_fila(
            $db,
            "SELECT usuario_id
             FROM usuarios_sucursales
             WHERE usuario_id = ?
               AND sucursal_id = ?
             LIMIT 1",
            'ii',
            array((int) $usuarioId, (int) $sucursalId)
        ) !== null;
    }
}

if (!function_exists('configuracion_clase_en_sucursal')) {
    function configuracion_clase_en_sucursal($db, $claseId, $sucursalId)
    {
        return configuracion_fila(
            $db,
            "SELECT id
             FROM clases
             WHERE id = ?
               AND sucursal_id = ?
             LIMIT 1",
            'ii',
            array((int) $claseId, (int) $sucursalId)
        ) !== null;
    }
}

if (!function_exists('configuracion_instructor_valido')) {
    function configuracion_instructor_valido($db, $nombre, $sucursalId)
    {
        return configuracion_fila(
            $db,
            "SELECT u.id
             FROM usuarios u
             INNER JOIN usuarios_sucursales us
                ON us.usuario_id = u.id
             WHERE us.sucursal_id = ?
               AND us.estado = 'activo'
               AND u.estado = 'activo'
               AND COALESCE(us.rol_sucursal, u.rol) = 'entrenador'
               AND u.nombre = ?
             LIMIT 1",
            'is',
            array((int) $sucursalId, trim((string) $nombre))
        ) !== null;
    }
}

if (!function_exists('configuracion_guardar_logo')) {
    function configuracion_guardar_logo($archivo, $sucursalId)
    {
        if (!isset($archivo['error']) || $archivo['error'] !== UPLOAD_ERR_OK) {
            return null;
        }

        $tamano = (int) ($archivo['size'] ?? 0);
        if ($tamano <= 0 || $tamano > 2 * 1024 * 1024) {
            throw new RuntimeException('El logo no puede superar los 2 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $archivo['tmp_name']);
        $extensiones = array(
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp'
        );

        if (!isset($extensiones[$mime])) {
            throw new RuntimeException('El logo debe ser JPG, PNG o WEBP.');
        }

        $directorioRelativo = $sucursalId > 0
            ? 'img/sucursales/'
            : 'img/';
        $directorioAbsoluto = dirname(__DIR__) . '/' . $directorioRelativo;

        if (!is_dir($directorioAbsoluto) && !mkdir($directorioAbsoluto, 0775, true)) {
            throw new RuntimeException('No fue posible crear la carpeta del logo.');
        }

        $nombre = $sucursalId > 0
            ? 'logo-sucursal-' . (int) $sucursalId . '-' . time()
            : 'logo-gym-' . time();
        $nombre .= '.' . $extensiones[$mime];

        $rutaRelativa = $directorioRelativo . $nombre;
        $rutaAbsoluta = $directorioAbsoluto . $nombre;

        if (!move_uploaded_file((string) $archivo['tmp_name'], $rutaAbsoluta)) {
            throw new RuntimeException('No fue posible guardar el logo.');
        }

        return $rutaRelativa;
    }
}

if (!function_exists('configuracion_eliminar_archivo_logo')) {
    function configuracion_eliminar_archivo_logo($ruta)
    {
        $ruta = trim((string) $ruta);
        if ($ruta === '') {
            return;
        }

        $raiz = realpath(dirname(__DIR__));
        $archivo = realpath(dirname(__DIR__) . '/' . ltrim($ruta, '/\\'));

        if (
            $raiz
            && $archivo
            && strpos($archivo, $raiz . DIRECTORY_SEPARATOR) === 0
            && is_file($archivo)
        ) {
            @unlink($archivo);
        }
    }
}
