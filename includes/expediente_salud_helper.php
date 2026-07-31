<?php

declare(strict_types=1);

if (!function_exists('expediente_h')) {
    function expediente_h($valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('expediente_es_administrativo')) {
    function expediente_es_administrativo(): bool
    {
        if (function_exists('rol_base_real_sesion') && function_exists('rol_es_administrativo')) {
            return rol_es_administrativo(rol_base_real_sesion());
        }

        $rol = strtolower(trim((string) ($_SESSION['user_rol'] ?? $_SESSION['rol'] ?? '')));

        return in_array($rol, ['super_administrador', 'admin', 'administrador'], true);
    }
}

if (!function_exists('expediente_csrf_token')) {
    function expediente_csrf_token(): string
    {
        if (empty($_SESSION['expediente_salud_csrf'])) {
            $_SESSION['expediente_salud_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['expediente_salud_csrf'];
    }
}

if (!function_exists('expediente_validar_csrf')) {
    function expediente_validar_csrf(string $token): bool
    {
        $sesion = (string) ($_SESSION['expediente_salud_csrf'] ?? '');

        return $sesion !== '' && $token !== '' && hash_equals($sesion, $token);
    }
}

if (!function_exists('expediente_redirigir')) {
    function expediente_redirigir(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}


if (!function_exists('expediente_ejecutar_stmt')) {
    function expediente_ejecutar_stmt(mysqli_stmt $stmt, string $contexto): void
    {
        try {
            $ok = $stmt->execute();
        } catch (mysqli_sql_exception $e) {
            throw new RuntimeException(
                $contexto . ' Detalle de MySQL: ' . $e->getMessage(),
                0,
                $e
            );
        }

        if ($ok !== true) {
            $detalle = trim((string) $stmt->error);
            throw new RuntimeException(
                $contexto . ($detalle !== '' ? ' Detalle de MySQL: ' . $detalle : '')
            );
        }
    }
}

if (!function_exists('expediente_guardar_firma')) {
    function expediente_guardar_firma(
        mysqli $conn,
        int $expedienteId,
        string $firmaDataUrl
    ): void {
        if ($firmaDataUrl === '') {
            return;
        }

        $stmt = $conn->prepare(
            'UPDATE expedientes_salud SET firma_data_url = ? WHERE id = ?'
        );

        if (!$stmt) {
            throw new RuntimeException(
                'No fue posible preparar el almacenamiento de la firma. Detalle de MySQL: '
                . $conn->error
            );
        }

        $stmt->bind_param('si', $firmaDataUrl, $expedienteId);
        expediente_ejecutar_stmt(
            $stmt,
            'No fue posible guardar la firma del socio o responsable.'
        );
        $stmt->close();
    }
}

if (!function_exists('expediente_post_supera_limite')) {
    function expediente_post_supera_limite(): bool
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            return false;
        }

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        return $contentLength > 0 && $_POST === [];
    }
}

if (!function_exists('expediente_configuracion')) {
    function expediente_configuracion(mysqli $conn): array
    {
        $sql = "
            SELECT
                id,
                nombre_cuestionario,
                introduccion,
                version,
                vigencia_dias,
                requerir_firma,
                documento_titulo,
                documento_texto,
                actualizado_por,
                created_at,
                updated_at
            FROM configuracion_expediente_salud
            WHERE id = 1
            LIMIT 1
        ";

        $resultado = $conn->query($sql);

        if (!$resultado || !$fila = $resultado->fetch_assoc()) {
            throw new RuntimeException('No existe la configuración del expediente de salud. Ejecuta primero el archivo SQL del módulo.');
        }

        return $fila;
    }
}

if (!function_exists('expediente_preguntas')) {
    function expediente_preguntas(mysqli $conn, bool $soloActivas = true): array
    {
        $where = $soloActivas ? "WHERE estado = 'activa'" : '';
        $sql = "
            SELECT
                id,
                seccion,
                pregunta,
                tipo_respuesta,
                opciones_json,
                obligatoria,
                dispara_alerta,
                ayuda,
                orden,
                estado,
                created_at,
                updated_at
            FROM preguntas_expediente_salud
            {$where}
            ORDER BY orden ASC, id ASC
        ";

        $resultado = $conn->query($sql);
        $preguntas = [];

        if ($resultado) {
            while ($fila = $resultado->fetch_assoc()) {
                $opciones = [];
                $json = trim((string) ($fila['opciones_json'] ?? ''));

                if ($json !== '') {
                    $decodificado = json_decode($json, true);
                    if (is_array($decodificado)) {
                        $opciones = array_values(array_filter(array_map('strval', $decodificado), static function ($valor): bool {
                            return trim($valor) !== '';
                        }));
                    }
                }

                $fila['opciones'] = $opciones;
                $preguntas[] = $fila;
            }
        }

        return $preguntas;
    }
}

if (!function_exists('expediente_cliente')) {
    function expediente_cliente(mysqli $conn, int $clienteId): ?array
    {
        $sql = "
            SELECT
                c.id,
                c.sucursal_registro_id,
                c.nombre,
                c.apellido,
                c.telefono,
                c.email,
                c.contacto_emergencia_nombre,
                c.contacto_emergencia_telefono,
                c.codigo_qr,
                c.estado,
                s.nombre AS sucursal_registro
            FROM clientes c
            LEFT JOIN sucursales s ON s.id = c.sucursal_registro_id
            WHERE c.id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('No fue posible preparar la consulta del socio.');
        }

        $stmt->bind_param('i', $clienteId);
        $stmt->execute();
        $resultado = $stmt->get_result();
        $fila = $resultado ? $resultado->fetch_assoc() : null;
        $stmt->close();

        return $fila ?: null;
    }
}

if (!function_exists('expediente_nombre_cliente')) {
    function expediente_nombre_cliente(array $cliente): string
    {
        return trim((string) ($cliente['nombre'] ?? '') . ' ' . (string) ($cliente['apellido'] ?? ''));
    }
}

if (!function_exists('expediente_reemplazar_documento')) {
    function expediente_reemplazar_documento(string $texto, array $datos): string
    {
        $reemplazos = [
            '{{GIMNASIO}}' => (string) ($datos['gimnasio'] ?? 'Gimnasio'),
            '{{SOCIO}}' => (string) ($datos['socio'] ?? ''),
            '{{FECHA}}' => (string) ($datos['fecha'] ?? ''),
            '{{SUCURSAL}}' => (string) ($datos['sucursal'] ?? ''),
            '{{ADMINISTRADOR}}' => (string) ($datos['administrador'] ?? ''),
        ];

        return strtr($texto, $reemplazos);
    }
}

if (!function_exists('expediente_opciones_desde_texto')) {
    function expediente_opciones_desde_texto(string $texto): array
    {
        $lineas = preg_split('/\r\n|\r|\n|,/', $texto);
        $resultado = [];

        if (!is_array($lineas)) {
            return [];
        }

        foreach ($lineas as $linea) {
            $valor = trim((string) $linea);
            if ($valor !== '' && !in_array($valor, $resultado, true)) {
                $resultado[] = $valor;
            }
        }

        return $resultado;
    }
}

if (!function_exists('expediente_respuesta_genera_alerta')) {
    function expediente_respuesta_genera_alerta(array $pregunta, string $respuesta): bool
    {
        $regla = (string) ($pregunta['dispara_alerta'] ?? 'ninguna');
        $respuestaNormalizada = strtolower(trim($respuesta));

        if ($regla === 'si') {
            return in_array($respuestaNormalizada, ['1', 'si', 'sí', 'true'], true);
        }

        if ($regla === 'no') {
            return in_array($respuestaNormalizada, ['0', 'no', 'false'], true);
        }

        if ($regla === 'cualquier_respuesta') {
            return $respuestaNormalizada !== '';
        }

        return false;
    }
}

if (!function_exists('expediente_formatear_fecha')) {
    function expediente_formatear_fecha(?string $fecha, bool $conHora = false): string
    {
        $fecha = trim((string) $fecha);
        if ($fecha === '') {
            return '—';
        }

        $timestamp = strtotime($fecha);
        if ($timestamp === false) {
            return $fecha;
        }

        return date($conHora ? 'd/m/Y H:i' : 'd/m/Y', $timestamp);
    }
}

if (!function_exists('expediente_estado_etiqueta')) {
    function expediente_estado_etiqueta(string $estado): string
    {
        $mapa = [
            'sin_observaciones' => 'Sin observaciones',
            'requiere_revision' => 'Requiere revisión',
            'documentacion_pendiente' => 'Documentación pendiente',
        ];

        return $mapa[$estado] ?? ucfirst(str_replace('_', ' ', $estado));
    }
}

if (!function_exists('expediente_firma_valida')) {
    function expediente_firma_valida(string $firma): bool
    {
        if ($firma === '') {
            return true;
        }

        if (strlen($firma) > 2200000) {
            return false;
        }

        return strpos($firma, 'data:image/png;base64,') === 0;
    }
}

if (!function_exists('expediente_bind_parametros')) {
    function expediente_bind_parametros(mysqli_stmt $stmt, string $tipos, array &$valores): void
    {
        if ($tipos === '' || $valores === []) {
            return;
        }

        $argumentos = [$tipos];
        foreach ($valores as $indice => &$valor) {
            $argumentos[] = &$valor;
        }
        unset($valor);

        call_user_func_array([$stmt, 'bind_param'], $argumentos);
    }
}
