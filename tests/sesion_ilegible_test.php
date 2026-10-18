<?php
declare(strict_types=1);

/**
 * Prueba de la v0.11.2: una sesión que no se puede leer no provoca un error 500.
 * Simula un archivo de sesión corrupto (con www-data además se puede probar uno de otro propietario).
 * Uso: php tests/sesion_ilegible_test.php   → «correcta» y código 0.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);   // igual que public/index.php
});

$dir = PH_ROOT . '/storage/sessions';
$id = 'pruebailegible' . bin2hex(random_bytes(8));
$archivo = "$dir/sess_$id";
is_dir($dir) || mkdir($dir, 0700, true);
file_put_contents($archivo, "\x00contenido-no-valido");
$_COOKIE['PHSESSID'] = $id;

try {
    \PymeHub\Core\Session::start();
    $ok = session_status() === PHP_SESSION_ACTIVE && session_id() !== $id && ($_SESSION ?? []) === [];
} catch (\Throwable $e) {
    $ok = false;
    fwrite(STDERR, 'Excepción: ' . $e->getMessage() . "\n");
}
session_abort();
is_file($archivo) && unlink($archivo);
echo $ok ? "Sesión ilegible: correcta (sesión nueva, sin error)\n" : "Sesión ilegible: FALLA\n";
exit($ok ? 0 : 1);
