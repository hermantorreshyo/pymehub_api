-- =====================================================================
-- Pyme Hub API · Migración 0004 · Avance de la formación (fase 5)
-- Motores: MariaDB 11.8 LTS y MySQL 8.4 LTS (RNF-009)
--
-- - bloque_actual: último bloque visto, para retomar el módulo donde se dejó.
-- - ultima_nota: nota del último intento (mejor_nota guarda la mejor, RF-044).
-- - ultimo_envio: identificador del último cuestionario recibido. El empleado puede
--   responder sin conexión y la app reenvía al recuperar la red (RF-045, RF-062):
--   si llega dos veces el mismo envío, no cuenta como un intento nuevo.
-- - ix_asignacion_modulo: seguimiento por módulo para gerencia y RR. HH.
-- - modulo_formativo.huella: SHA-256 del contenido del catálogo global, para que
--   cli/catalog_sync.php solo actualice (y suba de versión) los módulos que cambian.
-- =====================================================================

ALTER TABLE asignacion_formativa
    ADD COLUMN bloque_actual TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER progreso_pct,
    ADD COLUMN ultima_nota   TINYINT UNSIGNED NULL AFTER mejor_nota,
    ADD COLUMN ultimo_envio  CHAR(36) NULL AFTER ultima_nota,
    ADD KEY ix_asignacion_modulo (interlocutor_id, modulo_id, estado),
    ADD CONSTRAINT ck_asignacion_ultima CHECK (ultima_nota IS NULL OR ultima_nota <= 100);

ALTER TABLE modulo_formativo
    ADD COLUMN huella CHAR(64) NULL AFTER ruta_multimedia;
