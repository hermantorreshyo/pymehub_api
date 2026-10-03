<?php
declare(strict_types=1);

/**
 * Arranque común de la API (web y CLI).
 * Sin Composer: autocarga propia para el espacio de nombres PymeHub\.
 */

define('PH_ROOT', dirname(__DIR__));
define('PH_VERSION', '0.1.0');

spl_autoload_register(static function (string $class): void {
    $prefix = 'PymeHub\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = PH_ROOT . '/src/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
