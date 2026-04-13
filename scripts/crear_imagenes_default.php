<?php
// scripts/crear_imagenes_fontawesome.php

// Asegurar que se ejecute desde línea de comandos
if (php_sapi_name() !== 'cli') {
    die("Este script solo puede ejecutarse desde la línea de comandos.\n");
}

$defaults_dir = __DIR__ . '/../uploads/productos/defaults/';
if (!file_exists($defaults_dir)) {
    mkdir($defaults_dir, 0777, true);
}

// Buscar la fuente Font Awesome en diferentes ubicaciones
$raiz_proyecto = __DIR__ . '/../';
$fuentes_posibles = [
    // Ubicaciones con la carpeta fontawesome-free-7.2.0-desktop
    $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/otfs/Font Awesome 6 Free-Solid-900.otf',
    $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/otfs/Font Awesome 6 Free-Solid-900.otf',
    $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/otfs/Font Awesome 6 Free-Regular-400.otf',
    $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/ttf/Font Awesome 6 Free-Solid-900.ttf',
    $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/ttf/Font Awesome 6 Free-Regular-400.ttf',
    
    // Buscar cualquier archivo .otf o .ttf dentro de la carpeta
    $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/otfs/*.otf',
    $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/ttf/*.ttf',
    
    // Otras ubicaciones posibles
    $raiz_proyecto . 'fonts/Font Awesome 6 Free-Solid-900.otf',
    $raiz_proyecto . 'fonts/Font Awesome 6 Free-Solid-900.ttf',
    $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/otfs/FontAwesome6Free-Solid-900.otf',
    $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/otfs/FontAwesome6Free-Solid-900.otf',
];

// Buscar archivos .otf o .ttf en la carpeta de Font Awesome
$carpeta_fa = $raiz_proyecto . 'fontawesome-free-7.2.0-desktop/';
if (is_dir($carpeta_fa)) {
    // Buscar recursivamente archivos de fuente
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($carpeta_fa));
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $extension = strtolower($file->getExtension());
            if ($extension === 'otf' || $extension === 'ttf') {
                $fuentes_posibles[] = $file->getPathname();
            }
        }
    }
}

$fuente_fa = null;
echo " Buscando fuentes de Font Awesome...\n\n";

foreach ($fuentes_posibles as $fuente) {
    if (file_exists($fuente) && (strpos($fuente, '.otf') !== false || strpos($fuente, '.ttf') !== false)) {
        $fuente_fa = $fuente;
        echo " Fuente encontrada: " . $fuente . "\n";
        break;
    }
}

if (!$fuente_fa) {
    echo "No se encontró la fuente de Font Awesome.\n";
    echo "Ubicaciones buscadas:\n";
    foreach ($fuentes_posibles as $fuente) {
        if (strpos($fuente, '*') === false) {
            echo "   - " . $fuente . "\n";
        }
    }
    echo "\n Soluciones:\n";
    echo "   1. Verifica que la carpeta se llame exactamente 'fontawesome-free-7.2.0-desktop'\n";
    echo "   2. Verifica que esté en la raíz del proyecto: " . $raiz_proyecto . "\n";
    echo "   3. Busca archivos .otf o .ttf dentro de la carpeta\n";
    echo "   4. Ejecuta: ls -la " . $raiz_proyecto . "fontawesome-free-7.2.0-desktop/otfs/\n";
    exit(1);
}

echo "\n🎨 Generando imágenes con iconos de Font Awesome...\n\n";

// Función para crear imagen con icono de Font Awesome
function crearImagenConIcono($nombre_archivo, $texto, $icono_unicode, $color_fondo, $color_icono) {
    global $defaults_dir, $fuente_fa;
    
    $ancho = 300;
    $alto = 300;
    
    // Crear imagen
    $img = imagecreatetruecolor($ancho, $alto);
    
    // Habilitar antialiasing
    imageantialias($img, true);
    
    // Convertir hex a RGB
    $fondo_rgb = sscanf($color_fondo, "%02x%02x%02x");
    $icono_rgb = sscanf($color_icono, "%02x%02x%02x");
    
    // Colores
    $fondo = imagecolorallocate($img, $fondo_rgb[0], $fondo_rgb[1], $fondo_rgb[2]);
    $icono_color = imagecolorallocate($img, $icono_rgb[0], $icono_rgb[1], $icono_rgb[2]);
    $blanco = imagecolorallocate($img, 255, 255, 255);
    $gris_claro = imagecolorallocate($img, 230, 230, 230);
    
    // Llenar fondo
    imagefill($img, 0, 0, $fondo);
    
    // Crear degradado
    for ($i = 0; $i < $alto; $i++) {
        $ratio = $i / $alto;
        $r = $fondo_rgb[0] - ($fondo_rgb[0] * $ratio * 0.3);
        $g = $fondo_rgb[1] - ($fondo_rgb[1] * $ratio * 0.3);
        $b = $fondo_rgb[2] - ($fondo_rgb[2] * $ratio * 0.3);
        $r = max(0, min(255, $r));
        $g = max(0, min(255, $g));
        $b = max(0, min(255, $b));
        $color_grad = imagecolorallocate($img, $r, $g, $b);
        imageline($img, 0, $i, $ancho, $i, $color_grad);
    }
    
    // Dibujar un círculo de fondo para el icono
    $circulo_color = imagecolorallocatealpha($img, 255, 255, 255, 60);
    imagefilledellipse($img, $ancho/2, $alto/2 - 20, 160, 160, $circulo_color);
    imageellipse($img, $ancho/2, $alto/2 - 20, 160, 160, $blanco);
    
    // Dibujar un círculo interior
    $circulo_interno = imagecolorallocatealpha($img, 255, 255, 255, 30);
    imagefilledellipse($img, $ancho/2, $alto/2 - 20, 140, 140, $circulo_interno);
    
    // Dibujar el icono (usando la fuente Font Awesome)
    $tamanio_icono = 70;
    $icono_bbox = imagettfbbox($tamanio_icono, 0, $fuente_fa, $icono_unicode);
    $icono_ancho = $icono_bbox[2] - $icono_bbox[0];
    $icono_x = ($ancho - $icono_ancho) / 2;
    $icono_y = $alto/2 - 10;
    
    // Sombra del icono
    imagettftext($img, $tamanio_icono, 0, $icono_x + 2, $icono_y + 2, $gris_claro, $fuente_fa, $icono_unicode);
    imagettftext($img, $tamanio_icono, 0, $icono_x, $icono_y, $icono_color, $fuente_fa, $icono_unicode);
    
    // Dibujar texto debajo del icono
    $tamanio_texto = 18;
    $texto_bbox = imagettfbbox($tamanio_texto, 0, $fuente_fa, $texto);
    $texto_ancho = $texto_bbox[2] - $texto_bbox[0];
    $texto_x = ($ancho - $texto_ancho) / 2;
    $texto_y = $alto - 50;
    
    // Sombra del texto
    imagettftext($img, $tamanio_texto, 0, $texto_x + 1, $texto_y + 1, $gris_claro, $fuente_fa, $texto);
    imagettftext($img, $tamanio_texto, 0, $texto_x, $texto_y, $blanco, $fuente_fa, $texto);
    
    // Guardar imagen
    imagepng($img, $defaults_dir . $nombre_archivo);
    imagedestroy($img);
    
    return true;
}

// Mapeo de iconos Font Awesome (códigos Unicode)
$iconos_fa = [
    'producto_generico.png' => ['texto' => 'Producto', 'icono' => "\u{f07a}", 'color_fondo' => '667eea', 'color_icono' => 'ffffff'],
    'agua.png' => ['texto' => 'Agua', 'icono' => "\u{f043}", 'color_fondo' => '2193b0', 'color_icono' => 'ffffff'],
    'gatorade.png' => ['texto' => 'Gatorade', 'icono' => "\u{f0f4}", 'color_fondo' => 'f12711', 'color_icono' => 'ffffff'],
    'electrolit.png' => ['texto' => 'Electrolit', 'icono' => "\u{f0e0}", 'color_fondo' => '1a2980', 'color_icono' => 'ffffff'],
    'powerade.png' => ['texto' => 'Powerade', 'icono' => "\u{f0f4}", 'color_fondo' => 'e53935', 'color_icono' => 'ffffff'],
    'monster.png' => ['texto' => 'Monster', 'icono' => "\u{f005}", 'color_fondo' => '000000', 'color_icono' => 'ffcc00'],
    'redbull.png' => ['texto' => 'Red Bull', 'icono' => "\u{f06d}", 'color_fondo' => 'dc143c', 'color_icono' => 'ffffff'],
    'proteina.png' => ['texto' => 'Proteina', 'icono' => "\u{f4f5}", 'color_fondo' => '4b6cb7', 'color_icono' => 'ffffff'],
    'creatina.png' => ['texto' => 'Creatina', 'icono' => "\u{f4fe}", 'color_fondo' => 'e96443', 'color_icono' => 'ffffff'],
    'bcaa.png' => ['texto' => 'BCAA', 'icono' => "\u{f005}", 'color_fondo' => '00b4db', 'color_icono' => 'ffffff'],
    'aminoacidos.png' => ['texto' => 'Aminoacidos', 'icono' => "\u{f500}", 'color_fondo' => '36d1dc', 'color_icono' => 'ffffff'],
    'glutamina.png' => ['texto' => 'Glutamina', 'icono' => "\u{f4fe}", 'color_fondo' => '8e2de2', 'color_icono' => 'ffffff'],
    'pre_entreno.png' => ['texto' => 'Pre-Entreno', 'icono' => "\u{f46d}", 'color_fondo' => 'ff416c', 'color_icono' => 'ffffff'],
    'playera.png' => ['texto' => 'Playera', 'icono' => "\u{f453}", 'color_fondo' => '2c3e50', 'color_icono' => 'ffffff'],
    'pants.png' => ['texto' => 'Pants', 'icono' => "\u{f45b}", 'color_fondo' => '232526', 'color_icono' => 'ffffff'],
    'short.png' => ['texto' => 'Short', 'icono' => "\u{f45d}", 'color_fondo' => '1f4037', 'color_icono' => 'ffffff'],
    'tenis.png' => ['texto' => 'Tenis', 'icono' => "\u{f460}", 'color_fondo' => 'c31432', 'color_icono' => 'ffffff'],
    'guantes.png' => ['texto' => 'Guantes', 'icono' => "\u{f45c}", 'color_fondo' => '232526', 'color_icono' => 'ffffff'],
    'cuerda.png' => ['texto' => 'Cuerda', 'icono' => "\u{f0c1}", 'color_fondo' => '757f9a', 'color_icono' => 'ffffff'],
    'toalla.png' => ['texto' => 'Toalla', 'icono' => "\u{f2dc}", 'color_fondo' => '606c88', 'color_icono' => 'ffffff'],
    'botella.png' => ['texto' => 'Botella', 'icono' => "\u{f0e0}", 'color_fondo' => '11998e', 'color_icono' => 'ffffff'],
    'shaker.png' => ['texto' => 'Shaker', 'icono' => "\u{f0f4}", 'color_fondo' => 'ff7e5f', 'color_icono' => 'ffffff'],
    'barra_energetica.png' => ['texto' => 'Barra', 'icono' => "\u{f4ff}", 'color_fondo' => 'f2994a', 'color_icono' => 'ffffff'],
    'barra_proteica.png' => ['texto' => 'Barra', 'icono' => "\u{f4ff}", 'color_fondo' => '8ba6a5', 'color_icono' => 'ffffff'],
    'suplemento_generico.png' => ['texto' => 'Suplemento', 'icono' => "\u{f0a3}", 'color_fondo' => '536976', 'color_icono' => 'ffffff'],
    'ropa_generica.png' => ['texto' => 'Ropa', 'icono' => "\u{f453}", 'color_fondo' => '4facfe', 'color_icono' => 'ffffff'],
    'accesorio_generico.png' => ['texto' => 'Accesorio', 'icono' => "\u{f0b1}", 'color_fondo' => 'ff9a9e', 'color_icono' => 'ffffff'],
    'bebida_generica.png' => ['texto' => 'Bebida', 'icono' => "\u{f0f4}", 'color_fondo' => '3a6186', 'color_icono' => 'ffffff'],
    'alimento_generico.png' => ['texto' => 'Alimento', 'icono' => "\u{f2e7}", 'color_fondo' => '56ab2f', 'color_icono' => 'ffffff']
];

$contador = 0;
foreach ($iconos_fa as $archivo => $datos) {
    $ruta_completa = $defaults_dir . $archivo;
    if (!file_exists($ruta_completa)) {
        if (crearImagenConIcono($archivo, $datos['texto'], $datos['icono'], $datos['color_fondo'], $datos['color_icono'])) {
            echo "Creada: " . $archivo . "\n";
            $contador++;
        } else {
            echo "Error al crear: " . $archivo . "\n";
        }
    } else {
        echo "⏭Ya existe: " . $archivo . "\n";
    }
}

echo "\n✨ ¡$contador imágenes generadas correctamente!\n";
echo "📁 Ubicación: " . $defaults_dir . "\n";
?>