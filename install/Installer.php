<?php
declare(strict_types=1);

namespace PymeHub\Install;

use PDO;
use PymeHub\Core\Db;
use PymeHub\Core\Migrator;
use PymeHub\Core\Request;

/**
 * Asistente de instalación (RF-090 a RF-095).
 * Disponible solo mientras no exista storage/installed.lock.
 */
final class Installer
{
    private const PHP_MIN    = '8.3.0';
    private const MYSQL_MIN  = '8.4.0';
    private const EXTENSIONS = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'sodium', 'fileinfo', 'intl', 'curl'];

    private string $tokenFile;

    public function __construct()
    {
        $this->tokenFile = PH_ROOT . '/storage/install.token';
    }

    public function handle(Request $request): void
    {
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'");
        header('Referrer-Policy: no-referrer');

        if ($request->path !== '/install') {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['error' => ['code' => 'PH-SYS-004', 'message' => 'Instalación pendiente: abre /install']], JSON_UNESCAPED_UNICODE);
            return;
        }
        header('Content-Type: text/html; charset=utf-8');

        $checks = $this->checks();
        if ($request->method === 'POST') {
            $this->post($checks);
            return;
        }
        $this->render($checks, [], $this->defaults());
    }

    /** RF-090: comprobaciones del entorno. */
    private function checks(): array
    {
        $c = [];
        $c[] = ['PHP ' . self::PHP_MIN . ' o superior (recomendado 8.4)', version_compare(PHP_VERSION, self::PHP_MIN, '>='), PHP_VERSION];
        foreach (self::EXTENSIONS as $ext) {
            $c[] = ["Extensión $ext", extension_loaded($ext), extension_loaded($ext) ? 'cargada' : 'falta'];
        }
        foreach (['storage', 'storage/logs', 'storage/sessions', 'storage/uploads', 'storage/ai_cache', 'config'] as $dir) {
            $path = PH_ROOT . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0750, true);
            }
            $c[] = ["Escritura en $dir/", is_writable($path), is_writable($path) ? 'ok' : 'sin permiso'];
        }
        $rewrite = function_exists('apache_get_modules') ? in_array('mod_rewrite', apache_get_modules(), true) : null;
        $c[] = ['Apache mod_rewrite', $rewrite !== false, $rewrite === null ? 'no verificable (PHP-FPM o CLI)' : ($rewrite ? 'activo' : 'inactivo')];
        return $c;
    }

    private function defaults(): array
    {
        $https = (($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'api.pymehub.hermantorres.com');
        return [
            'db_host' => '127.0.0.1', 'db_port' => '3306', 'db_name' => 'pymehub', 'db_user' => 'pymehub_user', 'db_pass' => '',
            'api_url' => ($https ? 'https://' : 'http://') . $host,
            'frontend_url' => 'https://pymehub.hermantorres.com',
            'cookie_domain' => '',
            'cookie_secure' => $https ? '1' : '0',
            'admin_nombre' => '', 'admin_apellidos' => '', 'admin_email' => '', 'admin_password' => '',
            'ia_api_key' => '', 'ia_mode' => 'offline',
        ];
    }

    private function post(array $checks): void
    {
        $in = array_map(static fn ($v) => is_string($v) ? trim($v) : '', array_merge($this->defaults(), $_POST));
        $errors = [];

        $token = is_file($this->tokenFile) ? (string) file_get_contents($this->tokenFile) : '';
        if ($token === '' || !hash_equals($token, (string) ($_POST['_token'] ?? ''))) {
            $errors[] = 'El formulario ha caducado. Recarga la página.';
        }
        foreach ($checks as [$label, $ok]) {
            if ($ok === false) {
                $errors[] = "Requisito no cumplido: $label";
            }
        }
        if (!filter_var($in['admin_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email del administrador no válido.';
        }
        if (mb_strlen($in['admin_password']) < 12) {
            $errors[] = 'La contraseña del administrador debe tener al menos 12 caracteres.';
        }
        if ($in['admin_nombre'] === '') {
            $errors[] = 'Indica el nombre del administrador.';
        }
        foreach (['api_url', 'frontend_url'] as $u) {
            if (!preg_match('#^https?://[^/\s]+$#', $in[$u])) {
                $errors[] = "La URL $u debe ser del tipo https://dominio (sin barra final).";
            }
        }
        if (!in_array($in['ia_mode'], ['live', 'cache_first', 'offline'], true)) {
            $errors[] = 'Modo de IA no válido.';
        }
        if ($errors !== []) {
            $this->render($checks, $errors, $in);
            return;
        }

        $db = ['host' => $in['db_host'], 'port' => (int) $in['db_port'], 'name' => $in['db_name'], 'user' => $in['db_user'], 'pass' => $in['db_pass']];
        try {
            $pdo = Db::connect($db);
        } catch (\Throwable $e) {
            $this->render($checks, ['No se pudo conectar con MySQL: ' . $e->getMessage()], $in);
            return;
        }

        // RF-091: debe ser MySQL 8.4 o superior, nunca MariaDB
        $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $numeric = preg_replace('/[^0-9.].*$/', '', $version);
        if (stripos($version, 'mariadb') !== false) {
            $this->render($checks, ["El servidor es MariaDB ($version). Pyme Hub requiere MySQL 8.4 LTS."], $in);
            return;
        }
        $allowDev = getenv('PYMEHUB_PERMITIR_MYSQL80') === '1' && version_compare($numeric, '8.0.16', '>=');
        if (version_compare($numeric, self::MYSQL_MIN, '<') && !$allowDev) {
            $this->render($checks, ["MySQL $version no es compatible: se requiere " . self::MYSQL_MIN . ' o superior.'], $in);
            return;
        }
        $tables = (int) $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        if ($tables > 0) {
            $this->render($checks, ['La base de datos no está vacía. Usa una base nueva o vacíala antes de instalar.'], $in);
            return;
        }

        try {
            $applied = (new Migrator($pdo))->migrate();
            $platformId = (int) $pdo->query("SELECT id FROM interlocutor WHERE tipo = 'PLATAFORMA'")->fetchColumn();
            $st = $pdo->prepare(
                "INSERT INTO usuario (interlocutor_id, tipo_interlocutor, perfil, nombre, apellidos, email, password_hash, estado_acceso, privacidad_aceptada_en)
                 VALUES (?, 'PLATAFORMA', 'ADMIN', ?, ?, ?, ?, 'ACTIVO', UTC_TIMESTAMP())"
            );
            $st->execute([$platformId, $in['admin_nombre'], $in['admin_apellidos'], mb_strtolower($in['admin_email']), password_hash($in['admin_password'], PASSWORD_ARGON2ID)]);
        } catch (\Throwable $e) {
            $this->render($checks, ['Error al crear el esquema: ' . $e->getMessage() . '. Vacía la base de datos y vuelve a intentarlo.'], $in);
            return;
        }

        $config = [
            'app' => [
                'env'          => 'production',
                'api_url'      => $in['api_url'],
                'frontend_url' => $in['frontend_url'],
            ],
            'db' => $db,
            'session' => [
                'cookie_domain' => $in['cookie_domain'],
                'cookie_secure' => $in['cookie_secure'] === '1',
            ],
            'cors' => ['allowed_origins' => [$in['frontend_url']]],
            'crypto' => ['key' => base64_encode(sodium_crypto_secretbox_keygen())],
            'ia' => [
                'provider' => 'mistral',
                'base_url' => 'https://api.mistral.ai/v1',
                'model'    => 'mistral-small-latest',
                'api_key'  => $in['ia_api_key'],
                'mode'     => $in['ia_api_key'] === '' ? 'offline' : $in['ia_mode'],
            ],
        ];
        $php = "<?php\n// Generado por el instalador el " . gmdate('c') . ". NO versionar.\nreturn " . var_export($config, true) . ";\n";
        $cfgPath = PH_ROOT . '/config/config.php';
        if (file_put_contents($cfgPath, $php, LOCK_EX) === false) {
            $this->render($checks, ['No se pudo escribir config/config.php. Revisa los permisos.'], $in);
            return;
        }
        @chmod($cfgPath, 0640);
        file_put_contents(PH_ROOT . '/storage/installed.lock', gmdate('c') . "\n");
        @unlink($this->tokenFile);

        $this->done($applied, $in);
    }

    private function token(): string
    {
        $t = bin2hex(random_bytes(32));
        file_put_contents($this->tokenFile, $t, LOCK_EX);
        @chmod($this->tokenFile, 0600);
        return $t;
    }

    private static function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function render(array $checks, array $errors, array $v): void
    {
        $e = [self::class, 'e'];
        $rows = '';
        foreach ($checks as [$label, $ok, $detail]) {
            $state = $ok === false ? 'No cumple' : 'Correcto';
            $cls = $ok === false ? 'ko' : 'ok';
            $rows .= "<tr><td>{$e($label)}</td><td>{$e((string) $detail)}</td><td class=\"$cls\">$state</td></tr>";
        }
        $errHtml = '';
        if ($errors !== []) {
            $errHtml = '<div class="alert" role="alert"><strong>No se ha podido instalar</strong><ul>';
            foreach ($errors as $err) {
                $errHtml .= '<li>' . $e($err) . '</li>';
            }
            $errHtml .= '</ul></div>';
        }
        $sel = static fn (string $o) => $v['ia_mode'] === $o ? ' selected' : '';
        $sec = static fn (string $o) => $v['cookie_secure'] === $o ? ' selected' : '';
        $token = $this->token();

        echo self::layout('Instalar Pyme Hub API', <<<HTML
<h1>Instalar Pyme Hub API</h1>
<p class="lead">Este asistente crea el esquema en MySQL, el administrador de la plataforma y el archivo de configuración. Solo se puede ejecutar una vez.</p>
{$errHtml}
<section><h2>1. Comprobación del servidor</h2>
<table><thead><tr><th>Requisito</th><th>Detalle</th><th>Estado</th></tr></thead><tbody>{$rows}</tbody></table></section>
<form method="post" action="/install" autocomplete="off">
<input type="hidden" name="_token" value="{$token}">
<section><h2>2. Base de datos MySQL 8.4</h2>
<p class="hint">La base y el usuario deben existir y la base debe estar vacía (ver manual de despliegue, apartado 5).</p>
<div class="grid">
<label>Servidor<input name="db_host" required value="{$e($v['db_host'])}"></label>
<label>Puerto<input name="db_port" required inputmode="numeric" value="{$e($v['db_port'])}"></label>
<label>Base de datos<input name="db_name" required value="{$e($v['db_name'])}"></label>
<label>Usuario<input name="db_user" required value="{$e($v['db_user'])}"></label>
<label>Contraseña<input name="db_pass" type="password" value=""></label>
</div></section>
<section><h2>3. Direcciones</h2>
<div class="grid">
<label>URL de esta API<input name="api_url" required value="{$e($v['api_url'])}"></label>
<label>URL del frontend (origen permitido en CORS)<input name="frontend_url" required value="{$e($v['frontend_url'])}"></label>
<label>Dominio de la cookie (vacío = solo este host)<input name="cookie_domain" value="{$e($v['cookie_domain'])}"></label>
<label>Cookie solo por HTTPS<select name="cookie_secure"><option value="1"{$sec('1')}>Sí (producción)</option><option value="0"{$sec('0')}>No (solo desarrollo local)</option></select></label>
</div></section>
<section><h2>4. Administrador de la plataforma</h2>
<div class="grid">
<label>Nombre<input name="admin_nombre" required value="{$e($v['admin_nombre'])}"></label>
<label>Apellidos<input name="admin_apellidos" value="{$e($v['admin_apellidos'])}"></label>
<label>Email<input name="admin_email" type="email" required value="{$e($v['admin_email'])}"></label>
<label>Contraseña (mínimo 12 caracteres)<input name="admin_password" type="password" minlength="12" required></label>
</div></section>
<section><h2>5. Asesor de formación con IA (opcional)</h2>
<p class="hint">Sin clave, el asesor funciona en modo sin conexión con recomendaciones por reglas.</p>
<div class="grid">
<label>Clave de la API de Mistral<input name="ia_api_key" type="password" value=""></label>
<label>Modo<select name="ia_mode"><option value="offline"{$sel('offline')}>Sin conexión (reglas y respuestas pregeneradas)</option><option value="cache_first"{$sel('cache_first')}>Caché primero</option><option value="live"{$sel('live')}>En vivo</option></select></label>
</div></section>
<button type="submit">Instalar</button>
</form>
HTML);
    }

    private function done(array $applied, array $v): void
    {
        $e = [self::class, 'e'];
        $list = implode(', ', array_map($e, $applied));
        echo self::layout('Pyme Hub API instalada', <<<HTML
<h1>Instalación completada</h1>
<div class="success" role="status">
<p>Migraciones aplicadas: <strong>{$list}</strong>.</p>
<p>Administrador creado: <strong>{$e($v['admin_email'])}</strong>.</p>
<p>Configuración escrita en <code>config/config.php</code> y asistente bloqueado.</p>
</div>
<h2>Siguientes pasos</h2>
<ol>
<li>Comprueba que <code>{$e($v['api_url'])}/v1/health</code> responde con estado <code>ok</code>.</li>
<li>Despliega el frontend y entra con el administrador.</li>
<li>Haz una copia de seguridad de <code>config/config.php</code> fuera del servidor: contiene la clave de cifrado.</li>
</ol>
HTML);
    }

    private static function layout(string $title, string $body): string
    {
        return <<<HTML
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex"><title>{$title}</title>
<style>
:root{--primary:#22C55E;--primary-ink:#15803D;--text:#1F2937;--muted:#64748B;--bg:#F6F8FA;--surface:#fff;--border:#E2E8F0;--danger:#B91C1C;--navy:#08263B}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.55 Inter,system-ui,-apple-system,Segoe UI,Roboto,sans-serif}
header{background:var(--navy);color:#fff;padding:14px 24px;font-weight:700;letter-spacing:.2px}header span{color:var(--primary)}
main{max-width:820px;margin:0 auto;padding:32px 20px 64px}h1{font-size:28px;margin:0 0 8px}h2{font-size:18px;margin:0 0 12px}
.lead,.hint{color:var(--muted)}section{background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:20px;margin:16px 0}
table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:8px;border-bottom:1px solid var(--border)}td.ok{color:var(--primary-ink);font-weight:600}td.ko{color:var(--danger);font-weight:600}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,240px),1fr));gap:12px 16px}
label{display:flex;flex-direction:column;gap:4px;font-size:14px;font-weight:600}
input,select{font:inherit;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:#fff;color:var(--text)}
input:focus,select:focus,button:focus{outline:3px solid #93C5FD;outline-offset:1px}
button{font:inherit;font-weight:700;background:var(--primary-ink);color:#fff;border:0;border-radius:8px;padding:12px 22px;cursor:pointer}
.alert{border:1px solid #FECACA;background:#FEF2F2;color:var(--danger);border-radius:12px;padding:14px 18px}
.success{border:1px solid #BBF7D0;background:#F0FDF4;border-radius:12px;padding:14px 18px}code{background:#EEF2F7;padding:1px 5px;border-radius:4px}
</style></head><body><header>Pyme<span>Hub</span> · API</header><main>{$body}</main></body></html>
HTML;
    }
}
