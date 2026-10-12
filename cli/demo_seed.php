<?php
declare(strict_types=1);

/**
 * Datos de demostración para la defensa del TFM (§18, RF-100). Solo datos ficticios (LEG-009).
 *
 * Uso:
 *   php cli/demo_seed.php --password='Contraseña-de-la-demo'          crea los datos
 *   php cli/demo_seed.php --password='…' --reset                      los borra y los vuelve a crear
 *   php cli/demo_seed.php --borrar                                    solo los borra
 *
 * Usuarios (todos con la misma contraseña): admin@pymehub.demo, gerente@pymehub.demo,
 * rrhh@pymehub.demo, conductor@pymehub.demo (Castilla) y gerente2@pymehub.demo (Tajo).
 * La contraseña no se guarda en ningún sitio: entrégala al tribunal por separado.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Services\DemoService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}
$opts = getopt('', ['password:', 'reset', 'borrar']);

if (isset($opts['borrar'])) {
    DemoService::borrar();
    echo "Datos de demostración borrados.\n";
    exit(0);
}
$password = (string) ($opts['password'] ?? '');
if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Indica --password con al menos 10 caracteres.\n");
    exit(1);
}
if (DemoService::existe()) {
    if (!isset($opts['reset'])) {
        fwrite(STDERR, "Los datos de demostración ya existen. Usa --reset para regenerarlos.\n");
        exit(1);
    }
    DemoService::borrar();
    echo "Datos anteriores borrados.\n";
}
$r = (new DemoService($password))->generar();
echo "Reparto Rápido Castilla S.L. (Completo): {$r['castilla']['empleados']} empleados\n";
echo "Mensajería Tajo S.L. (Entrada): {$r['tajo']['empleados']} empleados\n";
echo "Check-ins de bienestar: {$r['checkins']}\n";
echo "Alertas de bienestar: {$r['alertas']}\n";
echo "Incidencias: {$r['incidencias']}\n";
echo "Formación asignada o en curso: {$r['formacion']}\n";
echo "Preferencias para el asesor: {$r['preferencias']}\n";
echo "Usuarios: admin@, gerente@, rrhh@, conductor@ y gerente2@ " . DemoService::DOMINIO . "\n";
echo "Asesor con IA: si hay clave de Mistral en config.php, pregenera las respuestas con php cli/ai_warmup.php\n";
