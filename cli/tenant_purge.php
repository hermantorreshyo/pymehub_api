<?php
declare(strict_types=1);

/**
 * Borrado definitivo de las empresas dadas de baja (LEG-017, RF-111). Programar a diario (§17.2):
 *   30 4 * * *  www-data  php /var/www/hermantorres/eude/pymehub/pymehub_api/cli/tenant_purge.php
 * Borra las empresas en estado BAJA cuya baja tiene más de operacion.dias_borrado_tras_baja días
 * (30 por defecto), con todas sus personas y datos. La auditoría se conserva anonimizada.
 * Uso manual: php cli/tenant_purge.php [--simular]
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Core\Logger;
use PymeHub\Services\BorradoService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}
$simular = isset(getopt('', ['simular'])['simular']);
$dias = BorradoService::diasTrasBaja();
$empresas = Db::all(
    "SELECT id, nombre, fecha_baja FROM interlocutor
      WHERE tipo = 'EMPRESA' AND estado = 'BAJA' AND fecha_baja IS NOT NULL AND fecha_baja < UTC_TIMESTAMP() - INTERVAL ? DAY",
    [$dias]
);
foreach ($empresas as $e) {
    echo sprintf("%s %-40s baja %s\n", $simular ? '[simulación]' : 'Borrada', mb_substr($e['nombre'], 0, 40), $e['fecha_baja']);
    if (!$simular) {
        $r = BorradoService::borrar([(int) $e['id']]);
        Logger::info('Empresa borrada tras la baja', ['interlocutor_id' => (int) $e['id'], 'usuarios' => $r['usuarios']]);
        Db::run("INSERT INTO registro_auditoria (accion, entidad) VALUES ('empresa.purgada', 'interlocutor')");
    }
}
echo 'Empresas ' . ($simular ? 'que se borrarían' : 'borradas') . " (baja de más de $dias días): " . count($empresas) . "\n";
