<?php
declare(strict_types=1);

use PymeHub\Controllers\V1\AdminEmpresaController as Empresas;
use PymeHub\Controllers\V1\AuthController as AuthC;
use PymeHub\Controllers\V1\HealthController as Health;
use PymeHub\Controllers\V1\UsuarioController as Usuarios;
use PymeHub\Core\Router;
use PymeHub\Middleware\Auth;

/**
 * Tabla de rutas de la API v1. Perfiles: ADMIN, GERENTE, RRHH, EMPLEADO.
 */
return static function (Router $r): void {
    $csrf    = Auth::csrf();
    $logged  = Auth::require();
    $admin   = Auth::require('ADMIN');
    $gerente = Auth::require('GERENTE');

    // Salud (pública)
    $r->add('GET', '/v1/health', [Health::class, 'show']);

    // Autenticación
    $r->add('POST', '/v1/auth/login', [AuthC::class, 'login']);
    $r->add('POST', '/v1/auth/invitations/accept', [AuthC::class, 'acceptInvitation']);
    $r->add('GET',  '/v1/auth/me', [AuthC::class, 'me'], [$logged]);
    $r->add('POST', '/v1/auth/logout', [AuthC::class, 'logout'], [$logged, $csrf]);

    // Plataforma
    $r->add('GET',   '/v1/admin/tenants', [Empresas::class, 'index'], [$admin]);
    $r->add('POST',  '/v1/admin/tenants', [Empresas::class, 'store'], [$admin, $csrf]);
    $r->add('GET',   '/v1/admin/tenants/{id}', [Empresas::class, 'show'], [$admin]);
    $r->add('PATCH', '/v1/admin/tenants/{id}', [Empresas::class, 'update'], [$admin, $csrf]);
    $r->add('GET',   '/v1/admin/metrics', [Empresas::class, 'metrics'], [$admin]);

    // Cuentas de RR. HH. (solo gerente)
    $r->add('GET',    '/v1/users', [Usuarios::class, 'index'], [$gerente]);
    $r->add('POST',   '/v1/users', [Usuarios::class, 'store'], [$gerente, $csrf]);
    $r->add('PATCH',  '/v1/users/{id}', [Usuarios::class, 'update'], [$gerente, $csrf]);
    $r->add('DELETE', '/v1/users/{id}', [Usuarios::class, 'destroy'], [$gerente, $csrf]);
    $r->add('POST',   '/v1/users/{id}/invitation', [Usuarios::class, 'invite'], [$gerente, $csrf]);
};
