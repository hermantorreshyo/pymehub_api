<?php
declare(strict_types=1);

use PymeHub\Controllers\V1\AdminEmpresaController as Empresas;
use PymeHub\Controllers\V1\AlertaController as Alertas;
use PymeHub\Controllers\V1\BienestarController as Bienestar;
use PymeHub\Controllers\V1\CheckinController as Checkins;
use PymeHub\Controllers\V1\AuthController as AuthC;
use PymeHub\Controllers\V1\EmpleadoController as Empleados;
use PymeHub\Controllers\V1\EquipoController as Equipos;
use PymeHub\Controllers\V1\HealthController as Health;
use PymeHub\Controllers\V1\IncidenciaController as Incidencias;
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
    $gestion = Auth::require('GERENTE', 'RRHH');
    $empleado = Auth::require('EMPLEADO');
    $planBienestar = Auth::plan('bienestar');

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

    // Equipos: el gerente los gestiona, RR. HH. los ve (P-10)
    $r->add('GET',    '/v1/teams', [Equipos::class, 'index'], [$gestion]);
    $r->add('POST',   '/v1/teams', [Equipos::class, 'store'], [$gerente, $csrf]);
    $r->add('PATCH',  '/v1/teams/{id}', [Equipos::class, 'update'], [$gerente, $csrf]);
    $r->add('DELETE', '/v1/teams/{id}', [Equipos::class, 'destroy'], [$gerente, $csrf]);

    // Empleados, invitaciones, CSV e incidencias: gerente y RR. HH. (P-10)
    $r->add('GET',    '/v1/employees', [Empleados::class, 'index'], [$gestion]);
    $r->add('POST',   '/v1/employees', [Empleados::class, 'store'], [$gestion, $csrf]);
    $r->add('POST',   '/v1/employees/import', [Empleados::class, 'import'], [$gestion, $csrf]);
    $r->add('GET',    '/v1/employees/{id}', [Empleados::class, 'show'], [$gestion]);
    $r->add('PATCH',  '/v1/employees/{id}', [Empleados::class, 'update'], [$gestion, $csrf]);
    $r->add('DELETE', '/v1/employees/{id}', [Empleados::class, 'destroy'], [$gestion, $csrf]);
    $r->add('POST',   '/v1/employees/{id}/invitation', [Empleados::class, 'invite'], [$gestion, $csrf]);
    $r->add('GET',    '/v1/employees/{id}/incidents', [Incidencias::class, 'index'], [$gestion]);
    $r->add('POST',   '/v1/employees/{id}/incidents', [Incidencias::class, 'store'], [$gestion, $csrf]);

    // Bienestar (§8.6, fase 4)
    $r->add('POST', '/v1/me/checkins', [Checkins::class, 'store'], [$empleado, $planBienestar, $csrf]);
    $r->add('GET',  '/v1/me/checkins', [Checkins::class, 'index'], [$empleado, $planBienestar]);
    $r->add('GET',  '/v1/wellbeing/summary', [Bienestar::class, 'summary'], [$gestion, $planBienestar]);

    // Alertas (§8.4): bienestar en ambos planes; riesgo de rotación solo en Completo
    $r->add('GET',   '/v1/alerts', [Alertas::class, 'index'], [$gestion]);
    $r->add('PATCH', '/v1/alerts/{id}', [Alertas::class, 'update'], [$gestion, $csrf]);
};
