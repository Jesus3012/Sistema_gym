-- ============================================================
-- EGO / SISTEMA GIMNASIO
-- Módulo: Apariencia y tema corporativo
-- Fecha: 2026-08-13
-- ============================================================

CREATE TABLE IF NOT EXISTS configuracion_apariencia (
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
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

INSERT INTO configuracion_apariencia (
    id,
    tema,
    color_primario,
    color_acento,
    color_sidebar,
    color_fondo,
    color_superficie,
    color_texto,
    radio_componentes,
    actualizado_por
) VALUES (
    1,
    'ego',
    '#1e3a8a',
    '#2563eb',
    '#0a2540',
    '#f4f6f9',
    '#ffffff',
    '#172033',
    12,
    NULL
)
ON DUPLICATE KEY UPDATE id = id;
