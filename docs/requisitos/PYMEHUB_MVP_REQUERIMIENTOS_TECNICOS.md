# Pyme Hub — Requerimientos Técnicos del MVP

**Proyecto:** Pyme Hub — TFM EUDE Business School (The Best Team)
**Documento:** Especificación de requerimientos técnicos del Producto Mínimo Viable (MVP)
**Versión:** 0.9.9 · 18/10/2026
**Stack:** LAMP nativo, sin Docker (Linux · Apache · **MariaDB 11.8 LTS o MySQL 8.4 LTS** · PHP 8.4). Producción: MariaDB 11.8
**Repositorios:** dos, independientes: `pymehub_api` (backend tipo API) y `pymehub_web` (frontend que consume la API)
**Estado:** Alineado con la versión 4 del documento del TFM (carpeta *Revisión 004*). Los cambios que este documento obliga a trasladar al TFM se listan en §22.3.

### Control de cambios

| Versión | Fecha | Cambios |
|---|---|---|
| 0.1 | 27/09/2026 | Primera versión |
| 0.2 | 27/09/2026 | Cerrados P-01 a P-06: subdominios confirmados; sesión de 7 días; motor de riesgo recalibrado con fuentes documentadas (§9); plan de entrada = Bienestar + Upskilling (§2, §6); módulo de IA generativa con proveedor gratuito (§12) |
| 0.3 | 27/09/2026 | P-07 resuelto (titular de la cuenta de Mistral); P-08 aplazado; P-09 aclarado |
| 0.4 | 27/09/2026 | P-09 resuelto: la IA se acota al **Asesor de formación** para el empleado (§12). Catálogo de 15 módulos con metadatos y autoinscripción |
| 0.5 | 03/10/2026 | Alineación con las versiones 3 y 4 del TFM: cinco módulos (tres en el MVP, dos en fase 2) y modelo SaaS sin servicios de selección (§1); cuarto rol **RR. HH.** (§6.1); ciclo de cobro mensual o anual y línea base de impacto (§7, §9); análisis del AI Act con revisión de sesgos y contrato de encargado del tratamiento (§15); nivel de servicio, monitorización, mantenimiento y baja de la empresa cliente según el apartado 9.8 del TFM (§17.4); demo y criterios de aceptación con las pantallas del Anexo D (§18, §20); plan de construcción separado del cronograma del proyecto (Anexo F) (§21); tabla de alineación con el TFM (§22.3) |
| 0.6 | 03/10/2026 | **Base de datos MySQL 8.4 LTS** en lugar de MariaDB (`DEC-05`, §3.5); **backend API y frontend en repositorios separados** con contrato OpenAPI versionado (`DEC-03`, `DEC-13`, §4); frontend sin código de servidor que solo consume la API (`RNF-007`); §13 reescrito con el Manual de Imagen Corporativa v1.0, el Manual de Maquetación HTML/CSS v1.0 y los mockups aprobados, incluidas las diferencias entre los mockups y el alcance del MVP (§13.6) |
| 0.7 | 03/10/2026 | **Modelo de datos interlocutor/usuario** (§7, `DEC-14`): todos los actores en `interlocutor` (atributo `tipo`) y las personas en `usuario` (atributo `perfil`), con un interlocutor que tiene varios usuarios; tablas en español. Repositorios renombrados a `pymehub_api` y `pymehub_web` (GitHub). Aclaración de `RF-096`: en MySQL el DDL no es transaccional |
| 0.8 | 03/10/2026 | Fase 3 (Organización): `RF-020` importación CSV de la plantilla y `RF-021` a `RF-023` (empleados, baja laboral e invitación de empleados) en §8.3; `LEG-016` referido a la columna real `interlocutor.fecha_contrato_encargado` |
| 0.9 | 12/10/2026 | **Compatibilidad con MariaDB 11.8 LTS** (`DEC-05`): el VPS de producción ya usa MariaDB 11.8.6 con otras aplicaciones y no se puede sustituir. La API funciona sobre MySQL 8.4 y MariaDB 11.8 con el SQL común a ambos (`RNF-009`). Instalador con detección de motor y versión mínima por motor (`RF-091`), collation comprobada antes de migrar, conexión por socket, copias con `mariadb-dump` o `mysqldump` (`RNF-064`). `P-11` cerrado |
| 0.9.1 | 12/10/2026 | Rutas reales de producción: `/var/www/hermantorres/eude/pymehub/pymehub_api` y `/var/www/hermantorres/eude/pymehub/pymehub_web` (§3.2, §4.1) |
| 0.9.3 | 12/10/2026 | Fase 6 (Asesor de formación): implementación de §12.5 en §12.7 (objetivos cerrados, perfil no identificativo, caché independiente del avance, orden de los modos, cuotas y PayloadGuard ampliado a datos de salud); `GET /v1/admin/ai-usage` sin contenido |
| 0.9.2 | 12/10/2026 | Fase 5 (Upskilling): §8.5 con el seguimiento (`GET /v1/training/assignments`), la retirada de asignaciones y el resumen (`GET /v1/training/summary`); §10 con el detalle de `RF-041` a `RF-047` (estado `VENCIDA` calculado, idempotencia del cuestionario sin conexión, reglas de `RF-043` sin datos individuales de bienestar) |
| 0.9.4 | 12/10/2026 | Fase 7 (IA de RR. HH.): §8.4 con `GET /v1/risk/bias` y `GET/PUT /v1/baseline`; §9.6 con la implementación (puntuación diaria, alerta solo al pasar a alto, recálculo limitado, retrospectiva, línea base homogénea en ausencias injustificadas, cron de sesgos) |
| 0.9.5 | 13/10/2026 | Demo comercial y QR del folleto (`docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md`, `DMO-*`): §18.1 con la ruta `/demo`, usuarios demo con restricciones, reinicio nocturno y métricas; `PH-DEMO-001` y `PH-DEMO-002` en §16 |
| 0.9.6 | 17/10/2026 | QR de instalación de la app: ruta estable `/app` → página `#/instalar` (§13.7, §18.1) |
| 0.9.7 | 17/10/2026 | Fases 9 y 9a (v0.8.0): implementación de `RF-110`, `RF-111`, `RNF-062`, `RNF-063`, `RNF-064` y `LEG-007`/`LEG-017` (§17.5); nuevos `LEG-018` a `LEG-022` (textos legales, titular parametrizado, IVA y precios, contrato de encargo, versión aceptada) y `RF-112`/`RF-113` (parámetros de la plataforma, derecho de acceso del propio usuario) en §15 y §17.4 |
| 0.9.8 | 17/10/2026 | Fases 9b y 9c (v0.9.0): vigencia de la suscripción (`RF-114` a `RF-116`) y pagos con Stripe en modo prueba (`RF-117` a `RF-120`); `PH-PLAN-002` y `PH-PAY-001` a `PH-PAY-005` en §16; tarea `cli/subscription_status.php` en §17.2; implementación en §17.6 |
| 0.9.9 | 18/10/2026 | Fase 9d (v0.10.0): marketplace Pyme Hub Skills en modo prueba (`RF-121` a `RF-128`), `PH-SKL-002` y `PH-SKL-003` en §16, implementación y alcance diferido en §17.7 |

---

## 0. Cómo leer este documento

| Prefijo | Significado |
|---|---|
| `RF-xxx` | Requerimiento funcional |
| `RNF-xxx` | Requerimiento no funcional |
| `SEC-xxx` | Seguridad |
| `LEG-xxx` | Cumplimiento normativo (RGPD / LOPDGDD / AI Act) |
| `IA-xxx` | Requerimiento del módulo de IA generativa |
| `DEC-xxx` | Decisión de arquitectura tomada |
| `P-xxx` | Punto pendiente de confirmar |

Prioridad: **M** (imprescindible en el MVP) · **S** (deseable) · **C** (fase posterior).

---

## 1. Propósito y alcance del MVP

### 1.1 Objetivo

Demostrar ante el tribunal, con un producto funcionando en producción, que la propuesta de valor de Pyme Hub es técnicamente viable para una PYME de **logística de última milla** (10–250 empleados, alta rotación de conductores y repartidores): un gerente o el responsable de RR. HH. ve el riesgo de rotación de su plantilla con explicación y fuentes, asigna micro-formación y conoce el clima de bienestar de sus equipos de forma anónima; un conductor hace su check-in diario, pide al asesor de IA qué formación le conviene según sus intereses y su perfil, y la completa desde el móvil, incluso con conexión intermitente.

### 1.2 Módulos de Pyme Hub y alcance del MVP

Pyme Hub opera como **SaaS puro**: la PYME usa la plataforma para cuidar, formar y retener a su plantilla. **No presta servicios de reclutamiento ni de selección de personal.** El TFM define cinco módulos; el MVP construye los tres primeros:

| # | Módulo (TFM, apartado 6.2) | Qué incluye | Estado | Plan |
|---|---|---|---|---|
| 1 | Bienestar | Check-in diario, historial propio, clima agregado y anónimo por equipo, alertas de equipo | **MVP** | Entrada y Completo |
| 2 | Formación adaptativa (upskilling) | Catálogo de micro-módulos, asignación, progreso, cuestionario, autoinscripción y **Asesor de formación con IA** (§12), única funcionalidad con IA generativa | **MVP** | Entrada y Completo |
| 3 | IA de RR. HH. | Score de riesgo de rotación explicable, alertas, panel de indicadores y línea base de impacto | **MVP** | Solo Completo |
| 4 | Gestión de plantilla y búsqueda de talento | Fichas, equipos, historial formativo y certificaciones; publicación de vacantes en la comunidad y búsqueda de perfiles que han dado su consentimiento | **Fase 2** (el MVP solo tiene la base: empleados, equipos e historial formativo) | Completo |
| 5 | Pyme Hub Skills | Marketplace en el que formadores externos venden cursos a la comunidad; comisión del 20 % para la plataforma | **Fase 2**, desde el mes 13 del cronograma | Transversal |

Además, el MVP incluye el **núcleo** (multiempresa, usuarios, cuatro roles, importación CSV e instalador) y el **panel de administración de la plataforma** (empresas, plan contratado, ciclo de cobro y empleados activos).

### 1.3 Fuera del alcance del MVP

- Módulo 4 completo: vacantes, perfiles públicos de la comunidad y consentimiento para ser contactado.
- Módulo 5, Pyme Hub Skills: alta y verificación de formadores, venta de cursos, comisión y liquidación.
- Cualquier servicio de reclutamiento o selección (fuera del modelo de negocio, no solo del MVP).
- Pasarela de pago y factura electrónica: el plan y el ciclo de cobro se registran manualmente.
- Modelos de machine learning entrenados: el MVP usa reglas ponderadas con fuentes y no habrá ML hasta disponer de datos reales de bajas (§9.5).
- Correo transaccional: invitaciones por enlace de un solo uso.
- Integraciones con nóminas o gestión de rutas y aplicaciones nativas.
- Trabajo de la **versión de producción** posterior al TFM (mes 4 del cronograma, Anexo F del TFM): evaluación de impacto (DPIA) completa, endurecimiento adicional y pilotos con 4 PYMEs.

---

## 2. Decisiones de arquitectura

| ID | Decisión | Motivo |
|---|---|---|
| `DEC-01` | LAMP nativo, sin Docker, en el VPS de Hostinger (Debian 13) | Requisito del proyecto |
| `DEC-02` | PHP 8.4 puro, sin frameworks ni Composer | Cero dependencias, despliegue por copia, sin build. **Sustituye** a Laravel 11 del `PROMPT_DESARROLLO_PYME_HUB.md` |
| `DEC-03` | **Backend tipo API y frontend en dos repositorios Git separados**: `pymehub_api` (API REST, solo JSON) y `pymehub_web` (PWA). Se versionan, prueban y despliegan de forma independiente | El frontend consume exclusivamente la API: nunca accede a la base de datos ni contiene lógica de negocio |
| `DEC-04` | Frontend en HTML + CSS + JavaScript nativo (ES Modules), PWA | Sin pipeline de build. **Sustituye** a React/Vue + TypeScript |
| `DEC-05` | **MariaDB 11.8 LTS o MySQL 8.4 LTS**, InnoDB, `utf8mb4` con `utf8mb4_0900_ai_ci`. El SQL usa solo el subconjunto común de ambos motores (`RNF-009`). **Producción: MariaDB 11.8** (v0.9; sustituye a la decisión de la v0.6) | El VPS de producción ya tiene MariaDB 11.8.6, que usan otras aplicaciones; su propietario no autoriza a sustituirlo, a instalar MySQL en paralelo ni a cambiar su configuración global. Mantener la compatibilidad con MySQL 8.4 evita depender de un solo motor (§3.5) |
| `DEC-06` | Sesión en cookie `HttpOnly` + token CSRF | Más segura que un token en `localStorage`; funciona entre subdominios del mismo dominio |
| `DEC-07` | Motor de riesgo por reglas ponderadas, con fuente y nivel de evidencia por factor | Explicabilidad (AI Act) y ausencia de datos históricos para ML |
| `DEC-08` | Chart.js y Google Fonts por CDN como únicas dependencias del frontend | Estándar habitual |
| `DEC-09` | IA generativa **en una única funcionalidad** (Asesor de formación), vía **Mistral AI (plan gratuito "Experiment")**, detrás de un adaptador intercambiable, sin datos identificativos y con respaldo de respuestas pregeneradas | Acotar alcance y riesgo; ver §12 |
| `DEC-10` | Dos planes: **Entrada** (Bienestar + Upskilling) y **Completo** (+ IA de RR. HH. y, en fase 2, gestión de plantilla y talento), controlados en servidor | Modelo de negocio del TFM: entrada económica con upselling |
| `DEC-11` | **Cuatro roles**: administrador de la plataforma, gerente, RR. HH. y empleado | Coherencia con los apartados 9.2 y 9.7 del TFM: Customer Success forma al gerente y al responsable de RR. HH., y las alertas de rotación van a RR. HH. |
| `DEC-13` | **El contrato entre repositorios es la especificación OpenAPI** (`pymehub-api/docs/openapi.yaml`), versionada con SemVer. Un cambio incompatible en la API exige nueva versión de ruta (`/v2`) o una versión mayor del contrato coordinada con el frontend | Permite trabajar ambos repositorios en paralelo sin romperse |
| `DEC-14` | **Todos los actores del sistema en la tabla `interlocutor`**, diferenciados por `tipo` (`PLATAFORMA`, `EMPRESA`, `FORMADOR`). **Las personas en `usuario`**, diferenciadas por `perfil` (`ADMIN`, `GERENTE`, `RRHH`, `EMPLEADO`, `FORMADOR`). Un interlocutor tiene varios usuarios. La coherencia tipo-perfil la garantiza la base de datos con claves foráneas compuestas y catálogos, sin triggers | Requisito del proyecto; permite que una empresa tenga varios empleados con usuarios diferenciados por perfil y añadir actores en la fase 2 sin rediseñar |
| `DEC-12` | El MVP **no implementa** los módulos 4 y 5, pero su modelo de datos no debe impedirlos: los módulos formativos llevan un campo de origen (`pymehub` / `empresa`, con `formador` reservado) y las fichas de empleado guardan el historial formativo | Evitar una migración disruptiva en la fase 2 |

> **Alineación con el TFM:** `DEC-02`, `DEC-04` y `DEC-09` ya están reflejadas en los apartados 9.3 y 9.7 y en el Anexo D de la versión 4 del TFM. Los repositorios separados (`DEC-03`) todavía no. Con la v0.9, el TFM puede mantener MariaDB como motor (`DEC-05`); ver §22.3.

---

## 3. Entorno de despliegue

### 3.1 Servidor

| Elemento | Valor |
|---|---|
| Proveedor | VPS Hostinger |
| SO | Debian 13 |
| Web | Apache 2.4 con `mod_rewrite`, `mod_headers`, `mod_ssl` |
| PHP | 8.4 |
| BD | MariaDB 11.8 LTS ya instalado en el servidor (compartido con otras aplicaciones), base y usuario exclusivos (ver §3.5) |
| SSL | Let's Encrypt vía Certbot |
| DNS | GoDaddy |

### 3.2 Dominios (confirmado)

| Uso | Subdominio | DocumentRoot | DNS en GoDaddy |
|---|---|---|---|
| Frontend (PWA) | `pymehub.hermantorres.com` | `/var/www/hermantorres/eude/pymehub/pymehub_web/public` | Registro A → IP del VPS |
| API | `api.pymehub.hermantorres.com` | `/var/www/hermantorres/eude/pymehub/pymehub_api/public` | Registro A → IP del VPS |

Ambos comparten el dominio registrable `hermantorres.com`, por lo que la cookie `SameSite=Strict` viaja en las peticiones `fetch` con `credentials: 'include'`.

### 3.3 Convivencia con otros sitios del VPS

- `RNF-001` (M) Solo se crean los vhosts propios (`pymehub-web.conf`, `pymehub-api.conf` y sus `-le-ssl.conf`); no se toca otro vhost ni la configuración global.
- `RNF-002` (M) Base de datos y usuario propios (`pymehub` / `pymehub_user`) con privilegios solo sobre esa base. Se usa el método de autenticación predeterminado del motor (`mysql_native_password` o `ed25519` en MariaDB, `caching_sha2_password` en MySQL 8.4); `pdo_mysql` admite todos ellos.
- `RNF-003` (M) Ajustes de PHP por `php_admin_value` en el vhost, no en `php.ini` global.

### 3.4 Extensiones PHP requeridas

`pdo_mysql`, `mbstring`, `json`, `openssl`, `sodium`, `fileinfo`, `intl`, `curl` (esta última, necesaria para el proveedor de IA).

### 3.5 Motor de base de datos: MariaDB 11.8 LTS o MySQL 8.4 LTS

- `RNF-008` (M) Motores admitidos: **MariaDB 11.4.5 o superior** (producción: MariaDB 11.8 LTS, el paquete de Debian 13) y **MySQL Community Server 8.4 LTS o superior**. En producción se usa el MariaDB que ya existe en el VPS: no se sustituye, no se instala otro motor en paralelo y no se toca su configuración global (`RNF-001`). El instalador detecta el motor y la versión con `SELECT VERSION()` y guarda el motor en `config.php` (`db.engine`).
- `RNF-009` (M) **SQL del subconjunto común de MySQL 8.4 y MariaDB 11.8.**
  - Prohibido: `->`/`->>`, `JSON_TABLE`, `LATERAL`, `ANY_VALUE`, `REGEXP_LIKE`, `UUID_TO_BIN`/`BIN_TO_UUID`, `MEMBER OF`, `VALUES ROW`, `INSERT … AS alias … ON DUPLICATE KEY`, índices funcionales y `DEFAULT (expresión)` salvo en columnas compatibles.
  - Prohibido también un `GROUP BY` que dependa de la dependencia funcional: se agrupa siempre por todas las columnas no agregadas.
  - Las funciones JSON se usan solo si existen en los dos motores. `SHOW …` no se usa con parámetros en sentencias preparadas.
  - Las migraciones publicadas no se editan. Si el servidor no tiene `utf8mb4_0900_ai_ci`, el migrador usa en tiempo de ejecución `utf8mb4_uca1400_ai_ci` (MariaDB).
  - Cada migración se prueba contra MariaDB 11.8 y MySQL 8.4 antes de publicarse.
- `RNF-010b` (M) `sql_mode` estricto (`STRICT_TRANS_TABLES`, `ONLY_FULL_GROUP_BY`, `NO_ZERO_DATE`, `ERROR_FOR_DIVISION_BY_ZERO`) fijado por la propia conexión PDO al abrirse, sin depender de la configuración global del servidor. Todos esos modos existen en los dos motores.

> **`P-11` cerrado (v0.9).** El VPS (`srv1616185`, Debian 13) tiene MariaDB 11.8.6, que comparten otras aplicaciones. Ya existen la base `pymehub` (vacía) y el usuario `pymehub_user`. Pyme Hub se instala sobre ese MariaDB sin cambiar nada global. El procedimiento de producción está en el manual `02_DESPLIEGUE_VPS_PRODUCCION.md`.

---

## 4. Repositorios y estructura

### 4.1 Dos repositorios independientes

| Repositorio | Contenido | Despliegue | Depende de |
|---|---|---|---|
| `pymehub_api` | Backend tipo API: PHP 8.4, migraciones SQL, instalador, tareas programadas, especificación OpenAPI | `/var/www/hermantorres/eude/pymehub/pymehub_api` → `api.pymehub.hermantorres.com` | MariaDB 11.8 o MySQL 8.4 y, opcionalmente, Mistral AI |
| `pymehub_web` | Frontend: PWA en HTML, CSS y JavaScript nativo, sin código de servidor | `/var/www/hermantorres/eude/pymehub/pymehub_web` → `pymehub.hermantorres.com` | **Solo de la API**, a través de HTTPS |

- `RNF-006` (M) Cada repositorio tiene su `README.md` (instalación, configuración, despliegue), su `CHANGELOG.md`, su numeración SemVer y sus propias etiquetas de versión. Ningún repositorio contiene código del otro.
- `RNF-007` (M) **El frontend solo consume la API**: no contiene PHP ni acceso a base de datos, y todo dato que muestra procede de la API. Puede servirse desde cualquier servidor web estático sin cambiar el backend.
- `RNF-005` (M) El frontend no contiene credenciales. La URL de la API vive en `js/config.js`, que **no se versiona** (se versiona `config.example.js`). La clave del proveedor de IA nunca llega al navegador.
- `RNF-004` (M) En el backend, nada fuera de `public/` es accesible por HTTP; carpetas sensibles con `.htaccess` `Require all denied` como segunda barrera.
- `RNF-066` (M) **Nunca se versionan** secretos ni datos: `.gitignore` excluye `config/config.php`, `storage/`, copias de seguridad, `js/config.js` y archivos `.env`.
- `RNF-067` (M) Despliegue por `git pull` de una etiqueta (o `rsync` de la etiqueta) en cada ruta. El orden en producción es **primero la API, después el frontend**; la API mantiene compatibilidad con la versión anterior del frontend durante el despliegue.
- `RNF-068` (S) Entorno local: API y frontend en `localhost` con puertos distintos (mismo sitio para la cookie). La lista blanca de CORS se define por entorno en `config.php`.

### 4.2 Estructura de `pymehub_api`

```
pymehub_api/
├── public/                   ← única carpeta expuesta por Apache
│   ├── index.php             ← front controller
│   └── .htaccess
├── src/
│   ├── Core/                 ← Router, Request, Response, Db, Session, Csrf,
│   │                            Validator, RateLimiter, Logger, Crypto, Http
│   ├── Middleware/           ← Cors, Auth, Tenant, Role, PlanGate, Csrf, RateLimit
│   ├── Controllers/V1/
│   ├── Services/             ← RotationRiskService, TrainingService,
│   │                            WellbeingService, ImportService, AuditService
│   ├── Ai/                   ← AiGateway, providers/, PayloadGuard, prompts/
│   └── Repositories/         ← todo el SQL (PDO preparado)
├── config/
│   ├── config.example.php    ← versionado
│   ├── config.php            ← generado por el instalador, NO versionado
│   └── risk_rules.php
├── migrations/               ← 0001_init.sql … (MariaDB 11.8 y MySQL 8.4)
├── install/
├── cli/
├── storage/                  ← logs/, uploads/, ai_cache/ (NO versionado)
├── docs/
│   ├── openapi.yaml          ← contrato con el frontend (DEC-13)
│   └── hoja_tecnica_modelo_riesgo.md
├── tests/
├── README.md
└── CHANGELOG.md
```

### 4.3 Estructura de `pymehub_web`

Adaptación a JavaScript nativo de la estructura recomendada por el Manual de Maquetación (§13.1): cada componente es un módulo ES que recibe datos y devuelve nodos del DOM.

```
pymehub_web/
├── public/                   ← DocumentRoot
│   ├── index.html
│   ├── manifest.webmanifest
│   ├── sw.js
│   ├── assets/
│   │   ├── brand/            ← logotipo, símbolo, favicon, iconos de app
│   │   ├── icons/            ← sprite SVG de iconos (una sola familia)
│   │   └── img/              ← imágenes locales optimizadas
│   ├── styles/
│   │   ├── tokens.css        ← tokens de diseño (§13.2)
│   │   ├── globals.css
│   │   └── responsive.css
│   └── js/
│       ├── config.example.js ← versionado; config.js NO versionado
│       ├── app.js            ← arranque y enrutado por hash
│       ├── services/api.js   ← único punto de acceso a la API
│       ├── lib/              ← store, cola offline (IndexedDB), formato, escape
│       ├── components/
│       │   ├── layout/       ← AppShell, Sidebar, Topbar, MobileNavigation
│       │   ├── dashboard/    ← WelcomeHeader, KPIGrid, KPICard, WellbeingChart,
│       │   │                    TrainingProgress, RotationRisk
│       │   └── ui/           ← Card, Button, Badge, Avatar, Progress, Icon,
│       │                        StateView (loading/empty/error)
│       └── views/
│           ├── management/   ← inicio, bienestar, formación, IA de RR. HH.,
│           │                    plantilla, configuración
│           └── employee/     ← inicio, check-in, formación, asesor, progreso
├── README.md
└── CHANGELOG.md
```

---

## 5. Backend: arquitectura interna y convenciones

### 5.1 Flujo de una petición

```
Apache → public/index.php → Router
       → [Cors → RateLimit → Auth → Tenant → Role → PlanGate → Csrf]
       → Controller → Service → Repository → Response JSON
```

### 5.2 Convenciones de la API

- `RNF-010` (M) Prefijo `/v1/...`.
- `RNF-011` (M) Solo `application/json`, UTF-8.
- `RNF-012` (M) Fechas en UTC, ISO 8601; el frontend convierte a `Europe/Madrid`.
- `RNF-013` (M) Éxito: `{ "data": ..., "meta": {...} }`; paginación `?page=&per_page=` (máx. 100).
- `RNF-014` (M) Error uniforme con código semántico (§16): `{ "error": { "code": "PH-AUTH-001", "message": "...", "details": {} } }`.
- `RNF-015` (M) Códigos HTTP correctos: 200, 201, 204, 400, 401, 403, 404, 409, 410, 422, 429, 500, 503.
- `RNF-016` (S) `docs/openapi.yaml` actualizado con cada endpoint.

### 5.3 Base de datos

- `RNF-020` (M) PDO con sentencias preparadas; `ATTR_EMULATE_PREPARES = false`; `ERRMODE_EXCEPTION`.
- `RNF-021` (M) Transacciones en operaciones compuestas; contadores y acumulados actualizados de forma atómica en SQL, nunca sobrescritos con valores recalculados en PHP.
- `RNF-023b` (M) Aislamiento por empresa: el `interlocutor_id` sale de la sesión y va en todo `WHERE`; nunca se acepta desde la petición (detalle en §7.1).
- `RNF-023` (M) Borrado lógico en empleados, equipos y módulos.

---

## 6. Autenticación, roles, planes y multi-empresa

### 6.1 Roles

| Perfil (`usuario.perfil`) | Ámbito | Puede |
|---|---|---|
| `ADMIN` (interlocutor `PLATAFORMA`) | Global | Gestionar tenants y planes; métricas de uso agregadas. **No** ve datos de empleados |
| `GERENTE` | Su empresa | Todo lo de RR. HH., más: crear y desactivar cuentas de RR. HH., gestionar equipos, suprimir datos de empleados (RGPD) y ver el plan contratado |
| `RRHH` | Su empresa | Empleados, invitaciones, importación CSV, incidencias, formación, línea base, bienestar agregado y, con plan Completo, riesgo de rotación y gestión de sus alertas |
| `EMPLEADO` | Él mismo | Check-in, formación asignada y autoinscripción, asesor de formación, su progreso e historial |

**Matriz de permisos (propuesta `P-10`):**

| Acción | Admin | Gerente | RR. HH. | Empleado |
|---|---|---|---|---|
| Alta de empresas, plan y ciclo de cobro | ✔ | — | — | — |
| Cuentas de RR. HH. | — | ✔ | — | — |
| Equipos | — | ✔ | Ver | — |
| Empleados, invitaciones, CSV, incidencias | — | ✔ | ✔ | — |
| Formación (asignar, crear módulos propios) | — | ✔ | ✔ | Autoinscripción |
| Bienestar agregado y alertas de equipo | — | ✔ | ✔ | — |
| Riesgo de rotación y sus alertas (plan Completo) | — | ✔ | ✔ | — |
| Supresión de datos de un empleado | — | ✔ | — | — |
| Datos de otra empresa | — | — | — | — |
| Datos de un empleado concreto | No | Según lo anterior | Según lo anterior | Solo los suyos |

### 6.2 Planes (confirmado)

| Funcionalidad | Entrada (15 €/empleado/mes) | Completo (29 €/empleado/mes) |
|---|---|---|
| Bienestar (check-in, agregado, alertas de equipo) | ✔ | ✔ |
| Upskilling (catálogo, asignación, cuestionarios, recomendaciones) | ✔ | ✔ |
| Asesor de formación con IA (empleado) | ✔ | ✔ |
| IA de RR. HH.: score de riesgo, alertas de rotación, tendencias y línea base | — | ✔ |
| Gestión de plantilla y búsqueda de talento (fase 2) | — | ✔ |
| Compra de cursos de Pyme Hub Skills (fase 2) | ✔ | ✔ |

Cobro (TFM, apartado 9.8): suscripción **mensual o anual** por empleado activo. En el MVP el plan y el ciclo se registran manualmente; la pasarela de pago llega en la fase comercial.

- `RF-010` (M) El middleware `PlanGate` rechaza en servidor (`PH-PLAN-001`) cualquier endpoint no incluido en el plan del tenant. Ocultar menús en el frontend es solo comodidad, no control.
- `RF-011` (M) `GET /v1/auth/me` devuelve la lista de funcionalidades activas (`features`) para que el frontend construya el menú.
- `RF-012` (S) En plan Entrada, la sección de riesgo muestra una vista informativa del plan Completo (argumento de upselling en la demo).

### 6.3 Autenticación

- `RF-001` (M) Email + contraseña; `password_hash()` Argon2id.
- `RF-002` (M) Al iniciar sesión: `session_regenerate_id(true)` y rotación del token CSRF.
- `RF-003` (M) Cookie `HttpOnly`, `Secure`, `SameSite=Strict`. Caducidad por inactividad: **30 min** para admin, gerente y RR. HH.; **7 días** para empleado (confirmado para el MVP).
- `RF-004` (M) Token CSRF en `GET /v1/auth/me`, exigido en `X-CSRF-Token` para toda petición que modifica datos.
- `RF-005` (M) Alta por invitación: enlace de un solo uso (32 bytes, hash SHA-256, caduca en 72 h).
- `RF-006` (M) Bloqueo temporal tras 5 intentos fallidos en 15 min por email + IP.
- `RF-007` (S) Restablecimiento de contraseña por enlace generado por el gerente.
- `RF-008` (M) Cierre de sesión que destruye la sesión en servidor.

---

## 7. Modelo de datos

La especificación completa, con diagrama y justificación, está en el repositorio de la API: `docs/base_de_datos/MODELO_DATOS.md`, y el esquema ejecutable en `migrations/0001_esquema_inicial.sql`. Este apartado resume las reglas.

### 7.1 Actores y personas (`DEC-14`)

| `interlocutor.tipo` | Qué representa | Perfiles de `usuario` admitidos |
|---|---|---|
| `PLATAFORMA` | Pyme Hub como operador; registro único | `ADMIN` |
| `EMPRESA` | PYME cliente | `GERENTE`, `RRHH`, `EMPLEADO` |
| `FORMADOR` | Formador de Pyme Hub Skills (fase 2) | `FORMADOR` |

- `RF-120` (M) Todo actor es un `interlocutor`; toda persona es un `usuario` de un interlocutor, con un `perfil`. Un interlocutor tiene N usuarios.
- `RF-121` (M) La base de datos impide un perfil que no corresponda al tipo del interlocutor (catálogo `cat_perfil` y clave foránea compuesta), un segundo interlocutor `PLATAFORMA`, más de una suscripción vigente por empresa y una cuenta `ACTIVO` sin credenciales.
- `RF-122` (M) Un empleado existe como `usuario` con perfil `EMPLEADO` aunque todavía no tenga acceso (`estado_acceso = SIN_ACCESO`), por ejemplo tras importar la plantilla. Sus datos laborales están en `ficha_laboral` (1:1), separados de los de acceso.
- `RNF-022` (M) Aislamiento: el `interlocutor_id` sale de la sesión y va en todo `WHERE`; las tablas de negocio usan claves foráneas compuestas (`usuario_id`, `interlocutor_id`) para que un registro nunca pueda apuntar a una persona de otra empresa.

### 7.2 Tablas

| Tabla | Propósito |
|---|---|
| `cat_tipo_interlocutor`, `cat_perfil` | Catálogos de tipos y de perfiles admitidos por tipo |
| `interlocutor` | Todos los actores; estado `PILOTO`, `ACTIVO`, `SUSPENDIDO`, `BAJA`; fecha del contrato de encargado |
| `suscripcion` | Plan, ciclo de cobro, precio, límites; histórico con una vigente |
| `usuario` | Personas: perfil, credenciales Argon2id, estado de acceso |
| `invitacion` | Enlaces de un solo uso (hash SHA-256, 72 h) |
| `equipo` | Equipos de la empresa |
| `ficha_laboral` | Puesto, contrato, turno, alta, baja y **motivo de baja** (para medir la rotación voluntaria) |
| `incidencia` | Ausencias injustificadas, retrasos, siniestros, quejas y reconocimientos |
| `modulo_formativo`, `pregunta_modulo` | Catálogo; el propietario es un interlocutor (plataforma = catálogo global, empresa = módulo propio, formador = fase 2) |
| `asignacion_formativa` | Formación de cada empleado (gestión, autoinscripción o asesor) |
| `preferencia_formativa` | Preferencias para el asesor de IA |
| `checkin_bienestar` | Check-in diario con nota cifrada y equipo en ese momento |
| `puntuacion_riesgo`, `alerta`, `linea_base` | IA de RR. HH. |
| `solicitud_ia` | Registro de consultas al asesor, sin contenido |
| `registro_auditoria`, `limite_peticion`, `migracion_esquema` | Soporte técnico |

Convenciones: nombres en español, minúsculas y singular; enumerados en mayúsculas validados con `CHECK` o catálogo; `JSON` nativo; fechas en UTC; borrado lógico con `eliminado_en`.

---

## 8. Contrato de API

Roles: **A** admin · **G** gerente · **H** RR. HH. · **E** empleado. Plan: **C** = solo Completo.

### 8.1 Autenticación

| Método | Ruta | Rol |
|---|---|---|
| POST | `/v1/auth/login` | — |
| POST | `/v1/auth/logout` | A G E |
| GET | `/v1/auth/me` (usuario, rol, tenant, plan, `features`, CSRF) | A G E |
| POST | `/v1/auth/invitations/accept` | — |

### 8.2 Plataforma

| Método | Ruta | Rol |
|---|---|---|
| GET/POST | `/v1/admin/tenants` | A |
| GET/PATCH | `/v1/admin/tenants/{id}` (plan, ciclo de cobro, límite, cuota IA, fecha del contrato de encargado, estado) | A |
| POST | `/v1/admin/tenants/{id}/export` (exportación completa de la empresa) | A |
| POST | `/v1/admin/tenants/{id}/terminate` (baja con borrado programado) | A |
| GET/PUT | `/v1/admin/maintenance` (aviso de mantenimiento programado y modo mantenimiento) | A |
| GET | `/v1/admin/metrics` (empresas por estado, empleados activos, MRR teórico por plan y ciclo de cobro) | A |
| GET | `/v1/health` (estado de API, base de datos, disco y última copia) | — |

### 8.3 Organización

| Método | Ruta | Rol |
|---|---|---|
| GET/POST | `/v1/users` (cuentas de RR. HH. de la empresa) | G |
| PATCH/DELETE | `/v1/users/{id}` | G |
| GET/PUT | `/v1/baseline` (línea base de impacto) | G H |
| GET | `/v1/teams` | G H |
| POST | `/v1/teams` | G |
| PATCH/DELETE | `/v1/teams/{id}` | G |
| GET/POST | `/v1/employees` | G H |
| GET/PATCH/DELETE | `/v1/employees/{id}` | G H |
| POST | `/v1/employees/{id}/invitation` | G H |
| POST | `/v1/employees/import` (CSV) | G H |
| GET/POST | `/v1/employees/{id}/incidents` | G H |

- `RF-020` (M) **Importación CSV de la plantilla** (`POST /v1/employees/import`, cuerpo `text/csv`):
  - Archivo UTF-8 (con o sin BOM), separador `;` (Excel en español) o `,`, primera fila de cabecera. Máximo 1 MB y 250 filas de datos; las filas vacías se ignoran.
  - Columnas: `codigo_interno`, `nombre`, `apellidos`, `email`, `puesto`, `tipo_contrato`, `turno`, `fecha_alta` (`AAAA-MM-DD` o `DD/MM/AAAA`) y `equipo` (nombre). Obligatorias: `codigo_interno`, `nombre`, `puesto`, `tipo_contrato`, `turno` y `fecha_alta`. Los enumerados admiten minúsculas, tildes y espacios (`Mozo almacén` = `MOZO_ALMACEN`). Plantilla de ejemplo: `docs/ejemplos/plantilla_empleados.csv`.
  - **Todo o nada:** si una fila falla no se importa ninguna, y la respuesta `PH-VAL-002` lista cada error con `fila` (numeración de Excel: la cabecera es la 1), `columna` y `motivo`.
  - Si `codigo_interno` ya existe en la empresa se actualiza la ficha (las columnas opcionales vacías no borran datos); si no, se crea el empleado. No se modifican por CSV empleados dados de baja ni el email de una cuenta activa.
  - Los equipos deben existir: un equipo desconocido es un error, no se crea solo.
  - `?simular=1` valida y devuelve el resumen (`altas`, `cambios`, `sin_cambios`, `errores`) sin escribir nada.
  - Las altas comprueban `limite_empleados` (`PH-TENANT-002`), también al simular. La importación no crea invitaciones: se invita después, empleado a empleado.
- `RF-021` (M) Un empleado es un `usuario` con perfil `EMPLEADO` y su `ficha_laboral` (`RF-122`). Se crea en `SIN_ACCESO` y el email es opcional hasta invitarle. Cada alta comprueba `limite_empleados` de la suscripción vigente (`PH-TENANT-002`); cuentan los empleados sin `fecha_baja`.
- `RF-022` (M) `DELETE /v1/employees/{id}` registra la **baja laboral** con `fecha_baja` (no futura) y `motivo_baja` (`VOLUNTARIA`, `NO_VOLUNTARIA`, `FIN_CONTRATO`): no borra al usuario, le retira el acceso y anula sus invitaciones pendientes. La ficha queda en solo lectura. La supresión de datos es otro endpoint (`POST /v1/employees/{id}/erase`, §8.8).
- `RF-023` (M) `POST /v1/employees/{id}/invitation` exige el contrato de encargado del tratamiento de la empresa (`LEG-016`, `PH-TENANT-003`) y un email, que puede indicarse en la propia petición. Un equipo solo se borra si no tiene empleados de alta.

### 8.4 IA para RRHH (plan Completo)

| Método | Ruta | Rol | Plan |
|---|---|---|---|
| GET | `/v1/dashboard/overview` | G H | Ambos (KPIs de riesgo solo en C) |
| GET | `/v1/dashboard/trends?months=6` | G H | C |
| GET | `/v1/risk?team_id=&level=` | G H | C |
| GET | `/v1/employees/{id}/risk` | G H | C |
| POST | `/v1/risk/recalculate` | G H | C |
| GET | `/v1/risk/methodology` (reglas, pesos, fuentes, versión) | G H | C |
| GET | `/v1/risk/bias` (revisión de sesgos, `LEG-015`) | G H | C |
| GET/PUT | `/v1/baseline` (línea base de impacto, `RF-037`) | G H | C |
| GET | `/v1/alerts?status=open` | G H | Ambos (alertas de rotación solo en C) |
| PATCH | `/v1/alerts/{id}` | G H | Ambos |

### 8.5 Upskilling

| Método | Ruta | Rol |
|---|---|---|
| GET/POST | `/v1/training/modules` | G H |
| GET/PATCH | `/v1/training/modules/{id}` | G H |
| POST | `/v1/training/modules/{id}/publish` (publica un módulo propio) | G H |
| GET/POST | `/v1/training/assignments` (seguimiento con filtros y asignación) | G H |
| DELETE | `/v1/training/assignments/{id}` (retira una asignación de la empresa no completada) | G H |
| GET | `/v1/training/summary` (indicadores de formación) | G H |
| GET | `/v1/training/recommendations?employee_id=` | G H |
| GET | `/v1/me/training` | E |
| GET | `/v1/me/training/catalog?category=&level=` | E |
| POST | `/v1/me/training/enroll` (autoinscripción en un módulo publicado) | E |
| GET | `/v1/me/training/{assignmentId}` | E |
| POST | `/v1/me/training/{assignmentId}/progress` | E |
| POST | `/v1/me/training/{assignmentId}/submit` | E |

### 8.6 Bienestar

| Método | Ruta | Rol |
|---|---|---|
| POST | `/v1/me/checkins` | E |
| GET | `/v1/me/checkins?days=30` | E |
| GET | `/v1/wellbeing/summary?team_id=&weeks=8` (solo agregado anónimo) | G H |

### 8.7 Asesor de formación con IA (§12)

| Método | Ruta | Rol | Plan |
|---|---|---|---|
| GET/PUT | `/v1/me/training/preferences` | E | Ambos |
| DELETE | `/v1/me/training/preferences` | E | Ambos |
| POST | `/v1/me/training/advisor` (devuelve recomendaciones) | E | Ambos |
| POST | `/v1/me/training/advisor/feedback` (útil / no útil) | E | Ambos |
| GET | `/v1/training/demand` (temas más pedidos al asesor, agregados, ≥ 5 empleados) | G H | Ambos |
| GET | `/v1/admin/ai-usage` | A | — |

### 8.8 Privacidad

| Método | Ruta | Rol |
|---|---|---|
| GET | `/v1/me/data-export` | E |
| POST | `/v1/employees/{id}/erase` | G |

---

## 9. Módulo IA para RRHH — motor de riesgo de rotación

### 9.1 Requerimientos

- `RF-030` (M) Dashboard: plantilla activa, rotación a 12 meses (%), antigüedad media, empleados por nivel de riesgo, % de formación completada en plazo, índice de bienestar agregado, alertas abiertas.
- `RF-031` (M) Score 0–100 por empleado activo, diario (cron) y bajo demanda.
- `RF-032` (M) Cada score guarda factores, puntos y `rules_version` para explicarlo y reproducirlo.
- `RF-033` (M) Niveles: **bajo** 0–29 · **medio** 30–54 · **alto** 55–100. El paso a *alto* genera una alerta dirigida a RR. HH., visible también para el gerente.
- `RF-034` (M) La ficha muestra el score **siempre** con los factores en lenguaje llano y un enlace "¿De dónde sale esto?" a la metodología y sus fuentes (`GET /v1/risk/methodology`).

- `RF-037` (S) **Línea base de impacto**: RR. HH. o Customer Success registran la plantilla media, las bajas voluntarias y los días de absentismo de los 12 meses anteriores al alta; el panel compara la rotación y el absentismo desde el alta con esa línea base. Es el dato que alimenta la medición del piloto (Anexo 16.4 del TFM: objetivo de reducción relativa del 15 % a 12 meses).

### 9.2 Criterios de diseño

1. **Los pesos siguen la fuerza de la evidencia**, no la intuición: un factor respaldado por meta-análisis pesa más que uno apoyado en una encuesta de opinión o en estudios de otro sector.
2. **Solo datos que un gerente ya maneja legítimamente** (fecha de alta, tipo de contrato, turno, incidencias laborales, formación). Nada de salud.
3. **Se excluye lo que la evidencia desmiente**, aunque parezca intuitivo (caso de los retrasos).
4. **Se excluye lo que convertiría una herramienta voluntaria en obligatoria** (participación en el check-in).
5. Los pesos son una **ponderación ordinal justificada, no una calibración estadística**. Se declara como limitación (§9.5).

### 9.3 Reglas v1.1 (recalibradas)

| # | Factor | Condición | Puntos | Evidencia | Fuente |
|---|---|---|---|---|---|
| F1 | Antigüedad | < 90 días / 90–365 días | 30 / 15 | **Fuerte** | [S1] [S2] |
| F2 | Ausencias injustificadas | ≥ 2 en los últimos 60 días / 1 | 25 / 10 | **Fuerte** (meta-analítica) | [S3] |
| F3 | Tipo de contrato | Temporal o ETT | 15 | Moderada | [S4] |
| F4 | Formación | ≥ 1 asignación vencida sin completar | 10 | Débil (declarativa) | [S5] |
| F5 | Turno | Noche o rotativo | 10 | Moderada, indirecta (otro sector) | [S6] |
| F6 | Siniestros o quejas de cliente | ≥ 1 en los últimos 60 días | 10 | **Hipótesis del equipo**, sin fuente directa | Validar en piloto |
| F7 | Reconocimiento | ≥ 1 registrado en los últimos 90 días | **−15** | Moderada-fuerte (longitudinal) | [S7] |
| — | Retrasos | — | **0** (se registran, no puntúan) | Evidencia **contraria** | [S3] |
| — | Participación en check-in | — | **Excluido** | Decisión ética y legal | `LEG-013` |

Máximo teórico: 100 puntos (F1 30 + F2 25 + F3 15 + F4 10 + F5 10 + F6 10); el resultado se acota a 0–100 tras aplicar F7. Pesos en `config/risk_rules.php`; cambiar un peso exige subir `rules_version`.

**Cambios frente a la v1 (borrador 0.1):**

| Cambio | Motivo |
|---|---|
| Antigüedad sube de 25 a 30 y su tramo medio se amplía hasta 365 días | Es el predictor mejor documentado; la concentración de bajas se observa en el primer año completo, no solo en los primeros 90 días [S1] |
| Ausencias suben de 15 a 25 y la ventana pasa a 60 días | Es el comportamiento con mayor relación meta-analítica con la baja voluntaria [S3] |
| **Retrasos pasan de 10 a 0** | El meta-análisis de Berry et al. encuentra una relación prácticamente nula entre retrasos y abandono, frente a una relación apreciable en ausencias [S3] |
| **Participación en check-in, eliminada** | Si no responder al check-in sube el riesgo, el check-in deja de ser voluntario, lo que choca con el consentimiento libre (RGPD) y con la confianza que necesita el módulo |
| Reconocimiento pasa de −10 a −15 | Evidencia longitudinal de reducción del abandono en empleados bien reconocidos [S7] |
| Umbrales de nivel recalibrados | Con la nueva escala, un empleado nuevo, temporal y con ausencias (30+25+15 = 70) debe quedar en *alto*; uno solo nuevo (30) en *medio* |

### 9.4 Fuentes

| Id | Fuente | Qué aporta | Uso |
|---|---|---|---|
| S1 | Work Institute, *2025 Retention Report: Employee Retention Truths in Today's Workplace* (y ediciones 2018–2023) | Hasta un 40 % de toda la rotación ocurre durante el primer año de empleo | F1 |
| S2 | Griffeth, R. W., Hom, P. W. y Gaertner, S. (2000). A meta-analysis of antecedents and correlates of employee turnover. *Journal of Management, 26*(3), 463–488 | Meta-análisis de referencia sobre predictores de rotación; la antigüedad es uno de los más estudiados | F1, marco general |
| S3 | Berry, C. M., Lelchook, A. M. y Clark, M. A. (2012). A meta-analysis of the interrelationships between employee lateness, absenteeism, and turnover. *Journal of Organizational Behavior, 33*(5), 678–699 | Correlación absentismo–rotación ≈ 0,25; retrasos–rotación ≈ 0,01 | F2 y exclusión de retrasos |
| S4 | Frontiers in Psychology (2022), estudio sobre intención de abandono en trabajadores temporales y permanentes; De Cuyper et al. (2009) sobre el contrato psicológico de los temporales | Menor compromiso afectivo de los temporales respecto a los permanentes | F3 |
| S5 | LinkedIn Learning, *Workplace Learning Report* (2018–2019) | El 94 % de los empleados afirma que se quedaría más tiempo si la empresa invirtiera en su formación. Dato declarativo: por eso peso bajo | F4 |
| S6 | Estudios en personal sanitario a turnos (BMC Nursing, 2022; revisión en Frontiers in Psychology, 2021) | El trabajo a turnos y nocturno se asocia a mayor intención de abandono. Evidencia de otro sector: peso bajo | F5 |
| S7 | Gallup y Workhuman (2024), estudio longitudinal 2022–2024 con cerca de 3.500 empleados | Los empleados con reconocimiento de calidad tienen un 45 % menos de probabilidad de haberse marchado a los dos años | F7 |
| C1 | IRU, informe de escasez de conductores (2025, publicado junio 2026) | Europa tiene un 13 % de puestos de conductor sin cubrir (≈ 502.000) | Contexto del sector para el TFM |
| C2 | Gallup, coste de reposición por perfil | Reponer a un trabajador de primera línea cuesta en torno al 40 % de su salario | Caso de negocio del TFM |

> **Advertencia honesta para el tribunal:** S1, S5 y S7 proceden de Estados Unidos o de muestras internacionales, no del transporte español. Por eso el sistema se presenta como un **modelo inicial basado en literatura**, y el piloto (§9.5) es el que debe ajustarlo al sector y al país. Conviene contrastar también F1–F7 con los resultados de las encuestas de campo del TFM (`P-08`).

### 9.5 Limitaciones y evolución

- `RF-035` (M) La hoja técnica `docs/hoja_tecnica_modelo_riesgo.md` recoge propósito, datos, reglas, pesos, fuentes, versión y estas limitaciones.
- `RF-036` (C) Fase 2 (meses 17–18 del cronograma, Anexo F del TFM): con 12 meses de datos reales de bajas de los clientes piloto, sustituir los pesos por coeficientes de una regresión logística validada (AUC, calibración), manteniendo la explicación por factor.

### 9.6 Implementación (v0.6.0)

- **Una puntuación por persona y día** (`puntuacion_riesgo.fecha`, migración `0006`). El recálculo bajo demanda sustituye la del día; el cron de las 03:00 añade la del día siguiente. Las ventanas (60 y 90 días) se calculan respecto a la fecha de la puntuación, así que cualquier puntuación se puede reproducir.
- **Alerta de rotación** (`RF-033`) solo al **pasar** a nivel alto y si no hay otra abierta de esa persona; prioridad alta desde 70 puntos. El texto nombra los tres factores principales y recuerda que la puntuación orienta una conversación.
- `POST /v1/risk/recalculate`: una vez cada 10 minutos por empresa (`PH-RATE-001`). La primera consulta de una empresa sin puntuaciones calcula la del día.
- F4 cuenta solo asignaciones de la empresa (`origen = GESTION`), nunca autoinscripciones ni recomendaciones del asesor (`RF-047`).
- **Comprobación retrospectiva** en el panel: nivel de la última puntuación de cada baja voluntaria desde el alta en Pyme Hub. Orientativa con pocas bajas.
- **Línea base** (`RF-037`): una vigente por empresa. Los días de absentismo son **días de ausencia injustificada**, la misma medida que registra la aplicación (`LEG-012`), para que la comparación sea homogénea; la evolución desde el alta se anualiza y se marca como no representativa con menos de 90 días.
- **Sesgos** (`LEG-015`): `GET /v1/risk/bias` y `cli/bias_report.php` (trimestral) marcan grupos de 5 o más personas a más de 20 puntos del total, indicando si el grupo es un factor del modelo (turno, contrato). El resultado se anota en la hoja técnica.
- Auditoría (`LEG-004`): `riesgo.ficha_consultada`, `riesgo.recalculado` y `linea_base.registrada`.

---

## 10. Módulo Upskilling

- `RF-040` (M) Catálogo global de **al menos 15 micro-módulos** con metadatos (categoría, nivel, puestos destinatarios, etiquetas, duración), para que el asesor tenga donde elegir:

| Categoría | Módulos |
|---|---|
| Conducción y seguridad vial | Conducción eficiente · Seguridad vial en ciudad · Conducción nocturna segura · Uso del vehículo eléctrico de reparto |
| Almacén y carga | Manipulación manual de cargas · Uso seguro del transpalé · Estiba y sujeción de la carga |
| Cliente y entrega | Atención al cliente en la entrega · Gestión de incidencias y rechazos · Comunicación con clientes difíciles |
| Bienestar | Gestión del estrés en ruta · Pausas activas e higiene postural · Descanso y sueño en turnos rotativos |
| Desarrollo profesional | Primeros pasos como coordinador de ruta · Herramientas digitales del repartidor (app, escáner, POD) |
- `RF-041` (M) Módulo = bloques (texto breve, imagen, consejo clave, vídeo opcional) + cuestionario de 2–4 preguntas; 3–5 min.
- `RF-042` (M) Asignación individual o por equipo, con fecha límite.
- `RF-043` (S) Recomendaciones por reglas: siniestro → seguridad vial; queja → atención al cliente; estrés del equipo al alza → gestión del estrés; antigüedad < 30 días → acogida.
- `RF-044` (M) Aprobado con ≥ 70 %; intentos ilimitados; se guarda la mejor nota.
- `RF-045` (M) Completable sin conexión (`RF-062`).
- `RF-046` (S) El gerente puede crear módulos propios (sin IA).
- `RF-047` (M) El empleado puede **autoinscribirse** en cualquier módulo publicado, sin fecha límite. Las autoinscripciones no puntúan en el motor de riesgo como formación vencida.

**Implementación (v0.4.0):**
- El catálogo global vive en `data/catalogo_formativo.php` y se sincroniza por `codigo` (instalador, `cli/migrate.php`, `cli/catalog_sync.php`); si cambia el contenido sube la versión del módulo sin tocar el avance. Las opciones de cada pregunta se ordenan de forma determinista para que la correcta no esté siempre en la misma posición.
- Una asignación por persona y módulo. Si la gestión asigna un módulo en el que la persona ya se había autoinscrito, pasa a ser de la empresa con la nueva fecha límite. `VENCIDA` es un estado calculado: no completada con la fecha límite pasada.
- El reproductor nunca recibe la solución del cuestionario; la corrección es en el servidor y la solución solo se muestra al aprobar. El avance es monótono (nunca retrocede) y no llega al 100 % sin aprobar. Cada envío del cuestionario lleva un `envio_id`: reenviarlo desde la cola sin conexión no suma un intento (`RF-045`, `RF-062`).
- `RF-043`: las reglas usan incidencias de los últimos 90 días, la antigüedad y las **alertas de bienestar de equipo** (dato agregado); nunca respuestas individuales del check-in (§9.2, `LEG-013`). Siniestro → *Seguridad vial en ciudad* (y *Conducción nocturna* en turno de noche); queja → *Atención al cliente* (y *Clientes difíciles* si hay más de una); alerta del equipo → *Gestión del estrés*; menos de 30 días → *Herramientas digitales* y el módulo básico del puesto. La gestión decide si asigna.
- `RF-046`: los módulos propios se escriben en un editor (bloques de texto, lista, consejo e ilustración de marca, 2–4 preguntas), se guardan como borrador y se publican en el catálogo de la empresa. El catálogo global no se edita (`PH-PERM-001`).

---

## 11. Módulo Bienestar

- `RF-050` (M) Check-in en menos de 15 segundos: tres escalas 1–5 (ánimo, energía, estrés) y nota opcional.
- `RF-051` (M) Un check-in por día; repetirlo actualiza el del día.
- `RF-052` (M) El empleado ve su evolución de 30 días.
- `RF-053` (M) El gerente ve medias semanales por equipo; nunca registros individuales ni notas.
- `RF-054` (M) Alerta de equipo si la media semanal de estrés sube ≥ 1 punto frente a las 4 semanas anteriores o el ánimo medio baja de 2,5.
- `RF-055` (S) Ante valores bajos, mensaje de apoyo al empleado con recursos (teléfono 024, servicio de prevención). Sin intervención automática.
- `RF-056` (M) El check-in es **voluntario** y no responder no tiene ninguna consecuencia en ningún cálculo.

---

## 12. Módulo de IA generativa: Asesor de formación

### 12.1 Alcance

La IA generativa se usa en **una única funcionalidad**: el **Asesor de formación**. Un empleado (por ejemplo, un conductor) indica sus objetivos, intereses y preferencias, y el asesor le recomienda qué micro-módulos del catálogo le convienen y en qué orden, con una explicación breve de por qué.

Ninguna otra parte del sistema usa IA generativa. El motor de riesgo (§9) y las recomendaciones del gerente (`RF-043`) son reglas deterministas.

### 12.2 Flujo del empleado

1. En **Formación → "¿Qué me conviene aprender?"**, el empleado completa un breve perfil de preferencias (menos de 30 segundos, sobre todo con selección de opciones):

| Campo | Tipo | Opciones de ejemplo |
|---|---|---|
| Objetivo principal | Una opción | Hacer mejor mi trabajo actual · Conducir con más seguridad · Cuidar mi salud y energía · Prepararme para un puesto de más responsabilidad · Aprender a usar mejor las herramientas digitales |
| Temas de interés | Varias opciones | Categorías del catálogo (§10) |
| Formato preferido | Una opción | Solo texto · Con imágenes · Con vídeo |
| Tiempo disponible por semana | Una opción | 5 · 15 · 30 minutos |
| Comentario libre | Texto opcional, máx. 200 caracteres | "Me gustaría llegar a coordinar un equipo" |

2. El sistema añade automáticamente el **perfil laboral no identificativo** del empleado: puesto genérico, tramo de antigüedad, tipo de turno y la lista de módulos ya completados y en curso.
3. El asesor devuelve **entre 3 y 5 módulos del catálogo**, ordenados, cada uno con el motivo en una frase ("Como trabajas en turno de noche y quieres cuidar tu energía, empieza por…") y un plan semanal ajustado al tiempo disponible.
4. El empleado puede **inscribirse con un toque** en cada recomendación (`source = asesor_ia`) y valorar si le ha sido útil.

### 12.3 Qué se envía y qué no se envía al modelo

| Se envía | No se envía nunca |
|---|---|
| Puesto genérico (conductor, mozo, coordinador) | Nombre, email, identificadores, empresa |
| Tramo de antigüedad (< 3 meses, 3–12 meses, > 1 año) | Fecha de alta exacta |
| Tipo de turno | Score de riesgo, incidencias, ausencias |
| Preferencias y comentario libre (tras `PayloadGuard`) | Check-ins de bienestar ni ningún dato de salud |
| IDs, títulos, resúmenes y metadatos del catálogo | Contenido completo de los módulos |
| IDs de módulos completados o en curso | Notas de los cuestionarios |

### 12.4 Proveedor

| Opción | Coste | Encaje para la demo | Decisión |
|---|---|---|---|
| **Mistral AI — plan "Experiment"** | Gratis, sin tarjeta, con verificación de teléfono | Empresa europea con alojamiento en la UE por defecto; API compatible con el formato de OpenAI; límites no publicados oficialmente, suficientes para un uso puntual | **Elegido** (cuenta de Herman Adrian Torres, `P-07`) |
| Google Gemini — cuota gratuita | Gratis | Sus términos exigen servicios de pago cuando la aplicación se ofrece a usuarios del EEE, Suiza o Reino Unido | Descartado para la demo |
| Anthropic / OpenAI | De pago | Sin plan gratuito estable de API | Opción de producción |
| Modelo local (Ollama) en el VPS | Gratis | VPS compartido y sin GPU: latencia de muchos segundos | Descartado |

Condiciones asumidas: el plan gratuito es de **evaluación, no de producción**, y sus entradas y salidas **pueden usarse para entrenar modelos** de Mistral. Por eso solo se envía lo indicado en §12.3, que no identifica a nadie. Para producción, basta con pasar a su modalidad de pago por uso; el modelo financiero del TFM (Anexo A) presupuesta 0,10 € por empleado y mes para la API de IA.

### 12.5 Requerimientos técnicos

- `IA-001` (M) **Recomendaciones ancladas al catálogo**: el modelo solo puede devolver IDs que existan en el catálogo enviado. El servidor descarta cualquier ID inexistente, duplicado o ya completado; si quedan menos de 3 válidos, completa la lista con el emparejamiento por reglas de `IA-002`.
- `IA-002` (M) **Respaldo determinista**: si el proveedor falla, se agota la cuota o no hay red, el asesor responde igualmente con un emparejamiento por reglas (etiquetas del catálogo × objetivo × puesto × turno), indicado como "Recomendación automática sin IA". El empleado nunca ve un error.
- `IA-010` (M) **`PayloadGuard`**: lista blanca de campos (§12.3). El comentario libre se rechaza si contiene email, teléfono, DNI/NIE o nombres de personas del tenant (`PH-AI-004`), y la interfaz avisa: "No incluyas datos personales ni de salud".
- `IA-011` (M) **Adaptador intercambiable** (`AiGateway` + proveedores): cambiar de proveedor es cambiar `config.php` (`ai.provider`, `ai.base_url`, `ai.model`, `ai.api_key`).
- `IA-012` (M) cURL de PHP, timeout de 20 s, un reintento ante 429/5xx, registro en `ai_requests` sin contenido.
- `IA-013` (M) Salida en **JSON validado por esquema**: `[{module_id, order, reason (≤ 160 caracteres)}]` + `weekly_plan`. Si no valida: un reintento y, después, respaldo `IA-002`.
- `IA-014` (M) **Caché** por hash de la entrada normalizada (preferencias + perfil + versión del catálogo), 7 días. Como la mayor parte de la entrada es de selección de opciones, perfiles iguales reutilizan la misma respuesta.
- `IA-015` (M) **Modos** `ai.mode = live | cache_first | offline`. En `offline` se sirven respuestas pregeneradas para los perfiles de la demo (§12.6) o, si no hay, el respaldo `IA-002`. La respuesta indica `served_from` (`live` / `cache` / `reglas`).
- `IA-016` (M) Límite de **5 consultas al día por empleado** y cuota diaria por tenant (`ai_daily_quota`).
- `IA-017` (M) El asesor **recomienda, no asigna**: nada se inscribe sin la acción del empleado, y el gerente no ve las preferencias individuales ni el texto libre.
- `IA-018` (M) **Transparencia**: las recomendaciones muestran "Recomendación generada con IA" y un enlace "¿Cómo funciona?" que explica qué datos se usan.
- `IA-019` (M) Prompt de sistema versionado en `src/Ai/prompts/advisor.v1.md`: español, tono de marca, solo catálogo, sin consejos médicos, legales ni de salud, formato JSON.
- `IA-020` (M) Clave del proveedor solo en `config.php`, nunca en el frontend ni en logs.
- `IA-021` (S) **Demanda formativa agregada**: el gerente ve qué objetivos y temas piden más sus empleados (solo recuentos, con el umbral de 5 personas de `LEG-002`), útil para decidir qué módulos propios crear.

### 12.6 Perfiles de la demo (resuelve `P-09`)

Para que el modo `offline` funcione en la defensa, se pregeneran las respuestas de estos perfiles con `cli/ai_warmup.php`:

| Perfil de demo | Preferencias | Resultado esperado |
|---|---|---|
| Conductor, turno de noche, 2 meses | Objetivo: cuidar mi salud y energía · Temas: bienestar, conducción · 15 min/semana | Conducción nocturna segura, Descanso y sueño en turnos rotativos, Pausas activas |
| Repartidor, turno de mañana, 14 meses | Objetivo: prepararme para más responsabilidad · Comentario: "Me gustaría llegar a coordinar un equipo" · 30 min/semana | Primeros pasos como coordinador, Comunicación con clientes difíciles, Gestión de incidencias |
| Mozo de almacén, turno rotativo, 5 meses | Objetivo: hacer mejor mi trabajo · Temas: almacén y carga · 5 min/semana | Uso seguro del transpalé, Manipulación de cargas (plan de 1 módulo por semana) |

El usuario `conductor@` de la demo tiene precargado el primer perfil. Si en la defensa se prueba un perfil distinto, se intenta en vivo y, sin red, responde el respaldo por reglas.

### 12.7 Implementación (v0.5.0)

- **Objetivos** (catálogo cerrado, `CHECK` en `preferencia_formativa`): `MEJORAR_TRABAJO`, `SEGURIDAD`, `SALUD_ENERGIA`, `RESPONSABILIDAD`, `HERRAMIENTAS_DIGITALES`. Temas = categorías del catálogo; formato `TEXTO`, `IMAGENES`, `VIDEO`; 5, 15 o 30 minutos.
- **Perfil enviado**: puesto, tramo de antigüedad (`MENOS_3_MESES`, `DE_3_A_12_MESES`, `MAS_DE_1_ANO`) y turno. `PayloadGuard::verificar()` comprueba la lista blanca antes de cada llamada.
- **PayloadGuard del comentario** (`PH-AI-004`): email, teléfono, DNI/NIE, nombres y apellidos de las personas de la empresa y términos de salud (categoría especial del RGPD).
- **Caché** (`respuesta_ia`): el hash no incluye el avance de la persona, para que perfiles iguales compartan respuesta; los módulos completados se descartan al servir y se completa con reglas (`IA-001`).
- **Modos**: `live` llama siempre al proveedor; `cache_first` usa la caché vigente y, si no hay, el proveedor; `offline` usa la caché (también caducada o pregenerada) y, si no hay, reglas. Con fallo del proveedor (`PH-AI-001`), cuota agotada (`PH-AI-002`, 5 consultas en vivo al día por empleado y `cuota_ia_diaria` por empresa) o respuesta no válida tras un reintento (`PH-AI-003`), se sirve la última caché del perfil o las reglas, con el aviso en `meta.aviso`.
- **Respaldo por reglas** (`IA-002`): puntuación por módulos del objetivo, categorías del objetivo o del puesto, temas, puesto destinatario, turno de noche o rotativo, coincidencia con el comentario y nivel según antigüedad; 3 módulos con 5 o 15 minutos y 4 con 30.
- **Inscripción**: `POST /v1/me/training/enroll` con `solicitud_id` crea la asignación con origen `ASESOR_IA`.
- **Uso de la plataforma**: `GET /v1/admin/ai-usage` (consultas por origen, avisos, valoraciones, tokens, latencia y por empresa), sin contenido.
- **Retención**: `cli/purge_retention.php` borra la caché caducada hace más de 30 días y el registro de consultas de más de 12 meses.

---

## 13. Frontend (repositorio `pymehub_web`)

### 13.1 Documentos de referencia

| Documento | Uso |
|---|---|
| **Manual de Imagen Corporativa Pyme Hub v1.0** (septiembre 2026) | Marca: esencia, logotipo, paleta, tipografía, lenguaje visual, voz y usos correctos |
| **Manual de Maquetación HTML/CSS v1.0** | Reglas de implementación: layout, tokens, breakpoints, componentes, estados y accesibilidad. Es la guía obligatoria para el desarrollador |
| Mockups aprobados (*Identidad visual y UI*, *Panel Talento en Crecimiento*, *HR Dashboard UI Collage*) | **Referencia visual** del resultado esperado, no especificación funcional: el contenido lo fija este documento (§13.6) |
| Logotipo *Conexión y talento* (PNG 1774 × 887) | Logotipo horizontal provisional hasta disponer del vectorial (§13.5) |

- `RNF-030` (M) Se cumplen las **reglas de implementación** del Manual de Maquetación: el mockup nunca se usa como imagen; sin `position: absolute` en el layout principal; CSS Grid para columnas y Flexbox para agrupaciones; unidades relativas; enfoque *mobile first*; *container queries* en componentes reutilizables; datos separados de la presentación; textos largos y valores variables sin romper el diseño; sin desbordamiento horizontal (`min-width: 0`, `minmax(0, 1fr)`).

### 13.2 Tokens de diseño

Se unifican los dos manuales en un único `styles/tokens.css`:

```css
:root {
  /* Marca (Manual de Imagen Corporativa) */
  --primary: #22C55E;        /* Verde Crecimiento: acción y crecimiento */
  --secondary: #3B82F6;      /* Azul Confianza: enlaces y acciones secundarias */
  --text: #1F2937;           /* Gris Carbón: texto */
  --sand: #F8F7F4;           /* Arena: fondos cálidos (login, marketing) */
  --blue-light: #E0F2FE;     /* Azul Claro: fondos destacados */
  /* Interfaz (Manual de Maquetación) */
  --navy: #08263B;           /* Navegación lateral (ver P-12) */
  --muted: #64748B;
  --background: #F6F8FA;
  --surface: #FFFFFF;
  --border: #E2E8F0;
  --success: #22C55E;
  --warning: #F59E0B;
  --danger: #EF4444;
  --radius-sm: 8px;
  --radius-md: 12px;
  --radius-lg: 16px;
  --shadow-card: 0 2px 8px rgba(15, 23, 42, 0.06);
  /* Tipografía */
  --font-display: 'Plus Jakarta Sans', system-ui, sans-serif; /* titulares, KPI, CTA */
  --font-ui: 'Inter', system-ui, sans-serif;                  /* interfaz, tablas, datos */
  /* Espaciado: 4 · 8 · 12 · 16 · 24 · 32 · 40 · 48 */
  --space-1: 4px; --space-2: 8px; --space-3: 12px; --space-4: 16px;
  --space-6: 24px; --space-8: 32px; --space-10: 40px; --space-12: 48px;
}
```

- `RNF-034` (M) Ningún color, radio, sombra ni espaciado se escribe fuera de `tokens.css`.
- `RNF-038` (M) Jerarquía tipográfica de la interfaz: H1 28–32 px (con `clamp()`), H2 20–24 px, H3 16–18 px, cuerpo 14–16 px, texto pequeño 12–13 px, valores KPI 28–36 px.
- `RNF-039` (M) Regla de proporción de la marca: verde y azul como acentos, gris carbón para la lectura; el verde no se usa como fondo dominante.
- `RNF-036` (M) Voz de la marca: humana, clara, práctica, positiva y confiable ("Conoce cómo evoluciona tu equipo"), sin jerga ("optimización holística de la fuerza laboral mediante IA").

### 13.3 Layout y comportamiento responsive

```
AppShell
├── Sidebar (240 px, navegación carbón/navy)          ← en móvil: MobileNavigation inferior
└── MainArea
    ├── Topbar (buscador, avisos, usuario y empresa)
    ├── WelcomeHeader ("Hola, Ana")
    ├── KPIGrid (4 KPICard)
    └── DashboardGrid (WellbeingChart · TrainingProgress · RotationRisk)
```

| Componente | ≥ 1024 px | 768–1023 px | < 768 px |
|---|---|---|---|
| Sidebar | Visible, 240 px | Reducible | Oculto |
| Navegación inferior | Oculta | Opcional | Visible |
| Topbar | Completa | Reducida | Simplificada (buscador como icono) |
| KPI | 4 columnas | 2 columnas | 1 columna |
| Dashboard | 3 columnas (`1.5fr 1fr 1fr`) | 2 columnas | 1 columna |
| Tablas | Completas | Adaptadas | Tarjetas o scroll propio |

- `RNF-031` (M) Dos experiencias sobre el mismo sistema de componentes: **gestión** (gerente y RR. HH., menús según permisos y plan) y **empleado** (navegación inferior siempre, zonas táctiles ≥ 44 px, uso con una mano).
- `RNF-040b` (M) Resoluciones de validación: 1440 × 900 y 390 × 844, más 768 × 1024; el diseño no debe depender de ellas.

### 13.4 Componentes, estados y accesibilidad

- `RNF-041` (M) **Estados obligatorios** en todo componente con datos: cargando, vacío, error (con botón "Reintentar") y éxito. El estado *bienestar no disponible por anonimato* (`PH-WB-001`) es un estado vacío propio con su explicación.
- `RNF-042` (M) **Botones** en tres variantes (primario verde con texto blanco, secundario con borde azul o gris, terciario) y seis estados (normal, hover, foco, activo, deshabilitado, cargando). Acciones con `<button>`, navegación con `<a>`.
- `RNF-043` (M) **Gráficos dinámicos** con Chart.js (`width: 100%`, `min-height: 240px`), nunca imágenes; con tooltip y alternativa textual accesible.
- `RNF-044` (M) **El riesgo y el bienestar nunca se comunican solo por color**: nivel en texto ("Alto", "Medio", "Bajo") más icono.
- `RNF-045` (M) Accesibilidad: contraste AA, navegación por teclado, foco visible, `alt` en imágenes informativas, `label` en formularios, `aria-label` cuando haga falta, HTML semántico (`aside`, `nav`, `main`, `header`, `section` con `aria-labelledby`).
- `RNF-046` (M) **Iconos de una sola familia**: sprite SVG local de trazo lineal y consistente (por ejemplo, un subconjunto de Lucide, licencia ISC, copiado en el repositorio). Sin emojis como iconos ni mezcla de librerías. El saludo con emoji del mockup se mantiene solo como texto decorativo opcional.
- `RNF-047` (M) Animaciones sutiles y respeto de `prefers-reduced-motion`.
- `RNF-033` (M) Sin `innerHTML` con datos de la API ni con texto generado por IA; `textContent` o una función de escape única.
- `RNF-032` (M) Todas las llamadas pasan por `js/services/api.js` (`credentials: 'include'`, `X-CSRF-Token`, traducción de los códigos `PH-*` a mensajes).
- `RNF-037` (M) El menú se construye a partir de `features` de `/v1/auth/me` (rol y plan).
- `RNF-048` (M) Imágenes y fotografías **locales y optimizadas** (WebP, `loading="lazy"`), con licencia de uso; la CSP no permite imágenes remotas. Según el manual de marca: personas reales trabajando en pymes, sin estética de multinacional.

### 13.5 Logotipo e iconos de aplicación

- `RNF-049` (M) Se respetan proporciones, área de protección (altura de la "P") y tamaño mínimo digital de **140 px de ancho**; por debajo, solo el símbolo.
- `RNF-050b` (M) Usos: símbolo como icono de la app y en el sidebar contraído; logotipo completo en login y pantallas de marca. Prohibido deformar, recolorear, añadir sombras o colocarlo sobre fondos sin contraste.
- `RF-064` (M) Kit derivado para el MVP: favicon 16/32/48 px, iconos PWA 192 y 512 px (también *maskable*) y `apple-touch-icon` 180 px, generados a partir del símbolo.
- `P-13`: el manual exige un **logotipo vectorial (SVG)**. El MVP arranca con el PNG optimizado a 2× y lo sustituye por el SVG en cuanto exista.

### 13.6 Diferencias entre los mockups y el alcance del MVP

Los mockups son referencia visual. Cuando su contenido choca con este documento, **manda este documento**:

| En el mockup | En el MVP | Motivo |
|---|---|---|
| Factores de riesgo "Niveles altos de estrés" y "Baja satisfacción en encuestas" | Solo los factores F1–F7 del motor (§9.3) | Los datos individuales de bienestar no entran en el riesgo (§9.2) |
| "Baja participación en formación" como factor | Equivale a F4 (formación vencida); la participación en el check-in está excluida | `LEG-013` |
| "Retención estimada 93,7 %" y "podría reducir la rotación en un 30 %" | Comparación con la línea base real (`RF-037`), sin predicciones de impacto | No hay modelo que respalde esas cifras |
| Menú "Desempeño", acceso "Contratar personal", "Evaluación de desempeño" | No existen | Fuera del modelo de negocio y del MVP (§1.3) |
| Menú "Reportes" | Se sustituye por la exportación CSV de cada vista; módulo propio en fase posterior (`P-14`) | No está en el alcance |
| "Plan Pro", "LogiFast S.A.S." | Planes **Entrada** y **Completo**; empresa demo "Reparto Rápido Castilla S.L." | Coherencia con el TFM y con §18 |
| KPI "Bienestar promedio 92 %" | **Índice de bienestar 0–100** del equipo: media de ánimo, energía y (6 − estrés), escalada de 1–5 a 0–100, con el umbral de anonimato | Las escalas del check-in son 1–5 |
| Cursos "Liderazgo", "Comunicación", "Gestión del tiempo" | Catálogo del MVP (§10) | Contenido del sector |
| Fotografías de stock en cabeceras | Opcionales y locales (`RNF-048`) | CSP y manual de marca |

**Correspondencia de navegación (gestión):** Inicio · Bienestar · Formación · IA de RR. HH. (solo plan Completo) · Plantilla (empleados, equipos, incidencias) · Configuración (usuarios de RR. HH., línea base). **Empleado (navegación inferior):** Inicio · Check-in · Formación (con el asesor) · Más.

### 13.7 PWA y modo sin conexión

- `RF-060` (M) Manifest instalable (iconos de `RF-064`, `theme_color` `#22C55E`, `background_color` `#F6F8FA`).
- `RF-061` (M) Service worker que cachea la estructura de la app y los módulos abiertos.
- `RF-062` (M) Cola en IndexedDB para check-ins y avances hechos sin conexión, reenviados al recuperar la red; servidor idempotente.
- `RF-063` (S) Indicador "sin conexión — se enviará al recuperar la señal".
- `RNF-035` (C) Modo oscuro, cuando el manual de marca defina la variante negativa del logotipo.

### 13.8 Criterios de aceptación visual (Manual de Maquetación, §25)

Coincidencia visual general con el mockup; funciona en escritorio, tableta y móvil; sin scroll horizontal accidental; sidebar y navegación móvil funcionales; KPI reutilizables; gráficos dinámicos; estados de carga, vacío y error; botones con estados; accesibilidad básica; tokens centralizados; el logotipo mantiene proporciones; el mockup no se usa como imagen; componentes separados; datos separados de la presentación.

---

## 14. Seguridad

| ID | Prio | Requerimiento |
|---|---|---|
| `SEC-001` | M | CORS con lista blanca exacta (`https://pymehub.hermantorres.com`) y credenciales; nunca `*` |
| `SEC-002` | M | CSRF en toda petición que modifica datos |
| `SEC-003` | M | Solo HTTPS; HSTS en ambos subdominios |
| `SEC-004` | M | CSP (self + CDN de Chart.js y Google Fonts), `nosniff`, `Referrer-Policy`, `Permissions-Policy`, `frame-ancestors 'none'` |
| `SEC-005` | M | Límites: login 5/15 min; API 120/min por usuario; recálculo 1/10 min por tenant; IA según `IA-016` |
| `SEC-006` | M | Validación estricta de entrada; se rechazan campos no esperados |
| `SEC-007` | M | Comprobación de pertenencia al tenant en cada acceso por `id` |
| `SEC-008` | M | Subidas: MIME real con `finfo`, 5 MB máx., nombre aleatorio, fuera del webroot, servidas vía API |
| `SEC-009` | M | Errores sin trazas hacia el cliente; detalle en logs con `correlation_id` |
| `SEC-010` | M | `config.php` con permisos `640` |
| `SEC-011` | M | Instalador deshabilitado tras instalar |
| `SEC-012` | S | Recursos CDN con SRI y versión fijada |
| `SEC-013` | M | El texto devuelto por la IA se trata como no confiable: se valida por esquema, se escapa al mostrarlo y nunca se ejecuta ni se interpreta como instrucción |

---

## 15. Cumplimiento normativo

| ID | Prio | Requerimiento |
|---|---|---|
| `LEG-001` | M | Aviso de privacidad en el primer acceso del empleado, con aceptación registrada |
| `LEG-002` | M | Anonimato por umbral: agregados de bienestar solo con ≥ 5 personas con check-in en el periodo |
| `LEG-003` | M | Notas del check-in cifradas en reposo (`sodium_crypto_secretbox`), legibles solo por el propio empleado |
| `LEG-004` | M | Toda consulta a la ficha de riesgo de un empleado queda en `audit_log` |
| `LEG-005` | M | Supervisión humana: "Esta puntuación orienta una conversación, no decide nada sobre la persona". El sistema solo alerta; ninguna acción automática basada en el score |
| `LEG-005b` | M | **Encaje en el AI Act (TFM, apartado 2.4):** el motor de riesgo se basa en reglas definidas y documentadas por el equipo y podría quedar fuera de la definición de sistema de IA según las directrices de la Comisión Europea (2025); aun así se le aplican voluntariamente las garantías del alto riesgo: explicación por factor (`RF-034`), supervisión humana (`LEG-005`), registro de actividad (`LEG-004`) y revisión de sesgos (`LEG-015`). El asesor de formación es IA generativa de un tercero y cumple la transparencia del art. 50 (`LEG-011`) |
| `LEG-006` | M | Hoja técnica del modelo de riesgo con fuentes (§9.4) y limitaciones (§9.5) |
| `LEG-007` | M | Exportación de datos del empleado y supresión por el gerente |
| `LEG-008` | S | Check-ins individuales eliminados a los 12 meses; se conservan agregados |
| `LEG-009` | M | Solo datos ficticios en el entorno de la defensa |
| `LEG-010` | M | **Ningún dato personal se envía a proveedores de IA** (`IA-010`) |
| `LEG-011` | M | Contenido generado por IA identificado como tal (`IA-018`), en línea con las obligaciones de transparencia del AI Act |
| `LEG-012` | M | Solo se registran ausencias **injustificadas**; las bajas médicas y sus causas no se registran ni puntúan (serían datos de salud) |
| `LEG-013` | M | La participación en el check-in no interviene en ningún cálculo sobre la persona (`RF-056`) |
| `LEG-015` | M | **Revisión de sesgos**: `cli/bias_report.php` genera cada trimestre la distribución de niveles de riesgo por equipo, puesto, turno y tipo de contrato, y señala diferencias superiores a 20 puntos porcentuales para revisión humana. El resultado se anota en la hoja técnica del modelo |
| `LEG-016` | S | **Contrato de encargado del tratamiento**: no se pueden invitar empleados de una empresa sin `interlocutor.fecha_contrato_encargado` registrada (`PH-TENANT-003`). Los proveedores que tratan datos personales (Hostinger) constan como subencargados; Mistral no recibe datos personales (`LEG-010`) |
| `LEG-017` | M | **Baja de la empresa cliente** (TFM, apartado 9.8): exportación completa de sus datos (`RF-110`) y borrado definitivo a los 30 días de la baja, salvo otro plazo pactado en el contrato |
| `LEG-014` | M | Las preferencias formativas y el comentario libre del empleado solo los ve él; puede editarlos o borrarlos, y se incluyen en su exportación de datos (`LEG-007`) |
| `LEG-018` | M | **Textos legales públicos** (`#/legal/aviso`, `privacidad`, `terminos`, `cookies`, `skills`), accesibles con y sin sesión y enlazados desde la landing, el acceso, la invitación y «Más». Borradores marcados «pendiente de revisión jurídica» |
| `LEG-019` | M | **Titular parametrizado**: razón social, NIF, domicilio, datos registrales y contactos viven en los parámetros (`titular.*`). Mientras `titular.constituida` es falso, los textos indican «Sociedad en constitución» y el correo de contacto; nunca datos inventados |
| `LEG-020` | M | **Precios e IVA**: precios de los planes sin IVA con «+ IVA» junto a cada importe (también en el pago anual) y la nota «Precios para empresas. IVA (21 %) no incluido.»; todo pedido o factura desglosa base, IVA y total (`Parametros::importe`). Excepción: en Pyme Hub Skills la compra de un particular se muestra con IVA incluido; la posible exención de la formación la confirma la gestoría (`marketplace.formacion_exenta_iva`) |
| `LEG-021` | M | **Contrato de encargo del tratamiento**: plantilla en `docs/legal/CONTRATO_ENCARGO_TRATAMIENTO.md` (art. 28 RGPD), complementa `LEG-016` |
| `LEG-022` | S | **Versión aceptada**: cada cuenta guarda la versión de la privacidad aceptada (`usuario.privacidad_version`), que se incluye en su exportación |

---

## 16. Códigos de error semánticos

| Código | HTTP | Significado |
|---|---|---|
| `PH-AUTH-001` | 401 | Credenciales no válidas |
| `PH-AUTH-002` | 401 | Sesión caducada o inexistente |
| `PH-AUTH-003` | 403 | Token CSRF ausente o no válido |
| `PH-AUTH-004` | 429 | Cuenta bloqueada temporalmente |
| `PH-AUTH-005` | 410 | Invitación caducada o usada |
| `PH-PERM-001` | 403 | El rol no permite la acción |
| `PH-PLAN-001` | 403 | Funcionalidad no incluida en el plan contratado |
| `PH-PLAN-002` | 403 | Suscripción vencida tras el periodo de gracia: solo lectura hasta renovarla (`RF-115`) |
| `PH-PAY-001` | 503 | Pago online no disponible (Stripe sin configurar o con clave que no es de prueba) |
| `PH-PAY-002` | 502 | Error al conectar con la pasarela de pago |
| `PH-PAY-003` | 409 | La empresa ya tiene el pago online activo |
| `PH-PAY-004` | 409 | La empresa todavía no tiene cliente en la pasarela |
| `PH-PAY-005` | 400 | Webhook con firma no válida |
| `PH-SKL-002` | 409 | El formador del curso todavía no tiene la cuenta de cobro activa |
| `PH-SKL-003` | 409 | El curso ya está disponible (licencia de la empresa o compra propia) |
| `PH-TENANT-001` | 404 | Recurso inexistente o de otra empresa |
| `PH-TENANT-002` | 409 | Límite de empleados del plan alcanzado |
| `PH-TENANT-003` | 403 | Falta el contrato de encargado del tratamiento de la empresa |
| `PH-TENANT-004` | 403 | Empresa dada de baja: solo lectura hasta el borrado |
| `PH-VAL-001` | 422 | Datos de entrada no válidos |
| `PH-VAL-002` | 422 | CSV con filas erróneas |
| `PH-RATE-001` | 429 | Demasiadas peticiones |
| `PH-WB-001` | 200 | Agregado no disponible por umbral de anonimato |
| `PH-TRN-001` | 409 | Módulo no asignado a este empleado |
| `PH-TRN-002` | 409 | El empleado ya está inscrito en ese módulo |
| `PH-AI-001` | 200 | Proveedor de IA no disponible: se sirve el respaldo por reglas (aviso, no error) |
| `PH-AI-002` | 200 | Límite diario de consultas alcanzado: se sirve el respaldo por reglas |
| `PH-AI-003` | 200 | Respuesta de la IA no válida tras reintento: se sirve el respaldo por reglas |
| `PH-AI-004` | 422 | La petición contiene datos personales y no se envía |
| `PH-DEMO-001` | 403 | Acción no disponible en la demostración comercial |
| `PH-DEMO-002` | 403 | La demostración no está disponible (`demo.habilitada`) |
| `PH-SYS-001` | 500 | Error interno (con `correlation_id`) |
| `PH-SYS-002` | 503 | Base de datos no disponible |
| `PH-SYS-003` | 503 | Plataforma en mantenimiento programado |

---

## 17. Instalación, migraciones y operación

### 17.1 Asistente de instalación

- `RF-090` (M) Comprueba PHP, extensiones, permisos y `mod_rewrite`.
- `RF-091` (M) Pide las credenciales de la base de datos (servidor, puerto o socket Unix) y prueba la conexión. Si falla, explica la diferencia entre `'usuario'@'localhost'` (socket) y `'usuario'@'127.0.0.1'` (TCP).
  - Detecta el motor y exige **MariaDB 11.4.5 o superior** o **MySQL 8.4.0 o superior**. Si no se cumple, se detiene con un mensaje que indica el motor y la versión detectados y el mínimo exigido.
  - Comprueba que existe la collation de las migraciones (o su equivalente en MariaDB) y que la base está vacía. Solo entonces aplica las migraciones y guarda `db.engine` en `config.php`.
- `RF-092` (M) Genera `config.php` con claves aleatorias y crea el primer usuario `ADMIN` del interlocutor `PLATAFORMA`.
- `RF-093` (M) Paso opcional de IA: clave de Mistral, prueba de conexión y modo (`live` / `cache_first` / `offline`). Sin clave, queda en `offline`.
- `RF-094` (M) Opción de cargar datos de demostración (§18).
- `RF-095` (M) Escribe `installed.lock`; el asistente queda inaccesible.
- `RF-096` (M) `php cli/migrate.php` aplica las migraciones pendientes en orden y registra cada versión. Como en MySQL las sentencias DDL se confirman de forma implícita, una migración fallida se resuelve restaurando la copia tomada antes de actualizar (manual de despliegue, apartado 16).

### 17.2 Tareas programadas

| Tarea | Frecuencia |
|---|---|
| `cli/recalc_risk.php` | Diaria, 03:00 |
| `cli/wellbeing_alerts.php` | Lunes, 06:00 |
| `cli/purge_retention.php` (retención, sesiones, invitaciones, caché de IA caducada) | Diaria, 04:00 |
| `cli/backup.sh` (`mysqldump` + uploads, 14 copias) | Diaria, 02:00 |
| `cli/ai_warmup.php` (pregenera las respuestas de los perfiles de §12.6) | Manual, antes de la defensa |
| `cli/bias_report.php` (`LEG-015`) | Trimestral (día 1 de enero, abril, julio y octubre, 04:30) |
| `cli/tenant_purge.php` (borrado de empresas con baja de más de 30 días, `LEG-017`) | Diaria, 04:30 |
| `cli/subscription_status.php` (vigencia de las suscripciones y cantidad de empleados en Stripe, `RF-116`, `RF-119`) | Diaria, 00:15 |

### 17.3 Registro

- `RNF-040` (M) Log diario JSON por línea en `storage/logs/`. Nunca contraseñas, tokens, contenido de check-ins ni prompts de IA.

### 17.4 Nivel de servicio, monitorización, mantenimiento y baja (TFM, apartado 9.8)

| ID | Prio | Requerimiento |
|---|---|---|
| `RNF-060` | M | **Disponibilidad objetivo del 99,5 % mensual**, medida por un servicio externo de monitorización que consulta `/v1/health` cada 5 minutos y avisa al administrador si falla |
| `RNF-061` | M | `/v1/health` comprueba API, conexión a la base de datos, espacio en disco y antigüedad de la última copia (> 26 h = degradado), sin exponer datos internos |
| `RNF-062` | M | **Registro de errores con resumen**: el panel de administración muestra los errores 5xx de las últimas 24 h agrupados por código y ruta; las incidencias de cliente se atienden en < 4 h laborables (compromiso organizativo, fuera del software) |
| `RNF-063` | M | **Modo mantenimiento**: un indicador activado desde el panel o por archivo hace que la API responda `PH-SYS-003` y la app muestre un aviso; los mantenimientos programados se anuncian con un aviso visible desde 48 h antes |
| `RNF-064` | M | **Copias**: diarias, con rotación de 14 y una prueba de restauración documentada antes de la defensa |
| `RNF-065` | S | Las actualizaciones de seguridad del sistema operativo son responsabilidad del administrador del VPS y quedan fuera del proyecto, que no modifica la configuración global (`RNF-001`) |
| `RF-110` | M | **Exportación completa de una empresa**: archivo ZIP con CSV/JSON de empleados, equipos, incidencias, formación y agregados de bienestar (sin notas cifradas), generado por el administrador al solicitar la baja |
| `RF-111` | M | **Baja de una empresa**: estado `baja`, acceso de solo lectura para gerente y RR. HH. hasta el borrado definitivo (`LEG-017`) |
| `RF-112` | M | **Parámetros de la plataforma** (tabla `parametro`), editables por el administrador sin desplegar: titular, precios sin IVA, IVA, descuento del pago anual (0 % hasta que se acuerde), días de prueba y de gracia, días hasta el borrado tras la baja, comisión y exención de IVA de Skills y versión de los textos legales. Validados por tipo y límites; cambios auditados |
| `RF-114` | M | **Vigencia de la suscripción**: `PRUEBA` (los primeros `suscripcion.dias_prueba` días de una empresa nueva), `VIGENTE` (pagada hasta `periodo_fin`, o sin vencimiento), `GRACIA` (`suscripcion.dias_gracia` días tras `periodo_fin`, con aviso) y `VENCIDA`. Se calcula en cada petición a partir de las fechas |
| `RF-115` | M | **Suscripción vencida = solo lectura** (`PH-PLAN-002`): se puede consultar, exportar, renovar y cerrar sesión. La web avisa a la gerencia en prueba (7 últimos días), en gracia y vencida, y a toda la empresa cuando vence |
| `RF-116` | M | **Gestión por la plataforma**: ampliar la vigencia, fijar una fecha o dejarla sin vencimiento, con un pago manual opcional (transferencia) registrado con su IVA. Tarea diaria que guarda la vigencia y audita cada cambio |
| `RF-117` | M | **Pago online con Stripe en modo prueba**: Checkout de suscripción por empleado activo, precio sin IVA y tax rate exclusivo del IVA parametrizado, mensual o anual con el descuento parametrizado; resumen del pedido con base, IVA y total antes de pagar (`LEG-020`); aceptación de los términos registrada con su versión. Con claves de modo real el pago online queda desactivado |
| `RF-118` | M | **Webhooks firmados e idempotentes** (`Stripe-Signature`, tolerancia de 5 minutos, `evento_stripe`): el cobro amplía la vigencia hasta el fin del periodo pagado y activa la empresa; el impago queda registrado; la cancelación deja de renovar sin cortar el periodo pagado |
| `RF-119` | S | **Cantidad facturada**: sincronización diaria de los empleados activos con la suscripción de Stripe, sin prorrateo (se cobra en la siguiente renovación) |
| `RF-120` | M | **Facturas y pagos**: la gerencia ve sus pagos (base, IVA, total, periodo y factura de Stripe) y abre el portal del cliente; la plataforma ve todos los pagos y el total cobrado. Los pagos se incluyen en la exportación de la empresa (`RF-110`) |
| `RF-121` | M | **Formadores**: interlocutores de tipo `FORMADOR` con cuentas de perfil `FORMADOR`, dados de alta por la plataforma con invitación. Panel propio: sus cursos, cobro y ventas. No acceden a datos de ninguna empresa |
| `RF-122` | M | **Cursos de Skills**: el formador los escribe con el mismo editor y formato que los módulos (contenido, cuestionario y publicación), fija el precio de licencia para empresas (sin IVA) y el precio para particulares (IVA incluido) y los envía a revisión |
| `RF-123` | M | **Revisión de la plataforma**: aprobar, pedir cambios con motivo o retirar de la venta. Solo se venden cursos aprobados; cambiar el contenido de un curso aprobado lo devuelve a revisión sin afectar a las compras hechas |
| `RF-124` | M | **Compra de empresa** (gerencia): licencia para toda la plantilla, precio + IVA (`LEG-020`); el curso aparece entre los módulos de la empresa para asignarlo y para la autoinscripción |
| `RF-125` | M | **Compra personal** (persona empleada): precio con IVA incluido, aceptación de las condiciones de Skills y renuncia expresa al desistimiento al empezar (art. 103.m TRLGDCU); la persona queda inscrita. La empresa ve la inscripción como una autoinscripción, nunca el pago |
| `RF-126` | M | **Pago con Stripe Connect en modo prueba**: cargo con destino a la cuenta Express del formador; la plataforma retiene la comisión (`marketplace.comision_pct`, 20 %) sobre la base y el IVA; Stripe transfiere el resto al formador. Exención de IVA parametrizada (`marketplace.formacion_exenta_iva`, la confirma la gestoría) |
| `RF-127` | M | **Ventas y liquidaciones**: el formador ve sus ventas sin datos personales de quien compra (empresa o «Particular»); la plataforma ve ventas, comisión y lo liquidado por formador |
| `RF-128` | S | **Demo**: formador@pymehub.demo con tres cursos (dos a la venta y uno en revisión), la licencia de Castilla y una compra personal del conductor, todo ficticio; comprar está bloqueado (`PH-DEMO-001`) |
| `RF-113` | M | **Derecho de acceso y portabilidad del propio usuario**: «Descargar mis datos» (`GET /v1/me/data-export`), con la nota del check-in descifrada; no disponible en la demo |

---

### 17.5 Implementación de la operación (v0.8.0)

- **Exportación** (`RF-110`): ZIP generado en memoria sin depender de `ext-zip` (`Core/Zip`), con CSV en UTF-8 con BOM y separador `;`, protegidos contra la inyección de fórmulas. Bienestar solo agregado por equipo y semana con 5 o más personas (`LEG-002`); nunca notas. La descarga el administrador (`GET /v1/admin/tenants/{id}/export`) o el gerente (`GET /v1/company/export`), también con la empresa de baja.
- **Supresión** (`LEG-007`): `POST /v1/employees/{id}/erase` con `{"confirmacion":"SUPRIMIR"}`, solo para personas ya de baja. Borra los datos personales y anonimiza la cuenta; conserva fechas, motivo, puesto y equipo para los indicadores de rotación.
- **Baja y borrado** (`RF-111`, `LEG-017`): `estado = BAJA` fija `fecha_baja`; `cli/tenant_purge.php` (diario, 04:30) borra las empresas con más de `operacion.dias_borrado_tras_baja` días de baja con `BorradoService`, el mismo que usa el reinicio de la demo. La auditoría se conserva anonimizada.
- **Mantenimiento** (`RNF-063`): `storage/maintenance.flag` (activo) y `storage/maintenance.json` (mensaje y ventana programada), gestionados desde el panel; `GET /v1/public/status` publica la ventana desde 48 h antes y la web muestra el aviso a todas las personas usuarias.
- **Errores** (`RNF-062`): los 5xx se registran con código, método y ruta; `GET /v1/admin/errors` los agrupa sin datos personales.
- **Restauración** (`RNF-064`): `cli/restore_test.sh` restaura la última copia en una base temporal, compara filas por tabla y deja un informe.
- **Demo**: exportar, descargar datos y suprimir devuelven `PH-DEMO-001`.

### 17.6 Implementación de la vigencia y los pagos (v0.9.0)

- **Datos** (migración `0009_vigencia_pagos`):
  - `suscripcion.vigencia`, `periodo_fin`, `empleados_facturados`, `stripe_subscription_id`, `stripe_item_id`, `cancelar_al_final`; la fila nueva de un cambio de plan hereda todos;
  - `interlocutor.stripe_customer_id` y la aceptación de los términos (`terminos_version`, `terminos_aceptados_en`, `terminos_aceptados_por`);
  - tablas `pago` y `evento_stripe`.
- **Vigencia** (`Core/Vigencia`): estado efectivo por fechas en hora de España. `Auth::require` aplica `PH-PLAN-002`. `GET /v1/auth/me` incluye `vigencia` para los avisos de la web.
- **Stripe** (`Core/Stripe`, sin SDK):
  - llamadas con `Stripe-Version` fija;
  - verificación de firma HMAC-SHA256 en tiempo constante;
  - solo con claves `sk_test_`.
- **Webhooks** (`PagoService`): se leen de forma defensiva, porque los campos cambian entre versiones de la API (p. ej. `invoice.subscription` → `invoice.parent.subscription_details.subscription`). Si el proceso falla, se olvida el evento para que Stripe lo reintente.
- **Portal del cliente**: se configura en el panel de Stripe con tarjeta, facturas y cancelación al final del periodo. Los cambios de plan los gestiona la plataforma.
- **Pruebas**: `tests/stripe_mock.php` simula la API de Stripe para la prueba de humo en local (variables `SMOKE_STRIPE_WHSEC` y `SMOKE_STRIPE_MOCK`). La prueba real en el servidor se hace con las claves de prueba y la tarjeta 4242 4242 4242 4242.
- **Configuración**: `cli/stripe_setup.php` escribe las claves en `config.php` sin mostrarlas en pantalla.

### 17.7 Implementación de Pyme Hub Skills (v0.10.0) y alcance diferido

- **Datos** (migración `0010_skills`):
  - `curso_skills`: la ficha comercial y su revisión;
  - `compra_skills`: compras de empresa o de persona, con base, IVA, total, comisión e importe del formador; FK compuesta `(usuario_id, interlocutor_id)`;
  - `interlocutor.stripe_cuenta_id` y `stripe_cuenta_lista`.
- **Visibilidad** (`ModuloRepository`): una empresa ve el catálogo global, sus módulos propios y los cursos de Skills con licencia pagada. Una persona ve, además, los que compró ella. `propio` significa «del interlocutor que consulta», de modo que el formador edita los suyos.
- **Webhooks**: `checkout.session.completed` con `metadata.compra_skills_id` marca la compra como pagada e inscribe a la persona; `checkout.session.expired` la marca como fallida; `account.updated` activa la cuenta del formador. También se consulta a Stripe al abrir el panel.
- **Pruebas**: el simulador `tests/stripe_mock.php` cubre las cuentas conectadas, los enlaces de alta y los pagos únicos con destino.
- **Diferido** (fuera del MVP, documentado):
  - valoraciones de los cursos;
  - reembolsos y disputas: se gestionan en el panel de Stripe;
  - vídeo externo;
  - comunicación a Hacienda de las ventas de terceros (DAC7);
  - facturación en nombre del formador: autofacturación o facturas del formador;
  - cobros reales.

## 18. Datos de demostración

- `RF-100` (M) Dos empresas ficticias para enseñar los planes:
  - **"Reparto Rápido Castilla S.L."**, plan Completo, 48 empleados, 4 equipos (Zona Norte, Zona Sur, Nocturno, Almacén).
  - **"Mensajería Tajo S.L."**, plan Entrada, 15 empleados, 2 equipos.
- Antigüedades mixtas (varias < 90 días), contratos temporales y ETT, turnos rotativos y nocturnos; 6 meses de incidencias con reconocimientos incluidos; 60 días de check-ins con el equipo "Nocturno" empeorando en estrés; 15 módulos globales; preferencias precargadas para `conductor@` (§12.6) y un historial de consultas al asesor para que la vista de demanda formativa tenga datos.
- Usuarios: `admin@`, `gerente@` y `rrhh@` (Castilla), `gerente2@` (Tajo) y `conductor@` (Castilla). Las credenciales se entregan al tribunal por separado.
- Línea base cargada para Castilla, para que el panel muestre la comparación con los 12 meses anteriores.
- Resultado esperado como `rrhh@` o `gerente@` de Castilla: 5–8 empleados en riesgo alto con factores y fuentes, alerta de bienestar en "Nocturno" y demanda formativa agregada. Como `conductor@`: el asesor recomienda 3–5 módulos con su motivo y la inscripción funciona con un toque. Como gerente de Tajo: Bienestar y Upskilling completos, sección de riesgo como vista informativa del plan Completo.
- `RF-101` (M) Los datos de demo permiten tomar las **cinco capturas del Anexo D del TFM** (Figuras D2 a D6): panel del gerente con indicadores y alertas, ficha de empleado con el riesgo explicado, check-in en el móvil, micro-formación con cuestionario y asesor de formación.

---

### 18.1 Demo comercial y QR del folleto (v0.7.0)

Requerimiento completo en `docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md` (`DMO-001` a `DMO-037`, `DMO-NF1` a `DMO-NF6`).

- **Ruta estable del QR:** `https://pymehub.hermantorres.com/demo` responde 302 a la landing en modo demo. La regla está en el `.htaccess` de la web.
- **Landing en modo demo:** franja comercial y panel «Pruebe Pyme Hub» con los usuarios de la empresa de ejemplo. «Entrar como …» abre el login de siempre con el correo ya escrito.
- **Separación de la operativa:** misma instalación y misma base de datos. Las empresas y personas de la demo llevan `es_demo = 1` (migración `0007`); el reinicio nocturno (03:30) solo regenera lo marcado.
- **Usuarios demo:** contraseña pública `demo.password` y restricciones `PH-DEMO-001`. El bloqueo es por IP, con 20 fallos en 15 minutos, y se admiten sesiones concurrentes. El interruptor `demo.habilitada` responde `PH-DEMO-002` sin desplegar.
- **QR de instalación (v0.7.1):** `https://pymehub.hermantorres.com/app` responde 302 a la página «Instala Pyme Hub» (`#/instalar`), que se adapta al navegador:
  - botón nativo de instalación donde se emite `beforeinstallprompt` (Chrome, Edge o Samsung Internet en Android y escritorio);
  - en iPhone y iPad, los pasos de «Añadir a pantalla de inicio»;
  - si se abre dentro de otra app, un aviso para pasar al navegador;
  - si ya está instalada, «Abrir Pyme Hub».
  Las visitas (campaña `instalar_app`) y las instalaciones completadas (evento `appinstalled`) se cuentan aparte.
- **Métricas sin datos personales:** visitas de campaña sin cookies, IP ni User-Agent (`POST /v1/public/visits`, tabla `visita_campania`), inicios de sesión `LOGIN_DEMO` en la auditoría, y la tarjeta «QR del folleto» del administrador (`GET /v1/admin/campaign-metrics`).

## 19. Requerimientos no funcionales adicionales

| ID | Prio | Requerimiento |
|---|---|---|
| `RNF-050` | M | API < 300 ms (p95) con 250 empleados por tenant, excluidas las llamadas de IA |
| `RNF-051` | M | Primera carga de la PWA < 3 s en 4G simulada; < 300 KB sin fuentes |
| `RNF-052` | M | Dos últimas versiones de Chrome, Safari iOS, Firefox y Edge |
| `RNF-053` | M | Todo en español |
| `RNF-054` | M | Índices en `tenant_id`, `employee_id`, `date` y combinaciones de filtros |
| `RNF-055` | S | `tests/smoke_test.php`: login, CRUD, check-in, formación, aislamiento entre tenants, bloqueo por plan, permisos de RR. HH., `PayloadGuard` |
| `RNF-056` | M | Una petición de IA en modo `live` responde en < 15 s; en `cache_first` u `offline`, < 300 ms |

---

## 20. Criterios de aceptación del MVP

En `https://pymehub.hermantorres.com`:

1. Instalación desde cero con el asistente, sin tocar otros sitios del VPS.
2. El administrador crea una empresa de cada plan con su gerente, su ciclo de cobro y la fecha del contrato de encargado.
3. El gerente crea equipos y una cuenta de RR. HH.; RR. HH. importa empleados por CSV e invita a un conductor.
4. El conductor instala la PWA, hace el check-in **sin conexión** y este llega al recuperar la red.
5. El conductor completa un micro-módulo y aprueba el cuestionario.
6. RR. HH. de plan Completo ve el ranking de riesgo con factores y fuentes y la alerta de bienestar de un equipo, **sin acceso a ningún check-in individual**.
7. El gerente de plan Entrada recibe `PH-PLAN-001` al llamar directamente a un endpoint de riesgo.
8. El conductor configura sus preferencias, recibe 3–5 recomendaciones del catálogo con su motivo y la etiqueta de IA, y se inscribe en una con un toque.
9. Con el proveedor desconectado, el asesor sigue respondiendo (caché de la demo o respaldo por reglas) sin mostrar errores.
10. Un comentario libre con un email o el nombre de un compañero es rechazado con `PH-AI-004`, y ninguna recomendación contiene un ID fuera del catálogo.
11. Un gerente de otra empresa no accede a ningún dato ajeno.
12. Cabeceras con nota **A** en securityheaders.com y SSL con **A** en SSL Labs.
13. RR. HH. recibe `PH-PERM-001` al intentar crear cuentas o suprimir datos de un empleado.
14. El monitor externo registra `/v1/health` y el modo mantenimiento muestra el aviso en la app.
15. La exportación completa de una empresa de prueba se genera y su baja la deja en solo lectura.
16. Las cinco pantallas del Anexo D se pueden capturar con los datos de demostración.
17. El instalador acepta MariaDB 11.8 y MySQL 8.4, y rechaza versiones por debajo del mínimo (por ejemplo, MariaDB 10.11). Las migraciones se ejecutan sin errores en los dos motores.
18. `pymehub_web` desplegado en otro servidor estático funciona igual apuntando a la misma API (prueba de independencia de repositorios).
19. Se cumplen los criterios de aceptación visual de §13.8.

---

## 21. Plan de construcción del MVP

Este plan cubre solo la construcción del MVP durante el TFM. El cronograma del proyecto empresarial (versión de producción en el mes 4, pilotos con 4 PYMEs en los meses 4 a 6, lanzamiento al final del mes 6, Pyme Hub Skills desde el mes 13 y recalibración del motor de riesgo en los meses 17 y 18) está en el **Anexo F del TFM**.

| Fase | Contenido | Entregable verificable |
|---|---|---|
| 0. Preparación | Resolver `P-11` (motor de base de datos), crear los dos repositorios, `README`, `.gitignore`, OpenAPI inicial | Repositorios creados; base de datos operativa (MariaDB 11.8 en producción) |
| 1. Núcleo | `Core/`, router, errores, instalador, migración inicial, vhosts, SSL | `GET /v1/health` en producción |
| 2. Identidad | Login, sesión, CSRF, cuatro roles, tenants, planes, invitaciones | Login de los cuatro roles; `PlanGate`; criterio 13 |
| 3. Organización | Equipos, empleados, CSV, incidencias | Gerente gestiona su plantilla |
| 4. Bienestar | Check-in, historial, agregado con umbral, alertas | Criterio 6 (parte bienestar) |
| 5. Upskilling | Catálogo, asignación, reproductor, cuestionario, recomendaciones | Criterio 5 |
| 6. Asesor de formación | Preferencias, `AiGateway`, `PayloadGuard`, respaldo por reglas, caché, modos, autoinscripción | Criterios 8, 9 y 10 |
| 7. RRHH | Motor v1.1, cron, ranking, metodología con fuentes, línea base, informe de sesgos, dashboard | Criterios 6 y 7 |
| 8. Frontend y PWA | Tokens, AppShell, componentes, vistas, service worker, cola offline | Criterios 4 y 19 |
| 9. Operación | `/v1/health`, monitor externo, modo mantenimiento, exportación y baja de empresa, prueba de restauración | Criterios 14 y 15 |
| 10. Cierre | Datos demo, cabeceras, smoke test, OpenAPI, hoja técnica, `ai_warmup`, capturas del Anexo D | Criterios 1–19 |

---

## 22. Puntos resueltos y pendientes

### 22.1 Resueltos

| ID | Resolución |
|---|---|
| `P-01` | Subdominios `pymehub.` y `api.pymehub.` sobre `hermantorres.com` |
| `P-02` | Sesión de empleado de 7 días |
| `P-03` | Reglas recalibradas con fuentes documentadas (§9.3–9.4) |
| `P-04` | Plan Entrada = Bienestar + Upskilling |
| `P-05` | IA generativa incluida, con Mistral gratuito y respaldo offline (§12) |
| `P-06` | TFM alineado en sus versiones 3 y 4 (arquitectura, MVP, Anexo D y cronograma en el Anexo F); lo que falta está en §22.3 |
| `P-07` | La cuenta de Mistral la crea y custodia Herman Adrian Torres (herman.adrian.torres@gmail.com). La clave se introduce solo en el instalador (`RF-093`) y nunca se comparte por chat ni se sube a ningún repositorio |
| `P-09` | IA acotada a una única funcionalidad: Asesor de formación para el empleado; perfiles de demo en §12.6 |
| `P-11` | El VPS tiene MariaDB 11.8.6 compartido con otras aplicaciones: Pyme Hub se instala sobre él (`DEC-05` v0.9, §3.5); la API es compatible con MariaDB 11.8 y MySQL 8.4 |

### 22.2 Pendientes

| ID | Pregunta | Estado |
|---|---|---|
| `P-08` | ¿Los resultados de las encuestas de campo del TFM confirman o matizan F1–F7? | Aplazado |
| `P-10` | Validar la división de permisos entre gerente y RR. HH. propuesta en §6.1 | Abierto |
| `P-12` | Color del sidebar: el manual de marca dice "navegación carbón" (#1F2937) y el de maquetación y los mockups usan navy (#08263B). Propuesta: navy, que es el aprobado en los mockups | Abierto |
| `P-13` | Logotipo vectorial (SVG) y kit de marca del apartado 10 del manual | Abierto |
| `P-14` | ¿Se quiere un módulo "Reportes" en el MVP o basta la exportación CSV por vista? | Abierto |
| `P-15` | ¿En qué plataforma se alojan los repositorios (GitHub, GitLab…) y quién del equipo tiene acceso? | Abierto |

### 22.3 Cambios que hay que trasladar al documento del TFM

| Apartado del TFM | Qué ajustar |
|---|---|
| 9.7 y Anexo D (introducción) | Citar el documento de requisitos en su **versión 0.5** (hoy dice 0.4) |
| Anexo D, D.7 | Añadir el usuario de RR. HH. (`rrhh@`) y la empresa de plan Entrada («Mensajería Tajo S.L.»); hoy menciona tres usuarios y una sola empresa |
| Anexo D, D.5 | Añadir la monitorización de disponibilidad, el modo mantenimiento y la exportación y baja de la empresa cliente |
| Anexo D, D.6 (Tabla D3) | Añadir las filas de operación (`RNF-060` a `RNF-065`, `RF-110` y `RF-111`), cumplimiento (`LEG-015` a `LEG-017`) y línea base (`RF-037`); el rango de instalación pasa a `RF-090` a `RF-096` |
| 2.4 AI Act | Remitir a `LEG-015` como la revisión de sesgos que el texto ya promete |
| 9.8 Políticas | Indicar que la disponibilidad, la monitorización, el mantenimiento y la baja están implementados en el MVP (§17.4) |
| 3.4 (Hito 2), 9.3, 9.7, Anexo D (Figura D1 y D.2) y glosario 16.1 | Pueden mantener **MariaDB** como motor (producción usa MariaDB 11.8, `DEC-05` v0.9); conviene añadir que la API también es compatible con MySQL 8.4 |
| 9.3 y 9.7 | Añadir que backend y frontend están en **repositorios separados** y que el frontend solo consume la API |
| Anexo D, D.8 | Las capturas deben tomarse con la interfaz final ajustada a §13, no con los mockups |
