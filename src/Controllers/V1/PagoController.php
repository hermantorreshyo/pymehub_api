<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Config;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\Logger;
use PymeHub\Core\Parametros;
use PymeHub\Core\RateLimiter;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Stripe;
use PymeHub\Core\Tiempo;
use PymeHub\Core\Validator;
use PymeHub\Services\PagoService;
use PymeHub\Services\SuscripcionService;

/**
 * Suscripción, pagos y facturas (fases 9b y 9c). Stripe solo en modo prueba.
 */
final class PagoController
{
    /** GET /v1/billing/subscription (GERENTE) */
    public static function subscription(Request $request): void
    {
        Validator::validate($request->query, []);
        Response::json(SuscripcionService::resumen(Context::interlocutorId()));
    }

    /** GET /v1/billing/invoices (GERENTE) */
    public static function invoices(Request $request): void
    {
        Validator::validate($request->query, []);
        $p = SuscripcionService::pagos(Context::interlocutorId());
        Response::json($p, 200, ['total' => count($p)]);
    }

    /** POST /v1/billing/checkout (GERENTE): { plan, ciclo, acepta_terminos: true } */
    public static function checkout(Request $request): void
    {
        RateLimiter::hit('checkout:' . Context::interlocutorId(), 10, 3600);
        $in = Validator::validate($request->json(), [
            'plan'            => 'required|in:ENTRADA,COMPLETO',
            'ciclo'           => 'required|in:MENSUAL,ANUAL',
            'acepta_terminos' => 'required|bool',
        ]);
        if ($in['acepta_terminos'] !== true) {
            throw ApiException::validation(['acepta_terminos' => 'Debes aceptar los términos y condiciones de contratación']);
        }
        $r = PagoService::checkout(Context::interlocutorId(), Context::usuarioId(), $in['plan'], $in['ciclo'], (string) (Context::$usuario['email'] ?? ''));
        Audit::log('pago.checkout_iniciado', 'interlocutor', Context::interlocutorId(), $request->ip);
        Response::json($r, 201);
    }

    /** POST /v1/billing/portal (GERENTE) */
    public static function portal(Request $request): void
    {
        Validator::validate($request->json(), []);
        Response::json(['url' => PagoService::portal(Context::interlocutorId())], 201);
    }

    /** POST /v1/stripe/webhook (Stripe, sin sesión): firma Stripe-Signature obligatoria. */
    public static function webhook(Request $request): void
    {
        $payload = (string) file_get_contents('php://input');
        if (!Stripe::firmaValida($payload, (string) $request->header('stripe-signature'), (string) Config::get('stripe.webhook_secret', ''))) {
            Logger::warn('Webhook de Stripe con firma no válida');
            throw new ApiException('PH-PAY-005', 'Firma no válida', 400);
        }
        $evento = json_decode($payload, true);
        if (!is_array($evento)) {
            throw new ApiException('PH-PAY-005', 'Evento no válido', 400);
        }
        // Un evento de modo real nunca se procesa en el MVP
        if (($evento['livemode'] ?? false) === true) {
            Response::json(['recibido' => true, 'resultado' => 'ignorado_livemode']);
            return;
        }
        Response::json(['recibido' => true, 'resultado' => PagoService::procesarEvento($evento)]);
    }

    /* ------------------------------------------------------------------ Plataforma */

    /** GET /v1/admin/payments?tenant= (ADMIN) */
    public static function adminPayments(Request $request): void
    {
        $q = Validator::validate($request->query, ['tenant' => 'nullable|int|min:1']);
        $p = SuscripcionService::pagos(isset($q['tenant']) ? (int) $q['tenant'] : null, 300);
        $cobrado = 0.0;
        foreach ($p as $x) {
            if ($x['estado'] === 'PAGADO') {
                $cobrado += $x['total'];
            }
        }
        Response::json($p, 200, ['total' => count($p), 'cobrado' => round($cobrado, 2), 'stripe' => ['habilitado' => Stripe::habilitado(), 'modo' => 'PRUEBA']]);
    }

    /**
     * PUT /v1/admin/tenants/{id}/validity (ADMIN): renovar, ampliar o quitar el vencimiento.
     *   { accion: AMPLIAR, dias }                  → suma días a periodo_fin (o a hoy si ya pasó)
     *   { accion: FIJAR, periodo_fin, vigencia }    → fecha exacta (PRUEBA o VIGENTE)
     *   { accion: SIN_VENCIMIENTO }                 → acuerdo sin fecha de fin
     *   pago_manual: { base, nota }                 → opcional: registra un pago fuera de Stripe con IVA
     */
    public static function adminValidity(Request $request): void
    {
        $id = $request->intParam('id');
        $body = $request->json();
        $pago = $body['pago_manual'] ?? null;
        unset($body['pago_manual']);
        $in = Validator::validate($body, [
            'accion'      => 'required|in:AMPLIAR,FIJAR,SIN_VENCIMIENTO',
            'dias'        => 'nullable|int|min:1|max:400',
            'periodo_fin' => 'nullable|date',
            'vigencia'    => 'nullable|in:PRUEBA,VIGENTE',
        ]);
        $s = SuscripcionService::vigente($id);
        if ($s === null || Db::value("SELECT 1 FROM interlocutor WHERE id = ? AND tipo = 'EMPRESA'", [$id]) === null) {
            throw ApiException::notFound();
        }
        $p = null;
        if ($pago !== null) {
            if (!is_array($pago)) {
                throw ApiException::validation(['pago_manual' => 'Objeto { base, nota }']);
            }
            $p = Validator::validate($pago, ['base' => 'required|numeric|min:0|max:100000', 'nota' => 'nullable|string|max:200']);
        }

        Db::transaction(static function () use ($id, $in, $s, $p): void {
            $inicio = $s['periodo_fin'] !== null && $s['periodo_fin'] >= Tiempo::hoy() ? $s['periodo_fin'] : Tiempo::dia(-1);
            match ($in['accion']) {
                'AMPLIAR' => SuscripcionService::fijarVigencia($id, Tiempo::sumarDias($inicio, (int) ($in['dias'] ?? 30)),
                    $s['vigencia'] === 'PRUEBA' ? 'PRUEBA' : 'VIGENTE'),
                'FIJAR' => SuscripcionService::fijarVigencia($id, $in['periodo_fin'] ?? throw ApiException::validation(['periodo_fin' => 'Obligatoria']),
                    $in['vigencia'] ?? 'VIGENTE'),
                default => SuscripcionService::fijarVigencia($id, null, 'VIGENTE'),
            };
            if ($p !== null) {
                $base = round((float) $p['base'], 2);
                $iva = round($base * Parametros::iva() / 100, 2);
                $nuevo = SuscripcionService::vigente($id);
                Db::insert(
                    "INSERT INTO pago (interlocutor_id, origen, numero, estado, plan, ciclo_cobro, empleados, base, iva, total, periodo_inicio, periodo_fin, nota)
                     VALUES (?, 'MANUAL', NULL, 'PAGADO', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                    [$id, $nuevo['plan'], $nuevo['ciclo_cobro'], SuscripcionService::empleadosActivos($id), $base, $iva, round($base + $iva, 2),
                     Tiempo::hoy(), $nuevo['periodo_fin'], $p['nota'] ?? null]
                );
            }
        });
        Audit::log('admin.vigencia_' . strtolower($in['accion']), 'interlocutor', $id, $request->ip);
        Response::json(\PymeHub\Core\Vigencia::resumen(SuscripcionService::vigente($id)));
    }
}
