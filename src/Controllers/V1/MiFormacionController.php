<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\RateLimiter;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Tiempo;
use PymeHub\Core\Validator;
use PymeHub\Repositories\AsignacionRepository;
use PymeHub\Repositories\ModuloRepository;
use PymeHub\Services\CatalogoService;
use PymeHub\Services\FormacionService;

/**
 * Formación del empleado (§8.5, §10): lo asignado, el catálogo con autoinscripción y el
 * reproductor del módulo con su cuestionario. Avance e intentos son idempotentes para que la
 * app pueda reenviarlos desde la cola sin conexión (RF-045, RF-062).
 */
final class MiFormacionController
{
    /** GET /v1/me/training — mi formación (asignada, autoinscrita y completada). */
    public static function index(Request $request): void
    {
        Validator::validate($request->query, []);
        $rows = AsignacionRepository::delUsuario(Context::usuarioId(), Context::interlocutorId());
        $rows = array_map(static fn ($a) => array_diff_key($a, ['empleado' => 1]), $rows);
        $cuenta = ['PENDIENTE' => 0, 'EN_CURSO' => 0, 'COMPLETADA' => 0, 'VENCIDA' => 0];
        $minutos = 0;
        foreach ($rows as $a) {
            $cuenta[$a['estado']]++;
            if ($a['estado'] === 'COMPLETADA') {
                $minutos += $a['modulo']['duracion_min'];
            }
        }
        Response::json($rows, 200, [
            'hoy'         => Tiempo::hoy(),
            'pendientes'  => $cuenta['PENDIENTE'],
            'en_curso'    => $cuenta['EN_CURSO'],
            'completadas' => $cuenta['COMPLETADA'],
            'vencidas'    => $cuenta['VENCIDA'],
            'minutos_completados' => $minutos,
        ]);
    }

    /** GET /v1/me/training/catalog?category=&level=&q= — módulos publicados, con mi situación en cada uno. */
    public static function catalog(Request $request): void
    {
        $q = Validator::validate($request->query, [
            'category' => 'nullable|in:' . implode(',', CatalogoService::CATEGORIAS),
            'level'    => 'nullable|in:' . implode(',', CatalogoService::NIVELES),
            'q'        => 'nullable|string|max:80',
        ]);
        $iid = Context::interlocutorId();
        $uid = Context::usuarioId();
        $rows = ModuloRepository::list($iid, ['categoria' => $q['category'] ?? null, 'nivel' => $q['level'] ?? null, 'q' => $q['q'] ?? null], true);
        $mias = [];
        foreach (AsignacionRepository::delUsuario($uid, $iid) as $a) {
            $mias[$a['modulo']['id']] = ['id' => $a['id'], 'estado' => $a['estado'], 'origen' => $a['origen']];
        }
        $puesto = Db::value('SELECT puesto FROM ficha_laboral WHERE usuario_id = ? AND interlocutor_id = ?', [$uid, $iid]);
        foreach ($rows as &$m) {
            $m['asignacion'] = $mias[$m['id']] ?? null;
            $m['para_mi_puesto'] = $puesto !== null && in_array($puesto, $m['puestos_destino'], true);
        }
        unset($m);
        Response::json($rows, 200, ['total' => count($rows), 'puesto' => $puesto]);
    }

    /** POST /v1/me/training/enroll { modulo_id } — RF-047: autoinscripción sin fecha límite. */
    public static function enroll(Request $request): void
    {
        $in = Validator::validate($request->json(), ['modulo_id' => 'required|int|min:1']);
        $iid = Context::interlocutorId();
        $uid = Context::usuarioId();
        RateLimiter::hit("inscripcion:$uid", 30, 3600);
        $m = ModuloRepository::find((int) $in['modulo_id'], $iid);
        if ($m === null || $m['estado'] !== 'PUBLICADO') {
            throw ApiException::notFound();
        }
        if (AsignacionRepository::existente($uid, $m['id']) !== null) {
            throw new ApiException('PH-TRN-002', 'Ya estás inscrito en este módulo', 409);
        }
        $id = AsignacionRepository::inscribir($iid, $uid, $m['id'], 'AUTOINSCRIPCION');
        Audit::log('formacion.autoinscripcion', 'asignacion_formativa', $id, $request->ip);
        Response::json(AsignacionRepository::propia($id, $uid, $iid), 201);
    }

    /** GET /v1/me/training/{assignmentId} — el módulo para cursarlo, sin las soluciones del cuestionario. */
    public static function show(Request $request): void
    {
        $a = self::mia($request);
        $m = ModuloRepository::find($a['modulo']['id'], Context::interlocutorId());
        if ($m === null) {
            throw ApiException::notFound();
        }
        unset($a['empleado'], $m['interlocutor_id']);
        $m['preguntas'] = ModuloRepository::preguntas($m['id'], false);
        $a['modulo'] = $m;
        Response::json($a, 200, ['aprobado_con' => FormacionService::APROBADO]);
    }

    /** POST /v1/me/training/{assignmentId}/progress { bloque, progreso_pct } — nunca retrocede. */
    public static function progress(Request $request): void
    {
        $a = self::mia($request);
        $in = Validator::validate($request->json(), [
            'bloque'       => 'required|int|min:0|max:20',
            'progreso_pct' => 'required|int|min:0|max:100',
        ]);
        // El 100 % solo se alcanza aprobando el cuestionario
        AsignacionRepository::avanzar($a['id'], Context::interlocutorId(), (int) $in['bloque'], min(90, (int) $in['progreso_pct']));
        $out = AsignacionRepository::propia($a['id'], Context::usuarioId(), Context::interlocutorId());
        unset($out['empleado']);
        Response::json($out);
    }

    /**
     * POST /v1/me/training/{assignmentId}/submit { respuestas: int[], envio_id?: uuid }
     * RF-044: aprobado con ≥ 70 %, intentos ilimitados, se guarda la mejor nota.
     * Un envío repetido (mismo envio_id, reenviado desde la cola sin conexión) no suma un intento.
     */
    public static function submit(Request $request): void
    {
        $a = self::mia($request);
        $uid = Context::usuarioId();
        $iid = Context::interlocutorId();
        RateLimiter::hit("cuestionario:$uid", 60, 3600);
        $body = $request->json();
        $in = Validator::validate(array_diff_key($body, ['respuestas' => 1]), [
            'envio_id' => 'nullable|string|min:8|max:36',
        ]);
        if (isset($in['envio_id']) && !preg_match('/^[A-Za-z0-9-]{8,36}$/', $in['envio_id'])) {
            throw ApiException::validation(['envio_id' => 'Identificador no válido']);
        }
        $preguntas = ModuloRepository::preguntas($a['modulo']['id'], true);
        if ($preguntas === []) {
            throw ApiException::validation(['respuestas' => 'Este módulo no tiene cuestionario']);
        }
        $respuestas = FormacionService::validarRespuestas($preguntas, $body['respuestas'] ?? null);
        $resultado = FormacionService::corregir($preguntas, $respuestas);
        $envio = $in['envio_id'] ?? bin2hex(random_bytes(16));

        $repetido = Db::transaction(function () use ($a, $iid, $resultado, $envio): bool {
            // Bloqueo de la fila: dos envíos simultáneos del mismo intento no cuentan dos veces
            $actual = Db::value('SELECT ultimo_envio FROM asignacion_formativa WHERE id = ? AND interlocutor_id = ? FOR UPDATE', [$a['id'], $iid]);
            if ($actual !== null && hash_equals((string) $actual, $envio)) {
                return true;
            }
            AsignacionRepository::registrarIntento($a['id'], $iid, $resultado['nota'], $resultado['aprobado'], $envio);
            return false;
        });
        $out = AsignacionRepository::propia($a['id'], $uid, $iid);
        unset($out['empleado']);
        Response::json([
            'resultado'  => $resultado,
            'asignacion' => $out,
            'repetido'   => $repetido,
        ], 200, ['aprobado_con' => FormacionService::APROBADO]);
    }

    private static function mia(Request $request): array
    {
        $a = AsignacionRepository::propia($request->intParam('id'), Context::usuarioId(), Context::interlocutorId());
        if ($a === null) {
            // No existe o es de otra persona: no se distingue (PH-TRN-001, §16)
            throw new ApiException('PH-TRN-001', 'Este módulo no está asignado a ti', 409);
        }
        return $a;
    }
}
