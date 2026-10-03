<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Enumerados de organización. Reflejan los CHECK de migrations/0001
 * (ficha_laboral e incidencia); si cambia uno, cambia el otro con una migración.
 */
final class Catalogo
{
    public const PUESTOS          = ['CONDUCTOR', 'REPARTIDOR', 'MOZO_ALMACEN', 'COORDINADOR', 'ADMINISTRATIVO', 'OTRO'];
    public const TIPOS_CONTRATO   = ['INDEFINIDO', 'TEMPORAL', 'ETT'];
    public const TURNOS           = ['MANANA', 'TARDE', 'NOCHE', 'ROTATIVO'];
    public const MOTIVOS_BAJA     = ['VOLUNTARIA', 'NO_VOLUNTARIA', 'FIN_CONTRATO'];

    /** LEG-012: solo ausencias injustificadas; nunca bajas médicas ni su causa. */
    public const TIPOS_INCIDENCIA = ['AUSENCIA_INJUSTIFICADA', 'RETRASO', 'SINIESTRO', 'QUEJA_CLIENTE', 'RECONOCIMIENTO'];

    /** Regla del Validator: 'in:A,B,C'. */
    public static function in(array $valores): string
    {
        return 'in:' . implode(',', $valores);
    }
}
