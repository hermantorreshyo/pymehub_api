<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Funcionalidades por plan (DEC-10, §6.2 de los requisitos).
 */
final class Plan
{
    public const FEATURES = [
        'ENTRADA'  => ['bienestar', 'formacion', 'asesor_formacion'],
        'COMPLETO' => ['bienestar', 'formacion', 'asesor_formacion', 'riesgo_rotacion', 'linea_base'],
    ];

    /** Precios por empleado y mes sin IVA: ver Parametros::precioMes() (editables en el panel). */

    public static function features(?string $plan): array
    {
        return self::FEATURES[$plan ?? ''] ?? [];
    }
}
