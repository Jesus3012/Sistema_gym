<?php
declare(strict_types=1);

/**
 * Tema visual corporativo del sistema.
 *
 * La configuración es global para toda la instalación. Los módulos siguen
 * conservando sus colores semánticos (éxito, advertencia, error), mientras
 * que la identidad principal, fondos, superficies y sidebar se centralizan.
 */

if (!function_exists('tema_sistema_defaults')) {
    function tema_sistema_defaults(): array
    {
        return [
            'id' => 1,
            'tema' => 'ego',
            'color_primario' => '#1e3a8a',
            'color_acento' => '#2563eb',
            'color_sidebar' => '#0a2540',
            'color_fondo' => '#f4f6f9',
            'color_superficie' => '#ffffff',
            'color_texto' => '#172033',
            'radio_componentes' => 12,
            'actualizado_por' => null,
            'actualizado_en' => null,
        ];
    }
}

if (!function_exists('tema_sistema_hex_valido')) {
    function tema_sistema_hex_valido(string $color): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', trim($color)) === 1;
    }
}

if (!function_exists('tema_sistema_normalizar_hex')) {
    function tema_sistema_normalizar_hex(string $color, string $fallback): string
    {
        $color = strtolower(trim($color));
        return tema_sistema_hex_valido($color) ? $color : strtolower($fallback);
    }
}

if (!function_exists('tema_sistema_rgb')) {
    function tema_sistema_rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}

if (!function_exists('tema_sistema_mezclar')) {
    function tema_sistema_mezclar(string $color, string $destino, float $peso): string
    {
        $peso = max(0.0, min(1.0, $peso));
        [$r1, $g1, $b1] = tema_sistema_rgb($color);
        [$r2, $g2, $b2] = tema_sistema_rgb($destino);

        $r = (int) round($r1 + (($r2 - $r1) * $peso));
        $g = (int) round($g1 + (($g2 - $g1) * $peso));
        $b = (int) round($b1 + (($b2 - $b1) * $peso));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }
}

if (!function_exists('tema_sistema_contraste')) {
    function tema_sistema_contraste(string $color): string
    {
        [$r, $g, $b] = tema_sistema_rgb($color);
        $luminancia = (($r * 299) + ($g * 587) + ($b * 114)) / 1000;
        return $luminancia >= 160 ? '#172033' : '#ffffff';
    }
}

if (!function_exists('tema_sistema_tabla_existe')) {
    function tema_sistema_tabla_existe(mysqli $db): bool
    {
        try {
            $resultado = $db->query("SHOW TABLES LIKE 'configuracion_apariencia'");
            return $resultado instanceof mysqli_result && $resultado->num_rows > 0;
        } catch (Throwable $error) {
            return false;
        }
    }
}

if (!function_exists('tema_sistema_asegurar_tabla')) {
    function tema_sistema_asegurar_tabla(mysqli $db): void
    {
        $db->query(
            "CREATE TABLE IF NOT EXISTS configuracion_apariencia (
                id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
                tema VARCHAR(32) NOT NULL DEFAULT 'ego',
                color_primario CHAR(7) NOT NULL DEFAULT '#1e3a8a',
                color_acento CHAR(7) NOT NULL DEFAULT '#2563eb',
                color_sidebar CHAR(7) NOT NULL DEFAULT '#0a2540',
                color_fondo CHAR(7) NOT NULL DEFAULT '#f4f6f9',
                color_superficie CHAR(7) NOT NULL DEFAULT '#ffffff',
                color_texto CHAR(7) NOT NULL DEFAULT '#172033',
                radio_componentes TINYINT UNSIGNED NOT NULL DEFAULT 12,
                actualizado_por INT NULL,
                actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $defaults = tema_sistema_defaults();
        $temaDefault = (string) $defaults['tema'];
        $primarioDefault = (string) $defaults['color_primario'];
        $acentoDefault = (string) $defaults['color_acento'];
        $sidebarDefault = (string) $defaults['color_sidebar'];
        $fondoDefault = (string) $defaults['color_fondo'];
        $superficieDefault = (string) $defaults['color_superficie'];
        $textoDefault = (string) $defaults['color_texto'];
        $radioDefault = (int) $defaults['radio_componentes'];

        $stmt = $db->prepare(
            "INSERT IGNORE INTO configuracion_apariencia (
                id, tema, color_primario, color_acento, color_sidebar,
                color_fondo, color_superficie, color_texto, radio_componentes
             ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->bind_param(
            'sssssssi',
            $temaDefault,
            $primarioDefault,
            $acentoDefault,
            $sidebarDefault,
            $fondoDefault,
            $superficieDefault,
            $textoDefault,
            $radioDefault
        );
        $stmt->execute();
        $stmt->close();
    }
}

if (!function_exists('tema_sistema_obtener')) {
    function tema_sistema_obtener(mysqli $db, bool $asegurar = false): array
    {
        $defaults = tema_sistema_defaults();

        try {
            if ($asegurar) {
                tema_sistema_asegurar_tabla($db);
            } elseif (!tema_sistema_tabla_existe($db)) {
                return $defaults;
            }

            $resultado = $db->query(
                "SELECT * FROM configuracion_apariencia WHERE id = 1 LIMIT 1"
            );
            $fila = $resultado instanceof mysqli_result
                ? $resultado->fetch_assoc()
                : null;

            if (!$fila) {
                return $defaults;
            }

            $config = array_merge($defaults, $fila);
            foreach (
                ['color_primario','color_acento','color_sidebar','color_fondo','color_superficie','color_texto']
                as $campo
            ) {
                $config[$campo] = tema_sistema_normalizar_hex(
                    (string) ($config[$campo] ?? ''),
                    (string) $defaults[$campo]
                );
            }
            $config['radio_componentes'] = max(
                6,
                min(22, (int) ($config['radio_componentes'] ?? 12))
            );

            return $config;
        } catch (Throwable $error) {
            error_log('[Tema sistema] ' . $error->getMessage());
            return $defaults;
        }
    }
}

if (!function_exists('tema_sistema_guardar')) {
    function tema_sistema_guardar(mysqli $db, array $datos, int $usuarioId): array
    {
        tema_sistema_asegurar_tabla($db);
        $defaults = tema_sistema_defaults();

        $tema = preg_replace('/[^a-z0-9_-]/i', '', (string) ($datos['tema'] ?? 'personalizado'));
        if ($tema === '') {
            $tema = 'personalizado';
        }

        $primario = tema_sistema_normalizar_hex((string) ($datos['color_primario'] ?? ''), $defaults['color_primario']);
        $acento = tema_sistema_normalizar_hex((string) ($datos['color_acento'] ?? ''), $defaults['color_acento']);
        $sidebar = tema_sistema_normalizar_hex((string) ($datos['color_sidebar'] ?? ''), $defaults['color_sidebar']);
        $fondo = tema_sistema_normalizar_hex((string) ($datos['color_fondo'] ?? ''), $defaults['color_fondo']);
        $superficie = tema_sistema_normalizar_hex((string) ($datos['color_superficie'] ?? ''), $defaults['color_superficie']);
        $texto = tema_sistema_normalizar_hex((string) ($datos['color_texto'] ?? ''), $defaults['color_texto']);
        $radio = max(6, min(22, (int) ($datos['radio_componentes'] ?? 12)));

        $stmt = $db->prepare(
            "INSERT INTO configuracion_apariencia (
                id, tema, color_primario, color_acento, color_sidebar,
                color_fondo, color_superficie, color_texto,
                radio_componentes, actualizado_por
             ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                tema = VALUES(tema),
                color_primario = VALUES(color_primario),
                color_acento = VALUES(color_acento),
                color_sidebar = VALUES(color_sidebar),
                color_fondo = VALUES(color_fondo),
                color_superficie = VALUES(color_superficie),
                color_texto = VALUES(color_texto),
                radio_componentes = VALUES(radio_componentes),
                actualizado_por = VALUES(actualizado_por)"
        );
        $stmt->bind_param(
            'sssssssii',
            $tema,
            $primario,
            $acento,
            $sidebar,
            $fondo,
            $superficie,
            $texto,
            $radio,
            $usuarioId
        );
        $stmt->execute();
        $stmt->close();

        return tema_sistema_obtener($db, true);
    }
}

if (!function_exists('tema_sistema_restaurar')) {
    function tema_sistema_restaurar(mysqli $db, int $usuarioId): array
    {
        $defaults = tema_sistema_defaults();
        return tema_sistema_guardar($db, $defaults, $usuarioId);
    }
}

if (!function_exists('tema_sistema_css')) {
    function tema_sistema_css(array $config): string
    {
        $defaults = tema_sistema_defaults();
        $primario = tema_sistema_normalizar_hex((string) ($config['color_primario'] ?? ''), $defaults['color_primario']);
        $acento = tema_sistema_normalizar_hex((string) ($config['color_acento'] ?? ''), $defaults['color_acento']);
        $sidebar = tema_sistema_normalizar_hex((string) ($config['color_sidebar'] ?? ''), $defaults['color_sidebar']);
        $fondo = tema_sistema_normalizar_hex((string) ($config['color_fondo'] ?? ''), $defaults['color_fondo']);
        $superficie = tema_sistema_normalizar_hex((string) ($config['color_superficie'] ?? ''), $defaults['color_superficie']);
        $texto = tema_sistema_normalizar_hex((string) ($config['color_texto'] ?? ''), $defaults['color_texto']);
        $radio = max(6, min(22, (int) ($config['radio_componentes'] ?? 12)));

        $primarioOscuro = tema_sistema_mezclar($primario, '#000000', .20);
        $primarioClaro = tema_sistema_mezclar($primario, '#ffffff', .92);
        $acentoOscuro = tema_sistema_mezclar($acento, '#000000', .16);
        $sidebarOscuro = tema_sistema_mezclar($sidebar, '#000000', .17);
        $sidebarHover = tema_sistema_mezclar($sidebar, '#ffffff', .12);
        $sidebarActivo = tema_sistema_mezclar($sidebar, $acento, .34);
        $sidebarTexto = tema_sistema_contraste($sidebar);
        $muted = tema_sistema_mezclar($texto, '#ffffff', .42);
        $borde = tema_sistema_mezclar($texto, '#ffffff', .88);
        $surfaceSoft = tema_sistema_mezclar($superficie, $fondo, .45);
        $primaryText = tema_sistema_contraste($primario);

        return <<<CSS
:root {
    --sys-primary: {$primario};
    --sys-primary-dark: {$primarioOscuro};
    --sys-primary-soft: {$primarioClaro};
    --sys-primary-text: {$primaryText};
    --sys-accent: {$acento};
    --sys-accent-dark: {$acentoOscuro};
    --sys-sidebar: {$sidebar};
    --sys-sidebar-dark: {$sidebarOscuro};
    --sys-sidebar-hover: {$sidebarHover};
    --sys-sidebar-active: {$sidebarActivo};
    --sys-sidebar-text: {$sidebarTexto};
    --sys-bg: {$fondo};
    --sys-surface: {$superficie};
    --sys-surface-soft: {$surfaceSoft};
    --sys-text: {$texto};
    --sys-muted: {$muted};
    --sys-border: {$borde};
    --sys-radius: {$radio}px;

    --sidebar-bg: var(--sys-sidebar);
    --sidebar-dark: var(--sys-sidebar-dark);
    --sidebar-hover: var(--sys-sidebar-hover);
    --sidebar-active: var(--sys-sidebar-active);
    --sidebar-text: var(--sys-sidebar-text);
    --sidebar-text-light: color-mix(in srgb, var(--sys-sidebar-text) 80%, transparent);
    --sidebar-accent: var(--sys-accent);

    --azul: var(--sys-primary);
    --azul-hover: var(--sys-primary-dark);
    --azul-oscuro: var(--sys-primary-dark);
    --azul-suave: var(--sys-primary-soft);
    --azul-2: var(--sys-accent);
    --fondo: var(--sys-bg);
    --blanco: var(--sys-surface);
    --superficie: var(--sys-surface);
    --superficie-2: var(--sys-surface-soft);
    --texto: var(--sys-text);
    --texto-suave: var(--sys-muted);
    --suave: var(--sys-muted);
    --borde: var(--sys-border);

    --brand: var(--sys-primary);
    --brand-dark: var(--sys-primary-dark);
    --brand-soft: var(--sys-primary-soft);
    --navy: var(--sys-sidebar);
    --page-bg: var(--sys-bg);
    --surface: var(--sys-surface);
    --surface-soft: var(--sys-surface-soft);
    --text: var(--sys-text);
    --muted: var(--sys-muted);
    --border: var(--sys-border);
    --info: var(--sys-accent);
    --info-dark: var(--sys-accent-dark);
    --radius: var(--sys-radius);
    --radio: var(--sys-radius);
    --radio-grande: calc(var(--sys-radius) + 14px);
    --radio-md: var(--sys-radius);
    --radio-lg: calc(var(--sys-radius) + 4px);
    --radio-xl: calc(var(--sys-radius) + 10px);

    --dash-blue: var(--sys-primary);
    --dash-blue-2: var(--sys-accent);
    --dash-blue-soft: var(--sys-primary-soft);
    --dash-bg: var(--sys-bg);
    --dash-surface: var(--sys-surface);
    --dash-text: var(--sys-text);
    --dash-muted: var(--sys-muted);
    --dash-border: var(--sys-border);

    --inventory-blue: var(--sys-primary);
    --inventory-blue-hover: var(--sys-primary-dark);
    --inventory-blue-soft: var(--sys-primary-soft);
    --inventory-bg: var(--sys-bg);
    --inventory-card: var(--sys-surface);
    --inventory-text: var(--sys-text);
    --inventory-muted: var(--sys-muted);
    --inventory-border: var(--sys-border);

    --plans-blue: var(--sys-primary);
    --plans-blue-dark: var(--sys-primary-dark);
    --plans-blue-soft: var(--sys-primary-soft);
    --plans-bg: var(--sys-bg);
    --plans-card: var(--sys-surface);
    --plans-text: var(--sys-text);
    --plans-muted: var(--sys-muted);
    --plans-border: var(--sys-border);

    --socios-primary: var(--sys-primary);
    --socios-primary-hover: var(--sys-primary-dark);
    --socios-bg: var(--sys-bg);
    --socios-card: var(--sys-surface);
    --socios-text: var(--sys-text);
    --socios-muted: var(--sys-muted);
    --socios-border: var(--sys-border);

    --trainer-blue: var(--sys-primary);
    --trainer-blue-dark: var(--sys-primary-dark);
    --trainer-blue-soft: var(--sys-primary-soft);
    --trainer-bg: var(--sys-bg);
    --trainer-card: var(--sys-surface);
    --trainer-text: var(--sys-text);
    --trainer-muted: var(--sys-muted);
    --trainer-border: var(--sys-border);

    --health-navy: var(--sys-sidebar);
    --health-blue: var(--sys-primary);
    --health-blue-soft: var(--sys-primary-soft);
    --health-border: var(--sys-border);
    --health-muted: var(--sys-muted);
    --health-bg: var(--sys-bg);
    --health-card: var(--sys-surface);

    --profile-bg: var(--sys-bg);
    --profile-card: var(--sys-surface);
    --profile-text: var(--sys-text);
    --profile-muted: var(--sys-muted);
    --profile-border: var(--sys-border);
    --profile-primary: var(--sys-primary);
    --profile-primary-hover: var(--sys-primary-dark);
    --profile-soft: var(--sys-primary-soft);
    --profile-radius: var(--sys-radius);

    --report-primary: var(--sys-sidebar);
    --report-primary-soft: var(--sys-primary-soft);
    --report-blue: var(--sys-primary);
    --report-blue-dark: var(--sys-primary-dark);
    --report-text: var(--sys-text);
    --report-muted: var(--sys-muted);
    --report-border: var(--sys-border);
    --report-bg: var(--sys-bg);
    --report-card: var(--sys-surface);

    --service-primary: var(--sys-primary);
    --service-primary-dark: var(--sys-primary-dark);
    --service-primary-soft: var(--sys-primary-soft);
    --service-bg: var(--sys-bg);
    --service-surface: var(--sys-surface);
    --service-text: var(--sys-text);
    --service-heading: var(--sys-text);
    --service-muted: var(--sys-muted);
    --service-border: var(--sys-border);
    --service-border-strong: var(--sys-border);
    --service-radius: var(--sys-radius);

    --tf-primary: var(--sys-primary);
    --tf-primary-dark: var(--sys-primary-dark);
    --tf-soft: var(--sys-primary-soft);
    --tf-bg: var(--sys-bg);
    --tf-text: var(--sys-text);
    --tf-muted: var(--sys-muted);
    --tf-border: var(--sys-border);

    --caja-azul: var(--sys-primary);
    --caja-azul-oscuro: var(--sys-primary-dark);
    --caja-azul-suave: var(--sys-primary-soft);
    --caja-fondo: var(--sys-bg);
    --caja-blanco: var(--sys-surface);
    --caja-texto: var(--sys-text);
    --caja-suave: var(--sys-muted);
    --caja-borde: var(--sys-border);

    --ventas-azul: var(--sys-primary);
    --ventas-azul-oscuro: var(--sys-primary-dark);
    --ventas-azul-suave: var(--sys-primary-soft);
    --ventas-fondo: var(--sys-bg);
    --ventas-blanco: var(--sys-surface);
    --ventas-texto: var(--sys-text);
    --ventas-suave: var(--sys-muted);
    --ventas-borde: var(--sys-border);

    --notif-azul: var(--sys-primary);
    --notif-azul-hover: var(--sys-primary-dark);
    --notif-fondo: var(--sys-bg);
    --notif-blanco: var(--sys-surface);
    --notif-texto: var(--sys-text);
    --notif-suave: var(--sys-muted);
    --notif-borde: var(--sys-border);
}

body.configuracion-page {
    --cfg-primary: var(--sys-sidebar);
    --cfg-blue: var(--sys-primary);
    --cfg-blue-dark: var(--sys-primary-dark);
    --cfg-blue-soft: var(--sys-primary-soft);
    --cfg-text: var(--sys-text);
    --cfg-muted: var(--sys-muted);
    --cfg-border: var(--sys-border);
    --cfg-border-soft: var(--sys-border);
    --cfg-bg: var(--sys-bg);
    --cfg-card: var(--sys-surface);
}

html, body { background-color: var(--sys-bg); color: var(--sys-text); }

.btn-primary,
.btn-custom-primary,
.page-primary-action,
.swal2-confirm.swal2-styled {
    background-color: var(--sys-primary) !important;
    border-color: var(--sys-primary) !important;
    color: var(--sys-primary-text) !important;
}
.btn-primary:hover,
.btn-primary:focus,
.btn-custom-primary:hover,
.page-primary-action:hover {
    background-color: var(--sys-primary-dark) !important;
    border-color: var(--sys-primary-dark) !important;
}
.text-primary { color: var(--sys-primary) !important; }
.bg-primary { background-color: var(--sys-primary) !important; }
.border-primary { border-color: var(--sys-primary) !important; }
.page-item.active .page-link,
.pagination .active .page-link {
    background-color: var(--sys-primary) !important;
    border-color: var(--sys-primary) !important;
}
.form-control:focus,
.form-select:focus,
.custom-select:focus {
    border-color: var(--sys-accent) !important;
    box-shadow: 0 0 0 .2rem color-mix(in srgb, var(--sys-accent) 18%, transparent) !important;
}
.modal-header-brand {
    background: linear-gradient(135deg, var(--sys-primary), var(--sys-primary-dark)) !important;
}
.card-custom,
.card,
.modal-content {
    border-radius: var(--sys-radius);
}

.sidebar,
#sidebar,
aside.sidebar {
    background: var(--sys-sidebar) !important;
    color: var(--sys-sidebar-text) !important;
    scrollbar-color: #8c959d var(--sys-sidebar) !important;
}
.sidebar::-webkit-scrollbar-track,
#sidebar::-webkit-scrollbar-track,
aside.sidebar::-webkit-scrollbar-track,
.sidebar *::-webkit-scrollbar-track {
    background: var(--sys-sidebar) !important;
}
CSS;
    }
}
