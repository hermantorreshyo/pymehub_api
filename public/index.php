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

// Modo mantenimiento (RNF-063): la salud, el estado público, el acceso y la plataforma siguen respondiendo
if (\PymeHub\Core\Mantenimiento::activo() && !\PymeHub\Core\Mantenimiento::permitida($request->path)) {
    if (Http::apply($request)) {
        exit; // preflight CORS
    }
    Response::error('PH-SYS-003', \PymeHub\Core\Mantenimiento::mensaje(), 503);
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
    if ($e->httpStatus >= 500) {
        Logger::error($e->getMessage(), ['code' => $e->errorCode, 'method' => $request->method, 'path' => $request->path]);
    }
    Response::error($e->errorCode, $e->getMessage(), $e->httpStatus, $e->details);
} catch (PDOException $e) {
    $connection = str_starts_with((string) $e->getCode(), '08') || str_contains($e->getMessage(), 'Connection refused');
    Logger::error('Error de base de datos', ['code' => $connection ? 'PH-SYS-002' : 'PH-SYS-001', 'method' => $request->method, 'ex' => $e->getMessage(), 'state' => $e->getCode(), 'path' => $request->path]);
    $connection
        ? Response::error('PH-SYS-002', 'Base de datos no disponible', 503)
        : Response::error('PH-SYS-001', 'Error interno. Si persiste, comunica este identificador a soporte.', 500);
} catch (Throwable $e) {
    Logger::error('Error interno', ['code' => 'PH-SYS-001', 'method' => $request->method, 'ex' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(), 'path' => $request->path]);
    Response::error('PH-SYS-001', 'Error interno. Si persiste, comunica este identificador a soporte.', 500);
}
