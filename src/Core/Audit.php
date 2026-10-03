<?php
declare(strict_types=1);

namespace PymeHub\Core;

/** Registro de auditoría de solo inserción (LEG-004). */
final class Audit
{
    public static function log(string $accion, ?string $entidad = null, ?int $entidadId = null, ?string $ip = null): void
    {
        $ipBin = null;
        if ($ip !== null && filter_var($ip, FILTER_VALIDATE_IP)) {
            $ipBin = inet_pton($ip);
        }
        Db::run(
            'INSERT INTO registro_auditoria (interlocutor_id, usuario_id, accion, entidad, entidad_id, ip) VALUES (?, ?, ?, ?, ?, ?)',
            [Context::interlocutorId() ?: null, Context::usuarioId() ?: null, $accion, $entidad, $entidadId, $ipBin]
        );
    }
}
