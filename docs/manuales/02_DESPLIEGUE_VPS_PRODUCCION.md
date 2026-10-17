# Manual 02 · Despliegue en el VPS de producción (hermantorres.com)

**Aplica a:** `pymehub_api` **v0.5.0** y `pymehub_web` **v0.5.0** (MariaDB compatible desde la API v0.3.1)
**Servidor:** VPS Hostinger `srv1616185` · Debian 13 · Apache 2.4 · PHP 8.4 · **MariaDB 11.8** (compartido con otras aplicaciones) · compartido con otros sitios
**Complementa a:** `01_MANUAL_DESPLIEGUE.md` (manual genérico). Este documento lo concreta para las rutas, vhosts y certificados que ya existen en el VPS.
**Última revisión:** 12/10/2026 · revisado tras la instalación real del 12/10/2026 (v0.3.2) y la actualización a v0.4.0

---

## 0. Punto de partida

Ya está hecho y **no se repite**:

| Elemento | Estado |
|---|---|
| DNS `pymehub` y `api.pymehub` → IP del VPS | Hecho |
| Vhosts `pymehub.hermantorres.com.conf` y `api.pymehub.hermantorres.com.conf` (+ `-le-ssl.conf`) | Hechos, pero apuntan a la raíz del repo (se corrigen en el paso 5) |
| Certificados Let's Encrypt de ambos subdominios | Hechos |
| Repos clonados con *deploy keys* (alias `github-pymehub_api` / `github-pymehub_web`) | Hechos |
| Base `pymehub` y usuario `pymehub_user` en el MariaDB del servidor | Hechos |

Rutas reales:

| Variable | Valor |
|---|---|
| `API` | `/var/www/hermantorres/eude/pymehub/pymehub_api` |
| `WEB` | `/var/www/hermantorres/eude/pymehub/pymehub_web` |
| Propietario del código | `root:www-data` (git se ejecuta como root, con las deploy keys de root) |
| Base de datos / usuario | `pymehub` / `pymehub_user` |

Exporta las variables al empezar la sesión SSH; el resto del manual las usa:

```bash
export API=/var/www/hermantorres/eude/pymehub/pymehub_api
export WEB=/var/www/hermantorres/eude/pymehub/pymehub_web
```

> **Textos de ejemplo.** Los comandos llevan valores de ejemplo en mayúsculas (`TU_CONTRASEÑA_ADMIN`, `LA_CONTRASEÑA_DE_LA_DEMO`…). Sustitúyelos antes de ejecutar: copiados tal cual, la prueba de humo falla en bloque y la demo queda con esa contraseña literal.
>
> **Sin usuario `pymehub`.** En este servidor el código es de `root:www-data` y git se ejecuta como root. Los comandos del manual 01 con `sudo -u pymehub` aquí se ejecutan sin ese prefijo.

Reglas: no se modifica ningún vhost, base de datos ni configuración global de otro sitio (`RNF-001`). **El motor MariaDB no se instala, no se reinicia ni se reconfigura**: Pyme Hub solo usa su base y su usuario (`DEC-05`). Cada paso termina con una comprobación y, si falla, no se continúa.

---

## 1. Comprobaciones previas (no cambian nada)

```bash
# 1.1 Etiquetas en GitHub (última: v0.5.0 en los dos repositorios)
cd $API && git fetch --tags origin && git tag -l
cd $WEB && git fetch --tags origin && git tag -l

# 1.2 Motor de base de datos (solo lectura)
mariadb --version

# 1.3 Cómo ejecuta Apache PHP
apache2ctl -M 2>/dev/null | grep -Ei 'php|proxy_fcgi|rewrite|headers|ssl'
ls /etc/apache2/conf-enabled/ | grep -i php

# 1.4 Nombres exactos de vhosts y certificados
ls /etc/apache2/sites-available/ | grep -i pymehub
sudo certbot certificates 2>/dev/null | grep -E 'Certificate Name|Domains|Expiry'

# 1.5 Exposición a través del sitio padre hermantorres.com
curl -s -o /dev/null -w "%{http_code}\n" https://hermantorres.com/eude/pymehub/pymehub_api/README.md
```

| Resultado | Qué hacer |
|---|---|
| 1.1 no aparece la etiqueta que vas a desplegar | Aún no se ha subido a GitHub: espera a que esté antes de seguir. |
| 1.2 muestra `11.4.5` o superior | Correcto. |
| 1.3 muestra `php_module` | mod_php: el vhost lleva los `php_admin_value`. |
| 1.3 muestra `proxy_fcgi` + `php8.4-fpm.conf` | PHP-FPM: ver nota del paso 5. |
| 1.5 devuelve **200** | El sitio padre sirve las carpetas del proyecto. Corrígelo en el paso 5.4 antes de instalar nada. |
| 1.5 devuelve 403/404 | Correcto. |

---

## 2. Fijar la versión y permisos

```bash
cd $API && git config core.fileMode false && git fetch --tags origin && git checkout v0.5.0 && git log --oneline -1
cd $WEB && git config core.fileMode false && git fetch --tags origin && git checkout v0.5.0 && git log --oneline -1
```

Debe aparecer el commit `(v0.5.0)` en cada repositorio. Git avisará de *detached HEAD*: es lo esperado al desplegar una etiqueta. `core.fileMode false` hace que los `chmod` de este manual no cuenten como cambios locales: sin él, el siguiente `git checkout` falla con *Your local changes … cli/backup.sh*.

```bash
# Código de solo lectura para Apache
sudo chown -R root:www-data $API $WEB
sudo find $API $WEB -path '*/.git' -prune -o -type d -exec chmod 750 {} \;
sudo find $API $WEB -path '*/.git' -prune -o -type f -exec chmod 640 {} \;
sudo chmod 750 $API/cli/*.sh $API/tests/*.sh      # www-data debe poder LEER backup.sh (grupo www-data)

# Solo storage/ y config/ escribibles por Apache (el instalador crea ahí sus archivos)
sudo chmod 2770 $API/storage $API/config
sudo -u www-data mkdir -p $API/storage/backups $API/storage/logs

# Las carpetas padre deben ser atravesables por Apache
namei -l $API/public
```

En `namei`, cada carpeta (`hermantorres`, `eude`, `pymehub`) necesita `x` para www-data (grupo u otros). Si falta: `sudo chmod o+x <carpeta>`.

---

## 3. Base de datos: MariaDB 11.8 existente

Pyme Hub usa el MariaDB que ya tiene el servidor (`DEC-05`). La base `pymehub` y el usuario `pymehub_user` ya existen. Aquí solo se **comprueba** que están listos; no hay nada que importar: el asistente del paso 7 crea las tablas a partir de las migraciones, y los datos de demostración se cargan en el paso 9.

Todo se ejecuta como root con el cliente `mariadb`. Nada de esto cambia el motor ni las bases de otras aplicaciones. En este VPS root tiene contraseña: añade `-u root -p` a cada `mariadb` (`mariadb -u root -p -e "…"`); sin ello responde *Access denied for user 'root'@'localhost'*.

### 3.1 Comprobaciones (solo lectura)

```bash
mariadb -e "SELECT VERSION();
  SHOW COLLATION LIKE 'utf8mb4_0900_ai_ci';
  SELECT SCHEMA_NAME, DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
    FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'pymehub';
  SELECT COUNT(*) AS tablas FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'pymehub';
  SELECT user, host, plugin FROM mysql.user WHERE user = 'pymehub_user';"
command -v mariadb-dump mysqldump
```

| Comprobación | Esperado | Si no |
|---|---|---|
| `VERSION()` | `11.8.x-MariaDB` | — |
| Collation `utf8mb4_0900_ai_ci` | Devuelve una fila | Pásalo al hilo de desarrollo (especificación, §3.2 opción B) |
| `pymehub` | `utf8mb4` | §3.2 |
| `tablas` | `0` | El instalador exige base vacía; ver §3.4 |
| `pymehub_user` | Una fila por host (`localhost`, `127.0.0.1` o `%`) | Anota el host: decide qué poner en «Servidor» en el paso 7 |
| `mariadb-dump` | Ruta del binario | Lo usa `cli/backup.sh` desde v0.3.1 |

### 3.2 Juego de caracteres de la base (solo si no es `utf8mb4`)

Solo afecta a la base `pymehub`, que está vacía:

```bash
mariadb -e "ALTER DATABASE pymehub CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
```

Si la collation no existe (3.1), usa `utf8mb4_uca1400_ai_ci`.

### 3.3 Permisos del usuario

```bash
mariadb -e "SHOW GRANTS FOR 'pymehub_user'@'localhost'"     # usa el host que viste en 3.1
```

Debe tener sobre `pymehub`.* al menos: `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP, LOCK TABLES`. Si falta alguno (ajusta el host):

```bash
mariadb -e "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP, LOCK TABLES
            ON pymehub.* TO 'pymehub_user'@'localhost'"
```

Ningún permiso fuera de la base `pymehub` (`RNF-002`).

**Comprobación de credenciales:** `mariadb -u pymehub_user -p -h localhost pymehub -e "SELECT 1"` devuelve `1`. Repite con `-h 127.0.0.1`: si solo funciona una de las dos, usa esa en el paso 7.

### 3.4 Prueba de compatibilidad (recomendada, antes del instalador)

Aplica las migraciones a mano en una base **temporal** que se borra al final. Valida en este MariaDB el mismo SQL que ejecutará el instalador, sin tocar `pymehub`:

```bash
mariadb -e "CREATE DATABASE pymehub_compat CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
for f in $API/migrations/*.sql; do
  echo "== $(basename $f)"
  mariadb --init-command="SET SESSION sql_mode='STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'; SET time_zone='+00:00'" pymehub_compat < "$f" || break
done
mariadb -e "SELECT COUNT(*) AS tablas FROM information_schema.TABLES WHERE TABLE_SCHEMA='pymehub_compat'"
mariadb -e "DROP DATABASE pymehub_compat"
```

Todas las migraciones (0001 a 0005 en v0.5.0) deben terminar sin error. Si alguna falla, copia el mensaje exacto al hilo de desarrollo.

Si `pymehub` tuviera tablas (3.1) de una prueba anterior, vacíala antes del paso 7, comprobando que no es de otra aplicación:

```bash
mariadb -e "DROP DATABASE pymehub; CREATE DATABASE pymehub CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
```

Los permisos del usuario sobre `pymehub.*` se conservan.

---

## 4. Extensiones de PHP y módulos de Apache

```bash
php -m | grep -Ei 'pdo_mysql|mbstring|openssl|sodium|fileinfo|intl|curl'
sudo apt install -y php8.4-mysql php8.4-mbstring php8.4-intl php8.4-curl
sudo a2enmod rewrite headers ssl
sudo systemctl reload apache2
```

Las siete extensiones deben aparecer. Si falta `intl`, el instalador se detiene con «Requisito no cumplido: Extensión intl». `curl` es la que usa el asesor de IA para llamar a Mistral. Si usas PHP-FPM, reinicia después: `sudo systemctl restart php8.4-fpm`.

---

## 5. Hosts virtuales

Los vhosts actuales sirven la **raíz** de cada repo, lo que expondría `src/`, `config/`, `storage/` y `docs/`. Hay que apuntarlos a `public/`. Solo se tocan los cuatro archivos de Pyme Hub.

### 5.1 Copia de seguridad de los actuales

```bash
cd /etc/apache2/sites-available
sudo mkdir -p /root/backup-vhosts-$(date +%F)
sudo cp pymehub.hermantorres.com*.conf api.pymehub.hermantorres.com*.conf /root/backup-vhosts-$(date +%F)/
```

Si en 1.4 los nombres o los certificados son distintos, ajusta abajo los nombres de archivo y las rutas `/etc/letsencrypt/live/<nombre>/`.

### 5.2 API

`sudo nano /etc/apache2/sites-available/api.pymehub.hermantorres.com.conf` — sustituye todo el contenido:

```apache
<VirtualHost *:80>
    ServerName api.pymehub.hermantorres.com
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
```

`sudo nano /etc/apache2/sites-available/api.pymehub.hermantorres.com-le-ssl.conf` — sustituye todo el contenido:

```apache
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName api.pymehub.hermantorres.com
    DocumentRoot /var/www/hermantorres/eude/pymehub/pymehub_api/public

    <Directory /var/www/hermantorres/eude/pymehub/pymehub_api>
        Require all denied
    </Directory>
    <Directory /var/www/hermantorres/eude/pymehub/pymehub_api/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <DirectoryMatch "/\.git">
        Require all denied
    </DirectoryMatch>

    # Solo con mod_php (con PHP-FPM, ver nota 5.5)
    <IfModule php_module>
        php_admin_value upload_max_filesize 5M
        php_admin_value post_max_size 6M
        php_admin_value expose_php Off
        php_admin_value date.timezone UTC
    </IfModule>

    ErrorLog ${APACHE_LOG_DIR}/pymehub-api-error.log
    CustomLog ${APACHE_LOG_DIR}/pymehub-api-access.log combined

    SSLCertificateFile /etc/letsencrypt/live/api.pymehub.hermantorres.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/api.pymehub.hermantorres.com/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf
</VirtualHost>
</IfModule>
```

Las cabeceras de seguridad, CORS y HSTS de la API las emite el propio código (`src/Middleware/Http.php`); no se duplican aquí.

### 5.3 Web

`sudo nano /etc/apache2/sites-available/pymehub.hermantorres.com.conf`:

```apache
<VirtualHost *:80>
    ServerName pymehub.hermantorres.com
    RewriteEngine On
    RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
    RewriteRule ^ https://%{SERVER_NAME}%{REQUEST_URI} [END,NE,R=permanent]
</VirtualHost>
```

`sudo nano /etc/apache2/sites-available/pymehub.hermantorres.com-le-ssl.conf`:

```apache
<IfModule mod_ssl.c>
<VirtualHost *:443>
    ServerName pymehub.hermantorres.com
    DocumentRoot /var/www/hermantorres/eude/pymehub/pymehub_web/public

    <Directory /var/www/hermantorres/eude/pymehub/pymehub_web>
        Require all denied
    </Directory>
    <Directory /var/www/hermantorres/eude/pymehub/pymehub_web/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    <DirectoryMatch "/\.git">
        Require all denied
    </DirectoryMatch>

    # La web nunca ejecuta PHP
    <FilesMatch "\.php$">
        Require all denied
    </FilesMatch>

    ErrorLog ${APACHE_LOG_DIR}/pymehub-web-error.log
    CustomLog ${APACHE_LOG_DIR}/pymehub-web-access.log combined

    SSLCertificateFile /etc/letsencrypt/live/pymehub.hermantorres.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/pymehub.hermantorres.com/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf
</VirtualHost>
</IfModule>
```

`AllowOverride All` es necesario: CSP, HSTS, caché, tipos MIME y la redirección a HTTPS de la web viven en `public/.htaccess` del repo.

### 5.4 Solo si 1.5 devolvió 200 · cerrar el acceso desde hermantorres.com

En **los dos** vhosts de `hermantorres.com` (puerto 80 y, sobre todo, el **443**, `…-le-ssl.conf`), dentro de `<VirtualHost>`, añade:

```apache
<Location "/eude/pymehub">
    Require all denied
</Location>
```

Es el único cambio en un vhost ajeno a Pyme Hub y solo afecta a las URL `/eude/pymehub` de ese sitio. En la instalación real, el bloqueo estaba solo en el vhost del puerto 80 y `https://hermantorres.com/eude/pymehub/…/.git/HEAD` respondía **200**: compruébalo siempre por HTTPS.

```bash
sudo apache2ctl -S 2>/dev/null | grep -i "hermantorres.com"      # archivos que atienden el dominio
grep -Rln "eude/pymehub" /etc/apache2/sites-enabled/              # en cuáles está ya el bloqueo
for u in eude/pymehub/pymehub_api/config/config.php eude/pymehub/pymehub_api/storage/backups/ultima_copia.txt \
         eude/pymehub/pymehub_api/.git/HEAD eude/pymehub/pymehub_web/.git/HEAD; do
  echo "$(curl -s -o /dev/null -w '%{http_code}' https://hermantorres.com/$u)  $u"; done     # las cuatro, 403 o 404
```

### 5.5 Nota PHP-FPM

Si 1.3 mostró PHP-FPM con `php8.4-fpm.conf` activo en `conf-enabled`, PHP ya funciona en el vhost de la API sin más cambios; los `php_admin_value` no aplican. Los límites por defecto (subida de 2 MB) bastan para el MVP. Para fijarlos sin tocar la configuración global, crea el pool propio descrito en el apartado 10.3 del manual 01 y añade su `SetHandler` al vhost 443 de la API.

### 5.6 Activar

```bash
sudo apache2ctl configtest           # Syntax OK
sudo systemctl reload apache2
sudo certbot renew --dry-run --cert-name api.pymehub.hermantorres.com
```

**Comprobaciones:**

```bash
curl -sI http://api.pymehub.hermantorres.com/v1/health | head -1     # 301
curl -s  https://api.pymehub.hermantorres.com/v1/health              # PH-SYS-004 (instalación pendiente): correcto
curl -s -o /dev/null -w "%{http_code}\n" https://api.pymehub.hermantorres.com/src/bootstrap.php   # 404
curl -s -o /dev/null -w "%{http_code}\n" https://pymehub.hermantorres.com/README.md               # 404
curl -s -o /dev/null -w "%{http_code}\n" https://pymehub.hermantorres.com/                         # 200
```

Si la web da **403** en `/` (y 404 en `js/config.js`), el `DocumentRoot` del `-le-ssl.conf` de la web no termina en `/public`.

Comprueba también que los demás sitios del servidor siguen respondiendo (`curl -sI https://hermantorres.com | head -1`, `https://entrenaymas.com`, etc.).

---

## 6. Configuración de la web

```bash
sudo cp $WEB/public/js/config.example.js $WEB/public/js/config.js
sudo chown root:www-data $WEB/public/js/config.js && sudo chmod 640 $WEB/public/js/config.js
grep apiUrl $WEB/public/js/config.js        # https://api.pymehub.hermantorres.com
```

`config.js` no se versiona: `git checkout` de nuevas versiones no lo pisa.

---

## 7. Asistente de instalación de la API

Abre **`https://api.pymehub.hermantorres.com/install`** en el navegador:

| Campo | Valor |
|---|---|
| Servidor / Puerto | `localhost` o `127.0.0.1`, el que funcionó en 3.3 / `3306` |
| Base de datos / Usuario / Contraseña | `pymehub` / `pymehub_user` / la del paso 3 |
| URL de esta API | `https://api.pymehub.hermantorres.com` |
| URL del frontend | `https://pymehub.hermantorres.com` (sin barra final) |
| Dominio de la cookie | vacío |
| Cookie solo por HTTPS | Sí |
| Administrador | tu nombre, email y contraseña de 12 caracteres o más |
| Clave de Mistral | opcional; sin ella el asesor responde con el respaldo por reglas. Puede añadirse después (apartado 8.1) |

El asistente crea el esquema completo (todas las migraciones), el catálogo de formación (15 módulos), el administrador, escribe `config/config.php` y se bloquea.

```bash
sudo chown root:www-data $API/config/config.php
sudo chmod 640 $API/config/config.php
sudo -u www-data php $API/cli/migrate.php                       # "nada pendiente"
sudo cp $API/config/config.php /root/config.pymehub.$(date +%F).php
```

Descarga esa copia a tu PC y guárdala en el gestor de contraseñas: contiene la clave de cifrado de las notas de bienestar (`LEG-003`). Si se pierde, esas notas no se recuperan.

---

## 8. Copias de seguridad y tareas programadas

Este VPS no traía `cron` (`crontab: command not found`). Las tareas van en un archivo del sistema, sin `crontab -e`:

```bash
sudo apt install -y cron && sudo systemctl enable --now cron

sudo tee /etc/cron.d/pymehub > /dev/null <<'CRON'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
API=/var/www/hermantorres/eude/pymehub/pymehub_api
0 2 * * *  www-data  bash $API/cli/backup.sh >> $API/storage/logs/backup.log 2>&1
0 4 * * *  www-data  php $API/cli/purge_retention.php >> $API/storage/logs/cron.log 2>&1
0 6 * * 1  www-data  php $API/cli/wellbeing_alerts.php >> $API/storage/logs/cron.log 2>&1
0 3 * * *  www-data  php $API/cli/recalc_risk.php >> $API/storage/logs/cron.log 2>&1
30 3 * * *  www-data  php $API/cli/demo_seed.php --reset >> $API/storage/logs/demo_seed.log 2>&1
30 4 1 1,4,7,10 *  www-data  php $API/cli/bias_report.php --salida=$API/storage/logs/sesgos_$(date +\%Y\%m).md >> $API/storage/logs/cron.log 2>&1
CRON
sudo chmod 644 /etc/cron.d/pymehub
systemctl is-active cron && cat /etc/cron.d/pymehub
```

Primera copia manual (sin ella `/v1/health` muestra `copia_seguridad: sin_datos`). Con `bash` delante funciona aunque el script pierda el permiso de ejecución:

```bash
sudo -u www-data bash $API/cli/backup.sh          # "Copia completada con mariadb-dump: …"
```

Si da *Permission denied*, www-data no puede leer el script: `sudo chown root:www-data $API/cli/backup.sh && sudo chmod 750 $API/cli/backup.sh`.

**Prueba de restauración** (`RNF-064`) en una base temporal:

```bash
M=mariadb
$M -e "CREATE DATABASE pymehub_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
sudo zcat "$(sudo ls -1t $API/storage/backups/db-*.sql.gz | head -1)" | $M pymehub_restore
$M -e "SELECT COUNT(*) FROM pymehub_restore.usuario; DROP DATABASE pymehub_restore;"
```

### 8.1 Asesor de formación con IA (opcional)

Sin clave, el asesor responde siempre con el respaldo por reglas, indicado en la app como «Recomendación automática sin IA». Para activarlo con Mistral:

1. Edita `config/config.php` (como root; **la clave nunca se comparte por chat ni se sube al repositorio**, `P-07`, `IA-020`):

   ```php
   'ia' => [
       'provider' => 'mistral',
       'base_url' => 'https://api.mistral.ai/v1',
       'model'    => 'mistral-small-latest',
       'api_key'  => 'LA_CLAVE_DE_MISTRAL',
       'mode'     => 'cache_first',   // live | cache_first | offline (IA-015)
   ],
   ```

2. Comprueba que el servidor llega al proveedor y pregenera los perfiles de la demo (§12.6):

   ```bash
   curl -s -o /dev/null -w '%{http_code}\n' https://api.mistral.ai/v1/models      # 401 sin clave = hay red
   sudo -u www-data php $API/cli/ai_warmup.php                                    # "pregenerada" por perfil y empresa
   ```

3. Para la defensa, `mode` = `offline` sirve las respuestas pregeneradas sin depender de la red; los perfiles no pregenerados responden por reglas. `GET /v1/admin/ai-usage` (o el inicio del administrador) muestra cuántas respuestas salen de cada origen.

### 8.2 Demo comercial y QR del folleto

El QR del folleto apunta a `https://pymehub.hermantorres.com/demo`, una ruta estable que redirige (302, sin caché) a la landing en modo demo. La redirección vive en el `.htaccess` de la web: no se toca el vhost. Requerimiento completo: `docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md`.

En `config/config.php`, al final del `return [ … ]`:

```php
'demo' => [
    'habilitada'       => true,           // false = demo apagada al instante (PH-DEMO-002), sin desplegar
    'habilitada_hasta' => null,           // o 'AAAA-MM-DD' para que caduque sola
    'password'         => 'PymeHub-Demo', // la impresa en el folleto; no cambiarla sin reimprimir
    'password_admin'   => 'UNA_CONTRASEÑA_SOLO_PARA_TI',  // admin@pymehub.demo, nunca se publica
],
'campaigns' => ['folleto_comercial'],
```

En la web, `public/js/config.js`:

```js
export const config = {
  apiUrl: 'https://api.pymehub.hermantorres.com',
  contactEmail: 'info@hyo.company',
};
```

- **Reinicio nocturno** a las 03:30: la línea `demo_seed.php --reset` de `/etc/cron.d/pymehub` (apartado 8). Solo borra y recrea las empresas marcadas con `es_demo = 1`; el administrador y las métricas se conservan.
- **Antes de cada presentación:** `sudo -u www-data php $API/cli/demo_seed.php --reset`, la prueba de humo con `'PymeHub-Demo'` y escanear el QR con dos móviles.
- **QR de instalación de la app:** `https://pymehub.hermantorres.com/app` → página «Instala Pyme Hub». Comprobación: `curl -sI https://pymehub.hermantorres.com/app` da 302 a `…#/instalar`. Los QR para imprimir están en `pymehub_web/docs/qr/`.
- **Métricas:** `admin@pymehub.demo` → Resumen → «Códigos QR» (folleto o instalar la app; hoy, 7 días, desde el inicio).
- **Si hay abuso:** `'habilitada' => false` en `config.php`. La landing muestra «La demostración no está disponible» y las sesiones demo abiertas se cierran.

---

## 9. Verificación

```bash
curl -s https://api.pymehub.hermantorres.com/v1/health
#  "estado":"ok", "version":"0.7.1", base_datos ok, copia_seguridad ok
curl -s -o /dev/null -w "%{http_code}\n" https://api.pymehub.hermantorres.com/install            # 404
curl -s -o /dev/null -w "%{http_code}\n" https://api.pymehub.hermantorres.com/config/config.php  # 404

# CORS: la API acepta el origen de la web
curl -s -D - -o /dev/null -X OPTIONS https://api.pymehub.hermantorres.com/v1/auth/login \
  -H "Origin: https://pymehub.hermantorres.com" -H "Access-Control-Request-Method: POST" \
  | grep -i access-control-allow-origin                     # https://pymehub.hermantorres.com

# Web: cabeceras de .htaccess activas
curl -sI https://pymehub.hermantorres.com/ | grep -Ei 'content-security|strict-transport'
```

**Prueba de humo** (174 comprobaciones con la contraseña de la demo; 167 sin ella). Crea empresas y usuarios de prueba: ejecútala **antes** de cargar la demo. Si no puede iniciar sesión como administrador, se detiene y lo dice (revisa la contraseña; tras 5 intentos, espera 15 minutos).

```bash
cd $API
bash tests/smoke_test.sh https://api.pymehub.hermantorres.com https://pymehub.hermantorres.com TU_EMAIL_ADMIN 'TU_CONTRASEÑA_ADMIN' 'PymeHub-Demo'
# Resultado: 174 correctas, 0 fallidas
```

**Datos de demostración** (repítelo la víspera de la defensa):

```bash
sudo -u www-data php $API/cli/demo_seed.php --reset       # contraseñas de config.php (apartado 8.2)
#   → … Riesgo de rotación (Castilla): 6 en nivel alto, 10 en medio, 6 alertas, … puntuaciones históricas
sudo -u www-data php $API/cli/ai_warmup.php          # solo si hay clave de IA (8.1)
sudo -u www-data php $API/cli/recalc_risk.php        # comprobación: "… 6 en nivel alto, 0 alerta(s) nueva(s)"
```

**En el navegador** (`https://pymehub.hermantorres.com`):

1. Carga el sitio público; "Acceder" lleva al login.
2. `gerente@pymehub.demo` → Inicio y Bienestar con datos; el equipo Nocturno aparece en nivel Bajo con su alerta.
3. `conductor@pymehub.demo` → Check-in; en el móvil, con modo avión, se guarda y se envía al recuperar señal.
4. `conductor@pymehub.demo` → Formación: «Conducción nocturna segura» a medias, cuestionario y pestaña **Asesor** con 3 recomendaciones e inscripción en un toque.
5. `gerente@pymehub.demo` → Formación → Recomendaciones y demanda formativa del equipo.
6. `rrhh@pymehub.demo` → **IA de RR. HH.**: 6 personas en riesgo alto, alertas de rotación, tendencias, línea base y comprobación retrospectiva; «Por persona», «Sesgos» y «Metodología». La ficha de una persona en riesgo alto muestra los factores y «¿De dónde sale esto?».
7. `gerente2@pymehub.demo` (plan Entrada) → IA de RR. HH. muestra la vista informativa del plan Completo.
8. Consola del navegador (F12) sin errores de CSP ni de CORS.

Externas: [securityheaders.com](https://securityheaders.com) y [SSL Labs](https://www.ssllabs.com/ssltest/) sobre ambos subdominios; objetivo, nota A.

---

## 10. Monitorización

UptimeRobot (o similar), monitor *Keyword* sobre `https://api.pymehub.hermantorres.com/v1/health` con la palabra `"estado":"ok"` cada 5 minutos, y uno HTTP sobre `https://pymehub.hermantorres.com`.

Errores de la API: `$API/storage/logs/api-AAAA-MM-DD.log` (JSON con `cid`). Errores de Apache: `/var/log/apache2/pymehub-api-error.log` y `pymehub-web-error.log`.

---

## 11. Actualizar a una versión nueva

Siempre **primero la API, después la web** (`RNF-067`).

```bash
export API=/var/www/hermantorres/eude/pymehub/pymehub_api WEB=/var/www/hermantorres/eude/pymehub/pymehub_web V=vX.Y.Z

sudo -u www-data bash $API/cli/backup.sh
sudo -u www-data touch $API/storage/maintenance.flag

cd $API && git config core.fileMode false && git fetch --tags origin && git checkout $V
sudo chown -R root:www-data $API && sudo chmod 2770 $API/storage $API/config && sudo chmod 750 $API/cli/backup.sh
sudo -u www-data php $API/cli/migrate.php        # migraciones pendientes y catálogo de formación

sudo rm $API/storage/maintenance.flag
curl -s https://api.pymehub.hermantorres.com/v1/health          # "version" = la nueva

cd $WEB && git config core.fileMode false && git fetch --tags origin && git checkout $V
sudo chown -R root:www-data $WEB
```

- **Nunca `git push` desde el servidor**: producción solo descarga (el 403 de GitHub es lo esperado).
- Si `git checkout` dice *Your local changes … would be overwritten*, son cambios de permisos o restos de un zip: `git checkout -- <archivo>` (o `git reset --hard HEAD`; `config.php`, `config.js` y `storage/` no se tocan porque git los ignora) y repite.
- Los navegadores cogen la web nueva al recargar dos veces (service worker).

Si una migración falla, restaura la copia del primer comando y vuelve a la etiqueta anterior antes de reintentar (apartado 16 del manual 01).

---

## 12. Problemas frecuentes en este servidor

| Síntoma | Causa | Solución |
|---|---|---|
| `/install` dice "MariaDB … requiere MySQL 8.4" | La API desplegada es anterior a v0.3.1 | Paso 2 con `v0.3.1` |
| `/install`: `Access denied for user 'pymehub_user'` | El host del usuario no coincide con el servidor elegido | Usa `localhost` o `127.0.0.1` según 3.3 |
| `/install`: `Unknown collation 'utf8mb4_0900_ai_ci'` | MariaDB sin el alias de compatibilidad | Pásalo al hilo de desarrollo (especificación, §3.2 opción B) |
| `/install` no puede escribir `config.php` | Permisos de `config/` | `sudo chmod 2770 $API/config` y grupo `www-data` |
| 404 de Apache en `/v1/health` | `DocumentRoot` sin `/public`, `mod_rewrite` inactivo o `AllowOverride` distinto de `All` | Paso 5 |
| 403 en todo el sitio | Carpetas padre sin `x` para www-data | `namei -l`, paso 2 |
| La web muestra "No se pudo conectar" | Falta `public/js/config.js` o CSP bloquea la API | Paso 6; revisa la consola |
| La sesión no se mantiene | URL del frontend con barra final o `cookie_secure` falso | Corrige `config/config.php` |
| `/v1/health` con `copia_seguridad: sin_datos` o `antigua` | Nunca se ha hecho una copia o el cron no se ejecuta | Copia manual (8), `systemctl is-active cron`, `cat /etc/cron.d/pymehub`, `storage/logs/backup.log` |
| `backup.sh`: *Permission denied* o *command not found* | www-data no puede leer el script o perdió el permiso de ejecución | `chown root:www-data`, `chmod 750` y lánzalo con `bash` |
| `crontab: command not found` | Debian mínimo sin `cron` | Paso 8 |
| `sudo: unknown user pymehub` | En este servidor no existe ese usuario | Ejecuta los `git` como root, sin `sudo -u pymehub` |
| `mariadb` como root: *Access denied* | root tiene contraseña | `mariadb -u root -p` |
| `/install`: «Requisito no cumplido: Extensión intl» | Falta `php8.4-intl` | Paso 4 |
| Prueba de humo: 12 correctas y 75 fallidas | Contraseña de ejemplo en lugar de la real | Repite con la contraseña del instalador |
| `git checkout`: *Your local changes … cli/backup.sh* | Cambio de permisos visto por git | `git config core.fileMode false` y `git checkout -- cli/backup.sh` |
| La web da 403 y `js/config.js` 404 | `DocumentRoot` sin `/public` | Paso 5.3 |
| `hermantorres.com/eude/pymehub/…/.git/HEAD` da 200 | Bloqueo solo en el vhost del puerto 80 | Paso 5.4, en el `-le-ssl.conf` |
| Consola: `GET …/v1/auth/me 401` al abrir la web | Web anterior a la 0.4.0 | Inofensivo; desaparece al actualizar |
| El asesor siempre dice «Recomendación automática sin IA» | Sin clave, `mode` = `offline` sin respuestas pregeneradas, o sin salida a `api.mistral.ai` | Apartado 8.1 |
| `git fetch` pide contraseña o falla | Remoto sin alias de *deploy key* | `git remote -v` debe mostrar `git@github-pymehub_api:` / `git@github-pymehub_web:` |

---

## 13. Lista de comprobación final

- [ ] API y web v0.5.0 en GitHub y desplegadas, con `core.fileMode false` en los dos repositorios.
- [ ] Base `pymehub` vacía, `utf8mb4`, permisos solo sobre ella; prueba de compatibilidad correcta; MariaDB y las demás aplicaciones intactas.
- [ ] Vhosts apuntando a `public/`, `configtest` correcto, renovación de certificados en *dry-run* correcta.
- [ ] `hermantorres.com/eude/...` no expone el proyecto.
- [ ] `config.js` de la web creado.
- [ ] Instalador completado, `/install` 404, `config.php` 640 y copia fuera del servidor.
- [ ] `/etc/cron.d/pymehub` con copias, retención, alertas, riesgo diario (03:00) y sesgos trimestrales; primera copia hecha; restauración probada.
- [ ] `/v1/health` en `ok` con versión 0.5.0 y `copia_seguridad: ok`.
- [ ] Prueba de humo 174/174 y datos de demo regenerados (y `ai_warmup` si hay clave).
- [ ] `curl -sI https://pymehub.hermantorres.com/demo` da 302 con `modo=demo`, y el QR impreso abre el panel «Pruebe Pyme Hub» en dos móviles.
- [ ] Recorrido en navegador sin errores de consola; notas A externas.
- [ ] Monitor externo activo.
