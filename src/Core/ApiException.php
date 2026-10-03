<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Error de negocio con código semántico PH-*.
 */
final class ApiException extends \RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 400,
        public readonly array $details = []
    ) {
        parent::__construct($message);
    }

    public static function validation(array $details, string $message = 'Datos de entrada no válidos'): self
    {
        return new self('PH-VAL-001', $message, 422, $details);
    }

    public static function notFound(): self
    {
        return new self('PH-TENANT-001', 'Recurso no encontrado', 404);
    }

    public static function forbidden(): self
    {
        return new self('PH-PERM-001', 'Tu perfil no permite esta acción', 403);
    }
}
