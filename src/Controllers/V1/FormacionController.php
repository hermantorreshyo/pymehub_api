<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Tiempo;
use PymeHub\Core\Validator;
use PymeHub\Repositories\AsignacionRepository;
use PymeHub\Repositories\EmpleadoRepository;
use PymeHub\Repositories\ModuloRepository;
use PymeHub\Services\CatalogoService;
use PymeHub\Services\FormacionService;

/**
 * Formación para gerencia y RR. HH. (§8.5, §10): catálogo, módulos propios, asignación,
 * seguimiento, recomendaciones por reglas y resumen.
 */
final class FormacionController
{
    private const MAX_PERSONAS = 250;

    /* ------------------------------------------------------------------ Módulos */

    /** GET /v1/training/modules?category=&level=&status=&q=&puesto= */
    public static function modules(Request $request): void
    {
        $q = Validator::validate($request->query, [
            'category' => 'nullable|in:' . implode(',', CatalogoService::CATEGORIAS),
            'level'    => 'nullable|in:' . implode(',', CatalogoService::NIVELES),
            'status'   => 'nullable|in:BORRADOR,PUBLICADO,ARCHIVADO',
            'puesto'   => 'nullable|in:' . implode(',', CatalogoService::PUESTOS),
            'q'        => 'nullable|string|max:80',
        ]);
        $iid = Context::interlocutorId();
        $rows = ModuloRepository::list($iid, [
            'categoria' => $q['category'] ?? null, 'nivel' => $q['level'] ?? null, 'estado' => $q['status'] ?? null,
            'q' => $q['q'] ?? null, 'puesto' => $q['puesto'] ?? null,
        ], false);

        // Uso del módulo en la empresa
        $uso = [];
        foreach (Db::all(
            'SELECT a.modulo_id, COUNT(*) AS total, SUM(' . AsignacionRepository::ESTADO_EFECTIVO . " = 'COMPLETADA') AS completadas
               FROM asignacion_formativa a
               JOIN ficha_laboral f ON f.usuario_id = a.usuario_id AND f.interlocutor_id = a.interlocutor_id AND f.fecha_baja IS NULL
              WHERE a.interlocutor_id = ?
              GROUP BY a.modulo_id",
            [Tiempo::hoy(), $iid]
        ) as $r) {
            $uso[(int) $r['modulo_id']] = ['personas' => (int) $r['total'], 'completadas' => (int) $r['completadas']];
        }
        foreach ($rows as &$m) {
            $m['uso'] = $uso[$m['id']] ?? ['personas' => 0, 'completadas' => 0];
        }
        unset($m);
        Response::json($rows, 200, ['total' => count($rows)]);
    }

    /** GET /v1/training/modules/{id} — con las preguntas y su solución (vista de gestión). */
    public static function module(Request $request): void
    {
        $m = FormacionService::moduloVisible($request->intParam('id'), Context::interlocutorId());
        if (!$m['propio'] && $m['estado'] !== 'PUBLICADO') {
            throw ApiException::notFound();
        }
        $m['preguntas'] = ModuloRepository::preguntas($m['id'], true);
        unset($m['interlocutor_id']);
        Response::json($m);
    }

    /** POST /v1/training/modules — módulo propio en borrador (RF-046, sin IA). */
    public static function storeModule(Request $request): void
    {
        $iid = Context::interlocutorId();
        $body = $request->json();
        $in = Validator::validate(array_diff_key($body, ['contenido' => 1, 'preguntas' => 1, 'puestos_destino' => 1, 'etiquetas' => 1]), [
            'titulo'       => 'required|string|min:3|max:150',
            'resumen'      => 'required|string|min:10|max:300',
            'categoria'    => 'required|in:' . implode(',', CatalogoService::CATEGORIAS),
            'nivel'        => 'nullable|in:' . implode(',', CatalogoService::NIVELES),
            'duracion_min' => 'required|int|min:1|max:15',
        ]);
        $puestos = self::listaEnum($body['puestos_destino'] ?? [], CatalogoService::PUESTOS, 'puestos_destino');
        $etiquetas = self::etiquetas($body['etiquetas'] ?? []);
        $mod = FormacionService::normalizarModulo($body['contenido'] ?? null, $body['preguntas'] ?? null, $in['categoria']);

        $id = Db::transaction(function () use ($iid, $in, $puestos, $etiquetas, $mod): int {
            $id = ModuloRepository::create($iid, [
                'codigo' => 'PROPIO_' . strtoupper(bin2hex(random_bytes(4))),
                'titulo' => $in['titulo'], 'resumen' => $in['resumen'], 'categoria' => $in['categoria'],
                'nivel' => $in['nivel'] ?? 'BASICO', 'duracion_min' => $in['duracion_min'],
                'puestos_destino' => $puestos, 'etiquetas' => $etiquetas, 'contenido' => $mod['contenido'],
            ]);
            CatalogoService::guardarPreguntas($id, $mod['preguntas']);
            return $id;
        });
        Audit::log('modulo.crear', 'modulo_formativo', $id, $request->ip);
        $m = ModuloRepository::find($id, $iid);
        $m['preguntas'] = ModuloRepository::preguntas($id, true);
        unset($m['interlocutor_id']);
        Response::json($m, 201);
    }

    /** PATCH /v1/training/modules/{id} — solo módulos propios. estado admite ARCHIVADO o volver a BORRADOR. */
    public static function updateModule(Request $request): void
    {
        $iid = Context::interlocutorId();
        $m = self::propio($request->intParam('id'), $iid);
        $body = $request->json();
        $in = Validator::validate(array_diff_key($body, ['contenido' => 1, 'preguntas' => 1, 'puestos_destino' => 1, 'etiquetas' => 1]), [
            'titulo'       => 'nullable|string|min:3|max:150',
            'resumen'      => 'nullable|string|min:10|max:300',
            'categoria'    => 'nullable|in:' . implode(',', CatalogoService::CATEGORIAS),
            'nivel'        => 'nullable|in:' . implode(',', CatalogoService::NIVELES),
            'duracion_min' => 'nullable|int|min:1|max:15',
            'estado'       => 'nullable|in:BORRADOR,ARCHIVADO',
        ]);
        $cambios = array_filter($in, static fn ($v) => $v !== null);
        if (array_key_exists('puestos_destino', $body)) {
            $cambios['puestos_destino'] = self::listaEnum($body['puestos_destino'], CatalogoService::PUESTOS, 'puestos_destino');
        }
        if (array_key_exists('etiquetas', $body)) {
            $cambios['etiquetas'] = self::etiquetas($body['etiquetas']);
        }
        $mod = null;
        if (array_key_exists('contenido', $body) || array_key_exists('preguntas', $body)) {
            $actuales = array_map(static fn ($p) => ['enunciado' => $p['enunciado'], 'opciones' => $p['opciones'], 'correcta' => $p['correcta']],
                ModuloRepository::preguntas($m['id'], true));
            $mod = FormacionService::normalizarModulo(
                $body['contenido'] ?? $m['contenido'],
                $body['preguntas'] ?? $actuales,
                $cambios['categoria'] ?? $m['categoria']
            );
            $cambios['contenido'] = $mod['contenido'];
            $cambios['preguntas'] = true;
        }
        if ($cambios === []) {
            throw ApiException::validation([], 'No hay cambios que guardar');
        }
        Db::transaction(function () use ($m, $iid, $cambios, $mod): void {
            ModuloRepository::update($m['id'], $iid, $cambios);
            if ($mod !== null) {
                Db::run('DELETE FROM pregunta_modulo WHERE modulo_id = ?', [$m['id']]);
                CatalogoService::guardarPreguntas($m['id'], $mod['preguntas']);
            }
        });
        Audit::log('modulo.editar', 'modulo_formativo', $m['id'], $request->ip);
        $out = ModuloRepository::find($m['id'], $iid);
        $out['preguntas'] = ModuloRepository::preguntas($m['id'], true);
        unset($out['interlocutor_id']);
        Response::json($out);
    }

    /** POST /v1/training/modules/{id}/publish — el módulo propio pasa al catálogo de la empresa. */
    public static function publish(Request $request): void
    {
        $iid = Context::interlocutorId();
        $m = self::propio($request->intParam('id'), $iid);
        $n = count(ModuloRepository::preguntas($m['id'], false));
        if ($n < 2 || $m['bloques'] < 1) {
            throw ApiException::validation(['modulo' => 'Necesita al menos un bloque y dos preguntas para publicarse']);
        }
        ModuloRepository::update($m['id'], $iid, ['estado' => 'PUBLICADO']);
        Audit::log('modulo.publicar', 'modulo_formativo', $m['id'], $request->ip);
        $out = ModuloRepository::find($m['id'], $iid);
        unset($out['interlocutor_id']);
        Response::json($out);
    }

    /* ------------------------------------------------------------------ Asignación y seguimiento */

    /**
     * POST /v1/training/assignments — RF-042: a personas concretas o a un equipo, con fecha límite.
     * { modulo_id, usuario_ids?: int[], equipo_id?: int, fecha_limite?: 'AAAA-MM-DD' }
     */
    public static function assign(Request $request): void
    {
        $iid = Context::interlocutorId();
        $body = $request->json();
        $in = Validator::validate(array_diff_key($body, ['usuario_ids' => 1]), [
            'modulo_id'    => 'required|int|min:1',
            'equipo_id'    => 'nullable|int|min:1',
            'fecha_limite' => 'nullable|date',
        ]);
        $ids = $body['usuario_ids'] ?? [];
        if (!is_array($ids) || !array_is_list($ids) || count($ids) > self::MAX_PERSONAS
            || array_filter($ids, static fn ($v) => !is_int($v) || $v < 1) !== []) {
            throw ApiException::validation(['usuario_ids' => 'Lista de identificadores de empleado (máximo ' . self::MAX_PERSONAS . ')']);
        }
        if ($ids === [] && empty($in['equipo_id'])) {
            throw ApiException::validation(['usuario_ids' => 'Elige al menos una persona o un equipo']);
        }
        if (isset($in['fecha_limite']) && $in['fecha_limite'] < Tiempo::hoy()) {
            throw ApiException::validation(['fecha_limite' => 'No puede ser anterior a hoy']);
        }
        $m = FormacionService::moduloVisible((int) $in['modulo_id'], $iid);
        if ($m['estado'] !== 'PUBLICADO') {
            throw ApiException::validation(['modulo_id' => 'Solo se pueden asignar módulos publicados']);
        }

        // Personas de alta de la empresa: las de otra empresa no existen para esta (PH-TENANT-001)
        $destino = [];
        foreach (array_unique($ids) as $uid) {
            $e = EmpleadoRepository::find($uid, $iid);
            if ($e === null) {
                throw ApiException::notFound();
            }
            if ($e['fecha_baja'] === null) {
                $destino[$uid] = true;
            }
        }
        if (!empty($in['equipo_id'])) {
            $equipo = Db::value('SELECT id FROM equipo WHERE id = ? AND interlocutor_id = ? AND eliminado_en IS NULL', [(int) $in['equipo_id'], $iid]);
            if ($equipo === null) {
                throw ApiException::notFound();
            }
            foreach (Db::all(
                "SELECT u.id FROM usuario u JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
                  WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL AND f.fecha_baja IS NULL AND f.equipo_id = ?",
                [$iid, (int) $in['equipo_id']]
            ) as $r) {
                $destino[(int) $r['id']] = true;
            }
        }
        if (count($destino) > self::MAX_PERSONAS) {
            throw ApiException::validation(['usuario_ids' => 'Máximo ' . self::MAX_PERSONAS . ' personas por asignación']);
        }

        $res = ['creadas' => 0, 'actualizadas' => 0, 'ya_completadas' => 0];
        Db::transaction(function () use ($destino, $iid, $m, $in, &$res): void {
            foreach (array_keys($destino) as $uid) {
                $r = AsignacionRepository::asignar($iid, $uid, $m['id'], $in['fecha_limite'] ?? null, Context::usuarioId());
                $res[match ($r) { 'creada' => 'creadas', 'actualizada' => 'actualizadas', default => 'ya_completadas' }]++;
            }
        });
        Audit::log('formacion.asignar', 'modulo_formativo', $m['id'], $request->ip);
        Response::json($res + ['personas' => count($destino), 'modulo' => ['id' => $m['id'], 'titulo' => $m['titulo']]], $res['creadas'] > 0 ? 201 : 200);
    }

    /** GET /v1/training/assignments?modulo_id=&equipo_id=&employee_id=&estado=&origen=&q=&page=&per_page= */
    public static function assignments(Request $request): void
    {
        $q = Validator::validate($request->query, [
            'modulo_id'   => 'nullable|int|min:1',
            'equipo_id'   => 'nullable|int|min:1',
            'employee_id' => 'nullable|int|min:1',
            'estado'      => 'nullable|in:PENDIENTE,EN_CURSO,COMPLETADA,VENCIDA',
            'origen'      => 'nullable|in:GESTION,AUTOINSCRIPCION,ASESOR_IA',
            'q'           => 'nullable|string|max:80',
            'page'        => 'nullable|int|min:1',
            'per_page'    => 'nullable|int|min:1|max:100',
        ]);
        $page = $q['page'] ?? 1;
        $perPage = $q['per_page'] ?? 25;
        [$rows, $total] = AsignacionRepository::list(Context::interlocutorId(), [
            'modulo_id' => $q['modulo_id'] ?? null, 'equipo_id' => $q['equipo_id'] ?? null,
            'usuario_id' => $q['employee_id'] ?? null, 'estado' => $q['estado'] ?? null,
            'origen' => $q['origen'] ?? null, 'q' => $q['q'] ?? null,
        ], $page, $perPage);
        Response::json($rows, 200, ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'hoy' => Tiempo::hoy()]);
    }

    /** DELETE /v1/training/assignments/{id} — retira una asignación de gestión que no se ha completado. */
    public static function unassign(Request $request): void
    {
        $iid = Context::interlocutorId();
        $a = AsignacionRepository::find($request->intParam('id'), $iid);
        if ($a === null) {
            throw ApiException::notFound();
        }
        if ($a['origen'] !== 'GESTION' || $a['estado'] === 'COMPLETADA') {
            throw ApiException::validation(['asignacion' => 'Solo se retiran asignaciones de la empresa que no se han completado']);
        }
        AsignacionRepository::delete($a['id'], $iid);
        Audit::log('formacion.retirar', 'asignacion_formativa', $a['id'], $request->ip);
        Response::json(null, 204);
    }

    /** GET /v1/training/recommendations?employee_id= — RF-043, por reglas y con su motivo. */
    public static function recommendations(Request $request): void
    {
        $q = Validator::validate($request->query, ['employee_id' => 'nullable|int|min:1']);
        $iid = Context::interlocutorId();
        if (isset($q['employee_id']) && EmpleadoRepository::find((int) $q['employee_id'], $iid) === null) {
            throw ApiException::notFound();
        }
        $rows = FormacionService::recomendaciones($iid, $q['employee_id'] ?? null);
        $porRegla = [];
        foreach ($rows as $r) {
            $porRegla[$r['regla']] = ($porRegla[$r['regla']] ?? 0) + 1;
        }
        Response::json($rows, 200, ['total' => count($rows), 'por_regla' => (object) $porRegla]);
    }

    /** GET /v1/training/summary — indicadores de formación de la empresa. */
    public static function summary(Request $request): void
    {
        Validator::validate($request->query, []);
        Response::json(FormacionService::resumen(Context::interlocutorId()), 200, ['hoy' => Tiempo::hoy()]);
    }

    /* ------------------------------------------------------------------ Apoyo */

    private static function propio(int $id, int $iid): array
    {
        $m = FormacionService::moduloVisible($id, $iid);
        if (!$m['propio']) {
            // El catálogo global es de la plataforma: la empresa no lo edita
            throw ApiException::forbidden();
        }
        return $m;
    }

    private static function listaEnum(mixed $v, array $permitidos, string $campo): array
    {
        if (!is_array($v) || !array_is_list($v) || array_diff($v, $permitidos) !== []) {
            throw ApiException::validation([$campo => 'Valores permitidos: ' . implode(', ', $permitidos)]);
        }
        return array_values(array_unique($v));
    }

    private static function etiquetas(mixed $v): array
    {
        if (!is_array($v) || !array_is_list($v) || count($v) > 8) {
            throw ApiException::validation(['etiquetas' => 'Hasta 8 etiquetas']);
        }
        $out = [];
        foreach ($v as $e) {
            if (!is_string($e) || trim($e) === '' || mb_strlen(trim($e)) > 30) {
                throw ApiException::validation(['etiquetas' => 'Cada etiqueta, de 1 a 30 caracteres']);
            }
            $out[] = mb_strtolower(trim($e));
        }
        return array_values(array_unique($out));
    }
}
