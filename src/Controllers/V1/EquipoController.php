<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;
use PymeHub\Repositories\EquipoRepository;
use PymeHub\Repositories\UsuarioRepository;

/**
 * Equipos de la empresa (§8.3). Matriz P-10: el gerente los gestiona y RR. HH. los ve.
 */
final class EquipoController
{
    /** GET /v1/teams */
    public static function index(Request $request): void
    {
        $rows = EquipoRepository::list(Context::interlocutorId());
        Response::json($rows, 200, ['total' => count($rows)]);
    }

    /** POST /v1/teams */
    public static function store(Request $request): void
    {
        $in = Validator::validate($request->json(), [
            'nombre'                 => 'required|string|min:2|max:80',
            'zona'                   => 'nullable|string|max:80',
            'responsable_usuario_id' => 'nullable|int|min:1',
        ]);
        $iid = Context::interlocutorId();
        self::validarNombre($in['nombre'], $iid, null);
        self::validarResponsable($in['responsable_usuario_id'] ?? null, $iid);

        $id = EquipoRepository::create($iid, $in['nombre'], $in['zona'] ?? null, $in['responsable_usuario_id'] ?? null);
        Audit::log('equipo.creado', 'equipo', $id, $request->ip);
        Response::json(EquipoRepository::find($id, $iid), 201);
    }

    /** PATCH /v1/teams/{id} */
    public static function update(Request $request): void
    {
        $iid = Context::interlocutorId();
        $equipo = self::find($request);
        $in = Validator::validate($request->json(), [
            'nombre'                 => 'nullable|string|min:2|max:80',
            'zona'                   => 'nullable|string|max:80',
            'responsable_usuario_id' => 'nullable|int|min:1',
        ]);
        if (array_key_exists('nombre', $in) && $in['nombre'] === null) {
            throw ApiException::validation(['nombre' => 'No puede quedar vacío']);
        }
        if (isset($in['nombre'])) {
            self::validarNombre($in['nombre'], $iid, $equipo['id']);
        }
        self::validarResponsable($in['responsable_usuario_id'] ?? null, $iid);

        EquipoRepository::update($equipo['id'], $iid, $in);
        Audit::log('equipo.actualizado', 'equipo', $equipo['id'], $request->ip);
        Response::json(EquipoRepository::find($equipo['id'], $iid));
    }

    /** DELETE /v1/teams/{id}: borrado lógico, solo sin empleados de alta */
    public static function destroy(Request $request): void
    {
        $equipo = self::find($request);
        if ($equipo['empleados'] > 0) {
            throw new ApiException('PH-VAL-001', 'El equipo tiene empleados de alta: reasígnalos antes de eliminarlo', 409, ['empleados' => $equipo['empleados']]);
        }
        EquipoRepository::softDelete($equipo['id'], Context::interlocutorId());
        Audit::log('equipo.eliminado', 'equipo', $equipo['id'], $request->ip);
        Response::json(null, 204);
    }

    private static function find(Request $request): array
    {
        $equipo = EquipoRepository::find($request->intParam('id'), Context::interlocutorId());
        if ($equipo === null) {
            throw ApiException::notFound();
        }
        return $equipo;
    }

    private static function validarNombre(string $nombre, int $interlocutorId, ?int $exceptoId): void
    {
        $otro = EquipoRepository::idPorNombre($interlocutorId, $nombre);
        if ($otro !== null && $otro !== $exceptoId) {
            throw new ApiException('PH-VAL-001', 'Ya existe un equipo con ese nombre', 409, ['nombre' => 'Duplicado']);
        }
    }

    /** El responsable debe ser una persona de la misma empresa que no esté de baja. */
    private static function validarResponsable(?int $usuarioId, int $interlocutorId): void
    {
        if ($usuarioId === null) {
            return;
        }
        $u = UsuarioRepository::findInInterlocutor($usuarioId, $interlocutorId);
        if ($u === null || $u['estado_acceso'] === 'BAJA') {
            throw ApiException::validation(['responsable_usuario_id' => 'Usuario no encontrado']);
        }
    }
}
