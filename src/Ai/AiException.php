<?php
declare(strict_types=1);

namespace PymeHub\Ai;

/** Fallo del proveedor de IA. Nunca llega al empleado: se sirve el respaldo (IA-002). */
final class AiException extends \RuntimeException
{
    /** @param string $motivo NO_DISPONIBLE | RESPUESTA_INVALIDA */
    public function __construct(public readonly string $motivo, string $message)
    {
        parent::__construct($message);
    }
}
