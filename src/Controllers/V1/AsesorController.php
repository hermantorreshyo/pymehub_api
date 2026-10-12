<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Ai\AiGateway;
use PymeHub\Ai\PayloadGuard;
use PymeHub\Core\ApiException;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\RateLimiter;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;
use PymeHub\Services\AsesorService;
use PymeHub\Services\CatalogoService;

/**
 * Asesor de formación con IA (§8.7, §12).
 * - Empleado: preferencias (LEG-014: solo las ve él), consulta y valoración.
 * - Gestión: demanda formativa agregada, con umbral de 5 personas (IA-021, LEG-002).
 * - Plataforma: uso del asesor, sin contenido.
 */
final class AsesorController
{
    private const UMBRAL = 5;

    /* ------------------------------------------------------------------ Empleado */

    /** GET /v1/me/training/preferences */
    public static function preferences(Request $request): void
    {
        Validator::validate($request->query, []);
        Response::json(AsesorService::preferencias(Context::usuarioId()), 200, ['opciones' => self::opciones()]);
    }

    /** PUT /v1/me/training/preferences */
    public static function savePreferences(Request $request): void
    {
        $body = $request->json();
        $in = Validator::validate(array_diff_key($body, ['intereses' => 1]), [
            'objetivo'       => 'required|in:' . implode(',', array_keys(AsesorService::OBJETIVOS)),
            'formato'        => 'nullable|in:' . implode(',', array_keys(AsesorService::FORMATOS)),
            'minutos_semana' => 'required|int|in:5,15,30',
            'comentario'     => 'nullable|string|max:200',
        ]);
        $intereses = $body['intereses'] ?? [];
        if (!is_array($intereses) || !array_is_list($intereses) || array_diff($intereses, CatalogoService::CATEGORIAS) !== []) {
            throw ApiException::validation(['intereses' => 'Temas del catálogo: ' . implode(', ', CatalogoService::CATEGORIAS)]);
        }
        // IA-010: el comentario no puede llevar datos personales ni de salud (PH-AI-004)
        $comentario = PayloadGuard::comentario($in['comentario'] ?? null, Context::interlocutorId());
        AsesorService::guardarPreferencias(Context::usuarioId(), [
            'objetivo'       => $in['objetivo'],
            'intereses'      => array_values(array_unique($intereses)),
            'formato'        => $in['formato'] ?? 'TEXTO',
            'minutos_semana' => (int) $in['minutos_semana'],
            'comentario'     => $comentario,
        ]);
        Response::json(AsesorService::preferencias(Context::usuarioId()), 200, ['opciones' => self::opciones()]);
    }

    /** DELETE /v1/me/training/preferences — LEG-014: el empleado puede borrarlas. */
    public static function deletePreferences(Request $request): void
    {
        Db::run('DELETE FROM preferencia_formativa WHERE usuario_id = ?', [Context::usuarioId()]);
        Response::json(null, 204);
    }

    /** POST /v1/me/training/advisor — 3 a 5 recomendaciones del catálogo con su motivo. */
    public static function advise(Request $request): void
    {
        Validator::validate($request->json(), []);
        $uid = Context::usuarioId();
        RateLimiter::hit("asesor:$uid", 20, 3600);
        $r = (new AsesorService(AiGateway::fromConfig()))->recomendar($uid, Context::interlocutorId(), Context::$suscripcion);
        $meta = ['aprobado_con' => \PymeHub\Services\FormacionService::APROBADO];
        if ($r['aviso'] !== null) {
            $mensajes = [
                'PH-AI-001' => 'El asistente no está disponible ahora mismo; te mostramos recomendaciones basadas en reglas.',
                'PH-AI-002' => 'Has llegado al límite de consultas de hoy; te mostramos recomendaciones basadas en reglas.',
                'PH-AI-003' => 'No hemos podido generar la respuesta; te mostramos recomendaciones basadas en reglas.',
            ];
            $meta['aviso'] = ['code' => $r['aviso'], 'message' => $mensajes[$r['aviso']]];
        }
        unset($r['aviso']);
        Response::json($r, 200, $meta);
    }

    /** POST /v1/me/training/advisor/feedback { solicitud_id, util } */
    public static function feedback(Request $request): void
    {
        $in = Validator::validate($request->json(), [
            'solicitud_id' => 'required|int|min:1',
            'util'         => 'required|bool',
        ]);
        $n = Db::run(
            'UPDATE solicitud_ia SET util = ? WHERE id = ? AND usuario_id = ? AND interlocutor_id = ?',
            [(int) $in['util'], (int) $in['solicitud_id'], Context::usuarioId(), Context::interlocutorId()]
        );
        if ($n === 0 && Db::value('SELECT 1 FROM solicitud_ia WHERE id = ? AND usuario_id = ?', [(int) $in['solicitud_id'], Context::usuarioId()]) === null) {
            throw ApiException::notFound();
        }
        Response::json(['solicitud_id' => (int) $in['solicitud_id'], 'util' => $in['util']]);
    }

    /* ------------------------------------------------------------------ Gestión */

    /** GET /v1/training/demand — qué objetivos y temas piden más (IA-021). Sin datos individuales. */
    public static function demand(Request $request): void
    {
        Validator::validate($request->query, []);
        $iid = Context::interlocutorId();
        $prefs = Db::all(
            "SELECT p.objetivo, p.intereses, p.minutos_semana
               FROM preferencia_formativa p
               JOIN usuario u ON u.id = p.usuario_id AND u.eliminado_en IS NULL AND u.perfil = 'EMPLEADO'
               JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id AND f.fecha_baja IS NULL
              WHERE u.interlocutor_id = ?",
            [$iid]
        );
        $personas = count($prefs);
        $desde = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $consultas = Db::one(
            "SELECT COUNT(*) AS total, COUNT(DISTINCT usuario_id) AS personas, SUM(util = 1) AS utiles, SUM(util IS NOT NULL) AS valoradas
               FROM solicitud_ia WHERE interlocutor_id = ? AND creado_en >= ?",
            [$iid, $desde]
        ) ?? [];
        $uso = [
            'consultas_30d' => (int) ($consultas['total'] ?? 0),
            'utilidad'      => (int) ($consultas['valoradas'] ?? 0) >= self::UMBRAL
                ? (int) round((int) $consultas['utiles'] / (int) $consultas['valoradas'] * 100) : null,
        ];
        if ($personas < self::UMBRAL) {
            Response::json(['disponible' => false, 'personas' => null, 'objetivos' => [], 'temas' => [], 'minutos' => [], 'uso' => $uso], 200, [
                'umbral' => self::UMBRAL,
                'aviso'  => ['code' => 'PH-WB-001', 'message' => 'Todavía no hay suficientes personas con preferencias para mostrar la demanda sin identificar a nadie.'],
            ]);
            return;
        }
        $objetivos = array_fill_keys(array_keys(AsesorService::OBJETIVOS), 0);
        $temas = array_fill_keys(CatalogoService::CATEGORIAS, 0);
        $minutos = [5 => 0, 15 => 0, 30 => 0];
        foreach ($prefs as $p) {
            $objetivos[$p['objetivo']] = ($objetivos[$p['objetivo']] ?? 0) + 1;
            foreach (json_decode((string) $p['intereses'], true) ?: [] as $t) {
                if (isset($temas[$t])) {
                    $temas[$t]++;
                }
            }
            $minutos[(int) $p['minutos_semana']] = ($minutos[(int) $p['minutos_semana']] ?? 0) + 1;
        }
        arsort($objetivos);
        arsort($temas);
        $lista = static fn (array $a, ?array $labels = null) => array_values(array_map(
            static fn ($k, $v) => ['clave' => (string) $k, 'etiqueta' => $labels[$k] ?? (string) $k, 'personas' => $v],
            array_keys($a), $a
        ));
        Response::json([
            'disponible' => true,
            'personas'   => $personas,
            'objetivos'  => $lista($objetivos, AsesorService::OBJETIVOS),
            'temas'      => $lista($temas),
            'minutos'    => $lista($minutos),
            'uso'        => $uso,
        ], 200, ['umbral' => self::UMBRAL]);
    }

    /* ------------------------------------------------------------------ Plataforma */

    /** GET /v1/admin/ai-usage?days=30 — uso del asesor por origen, avisos y empresas. Sin contenido. */
    public static function usage(Request $request): void
    {
        $q = Validator::validate($request->query, ['days' => 'nullable|int|min:1|max:90']);
        $dias = $q['days'] ?? 30;
        $desde = gmdate('Y-m-d H:i:s', time() - $dias * 86400);
        $tot = Db::one(
            "SELECT COUNT(*) AS total,
                    SUM(servido_desde = 'LIVE') AS live, SUM(servido_desde = 'CACHE') AS cache, SUM(servido_desde = 'REGLAS') AS reglas,
                    SUM(aviso IS NOT NULL) AS avisos, SUM(util = 1) AS utiles, SUM(util = 0) AS no_utiles,
                    SUM(COALESCE(tokens_entrada, 0)) AS tokens_entrada, SUM(COALESCE(tokens_salida, 0)) AS tokens_salida,
                    AVG(CASE WHEN servido_desde = 'LIVE' THEN latencia_ms END) AS latencia_media
               FROM solicitud_ia WHERE creado_en >= ?",
            [$desde]
        ) ?? [];
        $avisos = Db::all('SELECT aviso, COUNT(*) AS n FROM solicitud_ia WHERE creado_en >= ? AND aviso IS NOT NULL GROUP BY aviso ORDER BY n DESC', [$desde]);
        $empresas = Db::all(
            'SELECT i.id, i.nombre, COUNT(*) AS consultas, COUNT(DISTINCT s.usuario_id) AS personas
               FROM solicitud_ia s JOIN interlocutor i ON i.id = s.interlocutor_id
              WHERE s.creado_en >= ? GROUP BY i.id, i.nombre ORDER BY consultas DESC LIMIT 20',
            [$desde]
        );
        $gw = AiGateway::fromConfig();
        $int = static fn ($v) => (int) ($v ?? 0);
        Response::json([
            'proveedor'   => ['nombre' => $gw->provider(), 'modelo' => $gw->model(), 'modo' => $gw->mode(), 'configurado' => $gw->configured()],
            'consultas'   => $int($tot['total'] ?? 0),
            'por_origen'  => ['live' => $int($tot['live'] ?? 0), 'cache' => $int($tot['cache'] ?? 0), 'reglas' => $int($tot['reglas'] ?? 0)],
            'avisos'      => array_map(static fn ($r) => ['code' => $r['aviso'], 'total' => (int) $r['n']], $avisos),
            'valoracion'  => ['utiles' => $int($tot['utiles'] ?? 0), 'no_utiles' => $int($tot['no_utiles'] ?? 0)],
            'tokens'      => ['entrada' => $int($tot['tokens_entrada'] ?? 0), 'salida' => $int($tot['tokens_salida'] ?? 0)],
            'latencia_media_ms' => isset($tot['latencia_media']) ? (int) round((float) $tot['latencia_media']) : null,
            'cache'       => [
                'respuestas'   => (int) Db::value('SELECT COUNT(*) FROM respuesta_ia'),
                'pregeneradas' => (int) Db::value('SELECT COUNT(*) FROM respuesta_ia WHERE pregenerada = 1'),
            ],
            'empresas'    => array_map(static fn ($r) => ['id' => (int) $r['id'], 'nombre' => $r['nombre'], 'consultas' => (int) $r['consultas'], 'personas' => (int) $r['personas']], $empresas),
        ], 200, ['dias' => $dias]);
    }

    private static function opciones(): array
    {
        $kv = static fn (array $a) => array_map(static fn ($k, $v) => ['value' => $k, 'label' => $v], array_keys($a), $a);
        return [
            'objetivos' => $kv(AsesorService::OBJETIVOS),
            'temas'     => CatalogoService::CATEGORIAS,
            'formatos'  => $kv(AsesorService::FORMATOS),
            'minutos'   => AsesorService::MINUTOS,
        ];
    }
}
