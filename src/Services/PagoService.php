<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\ApiException;
use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Core\Logger;
use PymeHub\Core\Parametros;
use PymeHub\Core\Stripe;
use PymeHub\Core\Tiempo;

/**
 * Pagos de la suscripción con Stripe en modo prueba (fase 9c).
 *
 * - Checkout: suscripción por empleado activo, precio sin IVA + IVA (tax rate exclusivo del 21 %
 *   del parámetro precios.iva_pct), pago mensual o anual con el descuento parametrizado.
 * - Portal del cliente: tarjeta, facturas y cancelación (configurado en el panel de Stripe).
 * - Webhooks firmados e idempotentes (evento_stripe) que mueven la vigencia (fase 9b).
 * - Sincronización diaria de la cantidad de empleados.
 *
 * Los webhooks se leen de forma defensiva: los campos cambian entre versiones de la API de Stripe
 * (p. ej. invoice.subscription → invoice.parent.subscription_details.subscription).
 */
final class PagoService
{
    /* ------------------------------------------------------------------ Checkout y portal */

    /** Crea la sesión de Checkout y registra la aceptación de los términos. */
    public static function checkout(int $iid, int $uid, string $plan, string $ciclo, string $email): array
    {
        Stripe::exigir();
        $s = SuscripcionService::vigente($iid);
        $i = Db::one('SELECT id, nombre, nif, email_contacto, stripe_customer_id, estado FROM interlocutor WHERE id = ?', [$iid]);
        if ($s === null || $i === null) {
            throw ApiException::notFound();
        }
        if ($s['stripe_subscription_id'] !== null) {
            throw new ApiException('PH-PAY-003', 'La empresa ya tiene el pago online activo. Gestiona los cambios desde «Gestionar el pago».', 409);
        }

        Db::run('UPDATE interlocutor SET terminos_version = ?, terminos_aceptados_en = UTC_TIMESTAMP(), terminos_aceptados_por = ? WHERE id = ?',
            [(string) Parametros::get('legal.version', ''), $uid, $iid]);

        $cliente = $i['stripe_customer_id'] ?? null;
        if ($cliente === null) {
            $c = Stripe::api('POST', '/v1/customers', [
                'name'     => $i['nombre'],
                'email'    => $i['email_contacto'] ?: $email,
                'metadata' => ['interlocutor_id' => (string) $iid, 'nif' => (string) $i['nif']],
                'preferred_locales' => ['es'],
            ], 'pymehub-cliente-' . $iid);
            $cliente = (string) $c['id'];
            Db::run('UPDATE interlocutor SET stripe_customer_id = ? WHERE id = ?', [$cliente, $iid]);
        }

        $empleados = max(1, SuscripcionService::empleadosActivos($iid));
        $unidad = Parametros::importe($plan, $ciclo, 1); // base por empleado y periodo, ya con el descuento anual
        $web = rtrim((string) Config::get('app.frontend_url', ''), '/');
        $meta = ['interlocutor_id' => (string) $iid, 'plan' => $plan, 'ciclo' => $ciclo];
        $params = [
            'mode'                => 'subscription',
            'customer'            => $cliente,
            'client_reference_id' => (string) $iid,
            'locale'              => 'es',
            'line_items'          => [[
                'quantity'   => $empleados,
                'tax_rates'  => [self::tasaIva()],
                'price_data' => [
                    'currency'     => 'eur',
                    'unit_amount'  => (int) round($unidad['base'] * 100),
                    'recurring'    => ['interval' => $ciclo === 'ANUAL' ? 'year' : 'month'],
                    'product_data' => ['name' => 'Pyme Hub · Plan ' . ($plan === 'COMPLETO' ? 'Completo' : 'Entrada') . ' (' . ($ciclo === 'ANUAL' ? 'anual' : 'mensual') . ', por empleado)'],
                ],
            ]],
            'tax_id_collection'   => ['enabled' => 'true'],
            'customer_update'     => ['name' => 'auto', 'address' => 'auto'],
            'billing_address_collection' => 'required',
            'metadata'            => $meta,
            'subscription_data'   => ['metadata' => $meta],
            'success_url'         => $web . '/?pago=ok#/suscripcion',
            'cancel_url'          => $web . '/?pago=cancelado#/suscripcion',
        ];
        // Durante la prueba gratuita no se cobra hasta su último día (Stripe exige 48 h de margen)
        if ($s['vigencia'] === 'PRUEBA' && $s['periodo_fin'] !== null) {
            $fin = (new \DateTimeImmutable($s['periodo_fin'] . ' 23:00:00', new \DateTimeZone(Tiempo::ZONA)))->getTimestamp();
            if ($fin > time() + 2 * 86400 + 600) {
                $params['subscription_data']['trial_end'] = $fin;
            }
        }
        $sesion = Stripe::api('POST', '/v1/checkout/sessions', $params);
        return [
            'url'     => (string) $sesion['url'],
            'id'      => (string) $sesion['id'],
            'importe' => Parametros::importe($plan, $ciclo, $empleados),
            'cobro_al_terminar_prueba' => isset($params['subscription_data']['trial_end']),
        ];
    }

    /** Sesión del portal del cliente de Stripe (tarjeta, facturas, cancelación). */
    public static function portal(int $iid): string
    {
        Stripe::exigir();
        $cliente = Db::value('SELECT stripe_customer_id FROM interlocutor WHERE id = ?', [$iid]);
        if ($cliente === null) {
            throw new ApiException('PH-PAY-004', 'Todavía no hay un pago online para esta empresa', 409);
        }
        $web = rtrim((string) Config::get('app.frontend_url', ''), '/');
        $p = Stripe::api('POST', '/v1/billing_portal/sessions', ['customer' => $cliente, 'return_url' => $web . '/#/suscripcion', 'locale' => 'es']);
        return (string) $p['url'];
    }

    /**
     * Tax rate de Stripe con el IVA vigente (España); se crea una vez por porcentaje y tipo.
     * Exclusivo: precio sin IVA (empresas). Inclusivo: precio con IVA incluido (consumidores, Skills).
     */
    public static function tasaIva(bool $inclusiva = false): string
    {
        $pct = Parametros::iva();
        $clave = 'stripe.tax_rate' . ($inclusiva ? '_incl.' : '.') . str_replace('.', '_', (string) $pct);
        $id = Parametros::get($clave);
        if (is_string($id) && $id !== '') {
            return $id;
        }
        $t = Stripe::api('POST', '/v1/tax_rates', [
            'display_name' => 'IVA', 'description' => 'IVA España ' . $pct . ' %', 'percentage' => $pct,
            'inclusive' => $inclusiva ? 'true' : 'false', 'country' => 'ES', 'jurisdiction' => 'ES', 'tax_type' => 'vat',
        ], 'pymehub-iva-' . ($inclusiva ? 'incl-' : '') . $pct);
        Db::run('INSERT INTO parametro (clave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = ?',
            [$clave, $j = json_encode((string) $t['id']), $j]);
        return (string) $t['id'];
    }

    /* ------------------------------------------------------------------ Webhooks */

    /**
     * Procesa un evento ya verificado. Idempotente: un evento repetido devuelve 'duplicado'.
     * Si el proceso falla, se olvida el evento para que Stripe lo reintente.
     */
    public static function procesarEvento(array $evento): string
    {
        $id = (string) ($evento['id'] ?? '');
        $tipo = (string) ($evento['type'] ?? '');
        $obj = $evento['data']['object'] ?? null;
        if ($id === '' || $tipo === '' || !is_array($obj)) {
            throw ApiException::validation(['evento' => 'Evento de Stripe no válido']);
        }
        if (Db::run('INSERT IGNORE INTO evento_stripe (id, tipo, resultado) VALUES (?, ?, ?)', [$id, $tipo, 'PROCESANDO']) === 0) {
            return 'duplicado';
        }
        try {
            $resultado = Db::transaction(static fn (): string => match ($tipo) {
                'checkout.session.completed'    => isset($obj['metadata']['compra_skills_id'])
                    ? SkillsService::compraPagada($obj) : self::checkoutCompletado($obj),
                'checkout.session.expired'      => isset($obj['metadata']['compra_skills_id'])
                    ? (Db::run("UPDATE compra_skills SET estado = 'FALLIDA' WHERE id = ? AND estado = 'PENDIENTE'", [(int) $obj['metadata']['compra_skills_id']]) > 0 ? 'ok' : 'ignorado')
                    : 'ignorado',
                'account.updated'               => SkillsService::cuentaActualizada($obj),
                'invoice.paid'                  => self::factura($obj, 'PAGADO'),
                'invoice.payment_failed'        => self::factura($obj, 'FALLIDO'),
                'customer.subscription.updated',
                'customer.subscription.created' => self::suscripcionActualizada($obj),
                'customer.subscription.deleted' => self::suscripcionCancelada($obj),
                default                         => 'ignorado',
            });
        } catch (\Throwable $e) {
            Db::run('DELETE FROM evento_stripe WHERE id = ?', [$id]);
            throw $e;
        }
        Db::run('UPDATE evento_stripe SET resultado = ? WHERE id = ?', [$resultado, $id]);
        Logger::info('Evento de Stripe', ['tipo' => $tipo, 'resultado' => $resultado]);
        return $resultado;
    }

    private static function empresaPorCliente(?string $cliente, ?string $referencia = null): ?int
    {
        if ($cliente !== null && $cliente !== '') {
            $iid = Db::value("SELECT id FROM interlocutor WHERE stripe_customer_id = ? AND tipo = 'EMPRESA'", [$cliente]);
            if ($iid !== null) {
                return (int) $iid;
            }
        }
        if ($referencia !== null && ctype_digit($referencia)) {
            $iid = Db::value("SELECT id FROM interlocutor WHERE id = ? AND tipo = 'EMPRESA' AND (stripe_customer_id IS NULL OR stripe_customer_id = ?)",
                [(int) $referencia, (string) $cliente]);
            return $iid === null ? null : (int) $iid;
        }
        return null;
    }

    private static function checkoutCompletado(array $o): string
    {
        $iid = self::empresaPorCliente($o['customer'] ?? null, isset($o['client_reference_id']) ? (string) $o['client_reference_id'] : null);
        if ($iid === null || ($o['mode'] ?? '') !== 'subscription') {
            return 'ignorado';
        }
        Db::run('UPDATE interlocutor SET stripe_customer_id = COALESCE(stripe_customer_id, ?) WHERE id = ?', [$o['customer'] ?? null, $iid]);
        $plan = $o['metadata']['plan'] ?? null;
        $ciclo = $o['metadata']['ciclo'] ?? null;
        if (in_array($plan, ['ENTRADA', 'COMPLETO'], true) && in_array($ciclo, ['MENSUAL', 'ANUAL'], true)) {
            SuscripcionService::cambiarPlan($iid, $plan, $ciclo);
        }
        $subId = is_string($o['subscription'] ?? null) ? $o['subscription'] : ($o['subscription']['id'] ?? null);
        if ($subId !== null) {
            Db::run("UPDATE suscripcion SET stripe_subscription_id = ? WHERE interlocutor_id = ? AND estado = 'VIGENTE'", [$subId, $iid]);
            // Datos de la suscripción (cantidad, prueba y fin del periodo)
            self::suscripcionActualizada(Stripe::api('GET', '/v1/subscriptions/' . rawurlencode($subId)), $iid);
        }
        \PymeHub\Core\Db::run(
            "INSERT INTO registro_auditoria (interlocutor_id, usuario_id, accion, entidad, entidad_id, ip) VALUES (?, NULL, 'pago.checkout_completado', 'interlocutor', ?, NULL)",
            [$iid, $iid]
        );
        return 'ok';
    }

    private static function factura(array $o, string $estado): string
    {
        $iid = self::empresaPorCliente($o['customer'] ?? null);
        if ($iid === null) {
            return 'sin_empresa';
        }
        $total = Stripe::euros($o['total'] ?? 0);
        // Factura de 0 € al empezar una prueba: no es un pago
        if ($estado === 'PAGADO' && $total == 0.0 && ($o['billing_reason'] ?? '') === 'subscription_create') {
            return 'ignorado';
        }
        $linea = $o['lines']['data'][0] ?? [];
        $base = Stripe::euros($o['total_excluding_tax'] ?? $o['subtotal_excluding_tax'] ?? $o['subtotal'] ?? 0);
        $ini = isset($linea['period']['start']) ? self::fecha((int) $linea['period']['start']) : null;
        $fin = isset($linea['period']['end']) ? self::fecha((int) $linea['period']['end'] - 1) : null;
        $s = SuscripcionService::vigente($iid);
        Db::run(
            'INSERT INTO pago (interlocutor_id, origen, stripe_invoice_id, numero, estado, plan, ciclo_cobro, empleados, base, iva, total, moneda,
                               periodo_inicio, periodo_fin, url_factura, url_pdf)
             VALUES (?, \'STRIPE\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE estado = ?, numero = ?, url_factura = ?, url_pdf = ?',
            [$iid, (string) $o['id'], $o['number'] ?? null, $estado, $s['plan'] ?? null, $s['ciclo_cobro'] ?? null,
             isset($linea['quantity']) ? (int) $linea['quantity'] : null, $base, round($total - $base, 2), $total,
             strtoupper((string) ($o['currency'] ?? 'eur')), $ini, $fin, $o['hosted_invoice_url'] ?? null, $o['invoice_pdf'] ?? null,
             $estado, $o['number'] ?? null, $o['hosted_invoice_url'] ?? null, $o['invoice_pdf'] ?? null]
        );
        if ($estado === 'PAGADO' && $fin !== null && $s !== null) {
            // Pagado hasta el último día del periodo: la empresa pasa a VIGENTE (fase 9b)
            Db::run(
                "UPDATE suscripcion SET vigencia = 'VIGENTE', periodo_fin = GREATEST(COALESCE(periodo_fin, ?), ?),
                        stripe_subscription_id = COALESCE(stripe_subscription_id, ?)
                  WHERE id = ?",
                [$fin, $fin, self::suscripcionDeFactura($o), $s['id']]
            );
            Db::run("UPDATE interlocutor SET estado = 'ACTIVO' WHERE id = ? AND estado = 'PILOTO'", [$iid]);
        }
        Db::run(
            "INSERT INTO registro_auditoria (interlocutor_id, usuario_id, accion, entidad, entidad_id, ip) VALUES (?, NULL, ?, 'interlocutor', ?, NULL)",
            [$iid, $estado === 'PAGADO' ? 'pago.cobrado' : 'pago.fallido', $iid]
        );
        return 'ok';
    }

    private static function suscripcionDeFactura(array $o): ?string
    {
        $v = $o['subscription'] ?? $o['parent']['subscription_details']['subscription'] ?? $o['lines']['data'][0]['subscription'] ?? null;
        return is_string($v) ? $v : (is_array($v) ? ($v['id'] ?? null) : null);
    }

    private static function suscripcionActualizada(array $o, ?int $iid = null): string
    {
        $iid ??= self::empresaPorCliente($o['customer'] ?? null)
            ?? (isset($o['metadata']['interlocutor_id']) ? self::empresaPorCliente(null, (string) $o['metadata']['interlocutor_id']) : null);
        if ($iid === null) {
            return 'sin_empresa';
        }
        $item = $o['items']['data'][0] ?? [];
        $sets = ['stripe_subscription_id = ?', 'cancelar_al_final = ?'];
        $params = [(string) $o['id'], !empty($o['cancel_at_period_end']) ? 1 : 0];
        if (isset($item['id'])) {
            $sets[] = 'stripe_item_id = ?';
            $params[] = (string) $item['id'];
        }
        if (isset($item['quantity'])) {
            $sets[] = 'empleados_facturados = ?';
            $params[] = (int) $item['quantity'];
        }
        if (($o['status'] ?? '') === 'trialing' && isset($o['trial_end'])) {
            $sets[] = "vigencia = 'PRUEBA'";
            $sets[] = 'periodo_fin = ?';
            $params[] = self::fecha((int) $o['trial_end'] - 1);
        }
        $params[] = $iid;
        Db::run('UPDATE suscripcion SET ' . implode(', ', $sets) . " WHERE interlocutor_id = ? AND estado = 'VIGENTE'", $params);
        return 'ok';
    }

    private static function suscripcionCancelada(array $o): string
    {
        $n = Db::run(
            "UPDATE suscripcion SET stripe_subscription_id = NULL, stripe_item_id = NULL, cancelar_al_final = 0
              WHERE stripe_subscription_id = ? AND estado = 'VIGENTE'",
            [(string) ($o['id'] ?? '')]
        );
        // La vigencia sigue hasta periodo_fin; después, gracia y solo lectura
        return $n > 0 ? 'ok' : 'sin_empresa';
    }

    private static function fecha(int $ts): string
    {
        return (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(Tiempo::ZONA))->format('Y-m-d');
    }

    /* ------------------------------------------------------------------ Sincronización diaria */

    /**
     * Ajusta en Stripe la cantidad de empleados de cada suscripción enlazada a los empleados
     * activos. Sin prorrateo: la nueva cantidad se cobra en la siguiente renovación.
     * @return list<array{interlocutor_id:int, antes:int|null, despues:int}>
     */
    public static function sincronizarCantidades(): array
    {
        if (!Stripe::habilitado()) {
            return [];
        }
        $cambios = [];
        foreach (Db::all("SELECT interlocutor_id, stripe_subscription_id, stripe_item_id, empleados_facturados FROM suscripcion
                           WHERE estado = 'VIGENTE' AND stripe_item_id IS NOT NULL") as $s) {
            $iid = (int) $s['interlocutor_id'];
            $n = max(1, SuscripcionService::empleadosActivos($iid));
            if ($s['empleados_facturados'] !== null && (int) $s['empleados_facturados'] === $n) {
                continue;
            }
            try {
                Stripe::api('POST', '/v1/subscriptions/' . rawurlencode((string) $s['stripe_subscription_id']), [
                    'items' => [['id' => $s['stripe_item_id'], 'quantity' => $n]],
                    'proration_behavior' => 'none',
                ], 'pymehub-cantidad-' . $iid . '-' . $n . '-' . Tiempo::hoy());
                Db::run("UPDATE suscripcion SET empleados_facturados = ? WHERE interlocutor_id = ? AND estado = 'VIGENTE'", [$n, $iid]);
                $cambios[] = ['interlocutor_id' => $iid, 'antes' => $s['empleados_facturados'] === null ? null : (int) $s['empleados_facturados'], 'despues' => $n];
            } catch (ApiException $e) {
                Logger::warn('No se pudo sincronizar la cantidad con Stripe', ['interlocutor_id' => $iid, 'code' => $e->getMessage()]);
            }
        }
        return $cambios;
    }
}
