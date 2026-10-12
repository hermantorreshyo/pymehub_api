<?php
declare(strict_types=1);

/**
 * Pregenera con el proveedor de IA las respuestas del asesor para los perfiles de la demo
 * (§12.6, IA-015). Con ai.mode = offline, el asesor sirve estas respuestas sin conexión; si
 * un perfil no está pregenerado, responde el respaldo por reglas (IA-002).
 *
 * Requiere ai.api_key en config/config.php (nunca en el repositorio). Ejecutar después de
 * cargar los datos de demostración y otra vez si cambia el catálogo.
 * Uso: php cli/ai_warmup.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Ai\AiGateway;
use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Services\AsesorService;
use PymeHub\Services\DemoService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}
$ai = AiGateway::fromConfig();
if (!$ai->configured()) {
    echo "Sin clave de IA en config/config.php (ai.api_key): no hay nada que pregenerar.\n";
    echo "El asesor responderá con el respaldo por reglas, indicado como «Recomendación automática sin IA».\n";
    exit(0);
}

$empresas = Db::all('SELECT id, nombre FROM interlocutor WHERE nif IN (?, ?)', [DemoService::NIF_CASTILLA, DemoService::NIF_TAJO]);
if ($empresas === []) {
    fwrite(STDERR, "No hay datos de demostración: ejecuta antes php cli/demo_seed.php.\n");
    exit(1);
}

$servicio = new AsesorService($ai);
$fallos = 0;
foreach ($empresas as $e) {
    foreach (DemoService::PERFILES_ASESOR as $nombre => $p) {
        $r = $servicio->pregenerar($p['preferencias'], $p['perfil'], (int) $e['id']);
        echo sprintf("%-28s %-26s %s\n", $e['nombre'], $nombre, $r === 'ok' ? 'pregenerada' : "sin pregenerar ($r)");
        $fallos += $r === 'ok' ? 0 : 1;
        usleep(1_200_000); // margen para los límites del plan gratuito
    }
}
echo $fallos === 0 ? "Listo.\n" : "Hay perfiles sin pregenerar: vuelve a intentarlo más tarde. El asesor seguirá respondiendo con las reglas.\n";
