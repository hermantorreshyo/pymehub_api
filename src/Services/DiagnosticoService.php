<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\Config;
use PymeHub\Core\Db;
use PymeHub\Core\Demo;
use PymeHub\Core\Mantenimiento;
use PymeHub\Core\Parametros;
use PymeHub\Core\Stripe;

/**
 * Diagnóstico del despliegue: comprueba, sin cambiar nada, que todo lo entregado está instalado
 * y configurado. Lo usan la pantalla Administración → Diagnóstico (GET /v1/admin/diagnostics) y
 * cli/diagnostico.php. Nunca devuelve secretos: solo si están configurados.
 *
 * Cada comprobación: { grupo, nombre, estado: OK|AVISO|ERROR|INFO, detalle, accion }.
 *   OK     todo bien
 *   AVISO  funciona, pero conviene revisarlo
 *   ERROR  algo no funciona o falta para que funcione
 *   INFO   dato informativo o pendiente que no depende del software (constitución, gestoría…)
 */
final class DiagnosticoService
{
    /** Tareas que deben estar en /etc/cron.d/pymehub. */
    private const TAREAS = [
        'backup.sh'               => ['Copia diaria de seguridad', '0 2 * * *  www-data  bash $API/cli/backup.sh >> $API/storage/logs/backup.log 2>&1'],
        'purge_retention.php'     => ['Retención de datos (12 meses)', '0 4 * * *  www-data  php $API/cli/purge_retention.php >> $API/storage/logs/cron.log 2>&1'],
        'wellbeing_alerts.php'    => ['Alertas de bienestar (lunes)', '0 6 * * 1  www-data  php $API/cli/wellbeing_alerts.php >> $API/storage/logs/cron.log 2>&1'],
        'recalc_risk.php'         => ['Riesgo de rotación diario', '0 3 * * *  www-data  php $API/cli/recalc_risk.php >> $API/storage/logs/cron.log 2>&1'],
        'demo_seed.php'           => ['Reinicio nocturno de la demo', '30 3 * * *  www-data  php $API/cli/demo_seed.php --reset >> $API/storage/logs/demo_seed.log 2>&1'],
        'bias_report.php'         => ['Revisión trimestral de sesgos', '30 4 1 1,4,7,10 *  www-data  php $API/cli/bias_report.php --salida=$API/storage/logs/sesgos_$(date +\%Y\%m).md >> $API/storage/logs/cron.log 2>&1'],
        'tenant_purge.php'        => ['Borrado de empresas de baja', '30 4 * * *  www-data  php $API/cli/tenant_purge.php >> $API/storage/logs/cron.log 2>&1'],
        'subscription_status.php' => ['Vigencia de las suscripciones', '15 0 * * *  www-data  php $API/cli/subscription_status.php >> $API/storage/logs/cron.log 2>&1'],
    ];

    private array $r = [];
    private string $grupo = '';

    /** @return array{estado:string, version:string, generado_en:string, resumen:array, comprobaciones:list<array>} */
    public static function ejecutar(bool $red = true): array
    {
        $d = new self();
        $d->version();
        $d->servidor();
        $d->copias();
        $d->tareas();
        $d->seguridad($red);
        $d->operacion();
        $d->demo($red);
        $d->parametros();
        $d->pagos($red);
        $d->ia();
        $d->datos();
        $resumen = ['OK' => 0, 'AVISO' => 0, 'ERROR' => 0, 'INFO' => 0];
        foreach ($d->r as $c) {
            $resumen[$c['estado']]++;
        }
        return [
            'estado' => $resumen['ERROR'] > 0 ? 'ERROR' : ($resumen['AVISO'] > 0 ? 'AVISO' : 'OK'),
            'version' => PH_VERSION,
            'generado_en' => gmdate('Y-m-d\TH:i:s\Z'),
            'resumen' => $resumen,
            'comprobaciones' => $d->r,
        ];
    }

    private function add(string $estado, string $nombre, string $detalle, ?string $accion = null): void
    {
        $this->r[] = ['grupo' => $this->grupo, 'nombre' => $nombre, 'estado' => $estado, 'detalle' => $detalle, 'accion' => $accion];
    }

    /** Petición HTTP del servidor a sí mismo: estado, cabeceras (en minúsculas) y un trozo del cuerpo. */
    private static function sonda(string $url, string $metodo = 'GET'): ?array
    {
        if ($url === '' || !function_exists('curl_init')) {
            return null;
        }
        $cab = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $metodo, CURLOPT_NOBODY => $metodo === 'HEAD', CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 8, CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HEADERFUNCTION => static function ($c, string $l) use (&$cab): int {
                $p = explode(':', $l, 2);
                if (count($p) === 2) {
                    $cab[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($l);
            },
        ]);
        $cuerpo = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status === 0 ? null : ['status' => $status, 'cabeceras' => $cab, 'cuerpo' => is_string($cuerpo) ? substr($cuerpo, 0, 20000) : ''];
    }

    private static function web(): string
    {
        return rtrim((string) Config::get('app.frontend_url', ''), '/');
    }

    private static function api(): string
    {
        return rtrim((string) Config::get('app.api_url', ''), '/');
    }

    /* ------------------------------------------------------------------ Grupos */

    private function version(): void
    {
        $this->grupo = 'Versión y código';
        $this->add('INFO', 'Versión de la API', 'v' . PH_VERSION);

        $ficheros = array_map(static fn ($f) => basename($f, '.sql'), glob(PH_ROOT . '/migrations/*.sql') ?: []);
        sort($ficheros);
        $hechas = array_column(Db::all('SELECT version FROM migracion_esquema'), 'version');
        $faltan = array_values(array_diff($ficheros, $hechas));
        $faltan === []
            ? $this->add('OK', 'Migraciones de la base de datos', count($ficheros) . ' aplicadas; la última es ' . end($ficheros) . '.')
            : $this->add('ERROR', 'Migraciones de la base de datos', 'Faltan por aplicar: ' . implode(', ', $faltan) . '.', 'sudo -u www-data php $API/cli/migrate.php');

        $sw = self::sonda(self::web() . '/sw.js');
        if ($sw === null || $sw['status'] !== 200) {
            $this->add('AVISO', 'Versión de la web', 'No se ha podido leer ' . self::web() . '/sw.js desde el servidor.', 'Comprueba que la web responde y que app.frontend_url de config.php es correcta.');
        } elseif (preg_match("/VERSION\\s*=\\s*'v([\\d.]+)'/", $sw['cuerpo'], $m)) {
            // Mismas versión mayor y menor: un parche (x.y.Z) puede tocar solo uno de los dos repositorios
            $mm = static fn (string $v) => implode('.', array_slice(explode('.', $v), 0, 2));
            $mm($m[1]) === $mm(PH_VERSION)
                ? $this->add('OK', 'Versión de la web', 'v' . $m[1] . ', compatible con la API v' . PH_VERSION . '.')
                : $this->add('AVISO', 'Versión de la web', "La web está en v{$m[1]} y la API en v" . PH_VERSION . '.', 'Despliega en la web la versión que corresponde a la API (mismo x.y; consulta el CHANGELOG de la web).');
        }
    }

    private function servidor(): void
    {
        $this->grupo = 'Servidor';
        version_compare(PHP_VERSION, '8.4.0', '>=')
            ? $this->add('OK', 'PHP', PHP_VERSION)
            : $this->add('ERROR', 'PHP', PHP_VERSION . ' (se necesita 8.4 o superior).', 'Instala PHP 8.4.');
        $faltan = array_values(array_filter(['pdo_mysql', 'sodium', 'mbstring', 'json', 'openssl'], static fn ($e) => !extension_loaded($e)));
        $faltan === []
            ? $this->add('OK', 'Extensiones de PHP', 'pdo_mysql, sodium, mbstring, json y openssl cargadas.')
            : $this->add('ERROR', 'Extensiones de PHP', 'Faltan: ' . implode(', ', $faltan) . '.', 'apt install php8.4-' . implode(' php8.4-', $faltan));
        extension_loaded('curl')
            ? $this->add('OK', 'Extensión curl', 'Cargada (Stripe, IA y estas comprobaciones de red).')
            : $this->add('AVISO', 'Extensión curl', 'No está cargada: Stripe y la IA usan un método alternativo y no se pueden comprobar las URL.', 'apt install php8.4-curl && systemctl reload apache2');

        $v = (string) Db::value('SELECT VERSION()');
        $maria = stripos($v, 'mariadb') !== false;
        $ok = $maria ? version_compare($v, '11.4.5', '>=') : version_compare($v, '8.4.0', '>=');
        $this->add($ok ? 'OK' : 'ERROR', 'Base de datos', ($maria ? 'MariaDB ' : 'MySQL ') . $v, $ok ? null : 'Se necesita MariaDB 11.4.5+ o MySQL 8.4+.');

        $libre = @disk_free_space(PH_ROOT . '/storage');
        if ($libre !== false) {
            $gb = round($libre / 1073741824, 1);
            $this->add($libre < 500 * 1048576 ? 'ERROR' : ($libre < 2 * 1073741824 ? 'AVISO' : 'OK'), 'Espacio en disco', "$gb GB libres.",
                $libre < 2 * 1073741824 ? 'Libera espacio (copias antiguas, registros) o amplía el disco.' : null);
        }
        $noEscribibles = array_values(array_filter(['storage/logs', 'storage/backups', 'storage/uploads', 'storage'], static fn ($d) => !is_writable(PH_ROOT . '/' . $d)));
        $noEscribibles === []
            ? $this->add('OK', 'Permisos de storage', 'La API puede escribir en logs, copias y subidas.')
            : $this->add('ERROR', 'Permisos de storage', 'Sin permiso de escritura: ' . implode(', ', $noEscribibles) . '.', 'chown -R www-data:www-data $API/storage');
        $this->archivosDeStorage();
        is_file(PH_ROOT . '/storage/installed.lock')
            ? $this->add('OK', 'Asistente de instalación', 'Bloqueado (installed.lock): /install no responde.')
            : $this->add('ERROR', 'Asistente de instalación', '/install está disponible: cualquiera podría reinstalar.', 'Completa la instalación o crea storage/installed.lock.');
        $perm = @fileperms(\PymeHub\Core\Config::path());
        if ($perm !== false) {
            ($perm & 0o004) === 0
                ? $this->add('OK', 'Permisos de config.php', sprintf('%o: no lo puede leer cualquier usuario del servidor.', $perm & 0o777))
                : $this->add('AVISO', 'Permisos de config.php', sprintf('%o: lo puede leer cualquier usuario del servidor.', $perm & 0o777), 'chmod 640 $API/config/config.php');
        }
    }

    /**
     * Archivos de storage/ que la API ya no puede escribir (p. ej., tras un `chown -R root:…` en un
     * despliegue): sesiones que dan error, copias que no actualizan su marca, registros que no se escriben.
     */
    private function archivosDeStorage(): void
    {
        $versionados = ['.htaccess', 'README.md', 'installed.lock'];
        $malos = [];
        $total = 0;
        foreach (['sessions', 'logs', 'backups', 'ai_cache', 'uploads'] as $sub) {
            $dir = PH_ROOT . '/storage/' . $sub;
            if (!is_dir($dir)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if (++$total > 20000) {
                    break 2;
                }
                if (in_array($f->getFilename(), $versionados, true)) {
                    continue;
                }
                if (!is_writable($f->getPathname())) {
                    $malos[$sub] = ($malos[$sub] ?? 0) + 1;
                }
            }
        }
        if ($malos === []) {
            $this->add('OK', 'Propietario de los archivos de storage', 'La API puede leer y escribir todos sus archivos (sesiones, registros, copias y caché).');
            return;
        }
        $lista = implode(', ', array_map(static fn ($k, $v) => "$k ($v)", array_keys($malos), $malos));
        $this->add(isset($malos['backups']) ? 'ERROR' : 'AVISO', 'Propietario de los archivos de storage',
            "Hay archivos que la API no puede escribir: $lista. Suele pasar tras un chown -R del despliegue; las sesiones afectadas obligan a entrar de nuevo y las copias pueden fallar.",
            'find $API/storage -mindepth 1 ! -user www-data -exec chown www-data:www-data {} +');
    }

    private function copias(): void
    {
        $this->grupo = 'Copias de seguridad';
        $marca = PH_ROOT . '/storage/backups/ultima_copia.txt';
        if (!is_file($marca)) {
            $this->add('ERROR', 'Última copia', 'No hay ninguna copia registrada.', 'sudo -u www-data bash $API/cli/backup.sh');
        } else {
            $h = round((time() - (int) trim((string) file_get_contents($marca))) / 3600, 1);
            $this->add($h > 26 ? 'ERROR' : 'OK', 'Última copia', "Hace $h horas.", $h > 26 ? 'La copia diaria no se ejecuta: revisa la tarea programada y storage/logs/backup.log.' : null);
        }
        $n = count(glob(PH_ROOT . '/storage/backups/db-*.sql.gz') ?: []);
        $this->add($n > 0 ? 'OK' : 'ERROR', 'Copias guardadas', "$n copias de la base de datos (se conservan 14).");
        $informes = glob(PH_ROOT . '/storage/logs/restauracion-*.md') ?: [];
        rsort($informes);
        if ($informes === []) {
            $this->add('AVISO', 'Prueba de restauración (RNF-064)', 'No hay ningún informe de restauración.', 'bash $API/cli/restore_test.sh (como root)');
        } else {
            $texto = (string) file_get_contents($informes[0]);
            $dias = (int) floor((time() - filemtime($informes[0])) / 86400);
            $ok = str_contains($texto, '**correcta**');
            $this->add($ok ? ($dias > 90 ? 'AVISO' : 'OK') : 'ERROR', 'Prueba de restauración (RNF-064)',
                basename($informes[0]) . " (hace $dias días)" . ($ok ? '' : ': sin resultado correcto.'),
                $ok && $dias <= 90 ? null : 'Repite la prueba: bash $API/cli/restore_test.sh');
        }
    }

    private function tareas(): void
    {
        $this->grupo = 'Tareas programadas';
        $f = '/etc/cron.d/pymehub';
        if (!is_readable($f)) {
            $this->add('ERROR', 'Archivo de tareas', "$f no existe o no se puede leer.", 'Crea el archivo con las tareas del manual 01, apartado 14.');
            return;
        }
        $txt = (string) file_get_contents($f);
        foreach (self::TAREAS as $script => [$nombre, $linea]) {
            preg_match('/^[^#\n]*' . preg_quote($script, '/') . '/m', $txt)
                ? $this->add('OK', $nombre, $script . ' programada.')
                : $this->add('ERROR', $nombre, "$script no está en $f.", 'Añade esta línea: ' . $linea);
        }
        $log = PH_ROOT . '/storage/logs/cron.log';
        if (is_file($log)) {
            $h = round((time() - filemtime($log)) / 3600, 1);
            $this->add($h > 26 ? 'AVISO' : 'OK', 'Ejecución de las tareas', "cron.log actualizado hace $h horas.",
                $h > 26 ? 'Comprueba que el servicio cron está activo: systemctl is-active cron' : null);
        } else {
            $this->add('AVISO', 'Ejecución de las tareas', 'Todavía no hay cron.log (normal si se acaban de programar).');
        }
    }

    private function seguridad(bool $red): void
    {
        $this->grupo = 'Seguridad';
        Config::get('app.env') === 'production'
            ? $this->add('OK', 'Entorno', 'production')
            : $this->add('AVISO', 'Entorno', (string) Config::get('app.env'), "En el servidor real, app.env debe ser 'production'.");
        $https = str_starts_with(self::api(), 'https://') && str_starts_with(self::web(), 'https://');
        $this->add($https ? 'OK' : 'ERROR', 'HTTPS', $https ? 'La API y la web se sirven por HTTPS.' : 'app.api_url o app.frontend_url no usan https.', $https ? null : 'Corrige las URL en config.php y los certificados.');
        (bool) Config::get('session.cookie_secure', false)
            ? $this->add('OK', 'Cookie de sesión segura', 'Solo viaja por HTTPS.')
            : $this->add('ERROR', 'Cookie de sesión segura', 'cookie_secure está desactivado.', "Pon 'cookie_secure' => true en config.php.");
        strlen((string) Config::get('crypto.key', '')) >= 40 && Config::get('crypto.key') !== 'BASE64_GENERADA_POR_EL_INSTALADOR'
            ? $this->add('OK', 'Clave de cifrado de las notas', 'Configurada (LEG-003).')
            : $this->add('ERROR', 'Clave de cifrado de las notas', 'No está configurada.', 'Revisa crypto.key en config.php (la genera el instalador).');
        in_array(self::web(), (array) Config::get('cors.allowed_origins', []), true)
            ? $this->add('OK', 'CORS', 'La web está entre los orígenes permitidos.')
            : $this->add('ERROR', 'CORS', 'La URL de la web no está en cors.allowed_origins.', 'Añádela en config.php.');
        $pa = (string) Config::get('demo.password_admin', '');
        $existe = Db::value("SELECT 1 FROM usuario WHERE email = ? AND perfil = 'ADMIN' AND estado_acceso = 'ACTIVO'", ['admin@' . DemoService::DOMINIO]) !== null;
        if ($pa === '' || $pa === 'CAMBIAR' || mb_strlen($pa) < 10) {
            $this->add('AVISO', 'Contraseña de admin@pymehub.demo', ($pa === '' ? 'Sin definir' : 'Es el valor de ejemplo o tiene menos de 10 caracteres')
                . ($existe ? ': la cuenta existe, pero no se actualiza.' : ': la cuenta de pruebas internas no se crea.'),
                'Define demo.password_admin (mínimo 10 caracteres) en config.php y ejecuta: sudo -u www-data php $API/cli/demo_seed.php --reset');
        } else {
            $this->add($existe ? 'OK' : 'AVISO', 'Contraseña de admin@pymehub.demo', $existe ? 'Definida (no se muestra) y la cuenta existe.' : 'Definida, pero la cuenta todavía no existe.',
                $existe ? null : 'sudo -u www-data php $API/cli/demo_seed.php --reset');
        }
        if (!$red) {
            return;
        }
        foreach (['API' => [self::api() . '/v1/health', ['strict-transport-security', 'x-content-type-options', 'referrer-policy', 'content-security-policy']],
                  'Web' => [self::web() . '/', ['strict-transport-security', 'x-content-type-options', 'referrer-policy', 'content-security-policy', 'x-frame-options', 'permissions-policy']]] as $q => [$url, $deben]) {
            $s = self::sonda($url, 'GET');
            if ($s === null) {
                $this->add('ERROR', "Cabeceras de seguridad · $q", "$url no responde desde el servidor.", 'Comprueba el host virtual y el DNS.');
                continue;
            }
            $faltan = array_values(array_filter($deben, static fn ($c) => !isset($s['cabeceras'][$c])));
            $faltan === []
                ? $this->add('OK', "Cabeceras de seguridad · $q", 'Todas presentes: ' . implode(', ', $deben) . '.')
                : $this->add('AVISO', "Cabeceras de seguridad · $q", 'Faltan: ' . implode(', ', $faltan) . '.', 'Revisa el host virtual y .htaccess (fase 10, nota A).');
            if (isset($s['cabeceras']['x-powered-by'])) {
                $this->add('AVISO', "Huella del servidor · $q", 'La cabecera X-Powered-By muestra la versión de PHP.', "Desde la v0.11.1 la API la quita; en la web: Header always unset X-Powered-By (.htaccess).");
            } elseif (preg_match('/\d/', $s['cabeceras']['server'] ?? '')) {
                $this->add('INFO', "Huella del servidor · $q", 'La cabecera Server muestra la versión de Apache.',
                    'Se oculta con «ServerTokens Prod» en la configuración global de Apache, que es del administrador del VPS y queda fuera del proyecto (RNF-001).');
            }
        }
    }

    private function operacion(): void
    {
        $this->grupo = 'Operación';
        $m = Mantenimiento::estado();
        $m['activo']
            ? $this->add('AVISO', 'Mantenimiento', 'ACTIVO: las empresas no pueden usar la aplicación.', 'Desactívalo en Administración → Resumen → Mantenimiento.')
            : $this->add('OK', 'Mantenimiento', $m['programado'] ? 'Desactivado; hay una ventana programada.' : 'Desactivado.');
        $errores = 0;
        $desde = time() - 86400;
        foreach ([0, 1] as $dd) {
            $f = PH_ROOT . '/storage/logs/api-' . gmdate('Y-m-d', time() - $dd * 86400) . '.log';
            if (!is_file($f)) {
                continue;
            }
            $fh = fopen($f, 'r');
            while (($l = fgets($fh)) !== false) {
                if (str_contains($l, '"level":"error"') && ($e = json_decode($l, true)) && strtotime((string) ($e['ts'] ?? '')) >= $desde
                    && !str_starts_with((string) ($e['msg'] ?? ''), 'Stripe respondió')) {
                    $errores++;
                }
            }
            fclose($fh);
        }
        $this->add($errores === 0 ? 'OK' : 'AVISO', 'Errores del servidor (24 h)', $errores === 0 ? 'Ninguno.' : "$errores errores registrados.",
            $errores === 0 ? null : 'Consulta el detalle en Administración → Resumen → Errores del servidor.');
        $this->add('INFO', 'Monitor externo de disponibilidad', 'No se puede comprobar desde aquí.', 'Verifica en UptimeRobot (o similar) que vigila /v1/health cada 5 minutos.');
    }

    private function demo(bool $red): void
    {
        $this->grupo = 'Demo comercial';
        if (!Demo::habilitada()) {
            $this->add('AVISO', 'Demostración', 'Desactivada (demo.habilitada o demo.habilitada_hasta).', 'Actívala en config.php si la vas a enseñar.');
            return;
        }
        $this->add('OK', 'Demostración', 'Habilitada.');
        $pd = (string) Config::get('demo.password', '');
        $this->add($pd === '' ? 'ERROR' : 'OK', 'Contraseña de la demo', $pd === '' ? 'Sin definir.' : 'Definida (la impresa en el folleto).');
        foreach (['gerente', 'rrhh', 'conductor', 'gerente2', 'formador'] as $u) {
            $ok = Db::value("SELECT 1 FROM usuario WHERE email = ? AND estado_acceso = 'ACTIVO' AND es_demo = 1", [$u . '@' . DemoService::DOMINIO]) !== null;
            $this->add($ok ? 'OK' : 'ERROR', "Usuario $u@", $ok ? 'Existe y está activo.' : 'No existe.', $ok ? null : 'sudo -u www-data php $API/cli/demo_seed.php --reset');
        }
        $creada = Db::value("SELECT MAX(creado_en) FROM interlocutor WHERE es_demo = 1");
        if ($creada !== null) {
            $h = round((time() - strtotime($creada . ' UTC')) / 3600, 1);
            $this->add($h > 26 ? 'AVISO' : 'OK', 'Reinicio nocturno', "Datos de la demo generados hace $h horas.", $h > 26 ? 'El reinicio de las 03:30 no se ejecuta: revisa storage/logs/demo_seed.log.' : null);
        }
        if ($red) {
            foreach (['/demo' => 'QR del folleto', '/app' => 'QR de instalación'] as $ruta => $nombre) {
                $s = self::sonda(self::web() . $ruta, 'HEAD');
                $ok = $s !== null && in_array($s['status'], [301, 302], true);
                $this->add($ok ? 'OK' : 'ERROR', "Ruta $ruta ($nombre)", $ok ? 'Redirige correctamente.' : 'No redirige.', $ok ? null : 'Revisa public/.htaccess de la web (mod_rewrite activo).');
            }
        }
    }

    private function parametros(): void
    {
        $this->grupo = 'Parámetros y legal';
        Parametros::get('titular.constituida') === true
            ? $this->add('OK', 'Titular de la plataforma', 'Sociedad constituida: ' . Parametros::get('titular.razon_social') . '.')
            : $this->add('INFO', 'Titular de la plataforma', 'Sociedad en constitución: los textos legales lo indican.', 'Cuando se constituya: Administración → Parámetros → Titular.');
        $this->add('INFO', 'Precios', sprintf('Entrada %s € y Completo %s € por empleado y mes, sin IVA; IVA %s %%; descuento anual %s %%.',
            Parametros::precioMes('ENTRADA'), Parametros::precioMes('COMPLETO'), Parametros::iva(), Parametros::float('precios.descuento_anual_pct', 0)));
        $this->add('INFO', 'Versión de los textos legales', (string) Parametros::get('legal.version'), 'Pendiente de revisión jurídica antes de comercializar.');
        Parametros::get('marketplace.formacion_exenta_iva') === true
            ? $this->add('OK', 'IVA de la formación de Skills', 'Marcada como exenta.')
            : $this->add('INFO', 'IVA de la formación de Skills', 'Se aplica IVA general.', 'Pendiente de que la gestoría confirme si la formación está exenta.');
    }

    private function pagos(bool $red): void
    {
        $this->grupo = 'Pagos (Stripe)';
        $sk = (string) Config::get('stripe.secret_key', '');
        if (str_starts_with($sk, 'sk_live_')) {
            $this->add('ERROR', 'Modo de Stripe', 'Hay una clave de modo REAL: el pago online queda desactivado.', 'Usa claves de prueba: sudo -u www-data php $API/cli/stripe_setup.php');
            return;
        }
        if (!Stripe::habilitado()) {
            $this->add('INFO', 'Stripe', 'Sin configurar: contratar y comprar en Skills responden «pago online no disponible».', 'Pendiente: manual 03 (cli/stripe_setup.php, webhook, portal y Connect).');
            return;
        }
        $this->add('OK', 'Stripe', 'Activo en modo prueba (clave sk_test_…' . substr($sk, -4) . ').');
        (string) Config::get('stripe.webhook_secret', '') !== ''
            ? $this->add('OK', 'Secreto del webhook', 'Configurado.')
            : $this->add('ERROR', 'Secreto del webhook', 'Sin configurar: los pagos no se reflejarán en Pyme Hub.', 'Manual 03, apartado 2.3.');
        if ($red) {
            try {
                Stripe::api('GET', '/v1/balance');
                $this->add('OK', 'Conexión con Stripe', 'La clave funciona y el servidor llega a la API de Stripe.');
            } catch (\Throwable) {
                $this->add('ERROR', 'Conexión con Stripe', 'Stripe rechaza la clave o no se puede conectar.', 'Revisa la clave con cli/stripe_setup.php y la salida a internet del servidor.');
            }
        }
        $ult = Db::value('SELECT MAX(recibido_en) FROM evento_stripe');
        $this->add($ult === null ? 'AVISO' : 'OK', 'Webhooks recibidos', $ult === null ? 'Todavía no ha llegado ningún aviso de Stripe.' : "Último aviso: $ult UTC.",
            $ult === null ? 'Haz un pago de prueba; si no llega, revisa la URL y los eventos del webhook (manual 03).' : null);
        $f = (int) Db::value("SELECT COUNT(*) FROM interlocutor WHERE tipo = 'FORMADOR' AND es_demo = 0");
        $l = (int) Db::value("SELECT COUNT(*) FROM interlocutor WHERE tipo = 'FORMADOR' AND es_demo = 0 AND stripe_cuenta_lista = 1");
        $this->add('INFO', 'Formadores con cobro activo (Connect)', "$l de $f formadores reales.");
    }

    private function ia(): void
    {
        $this->grupo = 'Asesor con IA';
        $modo = (string) Config::get('ia.mode', 'offline');
        $clave = (string) Config::get('ia.api_key', '') !== '';
        $pre = (int) Db::value('SELECT COUNT(*) FROM respuesta_ia');
        $this->add($modo === 'offline' ? 'INFO' : ($clave ? 'OK' : 'AVISO'), 'Modo del asesor',
            "Modo $modo" . ($clave ? ', con clave de Mistral.' : ', sin clave de Mistral.') . " $pre respuestas guardadas en caché.",
            $modo !== 'offline' && !$clave ? 'Añade ia.api_key en config.php o usa el modo offline (respuestas por reglas).' : null);
    }

    private function datos(): void
    {
        $this->grupo = 'Datos';
        $n = static fn (string $sql) => (int) Db::value($sql);
        $this->add('INFO', 'Empresas', sprintf('%d reales (%d de demo).',
            $n("SELECT COUNT(*) FROM interlocutor WHERE tipo = 'EMPRESA' AND es_demo = 0"), $n("SELECT COUNT(*) FROM interlocutor WHERE tipo = 'EMPRESA' AND es_demo = 1")));
        $venc = $n("SELECT COUNT(*) FROM suscripcion s JOIN interlocutor i ON i.id = s.interlocutor_id WHERE s.estado = 'VIGENTE' AND s.vigencia = 'VENCIDA' AND i.es_demo = 0");
        $this->add($venc > 0 ? 'AVISO' : 'OK', 'Suscripciones vencidas', $venc > 0 ? "$venc empresas en solo lectura." : 'Ninguna.', $venc > 0 ? 'Revísalas en Administración → Empresas → Vigencia.' : null);
        $pend = $n("SELECT COUNT(*) FROM curso_skills c JOIN interlocutor f ON f.id = c.interlocutor_id WHERE c.estado = 'PENDIENTE' AND f.es_demo = 0");
        $this->add($pend > 0 ? 'AVISO' : 'OK', 'Cursos de Skills por revisar', $pend > 0 ? "$pend cursos esperan revisión." : 'Ninguno.', $pend > 0 ? 'Administración → Skills → Revisión de cursos.' : null);
    }
}
