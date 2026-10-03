<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\Plan;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;
use PymeHub\Repositories\UsuarioRepository;
use PymeHub\Services\InvitacionService;

/**
 * Gestión de empresas cliente por el administrador de la plataforma.
 * Una empresa = interlocutor de tipo EMPRESA + suscripción vigente.
 */
final class AdminEmpresaController
{
    private const SELECT = "SELECT i.id, i.nombre, i.nombre_comercial, i.nif, i.sector, i.email_contacto, i.telefono_contacto,
                                   i.fecha_contrato_encargado, i.estado, i.fecha_baja, i.creado_en,
                                   s.plan, s.ciclo_cobro, s.precio_empleado_mes, s.limite_empleados, s.cuota_ia_diaria, s.fecha_inicio,
                                   (SELECT COUNT(*) FROM usuario e JOIN ficha_laboral f ON f.usuario_id = e.id
                                     WHERE e.interlocutor_id = i.id AND e.perfil = 'EMPLEADO' AND e.eliminado_en IS NULL AND f.fecha_baja IS NULL) AS empleados_activos
                              FROM interlocutor i
                              LEFT JOIN suscripcion s ON s.interlocutor_id = i.id AND s.estado = 'VIGENTE'
                             WHERE i.tipo = 'EMPRESA'";

    /** GET /v1/admin/tenants */
    public static function index(Request $request): void
    {
        $rows = Db::all(self::SELECT . ' ORDER BY i.nombre');
        Response::json(array_map([self::class, 'present'], $rows), 200, ['total' => count($rows)]);
    }

    /** GET /v1/admin/tenants/{id} */
    public static function show(Request $request): void
    {
        $row = Db::one(self::SELECT . ' AND i.id = ?', [$request->intParam('id')]);
        if ($row === null) {
            throw ApiException::notFound();
        }
        $data = self::present($row);
        $data['usuarios_gestion'] = UsuarioRepository::listGestion((int) $row['id']);
        Response::json($data);
    }

    /** POST /v1/admin/tenants: crea empresa, suscripción y gerente invitado. */
    public static function store(Request $request): void
    {
        $body = $request->json();
        $gerente = $body['gerente'] ?? null;
        unset($body['gerente']);

        $in = Validator::validate($body, [
            'nombre'                   => 'required|string|min:2|max:150',
            'nombre_comercial'         => 'nullable|string|max:150',
            'nif'                      => 'required|nif',
            'sector'                   => 'nullable|string|max:80',
            'email_contacto'           => 'nullable|email',
            'telefono_contacto'        => 'nullable|string|max:30',
            'plan'                     => 'required|in:ENTRADA,COMPLETO',
            'ciclo_cobro'              => 'required|in:MENSUAL,ANUAL',
            'limite_empleados'         => 'nullable|int|min:1|max:250',
            'fecha_contrato_encargado' => 'nullable|date',
            'estado'                   => 'nullable|in:PILOTO,ACTIVO',
        ]);
        if (!is_array($gerente)) {
            throw ApiException::validation(['gerente' => 'Obligatorio: nombre, apellidos y email del gerente']);
        }
        $g = Validator::validate($gerente, [
            'nombre'    => 'required|string|min:2|max:80',
            'apellidos' => 'nullable|string|max:120',
            'email'     => 'required|email',
        ]);

        $nif = strtoupper(trim($in['nif']));
        if (Db::value('SELECT 1 FROM interlocutor WHERE nif = ?', [$nif]) !== null) {
            throw new ApiException('PH-VAL-001', 'Ya existe una empresa con ese NIF', 409, ['nif' => 'Duplicado']);
        }
        if (UsuarioRepository::emailExists($g['email'])) {
            throw new ApiException('PH-VAL-001', 'Ese email ya tiene una cuenta', 409, ['gerente.email' => 'Duplicado']);
        }

        $result = Db::transaction(static function () use ($in, $g, $nif): array {
            $id = Db::insert(
                "INSERT INTO interlocutor (tipo, nombre, nombre_comercial, nif, sector, email_contacto, telefono_contacto, fecha_contrato_encargado, estado)
                 VALUES ('EMPRESA', ?, ?, ?, ?, ?, ?, ?, ?)",
                [$in['nombre'], $in['nombre_comercial'] ?? null, $nif, $in['sector'] ?? null, $in['email_contacto'] ?? null,
                 $in['telefono_contacto'] ?? null, $in['fecha_contrato_encargado'] ?? null, $in['estado'] ?? 'ACTIVO']
            );
            Db::insert(
                'INSERT INTO suscripcion (interlocutor_id, plan, ciclo_cobro, precio_empleado_mes, limite_empleados, fecha_inicio)
                 VALUES (?, ?, ?, ?, ?, UTC_DATE())',
                [$id, $in['plan'], $in['ciclo_cobro'], Plan::PRECIO[$in['plan']], $in['limite_empleados'] ?? 250]
            );
            $gerenteId = UsuarioRepository::create($id, 'EMPRESA', 'GERENTE', $g['nombre'], $g['apellidos'] ?? '', $g['email']);
            $inv = InvitacionService::create($gerenteId, Context::usuarioId());
            return ['id' => $id, 'gerente_id' => $gerenteId, 'invitacion' => $inv];
        });

        Audit::log('admin.empresa_creada', 'interlocutor', $result['id'], $request->ip);
        $row = Db::one(self::SELECT . ' AND i.id = ?', [$result['id']]);
        $data = self::present($row);
        $data['invitacion_gerente'] = $result['invitacion'];
        Response::json($data, 201);
    }

    /** PATCH /v1/admin/tenants/{id} */
    public static function update(Request $request): void
    {
        $id = $request->intParam('id');
        if (Db::value("SELECT 1 FROM interlocutor WHERE id = ? AND tipo = 'EMPRESA'", [$id]) === null) {
            throw ApiException::notFound();
        }
        $in = Validator::validate($request->json(), [
            'nombre'                   => 'nullable|string|min:2|max:150',
            'nombre_comercial'         => 'nullable|string|max:150',
            'sector'                   => 'nullable|string|max:80',
            'email_contacto'           => 'nullable|email',
            'telefono_contacto'        => 'nullable|string|max:30',
            'fecha_contrato_encargado' => 'nullable|date',
            'estado'                   => 'nullable|in:PILOTO,ACTIVO,SUSPENDIDO',
            'plan'                     => 'nullable|in:ENTRADA,COMPLETO',
            'ciclo_cobro'              => 'nullable|in:MENSUAL,ANUAL',
            'limite_empleados'         => 'nullable|int|min:1|max:250',
            'cuota_ia_diaria'          => 'nullable|int|min:0|max:1000',
        ]);

        Db::transaction(static function () use ($id, $in): void {
            $cols = ['nombre', 'nombre_comercial', 'sector', 'email_contacto', 'telefono_contacto', 'fecha_contrato_encargado', 'estado'];
            $set = [];
            $params = [];
            foreach ($cols as $c) {
                if (array_key_exists($c, $in) && ($in[$c] !== null || in_array($c, ['nombre_comercial', 'sector', 'email_contacto', 'telefono_contacto', 'fecha_contrato_encargado'], true))) {
                    $set[] = "$c = ?";
                    $params[] = $in[$c];
                }
            }
            if ($set !== []) {
                $params[] = $id;
                Db::run('UPDATE interlocutor SET ' . implode(', ', $set) . ' WHERE id = ?', $params);
            }

            $current = Db::one("SELECT * FROM suscripcion WHERE interlocutor_id = ? AND estado = 'VIGENTE' FOR UPDATE", [$id]);
            $planChange = isset($in['plan']) || isset($in['ciclo_cobro']);
            if ($current !== null && $planChange && (($in['plan'] ?? $current['plan']) !== $current['plan'] || ($in['ciclo_cobro'] ?? $current['ciclo_cobro']) !== $current['ciclo_cobro'])) {
                // Cambio de plan o ciclo: se cierra la suscripción y se abre otra (histórico)
                Db::run("UPDATE suscripcion SET estado = 'FINALIZADA', fecha_fin = UTC_DATE() WHERE id = ?", [$current['id']]);
                $plan = $in['plan'] ?? $current['plan'];
                Db::insert(
                    'INSERT INTO suscripcion (interlocutor_id, plan, ciclo_cobro, precio_empleado_mes, limite_empleados, cuota_ia_diaria, fecha_inicio)
                     VALUES (?, ?, ?, ?, ?, ?, UTC_DATE())',
                    [$id, $plan, $in['ciclo_cobro'] ?? $current['ciclo_cobro'], Plan::PRECIO[$plan],
                     $in['limite_empleados'] ?? $current['limite_empleados'], $in['cuota_ia_diaria'] ?? $current['cuota_ia_diaria']]
                );
            } elseif ($current !== null && (isset($in['limite_empleados']) || isset($in['cuota_ia_diaria']))) {
                Db::run(
                    'UPDATE suscripcion SET limite_empleados = ?, cuota_ia_diaria = ? WHERE id = ?',
                    [$in['limite_empleados'] ?? $current['limite_empleados'], $in['cuota_ia_diaria'] ?? $current['cuota_ia_diaria'], $current['id']]
                );
            }
        });

        Audit::log('admin.empresa_actualizada', 'interlocutor', $id, $request->ip);
        Response::json(self::present(Db::one(self::SELECT . ' AND i.id = ?', [$id])));
    }

    /** GET /v1/admin/metrics */
    public static function metrics(Request $request): void
    {
        $porEstado = Db::all("SELECT estado, COUNT(*) AS total FROM interlocutor WHERE tipo = 'EMPRESA' GROUP BY estado");
        $mrr = Db::all(
            "SELECT s.plan, s.ciclo_cobro, COUNT(DISTINCT s.interlocutor_id) AS empresas,
                    COUNT(f.usuario_id) AS empleados
               FROM suscripcion s
               JOIN interlocutor i ON i.id = s.interlocutor_id AND i.estado IN ('PILOTO','ACTIVO')
               LEFT JOIN usuario u ON u.interlocutor_id = s.interlocutor_id AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL
               LEFT JOIN ficha_laboral f ON f.usuario_id = u.id AND f.fecha_baja IS NULL
              WHERE s.estado = 'VIGENTE'
              GROUP BY s.plan, s.ciclo_cobro"
        );
        Response::json([
            'empresas_por_estado' => $porEstado,
            'por_plan'            => array_map(static fn ($r) => [
                'plan'        => $r['plan'],
                'ciclo_cobro' => $r['ciclo_cobro'],
                'empresas'    => (int) $r['empresas'],
                'empleados'   => (int) $r['empleados'],
                'mrr_teorico' => round((int) $r['empleados'] * Plan::PRECIO[$r['plan']], 2),
            ], $mrr),
        ]);
    }

    private static function present(array $r): array
    {
        return [
            'id'                       => (int) $r['id'],
            'nombre'                   => $r['nombre'],
            'nombre_comercial'         => $r['nombre_comercial'],
            'nif'                      => $r['nif'],
            'sector'                   => $r['sector'],
            'email_contacto'           => $r['email_contacto'],
            'telefono_contacto'        => $r['telefono_contacto'],
            'fecha_contrato_encargado' => $r['fecha_contrato_encargado'],
            'estado'                   => $r['estado'],
            'suscripcion'              => $r['plan'] === null ? null : [
                'plan'                => $r['plan'],
                'ciclo_cobro'         => $r['ciclo_cobro'],
                'precio_empleado_mes' => (float) $r['precio_empleado_mes'],
                'limite_empleados'    => (int) $r['limite_empleados'],
                'cuota_ia_diaria'     => (int) $r['cuota_ia_diaria'],
                'fecha_inicio'        => $r['fecha_inicio'],
            ],
            'empleados_activos'        => (int) $r['empleados_activos'],
            'creado_en'                => $r['creado_en'],
        ];
    }
}
