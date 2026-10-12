<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\Plan;
use PymeHub\Core\RateLimiter;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Tiempo;
use PymeHub\Core\Validator;
use PymeHub\Repositories\AlertaRepository;
use PymeHub\Repositories\EmpleadoRepository;
use PymeHub\Repositories\EquipoRepository;
use PymeHub\Services\BienestarService;
use PymeHub\Services\RiesgoService;

/**
 * IA para RR. HH. (§9, fase 7): panel, tendencias, riesgo de rotación, metodología,
 * revisión de sesgos y línea base de impacto.
 *
 * - El riesgo solo existe con el plan Completo (RF-010, PH-PLAN-001 en las rutas).
 * - Toda consulta a la ficha de riesgo de una persona queda auditada (LEG-004).
 * - La puntuación orienta una conversación; ninguna acción es automática (LEG-005).
 */
final class RiesgoController
{
    private const AVISO_SUPERVISION = 'Esta puntuación orienta una conversación, no decide nada sobre la persona.';

    /* ------------------------------------------------------------------ Panel */

    /** GET /v1/dashboard/overview — ambos planes; el bloque de riesgo solo en Completo (RF-030). */
    public static function overview(Request $request): void
    {
        Validator::validate($request->query, []);
        $iid = Context::interlocutorId();
        $features = Plan::features(Context::$suscripcion['plan'] ?? null);
        $datos = RiesgoService::resumen($iid, in_array('riesgo_rotacion', $features, true));

        // Índice de bienestar agregado: solo si hay respuestas suficientes (LEG-002)
        $datos['bienestar'] = null;
        if (in_array('bienestar', $features, true)) {
            [$wb] = BienestarService::resumen($iid, null, 4);
            $datos['bienestar'] = $wb['actual'] === null
                ? ['disponible' => false, 'umbral_anonimato' => $wb['umbral_anonimato']]
                : ['disponible' => true, 'indice' => $wb['actual']['indice'], 'nivel' => $wb['actual']['nivel'], 'semana' => $wb['actual']['semana']];
        }
        $tipos = AlertaController::tiposVisibles();
        $datos['alertas_abiertas'] = AlertaRepository::contarAbiertas($iid, $tipos);
        $datos['alertas_riesgo_abiertas'] = in_array('RIESGO_ROTACION', $tipos, true)
            ? AlertaRepository::contarAbiertas($iid, ['RIESGO_ROTACION'])
            : null;
        Response::json($datos, 200, ['fecha' => Tiempo::hoy(), 'plan' => Context::$suscripcion['plan'] ?? null]);
    }

    /** GET /v1/dashboard/trends?months=6 — solo Completo. */
    public static function trends(Request $request): void
    {
        $q = Validator::validate($request->query, ['months' => 'nullable|int|min:3|max:12']);
        $meses = (int) ($q['months'] ?? 6);
        Response::json(RiesgoService::tendencias(Context::interlocutorId(), $meses), 200, ['meses' => $meses]);
    }

    /* ------------------------------------------------------------------ Riesgo */

    /** GET /v1/risk?team_id=&level=&q= — ranking por puntuación (RF-031). */
    public static function index(Request $request): void
    {
        $q = Validator::validate($request->query, [
            'team_id' => 'nullable|int|min:1',
            'level'   => 'nullable|in:BAJO,MEDIO,ALTO',
            'q'       => 'nullable|string|max:80',
        ]);
        $iid = Context::interlocutorId();
        if (isset($q['team_id']) && EquipoRepository::find((int) $q['team_id'], $iid) === null) {
            throw ApiException::notFound();
        }
        self::asegurarCalculo($iid);
        $filas = RiesgoService::ultimas($iid, [
            'equipo_id' => $q['team_id'] ?? null,
            'nivel'     => $q['level'] ?? null,
            'q'         => isset($q['q']) ? trim($q['q']) : null,
        ]);
        // En el ranking basta con los factores que suman (el detalle completo está en la ficha)
        $filas = array_map(static function (array $f): array {
            $f['factores'] = array_values(array_slice(array_filter($f['factores'], static fn ($x) => $x['puntos'] !== 0), 0, 3));
            return $f;
        }, $filas);
        $todas = RiesgoService::ultimas($iid);
        $niveles = ['ALTO' => 0, 'MEDIO' => 0, 'BAJO' => 0];
        foreach ($todas as $x) {
            $niveles[$x['nivel']]++;
        }
        Response::json($filas, 200, [
            'total'          => count($filas),
            'niveles'        => ['alto' => $niveles['ALTO'], 'medio' => $niveles['MEDIO'], 'bajo' => $niveles['BAJO']],
            'calculado'      => $todas === [] ? null : max(array_column($todas, 'fecha')),
            'version_reglas' => RiesgoService::reglas()['version'],
            'supervision'    => self::AVISO_SUPERVISION,
        ]);
    }

    /** GET /v1/employees/{id}/risk — ficha explicada (RF-034), auditada (LEG-004). */
    public static function show(Request $request): void
    {
        Validator::validate($request->query, []);
        $iid = Context::interlocutorId();
        $id = (int) $request->params['id'];
        $emp = EmpleadoRepository::find($id, $iid);
        if ($emp === null) {
            throw ApiException::notFound();
        }
        if ($emp['fecha_baja'] !== null) {
            throw new ApiException('PH-VAL-001', 'El empleado está dado de baja: ya no tiene puntuación de riesgo', 409, ['fecha_baja' => $emp['fecha_baja']]);
        }
        self::asegurarCalculo($iid);
        $ficha = RiesgoService::ficha($id, $iid);
        if ($ficha === null) {
            // Alta posterior al último cálculo: se calcula ahora la del día (RF-031, bajo demanda)
            RiesgoService::recalcular($iid);
            $ficha = RiesgoService::ficha($id, $iid);
        }
        if ($ficha === null) {
            throw ApiException::notFound();
        }
        Audit::log('riesgo.ficha_consultada', 'usuario', $id, $request->ip);
        Response::json($ficha, 200, [
            'supervision'  => self::AVISO_SUPERVISION,
            'metodologia'  => '/v1/risk/methodology',
            'alerta'       => self::alertaAbierta($iid, $id),
        ]);
    }

    /** POST /v1/risk/recalculate — bajo demanda, una vez cada 10 minutos por empresa (RF-031). */
    public static function recalculate(Request $request): void
    {
        Validator::validate($request->json(), []);
        $iid = Context::interlocutorId();
        RateLimiter::hit("riesgo:$iid", 1, 600, 'PH-RATE-001', 'El riesgo se acaba de recalcular. Puedes volver a hacerlo dentro de 10 minutos.');
        $r = RiesgoService::recalcular($iid);
        Audit::log('riesgo.recalculado', 'interlocutor', $iid, $request->ip);
        Response::json($r + ['fecha' => Tiempo::hoy(), 'version_reglas' => RiesgoService::reglas()['version']]);
    }

    /** GET /v1/risk/methodology — reglas, pesos, fuentes, exclusiones y limitaciones (RF-034, LEG-006). */
    public static function methodology(Request $request): void
    {
        Validator::validate($request->query, []);
        Response::json(RiesgoService::metodologia());
    }

    /** GET /v1/risk/bias — revisión de sesgos por grupos (LEG-015). */
    public static function bias(Request $request): void
    {
        Validator::validate($request->query, []);
        $iid = Context::interlocutorId();
        self::asegurarCalculo($iid);
        Response::json(RiesgoService::sesgos($iid));
    }

    /* ------------------------------------------------------------------ Línea base (RF-037) */

    /** GET /v1/baseline */
    public static function baseline(Request $request): void
    {
        Validator::validate($request->query, []);
        Response::json(RiesgoService::lineaBase(Context::interlocutorId()), 200, ['sugerencia' => self::periodoSugerido()]);
    }

    /** PUT /v1/baseline — los 12 meses anteriores al alta en Pyme Hub. */
    public static function saveBaseline(Request $request): void
    {
        $body = $request->json();
        $in = Validator::validate(array_diff_key($body, ['plantilla_media' => 1]), [
            'periodo_inicio'    => 'required|date',
            'periodo_fin'       => 'required|date',
            'bajas_voluntarias' => 'required|int|min:0|max:100000',
            'dias_absentismo'   => 'required|int|min:0|max:1000000',
            'fuente'            => 'nullable|string|max:150',
        ]);
        $errores = [];
        $plantilla = $body['plantilla_media'] ?? null;
        if (!is_int($plantilla) && !is_float($plantilla) && !(is_string($plantilla) && is_numeric($plantilla))) {
            $errores['plantilla_media'] = 'Debe ser un número';
        } elseif ((float) $plantilla < 1 || (float) $plantilla > 99999) {
            $errores['plantilla_media'] = 'Entre 1 y 99.999 personas';
        }
        $dias = (int) (new \DateTimeImmutable($in['periodo_inicio']))->diff(new \DateTimeImmutable($in['periodo_fin']))->format('%r%a');
        if ($dias <= 0) {
            $errores['periodo_fin'] = 'Debe ser posterior al inicio';
        } elseif ($dias < 180 || $dias > 400) {
            $errores['periodo_fin'] = 'El periodo debe cubrir unos 12 meses (entre 6 y 13)';
        }
        if ($in['periodo_fin'] > Tiempo::hoy()) {
            $errores['periodo_fin'] = 'No puede ser futura';
        }
        if ($errores !== []) {
            throw ApiException::validation($errores);
        }
        $iid = Context::interlocutorId();
        Db::transaction(static function () use ($iid, $in, $plantilla): void {
            // Una sola línea base vigente: la nueva sustituye a la anterior
            Db::run('DELETE FROM linea_base WHERE interlocutor_id = ?', [$iid]);
            Db::insert(
                'INSERT INTO linea_base (interlocutor_id, periodo_inicio, periodo_fin, plantilla_media, bajas_voluntarias, dias_absentismo, fuente, registrada_por)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$iid, $in['periodo_inicio'], $in['periodo_fin'], round((float) $plantilla, 2), (int) $in['bajas_voluntarias'],
                 (int) $in['dias_absentismo'], isset($in['fuente']) ? trim($in['fuente']) : null, Context::usuarioId()]
            );
        });
        Audit::log('linea_base.registrada', 'interlocutor', $iid, $request->ip);
        Response::json(RiesgoService::lineaBase($iid), 200, ['sugerencia' => self::periodoSugerido()]);
    }

    /* ------------------------------------------------------------------ Apoyo */

    /** Primera consulta de una empresa sin puntuaciones: se calcula la del día (RF-031). */
    private static function asegurarCalculo(int $iid): void
    {
        if (Db::value('SELECT 1 FROM puntuacion_riesgo WHERE interlocutor_id = ? LIMIT 1', [$iid]) === null) {
            RiesgoService::recalcular($iid);
        }
    }

    private static function alertaAbierta(int $iid, int $uid): ?array
    {
        $a = Db::one(
            "SELECT id, severidad, creado_en FROM alerta
              WHERE interlocutor_id = ? AND tipo = 'RIESGO_ROTACION' AND entidad = 'USUARIO' AND entidad_id = ? AND estado = 'ABIERTA'
              ORDER BY creado_en DESC LIMIT 1",
            [$iid, $uid]
        );
        return $a === null ? null : ['id' => (int) $a['id'], 'severidad' => $a['severidad'], 'creado_en' => str_replace(' ', 'T', $a['creado_en']) . 'Z'];
    }

    /** Los 12 meses anteriores al alta de la empresa en Pyme Hub. */
    private static function periodoSugerido(): ?array
    {
        $alta = Db::value('SELECT MIN(fecha_inicio) FROM suscripcion WHERE interlocutor_id = ?', [Context::interlocutorId()]);
        if ($alta === null) {
            return null;
        }
        $alta = substr((string) $alta, 0, 10);
        return [
            'alta'           => $alta,
            'periodo_inicio' => (new \DateTimeImmutable($alta))->modify('-12 months')->format('Y-m-d'),
            'periodo_fin'    => Tiempo::sumarDias($alta, -1),
        ];
    }
}
