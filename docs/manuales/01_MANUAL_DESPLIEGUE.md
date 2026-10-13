# Manual 01 · Despliegue de Pyme Hub

**Aplica a:** `pymehub_api` 0.5.0 y `pymehub_web` 0.5.0
**Entorno:** VPS Hostinger · Debian 13 · Apache 2.4 · PHP 8.4 · **MariaDB 11.8 LTS** (o MySQL 8.4 LTS) · sin Docker
**Dominios:** `api.pymehub.hermantorres.com` (API) y `pymehub.hermantorres.com` (web)
**Última revisión:** 12/10/2026 (v0.5.0: asesor de formación con IA; v0.4.0: formación y lecciones del primer despliegue)
**Rutas en el servidor:**

| Repositorio | Ruta | DocumentRoot |
|---|---|---|
| `pymehub_api` | `/var/www/hermantorres/eude/pymehub/pymehub_api` | `…/pymehub_api/public` |
| `pymehub_web` | `/var/www/hermantorres/eude/pymehub/pymehub_web` | `…/pymehub_web/public` |

> **Los comandos de este manual llevan textos de ejemplo** en mayúsculas (`TU_CONTRASEÑA_ADMIN`, `CONTRASEÑA-DE-LA-DEMO`…). Sustitúyelos por el valor real antes de ejecutarlos: copiados tal cual, el comando falla (por ejemplo, la prueba de humo da 12 correctas y 75 fallidas porque no puede iniciar sesión).

`/var/www/hermantorres` pertenece a otro sitio del mismo servidor: **no cambies su propietario ni sus permisos**. Aplica `chown` y `chmod` solo dentro de `…/eude/pymehub/pymehub_api` y `…/eude/pymehub/pymehub_web`.

> El procedimiento concreto del VPS de producción (`srv1616185`), con su MariaDB compartido, está en el **manual 02** (`02_DESPLIEGUE_VPS_PRODUCCION.md`). Este manual es la referencia general para cualquier servidor.

---

## 1. Qué cubre este manual

Este manual lleva Pyme Hub desde un servidor que ya aloja otros sitios hasta tener la API y la web publicadas con HTTPS, copias de seguridad y monitorización. Está escrito para ejecutarse por SSH con un usuario con `sudo`.

Las reglas que se respetan en todo el proceso:

- No se modifica ningún vhost, base de datos ni configuración global que pertenezca a otro sitio del servidor (`RNF-001`).
- Los secretos (contraseñas, claves) solo viven en el servidor, nunca en los repositorios ni en el chat.
- Cada paso termina con una comprobación. Si la comprobación falla, no se continúa.

Los comandos usan estos valores; cámbialos si los tuyos son distintos:

| Variable | Valor |
|---|---|
| Ruta de la API | `/var/www/hermantorres/eude/pymehub/pymehub_api` |
| Ruta de la web | `/var/www/hermantorres/eude/pymehub/pymehub_web` |
| Usuario de despliegue | `pymehub` |
| Base de datos y usuario MySQL | `pymehub` / `pymehub_user` |

---

## 2. Arquitectura del despliegue

```mermaid
flowchart LR
    U[Navegador o móvil] -->|HTTPS| W[pymehub.hermantorres.com<br/>Apache · archivos estáticos<br/>/var/www/hermantorres/eude/pymehub/pymehub_web]
    U -->|HTTPS · fetch con cookie| A[api.pymehub.hermantorres.com<br/>Apache · PHP 8.4<br/>/var/www/hermantorres/eude/pymehub/pymehub_api/public]
    A --> D[(MariaDB 11.8 o MySQL 8.4<br/>base pymehub)]
    A -. solo datos no personales .-> M[Mistral AI]
    C[cron] --> A
    UR[Monitor externo] -->|cada 5 min| H[/v1/health/]
```

La web no tiene código de servidor: solo descarga HTML, CSS y JavaScript, y todo lo demás lo pide a la API.

---

## 3. Requisitos previos

- [ ] Acceso SSH al VPS con un usuario con `sudo`.
- [ ] Acceso al panel DNS de GoDaddy de `hermantorres.com`.
- [ ] Acceso de lectura a los repositorios `hermantorreshyo/pymehub_api` y `hermantorreshyo/pymehub_web`.
- [ ] Una copia de seguridad reciente del servidor (panel de Hostinger → *Snapshots*) antes de empezar.

---

## 4. Paso 0 · Comprobar el motor de base de datos

Pyme Hub funciona sobre **MariaDB 11.4.5 o superior** (recomendado 11.8 LTS, el de Debian 13) y sobre **MySQL 8.4 LTS o superior** (`DEC-05`, `RNF-008`). **No sustituyas ni reconfigures un motor que ya usen otras aplicaciones**: Pyme Hub solo necesita su propia base y su propio usuario.

```bash
mariadb --version 2>/dev/null || mysql --version 2>/dev/null || echo "Sin motor instalado"
systemctl list-units --type=service | grep -Ei 'mysql|mariadb'
```

| Resultado | Qué hacer |
|---|---|
| MariaDB **11.4.5 o superior** (p. ej. `11.8.6-MariaDB-0+deb13u1`) | Úsalo. Continúa en el apartado 6. |
| MySQL **8.4.x o superior** | Úsalo. Continúa en el apartado 6. |
| `Sin motor instalado` | Instala MariaDB 11.8 desde Debian 13 (apartado 5). |
| MariaDB 10.x u 11.0–11.4.4, o MySQL 8.0 | Versión no admitida: el instalador la rechaza. Actualiza en una ventana pactada, con copia previa de todas las bases, o usa otro servidor. |

Comprueba también la collation de las migraciones (en MariaDB existe desde la 11.4.5):

```bash
sudo mariadb -e "SELECT CONVERT('a' USING utf8mb4) COLLATE utf8mb4_0900_ai_ci AS ok"   # con MySQL: sudo mysql -e …
```

Si responde `ok`, perfecto. Si da error, el migrador usa automáticamente la equivalente `utf8mb4_uca1400_ai_ci` en MariaDB. El instalador comprueba todo esto antes de crear nada.

---

## 5. Instalar el motor (solo si no hay ninguno)

**Opción recomendada: MariaDB 11.8 LTS de Debian 13.**

```bash
sudo apt update
sudo apt install -y mariadb-server mariadb-client
sudo mariadb-secure-installation
mariadb --version          # 11.8.x
systemctl is-active mariadb
```

**Alternativa: MySQL 8.4 LTS.** Se instala desde el repositorio APT oficial de Oracle (`mysql-apt-config`, opción `mysql-8.4-lts`). **No la instales si ya hay MariaDB**: los dos paquetes no pueden convivir en el mismo sistema.

---

## 6. Crear la base de datos y su usuario

Si la base y el usuario ya existen (caso de producción, manual 02), comprueba solo los permisos y el host del usuario.

```bash
sudo mariadb          # si root entra por socket (instalación por defecto de Debian)
mariadb -u root -p    # si root tiene contraseña (VPS de producción): "Access denied" con sudo mariadb
```

En los comandos de este manual, `sudo mariadb` puede sustituirse siempre por `mariadb -u root -p`.

```sql
CREATE DATABASE pymehub CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;
-- Elige el host según cómo se conectará la API (ver la nota de abajo)
CREATE USER 'pymehub_user'@'localhost' IDENTIFIED BY 'UNA_CONTRASEÑA_LARGA_Y_ÚNICA';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES, DROP, LOCK TABLES
   ON pymehub.* TO 'pymehub_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

Si la collation `utf8mb4_0900_ai_ci` no existe (apartado 4), crea la base con `utf8mb4_uca1400_ai_ci`.

**Host del usuario y servidor en el instalador.** En MariaDB, con `skip-name-resolve`, `'pymehub_user'@'localhost'` y `'pymehub_user'@'127.0.0.1'` son cuentas distintas:

| Usuario creado como | En el instalador, «Servidor» | Conexión |
|---|---|---|
| `'pymehub_user'@'localhost'` | `localhost` (y, si hace falta, el socket `/run/mysqld/mysqld.sock`) | Socket Unix |
| `'pymehub_user'@'127.0.0.1'` | `127.0.0.1`, puerto `3306` | TCP |

Para ver cómo está creado: `sudo mariadb -e "SELECT user, host FROM mysql.user WHERE user = 'pymehub_user'"`.

Guarda la contraseña en tu gestor de contraseñas: la pedirá el asistente de instalación.

**Comprobación:** `mariadb -u pymehub_user -p pymehub -e "SELECT 1"` devuelve `1` (con `-h 127.0.0.1` si el usuario es de TCP).

---

## 7. PHP 8.4 y extensiones

Debian 13 trae PHP 8.4. Comprueba qué tiene ya el servidor y añade lo que falte:

```bash
php -v
php -m | grep -Ei 'pdo_mysql|mbstring|openssl|sodium|fileinfo|intl|curl'
apache2ctl -M 2>/dev/null | grep -Ei 'php|proxy_fcgi|rewrite|headers|ssl'
```

```bash
sudo apt install -y php8.4-mysql php8.4-mbstring php8.4-intl php8.4-curl
sudo a2enmod rewrite headers ssl
```

Anota si Apache ejecuta PHP con **mod_php** (`php_module`) o con **PHP-FPM** (`proxy_fcgi_module`): cambia un detalle del vhost (apartado 10).

---

## 8. DNS en GoDaddy

En *Mis productos → hermantorres.com → DNS*, añade dos registros:

| Tipo | Nombre | Valor | TTL |
|---|---|---|---|
| A | `pymehub` | IP pública del VPS | 1 hora |
| A | `api.pymehub` | IP pública del VPS | 1 hora |

**Comprobación** (puede tardar unos minutos):

```bash
dig +short pymehub.hermantorres.com
dig +short api.pymehub.hermantorres.com
```

Ambos deben devolver la IP del VPS.

---

## 9. Usuario de despliegue y código

```bash
# Usuario propietario del código (sin acceso como root)
sudo adduser --disabled-password --gecos "" pymehub
sudo usermod -aG www-data pymehub

# Clonar los repositorios (la carpeta padre puede existir ya: no cambies sus permisos)
sudo mkdir -p /var/www/hermantorres/eude/pymehub
sudo mkdir -p /var/www/hermantorres/eude/pymehub/pymehub_api /var/www/hermantorres/eude/pymehub/pymehub_web
sudo chown pymehub:www-data /var/www/hermantorres/eude/pymehub/pymehub_api /var/www/hermantorres/eude/pymehub/pymehub_web
sudo -u pymehub git clone https://github.com/hermantorreshyo/pymehub_api.git /var/www/hermantorres/eude/pymehub/pymehub_api
sudo -u pymehub git clone https://github.com/hermantorreshyo/pymehub_web.git /var/www/hermantorres/eude/pymehub/pymehub_web

# Fijar la versión que se despliega (etiqueta)
cd /var/www/hermantorres/eude/pymehub/pymehub_api && sudo -u pymehub git checkout v0.5.0
cd /var/www/hermantorres/eude/pymehub/pymehub_web && sudo -u pymehub git checkout v0.5.0
```

Permisos de la API: el código es de solo lectura para Apache; solo `storage/` y `config/` son escribibles.

```bash
cd /var/www/hermantorres/eude/pymehub/pymehub_api
sudo chown -R pymehub:www-data .
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;
sudo chmod 750 cli/backup.sh tests/smoke_test.sh
sudo chmod 2770 storage config
sudo -u www-data mkdir -p storage/backups storage/logs
```

`www-data` debe poder **leer** `cli/backup.sh` (grupo `www-data` y permiso 750). Si el código lo copió otro usuario, `sudo -u www-data bash cli/backup.sh` falla con *Permission denied*: repite el `chown` de arriba. Lanzar el script con `bash` delante funciona aunque se pierda el permiso de ejecución al copiar o descomprimir.

Web (solo archivos estáticos):

```bash
cd /var/www/hermantorres/eude/pymehub/pymehub_web
sudo chown -R pymehub:www-data .
sudo find . -type d -exec chmod 750 {} \;
sudo find . -type f -exec chmod 640 {} \;
sudo -u pymehub cp public/js/config.example.js public/js/config.js    # apiUrl: https://api.pymehub.hermantorres.com
```

---

## 10. Hosts virtuales de Apache

### 10.1 API · `/etc/apache2/sites-available/pymehub-api.conf`

```apache
<VirtualHost *:80>
    ServerName api.pymehub.hermantorres.com
    DocumentRoot /var/www/hermantorres/eude/pymehub/pymehub_api/public

    <Directory /var/www/hermantorres/eude/pymehub/pymehub_api>
        Require all denied
    </Directory>
    <Directory /var/www/hermantorres/eude/pymehub/pymehub_api/public>
        AllowOverride All
        Require all granted
        Options -Indexes
    </Directory>
    <DirectoryMatch "/\.git">
        Require all denied
    </DirectoryMatch>

    # Solo con mod_php. Con PHP-FPM, pon estos valores en el pool (ver 10.3)
    <IfModule php_module>
        php_admin_value upload_max_filesize 5M
        php_admin_value post_max_size 6M
        php_admin_value expose_php Off
        php_admin_value date.timezone UTC
    </IfModule>

    ErrorLog ${APACHE_LOG_DIR}/pymehub-api-error.log
    CustomLog ${APACHE_LOG_DIR}/pymehub-api-access.log combined
</VirtualHost>
```

### 10.2 Web · `/etc/apache2/sites-available/pymehub-web.conf`

```apache
<VirtualHost *:80>
    ServerName pymehub.hermantorres.com
    DocumentRoot /var/www/hermantorres/eude/pymehub/pymehub_web/public

    <Directory /var/www/hermantorres/eude/pymehub/pymehub_web>
        Require all denied
    </Directory>
    <Directory /var/www/hermantorres/eude/pymehub/pymehub_web/public>
        AllowOverride None
        Require all granted
        Options -Indexes
    </Directory>
    <DirectoryMatch "/\.git">
        Require all denied
    </DirectoryMatch>

    # Aquí nunca se ejecuta PHP
    <FilesMatch "\.php$">
        Require all denied
    </FilesMatch>

    <IfModule mod_headers.c>
        Header always set X-Content-Type-Options "nosniff"
        Header always set Referrer-Policy "strict-origin-when-cross-origin"
        Header always set Permissions-Policy "camera=(), microphone=(), geolocation=()"
        Header always set Content-Security-Policy "default-src 'self'; script-src 'self' https://cdnjs.cloudflare.com; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self' https://api.pymehub.hermantorres.com; manifest-src 'self'; worker-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'"
        <FilesMatch "(index\.html|sw\.js|config\.js)$">
            Header set Cache-Control "no-cache"
        </FilesMatch>
    </IfModule>

    ErrorLog ${APACHE_LOG_DIR}/pymehub-web-error.log
    CustomLog ${APACHE_LOG_DIR}/pymehub-web-access.log combined
</VirtualHost>
```

### 10.3 Si el servidor usa PHP-FPM

Crea un pool propio para no tocar el de otros sitios, `/etc/php/8.4/fpm/pool.d/pymehub.conf`:

```ini
[pymehub]
user = www-data
group = www-data
listen = /run/php/php8.4-fpm-pymehub.sock
listen.owner = www-data
listen.group = www-data
pm = ondemand
pm.max_children = 10
php_admin_value[upload_max_filesize] = 5M
php_admin_value[post_max_size] = 6M
php_admin_value[expose_php] = Off
```

Y en el vhost de la API, dentro de `<VirtualHost>`:

```apache
<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.4-fpm-pymehub.sock|fcgi://localhost"
</FilesMatch>
```

### 10.4 Activar

```bash
sudo a2ensite pymehub-api pymehub-web
sudo apache2ctl configtest      # debe decir "Syntax OK"
sudo systemctl reload apache2
```

---

### 10.5 Rutas dentro de otro sitio: bloquear el acceso desde `hermantorres.com` (obligatorio)

Los repositorios viven dentro de `/var/www/hermantorres`. Si el sitio `hermantorres.com` usa esa carpeta (o una superior) como `DocumentRoot`, su vhost **también** sirve `…/eude/pymehub/…`. Eso dejaría accesibles desde fuera el código, `config/config.php`, `storage/` (logs y **copias de la base de datos**) y `.git`.

Los `.htaccess` con `Require all denied` de `config/`, `storage/`, `src/`, `cli/`… son una segunda barrera (`RNF-004`). Pero solo funcionan si ese vhost tiene `AllowOverride All`, y no cubren `.git` ni la raíz del repositorio.

1. Comprueba el `DocumentRoot` del otro sitio:

   ```bash
   grep -RhiE "ServerName|DocumentRoot" /etc/apache2/sites-enabled/ | paste - - | grep -i "hermantorres.com"
   ```

2. Si es `/var/www/hermantorres` o una carpeta superior, añade en **todos los vhosts de `hermantorres.com`** este bloque. Certbot crea un archivo aparte para HTTPS (`*-le-ssl.conf`): si solo lo pones en el del puerto 80, `https://hermantorres.com/eude/pymehub/…/.git/HEAD` sigue respondiendo **200**. Para ver qué archivos atienden el dominio:

   ```bash
   sudo apache2ctl -S 2>/dev/null | grep -i "hermantorres.com"
   grep -Rln "eude/pymehub" /etc/apache2/sites-enabled/     # en cuáles está ya el bloque
   ```

   El bloque: Solo afecta a las URL de ese sitio, no a los subdominios de Pyme Hub:

   ```apache
   # Pyme Hub se sirve solo por sus subdominios: aquí no debe verse nada
   <Location "/eude/pymehub">
       Require all denied
   </Location>
   ```

   ```bash
   sudo apache2ctl configtest && sudo systemctl reload apache2
   ```

3. Verifica. Las cuatro peticiones deben devolver **403 o 404**:

   ```bash
   for u in eude/pymehub/pymehub_api/config/config.php eude/pymehub/pymehub_api/storage/backups/ultima_copia.txt \
            eude/pymehub/pymehub_api/.git/HEAD eude/pymehub/pymehub_web/.git/HEAD; do
     echo "$(curl -s -o /dev/null -w '%{http_code}' https://hermantorres.com/$u)  $u"; done
   ```

> Es un cambio en la configuración de otro sitio: coordínalo con su responsable. No toca la configuración global de Apache (`RNF-001`) y no afecta al funcionamiento de `hermantorres.com`.

## 11. Certificados HTTPS

```bash
sudo certbot --apache -d api.pymehub.hermantorres.com -d pymehub.hermantorres.com
```

Elige redirigir HTTP a HTTPS. Certbot crea los `*-le-ssl.conf` y programa la renovación.

**Comprobación:** `curl -I https://api.pymehub.hermantorres.com/v1/health` devuelve `HTTP/2 503` con `PH-SYS-004` (instalación pendiente). Es lo esperado.

---

## 12. Asistente de instalación de la API

Abre `https://api.pymehub.hermantorres.com/install` y completa:

| Campo | Valor |
|---|---|
| Servidor / Puerto | `localhost` o `127.0.0.1` / `3306`, según el host del usuario (apartado 6) |
| Base de datos / Usuario / Contraseña | los del apartado 6 |
| URL de esta API | `https://api.pymehub.hermantorres.com` |
| URL del frontend | `https://pymehub.hermantorres.com` |
| Dominio de la cookie | vacío |
| Cookie solo por HTTPS | Sí |
| Administrador | tu nombre, email y una contraseña de al menos 12 caracteres |
| Clave de Mistral | opcional; sin ella, el asesor funciona sin conexión |

El asistente comprueba el servidor (incluidas las extensiones, también `intl`: `sudo apt install php8.4-intl` y `sudo systemctl reload apache2` si falta), rechaza MariaDB anterior a 11.4.5 y MySQL anterior a 8.4, exige una base vacía, crea el esquema, el catálogo de formación (15 módulos) y el administrador, escribe `config/config.php` y se bloquea.

Después:

```bash
sudo chown root:www-data /var/www/hermantorres/eude/pymehub/pymehub_api/config/config.php
sudo chmod 640 /var/www/hermantorres/eude/pymehub/pymehub_api/config/config.php
sudo cp /var/www/hermantorres/eude/pymehub/pymehub_api/config/config.php ~/config.pymehub.$(date +%F).php   # y guárdalo fuera del servidor
```

`config/config.php` contiene la clave de cifrado de las notas de bienestar: si se pierde, esas notas no se pueden recuperar.

---

### 12.1 Datos de demostración (defensa del TFM)

Para la defensa, la API incluye un generador de datos **ficticios** (`LEG-009`). Crea dos empresas:
- «Reparto Rápido Castilla S.L.»: plan Completo, 48 empleados y 4 equipos.
- «Mensajería Tajo S.L.»: plan Entrada, 15 empleados y 2 equipos.

También genera 60 días de check-ins, con el equipo Nocturno empeorando, y sus alertas; seis meses de incidencias (siniestros, quejas, retrasos y reconocimientos), campañas de formación por equipo con distinto avance, autoinscripciones y un módulo propio de Castilla.

```bash
cd /var/www/hermantorres/eude/pymehub/pymehub_api
sudo -u www-data php cli/demo_seed.php            # crear (contraseñas de config.php, sección 'demo')
sudo -u www-data php cli/demo_seed.php --reset    # regenerar desde cero (lo hace el cron cada noche a las 03:30)
sudo -u www-data php cli/demo_seed.php --borrar   # eliminarlos
```

| Usuario | Perfil | Empresa |
|---|---|---|
| `gerente@pymehub.demo` | Gerencia | Castilla (Completo) |
| `rrhh@pymehub.demo` | RR. HH. | Castilla (Completo) |
| `conductor@pymehub.demo` | Empleado (conductor de noche, 2 meses) | Castilla (Completo) |
| `gerente2@pymehub.demo` | Gerencia | Tajo (Entrada) |
| `admin@pymehub.demo` | Administración de la plataforma (solo pruebas internas, no es demo) | — |

**Demo comercial (v0.7.0).** Los cuatro primeros son cuentas de la demo pública (`es_demo = 1`) con la contraseña `demo.password` de `config.php`, la impresa en el folleto (`PymeHub-Demo`). El QR del folleto abre `https://pymehub.hermantorres.com/demo`, que lleva a la landing con el panel «Pruebe Pyme Hub». Tienen restricciones (`PH-DEMO-001`: cuentas, invitaciones, correos, CSV y bajas) y se bloquean por IP (20 fallos en 15 minutos), nunca por cuenta. El administrador tiene su propia contraseña (`demo.password_admin`), no aparece en el panel y el reinicio no lo borra. La demo se apaga sin desplegar con `'habilitada' => false`. Requerimiento completo: `docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md`. Los datos son relativos al día en que se generan, así que conviene **regenerarlos la víspera de la defensa** con `--reset`. El conductor no tiene check-in de ese día, para hacerlo en directo; tiene un módulo completado, otro a medias (Conducción nocturna segura) y el catálogo por explorar. La pestaña **Recomendaciones** del gerente muestra sugerencias por siniestros, quejas, acogida y la alerta del equipo Nocturno.

**Asesor de formación con IA.** Con la clave de Mistral en `config.php` (`ia.api_key`, nunca en el repositorio), pregenera las respuestas de los perfiles de la demo para poder usar `ia.mode = offline` en la defensa: `sudo -u www-data php cli/ai_warmup.php`. Sin clave, el asesor responde por reglas («Recomendación automática sin IA»). El detalle está en el manual 02, apartado 8.1.

**IA de RR. HH.** En Castilla hay 23 antiguos empleados (bajas de los últimos 12 meses), una puntuación de riesgo semanal desde el alta en Pyme Hub, 6 personas en riesgo alto con su alerta (una ya gestionada) y la línea base de los 12 meses anteriores. El conductor de la demo aparece en riesgo alto por su antigüedad, contrato y turno: el empleado nunca ve su propia puntuación. Tras cargar la demo, el cron de las 03:00 (`cli/recalc_risk.php`) añade la puntuación de cada día.

Si el comando responde «Los datos de demostración ya existen», ya se cargaron antes: añade `--reset` para regenerarlos.

## 13. Verificación

```bash
curl -s https://api.pymehub.hermantorres.com/v1/health
```

Respuesta esperada: `"estado":"ok"` con `base_datos: ok`.

```bash
curl -s https://api.pymehub.hermantorres.com/install -o /dev/null -w "%{http_code}\n"   # 404
curl -s https://api.pymehub.hermantorres.com/config/config.php -o /dev/null -w "%{http_code}\n"   # 404
```

**Prueba de humo.** `tests/smoke_test.sh` hace 171 comprobaciones (sesión, CSRF, perfiles, aislamiento entre empresas, organización, bienestar, formación, asesor de IA, riesgo de rotación y línea base, demo comercial, CORS, bloqueo por fuerza bruta). Con la contraseña de la demo como quinto argumento comprueba también el login demo, las sesiones concurrentes, `PH-DEMO-001` y el bloqueo por IP; el sexto es la URL de la web para la redirección `/demo`. Si no puede iniciar sesión como administrador se detiene y lo dice, en lugar de dar decenas de fallos. **Crea dos empresas y varios usuarios de prueba**, así que ejecútala en un entorno de pruebas, o en producción solo antes de cargar datos reales y borrando después lo creado:

```bash
cd /var/www/hermantorres/eude/pymehub/pymehub_api
bash tests/smoke_test.sh https://api.pymehub.hermantorres.com https://pymehub.hermantorres.com TU_EMAIL_ADMIN 'TU_CONTRASEÑA' 'PymeHub-Demo'
```

Resultado esperado: `171 correctas, 0 fallidas` (164 sin la contraseña de la demo).

**Web.** `curl -s -o /dev/null -w '%{http_code}\n' https://pymehub.hermantorres.com/` y `…/js/config.js` deben dar **200**. Un 403 en la raíz y 404 en `config.js` indican que el `DocumentRoot` del vhost no termina en `/public` (apartado 10.2; revisa también el `-le-ssl.conf`).

**Consola del navegador.** Con sesión iniciada no debe haber errores. Sin sesión, la web no consulta la API en el sitio público ni en el acceso (desde la v0.4.0).

Comprobaciones externas: [securityheaders.com](https://securityheaders.com) y [SSL Labs](https://www.ssllabs.com/ssltest/) sobre ambos subdominios; objetivo, nota **A** (criterio 12).

---

## 14. Copias de seguridad

El script `cli/backup.sh` vuelca la base de datos y las subidas en `storage/backups/`, conserva 14 copias y deja la marca que lee `/v1/health` (`storage/backups/ultima_copia.txt`).

Las tareas programadas van en un archivo del sistema, `/etc/cron.d/pymehub`, que no depende del `crontab` de ningún usuario. Debian 13 mínimo no trae `cron`: si `crontab` da *command not found*, instálalo primero.

```bash
sudo apt install -y cron && sudo systemctl enable --now cron

sudo tee /etc/cron.d/pymehub > /dev/null <<'CRON'
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
API=/var/www/hermantorres/eude/pymehub/pymehub_api
# Copia diaria de la base de datos (RNF-064)
0 2 * * *  www-data  bash $API/cli/backup.sh >> $API/storage/logs/backup.log 2>&1
# Retención de datos: check-ins de más de 12 meses (LEG-008) e invitaciones caducadas
0 4 * * *  www-data  php $API/cli/purge_retention.php >> $API/storage/logs/cron.log 2>&1
# Alertas de bienestar de equipo, los lunes con la semana anterior completa (RF-054)
0 6 * * 1  www-data  php $API/cli/wellbeing_alerts.php >> $API/storage/logs/cron.log 2>&1
# Riesgo de rotación de las empresas con plan Completo y alertas de paso a nivel alto (RF-031, RF-033)
0 3 * * *  www-data  php $API/cli/recalc_risk.php >> $API/storage/logs/cron.log 2>&1
# Reinicio de la demo comercial (REQ_QR_DEMO_COMERCIAL, DMO-027): solo empresas es_demo = 1
30 3 * * *  www-data  php $API/cli/demo_seed.php --reset >> $API/storage/logs/demo_seed.log 2>&1
# Revisión de sesgos del motor de riesgo, cada trimestre (LEG-015)
30 4 1 1,4,7,10 *  www-data  php $API/cli/bias_report.php --salida=$API/storage/logs/sesgos_$(date +\%Y\%m).md >> $API/storage/logs/cron.log 2>&1
CRON
sudo chmod 644 /etc/cron.d/pymehub
systemctl is-active cron && cat /etc/cron.d/pymehub
```

Las tareas programadas usan la zona horaria del servidor. Si el VPS está en UTC, `0 6 * * 1` son las 08:00 en verano y las 07:00 en invierno en España; es indiferente para el cálculo, que siempre usa semanas en hora de España.

**Prueba la copia y la restauración antes de la defensa** (`RNF-064`):

```bash
sudo -u www-data bash /var/www/hermantorres/eude/pymehub/pymehub_api/cli/backup.sh   # "Copia completada con mariadb-dump: …"
curl -s https://api.pymehub.hermantorres.com/v1/health                                 # "copia_seguridad":"ok"
sudo mariadb -e "CREATE DATABASE pymehub_restore CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci"
zcat "$(ls -1t /var/www/hermantorres/eude/pymehub/pymehub_api/storage/backups/db-*.sql.gz | head -1)" | sudo mariadb pymehub_restore
sudo mariadb -e "SELECT COUNT(*) FROM pymehub_restore.usuario; DROP DATABASE pymehub_restore;"
```

Copia también `storage/backups/` fuera del servidor periódicamente (por ejemplo, con `rsync` a tu equipo).

---

## 15. Monitorización

Configura un monitor externo gratuito (por ejemplo, UptimeRobot) de tipo *Keyword*:

| Ajuste | Valor |
|---|---|
| URL | `https://api.pymehub.hermantorres.com/v1/health` |
| Palabra clave | `"estado":"ok"` |
| Intervalo | 5 minutos |
| Aviso | tu email |

Un segundo monitor HTTP sobre `https://pymehub.hermantorres.com` vigila la web.

Los errores de la API quedan en `storage/logs/api-AAAA-MM-DD.log` (una línea JSON por evento, con `cid`). Cuando un usuario reporte un error, pídele el identificador que muestra el mensaje y búscalo:

```bash
grep '"cid":"IDENTIFICADOR"' /var/www/hermantorres/eude/pymehub/pymehub_api/storage/logs/api-*.log
```

---

## 16. Actualizar a una nueva versión

Orden obligatorio: **primero la API, después la web** (`RNF-067`).

```bash
# 1. Copia previa
sudo -u www-data bash /var/www/hermantorres/eude/pymehub/pymehub_api/cli/backup.sh

# 2. Mantenimiento (la API responde PH-SYS-003 salvo /v1/health)
sudo -u www-data touch /var/www/hermantorres/eude/pymehub/pymehub_api/storage/maintenance.flag

# 3. Código y migraciones
cd /var/www/hermantorres/eude/pymehub/pymehub_api
sudo -u pymehub git fetch --tags
sudo -u pymehub git checkout vX.Y.Z
sudo -u www-data php cli/migrate.php      # aplica migraciones y sincroniza el catálogo de formación

# 4. Fin del mantenimiento y comprobación
sudo rm /var/www/hermantorres/eude/pymehub/pymehub_api/storage/maintenance.flag
curl -s https://api.pymehub.hermantorres.com/v1/health

# 5. Web
cd /var/www/hermantorres/eude/pymehub/pymehub_web
sudo -u pymehub git fetch --tags && sudo -u pymehub git checkout vX.Y.Z
```

**Si una migración falla:** en MySQL los cambios de estructura se confirman de forma implícita, así que una migración no se deshace sola. Restaura la copia del paso 1, vuelve a la etiqueta anterior y revisa el error antes de reintentar:

```bash
zcat "$(ls -1t /var/www/hermantorres/eude/pymehub/pymehub_api/storage/backups/db-*.sql.gz | head -1)" | sudo mariadb pymehub
cd /var/www/hermantorres/eude/pymehub/pymehub_api && sudo -u pymehub git checkout vVERSION_ANTERIOR
```

---

## 17. Problemas frecuentes

| Síntoma | Causa probable | Solución |
|---|---|---|
| `/install` dice "Se ha detectado MariaDB 10.x … necesita MariaDB 11.4.5 o superior" | Versión del motor no admitida | Apartado 4 |
| `/install` dice "No se pudo conectar … Access denied for user 'pymehub_user'@'127.0.0.1'" | El usuario está creado para `localhost` (o al revés) | Usa el servidor que corresponda (apartado 6, tabla del host) |
| `/install` dice "no tiene la collation utf8mb4_0900_ai_ci ni una equivalente" | Motor sin las collations necesarias | Actualiza a MariaDB 11.8 o MySQL 8.4 |
| `cli/backup.sh` dice "no se encuentra mariadb-dump ni mysqldump" | Falta el cliente del motor | `sudo apt install mariadb-client` |
| `/install` dice "La base de datos no está vacía" | Instalación anterior a medias | Vacía la base (`DROP DATABASE` y `CREATE DATABASE`) y repite |
| Cualquier ruta devuelve 404 de Apache | `mod_rewrite` inactivo o `AllowOverride` no está en `All` | `sudo a2enmod rewrite`, revisa el vhost y recarga |
| La web no mantiene la sesión | Cookie bloqueada | Comprueba HTTPS en ambos dominios, `cookie_secure` a `true` y que la URL del frontend en `config.php` coincide exactamente (sin barra final) |
| Error de CORS en la consola del navegador | El origen de la web no está en `cors.allowed_origins` | Corrige `config/config.php` |
| `PH-SYS-002` en todas las peticiones | Base de datos caída o credenciales cambiadas | `systemctl status mariadb` (o `mysql`) y revisa `config.php` |
| `/v1/health` con `copia_seguridad: sin_datos` o `antigua` | Nunca se ha hecho una copia o el cron no se ejecuta | Ejecuta la copia a mano (apartado 14), revisa `/etc/cron.d/pymehub` y `storage/logs/backup.log` |
| `PH-AUTH-004` al iniciar sesión | 5 intentos fallidos en 15 minutos | Esperar 15 minutos |
| La prueba de humo da 12 correctas y 75 fallidas (o se detiene sin poder iniciar sesión) | Se ha usado el texto de ejemplo en vez de la contraseña real | Repite con la contraseña del instalador; si salta `PH-AUTH-004`, espera 15 minutos |
| `sudo mariadb` da *Access denied for user 'root'@'localhost'* | root tiene contraseña | `mariadb -u root -p` |
| `/install`: «Requisito no cumplido: Extensión intl» | Falta `php8.4-intl` | `sudo apt install php8.4-intl && sudo systemctl reload apache2` |
| `sudo -u www-data …/cli/backup.sh`: *command not found* o *Permission denied* | El script perdió el permiso de ejecución o www-data no puede leerlo | `sudo chown pymehub:www-data cli/backup.sh && sudo chmod 750 cli/backup.sh` y lánzalo con `bash` delante |
| `crontab: command not found` | Debian mínimo sin `cron` | `sudo apt install -y cron` y usa `/etc/cron.d/pymehub` (apartado 14) |
| La web da 403 y `js/config.js` 404 | `DocumentRoot` sin `/public` (en el vhost del 80 o en el `-le-ssl.conf`) | Apartado 10.2 |
| `https://hermantorres.com/eude/pymehub/…/.git/HEAD` da 200 | El bloqueo del apartado 10.5 falta en el vhost HTTPS | Añádelo al `*-le-ssl.conf` de `hermantorres.com` |
| La consola muestra `GET …/v1/auth/me 401` al abrir la web | Versión de la web anterior a la 0.4.0: comprueba la sesión aunque no haya ninguna | Es inofensivo; desaparece al actualizar a la 0.4.0 |
| El catálogo de formación está vacío | No se ha ejecutado la sincronización tras actualizar | `sudo -u www-data php cli/catalog_sync.php` |

---

## 18. Lista de comprobación final

- [ ] Motor de base de datos verificado (apartado 4): MariaDB ≥ 11.4.5 (producción: 11.8) o MySQL ≥ 8.4.
- [ ] Base `pymehub` y usuario `pymehub_user` con permisos solo sobre ella.
- [ ] DNS de ambos subdominios resolviendo a la IP del VPS.
- [ ] Vhosts activos, `configtest` correcto, otros sitios del servidor sin cambios (salvo el bloqueo de `/eude/pymehub` del apartado 10.5).
- [ ] `https://hermantorres.com/eude/pymehub/…` devuelve 403/404 para `config/`, `storage/` y `.git` (apartado 10.5).
- [ ] HTTPS en ambos subdominios, con redirección desde HTTP.
- [ ] Asistente completado, `/install` devuelve 404, `config.php` con permisos 640 y copia fuera del servidor.
- [ ] `/v1/health` en `ok`.
- [ ] Prueba de humo con 171 correctas (`tests/smoke_test.sh`, con la contraseña de la demo).
- [ ] La web responde 200 en `/` y en `js/config.js`, sin errores en la consola.
- [ ] `/etc/cron.d/pymehub` activo, primera copia hecha (`copia_seguridad: ok`) y restauración probada.
- [ ] Catálogo de formación con 15 módulos (`php cli/catalog_sync.php`).
- [ ] Monitor externo configurado.
- [ ] Notas A en securityheaders.com y SSL Labs.
