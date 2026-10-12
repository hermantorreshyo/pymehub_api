<?php
declare(strict_types=1);

namespace PymeHub\Core;

use PDO;

/**
 * Motor de base de datos (DEC-05, RNF-008): la API funciona sobre MySQL 8.4 LTS y
 * MariaDB 11.8 LTS con el subconjunto de SQL común a ambos (RNF-009).
 * Producción usa MariaDB 11.8 (VPS compartido, ver manual 02).
 */
final class DbEngine
{
    public const MYSQL_MIN   = '8.4.0';
    public const MARIADB_MIN = '11.4.5';

    /** Collation de las migraciones; MariaDB la admite desde 11.4.5 como alias de compatibilidad. */
    public const COLLATION          = 'utf8mb4_0900_ai_ci';
    public const COLLATION_MARIADB  = 'utf8mb4_uca1400_ai_ci';

    /**
     * Detecta motor y versión a partir de SELECT VERSION().
     *   '11.8.6-MariaDB-0+deb13u1'  → ['engine' => 'mariadb', 'version' => '11.8.6', ...]
     *   '8.4.3'                     → ['engine' => 'mysql',   'version' => '8.4.3',  ...]
     */
    public static function detect(PDO $pdo): array
    {
        $raw = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        return self::parse($raw);
    }

    public static function parse(string $raw): array
    {
        $engine = stripos($raw, 'mariadb') !== false ? 'mariadb' : 'mysql';
        $version = preg_match('/^(\d+\.\d+\.\d+)/', $raw, $m) ? $m[1] : '0.0.0';
        return ['engine' => $engine, 'version' => $version, 'raw' => $raw];
    }

    public static function minimum(string $engine): string
    {
        return $engine === 'mariadb' ? self::MARIADB_MIN : self::MYSQL_MIN;
    }

    public static function label(string $engine): string
    {
        return $engine === 'mariadb' ? 'MariaDB' : 'MySQL';
    }

    /** ¿Cumple la versión mínima de su motor? */
    public static function supported(array $info): bool
    {
        return version_compare($info['version'], self::minimum($info['engine']), '>=');
    }

    public static function hasCollation(PDO $pdo, string $collation): bool
    {
        // Se prueba la collation directamente: SHOW COLLATION no admite parámetros en MariaDB y,
        // desde MariaDB 10.10, las collations UCA 14.0 figuran en information_schema.COLLATIONS
        // con su nombre corto (uca1400_ai_ci). El nombre solo puede ser una constante de la clase.
        if (!preg_match('/^[a-z0-9_]+$/', $collation)) {
            return false;
        }
        try {
            $pdo->query("SELECT CONVERT('a' USING utf8mb4) COLLATE $collation")->fetchColumn();
            return true;
        } catch (\PDOException) {
            return false;
        }
    }

    /**
     * Collation que se usará al crear tablas: la de las migraciones si existe; en MariaDB sin
     * ella, la equivalente utf8mb4_uca1400_ai_ci (§3.2 opción B). Null si no hay ninguna válida.
     */
    public static function collationFor(PDO $pdo, array $info): ?string
    {
        if (self::hasCollation($pdo, self::COLLATION)) {
            return self::COLLATION;
        }
        if ($info['engine'] === 'mariadb' && self::hasCollation($pdo, self::COLLATION_MARIADB)) {
            return self::COLLATION_MARIADB;
        }
        return null;
    }
}
