<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Db;
use PymeHub\Core\Plan;
use PymeHub\Core\RateLimiter;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Session;
use PymeHub\Core\Validator;
use PymeHub\Repositories\UsuarioRepository;
use PymeHub\Services\InvitacionService;

final class AuthController
{
    private const MAX_INTENTOS = 5;
    private const VENTANA = 900; // 15 minutos (RF-006)

    /** POST /v1/auth/login */
    public static function login(Request $request): void
    {
        $in = Validator::validate($request->json(), [
            'email'    => 'required|email',
            'password' => 'required|string|max:200',
        ]);
        $key = 'login:' . $in['email'] . '|' . $request->ip;
        if (RateLimiter::exceeded($key, self::MAX_INTENTOS, self::VENTANA)) {
            throw new ApiException('PH-AUTH-004', 'Demasiados intentos fallidos. Espera 15 minutos e inténtalo de nuevo.', 429);
        }

        $u = UsuarioRepository::findByEmail($in['email']);
        $ok = $u !== null
            && $u['estado_acceso'] === 'ACTIVO'
            && $u['i_estado'] !== 'SUSPENDIDO'
            && is_string($u['password_hash'])
            && password_verify($in['password'], $u['password_hash']);

        if (!$ok) {
            // Mismo coste aunque el usuario no exista, para no revelar cuentas
            if ($u === null) {
                password_verify($in['password'], '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHRzb21lc2FsdA$4C6s3iOPqTfFQbO3Bv9dZb6kIbhX4yJHdP2CRNLOqi8');
            }
            RateLimiter::hit($key, PHP_INT_MAX, self::VENTANA);
            throw new ApiException('PH-AUTH-001', 'Email o contraseña incorrectos', 401);
        }

        if (password_needs_rehash($u['password_hash'], PASSWORD_ARGON2ID)) {
            Db::run('UPDATE usuario SET password_hash = ? WHERE id = ?', [password_hash($in['password'], PASSWORD_ARGON2ID), $u['id']]);
        }
        RateLimiter::reset($key);
        Session::login((int) $u['id'], (string) $u['perfil']);
        Db::run('UPDATE usuario SET ultimo_acceso_en = UTC_TIMESTAMP() WHERE id = ?', [$u['id']]);

        Context::$usuario = $u;
        Context::$interlocutor = ['id' => (int) $u['interlocutor_id']];
        Audit::log('auth.login', 'usuario', (int) $u['id'], $request->ip);

        self::me($request);
    }

    /** POST /v1/auth/logout */
    public static function logout(Request $request): void
    {
        Audit::log('auth.logout', 'usuario', Context::usuarioId(), $request->ip);
        Session::destroy();
        Response::json(null, 204);
    }

    /** GET /v1/auth/me */
    public static function me(Request $request): void
    {
        $u = Db::one(
            "SELECT u.id, u.perfil, u.nombre, u.apellidos, u.email, u.privacidad_aceptada_en,
                    i.id AS i_id, i.tipo AS i_tipo, i.nombre AS i_nombre, i.estado AS i_estado
               FROM usuario u JOIN interlocutor i ON i.id = u.interlocutor_id
              WHERE u.id = ?",
            [Session::userId()]
        );
        $s = $u['i_tipo'] === 'EMPRESA'
            ? Db::one("SELECT plan, ciclo_cobro FROM suscripcion WHERE interlocutor_id = ? AND estado = 'VIGENTE'", [$u['i_id']])
            : null;

        Response::json([
            'usuario' => [
                'id'        => (int) $u['id'],
                'nombre'    => $u['nombre'],
                'apellidos' => $u['apellidos'],
                'email'     => $u['email'],
                'perfil'    => $u['perfil'],
            ],
            'interlocutor' => [
                'id'     => (int) $u['i_id'],
                'tipo'   => $u['i_tipo'],
                'nombre' => $u['i_nombre'],
                'estado' => $u['i_estado'],
            ],
            'plan'       => $s['plan'] ?? null,
            'features'   => Plan::features($s['plan'] ?? null),
            'csrf_token' => Session::csrfToken(),
            'sesion'     => ['inactividad_max_seg' => Session::idleSeconds()],
        ]);
    }

    /** POST /v1/auth/invitations/accept */
    public static function acceptInvitation(Request $request): void
    {
        RateLimiter::hit('invitacion:' . $request->ip, 20, 3600);
        $in = Validator::validate($request->json(), [
            'token'              => 'required|string|min:20|max:100',
            'password'           => 'required|string|min:10|max:200',
            'acepta_privacidad'  => 'required|bool',
        ]);
        if ($in['acepta_privacidad'] !== true) {
            throw ApiException::validation(['acepta_privacidad' => 'Debes aceptar el aviso de privacidad']);
        }
        $r = InvitacionService::accept($in['token'], $in['password']);
        Context::$usuario = ['id' => $r['usuario_id']];
        Audit::log('auth.invitacion_aceptada', 'usuario', $r['usuario_id'], $request->ip);
        Response::json(['email' => $r['email'], 'mensaje' => 'Cuenta activada. Ya puedes iniciar sesión.']);
    }
}
