<?php
declare(strict_types=1);

use PymeHub\Controllers\V1\AdminEmpresaController as Empresas;
use PymeHub\Controllers\V1\AlertaController as Alertas;
use PymeHub\Controllers\V1\AsesorController as Asesor;
use PymeHub\Controllers\V1\BienestarController as Bienestar;
use PymeHub\Controllers\V1\CheckinController as Checkins;
use PymeHub\Controllers\V1\AuthController as AuthC;
use PymeHub\Controllers\V1\EmpleadoController as Empleados;
use PymeHub\Controllers\V1\EquipoController as Equipos;
use PymeHub\Controllers\V1\FormacionController as Formacion;
use PymeHub\Controllers\V1\HealthController as Health;
use PymeHub\Controllers\V1\IncidenciaController as Incidencias;
use PymeHub\Controllers\V1\MiFormacionController as MiFormacion;
use PymeHub\Controllers\V1\OperacionController as Operacion;
use PymeHub\Controllers\V1\PagoController as Pagos;
use PymeHub\Controllers\V1\ParametrosController as Parametros;
use PymeHub\Controllers\V1\PublicoController as Publico;
use PymeHub\Controllers\V1\RiesgoController as Riesgo;
use PymeHub\Controllers\V1\UsuarioController as Usuarios;
use PymeHub\Core\Router;
use PymeHub\Middleware\Auth;

/**
 * Tabla de rutas de la API v1. Perfiles: ADMIN, GERENTE, RRHH, EMPLEADO.
 */
return static function (Router $r): void {
    $csrf    = Auth::csrf();
    $noDemo  = Auth::noDemo();   // DMO-024: PH-DEMO-001 para usuarios de la demostración
    $logged  = Auth::require();
    $admin   = Auth::require('ADMIN');
    $gerente = Auth::require('GERENTE');
    $gestion = Auth::require('GERENTE', 'RRHH');
    $empleado = Auth::require('EMPLEADO');
    $planBienestar = Auth::plan('bienestar');
    $planFormacion = Auth::plan('formacion');
    $planAsesor = Auth::plan('asesor_formacion');
    $planRiesgo = Auth::plan('riesgo_rotacion');
    $planLineaBase = Auth::plan('linea_base');

    // Salud (pública)
    $r->add('GET', '/v1/health', [Health::class, 'show']);

    // Público: demo comercial y métricas del QR (REQ_QR_DEMO_COMERCIAL)
    $r->add('POST', '/v1/public/visits', [Publico::class, 'visit']);
    $r->add('GET',  '/v1/public/demo-status', [Publico::class, 'demoStatus']);
    $r->add('GET',  '/v1/public/status', [Operacion::class, 'status']);
    $r->add('GET',  '/v1/public/params', [Parametros::class, 'publicos']);

    // Stripe (modo prueba, fase 9c): sin sesión ni CSRF; la firma Stripe-Signature es obligatoria
    $r->add('POST', '/v1/stripe/webhook', [Pagos::class, 'webhook']);

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
    $r->add('GET',   '/v1/admin/ai-usage', [Asesor::class, 'usage'], [$admin]);
    $r->add('GET',   '/v1/admin/campaign-metrics', [Publico::class, 'metrics'], [$admin]);
    // Operación (fase 9): exportación, mantenimiento y errores
    $r->add('GET',   '/v1/admin/tenants/{id}/export', [Operacion::class, 'exportTenant'], [$admin]);
    $r->add('GET',   '/v1/admin/maintenance', [Operacion::class, 'maintenance'], [$admin]);
    $r->add('PUT',   '/v1/admin/maintenance', [Operacion::class, 'saveMaintenance'], [$admin, $csrf]);
    $r->add('GET',   '/v1/admin/errors', [Operacion::class, 'errors'], [$admin]);
    $r->add('GET',   '/v1/admin/params', [Parametros::class, 'index'], [$admin]);
    $r->add('PUT',   '/v1/admin/params', [Parametros::class, 'update'], [$admin, $csrf]);
    // Vigencia y pagos (fases 9b y 9c)
    $r->add('GET',   '/v1/admin/payments', [Pagos::class, 'adminPayments'], [$admin]);
    $r->add('PUT',   '/v1/admin/tenants/{id}/validity', [Pagos::class, 'adminValidity'], [$admin, $csrf]);

    // Portabilidad y derechos RGPD (LEG-017, §8.8)
    $r->add('GET',  '/v1/company/export', [Operacion::class, 'exportCompany'], [$gerente, $noDemo]);

    // Suscripción y pagos de la empresa (fases 9b y 9c): disponibles también con la suscripción vencida
    $r->add('GET',  '/v1/billing/subscription', [Pagos::class, 'subscription'], [$gerente]);
    $r->add('GET',  '/v1/billing/invoices', [Pagos::class, 'invoices'], [$gerente]);
    $r->add('POST', '/v1/billing/checkout', [Pagos::class, 'checkout'], [$gerente, $noDemo, $csrf]);
    $r->add('POST', '/v1/billing/portal', [Pagos::class, 'portal'], [$gerente, $noDemo, $csrf]);
    $r->add('GET',  '/v1/me/data-export', [Operacion::class, 'myData'], [$logged, $noDemo]);
    $r->add('POST', '/v1/employees/{id}/erase', [Operacion::class, 'erase'], [$gerente, $noDemo, $csrf]);

    // Cuentas de RR. HH. (solo gerente)
    $r->add('GET',    '/v1/users', [Usuarios::class, 'index'], [$gerente]);
    $r->add('POST',   '/v1/users', [Usuarios::class, 'store'], [$gerente, $noDemo, $csrf]);
    $r->add('PATCH',  '/v1/users/{id}', [Usuarios::class, 'update'], [$gerente, $noDemo, $csrf]);
    $r->add('DELETE', '/v1/users/{id}', [Usuarios::class, 'destroy'], [$gerente, $noDemo, $csrf]);
    $r->add('POST',   '/v1/users/{id}/invitation', [Usuarios::class, 'invite'], [$gerente, $noDemo, $csrf]);

    // Equipos: el gerente los gestiona, RR. HH. los ve (P-10)
    $r->add('GET',    '/v1/teams', [Equipos::class, 'index'], [$gestion]);
    $r->add('POST',   '/v1/teams', [Equipos::class, 'store'], [$gerente, $csrf]);
    $r->add('PATCH',  '/v1/teams/{id}', [Equipos::class, 'update'], [$gerente, $csrf]);
    $r->add('DELETE', '/v1/teams/{id}', [Equipos::class, 'destroy'], [$gerente, $csrf]);

    // Empleados, invitaciones, CSV e incidencias: gerente y RR. HH. (P-10)
    $r->add('GET',    '/v1/employees', [Empleados::class, 'index'], [$gestion]);
    $r->add('POST',   '/v1/employees', [Empleados::class, 'store'], [$gestion, $csrf]);
    $r->add('POST',   '/v1/employees/import', [Empleados::class, 'import'], [$gestion, $noDemo, $csrf]);
    $r->add('GET',    '/v1/employees/{id}', [Empleados::class, 'show'], [$gestion]);
    $r->add('PATCH',  '/v1/employees/{id}', [Empleados::class, 'update'], [$gestion, $csrf]);
    $r->add('DELETE', '/v1/employees/{id}', [Empleados::class, 'destroy'], [$gestion, $noDemo, $csrf]);
    $r->add('POST',   '/v1/employees/{id}/invitation', [Empleados::class, 'invite'], [$gestion, $noDemo, $csrf]);
    $r->add('GET',    '/v1/employees/{id}/incidents', [Incidencias::class, 'index'], [$gestion]);
    $r->add('POST',   '/v1/employees/{id}/incidents', [Incidencias::class, 'store'], [$gestion, $csrf]);

    // Bienestar (§8.6, fase 4)
    $r->add('POST', '/v1/me/checkins', [Checkins::class, 'store'], [$empleado, $planBienestar, $csrf]);
    $r->add('GET',  '/v1/me/checkins', [Checkins::class, 'index'], [$empleado, $planBienestar]);
    $r->add('GET',  '/v1/wellbeing/summary', [Bienestar::class, 'summary'], [$gestion, $planBienestar]);

    // Formación (§8.5, fase 5): gestión
    $r->add('GET',    '/v1/training/modules', [Formacion::class, 'modules'], [$gestion, $planFormacion]);
    $r->add('POST',   '/v1/training/modules', [Formacion::class, 'storeModule'], [$gestion, $planFormacion, $csrf]);
    $r->add('GET',    '/v1/training/modules/{id}', [Formacion::class, 'module'], [$gestion, $planFormacion]);
    $r->add('PATCH',  '/v1/training/modules/{id}', [Formacion::class, 'updateModule'], [$gestion, $planFormacion, $csrf]);
    $r->add('POST',   '/v1/training/modules/{id}/publish', [Formacion::class, 'publish'], [$gestion, $planFormacion, $csrf]);
    $r->add('GET',    '/v1/training/assignments', [Formacion::class, 'assignments'], [$gestion, $planFormacion]);
    $r->add('POST',   '/v1/training/assignments', [Formacion::class, 'assign'], [$gestion, $planFormacion, $csrf]);
    $r->add('DELETE', '/v1/training/assignments/{id}', [Formacion::class, 'unassign'], [$gestion, $planFormacion, $csrf]);
    $r->add('GET',    '/v1/training/recommendations', [Formacion::class, 'recommendations'], [$gestion, $planFormacion]);
    $r->add('GET',    '/v1/training/summary', [Formacion::class, 'summary'], [$gestion, $planFormacion]);
    $r->add('GET',    '/v1/training/demand', [Asesor::class, 'demand'], [$gestion, $planAsesor]);

    // Formación del empleado
    $r->add('GET',  '/v1/me/training', [MiFormacion::class, 'index'], [$empleado, $planFormacion]);
    $r->add('GET',  '/v1/me/training/catalog', [MiFormacion::class, 'catalog'], [$empleado, $planFormacion]);

    // Asesor de formación con IA (§8.7, fase 6)
    $r->add('GET',    '/v1/me/training/preferences', [Asesor::class, 'preferences'], [$empleado, $planAsesor]);
    $r->add('PUT',    '/v1/me/training/preferences', [Asesor::class, 'savePreferences'], [$empleado, $planAsesor, $csrf]);
    $r->add('DELETE', '/v1/me/training/preferences', [Asesor::class, 'deletePreferences'], [$empleado, $planAsesor, $csrf]);
    $r->add('POST',   '/v1/me/training/advisor', [Asesor::class, 'advise'], [$empleado, $planAsesor, $csrf]);
    $r->add('POST',   '/v1/me/training/advisor/feedback', [Asesor::class, 'feedback'], [$empleado, $planAsesor, $csrf]);

    $r->add('POST', '/v1/me/training/enroll', [MiFormacion::class, 'enroll'], [$empleado, $planFormacion, $csrf]);
    $r->add('GET',  '/v1/me/training/{id}', [MiFormacion::class, 'show'], [$empleado, $planFormacion]);
    $r->add('POST', '/v1/me/training/{id}/progress', [MiFormacion::class, 'progress'], [$empleado, $planFormacion, $csrf]);
    $r->add('POST', '/v1/me/training/{id}/submit', [MiFormacion::class, 'submit'], [$empleado, $planFormacion, $csrf]);

    // IA para RR. HH. (§9, fase 7): panel en ambos planes; riesgo y línea base solo en Completo (RF-010)
    $r->add('GET',  '/v1/dashboard/overview', [Riesgo::class, 'overview'], [$gestion]);
    $r->add('GET',  '/v1/dashboard/trends', [Riesgo::class, 'trends'], [$gestion, $planRiesgo]);
    $r->add('GET',  '/v1/risk', [Riesgo::class, 'index'], [$gestion, $planRiesgo]);
    $r->add('POST', '/v1/risk/recalculate', [Riesgo::class, 'recalculate'], [$gestion, $planRiesgo, $csrf]);
    $r->add('GET',  '/v1/risk/methodology', [Riesgo::class, 'methodology'], [$gestion, $planRiesgo]);
    $r->add('GET',  '/v1/risk/bias', [Riesgo::class, 'bias'], [$gestion, $planRiesgo]);
    $r->add('GET',  '/v1/employees/{id}/risk', [Riesgo::class, 'show'], [$gestion, $planRiesgo]);
    $r->add('GET',  '/v1/baseline', [Riesgo::class, 'baseline'], [$gestion, $planLineaBase]);
    $r->add('PUT',  '/v1/baseline', [Riesgo::class, 'saveBaseline'], [$gestion, $planLineaBase, $csrf]);

    // Alertas (§8.4): bienestar en ambos planes; riesgo de rotación solo en Completo
    $r->add('GET',   '/v1/alerts', [Alertas::class, 'index'], [$gestion]);
    $r->add('PATCH', '/v1/alerts/{id}', [Alertas::class, 'update'], [$gestion, $csrf]);
};
