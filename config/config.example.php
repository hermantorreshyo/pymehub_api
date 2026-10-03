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
        'host' => '127.0.0.1',
        'port' => 3306,
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
];
