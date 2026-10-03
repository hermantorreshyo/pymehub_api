<?php
declare(strict_types=1);

namespace PymeHub\Middleware;

use PymeHub\Core\Config;
use PymeHub\Core\Request;

/**
 * CORS con lista blanca exacta (SEC-001) y cabeceras de seguridad (SEC-003, SEC-004).
 */
final class Http
{
    /** Devuelve true si la petición era un preflight ya respondido. */
    public static function apply(Request $request): bool
    {
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Cache-Control: no-store');
        if ((bool) Config::get('session.cookie_secure', true)) {
            header('Strict-Transport-Security: max-age=31536000');
        }

        $origin  = $request->header('origin');
        $allowed = (array) Config::get('cors.allowed_origins', []);
        if ($origin !== null && in_array($origin, $allowed, true)) {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Credentials: true');
            header('Access-Control-Expose-Headers: X-Correlation-Id');
            header('Vary: Origin');
        }

        if ($request->method === 'OPTIONS') {
            if ($origin !== null && in_array($origin, $allowed, true)) {
                header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
                header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');
                header('Access-Control-Max-Age: 600');
            }
            http_response_code(204);
            return true;
        }
        return false;
    }
}
