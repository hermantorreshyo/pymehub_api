<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Limitación de peticiones en MySQL (sin Redis). Ventana fija.
 * La actualización es atómica en SQL (sin recalcular en PHP).
 */
final class RateLimiter
{
    /** Registra un intento y lanza PH-RATE-001 (o el código indicado) si se supera el límite. */
    public static function hit(string $key, int $max, int $windowSeconds, string $code = 'PH-RATE-001', string $msg = 'Demasiadas peticiones. Inténtalo más tarde.'): void
    {
        Db::run(
            'INSERT INTO limite_peticion (clave, contador, ventana_inicio) VALUES (?, 1, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
               contador = IF(ventana_inicio < UTC_TIMESTAMP() - INTERVAL ? SECOND, 1, contador + 1),
               ventana_inicio = IF(ventana_inicio < UTC_TIMESTAMP() - INTERVAL ? SECOND, UTC_TIMESTAMP(), ventana_inicio)',
            [$key, $windowSeconds, $windowSeconds]
        );
        $count = (int) Db::value('SELECT contador FROM limite_peticion WHERE clave = ?', [$key]);
        if ($count > $max) {
            throw new ApiException($code, $msg, 429);
        }
    }

    /** Comprueba sin sumar. */
    public static function exceeded(string $key, int $max, int $windowSeconds): bool
    {
        $row = Db::one(
            'SELECT contador FROM limite_peticion WHERE clave = ? AND ventana_inicio >= UTC_TIMESTAMP() - INTERVAL ? SECOND',
            [$key, $windowSeconds]
        );
        return $row !== null && (int) $row['contador'] >= $max;
    }

    public static function reset(string $key): void
    {
        Db::run('DELETE FROM limite_peticion WHERE clave = ?', [$key]);
    }
}
