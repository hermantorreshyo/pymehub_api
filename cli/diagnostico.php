<?php
declare(strict_types=1);

/**
 * Diagnóstico del despliegue en la terminal (el mismo que Administración → Diagnóstico).
 * Solo lee: no cambia nada. Nunca muestra secretos.
 *   sudo -u www-data php /var/www/hermantorres/eude/pymehub/pymehub_api/cli/diagnostico.php [--sin-red] [--solo-problemas]
 * Código de salida: 0 todo bien o con avisos, 1 con errores (útil para automatizarlo).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Services\DiagnosticoService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}
$opts = getopt('', ['sin-red', 'solo-problemas']);
$d = DiagnosticoService::ejecutar(!isset($opts['sin-red']));
$tty = function_exists('posix_isatty') && posix_isatty(STDOUT);
$color = static fn (string $e) => $tty ? ['OK' => "\033[32m", 'AVISO' => "\033[33m", 'ERROR' => "\033[31m", 'INFO' => "\033[36m"][$e] ?? '' : '';
$fin = $tty ? "\033[0m" : '';
$marca = ['OK' => '✔ OK   ', 'AVISO' => '! AVISO', 'ERROR' => '✘ ERROR', 'INFO' => 'i INFO '];

echo "Diagnóstico de Pyme Hub · API v{$d['version']} · {$d['generado_en']}\n";
$grupo = null;
foreach ($d['comprobaciones'] as $c) {
    if (isset($opts['solo-problemas']) && !in_array($c['estado'], ['AVISO', 'ERROR'], true)) {
        continue;
    }
    if ($c['grupo'] !== $grupo) {
        $grupo = $c['grupo'];
        echo "\n== $grupo\n";
    }
    echo '  ' . $color($c['estado']) . $marca[$c['estado']] . $fin . "  {$c['nombre']}: {$c['detalle']}\n";
    if ($c['accion'] !== null && $c['estado'] !== 'OK') {
        echo "           → {$c['accion']}\n";
    }
}
$r = $d['resumen'];
echo "\nResultado: " . $color($d['estado']) . $d['estado'] . $fin . " · {$r['OK']} correctas, {$r['AVISO']} avisos, {$r['ERROR']} errores, {$r['INFO']} informativas\n";
exit($d['estado'] === 'ERROR' ? 1 : 0);
