<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Vigencia de la suscripción de una empresa (fase 9b).
 *
 *   PRUEBA  → periodo de prueba gratuita, hasta periodo_fin (suscripcion.dias_prueba).
 *   VIGENTE → pagada hasta periodo_fin, o sin vencimiento (periodo_fin NULL).
 *   GRACIA  → periodo_fin ya pasó; durante suscripcion.dias_gracia días todo sigue funcionando
 *             y se avisa en la aplicación.
 *   VENCIDA → terminó la gracia: solo lectura (PH-PLAN-002). El gerente puede renovar y exportar.
 *
 * El estado se calcula siempre a partir de las fechas, en cada petición; cli/subscription_status.php
 * lo guarda a diario y registra los cambios. Las fechas se cuentan en hora de España.
 */
final class Vigencia
{
    public const ESTADOS = ['PRUEBA', 'VIGENTE', 'GRACIA', 'VENCIDA'];

    public static function diasGracia(): int
    {
        return max(0, Parametros::int('suscripcion.dias_gracia', 7));
    }

    public static function diasPrueba(): int
    {
        return max(0, Parametros::int('suscripcion.dias_prueba', 30));
    }

    /** Último día de la gracia, o null si no hay vencimiento. */
    public static function graciaHasta(?string $periodoFin): ?string
    {
        return $periodoFin === null ? null : Tiempo::sumarDias($periodoFin, self::diasGracia());
    }

    /** Estado efectivo a partir de la fecha de fin y del estado guardado. */
    public static function calcular(?string $periodoFin, string $guardado, ?string $hoy = null): string
    {
        $hoy ??= Tiempo::hoy();
        if ($periodoFin === null) {
            return $guardado === 'PRUEBA' ? 'PRUEBA' : 'VIGENTE';
        }
        if ($hoy <= $periodoFin) {
            return $guardado === 'PRUEBA' ? 'PRUEBA' : 'VIGENTE';
        }
        return $hoy <= (string) self::graciaHasta($periodoFin) ? 'GRACIA' : 'VENCIDA';
    }

    /**
     * Resumen para la API (auth/me, billing) a partir de una fila de suscripcion
     * con vigencia y periodo_fin.
     */
    public static function resumen(?array $s): ?array
    {
        if ($s === null) {
            return null;
        }
        $fin = $s['periodo_fin'] ?? null;
        $estado = self::calcular($fin, (string) ($s['vigencia'] ?? 'VIGENTE'));
        $hoy = new \DateTimeImmutable(Tiempo::hoy());
        $dias = $fin === null ? null : (int) $hoy->diff(new \DateTimeImmutable($fin))->format('%r%a');
        return [
            'estado'          => $estado,
            'periodo_fin'     => $fin,
            'gracia_hasta'    => self::graciaHasta($fin),
            'dias_restantes'  => $dias,
            'solo_lectura'    => $estado === 'VENCIDA',
            'cancelar_al_final' => (bool) ($s['cancelar_al_final'] ?? false),
            'pago_online'     => !empty($s['stripe_subscription_id']),
        ];
    }

    /**
     * Rutas que siguen disponibles con la suscripción vencida (solo lectura): renovar el pago,
     * gestionar la facturación, cerrar sesión y exportar.
     */
    public static function rutaPermitida(string $method, string $path): bool
    {
        return $method === 'GET'
            || str_starts_with($path, '/v1/billing/')
            || $path === '/v1/auth/logout';
    }
}
