<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;
use PymeHub\Repositories\UsuarioRepository;
use PymeHub\Services\InvitacionService;

/**
 * Cuentas de RR. HH. de la empresa, gestionadas por el gerente (§6.1).
 */
final class UsuarioController
{
    /** GET /v1/users */
    public static function index(Request $request): void
    {
        $rows = UsuarioRepository::listGestion(Context::interlocutorId());
        Response::json($rows, 200, ['total' => count($rows)]);
    }

    /** POST /v1/users: crea una cuenta de RR. HH. e invita */
    public static function store(Request $request): void
    {
        $in = Validator::validate($request->json(), [
            'nombre'    => 'required|string|min:2|max:80',
            'apellidos' => 'nullable|string|max:120',
            'email'     => 'required|email',
        ]);
        if (UsuarioRepository::emailExists($in['email'])) {
            throw new ApiException('PH-VAL-001', 'Ese email ya tiene una cuenta', 409, ['email' => 'Duplicado']);
        }
        $result = Db::transaction(static function () use ($in): array {
            $id = UsuarioRepository::create(Context::interlocutorId(), 'EMPRESA', 'RRHH', $in['nombre'], $in['apellidos'] ?? '', $in['email']);
            return ['id' => $id, 'invitacion' => InvitacionService::create($id, Context::usuarioId())];
        });
        Audit::log('usuario.rrhh_creado', 'usuario', $result['id'], $request->ip);
        $user = UsuarioRepository::findInInterlocutor($result['id'], Context::interlocutorId());
        $user['invitacion'] = $result['invitacion'];
        Response::json($user, 201);
    }

    /** PATCH /v1/users/{id}: bloquear o reactivar una cuenta de RR. HH. */
    public static function update(Request $request): void
    {
        $user = self::findRrhh($request->intParam('id'));
        $in = Validator::validate($request->json(), [
            'nombre'        => 'nullable|string|min:2|max:80',
            'apellidos'     => 'nullable|string|max:120',
            'estado_acceso' => 'nullable|in:ACTIVO,BLOQUEADO',
        ]);
        if (($in['estado_acceso'] ?? null) === 'ACTIVO' && $user['estado_acceso'] !== 'BLOQUEADO') {
            throw ApiException::validation(['estado_acceso' => 'Solo se puede reactivar una cuenta bloqueada']);
        }
        Db::run(
            'UPDATE usuario SET nombre = COALESCE(?, nombre), apellidos = COALESCE(?, apellidos), estado_acceso = COALESCE(?, estado_acceso)
              WHERE id = ? AND interlocutor_id = ?',
            [$in['nombre'] ?? null, $in['apellidos'] ?? null, $in['estado_acceso'] ?? null, $user['id'], Context::interlocutorId()]
        );
        Audit::log('usuario.rrhh_actualizado', 'usuario', (int) $user['id'], $request->ip);
        Response::json(UsuarioRepository::findInInterlocutor((int) $user['id'], Context::interlocutorId()));
    }

    /** DELETE /v1/users/{id}: baja de la cuenta de RR. HH. */
    public static function destroy(Request $request): void
    {
        $user = self::findRrhh($request->intParam('id'));
        Db::run(
            "UPDATE usuario SET estado_acceso = 'BAJA', eliminado_en = UTC_TIMESTAMP(), password_hash = NULL
              WHERE id = ? AND interlocutor_id = ?",
            [$user['id'], Context::interlocutorId()]
        );
        Audit::log('usuario.rrhh_baja', 'usuario', (int) $user['id'], $request->ip);
        Response::json(null, 204);
    }

    /** POST /v1/users/{id}/invitation: nuevo enlace de invitación */
    public static function invite(Request $request): void
    {
        $user = self::findRrhh($request->intParam('id'));
        if ($user['estado_acceso'] === 'ACTIVO') {
            throw ApiException::validation(['estado_acceso' => 'La cuenta ya está activa']);
        }
        $inv = InvitacionService::create((int) $user['id'], Context::usuarioId());
        Audit::log('usuario.invitacion_generada', 'usuario', (int) $user['id'], $request->ip);
        Response::json($inv, 201);
    }

    private static function findRrhh(int $id): array
    {
        $user = UsuarioRepository::findInInterlocutor($id, Context::interlocutorId());
        if ($user === null || $user['perfil'] !== 'RRHH') {
            throw ApiException::notFound();
        }
        return $user;
    }
}
