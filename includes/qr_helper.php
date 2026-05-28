<?php
// includes/qr_helper.php
// Generador de códigos QR usando API gratuita (sin librerías adicionales)

function generarCodigoQR($texto, $ruta_archivo = null) {
    // Si no se especifica ruta, usar una por defecto
    if ($ruta_archivo === null) {
        $directorio_qr = 'qrcodes/';
        if (!file_exists($directorio_qr)) {
            mkdir($directorio_qr, 0777, true);
        }
        // Usar el texto como nombre de archivo (reemplazando caracteres especiales)
        $nombre_archivo = preg_replace('/[^a-zA-Z0-9_-]/', '_', $texto) . '.png';
        $ruta_archivo = $directorio_qr . $nombre_archivo;
    }
    
    // Asegurar que el directorio existe
    $directorio = dirname($ruta_archivo);
    if (!file_exists($directorio)) {
        mkdir($directorio, 0777, true);
    }
    
    // Usar API gratuita de QR code
    $tamano = 300;
    $url_api = "https://api.qrserver.com/v1/create-qr-code/?size={$tamano}x{$tamano}&data=" . urlencode($texto);
    
    // Descargar la imagen
    $imagen_qr = @file_get_contents($url_api);
    
    if ($imagen_qr === false) {
        // Fallback: usar Google Charts API
        $url_api = "https://chart.googleapis.com/chart?chs={$tamano}x{$tamano}&cht=qr&chl=" . urlencode($texto);
        $imagen_qr = @file_get_contents($url_api);
        
        if ($imagen_qr === false) {
            return false;
        }
    }
    
    // Guardar el archivo
    $guardado = file_put_contents($ruta_archivo, $imagen_qr);
    
    if ($guardado !== false) {
        return $ruta_archivo;
    }
    
    return false;
}

// Función para obtener la URL del QR (para mostrar en el sistema)
function obtenerUrlQR($codigo_qr) {
    if (empty($codigo_qr)) {
        return '';
    }
    $nombre_archivo = preg_replace('/[^a-zA-Z0-9_-]/', '_', $codigo_qr) . '.png';
    $ruta_completa = 'qrcodes/' . $nombre_archivo;
    
    if (file_exists($ruta_completa)) {
        return $ruta_completa;
    }
    
    return '';
}
?>