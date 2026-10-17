<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Cliente mínimo de la API de Stripe (fase 9c), sin SDK ni Composer.
 *
 * - Solo en MODO PRUEBA: con una clave sk_live_ queda desactivado (el MVP no cobra importes reales).
 * - Las claves viven en config.php (stripe.secret_key, stripe.webhook_secret) y nunca se registran.
 * - stripe.api_base permite apuntar a un simulador local en las pruebas automáticas.
 */
final class Stripe
{
    private const VERSION_API = '2024-06-20';

    public static function habilitado(): bool
    {
        $key = (string) Config::get('stripe.secret_key', '');
        return (bool) Config::get('stripe.enabled', false)
            && str_starts_with($key, 'sk_test_');
    }

    public static function exigir(): void
    {
        if (!self::habilitado()) {
            throw new ApiException('PH-PAY-001', 'El pago online no está disponible. Escríbenos y te ayudamos a renovar.', 503);
        }
    }

    /**
     * Llama a la API de Stripe. Parámetros anidados con la notación de Stripe (a[b][c]=…).
     * @return array<string, mixed>
     */
    public static function api(string $method, string $path, array $params = [], ?string $idempotencia = null): array
    {
        self::exigir();
        $base = rtrim((string) Config::get('stripe.api_base', 'https://api.stripe.com'), '/');
        $url = $base . $path;
        $body = http_build_query($params, '', '&', PHP_QUERY_RFC1738);
        if ($method === 'GET' && $body !== '') {
            $url .= '?' . $body;
            $body = '';
        }
        $headers = [
            'Authorization: Bearer ' . Config::get('stripe.secret_key'),
            'Stripe-Version: ' . self::VERSION_API,
            'Content-Type: application/x-www-form-urlencoded',
        ];
        if ($idempotencia !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencia;
        }

        [$status, $raw] = self::http($method, $url, $headers, $body);
        $data = json_decode((string) $raw, true);
        if ($status < 200 || $status >= 300 || !is_array($data)) {
            $tipo = is_array($data) ? (string) ($data['error']['type'] ?? '') : '';
            Logger::error('Stripe respondió con error', ['status' => $status, 'ruta' => $path, 'tipo' => $tipo,
                'stripe_code' => is_array($data) ? ($data['error']['code'] ?? null) : null]);
            throw new ApiException('PH-PAY-002', 'No hemos podido conectar con la pasarela de pago. Inténtalo de nuevo en unos minutos.', 502);
        }
        return $data;
    }

    /** @return array{0:int,1:string|false} */
    private static function http(string $method, string $url, array $headers, string $body): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
            ]);
            if ($method !== 'GET') {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            }
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            return [$status, $raw];
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $method === 'GET' ? '' : $body,
            'timeout' => 20, 'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        $status = 0;
        foreach ($http_response_header ?? [] as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return [$status, $raw];
    }

    /**
     * Verifica la cabecera Stripe-Signature (t=…,v1=…) con el secreto del webhook:
     * HMAC-SHA256 de "t.payload", comparación en tiempo constante y tolerancia de 5 minutos.
     */
    public static function firmaValida(string $payload, string $cabecera, string $secreto, int $tolerancia = 300, ?int $ahora = null): bool
    {
        if ($secreto === '' || $cabecera === '') {
            return false;
        }
        $t = null;
        $firmas = [];
        foreach (explode(',', $cabecera) as $parte) {
            [$k, $v] = array_pad(explode('=', trim($parte), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $t = (int) $v;
            } elseif ($k === 'v1') {
                $firmas[] = $v;
            }
        }
        if ($t === null || $firmas === [] || abs(($ahora ?? time()) - $t) > $tolerancia) {
            return false;
        }
        $esperada = hash_hmac('sha256', $t . '.' . $payload, $secreto);
        foreach ($firmas as $f) {
            if (hash_equals($esperada, $f)) {
                return true;
            }
        }
        return false;
    }

    /** Céntimos de Stripe → euros con dos decimales. */
    public static function euros(mixed $centimos): float
    {
        return round(((int) $centimos) / 100, 2);
    }
}
