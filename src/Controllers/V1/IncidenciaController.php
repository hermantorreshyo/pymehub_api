<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Catalogo;
use PymeHub\Core\Context;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;
use PymeHub\Repositories\EmpleadoRepository;
use PymeHub\Repositories\IncidenciaRepository;

/**
 * Incidencias de un empleado (§8.3): gerente y RR. HH.
 * LEG-012: solo los tipos del catálogo; las bajas médicas y sus causas no se registran.
 */
final class IncidenciaController
{
    /** GET /v1/employees/{id}/incidents?tipo=&desde=&hasta=&page=&per_page= */
    public static function index(Request $request): void
    {
        $emp = self::empleado($request);
        $q = Validator::validate($request->query, [
            'tipo'     => 'nullable|' . Catalogo::in(Catalogo::TIPOS_INCIDENCIA),
            'desde'    => 'nullable|date',
            'hasta'    => 'nullable|date',
            'page'     => 'nullable|int|min:1',
            'per_page' => 'nullable|int|min:1|max:100',
        ]);
        $page = $q['page'] ?? 1;
        $perPage = $q['per_page'] ?? 25;
        [$rows, $total] = IncidenciaRepository::list(Context::interlocutorId(), $emp['id'], $q, $page, $perPage);
        Response::json($rows, 200, ['total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    /** POST /v1/employees/{id}/incidents */
    public static function store(Request $request): void
    {
        $iid = Context::interlocutorId();
        $emp = self::empleado($request);
        $in = Validator::validate($request->json(), [
            'tipo'  => 'required|' . Catalogo::in(Catalogo::TIPOS_INCIDENCIA),
            'fecha' => 'required|date',
            'nota'  => 'nullable|string|max:500',
        ]);
        if ($in['fecha'] > gmdate('Y-m-d')) {
            throw ApiException::validation(['fecha' => 'No puede ser futura']);
        }
        if ($in['fecha'] < $emp['fecha_alta']) {
            throw ApiException::validation(['fecha' => 'Es anterior a la fecha de alta del empleado']);
        }
        if ($emp['fecha_baja'] !== null && $in['fecha'] > $emp['fecha_baja']) {
            throw ApiException::validation(['fecha' => 'Es posterior a la baja del empleado']);
        }

        $id = IncidenciaRepository::create($iid, $emp['id'], $in['tipo'], $in['fecha'], $in['nota'] ?? null, Context::usuarioId());
        Audit::log('incidencia.creada', 'incidencia', $id, $request->ip);
        Response::json(IncidenciaRepository::find($id, $iid), 201);
    }

    private static function empleado(Request $request): array
    {
        $emp = EmpleadoRepository::find($request->intParam('id'), Context::interlocutorId());
        if ($emp === null) {
            throw ApiException::notFound();
        }
        return $emp;
    }
}
