<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Context;
use PymeHub\Core\Crypto;
use PymeHub\Core\RateLimiter;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Tiempo;
use PymeHub\Core\Validator;
use PymeHub\Repositories\CheckinRepository;
use PymeHub\Services\BienestarService;

/**
 * Check-in diario del empleado (§11, §8.6). Voluntario: no responder no tiene ninguna
 * consecuencia en ningún cálculo (RF-056, LEG-013).
 */
final class CheckinController
{
    /** Días hacia atrás que acepta un check-in hecho sin conexión y enviado después (RF-062). */
    private const DIAS_DIFERIDO = 3;

    /**
     * POST /v1/me/checkins
     * Idempotente por (persona, día): reenviar el mismo check-in desde la cola sin conexión
     * actualiza el registro del día en lugar de duplicarlo (RF-051, RF-062).
     */
    public static function store(Request $request): void
    {
        $uid = Context::usuarioId();
        $iid = Context::interlocutorId();
        RateLimiter::hit("checkin:$uid", 30, 3600);

        $in = Validator::validate($request->json(), [
            'animo'   => 'required|int|min:1|max:5',
            'energia' => 'required|int|min:1|max:5',
            'estres'  => 'required|int|min:1|max:5',
            'nota'    => 'nullable|string|max:500',
            'fecha'   => 'nullable|date',
        ]);
        $hoy = Tiempo::hoy();
        $fecha = $in['fecha'] ?? $hoy;
        if ($fecha > $hoy) {
            throw ApiException::validation(['fecha' => 'No puede ser futura']);
        }
        if ($fecha < Tiempo::dia(-self::DIAS_DIFERIDO)) {
            throw ApiException::validation(['fecha' => 'Solo se pueden enviar check-ins de los últimos ' . self::DIAS_DIFERIDO . ' días']);
        }

        $nota = isset($in['nota']) && $in['nota'] !== '' ? $in['nota'] : null;
        // LEG-003: la nota se cifra en reposo y solo la lee su autor
        $notaCifrada = $nota === null ? null : Crypto::encrypt($nota);
        $nuevo = CheckinRepository::guardar(
            $iid, $uid, CheckinRepository::equipoActual($uid, $iid),
            $fecha, (int) $in['animo'], (int) $in['energia'], (int) $in['estres'], $notaCifrada
        );
        // No se registra el contenido del check-in en ningún log (RNF-040)

        $row = CheckinRepository::delDia($uid, $iid, $fecha);
        $apoyo = BienestarService::necesitaApoyo((int) $in['animo'], (int) $in['energia'], (int) $in['estres'])
            ? BienestarService::recursosApoyo()
            : null;
        Response::json(['checkin' => self::present($row), 'actualizado' => !$nuevo, 'apoyo' => $apoyo], $nuevo ? 201 : 200);
    }

    /** GET /v1/me/checkins?days=30 — historial propio con sus notas (RF-052). */
    public static function index(Request $request): void
    {
        $q = Validator::validate($request->query, ['days' => 'nullable|int|min:7|max:90']);
        $dias = $q['days'] ?? 30;
        $uid = Context::usuarioId();
        $iid = Context::interlocutorId();
        $hoy = Tiempo::hoy();

        $rows = array_map([self::class, 'present'], CheckinRepository::propios($uid, $iid, Tiempo::dia(-($dias - 1))));
        $n = count($rows);
        $medias = $n === 0 ? null : [
            'animo'   => BienestarService::r(array_sum(array_column($rows, 'animo')) / $n),
            'energia' => BienestarService::r(array_sum(array_column($rows, 'energia')) / $n),
            'estres'  => BienestarService::r(array_sum(array_column($rows, 'estres')) / $n),
            'indice'  => (int) round(array_sum(array_column($rows, 'indice')) / $n),
        ];

        // Racha de días seguidos hasta hoy (o hasta ayer si hoy aún no ha respondido)
        $fechas = array_flip(array_column($rows, 'fecha'));
        $racha = 0;
        $cursor = isset($fechas[$hoy]) ? $hoy : Tiempo::dia(-1);
        while (isset($fechas[$cursor])) {
            $racha++;
            $cursor = Tiempo::sumarDias($cursor, -1);
        }

        Response::json($rows, 200, [
            'dias'      => $dias,
            'hoy'       => $hoy,
            'hecho_hoy' => isset($fechas[$hoy]),
            'respuestas' => $n,
            'medias'    => $medias,
            'racha'     => $racha,
        ]);
    }

    private static function present(array $r): array
    {
        return [
            'fecha'   => (string) $r['fecha'],
            'animo'   => (int) $r['animo'],
            'energia' => (int) $r['energia'],
            'estres'  => (int) $r['estres'],
            'indice'  => (int) round((float) $r['indice']),
            'nota'    => Crypto::decrypt($r['nota_cifrada']),
        ];
    }
}
