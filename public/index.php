<?php
declare(strict_types=1);

/**
 * Pyme Hub API · front controller.
 */
require dirname(__DIR__) . '/src/bootstrap.php';

use PymeHub\Core\ApiException;
use PymeHub\Core\Config;
use PymeHub\Core\Logger;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Router;
use PymeHub\Middleware\Http;

ini_set('display_errors', '0');
error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

$request = Request::fromGlobals();

// Asistente de instalación: solo mientras no exista storage/installed.lock
if (!Config::isInstalled()) {
    require PH_ROOT . '/install/Installer.php';
    (new \PymeHub\Install\Installer())->handle($request);
    exit;
}
if ($request->path === '/install') {
    Response::error('PH-TENANT-001', 'Ruta no encontrada', 404);
    exit;
}

// Modo mantenimiento (RNF-063)
if (is_file(PH_ROOT . '/storage/maintenance.flag') && $request->path !== '/v1/health') {
    Http::apply($request);
    Response::error('PH-SYS-003', 'Pyme Hub está en mantenimiento. Vuelve a intentarlo en unos minutos.', 503);
    exit;
}

try {
    if (Http::apply($request)) {
        exit;
    }
    $router = new Router();
    (require PH_ROOT . '/src/routes.php')($router);
    $router->dispatch($request);
} catch (ApiException $e) {
    Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
} catch (PDOException $e) {
    Logger::error('Error de base de datos', ['ex' => $e->getMessage(), 'state' => $e->getCode(), 'path' => $request->path]);
    $connection = str_starts_with((string) $e->getCode(), '08') || str_contains($e->getMessage(), 'Connection refused');
    $connection
        ? Response::error('PH-SYS-002', 'Base de datos no disponible', 503)
        : Response::error('PH-SYS-001', 'Error interno. Si persiste, comunica este identificador a soporte.', 500);
} catch (Throwable $e) {
    Logger::error('Error interno', ['ex' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'path' => $request->path]);
    Response::error('PH-SYS-001', 'Error interno. Si persiste, comunica este identificador a soporte.', 500);
}
