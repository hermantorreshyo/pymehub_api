# Changelog · pymehub_api

Formato basado en *Keep a Changelog*; versiones SemVer.

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
