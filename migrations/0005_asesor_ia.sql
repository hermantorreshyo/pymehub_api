-- =====================================================================
-- Pyme Hub API · Migración 0005 · Asesor de formación con IA (fase 6, §12)
-- Motores: MariaDB 11.8 LTS y MySQL 8.4 LTS (RNF-009)
--
-- - respuesta_ia: caché de respuestas del asesor (IA-014, IA-015). La clave es el hash
--   de la entrada normalizada (preferencias + perfil no identificativo + versión del
--   catálogo y del prompt). Solo guarda la salida validada (ids de módulo, motivo y
--   plan): ningún dato de la persona. Las pregeneradas (cli/ai_warmup.php) no caducan.
-- - solicitud_ia: interlocutor y servido_desde ya existían; se añade el aviso servido
--   (PH-AI-001/002/003) para el panel de uso, sin contenido (IA-012, RNF-040).
-- - preferencia_formativa: CHECK del objetivo (catálogo cerrado, §12.2).
-- =====================================================================

CREATE TABLE respuesta_ia (
    hash_entrada     CHAR(64)     NOT NULL,
    caso_uso         VARCHAR(30)  NOT NULL DEFAULT 'ASESOR_FORMACION',
    version_prompt   VARCHAR(20)  NOT NULL,
    proveedor        VARCHAR(20)  NOT NULL,
    modelo           VARCHAR(60)  NULL,
    respuesta        JSON         NOT NULL,
    pregenerada      TINYINT(1)   NOT NULL DEFAULT 0,
    usos             INT UNSIGNED NOT NULL DEFAULT 0,
    creado_en        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    caduca_en        DATETIME     NULL COMMENT 'NULL = no caduca (pregenerada para la demo)',
    PRIMARY KEY (hash_entrada),
    KEY ix_respuesta_caducidad (caduca_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE solicitud_ia
    ADD COLUMN aviso VARCHAR(12) NULL AFTER servido_desde,
    ADD KEY ix_solicitud_fecha (creado_en, servido_desde);

ALTER TABLE preferencia_formativa
    ADD CONSTRAINT ck_preferencia_objetivo CHECK (objetivo IN
        ('MEJORAR_TRABAJO','SEGURIDAD','SALUD_ENERGIA','RESPONSABILIDAD','HERRAMIENTAS_DIGITALES'));
