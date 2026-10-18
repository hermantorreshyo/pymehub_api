# Changelog · pymehub_api

Formato basado en *Keep a Changelog*; versiones SemVer.

## [0.11.2] · 2026-10-18

**Corrección: error 500 con una sesión ilegible.** Sin migraciones ni cambios de configuración.

### Núcleo
- `Core/Session`: si el archivo de la sesión de la cookie no se puede leer (propietario cambiado en un despliegue o contenido corrupto), se descarta y se abre una sesión nueva. El usuario vuelve a entrar, como con una sesión caducada (`RF-003`), en vez de recibir un 500 en `/v1/auth/me`.
- El aviso que queda en el registro solo dice el motivo («Permission denied (13)»); ya no incluye la ruta del archivo, que contenía el identificador de sesión.

### Operación
- Diagnóstico: nueva comprobación «Propietario de los archivos de storage». Señala los archivos de sesiones, registros, copias, caché y subidas que la API no puede escribir, y da el comando que lo arregla. Es un fallo si afecta a las copias; si no, un aviso.
- Causa en producción: el `chown -R root:www-data $API` de los despliegues alcanzaba `storage/`.

### Pruebas
- `tests/sesion_ilegible_test.php`: abre una sesión con un archivo corrupto y comprueba que se crea una nueva sin error (con el código anterior, falla). Prueba de humo: 276 correctas con el simulador de Stripe (MariaDB 11.8) y 254 sin Stripe (MySQL).

### Documentación
- Manual 02: tras el `chown -R` de cada actualización se devuelve `storage/` a `www-data`; también entra en la tabla de problemas frecuentes.
- Manual 04: la nueva comprobación.

## [0.11.1] · 2026-10-18

**Corrección: el reinicio nocturno de la demo se detenía.** Sin migraciones ni cambios de configuración obligatorios.

### Operación
- `cli/demo_seed.php`:
  - una `demo.password_admin` no válida en `config.php` (vacía, el valor de ejemplo «CAMBIAR» o con menos de 10 caracteres) ya no detiene la demo; solo deja `admin@pymehub.demo` sin cambios y avisa;
  - en producción, el valor de ejemplo hacía fallar el reinicio de las 03:30 cada noche, de modo que la demo no se regeneraba y `formador@` no existía. Lo detectó el diagnóstico;
  - con `--password-admin` explícito se sigue exigiendo el mínimo de 10 caracteres.
- La API quita la cabecera `X-Powered-By`, que revelaba la versión de PHP.

### Diagnóstico
- La versión de la web se compara por versión mayor y menor: un parche puede afectar a un solo repositorio, como este.
- Contraseña de `admin@pymehub.demo`: distingue «sin definir», «valor de ejemplo» y «cuenta inexistente».
- La versión de Apache en la cabecera `Server` pasa a ser informativa: se oculta con `ServerTokens Prod`, que es configuración global del VPS y queda fuera del proyecto (`RNF-001`).

## [0.11.0] · 2026-10-18

**Diagnóstico del despliegue** (`RNF-068`). Sin migraciones, sin cambios en `config.php` y sin tareas programadas nuevas.

### Operación
- `Services/DiagnosticoService`: más de 40 comprobaciones de solo lectura, sin secretos, cada una con estado (OK, AVISO, ERROR o INFO) y «qué hacer». Comprueba:
  - versión y migraciones, y que la web esté en la misma versión;
  - servidor: PHP, extensiones, base de datos, disco, permisos e instalador;
  - copias y prueba de restauración;
  - las ocho tareas programadas y su ejecución;
  - seguridad: HTTPS, cookie, clave de cifrado, CORS y cabeceras de API y web;
  - operación;
  - demo: usuarios, reinicio, `/demo` y `/app`;
  - parámetros y textos legales;
  - Stripe;
  - asesor con IA;
  - datos.
- `GET /v1/admin/diagnostics` (ADMIN, 20 por hora).
- `cli/diagnostico.php [--solo-problemas] [--sin-red]`, que sale con código 1 si hay errores.

### Pruebas y documentación
- Prueba de humo, sin fallos:
  - 276 comprobaciones con el simulador de Stripe (MariaDB 11.8.3);
  - 254 sin Stripe (MySQL).
- Incluye una comprobación de que el informe no contiene secretos.
- Manual nuevo **04 · Diagnóstico**, para personas no técnicas y para la terminal.
- OpenAPI 0.11.0, Postman (carpeta 21), requisitos v0.9.10 y listas de comprobación de los manuales 01 y 02.

## [0.10.0] · 2026-10-18

**Marketplace Pyme Hub Skills en modo prueba (fase 9d).**
- **Migración nueva:** `0010_skills`.
- **Configuración:** sin cambios en `config.php` (usa el mismo bloque `stripe`).
- **Tareas programadas:** ninguna nueva.

### Base de datos
- `0010_skills`:
  - tablas `curso_skills` (precios y revisión) y `compra_skills` (empresa o persona, con base, IVA, total, comisión e importe del formador);
  - `interlocutor.stripe_cuenta_id` y `stripe_cuenta_lista`.
- Probada en MariaDB 11.8.3 y MySQL.

### Núcleo
- `SkillsService`:
  - importes: empresa + IVA y persona con IVA incluido;
  - comisión parametrizada sobre la base y exención de IVA parametrizada;
  - catálogo, ficha del formador, revisión y compras con Stripe Checkout (cargo con destino);
  - inscripción automática en la compra personal;
  - ventas y alta en Stripe Connect Express.
- `ModuloRepository`: la empresa ve los cursos con licencia y la persona los que compró; `propio` pasa a significar «del interlocutor que consulta» y aparece el nuevo indicador `skills`.
- `PagoService`:
  - tax rate inclusivo para consumidores;
  - webhooks `checkout.session.completed` y `expired` de las compras de Skills, y `account.updated`.
- Los cursos del formador reutilizan los endpoints del editor de módulos.

### Endpoints
- `GET /v1/skills/catalog`.
- `GET` y `POST /v1/skills/purchases`.
- `/v1/skills/my-courses` (listado, alta, ficha, edición, publicación, `listing` y `submit`).
- `GET /v1/skills/sales`.
- `GET` y `POST /v1/skills/connect`.
- `GET` y `POST /v1/admin/trainers`.
- `/v1/admin/skills/courses` (listado, detalle y `review`).
- `GET /v1/admin/skills/sales`.
- Errores nuevos: `PH-SKL-002` y `PH-SKL-003`.

### Demo
- `formador@pymehub.demo` (Formación Vial Meseta): tres cursos, dos a la venta y uno en revisión.
- La licencia de Castilla y la compra personal del conductor, ambas ficticias.
- Comprar y activar el cobro están bloqueados.
- El reinicio de la demo también borra el formador demo.

### Pruebas y documentación
- Prueba de humo en MariaDB 11.8.3 y MySQL, sin fallos:
  - 273 comprobaciones con el simulador de Stripe en los dos motores (Connect y pagos únicos);
  - 251 en MySQL sin Stripe.
- OpenAPI 0.10.0, Postman (carpeta 20).
- Requisitos v0.9.9 (`RF-121` a `RF-128`, §16, §17.7 con el alcance diferido) y `MODELO_DATOS`.
- Manual 03 con Stripe Connect; manuales 01 y 02 y prompt maestro actualizados.

## [0.9.0] · 2026-10-17

**Vigencia de suscripciones (fase 9b) y pagos con Stripe en modo prueba (fase 9c).**
- **Migración nueva:** `0009_vigencia_pagos`.
- **`config.php`:** bloque `stripe` opcional; se rellena con `cli/stripe_setup.php`.
- **Tarea programada nueva:** `cli/subscription_status.php`, a las 00:15.

### Base de datos
- `0009_vigencia_pagos`:
  - en `suscripcion`: `vigencia` (`PRUEBA`, `VIGENTE`, `GRACIA`, `VENCIDA`), `periodo_fin`, `empleados_facturados`, `stripe_subscription_id`, `stripe_item_id` y `cancelar_al_final`;
  - en `interlocutor`: `stripe_customer_id` y la aceptación de los términos;
  - tablas `pago` y `evento_stripe`.
- Las empresas existentes quedan vigentes sin vencimiento.
- Probada en MariaDB 11.8.3 y MySQL.

### Núcleo
- `Core/Vigencia` (`RF-114`): estado efectivo por fechas en hora de España, con los días de prueba y de gracia parametrizados.
- `Auth::require` aplica `PH-PLAN-002` con la suscripción vencida (`RF-115`): consultar, exportar, renovar y cerrar sesión siguen disponibles. `GET /v1/auth/me` incluye `vigencia`.
- `Core/Stripe`: cliente sin SDK y verificación de la firma de los webhooks. Solo admite claves `sk_test_`.
- `SuscripcionService`:
  - los cambios de plan heredan la vigencia y el enlace con Stripe;
  - la plataforma puede fijar la vigencia;
  - la tarea diaria guarda los cambios y los audita.
- `PagoService`:
  - Checkout por empleado activo con IVA exclusivo y descuento anual (`RF-117`);
  - portal del cliente;
  - webhooks idempotentes (`RF-118`);
  - sincronización diaria de la cantidad (`RF-119`).
- Las empresas nuevas empiezan en prueba gratuita; `sin_prueba` deja el acuerdo sin vencimiento.
- Validador: nueva regla `numeric`.
- La exportación de la empresa incluye `pagos.csv`.

### Endpoints
- `GET /v1/billing/subscription` y `GET /v1/billing/invoices`.
- `POST /v1/billing/checkout` y `POST /v1/billing/portal`.
- `POST /v1/stripe/webhook`.
- `GET /v1/admin/payments` y `PUT /v1/admin/tenants/{id}/validity` (`RF-116`, `RF-120`).
- Errores nuevos: `PH-PLAN-002` y `PH-PAY-001` a `PH-PAY-005`.

### Demo
- Castilla: vigente con tres mensualidades ficticias.
- Tajo: en prueba con 12 días por delante.
- Contratar no está disponible en la demo (`PH-DEMO-001`).

### Operación
- `cli/subscription_status.php` (diaria).
- `cli/stripe_setup.php`: escribe las claves de prueba en `config.php` sin mostrarlas, con copia del archivo anterior.
- `tests/stripe_mock.php`: simulador local de Stripe para la prueba de humo.

### Pruebas y documentación
- Prueba de humo, sin fallos en ninguno de los dos motores:
  - MariaDB 11.8.3 con el simulador de Stripe: 242 comprobaciones;
  - MySQL: 242 con el simulador y 230 sin Stripe.
- OpenAPI 0.9.0, Postman (carpeta 19).
- Requisitos v0.9.8 (`RF-114` a `RF-120`, §16, §17.2, §17.6) y `MODELO_DATOS`.
- Manual nuevo **03 · Pagos con Stripe en modo prueba**; manuales 01 y 02 actualizados.

## [0.8.2] · 2026-10-17

**Prueba de restauración con root protegido por contraseña.** Sin migraciones ni cambios en `config.php`.

### Operación
- `cli/restore_test.sh` (`RNF-064`) ya no da `1045 Access denied for user 'root'@'localhost' (using password: NO)` cuando root tiene contraseña, como en este VPS:
  - si root no entra por socket, pide la contraseña una vez y sin eco;
  - la guarda solo en un archivo temporal 600 (`--defaults-file`), que se borra al terminar;
  - `PH_DB_ADMIN=usuario` permite usar otro administrador;
  - sin terminal, termina con un mensaje claro.
- Probado en MariaDB 11.8 con contraseña correcta, errónea y sin terminal.
- Manuales 01 y 02 actualizados.

 · 2026-10-17

**Corrección de la copia de seguridad.** Sin migraciones ni cambios en `config.php`.

### Operación
- `cli/backup.sh` usa `--defaults-file` en lugar de `--defaults-extra-file` (`RNF-064`). En el VPS compartido, otro archivo de opciones, como `/var/www/.my.cnf` de `www-data` o un `[client]` de `/etc/mysql`, sobrescribía la contraseña de `config.php`. El resultado era `1045 Access denied for user 'pymehub_user'@'localhost' (using password: YES)`. Reproducido y corregido en MariaDB 11.8.
- Manual 01: el error y su causa, en «Problemas frecuentes».

 · 2026-10-17

**Operación (fase 9) y parámetros, textos legales e IVA (fase 9a).** **Migración nueva: `0008_parametros_legal`.** Sin cambios en `config.php`. Nueva tarea programada: `cli/tenant_purge.php` (04:30).

### Base de datos
- `0008_parametros_legal`:
  - tabla `parametro` (`clave`, `valor` JSON, `actualizado_en`, `actualizado_por`) con los valores iniciales:
    - titular pendiente de constitución, con el contacto info@hyo.company;
    - 15 y 29 € sin IVA; IVA 21 %; descuento anual 0 %;
    - 30 días de prueba y 7 de gracia; borrado 30 días tras la baja;
    - comisión de Skills del 20 %, exención de IVA sin confirmar;
    - versión legal 2026-10-17.
  - `usuario.privacidad_version`.
  - Probada en MariaDB 11.8.3 y MySQL.

### Núcleo
- `Core/Parametros` (`RF-112`): definición con tipo, grupo y límites; caché; validación por clave. Incluye `importe()`, que calcula base, descuento anual, IVA y total. `Plan::PRECIO` desaparece: los precios salen de los parámetros.
- `Core/Zip`: ZIP en memoria sin `ext-zip`, más CSV en UTF-8 con BOM y protegido contra la inyección de fórmulas.
- `Core/Mantenimiento` (`RNF-063`): mantenimiento activo con `storage/maintenance.flag`; ventana programada y mensaje en `storage/maintenance.json`. Salud, rutas públicas, acceso y plataforma siguen respondiendo.
- `Services/BorradoService`: borrado de empresas y personas en todas las tablas, con la auditoría anonimizada. Lo comparten la purga de empresas y el reinicio de la demo.
- `Services/ExportacionService` (`RF-110`, `RF-113`): ZIP de la empresa y JSON de la persona.
- Los errores 5xx se registran con código, método y ruta (`RNF-062`).
- Al aceptar la invitación se guarda la versión de la privacidad aceptada (`LEG-022`).

### Endpoints
- `GET /v1/admin/tenants/{id}/export` y `GET /v1/company/export` (`RF-110`).
- `GET /v1/me/data-export` (`RF-113`, RGPD art. 15 y 20).
- `POST /v1/employees/{id}/erase` (`LEG-007`): `{"confirmacion":"SUPRIMIR"}`, solo para personas de baja.
- `PATCH /v1/admin/tenants/{id}` con `estado = BAJA` (`RF-111`, `LEG-017`): fecha de baja y `fecha_borrado`; reactivar la anula. `GET /v1/auth/me` incluye `fecha_borrado`.
- `GET /v1/public/status`, `GET`/`PUT /v1/admin/maintenance` (`RNF-063`).
- `GET /v1/admin/errors` (`RNF-062`).
- `GET /v1/public/params`, con ejemplos de importe por plan y ciclo, y `GET`/`PUT /v1/admin/params` (`RF-112`, `LEG-019`, `LEG-020`).
- Exportar, descargar datos y suprimir no están disponibles en la demo (`PH-DEMO-001`).

### Operación
- `cli/tenant_purge.php [--simular]`: borra las empresas con más de 30 días de baja (`LEG-017`).
- `cli/restore_test.sh` (`RNF-064`): restaura la última copia en una base temporal, compara las filas por tabla y deja un informe en `storage/logs/restauracion-*.md`.

### Pruebas y documentación
- Prueba de humo: **211 comprobaciones**, correctas en MariaDB 11.8.3 y MySQL. Cubren exportación, RGPD, baja de empresa, mantenimiento, errores, parámetros, importes con IVA y descuento, validación y CSRF.
- OpenAPI 0.8.0, que además corrige dos descripciones que hacían inválido el YAML.
- Colección de Postman: carpetas 17 y 18.
- Requisitos v0.9.7 (`LEG-018` a `LEG-022`, `RF-112`, `RF-113`, §17.5).
- `MODELO_DATOS` actualizado.
- Manuales 01 y 02: cron 04:30, prueba de restauración, operación desde el panel y datos del titular.
- Plantilla `docs/legal/CONTRATO_ENCARGO_TRATAMIENTO.md` (`LEG-021`).

## [0.7.1] · 2026-10-17

**QR de instalación de la app.** Sin migraciones ni cambios en `config.php`.

### Endpoints
- `POST /v1/public/visits`: la campaña `instalar_app` es de serie, como `folleto_comercial` (`Demo::CAMPANIAS_BASE`). `config.php → campaigns` solo añade campañas adicionales. Con `medium = instalacion` se registra una instalación completada.
- `GET /v1/admin/campaign-metrics`: nuevo bloque `instalaciones` (total y por dispositivo). Las instalaciones ya no cuentan como visitas.

### Pruebas y documentación
- Prueba de humo: **174 comprobaciones**, que incluyen la redirección `/app` y el recuento de instalaciones aparte; correctas en MariaDB 11.8.3 y MySQL.
- OpenAPI 0.7.1, requisitos v0.9.6, manuales 01 y 02, y nota en `REQ_QR_DEMO_COMERCIAL.md`.

## [0.7.0] · 2026-10-13

**Demo comercial y QR del folleto** (`docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md`). Una sola instalación: la demo se separa de la operativa con `es_demo` dentro de la misma base de datos. **Migración nueva: `0007_demo_comercial`.**

### Base de datos
- `0007_demo_comercial`: `interlocutor.es_demo` y `usuario.es_demo` (`DMO-022`), marcando las empresas demo existentes, y la tabla `visita_campania` (`DMO-031`) sin IP, User-Agent ni identificadores. Probada en MariaDB 11.8.3 y MySQL.

### Núcleo
- `Core/Demo`: interruptor `demo.habilitada` y `demo.habilitada_hasta` (`DMO-034`), contraseñas, campañas admitidas y tipo de dispositivo derivado del User-Agent sin guardarlo.
- `cli/demo_seed.php --reset`, idempotente (`DMO-020`):
  - toma las contraseñas de `config.php` (`demo.password`, `demo.password_admin`);
  - solo borra y recrea lo marcado `es_demo = 1`;
  - conserva el administrador de la plataforma y los `LOGIN_DEMO`.
- Datos de la demo: fechas relativas a la ejecución (`DMO-021`); Castilla con 48 empleados, 4 equipos y 6 personas en riesgo alto.
- Asesor: los usuarios demo usan `cache_first` aunque el modo sea `live` (`DMO-NF4`).

### Seguridad
- `Auth::noDemo()` y `PH-DEMO-001` (`DMO-024`): cuentas de gestión, invitaciones, importación CSV y bajas; además, el alta o cambio de correos de empleados.
- Login de cuentas demo:
  - bloqueo **por IP**, con 20 fallos en 15 minutos, nunca de la cuenta (`DMO-025`);
  - un acierto no borra los fallos de otras personas de la misma IP;
  - sesiones concurrentes (`DMO-026`).
- `PH-DEMO-002` con la demo desactivada, también para las sesiones demo abiertas (`DMO-034`).
- `LOGIN_DEMO` en la auditoría, con el perfil y sin IP (`DMO-033`). `/v1/auth/me` devuelve `demo`.
- `admin@pymehub.demo` no es demo: es solo para pruebas internas y tiene su propia contraseña (`DMO-028`).

### Endpoints
- Públicos: `POST /v1/public/visits` (lista blanca y 30 peticiones por minuto e IP, `DMO-030`) y `GET /v1/public/demo-status` (`DMO-037`).
- `GET /v1/admin/campaign-metrics` (ADMIN, `DMO-035`): totales, por dispositivo, por día y por hora (hora de España), entradas demo por perfil, y primera y última visita.

### Operación
- Reinicio de la demo cada noche a las 03:30 en `/etc/cron.d/pymehub` (`DMO-027`).
- Instalador y `config.example.php` con las secciones `demo` y `campaigns`.
- `tests/smoke_test.sh`: **171 comprobaciones** (15 nuevas), correctas en MariaDB 11.8.3 y MySQL:
  - argumentos opcionales: la contraseña de la demo y la URL de la web;
  - nuevas comprobaciones: redirección `/demo`, visitas, `demo-status`, métricas, login demo, sesiones concurrentes, `PH-DEMO-001` y bloqueo por IP;
  - no suma en las métricas comerciales: usa la campaña `prueba_interna` y la cabecera `X-PymeHub-Prueba`.

### Documentación
- `docs/requerimientos/REQ_QR_DEMO_COMERCIAL.md` con las notas de implementación.
- OpenAPI 0.7.0, colección de Postman (carpeta «16 · Demo comercial y QR del folleto»), requisitos v0.9.5 (§16 y §18.1), modelo de datos, manuales 01 y 02 (apartado 8.2), prompt maestro y README.

## [0.6.0] · 2026-10-12

Avance 5 · **IA de RR. HH.** (fase 7 del plan, §9): motor de riesgo de rotación v1.1, explicado y auditado, con alertas, panel, tendencias, revisión de sesgos y línea base de impacto. **Migración nueva: `0006_riesgo_diario`.**

### Base de datos
- `0006_riesgo_diario`: `puntuacion_riesgo.fecha` con clave única `(usuario_id, fecha)` e índice `(interlocutor_id, fecha, nivel)`: una puntuación por persona y día. Probada en MariaDB 11.8.3 y MySQL.

### Núcleo
- `config/risk_rules.php`: reglas v1.1 (§9.3) con tramos, ventanas, evidencia y fuentes S1–S7, exclusiones y limitaciones. Cambiar un peso exige subir la versión (`RF-032`).
- `Services/RiesgoService`:
  - cálculo F1–F7 con una frase en lenguaje llano por factor (`RF-034`);
  - datos a una fecha, para reconstruir y reproducir cualquier puntuación;
  - recálculo por empresa y alerta `RIESGO_ROTACION` al pasar a nivel alto, solo si no hay otra abierta; prioridad alta desde 70 (`RF-033`);
  - ranking, ficha con histórico, metodología, revisión de sesgos (`LEG-015`), panel (`RF-030`), tendencias mensuales, comprobación retrospectiva y línea base (`RF-037`).
- `cli/recalc_risk.php`: recálculo diario (03:00) de las empresas activas o en piloto con plan Completo; admite `--empresa`, `--fecha` y `--sin-alertas` (`RF-031`).
- `cli/bias_report.php`: informe trimestral en Markdown por equipo, puesto, turno y contrato, sin nombres (`LEG-015`).

### Seguridad
- Rutas de riesgo con `Auth::plan('riesgo_rotacion')` y línea base con `Auth::plan('linea_base')`: el plan Entrada recibe `PH-PLAN-001` (`RF-010`, criterio 7). El panel `GET /v1/dashboard/overview` es común a los dos planes, con el bloque de riesgo solo en Completo.
- Auditoría de cada consulta a la ficha de riesgo (`riesgo.ficha_consultada`, `LEG-004`), del recálculo y del registro de la línea base.
- Recálculo bajo demanda limitado a una vez cada 10 minutos por empresa (`PH-RATE-001`).
- El motor nunca usa datos de salud, el check-in de bienestar ni la participación en él (`LEG-012`, `LEG-013`). F4 solo cuenta formación asignada por la empresa (`RF-047`).

### Endpoints
- `GET /v1/dashboard/overview`, `GET /v1/dashboard/trends?months=`.
- `GET /v1/risk?team_id=&level=&q=`, `GET /v1/employees/{id}/risk`, `POST /v1/risk/recalculate`, `GET /v1/risk/methodology`, `GET /v1/risk/bias`.
- `GET` y `PUT /v1/baseline`.

### Operación
- Datos de demostración:
  - 23 antiguos empleados con bajas en los últimos 12 meses; las voluntarias tienen el perfil que la literatura asocia a la rotación;
  - ajuste determinista para que Castilla tenga entre 5 y 8 personas en riesgo alto (hoy, 6);
  - puntuación semanal desde el alta en Pyme Hub, 6 alertas de rotación (una ya gestionada) y línea base de los 12 meses anteriores.
- `tests/smoke_test.sh`: **156 comprobaciones** (26 nuevas de fase 7), correctas en MariaDB 11.8.3 y MySQL.

### Documentación
- **`docs/hoja_tecnica_modelo_riesgo.md`** (`RF-035`, `LEG-006`): propósito, datos, reglas, AI Act, fuentes, procedimiento y registro de la revisión de sesgos, validación, limitaciones y evolución.
- OpenAPI 0.6.0 (esquemas `Factor`, `PuntuacionRiesgo` y `LineaBase`), colección de Postman (carpeta «13 · IA de RR. HH.»), requisitos v0.9.4 (§9.6), modelo de datos, manuales 01 y 02 (tareas `recalc_risk` y `bias_report` en `/etc/cron.d/pymehub`, verificación), prompt maestro y README.

## [0.5.0] · 2026-10-12

Avance 4 · **Asesor de formación con IA** (fase 6 del plan, §12), la única funcionalidad con IA generativa. Incluye el manual 02 de producción. **Migración nueva: `0005_asesor_ia`.**

### Base de datos
- `0005_asesor_ia`: tabla `respuesta_ia` (caché del asesor con la salida validada, sin datos de la persona; `IA-014`), `solicitud_ia.aviso` e índice por fecha, y `CHECK` del objetivo en `preferencia_formativa`. Probada en MariaDB 11.8.3 y MySQL.

### Núcleo
- `src/Ai/AiGateway`: adaptador intercambiable por `config.php` para Mistral y APIs compatibles con chat/completions; cURL, 20 s, un reintento ante 429/5xx y salida JSON (`IA-011`, `IA-012`).
- `src/Ai/PayloadGuard`: lista blanca de §12.3 comprobada antes de cada llamada y rechazo del comentario con email, teléfono, DNI/NIE, nombres de la empresa o datos de salud (`IA-010`, `LEG-010`, `PH-AI-004`).
- `src/Ai/prompts/advisor.v1.md`: prompt de sistema versionado (`IA-019`).
- `Services/AsesorService`: modos `live`, `cache_first` y `offline` (`IA-015`), validación del esquema con un reintento (`IA-013`), anclaje al catálogo y relleno con reglas (`IA-001`), respaldo determinista (`IA-002`), cuotas por empleado y empresa (`IA-016`) y plan semanal.
- `cli/ai_warmup.php`: pregenera las respuestas de los tres perfiles de la demo (§12.6) en cada empresa de demostración.

### Seguridad y privacidad
- Al proveedor solo llegan el puesto, el tramo de antigüedad, el turno, las preferencias filtradas y el catálogo resumido. Nunca nombres, empresa, salud, incidencias ni notas.
- La clave solo vive en `config.php` y nunca se registra (`IA-020`); el registro de consultas no guarda contenido.
- La gestión no ve las preferencias individuales (`IA-017`, `LEG-014`); la demanda agregada exige 5 personas (`IA-021`).

### Endpoints
- Empleado: `GET/PUT/DELETE /v1/me/training/preferences`, `POST /v1/me/training/advisor`, `POST /v1/me/training/advisor/feedback`; `POST /v1/me/training/enroll` acepta `solicitud_id` (origen `ASESOR_IA`).
- Gestión: `GET /v1/training/demand`. Plataforma: `GET /v1/admin/ai-usage`.

### Operación
- `cli/purge_retention.php`: borra la caché caducada y el registro de consultas de más de 12 meses.
- Datos de demostración: preferencias de ~40 % de la plantilla (demanda formativa), `conductor@` con el primer perfil de §12.6 e historial de consultas atendidas por reglas (no se simula uso real del proveedor).
- `tests/smoke_test.sh`: **130 comprobaciones** (17 del asesor), correctas en MariaDB 11.8.3 y MySQL. Probado también contra un proveedor simulado: respuesta válida, caída (`PH-AI-001`), respuesta no JSON (`PH-AI-003`), ids inventados y cuota agotada (`PH-AI-002`).

### Documentación
- **Manual 02** (`docs/manuales/02_DESPLIEGUE_VPS_PRODUCCION.md`) incorporado y actualizado con la instalación real: código de `root:www-data` sin usuario `pymehub`, `core.fileMode false`, `/etc/cron.d/pymehub`, `bash` para las copias, `mariadb -u root -p`, `intl`, bloqueo en el `-le-ssl.conf`, comprobación de la web, activación del asesor (8.1) y nuevos problemas frecuentes.
- Manual 01, OpenAPI 0.5.0, colección de Postman (carpeta «12 · Asesor de formación con IA»), requisitos v0.9.3 (§12.7), modelo de datos, prompt maestro y README.

## [0.4.0] · 2026-10-12

Avance 3 · **Formación adaptativa** (fase 5 del plan, §10). Incluye las correcciones de despliegue detectadas en la instalación de producción. **Migración nueva: `0004_formacion_avance`.**

### Base de datos
- `0004_formacion_avance`: `asignacion_formativa.bloque_actual`, `ultima_nota` y `ultimo_envio` (cuestionario idempotente desde la cola sin conexión, `RF-045`, `RF-062`), índice `ix_asignacion_modulo` y `modulo_formativo.huella`. Probada en MariaDB 11.8.3 y MySQL (`RNF-009`).
- Catálogo global de **15 micro-módulos** del sector (`RF-040`, `RF-041`) en `data/catalogo_formativo.php`: conducción y seguridad vial, almacén y carga, cliente y entrega, bienestar y desarrollo profesional. Cada uno con objetivos, 3–4 bloques y 2–3 preguntas.

### Núcleo
- `Services/CatalogoService`: sincroniza el catálogo por `codigo`, sube la versión solo si cambia el contenido y ordena las opciones de forma determinista. Lo ejecutan el instalador, `cli/migrate.php` y el nuevo `cli/catalog_sync.php`.
- `Services/FormacionService`: corrección del cuestionario (≥ 70 %, `RF-044`), validación de módulos propios (`RF-046`), recomendaciones por reglas (`RF-043`, sin datos individuales de bienestar) y resumen de la empresa.
- `Repositories/ModuloRepository` y `AsignacionRepository` (estado `VENCIDA` calculado al leer; avance monótono).
- `Db::usar()` para que el instalador cree el catálogo con su propia conexión.

### Endpoints
- Gestión (GERENTE, RRHH; plan con `formacion`): `GET/POST /v1/training/modules`, `GET/PATCH /v1/training/modules/{id}`, `POST /v1/training/modules/{id}/publish`, `GET/POST /v1/training/assignments` (`RF-042`), `DELETE /v1/training/assignments/{id}`, `GET /v1/training/recommendations`, `GET /v1/training/summary`.
- Empleado: `GET /v1/me/training`, `GET /v1/me/training/catalog`, `POST /v1/me/training/enroll` (`RF-047`, `PH-TRN-002`), `GET /v1/me/training/{id}` (sin soluciones), `POST …/progress`, `POST …/submit` (`PH-TRN-001` si no es suya).

### Seguridad
- La solución del cuestionario nunca llega al reproductor; solo se devuelve al aprobar.
- Aislamiento: los módulos propios de una empresa no existen para otra (`PH-TENANT-001`); el catálogo global no se edita (`PH-PERM-001`).
- Límites de peticiones en inscripción y cuestionario.

### Operación
- `cli/backup.sh` recupera el permiso de ejecución en el repositorio (se había perdido).
- `cli/demo_seed.php`: seis meses de incidencias, campañas de formación por equipo, autoinscripciones, un módulo propio de Castilla y, para `conductor@`, un módulo completado y otro a medias.
- `tests/smoke_test.sh`: **113 comprobaciones** (26 de formación). Si no puede iniciar sesión como administrador se detiene con un mensaje claro en lugar de dar 75 fallos.

### Documentación
- OpenAPI 0.4.0 con los 11 endpoints de formación y sus esquemas.
- Colección de Postman: carpetas «10 · Formación (GERENTE, RRHH)» y «11 · Formación del empleado».
- Manual 01 con lo aprendido en la instalación de producción: `mariadb -u root -p`, `php8.4-intl`, permisos de `backup.sh` y `storage/`, `/etc/cron.d/pymehub` en lugar de `crontab -e`, bloqueo también en el `-le-ssl.conf`, `DocumentRoot …/public`, aviso sobre los textos de ejemplo y nuevos problemas frecuentes.
- Requisitos v0.9.2, modelo de datos, prompt maestro y README.

## [0.3.2] · 2026-10-12

Corrige las rutas de producción. Sin cambios de comportamiento ni migraciones.

### Operación
- Rutas reales del VPS: `/var/www/hermantorres/eude/pymehub/pymehub_api` (API) y `/var/www/hermantorres/eude/pymehub/pymehub_web` (web), con DocumentRoot en su `public/`.
- Comentarios de uso de `cli/backup.sh`, `cli/purge_retention.php` y `cli/wellbeing_alerts.php` (líneas de `crontab`) con la ruta nueva.

### Seguridad
- Manual 01, apartado 10.5 (obligatorio): como los repositorios quedan dentro de `/var/www/hermantorres`, se bloquea `/eude/pymehub` en los vhosts de `hermantorres.com`. Así no se exponen `config/`, `storage/` (copias de la base de datos) ni `.git`. Incluye la comprobación con `curl` (`RNF-004`, `SEC-010`).

### Documentación
- Manual 01:
  - tabla de rutas al principio y aviso de no cambiar los permisos de `/var/www/hermantorres`, que es de otro sitio;
  - clonado, vhosts, permisos, copias, tareas programadas, actualización, datos de demostración y restauración con las rutas nuevas.
- Requisitos v0.9.1: §3.2 (DocumentRoot) y §4.1 (despliegue de cada repositorio).

## [0.3.1] · 2026-10-12

Compatibilidad con **MariaDB 11.8 LTS**, el motor del VPS de producción (`DEC-05`). La API funciona sobre MariaDB ≥ 11.4.5 y MySQL ≥ 8.4.0. **Sin migraciones nuevas.**

### Base de datos
- `Core/DbEngine.php`: detección del motor y la versión (`SELECT VERSION()`), mínimos por motor y comprobación de la collation (`RNF-008`).
- `Core/Migrator.php`: comprueba la collation antes de migrar. Si el servidor no tiene `utf8mb4_0900_ai_ci`, usa `utf8mb4_uca1400_ai_ci` en tiempo de ejecución, sin editar las migraciones publicadas (§3.2, opción B). Lo he verificado en MariaDB 10.11, que no la tiene.
- `Core/Db.php`: conexión opcional por socket Unix (`db.socket`).
- Migraciones 0001 a 0003 sin cambios: se aplican igual en MariaDB 11.8 y MySQL (`RNF-009`).

### Núcleo
- Instalador (`RF-091`):
  - Acepta MariaDB ≥ 11.4.5 y MySQL ≥ 8.4.0 (se mantiene `PYMEHUB_PERMITIR_MYSQL80` para desarrollo).
  - Mensajes con el motor y la versión detectados y el mínimo exigido.
  - Comprueba la collation y que la base esté vacía antes de crear nada, y guarda `db.engine` en `config.php`.
  - Admite `localhost` o socket Unix y explica la diferencia entre `'usuario'@'localhost'` y `'usuario'@'127.0.0.1'`.
  - Textos «MySQL 8.4 / MariaDB 11.8» y «base de datos».
- `config.example.php` con `db.engine` y `db.socket`.

### Operación
- `cli/backup.sh` (`RNF-064`):
  - Usa `mariadb-dump` o, si no existe, `mysqldump`, y avisa con un error claro si no hay ninguno.
  - Las credenciales se escriben desde PHP, así que admite contraseñas con espacios o caracteres especiales, y puede conectar por socket.
- `/v1/health` sin cambios: no expone el motor ni su versión.

### Pruebas
- **MariaDB 11.8.3:**
  - Instalador web desde una base vacía, por socket y por TCP.
  - Prueba de humo con **87 correctas, 0 fallidas** y colección de Postman con 40 peticiones correctas.
  - `demo_seed.php`, dos veces seguidas.
  - Copia con `mariadb-dump` y restauración en una base nueva.
- **MySQL:** instalación limpia y prueba de humo con 87 correctas. Las hice en MySQL 8.0 con `PYMEHUB_PERMITIR_MYSQL80` porque mi entorno no tiene 8.4.
- **Rechazos del instalador:** MariaDB 10.11, base no vacía y credenciales erróneas.

### Documentación
- Requisitos v0.9:
  - `DEC-05`, `RNF-002`, `RNF-008`, `RNF-009` y §3.5.
  - `RF-091` y criterio de aceptación 17.
  - `P-11` cerrado.
  - Tabla de alineación con el TFM, que ya puede mantener MariaDB.
- `CLAUDE.md` con las reglas de SQL común; README, prompt maestro e índice de documentación.
- Manual 01, apartados 4 a 6, 14 y 17, con MariaDB 11.8 como motor válido. Se enlaza el manual 02 de despliegue en producción.

## [0.3.0] · 2026-10-11

### Añadido
- **Check-in de bienestar del empleado** (`POST /v1/me/checkins`): tres escalas 1–5 y nota opcional. Un check-in por día; repetirlo actualiza el del día, así que el reenvío desde la cola sin conexión es idempotente. Admite `fecha` hasta 3 días atrás (RF-050, RF-051, RF-056, RF-062).
- **Nota cifrada en reposo** con libsodium (`Core/Crypto.php`); solo la lee su autor y no se registra en ningún log (LEG-003, RNF-040).
- **Mensaje de apoyo** ante valores bajos, con la Línea 024 y el servicio de prevención. Sin intervención automática (RF-055).
- **Historial propio** (`GET /v1/me/checkins?days=30`) con medias, racha y notas descifradas (RF-052).
- **Bienestar agregado** (`GET /v1/wellbeing/summary`): medias semanales de la empresa o de un equipo, índice 0–100 (media de ánimo, energía y 6 − estrés), nivel, variación, participación agregada y comparación de equipos de las últimas 4 semanas. Por debajo de 5 personas no devuelve valores ni recuento y avisa con `PH-WB-001` (RF-053, LEG-002, §13.6).
- **Alertas** (`GET /v1/alerts`, `PATCH /v1/alerts/{id}`): listado por estado y tipo según el plan, y gestión humana con nota (§8.4, LEG-005).
- **Alertas de bienestar de equipo** (`cli/wellbeing_alerts.php`, lunes 06:00): estrés de la última semana completa ≥ 1 punto por encima de las 4 anteriores o ánimo < 2,5, sin duplicar alertas abiertas (RF-054).
- **Retención** (`cli/purge_retention.php`, diaria 04:00): borra los check-ins de más de 12 meses y las invitaciones caducadas (LEG-008).
- **Datos de demostración** (`cli/demo_seed.php`, `Services/DemoService.php`): las empresas Castilla (Completo, 48 empleados, 4 equipos) y Tajo (Entrada, 15 empleados, 2 equipos), usuarios `@pymehub.demo`, 60 días de check-ins con el equipo Nocturno empeorando y su alerta. Regenerable con `--reset` (§18, RF-100, LEG-009).
- `Core/Tiempo.php`: días y semanas en hora de España para el check-in y los agregados (RNF-012).
- Migración `0003_alerta_entidad`: índice para comprobar alertas abiertas por equipo o persona.
- Prueba de humo ampliada a **87 comprobaciones**: check-in, CSRF, apoyo, actualización del día, nota descifrada, validaciones, permisos, umbral de anonimato, aislamiento y alertas.

### Documentación
- OpenAPI 0.3.0 con los endpoints de bienestar y alertas.
- Colección de Postman: carpetas 08 (empleado) y 09 (gestión); recorrido de 36 peticiones verificado.
- Manual de despliegue: tareas programadas nuevas y apartado 12.1, sobre los datos de demostración.

### Al actualizar
- Ejecuta `php cli/migrate.php` (migración 0003) y añade las dos tareas programadas nuevas al `crontab` de `www-data`.

## [0.2.1] · 2026-10-11

### Documentación
- `docs/PROMPT_MAESTRO.md`: prompt maestro para continuar el desarrollo con IA (común a ambos repositorios).
- `docs/coleccion/`: **colección de Postman** de la API v1 (salud, autenticación, plataforma, cuentas de RR. HH., equipos, empleados, CSV, incidencias, bajas e invitaciones) con captura automática del token CSRF y de los identificadores, y entornos local y producción. Recorrido completo verificado: 30 peticiones correctas.
- `docs/anexos/`: documento final del TFM, anexo del prototipo de interfaz y presupuesto (Anexo A).
- `docs/README.md`: índice de la documentación.
- Sin cambios de código ni de esquema.

## [0.2.0] · 2026-10-03

### Añadido
- **Equipos** (`/v1/teams`): el gerente los crea, edita y borra (borrado lógico, solo sin empleados de alta); RR. HH. los consulta (matriz `P-10`).
- **Empleados** (`/v1/employees`): alta en `SIN_ACCESO` con email opcional, ficha, edición, listado paginado con filtros y **baja laboral** con `fecha_baja` y `motivo_baja` sin borrar al usuario (`RF-021`, `RF-022`, `RF-122`).
- **Invitación de empleados** con el email en la propia petición; exige el contrato de encargado del tratamiento (`RF-023`, `LEG-016`, `PH-TENANT-003`).
- **Límite de empleados** del plan en altas e importación (`PH-TENANT-002`), serializado con bloqueo de la suscripción.
- **Importación CSV** todo o nada, con simulación (`?simular=1`) y errores por fila y columna (`RF-020`, `PH-VAL-002`). Plantilla de ejemplo en `docs/ejemplos/plantilla_empleados.csv`.
- **Incidencias** por empleado, solo con los tipos de `LEG-012`.
- Prueba de humo ampliada a 71 comprobaciones: permisos de RR. HH., aislamiento entre empresas en todos los endpoints nuevos, límite del plan, contrato de encargado, CSV válido, con errores y simulado, y baja laboral.

### Cambiado
- Migración `0002_equipo_nombre_vigente`: el nombre de equipo es único solo entre los equipos vigentes, para poder reutilizar el de uno borrado. **Al actualizar, ejecuta `php cli/migrate.php`** (manual 01, apartado 16).
- Requisitos técnicos v0.8: `RF-020` a `RF-023` en §8.3; `LEG-016` referido a `interlocutor.fecha_contrato_encargado`.

## [0.1.0] · 2026-10-03

### Añadido
- Esquema inicial MySQL 8.4 (`0001_esquema_inicial`) con el modelo **interlocutor (tipo) → usuario (perfil)**, ficha laboral, equipos, incidencias, formación, bienestar, riesgo, alertas, línea base, solicitudes de IA, auditoría y límites de peticiones.
- Núcleo de la API sin frameworks: front controller, router, validación estricta, respuestas y errores con códigos `PH-*`, logs JSON con identificador de correlación.
- Sesión en cookie `HttpOnly` + `SameSite=Strict`, CSRF, caducidad por inactividad según perfil, bloqueo tras 5 intentos fallidos.
- CORS con lista blanca y cabeceras de seguridad.
- Endpoints: salud, login, logout, `me`, aceptación de invitaciones, alta y gestión de empresas, métricas de plataforma y cuentas de RR. HH.
- Asistente de instalación web, migrador CLI y script de copias de seguridad.
- Prueba de humo `tests/smoke_test.sh` (20 comprobaciones).
- Manual de despliegue y documentación del modelo de datos.
