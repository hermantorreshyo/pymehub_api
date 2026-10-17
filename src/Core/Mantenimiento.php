<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Modo mantenimiento (RNF-063).
 * - Activo: existe storage/maintenance.flag (se puede crear a mano en el servidor o desde el panel).
 *   La API responde PH-SYS-003 salvo a la salud, al estado público, al acceso y a la plataforma.
 * - Programado: ventana anunciada en storage/maintenance.json; la web muestra el aviso desde
 *   48 horas antes y hasta que termina.
 */
final class Mantenimiento
{
    public const AVISO_HORAS = 48;
    private const ZONA = 'Europe/Madrid';

    private static function flag(): string
    {
        return PH_ROOT . '/storage/maintenance.flag';
    }

    private static function json(): string
    {
        return PH_ROOT . '/storage/maintenance.json';
    }

    public static function activo(): bool
    {
        return is_file(self::flag());
    }

    /** Rutas que siguen respondiendo con el mantenimiento activo. */
    public static function permitida(string $path): bool
    {
        return $path === '/v1/health' || str_starts_with($path, '/v1/public/')
            || str_starts_with($path, '/v1/auth/') || str_starts_with($path, '/v1/admin/');
    }

    private static function datos(): array
    {
        $raw = is_file(self::json()) ? json_decode((string) file_get_contents(self::json()), true) : null;
        return is_array($raw) ? $raw : [];
    }

    public static function mensaje(): string
    {
        $m = self::datos()['mensaje'] ?? null;
        return is_string($m) && $m !== '' ? $m : 'Pyme Hub está en mantenimiento. Vuelve a intentarlo en unos minutos.';
    }

    /** Estado completo para la plataforma. */
    public static function estado(): array
    {
        $d = self::datos();
        return [
            'activo'     => self::activo(),
            'mensaje'    => $d['mensaje'] ?? null,
            'programado' => isset($d['inicio'], $d['fin']) ? ['inicio' => $d['inicio'], 'fin' => $d['fin']] : null,
            'aviso_horas' => self::AVISO_HORAS,
        ];
    }

    /** Lo que ve cualquier visitante: solo si hay mantenimiento activo o próximo (48 h). */
    public static function estadoPublico(): array
    {
        $d = self::datos();
        $programado = null;
        if (isset($d['inicio'], $d['fin'])) {
            $ini = strtotime((string) $d['inicio']);
            $fin = strtotime((string) $d['fin']);
            if ($fin > time() && $ini - time() <= self::AVISO_HORAS * 3600) {
                $programado = ['inicio' => $d['inicio'], 'fin' => $d['fin'], 'mensaje' => $d['mensaje'] ?? null];
            }
        }
        return ['mantenimiento' => self::activo(), 'mensaje' => self::activo() ? self::mensaje() : null, 'programado' => $programado];
    }

    /** Fecha y hora local de España (AAAA-MM-DDTHH:MM) a ISO UTC, o null. */
    public static function fecha(?string $local): ?string
    {
        if ($local === null || $local === '') {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', substr($local, 0, 16), new \DateTimeZone(self::ZONA));
        return $dt === false ? null : $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function guardar(bool $activo, ?string $mensaje, ?string $inicio, ?string $fin): void
    {
        $data = array_filter(['mensaje' => $mensaje ?: null, 'inicio' => $inicio, 'fin' => $fin], static fn ($v) => $v !== null);
        if ($data === []) {
            @unlink(self::json());
        } else {
            file_put_contents(self::json(), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        }
        if ($activo) {
            file_put_contents(self::flag(), gmdate('c') . "\n", LOCK_EX);
        } elseif (is_file(self::flag())) {
            unlink(self::flag());
        }
    }
}
