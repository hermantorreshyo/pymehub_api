-- =====================================================================
-- Pyme Hub API · Migración 0007 · Demo comercial y métricas del QR
-- Motores: MariaDB 11.8 LTS y MySQL 8.4 LTS (RNF-009)
--
-- - interlocutor.es_demo y usuario.es_demo (DMO-022): marcan las empresas y personas de la
--   demostración comercial. El reinicio nocturno (cli/demo_seed.php --reset) solo borra lo
--   marcado; las restricciones PH-DEMO-001 solo se aplican a usuarios demo (DMO-024).
-- - visita_campania (DMO-031): una fila por visita de campaña (QR del folleto…). Sin IP,
--   sin User-Agent completo y sin identificador del dispositivo: no hay datos personales.
-- =====================================================================

ALTER TABLE interlocutor
    ADD COLUMN es_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER estado;

ALTER TABLE usuario
    ADD COLUMN es_demo TINYINT(1) NOT NULL DEFAULT 0 AFTER perfil;

-- Empresas de demostración ya existentes (RF-100): NIF reservados de la semilla
UPDATE interlocutor SET es_demo = 1 WHERE nif IN ('B45000001', 'B45000002');
UPDATE usuario SET es_demo = 1
 WHERE interlocutor_id IN (SELECT id FROM (SELECT id FROM interlocutor WHERE es_demo = 1) d);

CREATE TABLE visita_campania (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    campania    VARCHAR(40) NOT NULL,
    origen      VARCHAR(40) NOT NULL,
    medio       VARCHAR(40) NOT NULL,
    dispositivo VARCHAR(10) NOT NULL,
    creado_en   DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_visita_campania (campania, creado_en),
    CONSTRAINT ck_visita_dispositivo CHECK (dispositivo IN ('MOVIL','ESCRITORIO','OTRO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
