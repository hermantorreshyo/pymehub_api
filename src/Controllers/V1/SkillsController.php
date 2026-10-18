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
use PymeHub\Core\Validator;
use PymeHub\Repositories\ModuloRepository;
use PymeHub\Repositories\UsuarioRepository;
use PymeHub\Services\FormacionService;
use PymeHub\Services\InvitacionService;
use PymeHub\Services\SkillsService;

/**
 * Marketplace Pyme Hub Skills (fase 9d): catálogo y compras (empresas y personas), panel del
 * formador y revisión y ventas para la plataforma. Stripe Connect solo en modo prueba.
 */
final class SkillsController
{
    /* ------------------------------------------------------------------ Compradores */

    /** GET /v1/skills/catalog (GERENTE, RRHH, EMPLEADO) */
    public static function catalog(Request $request): void
    {
        Validator::validate($request->query, []);
        $c = SkillsService::catalogo(Context::interlocutorId(), Context::usuarioId());
        Response::json($c, 200, ['total' => count($c), 'comision_pct' => SkillsService::comisionPct(), 'iva_pct' => SkillsService::ivaPct()]);
    }

    /**
     * POST /v1/skills/purchases (GERENTE: licencia de empresa; EMPLEADO: para sí mismo)
     * { modulo_id, acepta_condiciones: true, renuncia_desistimiento?: true (persona) }
     */
    public static function purchase(Request $request): void
    {
        RateLimiter::hit('skills-compra:' . Context::usuarioId(), 20, 3600);
        $in = Validator::validate($request->json(), [
            'modulo_id'              => 'required|int|min:1',
            'acepta_condiciones'     => 'required|bool',
            'renuncia_desistimiento' => 'nullable|bool',
        ]);
        $tipo = Context::perfil() === 'EMPLEADO' ? 'PERSONA' : 'EMPRESA';
        $errores = [];
        if ($in['acepta_condiciones'] !== true) {
            $errores['acepta_condiciones'] = 'Acepta las condiciones de Pyme Hub Skills';
        }
        if ($tipo === 'PERSONA' && ($in['renuncia_desistimiento'] ?? false) !== true) {
            $errores['renuncia_desistimiento'] = 'Para empezar el curso al momento, confirma que pierdes el derecho de desistimiento';
        }
        if ($errores !== []) {
            throw ApiException::validation($errores);
        }
        $r = SkillsService::comprar((int) $in['modulo_id'], $tipo, Context::interlocutorId(), Context::usuarioId(), $tipo === 'PERSONA');
        Audit::log('skills.compra_iniciada', 'compra_skills', $r['compra_id'], $request->ip);
        Response::json($r, 201);
    }

    /** GET /v1/skills/purchases (GERENTE: de la empresa; EMPLEADO: las suyas) */
    public static function purchases(Request $request): void
    {
        Validator::validate($request->query, []);
        $p = SkillsService::compras(Context::interlocutorId(), Context::perfil() === 'EMPLEADO' ? Context::usuarioId() : null);
        Response::json($p, 200, ['total' => count($p)]);
    }

    /* ------------------------------------------------------------------ Formador */

    /** GET /v1/skills/my-courses (FORMADOR) */
    public static function myCourses(Request $request): void
    {
        Validator::validate($request->query, []);
        $c = SkillsService::misCursos(Context::interlocutorId());
        Response::json($c, 200, ['total' => count($c), 'comision_pct' => SkillsService::comisionPct(), 'iva_pct' => SkillsService::ivaPct()]);
    }

    /** GET /v1/skills/my-courses/{id} (FORMADOR): módulo completo, cuestionario y ficha comercial. */
    public static function myCourse(Request $request): void
    {
        $id = $request->intParam('id');
        SkillsService::moduloDelFormador($id, Context::interlocutorId());
        $m = FormacionService::moduloVisible($id, Context::interlocutorId());
        $m['preguntas'] = ModuloRepository::preguntas($id, true);
        unset($m['interlocutor_id']);
        $f = SkillsService::ficha($id);
        $m['ficha'] = $f === null ? null : [
            'precio_empresa' => (float) $f['precio_empresa'], 'precio_persona' => (float) $f['precio_persona'],
            'estado' => $f['estado'], 'motivo' => $f['motivo'], 'enviado_en' => $f['enviado_en'], 'revisado_en' => $f['revisado_en'],
            'importes' => ['EMPRESA' => SkillsService::importe($f, 'EMPRESA'), 'PERSONA' => SkillsService::importe($f, 'PERSONA')],
        ];
        Response::json($m);
    }

    /** PATCH /v1/skills/my-courses/{id} (FORMADOR): edición; un curso aprobado vuelve a revisión. */
    public static function updateCourse(Request $request): void
    {
        $id = $request->intParam('id');
        SkillsService::moduloDelFormador($id, Context::interlocutorId());
        SkillsService::cambioDeContenido($id);
        FormacionController::updateModule($request);
    }

    /** PUT /v1/skills/my-courses/{id}/listing (FORMADOR): { precio_empresa, precio_persona } */
    public static function listing(Request $request): void
    {
        $id = $request->intParam('id');
        $in = Validator::validate($request->json(), [
            'precio_empresa' => 'required|numeric|min:1|max:5000',
            'precio_persona' => 'required|numeric|min:1|max:5000',
        ]);
        SkillsService::guardarPrecios($id, Context::interlocutorId(), round((float) $in['precio_empresa'], 2), round((float) $in['precio_persona'], 2));
        Audit::log('skills.precios', 'modulo_formativo', $id, $request->ip);
        self::myCourse($request);
    }

    /** POST /v1/skills/my-courses/{id}/submit (FORMADOR): a revisión de la plataforma. */
    public static function submit(Request $request): void
    {
        $id = $request->intParam('id');
        SkillsService::enviarRevision($id, Context::interlocutorId());
        Audit::log('skills.enviado_revision', 'modulo_formativo', $id, $request->ip);
        self::myCourse($request);
    }

    /** GET /v1/skills/sales (FORMADOR) */
    public static function sales(Request $request): void
    {
        Validator::validate($request->query, []);
        $v = SkillsService::ventas(Context::interlocutorId());
        Response::json($v['data'], 200, ['total' => count($v['data']), 'totales' => $v['totales']]);
    }

    /** GET /v1/skills/connect (FORMADOR) */
    public static function connectStatus(Request $request): void
    {
        Response::json(SkillsService::estadoCuenta(Context::interlocutorId()));
    }

    /** POST /v1/skills/connect (FORMADOR): enlace de alta en Stripe Connect (modo prueba). */
    public static function connect(Request $request): void
    {
        Validator::validate($request->json(), []);
        $url = SkillsService::enlaceAlta(Context::interlocutorId(), (string) (Context::$usuario['email'] ?? ''));
        Audit::log('skills.connect_alta', 'interlocutor', Context::interlocutorId(), $request->ip);
        Response::json(['url' => $url], 201);
    }

    /* ------------------------------------------------------------------ Plataforma */

    /** GET /v1/admin/trainers (ADMIN) */
    public static function trainers(Request $request): void
    {
        $rows = Db::all(
            "SELECT f.id, f.nombre, f.nombre_comercial, f.nif, f.email_contacto, f.estado, f.creado_en, f.stripe_cuenta_id, f.stripe_cuenta_lista,
                    (SELECT COUNT(*) FROM curso_skills c WHERE c.interlocutor_id = f.id AND c.estado = 'APROBADO') AS cursos,
                    (SELECT COUNT(*) FROM curso_skills c WHERE c.interlocutor_id = f.id AND c.estado = 'PENDIENTE') AS pendientes,
                    (SELECT COUNT(*) FROM compra_skills v WHERE v.formador_id = f.id AND v.estado = 'PAGADA') AS ventas,
                    (SELECT COALESCE(SUM(v.importe_formador), 0) FROM compra_skills v WHERE v.formador_id = f.id AND v.estado = 'PAGADA') AS liquidado,
                    (SELECT COALESCE(SUM(v.comision), 0) FROM compra_skills v WHERE v.formador_id = f.id AND v.estado = 'PAGADA') AS comision
               FROM interlocutor f WHERE f.tipo = 'FORMADOR' ORDER BY f.nombre"
        );
        Response::json(array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'nombre' => $r['nombre'], 'nombre_comercial' => $r['nombre_comercial'], 'nif' => $r['nif'],
            'email_contacto' => $r['email_contacto'], 'estado' => $r['estado'], 'creado_en' => $r['creado_en'],
            'stripe' => ['cuenta' => $r['stripe_cuenta_id'] !== null, 'lista' => (bool) $r['stripe_cuenta_lista']],
            'cursos' => (int) $r['cursos'], 'pendientes' => (int) $r['pendientes'], 'ventas' => (int) $r['ventas'],
            'liquidado' => round((float) $r['liquidado'], 2), 'comision' => round((float) $r['comision'], 2),
        ], $rows), 200, ['total' => count($rows)]);
    }

    /** POST /v1/admin/trainers (ADMIN): formador y su cuenta invitada. */
    public static function storeTrainer(Request $request): void
    {
        $body = $request->json();
        $persona = $body['formador'] ?? null;
        unset($body['formador']);
        $in = Validator::validate($body, [
            'nombre'           => 'required|string|min:2|max:150',
            'nombre_comercial' => 'nullable|string|max:150',
            'nif'              => 'required|nif',
            'email_contacto'   => 'nullable|email',
        ]);
        if (!is_array($persona)) {
            throw ApiException::validation(['formador' => 'Obligatorio: nombre, apellidos y email de la persona formadora']);
        }
        $p = Validator::validate($persona, ['nombre' => 'required|string|min:2|max:80', 'apellidos' => 'nullable|string|max:120', 'email' => 'required|email']);
        $nif = strtoupper(trim($in['nif']));
        if (Db::value('SELECT 1 FROM interlocutor WHERE nif = ?', [$nif]) !== null) {
            throw new ApiException('PH-VAL-001', 'Ya existe un interlocutor con ese NIF', 409, ['nif' => 'Duplicado']);
        }
        if (UsuarioRepository::emailExists($p['email'])) {
            throw new ApiException('PH-VAL-001', 'Ese email ya tiene una cuenta', 409, ['formador.email' => 'Duplicado']);
        }
        $r = Db::transaction(static function () use ($in, $p, $nif): array {
            $id = Db::insert("INSERT INTO interlocutor (tipo, nombre, nombre_comercial, nif, email_contacto, estado) VALUES ('FORMADOR', ?, ?, ?, ?, 'ACTIVO')",
                [$in['nombre'], $in['nombre_comercial'] ?? null, $nif, $in['email_contacto'] ?? $p['email']]);
            $uid = UsuarioRepository::create($id, 'FORMADOR', 'FORMADOR', $p['nombre'], $p['apellidos'] ?? '', $p['email']);
            return ['id' => $id, 'invitacion' => InvitacionService::create($uid, Context::usuarioId())];
        });
        Audit::log('admin.formador_creado', 'interlocutor', $r['id'], $request->ip);
        Response::json(['id' => $r['id'], 'invitacion' => $r['invitacion']], 201);
    }

    /** GET /v1/admin/skills/courses?estado= (ADMIN) */
    public static function adminCourses(Request $request): void
    {
        $q = Validator::validate($request->query, ['estado' => 'nullable|in:BORRADOR,PENDIENTE,APROBADO,RECHAZADO,RETIRADO']);
        $rows = Db::all(
            "SELECT m.id, m.titulo, m.resumen, m.categoria, m.nivel, m.duracion_min, m.contenido, m.puestos_destino, m.estado AS modulo_estado,
                    c.precio_empresa, c.precio_persona, c.estado, c.motivo, c.enviado_en, c.revisado_en,
                    f.id AS formador_id, f.nombre AS formador, f.nombre_comercial AS formador_comercial, f.stripe_cuenta_lista
               FROM curso_skills c
               JOIN modulo_formativo m ON m.id = c.modulo_id AND m.eliminado_en IS NULL
               JOIN interlocutor f ON f.id = c.interlocutor_id"
            . (isset($q['estado']) ? ' WHERE c.estado = ?' : " WHERE c.estado <> 'BORRADOR'")
            . " ORDER BY c.estado = 'PENDIENTE' DESC, c.enviado_en DESC",
            isset($q['estado']) ? [$q['estado']] : []
        );
        $data = array_map(static fn (array $r) => SkillsService::presentar($r) + ['motivo' => $r['motivo'], 'enviado_en' => $r['enviado_en'], 'revisado_en' => $r['revisado_en']], $rows);
        Response::json($data, 200, ['total' => count($data), 'pendientes' => count(array_filter($data, static fn ($c) => $c['estado'] === 'PENDIENTE'))]);
    }

    /** GET /v1/admin/skills/courses/{id} (ADMIN): contenido completo para revisarlo. */
    public static function adminCourse(Request $request): void
    {
        $id = $request->intParam('id');
        $r = SkillsService::curso($id);
        $m = Db::one("SELECT m.*, i.tipo AS propietario_tipo FROM modulo_formativo m JOIN interlocutor i ON i.id = m.interlocutor_id WHERE m.id = ?", [$id]);
        $out = SkillsService::presentar($r) + ['motivo' => $r['motivo']];
        $det = ModuloRepository::present($m, true);
        $out['contenido'] = $det['contenido'];
        $out['preguntas'] = ModuloRepository::preguntas($id, true);
        Response::json($out);
    }

    /** POST /v1/admin/skills/courses/{id}/review (ADMIN): { decision: APROBAR|RECHAZAR|RETIRAR, motivo? } */
    public static function review(Request $request): void
    {
        $id = $request->intParam('id');
        $in = Validator::validate($request->json(), ['decision' => 'required|in:APROBAR,RECHAZAR,RETIRAR', 'motivo' => 'nullable|string|max:300']);
        $r = SkillsService::curso($id);
        if ($in['decision'] === 'APROBAR' && $r['modulo_estado'] !== 'PUBLICADO') {
            throw ApiException::validation(['decision' => 'El curso no está publicado por el formador']);
        }
        SkillsService::revisar($id, $in['decision'], $in['motivo'] ?? null, Context::usuarioId());
        Audit::log('skills.revision_' . strtolower($in['decision']), 'modulo_formativo', $id, $request->ip);
        Response::json(SkillsService::presentar(SkillsService::curso($id)));
    }

    /** GET /v1/admin/skills/sales (ADMIN): ventas, comisiones y lo liquidado a cada formador. */
    public static function adminSales(Request $request): void
    {
        Validator::validate($request->query, []);
        $v = SkillsService::ventas(null);
        Response::json($v['data'], 200, ['total' => count($v['data']), 'totales' => $v['totales'], 'stripe' => ['habilitado' => \PymeHub\Core\Stripe::habilitado(), 'modo' => 'PRUEBA']]);
    }
}
