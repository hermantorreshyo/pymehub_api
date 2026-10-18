<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\ApiException;
use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Core\Logger;
use PymeHub\Core\Parametros;
use PymeHub\Core\Stripe;
use PymeHub\Repositories\AsignacionRepository;

/**
 * Marketplace Pyme Hub Skills (fase 9d), con Stripe Connect en modo prueba.
 *
 * - Un curso es un modulo_formativo de un formador (interlocutor FORMADOR) con su ficha comercial
 *   en curso_skills. Solo se vende tras la aprobación de la plataforma.
 * - Empresa: licencia para toda la plantilla, precio sin IVA (+ IVA). Persona: compra para sí
 *   misma, precio con IVA incluido (consumidor) y renuncia al desistimiento al empezar (art. 103.m TRLGDCU).
 * - Comisión de la plataforma (marketplace.comision_pct) sobre la base; el formador recibe el resto
 *   por Stripe Connect (cargo con destino). La plataforma conserva la comisión y el IVA.
 * - Exención de IVA de la formación: parámetro marketplace.formacion_exenta_iva (lo confirma la gestoría).
 */
final class SkillsService
{
    public static function comisionPct(): float
    {
        return Parametros::float('marketplace.comision_pct', 20.0);
    }

    public static function ivaPct(): float
    {
        return Parametros::get('marketplace.formacion_exenta_iva') === true ? 0.0 : Parametros::iva();
    }

    /**
     * Importe de una compra.
     * EMPRESA: base = precio sin IVA, total = base + IVA. PERSONA: total = precio con IVA, base = total / (1 + IVA).
     */
    public static function importe(array $curso, string $tipo): array
    {
        $iva = self::ivaPct();
        if ($tipo === 'EMPRESA') {
            $base = round((float) $curso['precio_empresa'], 2);
            $total = round($base * (1 + $iva / 100), 2);
        } else {
            $total = round((float) $curso['precio_persona'], 2);
            $base = round($total / (1 + $iva / 100), 2);
        }
        $comision = round($base * self::comisionPct() / 100, 2);
        return [
            'tipo' => $tipo, 'base' => $base, 'iva_pct' => $iva, 'iva' => round($total - $base, 2), 'total' => $total,
            'iva_incluido' => $tipo === 'PERSONA', 'comision_pct' => self::comisionPct(), 'comision' => $comision,
            'importe_formador' => round($base - $comision, 2), 'moneda' => 'EUR',
        ];
    }

    /* ------------------------------------------------------------------ Catálogo */

    private const CURSO = "SELECT m.id, m.titulo, m.resumen, m.categoria, m.nivel, m.duracion_min, m.contenido, m.puestos_destino,
                                  m.estado AS modulo_estado, c.precio_empresa, c.precio_persona, c.estado, c.motivo, c.enviado_en, c.revisado_en,
                                  f.id AS formador_id, f.nombre AS formador, f.nombre_comercial AS formador_comercial, f.stripe_cuenta_lista
                             FROM curso_skills c
                             JOIN modulo_formativo m ON m.id = c.modulo_id AND m.eliminado_en IS NULL
                             JOIN interlocutor f ON f.id = c.interlocutor_id AND f.tipo = 'FORMADOR'";

    public static function presentar(array $r, bool $conPrecios = true): array
    {
        $contenido = json_decode((string) $r['contenido'], true) ?: [];
        $c = [
            'id' => (int) $r['id'], 'titulo' => $r['titulo'], 'resumen' => $r['resumen'], 'categoria' => $r['categoria'],
            'nivel' => $r['nivel'], 'duracion_min' => (int) $r['duracion_min'],
            'puestos_destino' => json_decode((string) $r['puestos_destino'], true) ?: [],
            'objetivos' => array_values($contenido['objetivos'] ?? []), 'bloques' => count($contenido['bloques'] ?? []),
            'formador' => ['id' => (int) $r['formador_id'], 'nombre' => $r['formador_comercial'] ?: $r['formador'],
                'cobra_online' => (bool) $r['stripe_cuenta_lista']],
            'estado' => $r['estado'], 'modulo_estado' => $r['modulo_estado'],
        ];
        if ($conPrecios) {
            $c['precio_empresa'] = (float) $r['precio_empresa'];
            $c['precio_persona'] = (float) $r['precio_persona'];
            $c['importes'] = ['EMPRESA' => self::importe($r, 'EMPRESA'), 'PERSONA' => self::importe($r, 'PERSONA')];
        }
        return $c;
    }

    /** Cursos aprobados, con lo que ya tiene la empresa o la persona. */
    public static function catalogo(int $iid, int $uid): array
    {
        $rows = Db::all(self::CURSO . " WHERE c.estado = 'APROBADO' AND m.estado = 'PUBLICADO' ORDER BY m.titulo");
        $empresa = array_flip(array_map('intval', array_column(Db::all(
            "SELECT modulo_id FROM compra_skills WHERE interlocutor_id = ? AND tipo = 'EMPRESA' AND estado = 'PAGADA'", [$iid]), 'modulo_id')));
        $propia = array_flip(array_map('intval', array_column(Db::all(
            "SELECT modulo_id FROM compra_skills WHERE usuario_id = ? AND tipo = 'PERSONA' AND estado = 'PAGADA'", [$uid]), 'modulo_id')));
        return array_map(static function (array $r) use ($empresa, $propia): array {
            $c = self::presentar($r);
            $c['licencia_empresa'] = isset($empresa[(int) $r['id']]);
            $c['comprado_por_mi'] = isset($propia[(int) $r['id']]);
            return $c;
        }, $rows);
    }

    public static function curso(int $moduloId, ?string $estado = null): array
    {
        $r = Db::one(self::CURSO . ' WHERE m.id = ?' . ($estado === null ? '' : ' AND c.estado = ?'),
            $estado === null ? [$moduloId] : [$moduloId, $estado]);
        if ($r === null) {
            throw ApiException::notFound();
        }
        return $r;
    }

    /* ------------------------------------------------------------------ Formador */

    /** Cursos del formador: sus módulos con la ficha comercial (si la tienen) y las ventas. */
    public static function misCursos(int $fid): array
    {
        $rows = Db::all(
            "SELECT m.id, m.titulo, m.resumen, m.categoria, m.nivel, m.duracion_min, m.estado AS modulo_estado, m.actualizado_en,
                    c.precio_empresa, c.precio_persona, c.estado, c.motivo,
                    (SELECT COUNT(*) FROM compra_skills v WHERE v.modulo_id = m.id AND v.estado = 'PAGADA') AS ventas,
                    (SELECT COALESCE(SUM(v.importe_formador), 0) FROM compra_skills v WHERE v.modulo_id = m.id AND v.estado = 'PAGADA') AS ingresos
               FROM modulo_formativo m
               LEFT JOIN curso_skills c ON c.modulo_id = m.id
              WHERE m.interlocutor_id = ? AND m.eliminado_en IS NULL AND m.estado <> 'ARCHIVADO'
              ORDER BY m.actualizado_en DESC",
            [$fid]
        );
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'titulo' => $r['titulo'], 'resumen' => $r['resumen'], 'categoria' => $r['categoria'],
            'nivel' => $r['nivel'], 'duracion_min' => (int) $r['duracion_min'], 'modulo_estado' => $r['modulo_estado'],
            'estado' => $r['estado'] ?? 'SIN_PRECIO', 'motivo' => $r['motivo'],
            'precio_empresa' => $r['precio_empresa'] === null ? null : (float) $r['precio_empresa'],
            'precio_persona' => $r['precio_persona'] === null ? null : (float) $r['precio_persona'],
            'ventas' => (int) $r['ventas'], 'ingresos' => round((float) $r['ingresos'], 2), 'actualizado_en' => $r['actualizado_en'],
        ], $rows);
    }

    public static function moduloDelFormador(int $moduloId, int $fid): array
    {
        $m = Db::one('SELECT id, estado FROM modulo_formativo WHERE id = ? AND interlocutor_id = ? AND eliminado_en IS NULL', [$moduloId, $fid]);
        if ($m === null) {
            throw ApiException::notFound();
        }
        return $m;
    }

    public static function ficha(int $moduloId): ?array
    {
        return Db::one('SELECT * FROM curso_skills WHERE modulo_id = ?', [$moduloId]);
    }

    /** Precios del curso. Un curso ya aprobado conserva la aprobación. */
    public static function guardarPrecios(int $moduloId, int $fid, float $empresa, float $persona): void
    {
        self::moduloDelFormador($moduloId, $fid);
        Db::run(
            "INSERT INTO curso_skills (modulo_id, interlocutor_id, precio_empresa, precio_persona) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE precio_empresa = ?, precio_persona = ?",
            [$moduloId, $fid, $empresa, $persona, $empresa, $persona]
        );
    }

    /** Envía el curso a revisión: módulo publicado, precios fijados. */
    public static function enviarRevision(int $moduloId, int $fid): void
    {
        $m = self::moduloDelFormador($moduloId, $fid);
        $f = self::ficha($moduloId);
        $errores = [];
        if ($m['estado'] !== 'PUBLICADO') {
            $errores['modulo'] = 'Publica el curso (contenido y cuestionario completos) antes de enviarlo a revisión';
        }
        if ($f === null || (float) $f['precio_empresa'] <= 0 || (float) $f['precio_persona'] <= 0) {
            $errores['precios'] = 'Fija el precio para empresas y para particulares';
        }
        if ($errores !== []) {
            throw ApiException::validation($errores);
        }
        if ($f['estado'] === 'APROBADO') {
            return;
        }
        Db::run("UPDATE curso_skills SET estado = 'PENDIENTE', enviado_en = UTC_TIMESTAMP(), motivo = NULL WHERE modulo_id = ?", [$moduloId]);
    }

    /** Un cambio de contenido en un curso aprobado vuelve a revisión (las compras hechas se conservan). */
    public static function cambioDeContenido(int $moduloId): void
    {
        Db::run("UPDATE curso_skills SET estado = 'PENDIENTE', enviado_en = UTC_TIMESTAMP() WHERE modulo_id = ? AND estado = 'APROBADO'", [$moduloId]);
    }

    public static function revisar(int $moduloId, string $decision, ?string $motivo, int $uid): void
    {
        $f = self::ficha($moduloId);
        if ($f === null) {
            throw ApiException::notFound();
        }
        if ($decision === 'RECHAZAR' && trim((string) $motivo) === '') {
            throw ApiException::validation(['motivo' => 'Explica al formador qué debe cambiar']);
        }
        $estado = ['APROBAR' => 'APROBADO', 'RECHAZAR' => 'RECHAZADO', 'RETIRAR' => 'RETIRADO'][$decision];
        Db::run('UPDATE curso_skills SET estado = ?, motivo = ?, revisado_en = UTC_TIMESTAMP(), revisado_por = ? WHERE modulo_id = ?',
            [$estado, $decision === 'APROBAR' ? null : $motivo, $uid, $moduloId]);
    }

    /* ------------------------------------------------------------------ Compras */

    /**
     * Crea la compra pendiente y la sesión de Checkout (pago único) con cargo a la cuenta del formador.
     * @return array{url:string, compra_id:int, importe:array}
     */
    public static function comprar(int $moduloId, string $tipo, int $iid, int $uid, bool $renuncia): array
    {
        Stripe::exigir();
        $r = self::curso($moduloId, 'APROBADO');
        if ($r['modulo_estado'] !== 'PUBLICADO') {
            throw ApiException::notFound();
        }
        $ya = $tipo === 'EMPRESA'
            ? Db::value("SELECT 1 FROM compra_skills WHERE modulo_id = ? AND interlocutor_id = ? AND tipo = 'EMPRESA' AND estado = 'PAGADA'", [$moduloId, $iid])
            : Db::value("SELECT 1 FROM compra_skills WHERE modulo_id = ? AND interlocutor_id = ? AND estado = 'PAGADA' AND (tipo = 'EMPRESA' OR usuario_id = ?)", [$moduloId, $iid, $uid]);
        if ($ya !== null) {
            throw new ApiException('PH-SKL-003', 'Este curso ya está disponible para ti', 409);
        }
        $cuenta = Db::one('SELECT stripe_cuenta_id, stripe_cuenta_lista FROM interlocutor WHERE id = ?', [(int) $r['formador_id']]);
        if ($cuenta === null || !(int) $cuenta['stripe_cuenta_lista'] || $cuenta['stripe_cuenta_id'] === null) {
            throw new ApiException('PH-SKL-002', 'El formador todavía no puede cobrar online. Prueba más adelante.', 409);
        }
        $imp = self::importe($r, $tipo);
        $compra = Db::insert(
            "INSERT INTO compra_skills (modulo_id, formador_id, tipo, interlocutor_id, usuario_id, base, iva, total, comision_pct, comision,
                                        importe_formador, estado, condiciones_version, renuncia_desistimiento)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PENDIENTE', ?, ?)",
            [$moduloId, (int) $r['formador_id'], $tipo, $iid, $uid, $imp['base'], $imp['iva'], $imp['total'], $imp['comision_pct'],
             $imp['comision'], $imp['importe_formador'], (string) Parametros::get('legal.version', ''), $renuncia ? 1 : 0]
        );
        $web = rtrim((string) Config::get('app.frontend_url', ''), '/');
        $item = [
            'quantity' => 1,
            'price_data' => [
                'currency' => 'eur',
                'unit_amount' => (int) round(($tipo === 'EMPRESA' ? $imp['base'] : $imp['total']) * 100),
                'product_data' => ['name' => 'Pyme Hub Skills · ' . $r['titulo'] . ($tipo === 'EMPRESA' ? ' (licencia para la empresa)' : '')],
            ],
        ];
        if ($imp['iva_pct'] > 0) {
            $item['tax_rates'] = [PagoService::tasaIva($tipo === 'PERSONA')];
        }
        $meta = ['compra_skills_id' => (string) $compra, 'modulo_id' => (string) $moduloId, 'tipo' => $tipo];
        $sesion = Stripe::api('POST', '/v1/checkout/sessions', [
            'mode' => 'payment',
            'locale' => 'es',
            'client_reference_id' => (string) $iid,
            'line_items' => [$item],
            'payment_intent_data' => [
                // Cargo con destino: el formador recibe su parte; la plataforma conserva la comisión y el IVA
                'application_fee_amount' => (int) round(($imp['total'] - $imp['importe_formador']) * 100),
                'transfer_data' => ['destination' => $cuenta['stripe_cuenta_id']],
                'metadata' => $meta,
            ],
            'metadata' => $meta,
            'success_url' => $web . '/?compra=ok#/skills',
            'cancel_url' => $web . '/?compra=cancelada#/skills',
        ], 'pymehub-compra-' . $compra);
        Db::run('UPDATE compra_skills SET stripe_session_id = ? WHERE id = ?', [(string) $sesion['id'], $compra]);
        return ['url' => (string) $sesion['url'], 'compra_id' => $compra, 'importe' => $imp];
    }

    /** checkout.session.completed de una compra de Skills: compra pagada y, si es personal, inscripción. */
    public static function compraPagada(array $o): string
    {
        $id = (int) ($o['metadata']['compra_skills_id'] ?? 0);
        $c = Db::one("SELECT * FROM compra_skills WHERE id = ? AND (stripe_session_id = ? OR stripe_session_id IS NULL) FOR UPDATE",
            [$id, (string) ($o['id'] ?? '')]);
        if ($c === null) {
            return 'sin_compra';
        }
        if (($o['payment_status'] ?? 'paid') !== 'paid') {
            return 'pendiente';
        }
        if ($c['estado'] !== 'PAGADA') {
            $pi = is_string($o['payment_intent'] ?? null) ? $o['payment_intent'] : null;
            Db::run("UPDATE compra_skills SET estado = 'PAGADA', pagado_en = UTC_TIMESTAMP(), stripe_payment_intent = ? WHERE id = ?", [$pi, $id]);
            self::alPagar($c);
        }
        return 'ok';
    }

    /** Efectos de una compra pagada: la persona queda inscrita en su curso. */
    public static function alPagar(array $c): void
    {
        if ($c['tipo'] === 'PERSONA' && AsignacionRepository::existente((int) $c['usuario_id'], (int) $c['modulo_id']) === null) {
            AsignacionRepository::inscribir((int) $c['interlocutor_id'], (int) $c['usuario_id'], (int) $c['modulo_id'], 'AUTOINSCRIPCION');
        }
        Db::run(
            "INSERT INTO registro_auditoria (interlocutor_id, usuario_id, accion, entidad, entidad_id, ip) VALUES (?, ?, 'skills.compra_pagada', 'compra_skills', ?, NULL)",
            [(int) $c['interlocutor_id'], (int) $c['usuario_id'], (int) $c['id']]
        );
    }

    /** Compras de una empresa (gerencia) o de una persona. Sin datos de otras personas. */
    public static function compras(int $iid, ?int $uid): array
    {
        $rows = Db::all(
            "SELECT v.id, v.modulo_id, m.titulo, f.nombre AS formador, v.tipo, v.base, v.iva, v.total, v.estado, v.creado_en, v.pagado_en, v.ficticia
               FROM compra_skills v JOIN modulo_formativo m ON m.id = v.modulo_id JOIN interlocutor f ON f.id = v.formador_id
              WHERE v.interlocutor_id = ? AND v.estado <> 'PENDIENTE'" . ($uid === null ? " AND v.tipo = 'EMPRESA'" : ' AND v.usuario_id = ?') . '
              ORDER BY v.creado_en DESC',
            $uid === null ? [$iid] : [$iid, $uid]
        );
        return array_map([self::class, 'presentarCompra'], $rows);
    }

    public static function presentarCompra(array $v): array
    {
        $out = [];
        foreach ($v as $k => $x) {
            $out[$k] = in_array($k, ['base', 'iva', 'total', 'comision', 'importe_formador', 'comision_pct'], true) ? (float) $x
                : (in_array($k, ['id', 'modulo_id', 'ventas'], true) ? (int) $x : ($k === 'ficticia' ? (bool) $x : $x));
        }
        return $out;
    }

    /** Ventas de un formador (o de todos, para la plataforma). Sin datos personales de quien compra. */
    public static function ventas(?int $fid): array
    {
        $rows = Db::all(
            "SELECT v.id, v.modulo_id, m.titulo, v.formador_id, f.nombre AS formador, v.tipo,
                    CASE WHEN v.tipo = 'EMPRESA' THEN e.nombre ELSE 'Particular' END AS cliente,
                    v.base, v.iva, v.total, v.comision_pct, v.comision, v.importe_formador, v.estado, v.creado_en, v.pagado_en, v.ficticia
               FROM compra_skills v
               JOIN modulo_formativo m ON m.id = v.modulo_id
               JOIN interlocutor f ON f.id = v.formador_id
               JOIN interlocutor e ON e.id = v.interlocutor_id
              WHERE v.estado <> 'PENDIENTE'" . ($fid === null ? '' : ' AND v.formador_id = ' . $fid) . '
              ORDER BY v.creado_en DESC LIMIT 500'
        );
        $data = array_map([self::class, 'presentarCompra'], $rows);
        $tot = ['ventas' => 0, 'total' => 0.0, 'comision' => 0.0, 'importe_formador' => 0.0];
        foreach ($data as $v) {
            if ($v['estado'] === 'PAGADA') {
                $tot['ventas']++;
                $tot['total'] += $v['total'];
                $tot['comision'] += $v['comision'];
                $tot['importe_formador'] += $v['importe_formador'];
            }
        }
        return ['data' => $data, 'totales' => array_map(static fn ($x) => is_float($x) ? round($x, 2) : $x, $tot)];
    }

    /* ------------------------------------------------------------------ Stripe Connect */

    /** Enlace de alta (o de actualización) de la cuenta conectada Express del formador. */
    public static function enlaceAlta(int $fid, string $email): string
    {
        Stripe::exigir();
        $f = Db::one('SELECT id, nombre, stripe_cuenta_id FROM interlocutor WHERE id = ?', [$fid]);
        $cuenta = $f['stripe_cuenta_id'] ?? null;
        if ($cuenta === null) {
            $a = Stripe::api('POST', '/v1/accounts', [
                'type' => 'express', 'country' => 'ES', 'email' => $email, 'default_currency' => 'eur',
                'capabilities' => ['card_payments' => ['requested' => 'true'], 'transfers' => ['requested' => 'true']],
                'business_profile' => ['name' => $f['nombre'], 'product_description' => 'Cursos de formación en Pyme Hub Skills'],
                'metadata' => ['interlocutor_id' => (string) $fid],
            ], 'pymehub-cuenta-' . $fid);
            $cuenta = (string) $a['id'];
            Db::run('UPDATE interlocutor SET stripe_cuenta_id = ? WHERE id = ?', [$cuenta, $fid]);
        }
        $web = rtrim((string) Config::get('app.frontend_url', ''), '/');
        $l = Stripe::api('POST', '/v1/account_links', [
            'account' => $cuenta, 'type' => 'account_onboarding',
            'refresh_url' => $web . '/?connect=reintentar#/inicio', 'return_url' => $web . '/?connect=ok#/inicio',
        ]);
        return (string) $l['url'];
    }

    /** Estado de la cuenta conectada; se consulta a Stripe si existe. */
    public static function estadoCuenta(int $fid): array
    {
        $f = Db::one('SELECT stripe_cuenta_id, stripe_cuenta_lista FROM interlocutor WHERE id = ?', [$fid]);
        $out = ['disponible' => Stripe::habilitado(), 'cuenta' => $f['stripe_cuenta_id'] !== null, 'lista' => (bool) $f['stripe_cuenta_lista'], 'modo' => 'PRUEBA'];
        if ($f['stripe_cuenta_id'] !== null && Stripe::habilitado()) {
            try {
                $a = Stripe::api('GET', '/v1/accounts/' . rawurlencode((string) $f['stripe_cuenta_id']));
                $out['lista'] = self::cuentaActualizada($a) === 'lista';
                $out['pendiente'] = array_values((array) ($a['requirements']['currently_due'] ?? []));
            } catch (ApiException $e) {
                Logger::warn('No se pudo consultar la cuenta conectada', ['interlocutor_id' => $fid]);
            }
        }
        return $out;
    }

    /** account.updated (o consulta directa): la cuenta puede cobrar cuando Stripe habilita cargos y pagos. */
    public static function cuentaActualizada(array $a): string
    {
        $lista = !empty($a['charges_enabled']) && !empty($a['payouts_enabled']) ? 1 : 0;
        $n = Db::run('UPDATE interlocutor SET stripe_cuenta_lista = ? WHERE stripe_cuenta_id = ?', [$lista, (string) ($a['id'] ?? '')]);
        return $n >= 0 && $lista ? 'lista' : 'pendiente';
    }
}
