<?php
declare(strict_types=1);

namespace PymeHub\Core;

use PDO;

/**
 * Aplica las migraciones de /migrations en orden y registra cada versión.
 * Nota: en MySQL las sentencias DDL confirman la transacción de forma
 * implícita, así que una migración fallida a medias se corrige restaurando
 * la copia previa (ver manual de despliegue).
 */
final class Migrator
{
    public function __construct(private readonly PDO $pdo) {}

    /** @return string[] versiones aplicadas en esta ejecución */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migracion_esquema (
                version     VARCHAR(100) NOT NULL,
                aplicada_en DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (version)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci'
        );
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
                $this->pdo->exec($statement);
            }
            $st = $this->pdo->prepare('INSERT INTO migracion_esquema (version) VALUES (?)');
            $st->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
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
