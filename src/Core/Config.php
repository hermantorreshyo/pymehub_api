<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Configuración cargada desde config/config.php (generado por el instalador).
 */
final class Config
{
    private static ?array $data = null;

    public static function path(): string
    {
        return PH_ROOT . '/config/config.php';
    }

    public static function isInstalled(): bool
    {
        return is_file(PH_ROOT . '/storage/installed.lock') && is_file(self::path());
    }

    public static function load(): void
    {
        if (self::$data === null) {
            self::$data = is_file(self::path()) ? (array) require self::path() : [];
        }
    }

    /** Lectura con notación de puntos: Config::get('db.host') */
    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        $value = self::$data;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }
        return $value;
    }
}
