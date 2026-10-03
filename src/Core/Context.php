<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Usuario autenticado de la petición en curso y su interlocutor.
 * El interlocutor_id se toma SIEMPRE de aquí, nunca de la petición (RNF-022).
 */
final class Context
{
    public static ?array $usuario = null;
    public static ?array $interlocutor = null;
    public static ?array $suscripcion = null;

    public static function usuarioId(): int
    {
        return (int) (self::$usuario['id'] ?? 0);
    }

    public static function interlocutorId(): int
    {
        return (int) (self::$interlocutor['id'] ?? 0);
    }

    public static function perfil(): string
    {
        return (string) (self::$usuario['perfil'] ?? '');
    }
}
