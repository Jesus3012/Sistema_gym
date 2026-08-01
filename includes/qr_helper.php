<?php

declare(strict_types=1);

/**
 * Generador QR local para los códigos numéricos de socios.
 *
 * - No requiere Composer ni servicios externos.
 * - Genera un QR estándar versión 1, nivel L (hasta 41 dígitos).
 * - Escribe el PNG de forma atómica para evitar archivos incompletos.
 * - No requiere GD; crea el PNG directamente con zlib.
 */

function qr_helper_error(string $mensaje): void
{
    $GLOBALS['qr_helper_ultimo_error'] = trim($mensaje);
    error_log('[QR socios] ' . trim($mensaje));
}

function qr_helper_ultimo_error(): string
{
    return trim((string) ($GLOBALS['qr_helper_ultimo_error'] ?? ''));
}

function obtenerUltimoErrorQR(): string
{
    return qr_helper_ultimo_error();
}

/** @param array<int,int> $bytes */
function qr_helper_rs_divisor(int $grado): array
{
    $resultado = array_fill(0, $grado, 0);
    $resultado[$grado - 1] = 1;
    $raiz = 1;

    for ($i = 0; $i < $grado; $i++) {
        for ($j = 0; $j < $grado; $j++) {
            $resultado[$j] = qr_helper_gf_multiplicar($resultado[$j], $raiz);
            if ($j + 1 < $grado) {
                $resultado[$j] ^= $resultado[$j + 1];
            }
        }
        $raiz = qr_helper_gf_multiplicar($raiz, 0x02);
    }

    return $resultado;
}

function qr_helper_gf_multiplicar(int $x, int $y): int
{
    $z = 0;
    for ($i = 7; $i >= 0; $i--) {
        $z = (($z << 1) ^ ((($z >> 7) & 1) * 0x11D)) & 0xFF;
        $z ^= ((($y >> $i) & 1) * $x);
    }
    return $z;
}

/** @param array<int,int> $datos
 *  @param array<int,int> $divisor
 *  @return array<int,int>
 */
function qr_helper_rs_resto(array $datos, array $divisor): array
{
    $resultado = array_fill(0, count($divisor), 0);

    foreach ($datos as $byte) {
        $factor = $byte ^ $resultado[0];
        array_shift($resultado);
        $resultado[] = 0;

        foreach ($divisor as $i => $coeficiente) {
            $resultado[$i] ^= qr_helper_gf_multiplicar($coeficiente, $factor);
        }
    }

    return $resultado;
}

function qr_helper_agregar_bits(array &$bits, int $valor, int $longitud): void
{
    for ($i = $longitud - 1; $i >= 0; $i--) {
        $bits[] = ($valor >> $i) & 1;
    }
}

/** @return array<int,int> */
function qr_helper_codificar_numerico(string $texto): array
{
    if ($texto === '' || preg_match('/^\d{1,41}$/', $texto) !== 1) {
        throw new InvalidArgumentException(
            'El código QR debe contener entre 1 y 41 dígitos.'
        );
    }

    $bits = [];
    qr_helper_agregar_bits($bits, 0x1, 4); // Modo numérico.
    qr_helper_agregar_bits($bits, strlen($texto), 10); // Versión 1.

    $longitud = strlen($texto);
    for ($i = 0; $i < $longitud; $i += 3) {
        $grupo = substr($texto, $i, 3);
        $cantidad = strlen($grupo);
        qr_helper_agregar_bits(
            $bits,
            (int) $grupo,
            $cantidad === 3 ? 10 : ($cantidad === 2 ? 7 : 4)
        );
    }

    // Versión 1-L: 19 codewords de datos = 152 bits.
    $capacidad = 152;
    $terminador = min(4, $capacidad - count($bits));
    qr_helper_agregar_bits($bits, 0, $terminador);

    while (count($bits) % 8 !== 0) {
        $bits[] = 0;
    }

    $datos = [];
    for ($i = 0; $i < count($bits); $i += 8) {
        $byte = 0;
        for ($j = 0; $j < 8; $j++) {
            $byte = ($byte << 1) | $bits[$i + $j];
        }
        $datos[] = $byte;
    }

    $rellenos = [0xEC, 0x11];
    $indiceRelleno = 0;
    while (count($datos) < 19) {
        $datos[] = $rellenos[$indiceRelleno % 2];
        $indiceRelleno++;
    }

    $ecc = qr_helper_rs_resto($datos, qr_helper_rs_divisor(7));
    return array_merge($datos, $ecc);
}

/** @param array<int,array<int,bool>> $modulos
 *  @param array<int,array<int,bool>> $funcion
 */
function qr_helper_modulo_funcion(
    array &$modulos,
    array &$funcion,
    int $x,
    int $y,
    bool $oscuro
): void {
    if ($x < 0 || $y < 0 || $y >= count($modulos) || $x >= count($modulos)) {
        return;
    }
    $modulos[$y][$x] = $oscuro;
    $funcion[$y][$x] = true;
}

/** @param array<int,array<int,bool>> $modulos
 *  @param array<int,array<int,bool>> $funcion
 */
function qr_helper_dibujar_finder(
    array &$modulos,
    array &$funcion,
    int $centroX,
    int $centroY
): void {
    for ($dy = -4; $dy <= 4; $dy++) {
        for ($dx = -4; $dx <= 4; $dx++) {
            $distancia = max(abs($dx), abs($dy));
            $oscuro = $distancia !== 2 && $distancia !== 4;
            qr_helper_modulo_funcion(
                $modulos,
                $funcion,
                $centroX + $dx,
                $centroY + $dy,
                $oscuro
            );
        }
    }
}

function qr_helper_bit(int $valor, int $indice): bool
{
    return (($valor >> $indice) & 1) !== 0;
}

/** @param array<int,array<int,bool>> $modulos
 *  @param array<int,array<int,bool>> $funcion
 */
function qr_helper_dibujar_formato(
    array &$modulos,
    array &$funcion,
    int $mascara
): void {
    // Nivel L = 01. Se guarda como 5 bits: ECL(01) + máscara.
    $datos = (1 << 3) | $mascara;
    $resto = $datos;
    for ($i = 0; $i < 10; $i++) {
        $resto = ($resto << 1) ^ ((($resto >> 9) & 1) * 0x537);
    }
    $bits = (($datos << 10) | $resto) ^ 0x5412;
    $tamano = count($modulos);

    for ($i = 0; $i <= 5; $i++) {
        qr_helper_modulo_funcion($modulos, $funcion, 8, $i, qr_helper_bit($bits, $i));
    }
    qr_helper_modulo_funcion($modulos, $funcion, 8, 7, qr_helper_bit($bits, 6));
    qr_helper_modulo_funcion($modulos, $funcion, 8, 8, qr_helper_bit($bits, 7));
    qr_helper_modulo_funcion($modulos, $funcion, 7, 8, qr_helper_bit($bits, 8));
    for ($i = 9; $i < 15; $i++) {
        qr_helper_modulo_funcion(
            $modulos,
            $funcion,
            14 - $i,
            8,
            qr_helper_bit($bits, $i)
        );
    }

    for ($i = 0; $i < 8; $i++) {
        qr_helper_modulo_funcion(
            $modulos,
            $funcion,
            $tamano - 1 - $i,
            8,
            qr_helper_bit($bits, $i)
        );
    }
    for ($i = 8; $i < 15; $i++) {
        qr_helper_modulo_funcion(
            $modulos,
            $funcion,
            8,
            $tamano - 15 + $i,
            qr_helper_bit($bits, $i)
        );
    }

    // Módulo oscuro fijo de la especificación.
    qr_helper_modulo_funcion($modulos, $funcion, 8, $tamano - 8, true);
}

/** @param array<int,int> $codewords
 *  @return array<int,array<int,bool>>
 */
function qr_helper_construir_matriz(array $codewords): array
{
    $tamano = 21;
    $modulos = array_fill(0, $tamano, array_fill(0, $tamano, false));
    $funcion = array_fill(0, $tamano, array_fill(0, $tamano, false));

    // Patrones de sincronización primero; los finder los sobreescriben.
    for ($i = 0; $i < $tamano; $i++) {
        qr_helper_modulo_funcion($modulos, $funcion, 6, $i, $i % 2 === 0);
        qr_helper_modulo_funcion($modulos, $funcion, $i, 6, $i % 2 === 0);
    }

    qr_helper_dibujar_finder($modulos, $funcion, 3, 3);
    qr_helper_dibujar_finder($modulos, $funcion, $tamano - 4, 3);
    qr_helper_dibujar_finder($modulos, $funcion, 3, $tamano - 4);

    // Reserva y escribe formato para máscara 0.
    qr_helper_dibujar_formato($modulos, $funcion, 0);

    $bits = [];
    foreach ($codewords as $byte) {
        qr_helper_agregar_bits($bits, $byte, 8);
    }

    $indice = 0;
    $haciaArriba = true;
    for ($derecha = $tamano - 1; $derecha >= 1; $derecha -= 2) {
        if ($derecha === 6) {
            $derecha--;
        }

        for ($vertical = 0; $vertical < $tamano; $vertical++) {
            $y = $haciaArriba ? $tamano - 1 - $vertical : $vertical;

            for ($j = 0; $j < 2; $j++) {
                $x = $derecha - $j;
                if ($funcion[$y][$x]) {
                    continue;
                }

                $oscuro = $indice < count($bits) ? $bits[$indice] === 1 : false;
                $indice++;

                // Máscara 0: (fila + columna) par.
                if ((($x + $y) % 2) === 0) {
                    $oscuro = !$oscuro;
                }
                $modulos[$y][$x] = $oscuro;
            }
        }
        $haciaArriba = !$haciaArriba;
    }

    return $modulos;
}

/** @param array<int,array<int,bool>> $matriz */
function qr_helper_png_chunk(string $tipo, string $datos): string
{
    return pack('N', strlen($datos))
        . $tipo
        . $datos
        . pack('N', crc32($tipo . $datos));
}

/** @param array<int,array<int,bool>> $matriz */
function qr_helper_guardar_png(
    array $matriz,
    string $rutaDestino,
    int $escala = 10,
    int $margen = 4
): bool {
    if (!function_exists('gzcompress')) {
        qr_helper_error(
            'La extensión zlib de PHP no está habilitada y es necesaria para crear el PNG del QR.'
        );
        return false;
    }

    $directorio = dirname($rutaDestino);
    if (!is_dir($directorio) && !@mkdir($directorio, 0775, true) && !is_dir($directorio)) {
        qr_helper_error('No se pudo crear la carpeta del QR: ' . $directorio);
        return false;
    }

    if (!is_writable($directorio)) {
        qr_helper_error('La carpeta del QR no tiene permisos de escritura: ' . $directorio);
        return false;
    }

    $escala = max(4, min(20, $escala));
    $margen = max(4, min(10, $margen));
    $ladoModulos = count($matriz);
    $ladoPx = ($ladoModulos + ($margen * 2)) * $escala;

    $filaBlanca = str_repeat("\xFF", $ladoPx);
    $filas = [];

    // Margen superior.
    for ($i = 0; $i < $margen * $escala; $i++) {
        $filas[] = "\x00" . $filaBlanca;
    }

    foreach ($matriz as $filaModulos) {
        $fila = str_repeat("\xFF", $margen * $escala);
        foreach ($filaModulos as $oscuro) {
            $fila .= str_repeat($oscuro ? "\x00" : "\xFF", $escala);
        }
        $fila .= str_repeat("\xFF", $margen * $escala);

        for ($i = 0; $i < $escala; $i++) {
            // Filtro PNG 0: sin filtro.
            $filas[] = "\x00" . $fila;
        }
    }

    // Margen inferior.
    for ($i = 0; $i < $margen * $escala; $i++) {
        $filas[] = "\x00" . $filaBlanca;
    }

    $datosCrudos = implode('', $filas);
    $comprimidos = gzcompress($datosCrudos, 6);
    if (!is_string($comprimidos) || $comprimidos === '') {
        qr_helper_error('No se pudieron comprimir los datos PNG del QR.');
        return false;
    }

    // PNG 8 bits, escala de grises, sin entrelazado.
    $png = "\x89PNG\r\n\x1A\n"
        . qr_helper_png_chunk(
            'IHDR',
            pack('NNCCCCC', $ladoPx, $ladoPx, 8, 0, 0, 0, 0)
        )
        . qr_helper_png_chunk('IDAT', $comprimidos)
        . qr_helper_png_chunk('IEND', '');

    $temporal = $rutaDestino . '.tmp-' . bin2hex(random_bytes(5));
    $bytes = @file_put_contents($temporal, $png, LOCK_EX);

    if ($bytes === false || !is_file($temporal) || filesize($temporal) < 100) {
        @unlink($temporal);
        qr_helper_error('No se pudo escribir el archivo PNG del QR.');
        return false;
    }

    if (is_file($rutaDestino)) {
        @unlink($rutaDestino);
    }

    if (!@rename($temporal, $rutaDestino)) {
        $copiado = @copy($temporal, $rutaDestino);
        @unlink($temporal);
        if (!$copiado) {
            qr_helper_error('No se pudo mover el QR a su ruta definitiva.');
            return false;
        }
    }

    clearstatcache(true, $rutaDestino);
    return is_file($rutaDestino) && filesize($rutaDestino) > 100;
}

function generarCodigoQR(string $contenido, string $rutaArchivo): bool
{
    $GLOBALS['qr_helper_ultimo_error'] = '';
    $contenido = trim($contenido);
    $rutaArchivo = trim($rutaArchivo);

    if ($contenido === '' || $rutaArchivo === '') {
        qr_helper_error('El contenido o la ruta del QR están vacíos.');
        return false;
    }

    // Convierte rutas relativas a una ruta absoluta desde la raíz del proyecto.
    $esAbsolutaWindows = preg_match('/^[a-zA-Z]:[\\\\\/]/', $rutaArchivo) === 1;
    $esAbsolutaUnix = substr($rutaArchivo, 0, 1) === '/';
    if (!$esAbsolutaWindows && !$esAbsolutaUnix) {
        $rutaArchivo = dirname(__DIR__) . DIRECTORY_SEPARATOR
            . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, ltrim($rutaArchivo, '/\\'));
    }

    try {
        $matriz = qr_helper_construir_matriz(
            qr_helper_codificar_numerico($contenido)
        );
        return qr_helper_guardar_png($matriz, $rutaArchivo, 10, 4);
    } catch (Throwable $e) {
        qr_helper_error($e->getMessage());
        return false;
    }
}

function generarCodigoQRUnico(mysqli $conn): ?string
{
    for ($intento = 0; $intento < 30; $intento++) {
        $codigo = date('ymdHis') . str_pad(
            (string) random_int(0, 999),
            3,
            '0',
            STR_PAD_LEFT
        );

        $stmt = $conn->prepare(
            "SELECT id FROM clientes WHERE codigo_qr = ? LIMIT 1"
        );
        $stmt->bind_param('s', $codigo);
        $stmt->execute();
        $existe = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$existe) {
            return $codigo;
        }
        usleep(1000);
    }

    qr_helper_error('No fue posible crear un código QR único después de varios intentos.');
    return null;
}
