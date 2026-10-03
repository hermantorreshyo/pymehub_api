-- =====================================================================
-- Pyme Hub API · Migración 0001 · Esquema inicial
-- Motor: MySQL 8.4 LTS · InnoDB · utf8mb4_0900_ai_ci
-- Modelo de actores: INTERLOCUTOR (tipo) 1 ── N USUARIO (perfil)
-- =====================================================================

-- ---------------------------------------------------------------------
-- Catálogos
-- ---------------------------------------------------------------------
CREATE TABLE cat_tipo_interlocutor (
    codigo       VARCHAR(20)  NOT NULL,
    nombre       VARCHAR(60)  NOT NULL,
    descripcion  VARCHAR(255) NULL,
    PRIMARY KEY (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Perfiles de usuario admitidos por cada tipo de interlocutor
CREATE TABLE cat_perfil (
    tipo_interlocutor VARCHAR(20) NOT NULL,
    perfil            VARCHAR(20) NOT NULL,
    nombre            VARCHAR(60) NOT NULL,
    PRIMARY KEY (tipo_interlocutor, perfil),
    CONSTRAINT fk_cat_perfil_tipo FOREIGN KEY (tipo_interlocutor)
        REFERENCES cat_tipo_interlocutor (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO cat_tipo_interlocutor (codigo, nombre, descripcion) VALUES
 ('PLATAFORMA', 'Plataforma', 'Pyme Hub como operador de la plataforma. Existe un único registro.'),
 ('EMPRESA',    'Empresa',    'PYME cliente con suscripción.'),
 ('FORMADOR',   'Formador',   'Formador externo de Pyme Hub Skills (reservado para la fase 2).');

INSERT INTO cat_perfil (tipo_interlocutor, perfil, nombre) VALUES
 ('PLATAFORMA', 'ADMIN',    'Administrador de la plataforma'),
 ('EMPRESA',    'GERENTE',  'Gerente'),
 ('EMPRESA',    'RRHH',     'Recursos Humanos'),
 ('EMPRESA',    'EMPLEADO', 'Empleado'),
 ('FORMADOR',   'FORMADOR', 'Formador');

-- ---------------------------------------------------------------------
-- Interlocutor: todos los actores del sistema
-- ---------------------------------------------------------------------
CREATE TABLE interlocutor (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tipo                      VARCHAR(20)  NOT NULL,
    nombre                    VARCHAR(150) NOT NULL,
    nombre_comercial          VARCHAR(150) NULL,
    nif                       VARCHAR(15)  NULL,
    sector                    VARCHAR(80)  NULL,
    email_contacto            VARCHAR(190) NULL,
    telefono_contacto         VARCHAR(30)  NULL,
    fecha_contrato_encargado  DATE         NULL COMMENT 'Firma del contrato de encargado del tratamiento (LEG-016)',
    estado                    VARCHAR(15)  NOT NULL DEFAULT 'ACTIVO',
    fecha_baja                DATETIME     NULL,
    creado_en                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    -- Garantiza un único interlocutor de tipo PLATAFORMA
    plataforma_unica          TINYINT GENERATED ALWAYS AS (IF(tipo = 'PLATAFORMA', 1, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_interlocutor_id_tipo (id, tipo),
    UNIQUE KEY uq_interlocutor_nif (nif),
    UNIQUE KEY uq_interlocutor_plataforma (plataforma_unica),
    KEY ix_interlocutor_tipo_estado (tipo, estado),
    CONSTRAINT fk_interlocutor_tipo FOREIGN KEY (tipo) REFERENCES cat_tipo_interlocutor (codigo),
    CONSTRAINT ck_interlocutor_estado CHECK (estado IN ('PILOTO','ACTIVO','SUSPENDIDO','BAJA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Suscripción de una empresa (histórico; una sola vigente)
CREATE TABLE suscripcion (
    id                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id      BIGINT UNSIGNED NOT NULL,
    plan                 VARCHAR(10)   NOT NULL,
    ciclo_cobro          VARCHAR(10)   NOT NULL DEFAULT 'MENSUAL',
    precio_empleado_mes  DECIMAL(6,2)  NOT NULL,
    limite_empleados     INT UNSIGNED  NOT NULL DEFAULT 250,
    cuota_ia_diaria      INT UNSIGNED  NOT NULL DEFAULT 20,
    fecha_inicio         DATE          NOT NULL,
    fecha_fin            DATE          NULL,
    estado               VARCHAR(12)   NOT NULL DEFAULT 'VIGENTE',
    creado_en            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    vigente_unica        BIGINT UNSIGNED GENERATED ALWAYS AS (IF(estado = 'VIGENTE', interlocutor_id, NULL)) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_suscripcion_vigente (vigente_unica),
    KEY ix_suscripcion_interlocutor (interlocutor_id),
    CONSTRAINT fk_suscripcion_interlocutor FOREIGN KEY (interlocutor_id) REFERENCES interlocutor (id),
    CONSTRAINT ck_suscripcion_plan   CHECK (plan IN ('ENTRADA','COMPLETO')),
    CONSTRAINT ck_suscripcion_ciclo  CHECK (ciclo_cobro IN ('MENSUAL','ANUAL')),
    CONSTRAINT ck_suscripcion_estado CHECK (estado IN ('VIGENTE','FINALIZADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Usuario: personas que pertenecen a un interlocutor, con un perfil
-- ---------------------------------------------------------------------
CREATE TABLE usuario (
    id                        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id           BIGINT UNSIGNED NOT NULL,
    tipo_interlocutor         VARCHAR(20)  NOT NULL COMMENT 'Copia controlada por FK compuesta: siempre igual al tipo del interlocutor',
    perfil                    VARCHAR(20)  NOT NULL,
    nombre                    VARCHAR(80)  NOT NULL,
    apellidos                 VARCHAR(120) NOT NULL DEFAULT '',
    email                     VARCHAR(190) NULL,
    password_hash             VARCHAR(255) NULL,
    estado_acceso             VARCHAR(12)  NOT NULL DEFAULT 'SIN_ACCESO',
    ultimo_acceso_en          DATETIME     NULL,
    privacidad_aceptada_en    DATETIME     NULL COMMENT 'LEG-001',
    creado_en                 DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    eliminado_en              DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_usuario_email (email),
    UNIQUE KEY uq_usuario_id_interlocutor (id, interlocutor_id),
    KEY ix_usuario_interlocutor_perfil (interlocutor_id, perfil, estado_acceso),
    -- El tipo copiado debe coincidir con el del interlocutor...
    CONSTRAINT fk_usuario_interlocutor FOREIGN KEY (interlocutor_id, tipo_interlocutor)
        REFERENCES interlocutor (id, tipo),
    -- ...y el perfil debe estar permitido para ese tipo
    CONSTRAINT fk_usuario_perfil FOREIGN KEY (tipo_interlocutor, perfil)
        REFERENCES cat_perfil (tipo_interlocutor, perfil),
    CONSTRAINT ck_usuario_estado CHECK (estado_acceso IN ('SIN_ACCESO','INVITADO','ACTIVO','BLOQUEADO','BAJA')),
    CONSTRAINT ck_usuario_credenciales CHECK (estado_acceso <> 'ACTIVO' OR (email IS NOT NULL AND password_hash IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE invitacion (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    usuario_id    BIGINT UNSIGNED NOT NULL,
    token_hash    CHAR(64)  NOT NULL,
    expira_en     DATETIME  NOT NULL,
    usada_en      DATETIME  NULL,
    creada_por    BIGINT UNSIGNED NULL,
    creado_en     DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invitacion_token (token_hash),
    KEY ix_invitacion_usuario (usuario_id),
    CONSTRAINT fk_invitacion_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE,
    CONSTRAINT fk_invitacion_creador FOREIGN KEY (creada_por) REFERENCES usuario (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Organización de la empresa
-- ---------------------------------------------------------------------
CREATE TABLE equipo (
    id                      BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id         BIGINT UNSIGNED NOT NULL,
    nombre                  VARCHAR(80)  NOT NULL,
    zona                    VARCHAR(80)  NULL,
    responsable_usuario_id  BIGINT UNSIGNED NULL,
    creado_en               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    eliminado_en            DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_equipo_id_interlocutor (id, interlocutor_id),
    UNIQUE KEY uq_equipo_nombre (interlocutor_id, nombre),
    CONSTRAINT fk_equipo_interlocutor FOREIGN KEY (interlocutor_id) REFERENCES interlocutor (id),
    CONSTRAINT fk_equipo_responsable FOREIGN KEY (responsable_usuario_id, interlocutor_id)
        REFERENCES usuario (id, interlocutor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Datos laborales del usuario con perfil EMPLEADO (1:1 con usuario)
CREATE TABLE ficha_laboral (
    usuario_id         BIGINT UNSIGNED NOT NULL,
    interlocutor_id    BIGINT UNSIGNED NOT NULL,
    equipo_id          BIGINT UNSIGNED NULL,
    codigo_interno     VARCHAR(30)  NULL,
    puesto             VARCHAR(20)  NOT NULL,
    tipo_contrato      VARCHAR(12)  NOT NULL,
    turno              VARCHAR(10)  NOT NULL,
    fecha_alta         DATE         NOT NULL,
    fecha_baja         DATE         NULL,
    motivo_baja        VARCHAR(15)  NULL,
    actualizado_en     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id),
    UNIQUE KEY uq_ficha_codigo (interlocutor_id, codigo_interno),
    KEY ix_ficha_equipo (interlocutor_id, equipo_id),
    CONSTRAINT fk_ficha_usuario FOREIGN KEY (usuario_id, interlocutor_id) REFERENCES usuario (id, interlocutor_id),
    CONSTRAINT fk_ficha_equipo  FOREIGN KEY (equipo_id, interlocutor_id)  REFERENCES equipo (id, interlocutor_id),
    CONSTRAINT ck_ficha_puesto   CHECK (puesto IN ('CONDUCTOR','REPARTIDOR','MOZO_ALMACEN','COORDINADOR','ADMINISTRATIVO','OTRO')),
    CONSTRAINT ck_ficha_contrato CHECK (tipo_contrato IN ('INDEFINIDO','TEMPORAL','ETT')),
    CONSTRAINT ck_ficha_turno    CHECK (turno IN ('MANANA','TARDE','NOCHE','ROTATIVO')),
    CONSTRAINT ck_ficha_motivo   CHECK (motivo_baja IS NULL OR motivo_baja IN ('VOLUNTARIA','NO_VOLUNTARIA','FIN_CONTRATO')),
    CONSTRAINT ck_ficha_baja     CHECK (fecha_baja IS NULL OR (fecha_baja >= fecha_alta AND motivo_baja IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE incidencia (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id  BIGINT UNSIGNED NOT NULL,
    usuario_id       BIGINT UNSIGNED NOT NULL,
    tipo             VARCHAR(25)  NOT NULL,
    fecha            DATE         NOT NULL,
    nota             VARCHAR(500) NULL,
    registrada_por   BIGINT UNSIGNED NULL,
    creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_incidencia_usuario_fecha (usuario_id, tipo, fecha),
    KEY ix_incidencia_interlocutor (interlocutor_id, fecha),
    CONSTRAINT fk_incidencia_usuario FOREIGN KEY (usuario_id, interlocutor_id) REFERENCES usuario (id, interlocutor_id),
    CONSTRAINT fk_incidencia_registro FOREIGN KEY (registrada_por) REFERENCES usuario (id) ON DELETE SET NULL,
    -- LEG-012: solo ausencias injustificadas; nunca bajas médicas ni su causa
    CONSTRAINT ck_incidencia_tipo CHECK (tipo IN ('AUSENCIA_INJUSTIFICADA','RETRASO','SINIESTRO','QUEJA_CLIENTE','RECONOCIMIENTO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Formación
-- El propietario del módulo es un interlocutor:
-- PLATAFORMA = catálogo global · EMPRESA = módulo propio · FORMADOR = fase 2
-- ---------------------------------------------------------------------
CREATE TABLE modulo_formativo (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id   BIGINT UNSIGNED NOT NULL,
    codigo            VARCHAR(40)  NOT NULL,
    titulo            VARCHAR(150) NOT NULL,
    resumen           VARCHAR(300) NOT NULL,
    categoria         VARCHAR(25)  NOT NULL,
    nivel             VARCHAR(12)  NOT NULL DEFAULT 'BASICO',
    puestos_destino   JSON         NOT NULL,
    etiquetas         JSON         NOT NULL,
    duracion_min      TINYINT UNSIGNED NOT NULL,
    contenido         JSON         NOT NULL,
    ruta_multimedia   VARCHAR(255) NULL,
    estado            VARCHAR(10)  NOT NULL DEFAULT 'BORRADOR',
    version           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    creado_en         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    eliminado_en      DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_modulo_codigo (interlocutor_id, codigo),
    KEY ix_modulo_estado (estado, categoria),
    CONSTRAINT fk_modulo_interlocutor FOREIGN KEY (interlocutor_id) REFERENCES interlocutor (id),
    CONSTRAINT ck_modulo_categoria CHECK (categoria IN ('CONDUCCION','ALMACEN','CLIENTE','BIENESTAR','DESARROLLO')),
    CONSTRAINT ck_modulo_nivel     CHECK (nivel IN ('BASICO','INTERMEDIO','AVANZADO')),
    CONSTRAINT ck_modulo_duracion  CHECK (duracion_min BETWEEN 1 AND 15),
    CONSTRAINT ck_modulo_estado    CHECK (estado IN ('BORRADOR','PUBLICADO','ARCHIVADO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE pregunta_modulo (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    modulo_id        BIGINT UNSIGNED NOT NULL,
    orden            TINYINT UNSIGNED NOT NULL,
    enunciado        VARCHAR(300) NOT NULL,
    opciones         JSON NOT NULL,
    indice_correcto  TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pregunta_orden (modulo_id, orden),
    CONSTRAINT fk_pregunta_modulo FOREIGN KEY (modulo_id) REFERENCES modulo_formativo (id) ON DELETE CASCADE,
    CONSTRAINT ck_pregunta_indice CHECK (indice_correcto BETWEEN 0 AND 3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE asignacion_formativa (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id  BIGINT UNSIGNED NOT NULL,
    usuario_id       BIGINT UNSIGNED NOT NULL,
    modulo_id        BIGINT UNSIGNED NOT NULL,
    origen           VARCHAR(16)  NOT NULL,
    asignada_por     BIGINT UNSIGNED NULL,
    fecha_limite     DATE         NULL,
    estado           VARCHAR(12)  NOT NULL DEFAULT 'PENDIENTE',
    progreso_pct     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    intentos         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    mejor_nota       TINYINT UNSIGNED NULL,
    completada_en    DATETIME NULL,
    creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_asignacion (usuario_id, modulo_id),
    KEY ix_asignacion_estado (interlocutor_id, estado, fecha_limite),
    CONSTRAINT fk_asignacion_usuario FOREIGN KEY (usuario_id, interlocutor_id) REFERENCES usuario (id, interlocutor_id),
    CONSTRAINT fk_asignacion_modulo  FOREIGN KEY (modulo_id) REFERENCES modulo_formativo (id),
    CONSTRAINT fk_asignacion_autor   FOREIGN KEY (asignada_por) REFERENCES usuario (id) ON DELETE SET NULL,
    CONSTRAINT ck_asignacion_origen   CHECK (origen IN ('GESTION','AUTOINSCRIPCION','ASESOR_IA')),
    CONSTRAINT ck_asignacion_estado   CHECK (estado IN ('PENDIENTE','EN_CURSO','COMPLETADA','VENCIDA')),
    CONSTRAINT ck_asignacion_progreso CHECK (progreso_pct <= 100),
    CONSTRAINT ck_asignacion_nota     CHECK (mejor_nota IS NULL OR mejor_nota <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Preferencias declaradas por el empleado para el asesor (LEG-014)
CREATE TABLE preferencia_formativa (
    usuario_id       BIGINT UNSIGNED NOT NULL,
    objetivo         VARCHAR(30)  NOT NULL,
    intereses        JSON         NOT NULL,
    formato          VARCHAR(10)  NOT NULL DEFAULT 'TEXTO',
    minutos_semana   TINYINT UNSIGNED NOT NULL DEFAULT 15,
    comentario       VARCHAR(200) NULL,
    actualizado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (usuario_id),
    CONSTRAINT fk_preferencia_usuario FOREIGN KEY (usuario_id) REFERENCES usuario (id) ON DELETE CASCADE,
    CONSTRAINT ck_preferencia_formato CHECK (formato IN ('TEXTO','IMAGENES','VIDEO')),
    CONSTRAINT ck_preferencia_minutos CHECK (minutos_semana IN (5, 15, 30))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Bienestar
-- ---------------------------------------------------------------------
CREATE TABLE checkin_bienestar (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id  BIGINT UNSIGNED NOT NULL,
    usuario_id       BIGINT UNSIGNED NOT NULL,
    equipo_id        BIGINT UNSIGNED NULL COMMENT 'Equipo en el momento del check-in, para agregados históricos',
    fecha            DATE NOT NULL,
    animo            TINYINT UNSIGNED NOT NULL,
    energia          TINYINT UNSIGNED NOT NULL,
    estres           TINYINT UNSIGNED NOT NULL,
    nota_cifrada     VARBINARY(1024) NULL COMMENT 'sodium_crypto_secretbox (LEG-003)',
    creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_checkin_dia (usuario_id, fecha),
    KEY ix_checkin_equipo_fecha (interlocutor_id, equipo_id, fecha),
    CONSTRAINT fk_checkin_usuario FOREIGN KEY (usuario_id, interlocutor_id) REFERENCES usuario (id, interlocutor_id),
    CONSTRAINT fk_checkin_equipo  FOREIGN KEY (equipo_id) REFERENCES equipo (id) ON DELETE SET NULL,
    CONSTRAINT ck_checkin_escalas CHECK (animo BETWEEN 1 AND 5 AND energia BETWEEN 1 AND 5 AND estres BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- IA de RR. HH.
-- ---------------------------------------------------------------------
CREATE TABLE puntuacion_riesgo (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id  BIGINT UNSIGNED NOT NULL,
    usuario_id       BIGINT UNSIGNED NOT NULL,
    puntuacion       TINYINT UNSIGNED NOT NULL,
    nivel            VARCHAR(6)  NOT NULL,
    factores         JSON        NOT NULL,
    version_reglas   VARCHAR(10) NOT NULL,
    calculada_en     DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_riesgo_usuario (usuario_id, calculada_en),
    KEY ix_riesgo_interlocutor (interlocutor_id, calculada_en, nivel),
    CONSTRAINT fk_riesgo_usuario FOREIGN KEY (usuario_id, interlocutor_id) REFERENCES usuario (id, interlocutor_id),
    CONSTRAINT ck_riesgo_puntuacion CHECK (puntuacion <= 100),
    CONSTRAINT ck_riesgo_nivel CHECK (nivel IN ('BAJO','MEDIO','ALTO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE alerta (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id  BIGINT UNSIGNED NOT NULL,
    tipo             VARCHAR(20)  NOT NULL,
    entidad          VARCHAR(10)  NOT NULL,
    entidad_id       BIGINT UNSIGNED NOT NULL,
    severidad        VARCHAR(6)   NOT NULL,
    mensaje          VARCHAR(300) NOT NULL,
    estado           VARCHAR(10)  NOT NULL DEFAULT 'ABIERTA',
    gestionada_por   BIGINT UNSIGNED NULL,
    gestionada_en    DATETIME NULL,
    nota_gestion     VARCHAR(500) NULL,
    creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_alerta_estado (interlocutor_id, estado, creado_en),
    CONSTRAINT fk_alerta_interlocutor FOREIGN KEY (interlocutor_id) REFERENCES interlocutor (id),
    CONSTRAINT fk_alerta_gestor FOREIGN KEY (gestionada_por) REFERENCES usuario (id) ON DELETE SET NULL,
    CONSTRAINT ck_alerta_tipo CHECK (tipo IN ('RIESGO_ROTACION','BIENESTAR_EQUIPO','FORMACION_VENCIDA')),
    CONSTRAINT ck_alerta_entidad CHECK (entidad IN ('USUARIO','EQUIPO')),
    CONSTRAINT ck_alerta_severidad CHECK (severidad IN ('BAJA','MEDIA','ALTA')),
    CONSTRAINT ck_alerta_estado CHECK (estado IN ('ABIERTA','GESTIONADA','DESCARTADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE linea_base (
    id                 BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id    BIGINT UNSIGNED NOT NULL,
    periodo_inicio     DATE NOT NULL,
    periodo_fin        DATE NOT NULL,
    plantilla_media    DECIMAL(7,2) NOT NULL,
    bajas_voluntarias  INT UNSIGNED NOT NULL,
    dias_absentismo    INT UNSIGNED NOT NULL,
    fuente             VARCHAR(150) NULL,
    registrada_por     BIGINT UNSIGNED NULL,
    creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_linea_base (interlocutor_id, periodo_inicio),
    CONSTRAINT fk_linea_base_interlocutor FOREIGN KEY (interlocutor_id) REFERENCES interlocutor (id),
    CONSTRAINT fk_linea_base_usuario FOREIGN KEY (registrada_por) REFERENCES usuario (id) ON DELETE SET NULL,
    CONSTRAINT ck_linea_base_periodo CHECK (periodo_fin > periodo_inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Asesor de formación (IA): registro sin contenido
-- ---------------------------------------------------------------------
CREATE TABLE solicitud_ia (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id  BIGINT UNSIGNED NOT NULL,
    usuario_id       BIGINT UNSIGNED NOT NULL,
    caso_uso         VARCHAR(30) NOT NULL DEFAULT 'ASESOR_FORMACION',
    proveedor        VARCHAR(20) NOT NULL,
    modelo           VARCHAR(60) NULL,
    hash_entrada     CHAR(64)    NOT NULL,
    tokens_entrada   INT UNSIGNED NULL,
    tokens_salida    INT UNSIGNED NULL,
    latencia_ms      INT UNSIGNED NULL,
    estado           VARCHAR(10) NOT NULL,
    servido_desde    VARCHAR(8)  NOT NULL,
    util             TINYINT(1)  NULL COMMENT 'Valoración del empleado',
    creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_solicitud_cuota (interlocutor_id, creado_en),
    KEY ix_solicitud_usuario (usuario_id, creado_en),
    CONSTRAINT fk_solicitud_usuario FOREIGN KEY (usuario_id, interlocutor_id) REFERENCES usuario (id, interlocutor_id),
    CONSTRAINT ck_solicitud_estado CHECK (estado IN ('OK','ERROR','RECHAZADA')),
    CONSTRAINT ck_solicitud_origen CHECK (servido_desde IN ('LIVE','CACHE','REGLAS'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Soporte técnico
-- ---------------------------------------------------------------------
CREATE TABLE registro_auditoria (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id  BIGINT UNSIGNED NULL,
    usuario_id       BIGINT UNSIGNED NULL,
    accion           VARCHAR(60)  NOT NULL,
    entidad          VARCHAR(40)  NULL,
    entidad_id       BIGINT UNSIGNED NULL,
    ip               VARBINARY(16) NULL,
    creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_auditoria_interlocutor (interlocutor_id, creado_en),
    KEY ix_auditoria_entidad (entidad, entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE limite_peticion (
    clave           VARCHAR(190) NOT NULL,
    contador        INT UNSIGNED NOT NULL DEFAULT 0,
    ventana_inicio  DATETIME NOT NULL,
    PRIMARY KEY (clave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- ---------------------------------------------------------------------
-- Interlocutor PLATAFORMA (único)
-- ---------------------------------------------------------------------
INSERT INTO interlocutor (tipo, nombre, nombre_comercial, estado)
VALUES ('PLATAFORMA', 'Pyme Hub', 'Pyme Hub', 'ACTIVO');
