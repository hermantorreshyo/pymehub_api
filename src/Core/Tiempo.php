<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Fechas "de calendario" de las personas usuarias. La base de datos y la API trabajan en UTC
 * (RNF-012), pero el día de un check-in o el inicio de una semana se cuentan en hora de España.
 */
final class Tiempo
{
    public const ZONA = 'Europe/Madrid';

    public static function hoy(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::ZONA)))->format('Y-m-d');
    }

    /** Fecha local desplazada n días respecto a hoy (n negativo = pasado). */
    public static function dia(int $offset): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::ZONA)))->modify(sprintf('%+d days', $offset))->format('Y-m-d');
    }

    /** Lunes de la semana de una fecha (AAAA-MM-DD). */
    public static function lunes(string $fecha): string
    {
        $d = new \DateTimeImmutable($fecha);
        return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
    }

    public static function sumarDias(string $fecha, int $dias): string
    {
        return (new \DateTimeImmutable($fecha))->modify(sprintf('%+d days', $dias))->format('Y-m-d');
    }
}
