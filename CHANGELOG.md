# Changelog · pymehub_api

Formato basado en *Keep a Changelog*; versiones SemVer.

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
