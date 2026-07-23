<?php
declare(strict_types=1);

/**
 * Administración de documentos PDF de inscripciones y renovaciones.
 *
 * Los archivos se guardan en:
 * uploads/documentos_inscripciones/
 *
 * Cada PDF se identifica con el id de historial_pagos, por lo que la
 * inscripción inicial y cada renovación conservan su propio documento.
 * Compatible con PHP 7.4 y PHP 8.x (no utiliza str_starts_with()).
 */

function directorioDocumentosInscripciones(): string
{
    return dirname(__DIR__) . '/uploads/documentos_inscripciones';
}

function asegurarDirectorioDocumentosInscripciones(): string
{
    $directorio = directorioDocumentosInscripciones();

    if (
        !is_dir($directorio)
        && !mkdir($directorio, 0775, true)
        && !is_dir($directorio)
    ) {
        throw new RuntimeException(
            'No se pudo crear la carpeta uploads/documentos_inscripciones.'
        );
    }

    if (!is_writable($directorio)) {
        throw new RuntimeException(
            'La carpeta uploads/documentos_inscripciones no tiene permiso de escritura.'
        );
    }

    return $directorio;
}

function rutaDocumentoHistorialInscripcion(int $historialPagoId): string
{
    if ($historialPagoId <= 0) {
        throw new InvalidArgumentException(
            'El movimiento de pago no es válido.'
        );
    }

    return asegurarDirectorioDocumentosInscripciones()
        . '/membresia_' . $historialPagoId . '.pdf';
}

function urlDocumentoHistorialInscripcion(int $historialPagoId): string
{
    return 'includes/ver_documento_inscripcion.php?id=' . $historialPagoId;
}

/**
 * Recupera la información exacta del movimiento que dará origen al PDF.
 * Solo se permiten pagos positivos; las cancelaciones no generan documento.
 */
function datosDocumentoHistorialInscripcion(
    mysqli $conn,
    int $historialPagoId
): ?array {
    $stmt = $conn->prepare(
        "SELECT
            hp.id,
            hp.inscripcion_id,
            hp.cliente_id,
            hp.monto,
            hp.fecha_pago,
            hp.metodo_pago,
            hp.referencia,
            hp.periodo_inicio,
            hp.periodo_fin,
            hp.plan_nombre,
            c.nombre,
            c.apellido,
            c.codigo_qr,
            CASE
                WHEN hp.id = (
                    SELECT MIN(hp2.id)
                    FROM historial_pagos hp2
                    WHERE hp2.inscripcion_id = hp.inscripcion_id
                      AND hp2.monto > 0
                ) THEN 'inscripcion'
                ELSE 'renovacion'
            END AS tipo_documento
         FROM historial_pagos hp
         INNER JOIN clientes c ON c.id = hp.cliente_id
         WHERE hp.id = ?
           AND hp.monto > 0
         LIMIT 1"
    );
    $stmt->bind_param('i', $historialPagoId);
    $stmt->execute();
    $datos = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $datos ?: null;
}

function rutaQrDocumentoInscripcion(array $datos): string
{
    $codigoQr = trim((string) ($datos['codigo_qr'] ?? ''));
    if ($codigoQr === '') {
        return '';
    }

    $nombreArchivo = preg_replace(
        '/[^a-zA-Z0-9_-]/',
        '_',
        $codigoQr
    ) . '.png';

    $rutaRelativa = 'qrcodes/' . $nombreArchivo;
    $rutaAbsoluta = dirname(__DIR__) . '/' . $rutaRelativa;

    if (
        !is_file($rutaAbsoluta)
        && function_exists('generarCodigoQR')
    ) {
        $directorioQr = dirname($rutaAbsoluta);
        if (!is_dir($directorioQr)) {
            @mkdir($directorioQr, 0775, true);
        }

        generarCodigoQR($codigoQr, $rutaAbsoluta);
    }

    return is_file($rutaAbsoluta) ? $rutaRelativa : '';
}

function limpiarNombreDocumentoInscripcion(string $valor): string
{
    $valor = trim($valor);

    if (function_exists('limpiarNombreArchivoInscripciones')) {
        return limpiarNombreArchivoInscripciones($valor);
    }

    $valor = preg_replace('/[^a-zA-Z0-9_-]+/', '_', $valor) ?? '';
    return trim($valor, '_');
}

function nombreDescargaDocumentoInscripcion(array $datos): string
{
    $tipo = ($datos['tipo_documento'] ?? '') === 'renovacion'
        ? 'renovacion'
        : 'inscripcion';

    $socio = limpiarNombreDocumentoInscripcion(
        trim(
            (string) ($datos['nombre'] ?? '')
            . ' '
            . (string) ($datos['apellido'] ?? '')
        )
    );

    return $tipo
        . '_'
        . ($socio !== '' ? $socio : 'socio')
        . '_'
        . (int) $datos['id']
        . '.pdf';
}

/**
 * Acepta distintas respuestas del generador para mantener compatibilidad
 * con versiones previas de correo_inscripciones.php.
 */
function esContenidoPdfInscripciones(string $contenido): bool
{
    return strlen($contenido) >= 4
        && substr($contenido, 0, 4) === '%PDF';
}

function contenidoPdfDesdeAdjuntoInscripciones($adjunto): string
{
    if (is_string($adjunto)) {
        if (esContenidoPdfInscripciones($adjunto)) {
            return $adjunto;
        }

        if (is_file($adjunto)) {
            return (string) file_get_contents($adjunto);
        }

        return '';
    }

    if (!is_array($adjunto)) {
        return '';
    }

    foreach (['content', 'contenido', 'data'] as $campo) {
        if (
            isset($adjunto[$campo])
            && is_string($adjunto[$campo])
            && $adjunto[$campo] !== ''
        ) {
            return $adjunto[$campo];
        }
    }

    foreach (['path', 'ruta', 'archivo'] as $campo) {
        if (
            isset($adjunto[$campo])
            && is_string($adjunto[$campo])
            && is_file($adjunto[$campo])
        ) {
            return (string) file_get_contents($adjunto[$campo]);
        }
    }

    return '';
}

/**
 * Genera el documento si todavía no existe y devuelve su URL protegida.
 *
 * @return array{
 *     success:bool,
 *     url?:string,
 *     path?:string,
 *     name?:string,
 *     error?:string
 * }
 */
function asegurarDocumentoHistorialInscripcion(
    mysqli $conn,
    int $historialPagoId
): array {
    try {
        if (!function_exists('generarAdjuntoComprobanteInscripciones')) {
            throw new RuntimeException(
                'No está disponible el generador PDF de correo_inscripciones.php.'
            );
        }

        $datos = datosDocumentoHistorialInscripcion(
            $conn,
            $historialPagoId
        );

        if (!$datos) {
            throw new RuntimeException(
                'No se encontró un pago válido para generar el documento.'
            );
        }

        $rutaPdf = rutaDocumentoHistorialInscripcion($historialPagoId);
        $nombreDescarga = nombreDescargaDocumentoInscripcion($datos);

        if (is_file($rutaPdf) && (int) filesize($rutaPdf) > 100) {
            return [
                'success' => true,
                'url' => urlDocumentoHistorialInscripcion($historialPagoId),
                'path' => $rutaPdf,
                'name' => $nombreDescarga,
            ];
        }

        $rutaQr = rutaQrDocumentoInscripcion($datos);
        $nombreCompleto = trim(
            (string) $datos['nombre']
            . ' '
            . (string) $datos['apellido']
        );

        $gimnasio = function_exists('obtenerNombreGimnasioCorreo')
            ? obtenerNombreGimnasioCorreo($conn)
            : 'EGO';

        // Evita reutilizar un error anterior de correo/SMTP como si fuera
        // un error de la generación del documento actual.
        $GLOBALS['ultimo_error_correo_inscripciones'] = '';

        $adjunto = generarAdjuntoComprobanteInscripciones(
            (string) $datos['tipo_documento'],
            $gimnasio,
            [
                'nombre' => $nombreCompleto,
                'plan' => (string) $datos['plan_nombre'],
                'fecha_inicio' => $datos['periodo_inicio'],
                'fecha_fin' => $datos['periodo_fin'],
                'monto' => (float) $datos['monto'],
                'metodo_pago' => (string) $datos['metodo_pago'],
                'referencia' => $datos['referencia'],
                'codigo_qr' => (string) ($datos['codigo_qr'] ?? ''),
                'ruta_qr' => $rutaQr,
                'qr_data_uri' => function_exists('imagenBase64Inscripciones')
                    ? imagenBase64Inscripciones($rutaQr)
                    : '',
            ]
        );

        $contenidoPdf = contenidoPdfDesdeAdjuntoInscripciones($adjunto);

        if ($contenidoPdf === '' || !esContenidoPdfInscripciones($contenidoPdf)) {
            $detalle = function_exists(
                'obtenerUltimoErrorCorreoInscripciones'
            )
                ? trim((string) obtenerUltimoErrorCorreoInscripciones())
                : '';

            throw new RuntimeException(
                $detalle !== ''
                    ? $detalle
                    : 'El generador no devolvió un PDF válido.'
            );
        }

        $temporal = $rutaPdf . '.tmp-' . bin2hex(random_bytes(5));
        $bytes = file_put_contents($temporal, $contenidoPdf, LOCK_EX);

        if ($bytes === false || $bytes < 100) {
            @unlink($temporal);
            throw new RuntimeException(
                'No se pudo guardar el documento PDF.'
            );
        }

        if (!@rename($temporal, $rutaPdf)) {
            // En algunos entornos de Windows/XAMPP el cambio de nombre puede
            // fallar temporalmente aunque la carpeta sí permita escritura.
            if (!@copy($temporal, $rutaPdf)) {
                @unlink($temporal);
                throw new RuntimeException(
                    'No se pudo finalizar el archivo PDF en uploads/documentos_inscripciones.'
                );
            }

            @unlink($temporal);
        }

        return [
            'success' => true,
            'url' => urlDocumentoHistorialInscripcion($historialPagoId),
            'path' => $rutaPdf,
            'name' => $nombreDescarga,
        ];
    } catch (Throwable $e) {
        error_log('[Documentos inscripciones] ' . $e->getMessage());

        return [
            'success' => false,
            'error' => $e->getMessage(),
        ];
    }
}