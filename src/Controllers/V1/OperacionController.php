<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\Mantenimiento;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;
use PymeHub\Repositories\EmpleadoRepository;
use PymeHub\Services\ExportacionService;

/**
 * Operación (fase 9, §17.4):
 * - Exportación de la empresa (RF-110, LEG-017) para la plataforma y para su gerencia.
 * - Derechos RGPD de cada persona: acceso y portabilidad (data-export) y supresión (erase).
 * - Modo mantenimiento desde el panel y aviso programado (RNF-063).
 * - Resumen de errores 5xx de las últimas horas (RNF-062).
 */
final class OperacionController
{
    /* ------------------------------------------------------------------ Exportación */

    /** GET /v1/admin/tenants/{id}/export (ADMIN) */
    public static function exportTenant(Request $request): void
    {
        Validator::validate($request->query, []);
        $id = $request->intParam('id');
        $nif = Db::value("SELECT nif FROM interlocutor WHERE id = ? AND tipo = 'EMPRESA'", [$id]);
        if ($nif === null) {
            throw ApiException::notFound();
        }
        $zip = ExportacionService::empresa($id);
        Audit::log('empresa.exportada', 'interlocutor', $id, $request->ip);
        Response::file($zip, ExportacionService::nombreArchivo('pymehub-' . strtolower((string) $nif)), 'application/zip');
    }

    /** GET /v1/company/export (GERENTE): sus datos siempre exportables, también tras la baja. */
    public static function exportCompany(Request $request): void
    {
        Validator::validate($request->query, []);
        $iid = Context::interlocutorId();
        $nif = (string) Db::value('SELECT nif FROM interlocutor WHERE id = ?', [$iid]);
        $zip = ExportacionService::empresa($iid);
        Audit::log('empresa.exportada', 'interlocutor', $iid, $request->ip);
        Response::file($zip, ExportacionService::nombreArchivo('pymehub-' . strtolower($nif)), 'application/zip');
    }

    /** GET /v1/me/data-export: derecho de acceso y portabilidad de la persona (RGPD art. 15 y 20). */
    public static function myData(Request $request): void
    {
        Validator::validate($request->query, []);
        $json = ExportacionService::persona(Context::usuarioId());
        Audit::log('persona.datos_exportados', 'usuario', Context::usuarioId(), $request->ip);
        Response::file($json, 'mis-datos-pymehub.json', 'application/json; charset=utf-8');
    }

    /* ------------------------------------------------------------------ Supresión (RGPD art. 17) */

    /**
     * POST /v1/employees/{id}/erase (GERENTE): supresión de una persona dada de baja.
     * Borra sus check-ins, notas, incidencias, formación, preferencias, consultas al asesor,
     * puntuaciones y alertas, y anonimiza su usuario y su ficha. Se conservan, sin identificar a
     * nadie, la fecha de alta, la de baja, el motivo, el puesto y el equipo, que alimentan los
     * indicadores de rotación de la empresa.
     */
    public static function erase(Request $request): void
    {
        $in = Validator::validate($request->json(), ['confirmacion' => 'required|string|max:20']);
        if ($in['confirmacion'] !== 'SUPRIMIR') {
            throw ApiException::validation(['confirmacion' => 'Escribe SUPRIMIR para confirmar']);
        }
        $iid = Context::interlocutorId();
        $id = $request->intParam('id');
        $emp = EmpleadoRepository::find($id, $iid);
        if ($emp === null) {
            throw ApiException::notFound();
        }
        if ($emp['fecha_baja'] === null) {
            throw new ApiException('PH-VAL-001', 'Primero registra la baja de la persona; después podrás suprimir sus datos', 409);
        }
        Db::transaction(static function () use ($id, $iid): void {
            foreach (['checkin_bienestar', 'preferencia_formativa', 'solicitud_ia', 'puntuacion_riesgo', 'incidencia', 'asignacion_formativa', 'invitacion'] as $t) {
                Db::run("DELETE FROM $t WHERE usuario_id = ?", [$id]);
            }
            Db::run("DELETE FROM alerta WHERE interlocutor_id = ? AND entidad = 'USUARIO' AND entidad_id = ?", [$iid, $id]);
            Db::run(
                "UPDATE usuario SET nombre = 'Persona', apellidos = 'suprimida', email = NULL, password_hash = NULL,
                        estado_acceso = 'BAJA', privacidad_aceptada_en = NULL, eliminado_en = UTC_TIMESTAMP()
                  WHERE id = ? AND interlocutor_id = ?",
                [$id, $iid]
            );
            Db::run('UPDATE ficha_laboral SET codigo_interno = NULL WHERE usuario_id = ? AND interlocutor_id = ?', [$id, $iid]);
            Db::run("UPDATE registro_auditoria SET usuario_id = NULL, ip = NULL WHERE usuario_id = ?", [$id]);
        });
        Audit::log('persona.suprimida', 'usuario', $id, $request->ip);
        Response::json(null, 204);
    }

    /* ------------------------------------------------------------------ Mantenimiento (RNF-063) */

    /** GET /v1/public/status: mantenimiento activo o programado, para avisar en la web. */
    public static function status(Request $request): void
    {
        Validator::validate($request->query, []);
        Response::json(Mantenimiento::estadoPublico());
    }

    /** GET /v1/admin/maintenance (ADMIN) */
    public static function maintenance(Request $request): void
    {
        Validator::validate($request->query, []);
        Response::json(Mantenimiento::estado());
    }

    /** PUT /v1/admin/maintenance (ADMIN) */
    public static function saveMaintenance(Request $request): void
    {
        $in = Validator::validate($request->json(), [
            'activo'            => 'required|bool',
            'mensaje'           => 'nullable|string|max:200',
            'programado_inicio' => 'nullable|string|max:25',
            'programado_fin'    => 'nullable|string|max:25',
        ]);
        $errores = [];
        $ini = Mantenimiento::fecha($in['programado_inicio'] ?? null);
        $fin = Mantenimiento::fecha($in['programado_fin'] ?? null);
        if (!empty($in['programado_inicio']) && $ini === null) {
            $errores['programado_inicio'] = 'Fecha y hora no válidas (AAAA-MM-DDTHH:MM, hora de España)';
        }
        if (!empty($in['programado_fin']) && $fin === null) {
            $errores['programado_fin'] = 'Fecha y hora no válidas (AAAA-MM-DDTHH:MM, hora de España)';
        }
        if (($ini === null) !== ($fin === null) && $errores === []) {
            $errores['programado_fin'] = 'Indica el inicio y el fin de la ventana';
        }
        if ($ini !== null && $fin !== null && $fin <= $ini) {
            $errores['programado_fin'] = 'Debe ser posterior al inicio';
        }
        if ($errores !== []) {
            throw ApiException::validation($errores);
        }
        Mantenimiento::guardar((bool) $in['activo'], isset($in['mensaje']) ? trim($in['mensaje']) : null, $ini, $fin);
        Audit::log($in['activo'] ? 'mantenimiento.activado' : 'mantenimiento.actualizado', null, null, $request->ip);
        Response::json(Mantenimiento::estado());
    }

    /* ------------------------------------------------------------------ Errores (RNF-062) */

    /**
     * GET /v1/admin/errors?hours=24 (ADMIN): errores 5xx agrupados por código y ruta.
     * Solo código, ruta, veces e identificador de correlación: el detalle (que podría contener
     * datos) se consulta en el servidor con ese identificador.
     */
    public static function errors(Request $request): void
    {
        $q = Validator::validate($request->query, ['hours' => 'nullable|int|min:1|max:168']);
        $horas = (int) ($q['hours'] ?? 24);
        $desde = time() - $horas * 3600;
        $grupos = [];
        $total = 0;
        for ($d = 0; $d <= intdiv($horas, 24) + 1; $d++) {
            $file = PH_ROOT . '/storage/logs/api-' . gmdate('Y-m-d', time() - $d * 86400) . '.log';
            if (!is_file($file)) {
                continue;
            }
            $fh = fopen($file, 'r');
            while (($line = fgets($fh)) !== false) {
                if (!str_contains($line, '"level":"error"')) {
                    continue;
                }
                $e = json_decode($line, true);
                if (!is_array($e) || strtotime((string) ($e['ts'] ?? '')) < $desde) {
                    continue;
                }
                $code = (string) ($e['ctx']['code'] ?? 'PH-SYS-001');
                $ruta = trim(($e['ctx']['method'] ?? '') . ' ' . preg_replace('#/\d+(?=/|$)#', '/{id}', (string) ($e['ctx']['path'] ?? '(tarea programada)')));
                $k = $code . '|' . $ruta;
                $grupos[$k] ??= ['code' => $code, 'ruta' => $ruta, 'mensaje' => (string) ($e['msg'] ?? ''), 'veces' => 0, 'ultima' => null, 'correlation_id' => null];
                $grupos[$k]['veces']++;
                if ($grupos[$k]['ultima'] === null || $e['ts'] > $grupos[$k]['ultima']) {
                    $grupos[$k]['ultima'] = $e['ts'];
                    $grupos[$k]['correlation_id'] = $e['cid'] ?? null;
                }
                $total++;
            }
            fclose($fh);
        }
        $grupos = array_values($grupos);
        usort($grupos, static fn ($a, $b) => $b['veces'] <=> $a['veces'] ?: strcmp((string) $b['ultima'], (string) $a['ultima']));
        Response::json(['horas' => $horas, 'total' => $total, 'grupos' => array_slice($grupos, 0, 20)]);
    }
}
