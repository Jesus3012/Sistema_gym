<?php
// Archivo: config/mercadopago_config.php
// Valores de respaldo y clave usada para cifrar credenciales Point.

declare(strict_types=1);

/*
 * Las credenciales operativas se leen desde mercadopago_terminales.
 * Estas variables solamente funcionan como respaldo durante la migración.
 */
if (!defined('MP_ACCESS_TOKEN')) {
    define('MP_ACCESS_TOKEN', trim((string) (getenv('MP_ACCESS_TOKEN') ?: '')));
}

if (!defined('MP_TERMINAL_ID')) {
    define('MP_TERMINAL_ID', trim((string) (getenv('MP_TERMINAL_ID') ?: '')));
}

if (!defined('MP_PRINT_ON_TERMINAL')) {
    define(
        'MP_PRINT_ON_TERMINAL',
        trim((string) (getenv('MP_PRINT_ON_TERMINAL') ?: 'no_ticket'))
    );
}

if (!defined('MP_ORDER_EXPIRATION')) {
    define(
        'MP_ORDER_EXPIRATION',
        trim((string) (getenv('MP_ORDER_EXPIRATION') ?: 'PT3M'))
    );
}

if (!defined('MP_INSTALLMENTS_COST')) {
    define(
        'MP_INSTALLMENTS_COST',
        trim((string) (getenv('MP_INSTALLMENTS_COST') ?: 'terminal'))
    );
}

/*
 * Clave AES-256-GCM para cifrar Access Tokens.
 *
 * Prioridad:
 * 1. Variable de entorno MP_CREDENTIALS_KEY.
 * 2. Archivo local generado una sola vez:
 *    config/mercadopago_credentials_key.php
 *
 * El archivo local contiene PHP y no debe subirse al repositorio. Debe
 * conservarse en los respaldos del servidor porque sin él no es posible
 * descifrar las credenciales ya almacenadas.
 */
if (!defined('MP_CREDENTIALS_KEY')) {
    $mpCredentialsKey = trim((string) (
        getenv('MP_CREDENTIALS_KEY') ?: ''
    ));

    if ($mpCredentialsKey === '') {
        $mpCredentialsKeyFile =
            __DIR__ . '/mercadopago_credentials_key.php';

        if (is_file($mpCredentialsKeyFile)) {
            $mpLoadedKey = require $mpCredentialsKeyFile;
            $mpCredentialsKey = is_string($mpLoadedKey)
                ? trim($mpLoadedKey)
                : '';
        } else {
            $mpCredentialsKey =
                'base64:' . base64_encode(random_bytes(32));

            $mpKeyContents = "<?php\n"
                . "// Generado automáticamente. No compartir ni regenerar.\n"
                . 'return '
                . var_export($mpCredentialsKey, true)
                . ";\n";

            $mpKeyHandle = @fopen($mpCredentialsKeyFile, 'x');

            if (is_resource($mpKeyHandle)) {
                $mpKeyWritten = fwrite(
                    $mpKeyHandle,
                    $mpKeyContents
                );
                fclose($mpKeyHandle);
                @chmod($mpCredentialsKeyFile, 0600);

                if ($mpKeyWritten === false) {
                    @unlink($mpCredentialsKeyFile);
                    $mpCredentialsKey = '';
                }
            } elseif (is_file($mpCredentialsKeyFile)) {
                /* Otro proceso pudo generarla al mismo tiempo. */
                $mpLoadedKey = require $mpCredentialsKeyFile;
                $mpCredentialsKey = is_string($mpLoadedKey)
                    ? trim($mpLoadedKey)
                    : '';
            } else {
                $mpCredentialsKey = '';
            }
        }
    }

    if ($mpCredentialsKey === '') {
        throw new RuntimeException(
            'No fue posible crear la clave de cifrado de Mercado Pago. '
            . 'Da permiso de escritura a la carpeta config o define '
            . 'MP_CREDENTIALS_KEY como variable de entorno.'
        );
    }

    define('MP_CREDENTIALS_KEY', $mpCredentialsKey);

    unset(
        $mpCredentialsKey,
        $mpCredentialsKeyFile,
        $mpLoadedKey,
        $mpKeyContents,
        $mpKeyHandle,
        $mpKeyWritten
    );
}

