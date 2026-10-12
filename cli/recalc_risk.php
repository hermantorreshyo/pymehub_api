<?php
declare(strict_types=1);

/**
 * Recálculo diario del riesgo de rotación (RF-031, RF-033). Programar a las 03:00 (§17.2):
 *   0 3 * * *  www-data  php /var/www/hermantorres/eude/pymehub/pymehub_api/cli/recalc_risk.php
 * Solo empresas activas o en piloto con plan Completo. Guarda una puntuación por persona y día
 * y crea la alerta de rotación cuando alguien pasa a nivel alto.
 * Uso manual: php cli/recalc_risk.php [--empresa=ID] [--fecha=AAAA-MM-DD] [--sin-alertas]
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Core\Logger;
use PymeHub\Core\Tiempo;
use PymeHub\Services\RiesgoService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}
$opts = getopt('', ['empresa::', 'fecha::', 'sin-alertas']);
$fecha = $opts['fecha'] ?? Tiempo::hoy();
if (!is_string($fecha) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || $fecha > Tiempo::hoy()) {
    fwrite(STDERR, "Fecha no válida (AAAA-MM-DD, no futura).\n");
    exit(1);
}
$params = [];
$sql = "SELECT i.id, i.nombre FROM interlocutor i
          JOIN suscripcion s ON s.interlocutor_id = i.id AND s.estado = 'VIGENTE' AND s.plan = 'COMPLETO'
         WHERE i.tipo = 'EMPRESA' AND i.estado IN ('PILOTO','ACTIVO')";
if (!empty($opts['empresa'])) {
    $sql .= ' AND i.id = ?';
    $params[] = (int) $opts['empresa'];
}
$alertas = !isset($opts['sin-alertas']);
$fallos = 0;
$totales = ['personas' => 0, 'alto' => 0, 'alertas' => 0];
foreach (Db::all($sql, $params) as $empresa) {
    try {
        $r = RiesgoService::recalcular((int) $empresa['id'], $fecha, $alertas);
        foreach ($totales as $k => $_) {
            $totales[$k] += $r[$k];
        }
        echo sprintf("%-40s %3d personas · alto %d · medio %d · bajo %d · %d alerta(s)\n",
            mb_substr($empresa['nombre'], 0, 40), $r['personas'], $r['alto'], $r['medio'], $r['bajo'], $r['alertas']);
    } catch (Throwable $e) {
        $fallos++;
        Logger::error('Error al recalcular el riesgo', ['interlocutor_id' => (int) $empresa['id'], 'error' => $e->getMessage()]);
        fwrite(STDERR, "Error en la empresa {$empresa['id']}: revisa el log.\n");
    }
}
echo "Riesgo recalculado a $fecha (reglas " . RiesgoService::reglas()['version'] . "): {$totales['personas']} personas, {$totales['alto']} en nivel alto, {$totales['alertas']} alerta(s) nueva(s).\n";
exit($fallos > 0 ? 1 : 0);
