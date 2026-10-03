<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\ApiException;
use PymeHub\Core\Config;
use PymeHub\Core\Db;

/**
 * Invitaciones de un solo uso (RF-005): 32 bytes aleatorios, se guarda solo
 * el hash SHA-256, caducan a las 72 horas.
 */
final class InvitacionService
{
    public const HORAS_VALIDEZ = 72;

    /** Crea una invitación nueva (anula las anteriores) y devuelve el enlace. */
    public static function create(int $usuarioId, ?int $creadaPor): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Db::run('UPDATE invitacion SET usada_en = UTC_TIMESTAMP() WHERE usuario_id = ? AND usada_en IS NULL', [$usuarioId]);
        Db::insert(
            'INSERT INTO invitacion (usuario_id, token_hash, expira_en, creada_por)
             VALUES (?, ?, UTC_TIMESTAMP() + INTERVAL ? HOUR, ?)',
            [$usuarioId, hash('sha256', $token), self::HORAS_VALIDEZ, $creadaPor]
        );
        Db::run("UPDATE usuario SET estado_acceso = 'INVITADO' WHERE id = ? AND estado_acceso IN ('SIN_ACCESO','INVITADO')", [$usuarioId]);

        $appUrl = rtrim((string) Config::get('app.frontend_url', ''), '/');
        return [
            'enlace'    => $appUrl . '/#/invitacion/' . $token,
            'expira_en' => gmdate('c', time() + self::HORAS_VALIDEZ * 3600),
        ];
    }

    /** Valida el token y activa la cuenta con la contraseña elegida. */
    public static function accept(string $token, string $password): array
    {
        return Db::transaction(static function () use ($token, $password): array {
            $inv = Db::one(
                'SELECT inv.id, inv.usuario_id, inv.expira_en, inv.usada_en, u.email, u.perfil
                   FROM invitacion inv JOIN usuario u ON u.id = inv.usuario_id
                  WHERE inv.token_hash = ? FOR UPDATE',
                [hash('sha256', $token)]
            );
            if ($inv === null || $inv['usada_en'] !== null || strtotime($inv['expira_en'] . ' UTC') < time()) {
                throw new ApiException('PH-AUTH-005', 'La invitación ha caducado o ya se ha utilizado. Pide una nueva.', 410);
            }
            if ($inv['email'] === null) {
                throw ApiException::validation(['email' => 'La cuenta no tiene email asociado']);
            }
            Db::run(
                "UPDATE usuario
                    SET password_hash = ?, estado_acceso = 'ACTIVO', privacidad_aceptada_en = UTC_TIMESTAMP()
                  WHERE id = ?",
                [password_hash($password, PASSWORD_ARGON2ID), (int) $inv['usuario_id']]
            );
            Db::run('UPDATE invitacion SET usada_en = UTC_TIMESTAMP() WHERE id = ?', [(int) $inv['id']]);
            return ['usuario_id' => (int) $inv['usuario_id'], 'email' => $inv['email']];
        });
    }
}
