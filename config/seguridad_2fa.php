<?php
// Archivo: config/seguridad_2fa.php
// IMPORTANTE: no publiques este archivo ni lo subas al repositorio.
// La clave protege los secretos TOTP almacenados en la base de datos.

declare(strict_types=1);

return [
    'encryption_key_base64' => 'gfUD8KBiN13ouAPir5gASDTCY4xsXtEiOTgZ2e4t8cI=',
    'cookie_name' => 'gym_2fa_trusted',
];