<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Catalogo;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;
use PymeHub\Repositories\EmpleadoRepository;
use PymeHub\Services\EmpleadoService;
use PymeHub\Services\ImportacionEmpleadosService;
use PymeHub\Services\InvitacionService;

/**
 * Plantilla de la empresa (§8.3, RF-122): gerente y RR. HH.
 * Un empleado se crea en SIN_ACCESO; el email es opcional hasta invitarle.
 */
final class EmpleadoController
{
    /** GET /v1/employees?situacion=ALTA|BAJA|TODAS&equipo_id=&q=&page=&per_page= */
    public static function index(Request $request): void
    {
        $q = Validator::validate($request->query, [
            'situacion' => 'nullable|in:ALTA,BAJA,TODAS',
            'equipo_id' => 'nullable|int|min:1',
            'q'         => 'nullable|string|max:80',
            'page'      => 'nullable|int|min:1',
            'per_page'  => 'nullable|int|min:1|max:100',
        ]);
        $page = $q['page'] ?? 1;
        $perPage = $q['per_page'] ?? 25;
        [$rows, $total] = EmpleadoRepository::list(Context::interlocutorId(), $q, $page, $perPage);
        Response::json($rows, 200, ['total' => $total, 'page' => $page, 'per_page' => $perPage]);
    }

    /** POST /v1/employees */
    public static function store(Request $request): void
    {
        $in = Validator::validate($request->json(), self::reglas(true));
        $iid = Context::interlocutorId();
        EmpleadoService::validarEquipo($in['equipo_id'] ?? null, $iid);
        EmpleadoService::validarCodigoLibre($in['codigo_interno'] ?? null, $iid);
        EmpleadoService::validarEmailLibre($in['email'] ?? null);

        $id = Db::transaction(static function () use ($in, $iid): int {
            EmpleadoService::comprobarLimite($iid, 1);
            return EmpleadoRepository::create($iid, $in);
        });
        Audit::log('empleado.creado', 'usuario', $id, $request->ip);
        Response::json(EmpleadoRepository::find($id, $iid), 201);
    }

    /** GET /v1/employees/{id} */
    public static function show(Request $request): void
    {
        Response::json(self::find($request));
    }

    /** PATCH /v1/employees/{id} */
    public static function update(Request $request): void
    {
        $iid = Context::interlocutorId();
        $emp = self::findDeAlta($request);
        $in = Validator::validate($request->json(), self::reglas(false));

        $errores = [];
        foreach (['nombre', 'puesto', 'tipo_contrato', 'turno', 'fecha_alta'] as $c) {
            if (array_key_exists($c, $in) && $in[$c] === null) {
                $errores[$c] = 'No puede quedar vacío';
            }
        }
        if (array_key_exists('email', $in) && $in['email'] !== $emp['email'] && !EmpleadoService::emailEditable($emp['estado_acceso'])) {
            $errores['email'] = 'No se puede cambiar el email de un empleado con la cuenta activa';
        }
        if ($errores !== []) {
            throw ApiException::validation($errores);
        }
        if (array_key_exists('equipo_id', $in)) {
            EmpleadoService::validarEquipo($in['equipo_id'], $iid);
        }
        if (array_key_exists('codigo_interno', $in)) {
            EmpleadoService::validarCodigoLibre($in['codigo_interno'], $iid, $emp['id']);
        }
        if (array_key_exists('email', $in)) {
            EmpleadoService::validarEmailLibre($in['email'], $emp['id']);
        }

        EmpleadoRepository::update($emp['id'], $iid, $in);
        Audit::log('empleado.actualizado', 'usuario', $emp['id'], $request->ip);
        Response::json(EmpleadoRepository::find($emp['id'], $iid));
    }

    /**
     * DELETE /v1/employees/{id}: baja laboral con fecha y motivo. No borra al
     * usuario (se conserva para medir la rotación); le retira el acceso.
     * La supresión de datos (RGPD) es otro endpoint: POST /v1/employees/{id}/erase.
     */
    public static function destroy(Request $request): void
    {
        $iid = Context::interlocutorId();
        $emp = self::findDeAlta($request);
        $in = Validator::validate($request->json(), [
            'fecha_baja'  => 'required|date',
            'motivo_baja' => 'required|' . Catalogo::in(Catalogo::MOTIVOS_BAJA),
        ]);
        if ($in['fecha_baja'] < $emp['fecha_alta']) {
            throw ApiException::validation(['fecha_baja' => 'No puede ser anterior a la fecha de alta']);
        }
        if ($in['fecha_baja'] > gmdate('Y-m-d')) {
            throw ApiException::validation(['fecha_baja' => 'No puede ser futura: regístrala el día que se produzca']);
        }

        Db::transaction(static fn () => EmpleadoRepository::registrarBaja($emp['id'], $iid, $in['fecha_baja'], $in['motivo_baja']));
        Audit::log('empleado.baja', 'usuario', $emp['id'], $request->ip);
        Response::json(EmpleadoRepository::find($emp['id'], $iid));
    }

    /** POST /v1/employees/{id}/invitation (LEG-016). Cuerpo opcional: {"email": "..."} */
    public static function invite(Request $request): void
    {
        $iid = Context::interlocutorId();
        $emp = self::findDeAlta($request);
        EmpleadoService::comprobarContratoEncargado($iid);
        if ($emp['estado_acceso'] === 'ACTIVO' || $emp['estado_acceso'] === 'BLOQUEADO') {
            throw ApiException::validation(['estado_acceso' => $emp['estado_acceso'] === 'ACTIVO' ? 'La cuenta ya está activa' : 'La cuenta está bloqueada']);
        }
        $in = Validator::validate($request->json(), ['email' => 'nullable|email']);
        $email = $in['email'] ?? $emp['email'];
        if ($email === null) {
            throw ApiException::validation(['email' => 'El empleado no tiene email: indícalo para invitarle']);
        }
        if ($email !== $emp['email']) {
            EmpleadoService::validarEmailLibre($email, $emp['id']);
        }

        $inv = Db::transaction(static function () use ($emp, $iid, $email): array {
            if ($email !== $emp['email']) {
                EmpleadoRepository::update($emp['id'], $iid, ['email' => $email]);
            }
            return InvitacionService::create($emp['id'], Context::usuarioId());
        });
        Audit::log('empleado.invitacion_generada', 'usuario', $emp['id'], $request->ip);
        Response::json($inv, 201);
    }

    /** POST /v1/employees/import?simular=1 · cuerpo text/csv (RF-020) */
    public static function import(Request $request): void
    {
        $q = Validator::validate($request->query, ['simular' => 'nullable|in:0,1']);
        $ct = strtolower((string) $request->header('content-type'));
        if (!str_contains($ct, 'text/csv') && !str_contains($ct, 'text/plain')) {
            throw new ApiException('PH-VAL-001', 'El cuerpo debe ser el archivo CSV con Content-Type: text/csv', 415);
        }
        // Si el cuerpo supera post_max_size, PHP lo descarta: se decide por la cabecera
        if ((int) $request->header('content-length') > ImportacionEmpleadosService::MAX_BYTES) {
            throw new ApiException('PH-VAL-002', 'El CSV tiene filas erróneas: no se ha importado ninguna', 422, ['errores' => [
                ['fila' => null, 'columna' => null, 'motivo' => 'El archivo supera el máximo de 1 MB'],
            ]]);
        }

        $simular = ($q['simular'] ?? '0') === '1';
        $resumen = ImportacionEmpleadosService::importar($request->body(), Context::interlocutorId(), $simular);
        if (!$simular) {
            Audit::log('empleado.importacion', 'interlocutor', Context::interlocutorId(), $request->ip);
        }
        Response::json($resumen);
    }

    /** Reglas de alta (con obligatorios) o de cambio parcial. */
    private static function reglas(bool $alta): array
    {
        $reglas = [
            'nombre'         => 'required|string|min:2|max:80',
            'apellidos'      => 'nullable|string|max:120',
            'email'          => 'nullable|email',
            'codigo_interno' => 'nullable|string|max:30',
            'puesto'         => 'required|' . Catalogo::in(Catalogo::PUESTOS),
            'tipo_contrato'  => 'required|' . Catalogo::in(Catalogo::TIPOS_CONTRATO),
            'turno'          => 'required|' . Catalogo::in(Catalogo::TURNOS),
            'fecha_alta'     => 'required|date',
            'equipo_id'      => 'nullable|int|min:1',
        ];
        return $alta ? $reglas : array_map(static fn (string $r): string => str_replace('required|', 'nullable|', $r), $reglas);
    }

    private static function find(Request $request): array
    {
        $emp = EmpleadoRepository::find($request->intParam('id'), Context::interlocutorId());
        if ($emp === null) {
            throw ApiException::notFound();
        }
        return $emp;
    }

    /** Las fichas de baja son de solo lectura. */
    private static function findDeAlta(Request $request): array
    {
        $emp = self::find($request);
        if ($emp['fecha_baja'] !== null) {
            throw new ApiException('PH-VAL-001', 'El empleado está dado de baja', 409, ['fecha_baja' => $emp['fecha_baja']]);
        }
        return $emp;
    }
}
