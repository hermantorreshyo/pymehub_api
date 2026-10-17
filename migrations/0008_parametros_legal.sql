-- =====================================================================
-- Pyme Hub API · Migración 0008 · Parámetros de la plataforma y textos legales
-- Motores: MariaDB 11.8 LTS y MySQL 8.4 LTS (RNF-009)
--
-- - parametro: valores de negocio que se cambian desde el panel de la plataforma sin desplegar:
--   datos del titular (pendientes de la constitución de la sociedad), precios sin IVA, tipo de
--   IVA, descuento del ciclo anual, días de prueba y de gracia, plazo de borrado tras la baja,
--   comisión del marketplace y versiones vigentes de los textos legales. Valor en JSON (texto).
-- - usuario.privacidad_version: versión del aviso de privacidad aceptada (LEG-001).
-- =====================================================================

CREATE TABLE parametro (
    clave          VARCHAR(60)  NOT NULL,
    valor          TEXT         NOT NULL,
    actualizado_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por BIGINT UNSIGNED NULL,
    PRIMARY KEY (clave),
    CONSTRAINT fk_parametro_usuario FOREIGN KEY (actualizado_por) REFERENCES usuario (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO parametro (clave, valor) VALUES
    ('titular.constituida',          'false'),
    ('titular.razon_social',         '""'),
    ('titular.nif',                  '""'),
    ('titular.domicilio',            '""'),
    ('titular.registro_mercantil',   '""'),
    ('titular.email',                '"info@hyo.company"'),
    ('titular.telefono',             '""'),
    ('titular.email_privacidad',     '"info@hyo.company"'),
    ('precios.entrada_mes',          '15'),
    ('precios.completo_mes',         '29'),
    ('precios.iva_pct',              '21'),
    ('precios.descuento_anual_pct',  '0'),
    ('suscripcion.dias_prueba',      '30'),
    ('suscripcion.dias_gracia',      '7'),
    ('operacion.dias_borrado_tras_baja', '30'),
    ('marketplace.comision_pct',     '20'),
    ('marketplace.formacion_exenta_iva', 'false'),
    ('legal.version',                '"2026-10-17"');

ALTER TABLE usuario
    ADD COLUMN privacidad_version VARCHAR(20) NULL AFTER privacidad_aceptada_en;
