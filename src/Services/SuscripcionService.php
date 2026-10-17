<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\ApiException;
use PymeHub\Core\Db;
use PymeHub\Core\Logger;
use PymeHub\Core\Parametros;
use PymeHub\Core\Tiempo;
use PymeHub\Core\Vigencia;

/**
 * Suscripción vigente de una empresa: plan, ciclo y vigencia (fases 9b y 9c).
 * La suscripción guarda histórico: un cambio de plan o ciclo cierra la fila vigente y abre otra,
 * que hereda la vigencia y el enlace con Stripe.
 */
final class SuscripcionService
{
    public static function vigente(int $iid): ?array
    {
        return Db::one("SELECT * FROM suscripcion WHERE interlocutor_id = ? AND estado = 'VIGENTE'", [$iid]);
    }

    /** Empleados de alta (lo que se factura). */
    public static function empleadosActivos(int $iid): int
    {
        return (int) Db::value(
            "SELECT COUNT(*) FROM usuario u JOIN ficha_laboral f ON f.usuario_id = u.id
              WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL AND f.fecha_baja IS NULL",
            [$iid]
        );
    }

    /** Vigencia inicial de una empresa nueva: prueba gratuita de suscripcion.dias_prueba días. */
    public static function vigenciaInicial(bool $sinPrueba): array
    {
        return $sinPrueba || Vigencia::diasPrueba() === 0
            ? ['vigencia' => 'VIGENTE', 'periodo_fin' => null]
            : ['vigencia' => 'PRUEBA', 'periodo_fin' => Tiempo::dia(Vigencia::diasPrueba())];
    }

    /**
     * Cambia el plan o el ciclo: cierra la fila vigente y abre otra que conserva la vigencia,
     * los límites y el enlace con Stripe. Sin cambios reales, no hace nada.
     */
    public static function cambiarPlan(int $iid, string $plan, string $ciclo, array $limites = []): void
    {
        $cur = Db::one("SELECT * FROM suscripcion WHERE interlocutor_id = ? AND estado = 'VIGENTE' FOR UPDATE", [$iid]);
        if ($cur === null) {
            return;
        }
        if ($cur['plan'] === $plan && $cur['ciclo_cobro'] === $ciclo) {
            if ($limites !== []) {
                Db::run('UPDATE suscripcion SET limite_empleados = ?, cuota_ia_diaria = ? WHERE id = ?',
                    [$limites['limite_empleados'] ?? $cur['limite_empleados'], $limites['cuota_ia_diaria'] ?? $cur['cuota_ia_diaria'], $cur['id']]);
            }
            return;
        }
        Db::run("UPDATE suscripcion SET estado = 'FINALIZADA', fecha_fin = UTC_DATE() WHERE id = ?", [$cur['id']]);
        Db::insert(
            'INSERT INTO suscripcion (interlocutor_id, plan, ciclo_cobro, precio_empleado_mes, limite_empleados, cuota_ia_diaria, fecha_inicio,
                                      vigencia, periodo_fin, empleados_facturados, stripe_subscription_id, stripe_item_id, cancelar_al_final)
             VALUES (?, ?, ?, ?, ?, ?, UTC_DATE(), ?, ?, ?, ?, ?, ?)',
            [$iid, $plan, $ciclo, Parametros::precioMes($plan),
             $limites['limite_empleados'] ?? $cur['limite_empleados'], $limites['cuota_ia_diaria'] ?? $cur['cuota_ia_diaria'],
             $cur['vigencia'], $cur['periodo_fin'], $cur['empleados_facturados'], $cur['stripe_subscription_id'],
             $cur['stripe_item_id'], $cur['cancelar_al_final']]
        );
    }

    /**
     * La plataforma fija la vigencia a mano (renovación por transferencia, ampliación de la prueba
     * o acuerdo sin vencimiento).
     */
    public static function fijarVigencia(int $iid, ?string $periodoFin, string $vigencia): void
    {
        if (!in_array($vigencia, ['PRUEBA', 'VIGENTE'], true)) {
            throw ApiException::validation(['vigencia' => 'PRUEBA o VIGENTE']);
        }
        $n = Db::run("UPDATE suscripcion SET periodo_fin = ?, vigencia = ? WHERE interlocutor_id = ? AND estado = 'VIGENTE'",
            [$periodoFin, $vigencia, $iid]);
        if ($n === 0 && self::vigente($iid) === null) {
            throw ApiException::notFound();
        }
        self::recalcular($iid);
    }

    /** Guarda el estado efectivo de una empresa; devuelve [antes, después]. */
    public static function recalcular(int $iid): array
    {
        $s = self::vigente($iid);
        if ($s === null) {
            return [null, null];
        }
        $nuevo = Vigencia::calcular($s['periodo_fin'], (string) $s['vigencia']);
        if ($nuevo !== $s['vigencia']) {
            Db::run('UPDATE suscripcion SET vigencia = ? WHERE id = ?', [$nuevo, $s['id']]);
        }
        return [$s['vigencia'], $nuevo];
    }

    /**
     * Tarea diaria (cli/subscription_status.php): guarda la vigencia de todas las empresas y registra
     * los cambios en la auditoría. Devuelve los cambios.
     * @return list<array{interlocutor_id:int, antes:string, despues:string}>
     */
    public static function actualizarTodas(): array
    {
        $cambios = [];
        foreach (Db::all("SELECT interlocutor_id FROM suscripcion WHERE estado = 'VIGENTE' AND periodo_fin IS NOT NULL") as $r) {
            $iid = (int) $r['interlocutor_id'];
            [$antes, $despues] = self::recalcular($iid);
            if ($antes !== null && $antes !== $despues) {
                $cambios[] = ['interlocutor_id' => $iid, 'antes' => $antes, 'despues' => $despues];
                Db::run(
                    "INSERT INTO registro_auditoria (interlocutor_id, usuario_id, accion, entidad, entidad_id, ip)
                     VALUES (?, NULL, ?, 'interlocutor', ?, NULL)",
                    [$iid, 'suscripcion.' . strtolower((string) $despues), $iid]
                );
                Logger::info('Vigencia de la suscripción actualizada', ['interlocutor_id' => $iid, 'antes' => $antes, 'despues' => $despues]);
            }
        }
        return $cambios;
    }

    /** Resumen de la suscripción para la empresa (GET /v1/billing/subscription). */
    public static function resumen(int $iid): array
    {
        $s = self::vigente($iid);
        if ($s === null) {
            throw ApiException::notFound();
        }
        $i = Db::one('SELECT estado, stripe_customer_id, terminos_version, terminos_aceptados_en FROM interlocutor WHERE id = ?', [$iid]);
        $n = self::empleadosActivos($iid);
        $facturables = max(1, $n);
        return [
            'plan'               => $s['plan'],
            'ciclo_cobro'        => $s['ciclo_cobro'],
            'vigencia'           => Vigencia::resumen($s),
            'empleados_activos'  => $n,
            'empleados_facturados' => $s['empleados_facturados'] === null ? null : (int) $s['empleados_facturados'],
            'importes'           => [
                'MENSUAL' => Parametros::importe($s['plan'], 'MENSUAL', $facturables),
                'ANUAL'   => Parametros::importe($s['plan'], 'ANUAL', $facturables),
            ],
            'precios'            => [
                'ENTRADA'  => Parametros::precioMes('ENTRADA'),
                'COMPLETO' => Parametros::precioMes('COMPLETO'),
            ],
            'pago_online'        => [
                'disponible' => \PymeHub\Core\Stripe::habilitado(),
                'modo'       => 'PRUEBA',
                'cliente'    => $i['stripe_customer_id'] !== null,
                'suscripcion' => $s['stripe_subscription_id'] !== null,
            ],
            'terminos'           => [
                'version_vigente'  => Parametros::get('legal.version'),
                'version_aceptada' => $i['terminos_version'],
                'aceptados_en'     => $i['terminos_aceptados_en'],
            ],
        ];
    }

    /** Facturas y pagos de una empresa, del más reciente al más antiguo. */
    public static function pagos(?int $iid = null, int $limite = 100): array
    {
        $rows = Db::all(
            'SELECT p.*, i.nombre AS empresa FROM pago p JOIN interlocutor i ON i.id = p.interlocutor_id'
            . ($iid === null ? '' : ' WHERE p.interlocutor_id = ' . $iid)
            . ' ORDER BY p.creado_en DESC, p.id DESC LIMIT ' . max(1, min(500, $limite))
        );
        return array_map(static fn (array $p) => [
            'id' => (int) $p['id'], 'empresa_id' => (int) $p['interlocutor_id'], 'empresa' => $p['empresa'],
            'origen' => $p['origen'], 'numero' => $p['numero'], 'estado' => $p['estado'],
            'plan' => $p['plan'], 'ciclo_cobro' => $p['ciclo_cobro'], 'empleados' => $p['empleados'] === null ? null : (int) $p['empleados'],
            'base' => (float) $p['base'], 'iva' => (float) $p['iva'], 'total' => (float) $p['total'], 'moneda' => $p['moneda'],
            'periodo_inicio' => $p['periodo_inicio'], 'periodo_fin' => $p['periodo_fin'],
            'url_factura' => $p['url_factura'], 'url_pdf' => $p['url_pdf'], 'nota' => $p['nota'], 'creado_en' => $p['creado_en'],
        ], $rows);
    }
}
