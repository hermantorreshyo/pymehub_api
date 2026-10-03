<?php
declare(strict_types=1);

namespace PymeHub\Middleware;

use PymeHub\Core\ApiException;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\Request;
use PymeHub\Core\Session;

/**
 * Autenticación, perfil y comprobación CSRF.
 */
final class Auth
{
    /** Exige sesión válida y, opcionalmente, uno de los perfiles indicados. */
    public static function require(string ...$perfiles): callable
    {
        return static function (Request $request) use ($perfiles): void {
            $id = Session::userId();
            if ($id === null) {
                throw new ApiException('PH-AUTH-002', 'Tu sesión ha caducado. Vuelve a iniciar sesión.', 401);
            }
            $row = Db::one(
                "SELECT u.id, u.interlocutor_id, u.perfil, u.nombre, u.apellidos, u.email, u.estado_acceso,
                        u.privacidad_aceptada_en,
                        i.tipo AS i_tipo, i.nombre AS i_nombre, i.estado AS i_estado
                   FROM usuario u
                   JOIN interlocutor i ON i.id = u.interlocutor_id
                  WHERE u.id = ? AND u.eliminado_en IS NULL",
                [$id]
            );
            if ($row === null || $row['estado_acceso'] !== 'ACTIVO' || $row['i_estado'] === 'SUSPENDIDO') {
                Session::destroy();
                throw new ApiException('PH-AUTH-002', 'Tu sesión ha caducado. Vuelve a iniciar sesión.', 401);
            }
            Context::$usuario = $row;
            Context::$interlocutor = [
                'id'     => (int) $row['interlocutor_id'],
                'tipo'   => $row['i_tipo'],
                'nombre' => $row['i_nombre'],
                'estado' => $row['i_estado'],
            ];
            Context::$suscripcion = $row['i_tipo'] === 'EMPRESA'
                ? Db::one("SELECT plan, ciclo_cobro, limite_empleados, cuota_ia_diaria FROM suscripcion WHERE interlocutor_id = ? AND estado = 'VIGENTE'", [(int) $row['interlocutor_id']])
                : null;

            if ($perfiles !== [] && !in_array($row['perfil'], $perfiles, true)) {
                throw ApiException::forbidden();
            }
            // PH-TENANT-004: empresa dada de baja = solo lectura
            if ($row['i_estado'] === 'BAJA' && $request->method !== 'GET') {
                throw new ApiException('PH-TENANT-004', 'La empresa está dada de baja: solo lectura', 403);
            }
        };
    }

    /** SEC-002: exige X-CSRF-Token en peticiones que modifican datos. */
    public static function csrf(): callable
    {
        return static function (Request $request): void {
            if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
                return;
            }
            $sent = (string) $request->header('x-csrf-token');
            $expected = Session::csrfToken();
            if ($expected === '' || !hash_equals($expected, $sent)) {
                throw new ApiException('PH-AUTH-003', 'Token CSRF ausente o no válido', 403);
            }
        };
    }

    /** RF-010: la funcionalidad debe estar incluida en el plan vigente. */
    public static function plan(string $feature): callable
    {
        return static function (Request $request) use ($feature): void {
            $plan = Context::$suscripcion['plan'] ?? null;
            if (!in_array($feature, \PymeHub\Core\Plan::features($plan), true)) {
                throw new ApiException('PH-PLAN-001', 'Esta funcionalidad no está incluida en el plan contratado', 403);
            }
        };
    }
}
