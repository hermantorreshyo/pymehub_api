<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Sesión en cookie HttpOnly + Secure + SameSite=Strict (DEC-06).
 * Caducidad por inactividad según perfil (RF-003):
 *  - EMPLEADO: 7 días · ADMIN, GERENTE y RRHH: 30 minutos.
 */
final class Session
{
    public const IDLE_EMPLEADO = 7 * 24 * 3600;
    public const IDLE_GESTION  = 30 * 60;

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = PH_ROOT . '/storage/sessions';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        ini_set('session.save_path', $dir);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) self::IDLE_EMPLEADO);
        session_name('PHSESSID');
        session_set_cookie_params([
            'lifetime' => self::IDLE_EMPLEADO,
            'path'     => '/',
            'domain'   => (string) Config::get('session.cookie_domain', ''),
            'secure'   => (bool) Config::get('session.cookie_secure', true),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_start();
    }

    /** Inicia sesión autenticada: regenera ID y token CSRF (RF-002). */
    public static function login(int $usuarioId, string $perfil): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION = [
            'usuario_id'    => $usuarioId,
            'perfil'        => $perfil,
            'last_activity' => time(),
            'csrf'          => bin2hex(random_bytes(32)),
        ];
    }

    /** Devuelve el ID de usuario si la sesión sigue viva; null si no. */
    public static function userId(): ?int
    {
        self::start();
        if (!isset($_SESSION['usuario_id'])) {
            return null;
        }
        $idle = ($_SESSION['perfil'] ?? '') === 'EMPLEADO' ? self::IDLE_EMPLEADO : self::IDLE_GESTION;
        if (time() - (int) ($_SESSION['last_activity'] ?? 0) > $idle) {
            self::destroy();
            return null;
        }
        $_SESSION['last_activity'] = time();
        return (int) $_SESSION['usuario_id'];
    }

    public static function csrfToken(): string
    {
        self::start();
        return (string) ($_SESSION['csrf'] ?? '');
    }

    public static function idleSeconds(): int
    {
        return ($_SESSION['perfil'] ?? '') === 'EMPLEADO' ? self::IDLE_EMPLEADO : self::IDLE_GESTION;
    }

    public static function destroy(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        $_SESSION = [];
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 3600,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        session_destroy();
    }
}
