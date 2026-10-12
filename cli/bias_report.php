<?php
declare(strict_types=1);

/**
 * Revisión de sesgos del motor de riesgo (LEG-015). Programar cada trimestre (§17.2):
 *   30 4 1 1,4,7,10 *  www-data  php /var/www/hermantorres/eude/pymehub/pymehub_api/cli/bias_report.php
 * Distribución de niveles por equipo, puesto, turno y tipo de contrato en cada empresa con plan
 * Completo. Señala los grupos de 5 o más personas cuyo porcentaje en nivel alto se aparta más de
 * 20 puntos del total, para revisión humana. El resultado se anota en docs/hoja_tecnica_modelo_riesgo.md.
 * Sin nombres: solo recuentos por grupo.
 * Uso: php cli/bias_report.php [--empresa=ID] [--salida=ruta.md]
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Core\Tiempo;
use PymeHub\Services\RiesgoService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}
$opts = getopt('', ['empresa::', 'salida::']);
$params = [];
$sql = "SELECT i.id, i.nombre FROM interlocutor i
          JOIN suscripcion s ON s.interlocutor_id = i.id AND s.estado = 'VIGENTE' AND s.plan = 'COMPLETO'
         WHERE i.tipo = 'EMPRESA' AND i.estado IN ('PILOTO','ACTIVO')";
if (!empty($opts['empresa'])) {
    $sql .= ' AND i.id = ?';
    $params[] = (int) $opts['empresa'];
}
$nombres = ['equipo' => 'Equipo', 'puesto' => 'Puesto', 'turno' => 'Turno', 'tipo_contrato' => 'Tipo de contrato'];
$md = ['# Revisión de sesgos del motor de riesgo (LEG-015)', '', 'Fecha: ' . Tiempo::hoy() . ' · Reglas ' . RiesgoService::reglas()['version'], ''];
$avisos = 0;
foreach (Db::all($sql, $params) as $empresa) {
    $s = RiesgoService::sesgos((int) $empresa['id']);
    $md[] = '## ' . $empresa['nombre'];
    $md[] = '';
    $md[] = "Personas evaluadas: {$s['personas']} · En nivel alto: {$s['pct_alto_total']} %";
    $md[] = '';
    foreach ($s['dimensiones'] as $d) {
        $md[] = '### ' . $nombres[$d['dimension']];
        $md[] = '';
        $md[] = '| Grupo | Personas | Alto | Medio | Bajo | % alto | Diferencia (pp) | Revisión |';
        $md[] = '|---|---:|---:|---:|---:|---:|---:|---|';
        foreach ($d['grupos'] as $g) {
            $estado = $g['muestra_pequena'] ? 'Muestra pequeña (< 5)'
                : ($g['revisar'] ? ($g['esperable'] ? 'Revisar (esperable: es un factor del modelo)' : '**Revisar**') : 'Sin diferencia relevante');
            if ($g['revisar'] && !$g['esperable']) {
                $avisos++;
            }
            $md[] = sprintf('| %s | %d | %d | %d | %d | %s | %s | %s |', str_replace('|', '/', $g['grupo']), $g['personas'], $g['alto'], $g['medio'], $g['bajo'],
                number_format($g['pct_alto'], 1, ',', ''), ($g['diferencia'] > 0 ? '+' : '') . number_format($g['diferencia'], 1, ',', ''), $estado);
        }
        $md[] = '';
    }
}
$md[] = "Grupos que requieren revisión humana (no explicados por un factor del modelo): $avisos";
$texto = implode("\n", $md) . "\n";
if (!empty($opts['salida'])) {
    file_put_contents((string) $opts['salida'], $texto);
    echo "Informe guardado en {$opts['salida']} · $avisos grupo(s) para revisar.\n";
} else {
    echo $texto;
}
