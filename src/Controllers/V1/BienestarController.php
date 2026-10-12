<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Context;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;
use PymeHub\Repositories\EquipoRepository;
use PymeHub\Services\BienestarService;

/**
 * Bienestar agregado para gerencia y RR. HH. (§11, §8.6).
 * Nunca devuelve registros individuales ni notas (RF-053); por debajo del umbral de
 * anonimato no hay valores ni recuento exacto (LEG-002, PH-WB-001).
 */
final class BienestarController
{
    /** GET /v1/wellbeing/summary?team_id=&weeks=8 */
    public static function summary(Request $request): void
    {
        $q = Validator::validate($request->query, [
            'team_id' => 'nullable|int|min:1',
            'weeks'   => 'nullable|int|min:4|max:26',
        ]);
        $iid = Context::interlocutorId();
        $equipoId = $q['team_id'] ?? null;
        $equipo = null;
        if ($equipoId !== null) {
            $equipo = EquipoRepository::find($equipoId, $iid);
            if ($equipo === null) {
                throw ApiException::notFound();
            }
        }
        [$datos, $meta] = BienestarService::resumen($iid, $equipoId, $q['weeks'] ?? 8);
        $datos['ambito'] = $equipo === null
            ? ['tipo' => 'EMPRESA', 'equipo_id' => null, 'nombre' => Context::$interlocutor['nombre']]
            : ['tipo' => 'EQUIPO', 'equipo_id' => $equipo['id'], 'nombre' => $equipo['nombre']];
        Response::json($datos, 200, $meta);
    }
}
