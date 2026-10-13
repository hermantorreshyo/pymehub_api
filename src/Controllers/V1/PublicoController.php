<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Db;
use PymeHub\Core\Demo;
use PymeHub\Core\RateLimiter;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Tiempo;
use PymeHub\Core\Validator;

/**
 * Demostración comercial y métricas del QR del folleto (REQ_QR_DEMO_COMERCIAL).
 *
 * - Endpoints públicos sin sesión ni CSRF: no exponen datos internos ni credenciales (DMO-NF2).
 * - Se cuentan visitas, no visitantes: sin IP, sin User-Agent completo, sin cookies ni
 *   identificadores (DMO-031, DMO-032). La IP solo se usa, de paso, para limitar peticiones.
 */
final class PublicoController
{
    private const ZONA = 'Europe/Madrid';

    /** POST /v1/public/visits — una visita de campaña (DMO-030). */
    public static function visit(Request $request): void
    {
        RateLimiter::hit('visita:' . $request->ip, 30, 60);
        $in = Validator::validate($request->json(), [
            'campaign' => 'required|string|max:40',
            'source'   => 'required|string|max:40',
            'medium'   => 'required|string|max:40',
        ]);
        $errores = [];
        if (!in_array($in['campaign'], Demo::campanias(), true)) {
            $errores['campaign'] = 'Campaña no admitida';
        }
        foreach (['source', 'medium'] as $c) {
            if (!preg_match('/^[a-z0-9_-]{1,40}$/', $in[$c])) {
                $errores[$c] = 'Solo minúsculas, números, guion y guion bajo';
            }
        }
        if ($errores !== []) {
            throw ApiException::validation($errores);
        }
        Db::insert(
            'INSERT INTO visita_campania (campania, origen, medio, dispositivo, creado_en) VALUES (?, ?, ?, ?, UTC_TIMESTAMP())',
            [$in['campaign'], $in['source'], $in['medium'], Demo::dispositivo($request->header('user-agent'))]
        );
        Response::json(null, 204);
    }

    /** GET /v1/public/demo-status — ¿está disponible la demostración? (DMO-037) */
    public static function demoStatus(Request $request): void
    {
        Validator::validate($request->query, []);
        Response::json(['enabled' => Demo::habilitada()]);
    }

    /**
     * GET /v1/admin/campaign-metrics?campaign=&from=&to= (ADMIN, DMO-035)
     * Fechas en hora de España, ambas incluidas. Sin `from`, desde la primera visita.
     */
    public static function metrics(Request $request): void
    {
        $q = Validator::validate($request->query, [
            'campaign' => 'nullable|string|max:40',
            'from'     => 'nullable|date',
            'to'       => 'nullable|date',
        ]);
        $campania = $q['campaign'] ?? 'folleto_comercial';
        $hasta = $q['to'] ?? Tiempo::hoy();
        $desde = $q['from'] ?? null;
        if ($desde !== null && $desde > $hasta) {
            throw ApiException::validation(['from' => 'No puede ser posterior a «to»']);
        }
        $tz = new \DateTimeZone(self::ZONA);
        $utc = new \DateTimeZone('UTC');
        $aUtc = static fn (string $local) => (new \DateTimeImmutable($local, $tz))->setTimezone($utc)->format('Y-m-d H:i:s');
        $finUtc = $aUtc(Tiempo::sumarDias($hasta, 1) . ' 00:00:00');
        $iniUtc = $desde === null ? '2000-01-01 00:00:00' : $aUtc($desde . ' 00:00:00');

        $visitas = Db::all(
            'SELECT dispositivo, creado_en FROM visita_campania WHERE campania = ? AND creado_en >= ? AND creado_en < ? ORDER BY creado_en',
            [$campania, $iniUtc, $finUtc]
        );
        $porDispositivo = ['MOVIL' => 0, 'ESCRITORIO' => 0, 'OTRO' => 0];
        $porDia = [];
        $porHora = array_fill(0, 24, 0);
        $local = static fn (string $u) => (new \DateTimeImmutable($u, $utc))->setTimezone($tz);
        foreach ($visitas as $v) {
            $porDispositivo[$v['dispositivo']] = ($porDispositivo[$v['dispositivo']] ?? 0) + 1;
            $l = $local($v['creado_en']);
            $porDia[$l->format('Y-m-d')] = ($porDia[$l->format('Y-m-d')] ?? 0) + 1;
            $porHora[(int) $l->format('G')]++;
        }
        // Serie diaria continua (días sin visitas a 0), como mucho 92 días
        $serie = [];
        $primerDia = $desde ?? ($visitas === [] ? $hasta : $local($visitas[0]['creado_en'])->format('Y-m-d'));
        if ($primerDia < Tiempo::sumarDias($hasta, -91)) {
            $primerDia = Tiempo::sumarDias($hasta, -91);
        }
        for ($d = $primerDia; $d <= $hasta; $d = Tiempo::sumarDias($d, 1)) {
            $serie[] = ['fecha' => $d, 'visitas' => $porDia[$d] ?? 0];
        }

        $logins = ['GERENTE' => 0, 'RRHH' => 0, 'EMPLEADO' => 0];
        foreach (Db::all(
            "SELECT entidad AS perfil, COUNT(*) AS n FROM registro_auditoria
              WHERE accion = 'LOGIN_DEMO' AND creado_en >= ? AND creado_en < ? GROUP BY entidad",
            [$iniUtc, $finUtc]
        ) as $r) {
            $logins[$r['perfil']] = (int) $r['n'];
        }
        $iso = static fn (?string $u) => $u === null ? null : str_replace(' ', 'T', $u) . 'Z';

        Response::json([
            'campania'        => $campania,
            'visitas'         => count($visitas),
            'por_dispositivo' => $porDispositivo,
            'por_dia'         => $serie,
            'por_hora'        => array_map(static fn ($h, $n) => ['hora' => $h, 'visitas' => $n], array_keys($porHora), $porHora),
            'logins_demo'     => ['total' => array_sum($logins), 'por_perfil' => $logins],
            'primera_visita'  => $iso($visitas[0]['creado_en'] ?? null),
            'ultima_visita'   => $iso($visitas === [] ? null : $visitas[count($visitas) - 1]['creado_en']),
        ], 200, [
            'desde'     => $desde,
            'hasta'     => $hasta,
            'zona'      => self::ZONA,
            'campanias' => array_values(array_diff(Demo::campanias(), [Demo::CAMPANIA_PRUEBA])),
            'demo'      => ['habilitada' => Demo::habilitada()],
        ]);
    }
}
