<?php
declare(strict_types=1);

namespace PymeHub\Controllers\V1;

use PymeHub\Core\ApiException;
use PymeHub\Core\Audit;
use PymeHub\Core\Context;
use PymeHub\Core\Parametros;
use PymeHub\Core\Request;
use PymeHub\Core\Response;
use PymeHub\Core\Validator;

/**
 * Parámetros de la plataforma (fase 9a): titular (pendiente de constitución), precios sin IVA,
 * IVA, descuento anual, prueba, gracia, borrado tras la baja, marketplace y versión legal.
 */
final class ParametrosController
{
    /** GET /v1/public/params: precios y titular para la web y los textos legales. */
    public static function publicos(Request $request): void
    {
        Validator::validate($request->query, []);
        $p = Parametros::publicos();
        Response::json($p + [
            'ejemplos' => [
                'ENTRADA'  => ['MENSUAL' => Parametros::importe('ENTRADA', 'MENSUAL', 1), 'ANUAL' => Parametros::importe('ENTRADA', 'ANUAL', 1)],
                'COMPLETO' => ['MENSUAL' => Parametros::importe('COMPLETO', 'MENSUAL', 1), 'ANUAL' => Parametros::importe('COMPLETO', 'ANUAL', 1)],
            ],
        ]);
    }

    /** GET /v1/admin/params (ADMIN) */
    public static function index(Request $request): void
    {
        Validator::validate($request->query, []);
        $out = [];
        foreach (Parametros::DEF as $k => $d) {
            $out[] = ['clave' => $k, 'tipo' => $d[0], 'grupo' => $d[1], 'etiqueta' => $d[2],
                'min' => $d[0] === 'int' || $d[0] === 'money' ? $d[3] : null, 'max' => $d[0] === 'int' || $d[0] === 'money' ? $d[4] : ($d[3] ?? null),
                'valor' => Parametros::get($k)];
        }
        Response::json($out);
    }

    /** PUT /v1/admin/params (ADMIN): { "clave": valor, … } parcial. */
    public static function update(Request $request): void
    {
        $body = $request->json();
        if ($body === [] || array_is_list($body)) {
            throw ApiException::validation(['parametros' => 'Envía un objeto { clave: valor }']);
        }
        $errores = Parametros::guardar($body, Context::usuarioId());
        if ($errores !== []) {
            throw ApiException::validation($errores);
        }
        Audit::log('parametros.actualizados', null, null, $request->ip);
        self::index($request);
    }
}
