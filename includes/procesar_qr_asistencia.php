<?php
declare(strict_types=1);

require_once __DIR__ . '/asistencia_context.php';
require_once __DIR__ . '/asistencia_salida_minima.php';

try {
    $contexto = asistencia_contexto();
    asistencia_exigir_sede_concreta($contexto);

    $codigoQr = trim((string) (
        $_POST['codigo_qr'] ?? ''
    ));

    if ($codigoQr === '') {
        asistencia_error(
            'Código QR no proporcionado.'
        );
    }

    $cliente = asistencia_obtener_cliente_qr(
        $contexto['conn'],
        $codigoQr
    );

    if ($cliente === null) {
        asistencia_error(
            'Código QR no válido.',
            404
        );
    }

    /*
     * Cuando existe una entrada abierta, el modo "auto" convertiría
     * la siguiente lectura en salida. La bloqueamos durante 5 minutos
     * para evitar salidas accidentales por una segunda lectura del QR.
     */
    asistencia_exigir_tiempo_minimo_salida(
        $contexto['conn'],
        (int) $contexto['sucursal_id'],
        (int) $cliente['id'],
        5
    );

    $resultado = asistencia_registrar(
        $contexto['conn'],
        (int) $contexto['sucursal_id'],
        (int) $contexto['usuario_id'],
        $cliente,
        'qr',
        'auto'
    );

    asistencia_ok([
        'tipo' => $resultado['tipo'],
        'cliente_nombre' =>
            $resultado['cliente_nombre'],
        'hora_entrada' =>
            $resultado['hora_entrada'],
        'hora_salida' =>
            $resultado['hora_salida'],
        'mensaje' =>
            $resultado['tipo'] === 'entrada'
                ? 'Acceso permitido. Entrada registrada.'
                : 'Salida registrada correctamente.',
        'plan_nombre' =>
            $resultado['plan_nombre'],
        'dias_restantes' =>
            $resultado['dias_restantes'],
    ]);
} catch (AsistenciaOperacionException $error) {
    asistencia_error(
        $error->getMessage(),
        422,
        ['code' => 'asistencia_no_permitida']
    );
} catch (Throwable $error) {
    error_log(
        '[QR asistencia][servidor] ' .
        $error->getMessage() .
        ' en ' .
        $error->getFile() .
        ':' .
        $error->getLine()
    );

    asistencia_error(
        'No se pudo procesar el acceso por un error interno.',
        500,
        ['code' => 'asistencia_error_servidor']
    );
}
