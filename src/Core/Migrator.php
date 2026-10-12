<?php
declare(strict_types=1);

namespace PymeHub\Core;

use PDO;

/**
 * Aplica las migraciones de /migrations en orden y registra cada versión (RF-096).
 * Funciona en MySQL 8.4 y MariaDB 11.8 (DEC-05). Las migraciones publicadas no se editan:
 * si el servidor no tiene la collation utf8mb4_0900_ai_ci (MariaDB anterior a 11.4.5 o
 * compilado sin ella), se sustituye en tiempo de ejecución por utf8mb4_uca1400_ai_ci.
 * Nota: en ambos motores las sentencias DDL confirman la transacción de forma
 * implícita, así que una migración fallida a medias se corrige restaurando
 * la copia previa (ver manual de despliegue).
 */
final class Migrator
{
    private string $collation;

    public function __construct(private readonly PDO $pdo)
    {
        $info = DbEngine::detect($pdo);
        $collation = DbEngine::collationFor($pdo, $info);
        if ($collation === null) {
            throw new \RuntimeException(sprintf(
                'El servidor %s %s no tiene la collation %s ni una equivalente.',
                DbEngine::label($info['engine']), $info['version'], DbEngine::COLLATION
            ));
        }
        $this->collation = $collation;
    }

    /** Collation efectiva con la que se crean las tablas. */
    public function collation(): string
    {
        return $this->collation;
    }

    /** @return string[] versiones aplicadas en esta ejecución */
    public function migrate(): array
    {
        $this->pdo->exec($this->adapt(
            'CREATE TABLE IF NOT EXISTS migracion_esquema (
                version     VARCHAR(100) NOT NULL,
                aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        ));
        $done = $this->pdo->query('SELECT version FROM migracion_esquema')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(PH_ROOT . '/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }
            foreach (self::split((string) file_get_contents($file)) as $statement) {
                $this->pdo->exec($this->adapt($statement));
            }
            $st = $this->pdo->prepare('INSERT INTO migracion_esquema (version) VALUES (?)');
            $st->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** Sustituye la collation de las migraciones si el servidor no la tiene (§3.2 opción B). */
    private function adapt(string $sql): string
    {
        return $this->collation === DbEngine::COLLATION ? $sql : str_replace(DbEngine::COLLATION, $this->collation, $sql);
    }

    /** Divide un script SQL en sentencias, respetando comillas y comentarios. */
    public static function split(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $len = strlen($sql);
        $quote = null;
        for ($i = 0; $i < $len; $i++) {
            $c = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($quote === null && $c === '-' && $next === '-') {
                $eol = strpos($sql, "\n", $i);
                $i = $eol === false ? $len : $eol;
                $buffer .= "\n";
                continue;
            }
            if ($quote === null && $c === '/' && $next === '*') {
                $end = strpos($sql, '*/', $i + 2);
                $i = $end === false ? $len : $end + 1;
                continue;
            }
            if ($c === "'" || $c === '"' || $c === '`') {
                if ($quote === null) {
                    $quote = $c;
                } elseif ($quote === $c && ($sql[$i - 1] ?? '') !== '\\') {
                    $quote = null;
                }
            }
            if ($c === ';' && $quote === null) {
                if (trim($buffer) !== '') {
                    $statements[] = trim($buffer);
                }
                $buffer = '';
                continue;
            }
            $buffer .= $c;
        }
        if (trim($buffer) !== '') {
            $statements[] = trim($buffer);
        }
        return $statements;
    }
}
