<?php
// Ejemplo de configuración. El archivo real (config/config.php) lo genera el
// asistente /install y NO se versiona. Permisos recomendados: 640.
return [
    'app' => [
        'env'          => 'production',
        'api_url'      => 'https://api.pymehub.hermantorres.com',
        'frontend_url' => 'https://pymehub.hermantorres.com',
    ],
    'db' => [
        'engine' => 'mariadb',     // mariadb | mysql (lo detecta el instalador, DEC-05)
        'host' => '127.0.0.1',     // 'localhost' si el usuario está creado como 'usuario'@'localhost'
        'port' => 3306,
        'socket' => '',            // opcional: ruta del socket Unix (p. ej. /run/mysqld/mysqld.sock)
        'name' => 'pymehub',
        'user' => 'pymehub_user',
        'pass' => 'CAMBIAR',
    ],
    'session' => [
        'cookie_domain' => '',     // vacío = solo el host de la API
        'cookie_secure' => true,   // false solo en desarrollo local sin HTTPS
    ],
    'cors' => [
        'allowed_origins' => ['https://pymehub.hermantorres.com'],
    ],
    'crypto' => [
        'key' => 'BASE64_GENERADA_POR_EL_INSTALADOR', // cifrado de notas de check-in (LEG-003)
    ],
    'ia' => [
        'provider' => 'mistral',
        'base_url' => 'https://api.mistral.ai/v1',
        'model'    => 'mistral-small-latest',
        'api_key'  => '',
        'mode'     => 'offline', // live | cache_first | offline
    ],
    // Demostración comercial y QR del folleto (docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md)
    'demo' => [
        'habilitada'       => true,           // false = la demo se apaga sin desplegar (PH-DEMO-002)
        'habilitada_hasta' => null,           // 'AAAA-MM-DD' (incluido) o null, sin caducidad
        'password'         => 'PymeHub-Demo', // impresa en el folleto: gerente@, rrhh@, conductor@ y gerente2@
        'password_admin'   => 'CAMBIAR',      // admin@pymehub.demo, solo pruebas internas: nunca se publica
    ],
    'campaigns' => ['folleto_comercial'],     // campañas admitidas en POST /v1/public/visits
    // Pagos con Stripe, SOLO EN MODO PRUEBA (fase 9c). Con una clave sk_live_ el pago online se desactiva.
    // Las claves se copian del panel de Stripe (modo prueba) en el servidor; nunca se versionan ni se comparten.
    'stripe' => [
        'enabled'         => false,
        'secret_key'      => '',   // sk_test_…  (Desarrolladores → Claves de API)
        'publishable_key' => '',   // pk_test_…  (no la usa la API; se guarda por completitud)
        'webhook_secret'  => '',   // whsec_…    (Desarrolladores → Webhooks → endpoint de Pyme Hub)
        // 'api_base'     => 'http://127.0.0.1:12111', // solo pruebas locales con tests/stripe_mock.php
    ],
];
