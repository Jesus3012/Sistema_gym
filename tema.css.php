<?php
declare(strict_types=1);

header('Content-Type: text/css; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/tema_sistema.php';

$config = tema_sistema_defaults();

try {
    $database = new Database();
    $dbTema = $database->getConnection();
    if ($dbTema instanceof mysqli) {
        $dbTema->set_charset('utf8mb4');
        $config = tema_sistema_obtener($dbTema, false);
    }
} catch (Throwable $error) {
    error_log('[tema.css.php] ' . $error->getMessage());
}

echo tema_sistema_css($config);
