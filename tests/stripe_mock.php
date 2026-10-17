<?php
declare(strict_types=1);

/**
 * Simulador mínimo de la API de Stripe para la prueba de humo en local (fase 9c).
 * NO es Stripe: solo responde a lo que usa Pyme Hub para comprobar el flujo sin red.
 *
 *   php -S 127.0.0.1:12111 tests/stripe_mock.php
 *   config.php → 'stripe' => ['enabled' => true, 'secret_key' => 'sk_test_local', 'webhook_secret' => 'whsec_local',
 *                             'api_base' => 'http://127.0.0.1:12111']
 *
 * GET /__mock/state devuelve lo recibido (sesiones, cantidades) para las comprobaciones.
 */
$estadoArchivo = sys_get_temp_dir() . '/pymehub_stripe_mock.json';
$estado = is_file($estadoArchivo) ? (json_decode((string) file_get_contents($estadoArchivo), true) ?: []) : [];
$estado += ['n' => 0, 'sesiones' => [], 'suscripciones' => [], 'clientes' => [], 'tasas' => [], 'peticiones' => 0];
$metodo = $_SERVER['REQUEST_METHOD'];
$ruta = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
parse_str((string) file_get_contents('php://input'), $p);
header('Content-Type: application/json');

$guardar = static function () use (&$estado, $estadoArchivo): void {
    file_put_contents($estadoArchivo, json_encode($estado));
};
$salir = static function (int $status, array $data) use ($guardar): never {
    $guardar();
    http_response_code($status);
    echo json_encode($data);
    exit;
};

if ($ruta === '/__mock/state') {
    $salir(200, $estado);
}
if ($ruta === '/__mock/reset') {
    @unlink($estadoArchivo);
    echo '{}';
    exit;
}
if (!preg_match('/^Bearer sk_test_/', $_SERVER['HTTP_AUTHORIZATION'] ?? '')) {
    $salir(401, ['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API key']]);
}
$estado['peticiones']++;
$id = static function (string $pref) use (&$estado): string {
    return $pref . '_mock_' . (++$estado['n']);
};

switch (true) {
    case $metodo === 'POST' && $ruta === '/v1/customers':
        $c = ['id' => $id('cus'), 'object' => 'customer', 'name' => $p['name'] ?? null, 'email' => $p['email'] ?? null, 'metadata' => $p['metadata'] ?? []];
        $estado['clientes'][$c['id']] = $c;
        $salir(200, $c);
    case $metodo === 'POST' && $ruta === '/v1/tax_rates':
        $t = ['id' => $id('txr'), 'object' => 'tax_rate', 'percentage' => (float) ($p['percentage'] ?? 0), 'inclusive' => ($p['inclusive'] ?? '') === 'true'];
        $estado['tasas'][$t['id']] = $t;
        $salir(200, $t);
    case $metodo === 'POST' && $ruta === '/v1/checkout/sessions':
        if (empty($p['customer']) || empty($p['line_items'][0]['price_data']['unit_amount'])) {
            $salir(400, ['error' => ['type' => 'invalid_request_error', 'message' => 'Missing params']]);
        }
        $s = ['id' => $id('cs'), 'object' => 'checkout.session', 'url' => 'https://checkout.stripe.test/c/pay/' . $estado['n'], 'params' => $p];
        // La suscripción que crearía Stripe al pagar
        $sub = $id('sub');
        $estado['suscripciones'][$sub] = [
            'id' => $sub, 'object' => 'subscription', 'customer' => $p['customer'],
            'status' => isset($p['subscription_data']['trial_end']) ? 'trialing' : 'active',
            'trial_end' => isset($p['subscription_data']['trial_end']) ? (int) $p['subscription_data']['trial_end'] : null,
            'cancel_at_period_end' => false, 'metadata' => $p['subscription_data']['metadata'] ?? [],
            'items' => ['data' => [['id' => $id('si'), 'quantity' => (int) $p['line_items'][0]['quantity'],
                'current_period_end' => time() + 30 * 86400]]],
        ];
        $s['subscription_simulada'] = $sub;
        $estado['sesiones'][] = $s;
        $salir(200, $s);
    case $metodo === 'POST' && $ruta === '/v1/billing_portal/sessions':
        $salir(200, ['id' => $id('bps'), 'url' => 'https://billing.stripe.test/p/session/' . $estado['n'], 'customer' => $p['customer'] ?? null]);
    case preg_match('#^/v1/subscriptions/([\w]+)$#', $ruta, $m) === 1:
        $sub = $estado['suscripciones'][$m[1]] ?? null;
        if ($sub === null) {
            $salir(404, ['error' => ['type' => 'invalid_request_error', 'message' => 'No such subscription']]);
        }
        if ($metodo === 'POST' && isset($p['items'][0]['quantity'])) {
            $estado['suscripciones'][$m[1]]['items']['data'][0]['quantity'] = (int) $p['items'][0]['quantity'];
            $estado['suscripciones'][$m[1]]['proration_behavior'] = $p['proration_behavior'] ?? null;
        }
        $salir(200, $estado['suscripciones'][$m[1]]);
    default:
        $salir(404, ['error' => ['type' => 'invalid_request_error', 'message' => "Unrecognized request URL ($metodo $ruta)"]]);
}
