<?php
declare(strict_types=1);

/**
 * Datos de la demostración comercial (§18, RF-100, REQ_QR_DEMO_COMERCIAL). Solo datos ficticios (LEG-009).
 *
 * Uso:
 *   php cli/demo_seed.php                 crea los datos con las contraseñas de config.php
 *   php cli/demo_seed.php --reset         los borra y los vuelve a crear (idempotente, DMO-020);
 *                                         es lo que ejecuta el cron cada noche a las 03:30 (DMO-027)
 *   php cli/demo_seed.php --borrar        solo los borra
 *   --password='…' y --password-admin='…' sustituyen a las de config.php
 *
 * Contraseñas en config/config.php (no versionado):
 *   'demo' => ['password' => 'PymeHub-Demo', 'password_admin' => '…', …]
 * Usuarios demo (es_demo = 1, contraseña demo.password): gerente@, rrhh@ y conductor@ (Castilla)
 * y gerente2@ (Tajo), en pymehub.demo. admin@pymehub.demo no es demo (demo.password_admin): sin
 * esa contraseña no se crea ni se cambia, y el reinicio nunca lo borra.
 * Solo borra y recrea las empresas y personas marcadas con es_demo = 1.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Core\Demo;
use PymeHub\Services\DemoService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}
$opts = getopt('', ['password:', 'password-admin:', 'reset', 'borrar']);

if (isset($opts['borrar'])) {
    DemoService::borrar();
    echo "Datos de demostración borrados.\n";
    exit(0);
}
$password = (string) ($opts['password'] ?? Demo::password() ?? '');
if (mb_strlen($password) < 10) {
    fwrite(STDERR, "Falta la contraseña de la demo (mínimo 10 caracteres): añade 'demo' => ['password' => …] en config/config.php o usa --password.\n");
    exit(1);
}
// La contraseña de admin@pymehub.demo es opcional: un valor no válido en config.php (vacío, el de
// ejemplo «CAMBIAR» o menos de 10 caracteres) NO detiene la demo, solo deja esa cuenta sin cambios.
// Así el reinicio nocturno nunca falla por ella. Pasada a mano (--password-admin), sí se exige.
if (isset($opts['password-admin'])) {
    $passwordAdmin = (string) $opts['password-admin'];
    if (mb_strlen($passwordAdmin) < 10) {
        fwrite(STDERR, "La contraseña del administrador demo debe tener al menos 10 caracteres.\n");
        exit(1);
    }
} else {
    $passwordAdmin = Demo::passwordAdmin();
    if ($passwordAdmin !== null && (mb_strlen($passwordAdmin) < 10 || $passwordAdmin === 'CAMBIAR')) {
        fwrite(STDERR, "Aviso: demo.password_admin de config.php no es válida (mínimo 10 caracteres): admin@" . DemoService::DOMINIO . " queda sin cambios. La demo se genera igualmente.\n");
        $passwordAdmin = null;
    }
}
if (DemoService::existe()) {
    if (!isset($opts['reset'])) {
        fwrite(STDERR, "Los datos de demostración ya existen. Usa --reset para regenerarlos.\n");
        exit(1);
    }
    DemoService::borrar();
    echo "Datos anteriores borrados.\n";
}
$r = (new DemoService($password, 2026, $passwordAdmin === null ? null : (string) $passwordAdmin))->generar();
echo "Reparto Rápido Castilla S.L. (Completo): {$r['castilla']['empleados']} empleados\n";
echo "Mensajería Tajo S.L. (Entrada): {$r['tajo']['empleados']} empleados\n";
echo "Check-ins de bienestar: {$r['checkins']}\n";
echo "Alertas de bienestar: {$r['alertas']}\n";
echo "Incidencias: {$r['incidencias']}\n";
echo "Formación asignada o en curso: {$r['formacion']}\n";
echo "Preferencias para el asesor: {$r['preferencias']}\n";
echo "Bajas de los últimos 12 meses: {$r['bajas']}\n";
echo "Riesgo de rotación (Castilla): {$r['riesgo']['alto']} en nivel alto, {$r['riesgo']['medio']} en medio, {$r['riesgo']['alertas']} alertas, {$r['riesgo']['puntuaciones']} puntuaciones históricas\n";
echo "Usuarios demo: gerente@, rrhh@, conductor@, gerente2@ y formador@ (Pyme Hub Skills), dominio " . DemoService::DOMINIO . "\n";
echo $passwordAdmin === null
    ? "admin@" . DemoService::DOMINIO . ": sin cambios (demo.password_admin sin definir o no válida en config.php)\n"
    : "admin@" . DemoService::DOMINIO . ": contraseña de demo.password_admin\n";
echo 'Demostración ' . (Demo::habilitada() ? 'habilitada' : 'DESACTIVADA (demo.habilitada)') . "\n";
echo "Asesor con IA: si hay clave de Mistral en config.php, pregenera las respuestas con php cli/ai_warmup.php\n";
