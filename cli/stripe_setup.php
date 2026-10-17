<?php
declare(strict_types=1);

/**
 * Configura Stripe en MODO PRUEBA en config/config.php sin editar el archivo a mano (fase 9c).
 * Pide las claves sin mostrarlas en pantalla, comprueba que son de prueba, guarda una copia
 * del config.php anterior (600) y conserva el propietario y los permisos del original.
 *
 *   sudo -u www-data php /var/www/hermantorres/eude/pymehub/pymehub_api/cli/stripe_setup.php
 *   … --desactivar   deja el pago online apagado sin borrar las claves
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;

$ruta = Config::path();
if (!is_file($ruta) || !is_writable($ruta)) {
    fwrite(STDERR, "No se puede escribir en $ruta. Ejecútalo como el propietario del archivo (p. ej. sudo -u www-data).\n");
    exit(1);
}
$config = require $ruta;
if (!is_array($config)) {
    fwrite(STDERR, "config.php no devuelve un array.\n");
    exit(1);
}

$oculto = static function (string $pregunta): string {
    echo $pregunta;
    $tty = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
    if ($tty) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $v = trim((string) fgets(STDIN));
    if ($tty) {
        shell_exec('stty echo 2>/dev/null');
    }
    echo "\n";
    return $v;
};

$stripe = (array) ($config['stripe'] ?? []);
if (in_array('--desactivar', $argv, true)) {
    $stripe['enabled'] = false;
} else {
    echo "Configuración de Stripe en MODO PRUEBA (Desarrolladores → Claves de API, con «Modo de prueba» activado).\n";
    echo "Pulsa Intro sin escribir nada para conservar el valor actual.\n\n";
    $sk = $oculto('Clave secreta (sk_test_…): ');
    if ($sk !== '' && !str_starts_with($sk, 'sk_test_')) {
        fwrite(STDERR, "La clave secreta debe empezar por sk_test_: el MVP solo funciona en modo prueba.\n");
        exit(1);
    }
    echo 'Clave publicable (pk_test_…): ';
    $pk = trim((string) fgets(STDIN));
    if ($pk !== '' && !str_starts_with($pk, 'pk_test_')) {
        fwrite(STDERR, "La clave publicable debe empezar por pk_test_.\n");
        exit(1);
    }
    $wh = $oculto('Secreto del webhook (whsec_…, puedes añadirlo después): ');
    if ($wh !== '' && !str_starts_with($wh, 'whsec_')) {
        fwrite(STDERR, "El secreto del webhook debe empezar por whsec_.\n");
        exit(1);
    }
    $stripe = array_merge($stripe, array_filter(['secret_key' => $sk, 'publishable_key' => $pk, 'webhook_secret' => $wh], static fn ($v) => $v !== ''));
    unset($stripe['api_base']);
    $stripe['enabled'] = str_starts_with((string) ($stripe['secret_key'] ?? ''), 'sk_test_');
}
$config['stripe'] = $stripe + ['enabled' => false, 'secret_key' => '', 'publishable_key' => '', 'webhook_secret' => ''];

$copia = $ruta . '.bak-' . date('Ymd-His');
if (!copy($ruta, $copia)) {
    fwrite(STDERR, "No se pudo guardar la copia de config.php.\n");
    exit(1);
}
chmod($copia, 0600);
$php = "<?php\n// Configuración de Pyme Hub API (no versionar). Actualizado por cli/stripe_setup.php el " . date('Y-m-d H:i') . "\nreturn "
    . var_export($config, true) . ";\n";
if (file_put_contents($ruta, $php, LOCK_EX) === false) {
    fwrite(STDERR, "No se pudo escribir config.php; el original está en $copia.\n");
    exit(1);
}
$s = $config['stripe'];
echo "\nGuardado. Copia anterior: $copia (bórrala cuando compruebes que todo funciona).\n";
echo 'Pago online: ' . ($s['enabled'] ? 'ACTIVO (modo prueba)' : 'desactivado') . "\n";
echo 'Clave secreta: ' . ($s['secret_key'] !== '' ? 'sk_test_…' . substr($s['secret_key'], -4) : 'vacía') . "\n";
echo 'Secreto del webhook: ' . ($s['webhook_secret'] !== '' ? 'whsec_…' . substr($s['webhook_secret'], -4) : 'pendiente') . "\n";
