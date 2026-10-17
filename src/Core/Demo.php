<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Demostración comercial (REQ_QR_DEMO_COMERCIAL, DMO-*).
 *
 * Configuración en config/config.php (no versionado):
 *   'demo' => [
 *       'habilitada'       => true,          // DMO-034: interruptor sin desplegar
 *       'habilitada_hasta' => null,          // 'AAAA-MM-DD' (incluido) o null, sin caducidad
 *       'password'         => 'PymeHub-Demo',// contraseña pública de los usuarios demo (DMO-023)
 *       'password_admin'   => '…',           // admin@pymehub.demo, solo pruebas internas (DMO-028)
 *   ],
 *   'campaigns' => ['feria_2027'],           // campañas adicionales a las de serie (CAMPANIAS_BASE)
 */
final class Demo
{
    /** Campaña reservada para las pruebas automáticas: no ensucia las métricas comerciales. */
    public const CAMPANIA_PRUEBA = 'prueba_interna';

    /** Campañas que vienen con el código (QR del folleto y QR de instalación de la app). */
    public const CAMPANIAS_BASE = ['folleto_comercial', 'instalar_app'];

    /** Fallos de login admitidos por IP en 15 minutos para cuentas demo (DMO-025). */
    public const MAX_INTENTOS_IP = 20;

    public static function habilitada(): bool
    {
        if (Config::get('demo.habilitada', true) !== true) {
            return false;
        }
        $hasta = Config::get('demo.habilitada_hasta');
        return !is_string($hasta) || $hasta === '' || Tiempo::hoy() <= $hasta;
    }

    public static function password(): ?string
    {
        $p = Config::get('demo.password');
        return is_string($p) && $p !== '' ? $p : null;
    }

    public static function passwordAdmin(): ?string
    {
        $p = Config::get('demo.password_admin');
        return is_string($p) && $p !== '' ? $p : null;
    }

    /** @return list<string> campañas admitidas (más la de pruebas) */
    public static function campanias(): array
    {
        $c = Config::get('campaigns', []);
        $c = is_array($c) ? array_values(array_filter($c, 'is_string')) : [];
        return array_values(array_unique([...self::CAMPANIAS_BASE, ...$c, self::CAMPANIA_PRUEBA]));
    }

    /** ¿La persona de la sesión es un usuario de la demostración? */
    public static function enSesion(): bool
    {
        return (int) (Context::$usuario['es_demo'] ?? 0) === 1;
    }

    /** Tipo de dispositivo a partir del User-Agent, que no se guarda (DMO-031). */
    public static function dispositivo(?string $ua): string
    {
        $ua = (string) $ua;
        if ($ua === '') {
            return 'OTRO';
        }
        if (preg_match('/Mobi|Android|iPhone|iPad|iPod|Windows Phone/i', $ua)) {
            return 'MOVIL';
        }
        if (preg_match('/Windows NT|Macintosh|X11|Linux|CrOS/i', $ua)) {
            return 'ESCRITORIO';
        }
        return 'OTRO';
    }
}
