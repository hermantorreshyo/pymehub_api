<?php
declare(strict_types=1);

/**
 * Retención de datos (§17.2). Programar a diario a las 04:00:
 *   0 4 * * *  www-data  php /var/www/hermantorres/eude/pymehub/pymehub_api/cli/purge_retention.php
 * - LEG-008: borra los check-ins individuales de más de 12 meses (los agregados ya mostrados
 *   no se guardan aparte: se recalculan sobre los datos vigentes).
 * - Invitaciones caducadas hace más de 30 días.
 * - Caché del asesor de IA caducada hace más de 30 días (las respuestas pregeneradas no caducan)
 *   y registro de consultas al asesor de más de 12 meses (solo metadatos, sin contenido).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Core\Tiempo;
use PymeHub\Repositories\CheckinRepository;

if (!Config::isInstalled()) {
    fwrite(STDERR, "La API no está instalada.\n");
    exit(1);
}
$limite = Tiempo::dia(-365);
$checkins = CheckinRepository::purgarAnteriores($limite);
$invitaciones = Db::run('DELETE FROM invitacion WHERE expira_en < UTC_TIMESTAMP() - INTERVAL 30 DAY');
echo "Check-ins anteriores a $limite borrados: $checkins\n";
echo "Invitaciones caducadas borradas: $invitaciones\n";
$cache = Db::run('DELETE FROM respuesta_ia WHERE caduca_en IS NOT NULL AND caduca_en < UTC_TIMESTAMP() - INTERVAL 30 DAY');
$solicitudes = Db::run('DELETE FROM solicitud_ia WHERE creado_en < UTC_TIMESTAMP() - INTERVAL 365 DAY');
echo "Respuestas del asesor caducadas borradas: $cache\n";
echo "Consultas al asesor de más de 12 meses borradas: $solicitudes\n";
