# Modelo de datos · Pyme Hub

**Motor:** MySQL 8.4 LTS · InnoDB · `utf8mb4_0900_ai_ci` · fechas en UTC
**Migraciones de referencia:** `migrations/0001_esquema_inicial.sql` y `migrations/0002_equipo_nombre_vigente.sql`

## 1. Principio: interlocutor y usuario

Todos los **actores** del sistema están en la tabla `interlocutor` y se distinguen por `tipo`. Las **personas** que acceden (o pueden llegar a acceder) están en `usuario` y se distinguen por `perfil`. Un interlocutor tiene varios usuarios.

| `interlocutor.tipo` | Qué representa | Perfiles de `usuario` admitidos |
|---|---|---|
| `PLATAFORMA` | Pyme Hub como operador. Existe un único registro | `ADMIN` |
| `EMPRESA` | PYME cliente | `GERENTE`, `RRHH`, `EMPLEADO` |
| `FORMADOR` | Formador externo de Pyme Hub Skills (fase 2) | `FORMADOR` |

Ejemplo: la empresa «Reparto Rápido Castilla S.L.» es un interlocutor `EMPRESA` con una gerente (`GERENTE`), una responsable de personas (`RRHH`) y 48 conductores y mozos (`EMPLEADO`).

### Cómo lo garantiza la base de datos (sin triggers)

1. **Tipos y perfiles en catálogos.** `cat_tipo_interlocutor` lista los tipos y `cat_perfil` lista qué perfiles admite cada tipo. Añadir un tipo o un perfil es insertar una fila, no cambiar el esquema.
2. **El perfil debe corresponder al tipo.** `usuario` guarda una copia de `tipo_interlocutor` atada por dos claves foráneas compuestas:
   - `(interlocutor_id, tipo_interlocutor) → interlocutor (id, tipo)`: la copia siempre coincide con el tipo real.
   - `(tipo_interlocutor, perfil) → cat_perfil`: el perfil está permitido para ese tipo.

   Así, un usuario `ADMIN` en una `EMPRESA` o un `EMPLEADO` en la `PLATAFORMA` son imposibles a nivel de base de datos, y cambiar el tipo de un interlocutor que ya tiene usuarios también.
3. **Una sola plataforma.** Una columna generada (`plataforma_unica`) con índice único impide un segundo interlocutor `PLATAFORMA`.
4. **Una sola suscripción vigente por empresa**, con el mismo mecanismo (`vigente_unica`). También el **nombre de equipo** es único solo entre los equipos vigentes (`nombre_vigente`, migración 0002), para poder reutilizar el nombre de un equipo borrado.
5. **Aislamiento entre empresas.** Las tablas de negocio llevan `interlocutor_id` y sus claves foráneas son compuestas (`usuario_id, interlocutor_id`): es imposible, por ejemplo, registrar un check-in de un empleado con el `interlocutor_id` de otra empresa.
6. **Una cuenta activa siempre tiene credenciales**: `CHECK (estado_acceso <> 'ACTIVO' OR (email IS NOT NULL AND password_hash IS NOT NULL))`.

### Empleados con y sin cuenta

Un empleado puede existir antes de tener acceso (por ejemplo, tras importar la plantilla por CSV): es un `usuario` con perfil `EMPLEADO` en estado `SIN_ACCESO`. Al invitarle pasa a `INVITADO` y, al aceptar, a `ACTIVO`. Sus datos laborales están en `ficha_laboral` (1:1), separados de los datos de acceso:

| `usuario.estado_acceso` | Significado |
|---|---|
| `SIN_ACCESO` | Existe en la plantilla, sin cuenta |
| `INVITADO` | Tiene una invitación pendiente |
| `ACTIVO` | Puede iniciar sesión |
| `BLOQUEADO` | Acceso suspendido por la empresa |
| `BAJA` | Sin acceso por baja. En un empleado, la baja laboral se registra en `ficha_laboral` (`fecha_baja` y `motivo_baja`: `VOLUNTARIA`, `NO_VOLUNTARIA`, `FIN_CONTRATO`) y el usuario se conserva para medir la rotación |

## 2. Diagrama

```mermaid
erDiagram
    cat_tipo_interlocutor ||--o{ cat_perfil : "admite"
    cat_tipo_interlocutor ||--o{ interlocutor : "tipo"
    interlocutor ||--o{ usuario : "tiene"
    cat_perfil ||--o{ usuario : "perfil"
    interlocutor ||--o{ suscripcion : "contrata"
    usuario ||--o| ficha_laboral : "datos laborales"
    interlocutor ||--o{ equipo : "organiza"
    equipo ||--o{ ficha_laboral : "agrupa"
    usuario ||--o{ invitacion : "recibe"
    usuario ||--o{ incidencia : "registra"
    interlocutor ||--o{ modulo_formativo : "es propietario"
    modulo_formativo ||--o{ pregunta_modulo : "evalúa"
    usuario ||--o{ asignacion_formativa : "cursa"
    modulo_formativo ||--o{ asignacion_formativa : "se asigna"
    usuario ||--o| preferencia_formativa : "declara"
    usuario ||--o{ checkin_bienestar : "responde"
    usuario ||--o{ puntuacion_riesgo : "se puntúa"
    interlocutor ||--o{ alerta : "recibe"
    interlocutor ||--o{ linea_base : "mide"
    usuario ||--o{ solicitud_ia : "consulta"

    interlocutor {
        bigint id PK
        varchar tipo FK
        varchar nombre
        varchar nif UK
        varchar estado
        date fecha_contrato_encargado
    }
    usuario {
        bigint id PK
        bigint interlocutor_id FK
        varchar tipo_interlocutor FK
        varchar perfil FK
        varchar email UK
        varchar estado_acceso
    }
    ficha_laboral {
        bigint usuario_id PK
        bigint interlocutor_id FK
        bigint equipo_id FK
        varchar puesto
        varchar tipo_contrato
        varchar turno
        date fecha_alta
        date fecha_baja
        varchar motivo_baja
    }
    suscripcion {
        bigint id PK
        bigint interlocutor_id FK
        varchar plan
        varchar ciclo_cobro
        varchar estado
    }
```

## 3. Tablas

| Tabla | Propósito | Notas |
|---|---|---|
| `cat_tipo_interlocutor` | Tipos de actor | `PLATAFORMA`, `EMPRESA`, `FORMADOR` |
| `cat_perfil` | Perfiles admitidos por tipo | |
| `interlocutor` | Todos los actores | Estado `PILOTO`, `ACTIVO`, `SUSPENDIDO`, `BAJA`; con `BAJA`, `fecha_baja` marca el inicio del plazo de borrado definitivo (`cli/tenant_purge.php`, LEG-017). `es_demo` (0007): empresa de la demo comercial, que el reinicio nocturno regenera. 0009: `stripe_customer_id` y aceptación de los términos de contratación (`terminos_version`, `terminos_aceptados_en`, `terminos_aceptados_por`) |
| `suscripcion` | Plan y ciclo de cobro de una empresa | Histórico; una vigente. Desde 0009: `vigencia` (`PRUEBA`, `VIGENTE`, `GRACIA`, `VENCIDA`), `periodo_fin` (último día pagado o de prueba; NULL = sin vencimiento), `empleados_facturados`, `stripe_subscription_id`, `stripe_item_id` y `cancelar_al_final`. Un cambio de plan abre una fila que los hereda |
| `usuario` | Personas con perfil | Email único global; contraseña Argon2id. `privacidad_aceptada_en` y `privacidad_version` (0008): cuándo y qué versión de la privacidad aceptó (LEG-022). La supresión (LEG-007) anonimiza nombre y correo y fija `eliminado_en`. `es_demo` (0007): cuenta de la demo comercial, con restricciones `PH-DEMO-001` y bloqueo por IP |
| `invitacion` | Enlaces de un solo uso | Solo se guarda el hash SHA-256; 72 h |
| `equipo` | Equipos de la empresa | Nombre único entre los vigentes; no se borra si tiene empleados de alta |
| `ficha_laboral` | Datos laborales del empleado | `motivo_baja` permite medir la rotación voluntaria |
| `incidencia` | Ausencias injustificadas, retrasos, siniestros, quejas, reconocimientos | Sin bajas médicas (LEG-012) |
| `modulo_formativo` | Micro-módulos | El propietario es un interlocutor: plataforma = catálogo global (15 módulos, `data/catalogo_formativo.php`, sincronizado por `codigo` con `cli/catalog_sync.php`); empresa = módulo propio. `huella` (0004): SHA-256 del contenido global para versionarlo solo cuando cambia |
| `pregunta_modulo` | Cuestionario | 2–4 opciones |
| `asignacion_formativa` | Formación de cada empleado | Origen: gestión, autoinscripción o asesor de IA. Una por persona y módulo. `VENCIDA` se calcula al leer (no completada con la fecha límite pasada). Desde 0004: `bloque_actual` (retomar), `ultima_nota` y `ultimo_envio` (cuestionario idempotente desde la cola sin conexión) |
| `preferencia_formativa` | Preferencias para el asesor | Solo las ve el empleado (LEG-014), que puede borrarlas; la gestión solo ve recuentos con 5 personas o más (IA-021). Objetivo con `CHECK` (0005) |
| `checkin_bienestar` | Check-in diario | Nota cifrada; equipo guardado en el momento del check-in |
| `puntuacion_riesgo` | Score de rotación (§9) | Una por persona y día desde 0006 (`fecha`, clave única `usuario_id + fecha`): el recálculo sustituye la del día y el cron de las 03:00 añade la siguiente. Guarda los factores (código, puntos, frase en lenguaje llano, evidencia y fuentes) y `version_reglas` (RF-032). Nunca datos de salud ni de bienestar |
| `alerta` | Alertas de rotación, bienestar y formación | `RIESGO_ROTACION` (entidad `USUARIO`) al pasar a nivel alto, si no hay otra abierta; la gestiona una persona (LEG-005) |
| `linea_base` | Rotación y absentismo previos al alta | RF-037. Una vigente por empresa (`PUT /v1/baseline` la sustituye). `dias_absentismo` son días de ausencia injustificada, la misma medida que `incidencia`, para comparar en homogéneo |
| `solicitud_ia` | Registro de consultas al asesor | Sin contenido; solo hash, origen (`LIVE`, `CACHE`, `REGLAS`), aviso servido (0005), tokens, latencia y valoración útil/no útil |
| `respuesta_ia` | Caché del asesor (0005, `IA-014`) | Clave: hash de la entrada normalizada (perfil no identificativo, preferencias, catálogo y versión del prompt). Solo la salida validada; 7 días, sin caducidad las pregeneradas para la demo (`cli/ai_warmup.php`) |
| `registro_auditoria` | Acciones sensibles | Solo inserción. `LOGIN_DEMO` (con el perfil en `entidad` y sin IP) cuenta las entradas en la demo comercial y se conserva al reiniciarla |
| `visita_campania` | Visitas de campaña (QR del folleto) | 0007. Campaña, origen, medio, dispositivo (`MOVIL`, `ESCRITORIO`, `OTRO`) y fecha. Sin IP, User-Agent ni identificadores: no hay datos personales |
| `pago` | Facturas y pagos de la suscripción (0009, RF-120) | `origen` `STRIPE` (por webhook, único por `stripe_invoice_id`) o `MANUAL` (registrado por la plataforma); `estado` `PAGADO`, `FALLIDO`, `PENDIENTE`, `ANULADO`; `base`, `iva`, `total`, periodo y enlaces a la factura de Stripe |
| `evento_stripe` | Eventos de webhook procesados (0009, RF-118) | Clave: id del evento de Stripe; garantiza la idempotencia. Sin datos personales |
| `parametro` | Parámetros de la plataforma (0008, RF-112) | `clave` (PK, p. ej. `precios.descuento_anual_pct`), `valor` JSON en `TEXT`, `actualizado_en`, `actualizado_por` (FK a `usuario`). Titular (pendiente de constitución), precios sin IVA, IVA, descuento anual, prueba, gracia, borrado tras la baja, Skills y versión legal. Definición y límites en `Core/Parametros::DEF` |
| `limite_peticion` | Limitación de peticiones | |
| `migracion_esquema` | Versiones aplicadas | La crea el migrador |

## 4. Convenciones

- Nombres de tablas y columnas en español, en minúsculas y singular.
- Valores enumerados en mayúsculas y validados con `CHECK` (o catálogo si deben poder ampliarse sin migración).
- Todas las fechas y horas en UTC; la conversión a `Europe/Madrid` la hace el frontend.
- Borrado lógico con `eliminado_en` donde hace falta conservar el histórico.
