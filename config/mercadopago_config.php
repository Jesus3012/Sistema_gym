<?php

if (!defined('MP_ACCESS_TOKEN')) {
    define('MP_ACCESS_TOKEN', getenv('MP_ACCESS_TOKEN') ?: 'APP_USR-5864631040986073-031901-7746139b15df1b4cee5583d01e27313b-669932070');
}

if (!defined('MP_TERMINAL_ID')) {
    define('MP_TERMINAL_ID', getenv('MP_TERMINAL_ID') ?: 'NEWLAND_N950__N950NCC804255066');
}

if (!defined('MP_PRINT_ON_TERMINAL')) {
    // Valor documentado en el ejemplo vigente de Point Orders.
    define('MP_PRINT_ON_TERMINAL', getenv('MP_PRINT_ON_TERMINAL') ?: 'no_ticket');
}

if (!defined('MP_ORDER_EXPIRATION')) {
    define('MP_ORDER_EXPIRATION', getenv('MP_ORDER_EXPIRATION') ?: 'PT3M');
}

if (!defined('MP_INSTALLMENTS_COST')) {
    /**
     * Déjalo vacío para que la terminal/cuenta determine el costo de las cuotas.
     * Usa "seller" únicamente cuando tu cuenta tenga configurada la promoción
     * correspondiente y quieras asumir el costo de las mensualidades.
     */
    define('MP_INSTALLMENTS_COST', getenv('MP_INSTALLMENTS_COST') ?: '');
}

