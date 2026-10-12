<?php
declare(strict_types=1);

/**
 * Alertas de bienestar de equipo (RF-054). Programar los lunes a las 06:00 (§17.2):
 *   0 6 * * 1  www-data  php /var/www/pymehub-api/cli/wellbeing_alerts.php
 * Evalúa la última semana completa frente a las 4 anteriores en todas las empresas
 * activas o en piloto. Uso manual: php cli/wellbeing_alerts.php [--empresa=ID]
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Core\Logger;
use PymeHub\Services\BienestarService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}
$opts = getopt('', ['empresa::']);
$params = [];
$sql = "SELECT id, nombre FROM interlocutor WHERE tipo = 'EMPRESA' AND estado IN ('PILOTO','ACTIVO')";
if (!empty($opts['empresa'])) {
    $sql .= ' AND id = ?';
    $params[] = (int) $opts['empresa'];
}
$total = 0;
foreach (Db::all($sql, $params) as $empresa) {
    try {
        $n = BienestarService::evaluarAlertas((int) $empresa['id']);
        $total += $n;
        echo sprintf("%-40s %d alerta(s)\n", mb_substr($empresa['nombre'], 0, 40), $n);
    } catch (Throwable $e) {
        Logger::error('Error al evaluar alertas de bienestar', ['interlocutor_id' => (int) $empresa['id'], 'error' => $e->getMessage()]);
        fwrite(STDERR, "Error en la empresa {$empresa['id']}: revisa el log.\n");
    }
}
echo "Alertas de bienestar creadas: $total\n";
