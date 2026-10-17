<?php
declare(strict_types=1);

/**
 * Vigencia de las suscripciones y cantidad facturada en Stripe (fases 9b y 9c). Programar a diario:
 *   15 0 * * *  www-data  php /var/www/hermantorres/eude/pymehub/pymehub_api/cli/subscription_status.php
 * 1. Guarda PRUEBA, VIGENTE, GRACIA o VENCIDA según periodo_fin y suscripcion.dias_gracia, y
 *    registra cada cambio en la auditoría (la API calcula el estado también en cada petición).
 * 2. Con Stripe activo, ajusta la cantidad de cada suscripción a los empleados activos (sin prorrateo).
 * Uso manual: php cli/subscription_status.php
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Services\PagoService;
use PymeHub\Services\SuscripcionService;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}

$cambios = SuscripcionService::actualizarTodas();
foreach ($cambios as $c) {
    echo "Empresa {$c['interlocutor_id']}: {$c['antes']} → {$c['despues']}\n";
}
echo 'Vigencias actualizadas: ' . count($cambios) . "\n";

$cantidades = PagoService::sincronizarCantidades();
foreach ($cantidades as $c) {
    echo "Empresa {$c['interlocutor_id']}: " . ($c['antes'] ?? '—') . " → {$c['despues']} empleados en Stripe\n";
}
echo 'Cantidades sincronizadas con Stripe: ' . count($cantidades) . (\PymeHub\Core\Stripe::habilitado() ? '' : ' (Stripe no activo)') . "\n";
