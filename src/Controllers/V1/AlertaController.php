<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Plan;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;
use PymeHub\Repositories\AlertaRepository;

/**
 * Alertas para gerencia y RR. HH. (§8.4). Las de riesgo de rotación solo existen con el
 * plan Completo (RF-033); las de bienestar de equipo, en ambos planes (RF-054).
 * El sistema solo alerta: la gestión siempre es humana (LEG-005).
 */
final class AlertaController
{
    /** Tipos visibles según el plan de la empresa. */
    public static function tiposVisibles(): array
    {
        $features = Plan::features(Context::$suscripcion['plan'] ?? null);
        $tipos = [];
        if (in_array('bienestar', $features, true)) {
            $tipos[] = 'BIENESTAR_EQUIPO';
        }
        if (in_array('riesgo_rotacion', $features, true)) {
            $tipos[] = 'RIESGO_ROTACION';
        }
        if (in_array('formacion', $features, true)) {
            $tipos[] = 'FORMACION_VENCIDA';
        }
        return $tipos;
    }

    /** GET /v1/alerts?status=open|managed|dismissed|all&tipo=&page=&per_page= */
    public static function index(Request $request): void
    {
        $q = Validator::validate($request->query, [
            'status'   => 'nullable|in:open,managed,dismissed,all',
            'tipo'     => 'nullable|in:BIENESTAR_EQUIPO,RIESGO_ROTACION,FORMACION_VENCIDA',
            'page'     => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:1|max:100',
        ]);
        $estado = match ($q['status'] ?? 'open') {
            'open'      => 'ABIERTA',
            'managed'   => 'GESTIONADA',
            'dismissed' => 'DESCARTADA',
            default     => null,
        };
        $tipos = self::tiposVisibles();
        if (isset($q['tipo'])) {
            $tipos = array_values(array_intersect($tipos, [$q['tipo']]));
        }
        $page = $q['page'] ?? 1;
        $perPage = $q['per_page'] ?? 25;
        [$rows, $total] = AlertaRepository::list(Context::interlocutorId(), $tipos, $estado, $page, $perPage);
        Response::json($rows, 200, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $perPage,
            'abiertas' => AlertaRepository::contarAbiertas(Context::interlocutorId(), self::tiposVisibles()),
        ]);
    }

    /** PATCH /v1/alerts/{id} — marcar como gestionada o descartada, con nota opcional. */
    public static function update(Request $request): void
    {
        $iid = Context::interlocutorId();
        $alerta = AlertaRepository::find($request->intParam('id'), $iid);
        if ($alerta === null || !in_array($alerta['tipo'], self::tiposVisibles(), true)) {
            throw ApiException::notFound();
        }
        $in = Validator::validate($request->json(), [
            'estado'       => 'required|in:GESTIONADA,DESCARTADA,ABIERTA',
            'nota_gestion' => 'nullable|string|max:500',
        ]);
        AlertaRepository::gestionar($alerta['id'], $iid, $in['estado'], $in['nota_gestion'] ?? null, Context::usuarioId());
        Audit::log('alerta.' . strtolower($in['estado']), 'alerta', $alerta['id'], $request->ip);
        Response::json(AlertaRepository::find($alerta['id'], $iid));
    }
}
