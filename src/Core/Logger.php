<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Log JSON por línea (RNF-040). Nunca registra contraseñas, tokens,
 * contenido de check-ins ni prompts de IA.
 */
final class Logger
{
    private static ?string $correlationId = null;

    public static function correlationId(): string
    {
        return self::$correlationId ??= bin2hex(random_bytes(8));
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $dir = PH_ROOT . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $line = json_encode([
            'ts'      => gmdate('c'),
            'level'   => $level,
            'cid'     => self::correlationId(),
            'msg'     => $message,
            'ctx'     => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        @file_put_contents($dir . '/api-' . gmdate('Y-m-d') . '.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public static function info(string $m, array $c = []): void  { self::log('info', $m, $c); }
    public static function warn(string $m, array $c = []): void  { self::log('warning', $m, $c); }
    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }
}
