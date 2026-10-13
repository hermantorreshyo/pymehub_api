# REQ · Ruta `/demo`, acceso de demostración comercial y métricas del QR

| Campo | Valor |
|---|---|
| Proyecto | Pyme Hub · MVP |
| Repositorios afectados | `pymehub_web` (frontend) y `pymehub_api` (backend) |
| Destinatario | Hilo de desarrollo (Claude Code) |
| Prioridad | **Alta** — el folleto comercial se imprime con este QR |
| Fecha límite | **Jueves 15/10/2026** (primer uso: defensa del TFM, lunes 19/10/2026, 13:00) |
| Requisitos relacionados | `RF-100`, `RF-101` (datos de demo, §18), `RF-001`–`RF-008` (autenticación), `RNF-001` (no tocar configuración global del VPS), `RNF-067` (orden de despliegue) |
| Ubicación de este documento | `docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md` en **ambos** repositorios |
| Sustituye a | `REQ_TRIBUNAL_QR_Y_ACCESO_DEMO.md` (descartado: la URL `/tribunal` ya no se usa) |

---

## 1. Contexto y objetivo

Pyme Hub tiene un **folleto comercial** (díptico A4) y una diapositiva de cierre con el mismo **código QR**. Están pensados para cualquier empresa cliente o inversor: el primer público será el tribunal de la defensa, tratado como un inversor que quiere conocer el producto, y después se usarán en reuniones comerciales, ferias y pilotos. El QR apunta a:

```
https://pymehub.hermantorres.com/demo
```

Ese enlace debe:

1. Llevar al **sitio web público (landing)** que precede al login, para que el visitante conozca el producto.
2. Mostrar un **panel "Pruebe Pyme Hub"** con los usuarios de una empresa de demostración, para entrar al MVP desde el móvil en ese momento.
3. **Contar las visitas** que llegan por el QR y los inicios de sesión de demostración, como dato de interés comercial ("cuántos de los que reciben el folleto prueban la plataforma").

El destino del QR **no puede cambiar** una vez impreso: `/demo` es una ruta estable que redirige al destino real. Si mañana cambia la landing (o el dominio pasa a `pymehub.es`), se cambia la redirección, no el folleto.

---

## 2. Alcance

**Incluye:** ruta `/demo`, modo demo en la landing, panel de acceso, usuarios demo con restricciones, semilla reproducible con reinicio nocturno, contador de visitas y de inicios de sesión demo, tarjeta de métricas para el administrador, documentación y pruebas.

**No incluye:** analítica de terceros, cookies no técnicas, envío de correos, formulario de captación de leads con almacenamiento (queda para una fase posterior), cambios en el folleto o la diapositiva (los hacen otros hilos con este mismo QR).

---

## 3. Requerimientos funcionales

### 3.1 Ruta `/demo` (`pymehub_web`)

| ID | Prio | Requerimiento |
|---|---|---|
| `DMO-001` | M | `GET /demo` y `GET /demo/` responden **302** a `/?modo=demo&utm_source=qr&utm_medium=folleto&utm_campaign=folleto_comercial`. Redirección **temporal** (302, no 301) para que ningún navegador la cachee y el destino se pueda cambiar sin reimprimir. |
| `DMO-002` | M | La regla vive en `public/.htaccess` del proyecto (`mod_rewrite`). **No** se toca el vhost ni la configuración global de Apache (`RNF-001`). |
| `DMO-003` | M | HTTPS obligatorio: si llega por HTTP, se fuerza HTTPS y después se aplica `DMO-001` (máximo 2 saltos). |
| `DMO-004` | M | La ruta funciona aunque el visitante ya tenga sesión: se muestra la landing en modo demo con un botón "Ir a mi panel". |

### 3.2 Modo demo en la landing (`pymehub_web`)

| ID | Prio | Requerimiento |
|---|---|---|
| `DMO-010` | M | Con `modo=demo`, la landing muestra arriba una **franja de bienvenida comercial**: "Pruebe Pyme Hub con una empresa de ejemplo: 48 empleados, 4 equipos y datos ficticios." Misma línea gráfica y animaciones de la landing "Muy pronto" (tokens de `tokens.css`, sin fotos de stock). Ninguna mención a la defensa ni al TFM. |
| `DMO-011` | M | Debajo, el panel **"Pruebe Pyme Hub"** con tres tarjetas (§4.2): Gerente, RR. HH. y Conductor. Cada tarjeta muestra perfil, empresa ficticia, correo, contraseña (con botón "Copiar"), 2–3 cosas que ver y un botón **"Entrar como …"**. Una cuarta tarjeta secundaria, "Ver el plan Entrada", usa `gerente2@`. |
| `DMO-012` | M | "Entrar como …" abre `#/login` con el **correo precargado** (`#/login?email=...`). La contraseña **nunca** viaja en la URL. |
| `DMO-013` | M | El panel indica "Datos 100 % ficticios. La demostración se restaura cada noche." Si la demo está desactivada (`DMO-034`), sustituye las tarjetas por "La demostración no está disponible en este momento". |
| `DMO-014` | M | **Mobile first**: la mayoría llegará escaneando. Revisar a 390 × 844 (prioritario), 768 × 1024 y 1440 × 900. Panel visible en < 3 s con 4G simulada (`RNF-051`). |
| `DMO-015` | S | El modo demo no se muestra a quien entra por la raíz sin el parámetro. Tras leerlo, se limpia la URL con `history.replaceState`. |
| `DMO-016` | S | Accesibilidad: contraste AA, foco visible, botones "Copiar" con `aria-label`, aviso "Copiado" con `aria-live="polite"`. |
| `DMO-017` | S | Al final del panel, botón **"Solicitar un piloto gratuito"** que lleva a la sección de contacto de la landing si existe, o a un `mailto:` con el correo configurado en `config.js` (`contactEmail`). Sin formulario que guarde datos personales en esta fase. |
| `DMO-018` | M | Los textos comerciales de la landing en modo demo son coherentes con el folleto: piloto gratuito de 30 días, planes desde 15 €/empleado/mes, plan mensual sin permanencia, soporte en menos de 4 h laborables, disponibilidad del 99,5 %, datos exportables (TFM: 7.2.6, 8.8 y tabla 25). |

### 3.3 Usuarios y datos de demostración (`pymehub_api`)

| ID | Prio | Requerimiento |
|---|---|---|
| `DMO-020` | M | Semilla de `RF-100` como comando **idempotente**: `php cli/demo_seed.php --reset`. Borra y recrea **solo** los interlocutores marcados como demo y sus datos; nunca toca otros. |
| `DMO-021` | M | Fechas **relativas a la ejecución** (antigüedades, 6 meses de incidencias, 60 días de check-ins), para que cualquier día la demo muestre 5–8 empleados en riesgo alto con factores, alerta de bienestar en "Nocturno" y demanda formativa. |
| `DMO-022` | M | Migración nueva (no editar la 0001): `interlocutor.es_demo` y `usuario.es_demo`, `TINYINT(1) NOT NULL DEFAULT 0`. Todo lo de la semilla lleva `es_demo = 1`. |
| `DMO-023` | M | Usuarios de §4.1. Contraseña con Argon2id como el resto; en claro solo en `config.php` (no versionado), clave `demo.password`, y en este documento. |
| `DMO-024` | M | Un usuario `es_demo = 1` **no** puede cambiar contraseña ni correo, crear/desactivar/borrar usuarios, enviar invitaciones, ni exportar o suprimir datos de la empresa. Sí puede navegar, hacer check-in, inscribirse y completar formación y usar el asesor (se pierde en el reinicio). Intento bloqueado: `403 PH-DEMO-001` "Acción no disponible en la demostración". |
| `DMO-025` | M | **Bloqueo tras intentos fallidos por IP**, no por cuenta, para usuarios demo. Con las credenciales impresas, cualquiera podría bloquear la cuenta del gerente en mitad de una presentación tecleando mal cinco veces. |
| `DMO-026` | M | Sesiones **concurrentes**: varias personas pueden entrar a la vez con el mismo usuario demo sin cerrarse la sesión entre sí. |
| `DMO-027` | M | Reinicio automático cada noche a las **03:30 (Europe/Madrid)** con `crontab` del usuario del proyecto. Además, se ejecuta a mano antes de cada presentación importante (§7.3). |
| `DMO-028` | S | `admin@pymehub.demo` existe para las demostraciones que hace el equipo, pero **no** se imprime ni aparece en el panel público. |

### 3.4 Contador de visitas y de inicios de sesión demo

| ID | Prio | Requerimiento |
|---|---|---|
| `DMO-030` | M | Endpoint público `POST /v1/public/visits`, cuerpo `{ "campaign": "folleto_comercial", "source": "qr", "medium": "folleto" }`. La landing lo llama una vez al cargar en modo demo. Sin CSRF (no hay sesión), lista blanca de valores y `RateLimit` de 30 peticiones/minuto por IP. Respuesta `204`. Las campañas admitidas se definen en `config.php` (`campaigns`), para poder añadir otras (ferias, LinkedIn) sin desplegar código. |
| `DMO-031` | M | Tabla `visita_campania` (`id`, `campania`, `origen`, `medio`, `dispositivo` ENUM('MOVIL','ESCRITORIO','OTRO') derivado del User-Agent en el servidor, `creado_en` UTC). **No** se guarda IP, User-Agent completo ni identificador del dispositivo. |
| `DMO-032` | M | Sin cookies ni almacenamiento local para contar. Se cuentan **visitas**, no visitantes únicos: no hace falta consentimiento (LSSI art. 22.2) ni hay datos personales (RGPD). |
| `DMO-033` | M | Inicios de sesión correctos de usuarios demo en el registro de auditoría con la acción `LOGIN_DEMO` y el perfil (sin IP). |
| `DMO-034` | M | Interruptor `demo.habilitada` (por defecto `true`) y fecha opcional `demo.habilitada_hasta` (por defecto `null`, sin caducidad) en `config.php`. Con la demo desactivada, el login demo responde `403 PH-DEMO-002` "La demostración no está disponible" y la landing muestra el aviso de `DMO-013`. |
| `DMO-035` | M | `GET /v1/admin/campaign-metrics?campaign=folleto_comercial&from=&to=` (solo `ADMIN`): visitas totales y por dispositivo, visitas por día y por hora, inicios de sesión demo por perfil, primera y última visita. |
| `DMO-036` | M | Tarjeta **"QR del folleto"** en el panel del administrador con esas cifras, selector de rango de fechas ("hoy", "7 días", "desde el inicio") y botón "Actualizar". Sirve para enseñar en directo cuánta gente ha abierto la plataforma tras una presentación. |
| `DMO-037` | S | `GET /v1/public/demo-status` (público): `{ "enabled": true }`, para `DMO-013`. Sin credenciales. |

---

## 4. Datos fijos (deben coincidir con el folleto impreso)

> ⚠️ El folleto se imprime con estos valores. **No cambiarlos** sin avisar: obligaría a reimprimir.

### 4.1 Usuarios de demostración

| Perfil | Empresa ficticia | Correo | Contraseña | ¿Impreso? |
|---|---|---|---|---|
| Gerente | Reparto Rápido Castilla S.L. (plan Completo) | `gerente@pymehub.demo` | `PymeHub-Demo` | Sí |
| RR. HH. | Reparto Rápido Castilla S.L. | `rrhh@pymehub.demo` | `PymeHub-Demo` | Sí |
| Conductor | Reparto Rápido Castilla S.L. | `conductor@pymehub.demo` | `PymeHub-Demo` | Sí |
| Gerente | Mensajería Tajo S.L. (plan Entrada) | `gerente2@pymehub.demo` | `PymeHub-Demo` | No (solo en el panel web) |
| Administrador | — | `admin@pymehub.demo` | distinta, en `config.php` | No |

- El dominio `.demo` no existe: deja claro que son cuentas ficticias y no puede recibir correo. Comprobar que el validador del login lo acepta; si no, ajustarlo solo para `es_demo`.
- El folleto dice "48 empleados y 4 equipos": la semilla de Castilla debe tener exactamente eso (`RF-100`).

### 4.2 Contenido de las tarjetas del panel

| Tarjeta | Qué ver |
|---|---|
| Gerente | Indicadores de la plantilla · empleados en riesgo alto con su explicación · alerta de bienestar del equipo Nocturno |
| RR. HH. | Ranking de riesgo con factores y fuentes · comparación con la rotación de partida · demanda formativa |
| Conductor | Check-in diario desde el móvil · micro-formación con cuestionario · asesor de formación con IA |
| Plan Entrada (secundaria) | Bienestar y formación completos; la sección de riesgo aparece como vista informativa del plan Completo |

---

## 5. Requerimientos no funcionales y de seguridad

| ID | Requerimiento |
|---|---|
| `DMO-NF1` | No se debilita el aislamiento por empresa (`RNF-023b`): los usuarios demo solo ven su interlocutor. |
| `DMO-NF2` | Los endpoints públicos no exponen datos internos ni credenciales; errores con el formato `PH-*` (`RNF-014`). |
| `DMO-NF3` | `docs/openapi.yaml` actualizado con los tres endpoints y los códigos `PH-DEMO-001` y `PH-DEMO-002`. |
| `DMO-NF4` | Para los usuarios demo, el asesor de formación funciona en `cache_first`: responde en < 300 ms y no consume cuota de Mistral cuando varias personas lo prueban a la vez (`RNF-056`). |
| `DMO-NF5` | La CSP de la landing no añade orígenes nuevos. |
| `DMO-NF6` | Como la contraseña es pública y no caduca, el reinicio nocturno y las restricciones de `DMO-024` son obligatorios en producción. Si se detecta abuso, se desactiva con `demo.habilitada = false` sin desplegar. |

---

## 6. Criterios de aceptación

1. Escanear el QR impreso con un iPhone y con un Android abre `https://pymehub.hermantorres.com/demo`, redirige (302) a la landing y muestra la franja y el panel en < 3 s con 4G.
2. `curl -sI https://pymehub.hermantorres.com/demo` devuelve `302` con `modo=demo` en `Location`; por HTTP llega al mismo destino en ≤ 2 saltos.
3. Entrar por la raíz (`/`) no muestra el panel.
4. "Entrar como gerente" abre el login con el correo precargado; con `PymeHub-Demo` se entra y se ven 5–8 empleados en riesgo alto, la alerta de "Nocturno" y la demanda formativa.
5. Lo mismo para `rrhh@`, `conductor@` y `gerente2@` según §4.2.
6. Tres navegadores entran a la vez como `gerente@` sin echarse entre sí.
7. Cinco contraseñas erróneas para `gerente@` desde una IP no bloquean la cuenta desde otra IP.
8. Con `gerente@`, crear un usuario de RR. HH. o cambiar la contraseña devuelve `PH-DEMO-001` y la interfaz lo explica.
9. Tras `php cli/demo_seed.php --reset`, los cambios desaparecen y los datos vuelven a la semilla con fechas relativas a hoy.
10. Cada carga en modo demo suma 1 visita; la tarjeta "QR del folleto" la muestra al pulsar "Actualizar". No se crea ninguna cookie ni entrada de almacenamiento local nueva.
11. Con `demo.habilitada = false`, el login demo devuelve `PH-DEMO-002` y la landing muestra el aviso.
12. `tests/smoke_test.sh` ampliado con: redirección `/demo`, `POST /v1/public/visits`, login demo, `PH-DEMO-001`, bloqueo por IP y `demo-status`. Todo en verde.

---

## 7. Despliegue

Orden obligatorio: **primero la API, después el frontend** (`RNF-067`). Rutas del VPS: `/var/www/hermantorres/eude/pymehub/`.

### 7.1 API

```bash
cd /var/www/hermantorres/eude/pymehub/pymehub_api
git pull
php cli/migrate.php                       # es_demo y visita_campania
# Añadir en config/config.php (no versionado):
#   'demo' => ['habilitada' => true, 'habilitada_hasta' => null, 'password' => 'PymeHub-Demo'],
#   'campaigns' => ['folleto_comercial'],
php cli/demo_seed.php --reset
( crontab -l 2>/dev/null; echo 'CRON_TZ=Europe/Madrid'; echo '30 3 * * * cd /var/www/hermantorres/eude/pymehub/pymehub_api && php cli/demo_seed.php --reset >> storage/logs/demo_seed.log 2>&1' ) | crontab -
bash tests/smoke_test.sh
```

### 7.2 Frontend

```bash
cd /var/www/hermantorres/eude/pymehub/pymehub_web
git pull
# public/js/config.js (no versionado): añadir contactEmail
curl -sI https://pymehub.hermantorres.com/demo | grep -iE '^(HTTP|location)'
```

### 7.3 Antes de cada presentación (la primera: lunes 19/10, antes de las 11:00)

```bash
cd /var/www/hermantorres/eude/pymehub/pymehub_api
php cli/demo_seed.php --reset
bash tests/smoke_test.sh
```

Y escanear el QR del folleto impreso con dos móviles distintos. Para enseñar después las visitas de ese día, usar el rango "hoy" de la tarjeta "QR del folleto".

---

## 8. Mensajes de commit sugeridos

**pymehub_api**

```
feat: demo comercial y métricas del QR del folleto (v0.x.0)

Base de datos
- Migración: interlocutor.es_demo, usuario.es_demo y tabla visita_campania (DMO-022, DMO-031)

Servicios y CLI
- cli/demo_seed.php --reset: semilla RF-100 idempotente con fechas relativas (DMO-020, DMO-021)

Seguridad
- Restricciones de usuarios demo con PH-DEMO-001 (DMO-024)
- Bloqueo por IP y sesiones concurrentes para usuarios demo (DMO-025, DMO-026)
- Interruptor de la demo con PH-DEMO-002 (DMO-034)

Endpoints
- POST /v1/public/visits y GET /v1/public/demo-status (DMO-030, DMO-037)
- GET /v1/admin/campaign-metrics (DMO-035)

Documentación y pruebas
- openapi.yaml, CHANGELOG, docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md
- smoke_test ampliado (criterio 12)
```

**pymehub_web**

```
feat: ruta /demo y panel "Pruebe Pyme Hub" en la landing (v0.x.0)

Estructura
- public/.htaccess: /demo → 302 a la landing en modo demo (DMO-001 a DMO-003)

Vistas
- Landing: franja comercial y panel de acceso de demostración (DMO-010 a DMO-018)
- Login: precarga del correo desde #/login?email= (DMO-012)
- Administración: tarjeta "QR del folleto" con rango de fechas (DMO-036)

Accesibilidad
- Botones Copiar con aria-label y aviso aria-live (DMO-016)

Documentación
- CHANGELOG, docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md
```

---

## 9. Decisiones para Herman

| ID | Decisión | Propuesta por defecto |
|---|---|---|
| `D-1` | Contraseña impresa y sin caducidad | **Sí**, con restricciones, bloqueo por IP, reinicio nocturno e interruptor (`DMO-024`, `DMO-025`, `DMO-027`, `DMO-034`). |
| `D-2` | El TFM (Anexo B.7) dice "tres usuarios: administrador, gerente y conductor"; aquí se publican gerente, RR. HH. y conductor y el administrador queda para las demos del equipo. | Mantener y ajustar la frase del Anexo B.7. |
| `D-3` | Correo de contacto para "Solicitar un piloto gratuito" (`DMO-017`) | Definirlo antes del despliegue; el folleto no lo imprime. |


---

## 10. Notas de implementación (v0.7.0, 13/10/2026)

Decisiones tomadas con Herman al implementarlo:

| Tema | Implementación |
|---|---|
| Arquitectura | Una sola instalación: misma API, misma web y misma base de datos de producción. `/demo` solo es la puerta de entrada a la landing en modo demo; el login es el de siempre. La demo se separa de la operativa con `es_demo = 1` (empresas y personas) y el aislamiento por empresa |
| Login | La ruta real es `#/acceso`; las tarjetas usan `#/acceso?email=…` y `#/login` queda como alias |
| `DMO-024` | Bloqueado con `PH-DEMO-001`: cuentas de gestión, invitaciones, cambio o alta de correos, importación CSV, bajas laborales y, cuando existan, exportaciones y supresiones. Permitido: altas sin correo, incidencias, formación, alertas, recálculo del riesgo, línea base, check-in y asesor |
| `DMO-025` | Cuentas demo: 20 fallos en 15 minutos por IP (la sala de la defensa comparte wifi). Cuentas reales: 5 por cuenta e IP, como antes |
| `DMO-027` | Sin usuario del proyecto en el VPS: el reinicio va en `/etc/cron.d/pymehub` con `www-data` a las 03:30 (el servidor está en hora de Madrid) |
| `DMO-028` | `admin@pymehub.demo` no es demo: es de la plataforma, solo para pruebas internas, con su propia contraseña (`demo.password_admin`); el reinicio no lo borra |
| `DMO-017` | `contactEmail: 'info@hyo.company'` en `public/js/config.js` |
| `DMO-031` | `dispositivo` es `VARCHAR(10)` con `CHECK` (convención del proyecto) en lugar de `ENUM` |
| `DMO-033` | Los `LOGIN_DEMO` se conservan al reiniciar la demo (sin referencias a personas). La prueba de humo usa la cabecera `X-PymeHub-Prueba` y la campaña `prueba_interna` para no sumar en las métricas comerciales |
