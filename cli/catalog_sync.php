<?php
declare(strict_types=1);

/**
 * Sincroniza el catálogo global de micro-módulos (RF-040) desde data/catalogo_formativo.php.
 * Idempotente: crea los que faltan y actualiza (subiendo la versión) los que han cambiado.
 * Lo ejecutan también migrate.php, el instalador y demo_seed.php.
 * Uso: php cli/catalog_sync.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Services\CatalogoService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada. Ejecuta primero el asistente /install.\n");
    exit(1);
}
try {
    $r = CatalogoService::sincronizar();
    echo "Catálogo de formación: {$r['total']} módulos ({$r['creados']} nuevos, {$r['actualizados']} actualizados).\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error al sincronizar el catálogo: ' . $e->getMessage() . "\n");
    exit(1);
}
