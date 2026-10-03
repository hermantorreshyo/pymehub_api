<?php
declare(strict_types=1);

/**
 * Aplica migraciones pendientes (RF-096).
 * Uso: php cli/migrate.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Core\Migrator;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada. Ejecuta primero el asistente /install.\n");
    exit(1);
}
try {
    $applied = (new Migrator(Db::pdo()))->migrate();
    echo $applied === [] ? "Sin migraciones pendientes.\n" : 'Aplicadas: ' . implode(', ', $applied) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error en la migración: ' . $e->getMessage() . "\nRestaura la copia previa antes de reintentar.\n");
    exit(1);
}
