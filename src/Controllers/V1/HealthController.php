<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\Db;
use PymeHub\Core\Response;

/**
 * GET /v1/health (RNF-061). No expone datos internos.
 */
final class HealthController
{
    public static function show(): void
    {
        $checks = ['api' => 'ok', 'base_datos' => 'ok', 'disco' => 'ok', 'copia_seguridad' => 'sin_datos'];
        $status = 'ok';

        try {
            Db::value('SELECT 1');
        } catch (\Throwable) {
            $checks['base_datos'] = 'error';
            Response::json(['estado' => 'caido', 'comprobaciones' => $checks, 'version' => PH_VERSION], 503);
            return;
        }

        $free = @disk_free_space(PH_ROOT . '/storage');
        if ($free !== false && $free < 500 * 1024 * 1024) {
            $checks['disco'] = 'bajo';
            $status = 'degradado';
        }

        $marker = PH_ROOT . '/storage/backups/ultima_copia.txt';
        if (is_file($marker)) {
            $age = time() - (int) trim((string) file_get_contents($marker));
            $checks['copia_seguridad'] = $age > 26 * 3600 ? 'antigua' : 'ok';
            if ($age > 26 * 3600) {
                $status = 'degradado';
            }
        }

        Response::json(['estado' => $status, 'comprobaciones' => $checks, 'version' => PH_VERSION]);
    }
}
