<?php
// Archivo: includes/servicio_plataforma_helper.php
// Estado global del servicio contratado para esta instalación de EGO.

declare(strict_types=1);

if (!function_exists('servicio_plataforma_tabla_existe')) {
    function servicio_plataforma_tabla_existe(mysqli $db, string $tabla): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $tabla)) {
            return false;
        }

        $tablaEscapada = $db->real_escape_string($tabla);
        $resultado = $db->query("SHOW TABLES LIKE '{$tablaEscapada}'");

        return $resultado instanceof mysqli_result
            && $resultado->num_rows > 0;
    }
}

if (!function_exists('servicio_plataforma_instalado')) {
    function servicio_plataforma_instalado(mysqli $db): bool
    {
        return servicio_plataforma_tabla_existe(
            $db,
            'servicio_plataforma'
        ) && servicio_plataforma_tabla_existe(
            $db,
            'servicio_plataforma_historial'
        );
    }
}

if (!function_exists('servicio_plataforma_fecha_valida')) {
    function servicio_plataforma_fecha_valida(string $fecha): bool
    {
        $fecha = trim($fecha);
        $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);

        return $objeto instanceof DateTimeImmutable
            && $objeto->format('Y-m-d') === $fecha;
    }
}

if (!function_exists('servicio_plataforma_calcular_vencimiento')) {
    function servicio_plataforma_calcular_vencimiento(
        string $fechaInicio,
        int $meses
    ): string {
        if (!servicio_plataforma_fecha_valida($fechaInicio)) {
            throw new InvalidArgumentException(
                'La fecha de inicio del periodo no es válida.'
            );
        }

        if ($meses < 1 || $meses > 120) {
            throw new InvalidArgumentException(
                'Los meses contratados deben estar entre 1 y 120.'
            );
        }

        $inicio = new DateTimeImmutable($fechaInicio);
        $diaInicio = (int) $inicio->format('j');
        $primerDiaMesDestino = $inicio
            ->modify('first day of this month')
            ->modify('+' . $meses . ' months');
        $diasMesDestino = (int) $primerDiaMesDestino->format('t');

        /*
         * Evita el salto de PHP en fechas como 31 de enero + 1 mes.
         * Si el mes destino no contiene ese día, el periodo vence el
         * último día disponible de dicho mes.
         */
        if ($diaInicio > $diasMesDestino) {
            return $primerDiaMesDestino
                ->setDate(
                    (int) $primerDiaMesDestino->format('Y'),
                    (int) $primerDiaMesDestino->format('n'),
                    $diasMesDestino
                )
                ->format('Y-m-d');
        }

        $aniversario = $primerDiaMesDestino->setDate(
            (int) $primerDiaMesDestino->format('Y'),
            (int) $primerDiaMesDestino->format('n'),
            $diaInicio
        );

        return $aniversario
            ->modify('-1 day')
            ->format('Y-m-d');
    }
}

if (!function_exists('servicio_plataforma_obtener')) {
    function servicio_plataforma_obtener(mysqli $db): ?array
    {
        if (!servicio_plataforma_tabla_existe($db, 'servicio_plataforma')) {
            return null;
        }

        $resultado = $db->query(
            "SELECT
                id,
                proveedor_nombre,
                contacto_email,
                contacto_telefono,
                fecha_inicio,
                periodo_actual_inicio,
                fecha_vencimiento,
                precio_mensual,
                meses_ultimo_pago,
                importe_ultimo_pago,
                dias_aviso,
                activo,
                bloquear_al_vencer,
                notas,
                actualizado_por,
                created_at,
                updated_at
             FROM servicio_plataforma
             WHERE id = 1
             LIMIT 1"
        );

        if (!$resultado instanceof mysqli_result) {
            throw new RuntimeException(
                'No fue posible consultar la configuración del servicio.'
            );
        }

        $fila = $resultado->fetch_assoc();

        return $fila ?: null;
    }
}

if (!function_exists('servicio_plataforma_formatear_fecha')) {
    function servicio_plataforma_formatear_fecha(?string $fecha): string
    {
        $fecha = trim((string) $fecha);

        if (!servicio_plataforma_fecha_valida($fecha)) {
            return 'Sin definir';
        }

        return (new DateTimeImmutable($fecha))->format('d/m/Y');
    }
}

if (!function_exists('servicio_plataforma_contacto_texto')) {
    function servicio_plataforma_contacto_texto(array $configuracion): string
    {
        $proveedor = trim((string) (
            $configuracion['proveedor_nombre'] ?? 'GGFit'
        ));

        if ($proveedor === '') {
            $proveedor = 'GGFit';
        }

        return 'Contacta a ' . $proveedor . ' para renovar.';
    }
}

if (!function_exists('servicio_plataforma_resumen')) {
    function servicio_plataforma_resumen(mysqli $db): array
    {
        $base = [
            'instalado' => false,
            'configurado' => false,
            'estado' => 'sin_configurar',
            'nivel' => 'neutral',
            'titulo' => 'Servicio sin configurar',
            'mensaje' => 'El superadministrador todavía no ha definido el periodo de uso de la plataforma.',
            'mostrar_aviso' => false,
            'debe_bloquear' => false,
            'dias_restantes' => null,
            'dias_para_inicio' => null,
            'fecha_inicio_formateada' => 'Sin definir',
            'fecha_vencimiento_formateada' => 'Sin definir',
            'configuracion' => null,
        ];

        if (!servicio_plataforma_instalado($db)) {
            return $base;
        }

        $base['instalado'] = true;
        $configuracion = servicio_plataforma_obtener($db);

        if (!$configuracion) {
            return $base;
        }

        $base['configurado'] = true;
        $base['configuracion'] = $configuracion;
        $base['fecha_inicio_formateada'] =
            servicio_plataforma_formatear_fecha(
                (string) ($configuracion['fecha_inicio'] ?? '')
            );
        $base['fecha_vencimiento_formateada'] =
            servicio_plataforma_formatear_fecha(
                (string) ($configuracion['fecha_vencimiento'] ?? '')
            );

        $fechaInicio = trim((string) (
            $configuracion['fecha_inicio'] ?? ''
        ));
        $fechaVencimiento = trim((string) (
            $configuracion['fecha_vencimiento'] ?? ''
        ));

        if (
            !servicio_plataforma_fecha_valida($fechaInicio)
            || !servicio_plataforma_fecha_valida($fechaVencimiento)
        ) {
            $base['estado'] = 'configuracion_invalida';
            $base['nivel'] = 'danger';
            $base['titulo'] = 'Configuración incompleta';
            $base['mensaje'] = 'Las fechas del servicio no son válidas. El superadministrador debe corregirlas.';
            $base['mostrar_aviso'] = true;

            return $base;
        }

        $zona = new DateTimeZone(
            (string) (
                $_SESSION['sucursal_zona_horaria']
                ?? 'America/Mexico_City'
            )
        );
        $hoy = new DateTimeImmutable('today', $zona);
        $inicio = new DateTimeImmutable($fechaInicio, $zona);
        $vencimiento = new DateTimeImmutable($fechaVencimiento, $zona);
        $diasParaInicio = (int) $hoy->diff($inicio)->format('%r%a');
        $diasRestantes = (int) $hoy->diff($vencimiento)->format('%r%a');
        $diasAviso = max(0, (int) ($configuracion['dias_aviso'] ?? 7));
        $activo = (int) ($configuracion['activo'] ?? 1) === 1;
        $bloquearAlVencer =
            (int) ($configuracion['bloquear_al_vencer'] ?? 0) === 1;
        $contacto = servicio_plataforma_contacto_texto($configuracion);

        $base['dias_para_inicio'] = $diasParaInicio;
        $base['dias_restantes'] = $diasRestantes;

        if (!$activo) {
            $base['estado'] = 'suspendido';
            $base['nivel'] = 'danger';
            $base['titulo'] = 'Servicio suspendido';
            $base['mensaje'] = 'El acceso a la plataforma fue suspendido por el proveedor. ' . $contacto;
            $base['mostrar_aviso'] = true;
            $base['debe_bloquear'] = true;

            return $base;
        }

        if ($diasParaInicio > 0) {
            $base['estado'] = 'programado';
            $base['nivel'] = 'info';
            $base['titulo'] = 'Servicio programado';
            $base['mensaje'] = 'El periodo de uso comienza el '
                . $base['fecha_inicio_formateada'] . '.';
            $base['mostrar_aviso'] = true;

            return $base;
        }

        if ($diasRestantes < 0) {
            $base['estado'] = 'vencido';
            $base['nivel'] = 'danger';
            $base['titulo'] = 'Servicio vencido';
            $base['mensaje'] = 'El servicio de la plataforma venció el '
                . $base['fecha_vencimiento_formateada'] . '. '
                . $contacto;
            $base['mostrar_aviso'] = true;
            $base['debe_bloquear'] = $bloquearAlVencer;

            return $base;
        }

        if ($diasRestantes === 0) {
            $base['estado'] = 'vence_hoy';
            $base['nivel'] = 'danger';
            $base['titulo'] = 'El servicio vence hoy';
            $base['mensaje'] = 'El servicio de la plataforma vence hoy ('
                . $base['fecha_vencimiento_formateada'] . '). '
                . $contacto;
            $base['mostrar_aviso'] = true;

            return $base;
        }

        if ($diasRestantes === 1) {
            $base['estado'] = 'por_vencer';
            $base['nivel'] = 'warning';
            $base['titulo'] = 'El servicio vence mañana';
            $base['mensaje'] = 'El servicio de la plataforma vence mañana ('
                . $base['fecha_vencimiento_formateada'] . '). '
                . $contacto;
            $base['mostrar_aviso'] = true;

            return $base;
        }

        if ($diasRestantes <= $diasAviso) {
            $base['estado'] = 'por_vencer';
            $base['nivel'] = 'warning';
            $base['titulo'] = 'Servicio próximo a vencer';
            $base['mensaje'] = 'El servicio de la plataforma vence en '
                . $diasRestantes . ' días ('
                . $base['fecha_vencimiento_formateada'] . '). '
                . $contacto;
            $base['mostrar_aviso'] = true;

            return $base;
        }

        $base['estado'] = 'activo';
        $base['nivel'] = 'success';
        $base['titulo'] = 'Servicio activo';
        $base['mensaje'] = 'La plataforma está activa hasta el '
            . $base['fecha_vencimiento_formateada'] . '.';

        return $base;
    }
}

if (!function_exists('servicio_plataforma_es_superadministrador')) {
    function servicio_plataforma_es_superadministrador(): bool
    {
        if (function_exists('rol_base_real_sesion')) {
            return rol_base_real_sesion() === 'super_administrador';
        }

        $rol = strtolower(trim((string) (
            $_SESSION['user_rol_base']
            ?? $_SESSION['user_rol']
            ?? ''
        )));

        return $rol === 'super_administrador';
    }
}
