<?php
// includes/qr_helper.php

function limpiarNombreArchivo($texto) {
    $texto = strtolower(trim($texto));

    $texto = str_replace(
        ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'Á', 'É', 'Í', 'Ó', 'Ú', 'Ñ'],
        ['a', 'e', 'i', 'o', 'u', 'n', 'a', 'e', 'i', 'o', 'u', 'n'],
        $texto
    );

    $texto = preg_replace('/[^a-z0-9]+/', '_', $texto);
    $texto = trim($texto, '_');

    return $texto;
}

function obtenerRutaQRCliente($id, $nombre, $apellido) {
    $nombre_limpio = limpiarNombreArchivo($nombre . '_' . $apellido);

    return __DIR__ . '/../qrcodes/' .
           $nombre_limpio . '_' .
           $id . '.png';
}

function obtenerUrlQRCliente($id, $nombre, $apellido) {
    $nombre_limpio = limpiarNombreArchivo($nombre . '_' . $apellido);

    $ruta_relativa = 'qrcodes/' .
                     $nombre_limpio . '_' .
                     $id . '.png';

    $ruta_fisica = __DIR__ . '/../' . $ruta_relativa;

    return file_exists($ruta_fisica) ? $ruta_relativa : '';
}

function generarCodigoQR($texto, $ruta_archivo = null) {
    if (empty($texto)) {
        return false;
    }

    if ($ruta_archivo === null) {
        $directorio_qr = __DIR__ . '/../qrcodes/';

        if (!file_exists($directorio_qr)) {
            mkdir($directorio_qr, 0777, true);
        }

        $nombre_archivo = md5($texto) . '.png';
        $ruta_archivo = $directorio_qr . $nombre_archivo;
    }

    $directorio = dirname($ruta_archivo);

    if (!file_exists($directorio)) {
        mkdir($directorio, 0777, true);
    }

    $tamano = 500;

    $apis = [
        "https://quickchart.io/qr?text=" . urlencode($texto) . "&size={$tamano}&margin=4&dark=000000&light=ffffff&ecLevel=H",
        "https://api.qrserver.com/v1/create-qr-code/?size={$tamano}x{$tamano}&margin=20&ecc=H&data=" . urlencode($texto)
    ];

    foreach ($apis as $url_api) {
        $imagen_qr = @file_get_contents($url_api);

        if ($imagen_qr === false || strlen($imagen_qr) < 1000) {
            continue;
        }

        $img = @imagecreatefromstring($imagen_qr);

        if (!$img) {
            continue;
        }

        imagepng($img, $ruta_archivo, 0);
        imagedestroy($img);

        if (file_exists($ruta_archivo) && filesize($ruta_archivo) > 1000) {
            return $ruta_archivo;
        }
    }

    return false;
}

function generarCodigoQRUnico($conn) {
    $intentos = 0;
    $max_intentos = 20;

    while ($intentos < $max_intentos) {
        $codigo = date('ymdHis') . rand(100, 999);

        $stmt = $conn->prepare("SELECT id FROM clientes WHERE codigo_qr = ? LIMIT 1");
        $stmt->bind_param("s", $codigo);
        $stmt->execute();

        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            return $codigo;
        }

        $intentos++;
        usleep(100000);
    }

    return false;
}

function obtenerUrlQR($codigo_qr) {
    if (empty($codigo_qr)) {
        return '';
    }

    $ruta_relativa = 'qrcodes/' . md5($codigo_qr) . '.png';
    $ruta_fisica = __DIR__ . '/../' . $ruta_relativa;

    return file_exists($ruta_fisica) ? $ruta_relativa : '';
}

function regenerarTodosLosQR($conn) {
    $clientes = $conn->query("SELECT id, nombre, apellido FROM clientes ORDER BY id");
    $actualizados = 0;

    while ($cliente = $clientes->fetch_assoc()) {
        $nuevo_codigo = generarCodigoQRUnico($conn);

        if (!$nuevo_codigo) {
            continue;
        }

        $ruta_qr = obtenerRutaQRCliente(
            $cliente['id'],
            $cliente['nombre'],
            $cliente['apellido']
        );

        if (generarCodigoQR($nuevo_codigo, $ruta_qr)) {
            $stmt = $conn->prepare("UPDATE clientes SET codigo_qr = ? WHERE id = ?");
            $stmt->bind_param("si", $nuevo_codigo, $cliente['id']);
            $stmt->execute();

            $actualizados++;
        }

        usleep(300000);
    }

    return $actualizados;
}
?>