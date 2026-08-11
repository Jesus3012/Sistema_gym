<?php
declare(strict_types=1);

require_once __DIR__ . '/asistencia_context.php';
require_once __DIR__ . '/asistencia_salida_minima.php';

try {
    $contexto = asistencia_contexto();
    asistencia_exigir_sede_concreta($contexto);

    $clienteId = (int) (
        $_POST['cliente_id'] ?? 0
    );

    $tipo = strtolower(trim((string) (
        $_POST['tipo'] ?? 'entrada'
    )));

    if ($clienteId <= 0) {
        asistencia_error(
            'Selecciona un socio válido.'
        );
    }

    if (!in_array(
        $tipo,
        ['entrada', 'salida'],
        true
    )) {
        asistencia_error(
            'Selecciona entrada o salida.'
        );
    }

    $cliente = asistencia_obtener_cliente_id(
        $contexto['conn'],
        $clienteId
    );

    if ($cliente === null) {
        asistencia_error(
            'El socio seleccionado no existe.',
            404
        );
    }

    if ($tipo === 'salida') {
        asistencia_exigir_tiempo_minimo_salida(
            $contexto['conn'],
            (int) $contexto['sucursal_id'],
            $clienteId,
            5
        );
    }

    $resultado = asistencia_registrar(
        $contexto['conn'],
        (int) $contexto['sucursal_id'],
        (int) $contexto['usuario_id'],
        $cliente,
        'manual',
        $tipo
    );

    asistencia_ok([
        'message' =>
            $resultado['tipo'] === 'entrada'
                ? 'Entrada registrada correctamente a las ' .
                    $resultado['hora_entrada']
                : 'Salida registrada correctamente a las ' .
                    $resultado['hora_salida'],
        'tipo' => $resultado['tipo'],
        'cliente_nombre' =>
            $resultado['cliente_nombre'],
        'hora_entrada' =>
            $resultado['hora_entrada'],
        'hora_salida' =>
            $resultado['hora_salida'],
    ]);
} catch (AsistenciaOperacionException $error) {
    asistencia_error(
        $error->getMessage(),
        422,
        ['code' => 'asistencia_no_permitida']
    );
} catch (Throwable $error) {
    error_log(
        '[Registro manual asistencia][servidor] ' .
        $error->getMessage() .
        ' en ' .
        $error->getFile() .
        ':' .
        $error->getLine()
    );

    asistencia_error(
        'No se pudo registrar la asistencia por un error interno.',
        500,
        ['code' => 'asistencia_error_servidor']
    );
}
