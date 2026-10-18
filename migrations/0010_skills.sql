-- =====================================================================
-- Pyme Hub API · Migración 0010 · Marketplace Pyme Hub Skills (fase 9d, Stripe en modo prueba)
-- Motores: MariaDB 11.8 LTS y MySQL 8.4 LTS (RNF-009)
--
-- Los formadores son interlocutores de tipo FORMADOR (catálogo de 0001) con usuarios de perfil
-- FORMADOR. Un curso de Skills es un modulo_formativo cuyo propietario es un formador, con su
-- ficha comercial en curso_skills (precios y revisión de la plataforma).
-- - interlocutor.stripe_cuenta_id / stripe_cuenta_lista: cuenta conectada (Stripe Connect Express).
-- - curso_skills: precio de la licencia para empresa (sin IVA) y precio para particulares (IVA
--   incluido); estado de revisión BORRADOR, PENDIENTE, APROBADO, RECHAZADO o RETIRADO.
-- - compra_skills: compra de una empresa (licencia para toda la plantilla) o de una persona para
--   sí misma, con base, IVA, total, comisión de la plataforma e importe del formador.
-- =====================================================================

ALTER TABLE interlocutor
    ADD COLUMN stripe_cuenta_id    VARCHAR(64) NULL AFTER terminos_aceptados_por,
    ADD COLUMN stripe_cuenta_lista TINYINT(1)  NOT NULL DEFAULT 0 AFTER stripe_cuenta_id,
    ADD UNIQUE KEY uq_interlocutor_stripe_cuenta (stripe_cuenta_id);

CREATE TABLE curso_skills (
    modulo_id        BIGINT UNSIGNED NOT NULL,
    interlocutor_id  BIGINT UNSIGNED NOT NULL,
    precio_empresa   DECIMAL(8,2)  NOT NULL,
    precio_persona   DECIMAL(8,2)  NOT NULL,
    estado           VARCHAR(10)   NOT NULL DEFAULT 'BORRADOR',
    motivo           VARCHAR(300)  NULL,
    enviado_en       DATETIME      NULL,
    revisado_en      DATETIME      NULL,
    revisado_por     BIGINT UNSIGNED NULL,
    creado_en        DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en   DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (modulo_id),
    KEY ix_curso_skills_estado (estado),
    KEY ix_curso_skills_formador (interlocutor_id),
    CONSTRAINT fk_curso_skills_modulo   FOREIGN KEY (modulo_id) REFERENCES modulo_formativo (id),
    CONSTRAINT fk_curso_skills_formador FOREIGN KEY (interlocutor_id) REFERENCES interlocutor (id),
    CONSTRAINT fk_curso_skills_revisor  FOREIGN KEY (revisado_por) REFERENCES usuario (id) ON DELETE SET NULL,
    CONSTRAINT ck_curso_skills_estado   CHECK (estado IN ('BORRADOR','PENDIENTE','APROBADO','RECHAZADO','RETIRADO')),
    CONSTRAINT ck_curso_skills_precios  CHECK (precio_empresa >= 0 AND precio_persona >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE compra_skills (
    id                     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    modulo_id              BIGINT UNSIGNED NOT NULL,
    formador_id            BIGINT UNSIGNED NOT NULL,
    tipo                   VARCHAR(8)    NOT NULL,
    interlocutor_id        BIGINT UNSIGNED NOT NULL,
    usuario_id             BIGINT UNSIGNED NOT NULL,
    base                   DECIMAL(10,2) NOT NULL,
    iva                    DECIMAL(10,2) NOT NULL,
    total                  DECIMAL(10,2) NOT NULL,
    comision_pct           DECIMAL(5,2)  NOT NULL,
    comision               DECIMAL(10,2) NOT NULL,
    importe_formador       DECIMAL(10,2) NOT NULL,
    estado                 VARCHAR(12)   NOT NULL DEFAULT 'PENDIENTE',
    stripe_session_id      VARCHAR(80)   NULL,
    stripe_payment_intent  VARCHAR(80)   NULL,
    condiciones_version    VARCHAR(20)   NULL,
    renuncia_desistimiento TINYINT(1)    NOT NULL DEFAULT 0,
    ficticia               TINYINT(1)    NOT NULL DEFAULT 0,
    creado_en              DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    pagado_en              DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_compra_skills_sesion (stripe_session_id),
    KEY ix_compra_skills_empresa (interlocutor_id, estado),
    KEY ix_compra_skills_formador (formador_id, estado),
    KEY ix_compra_skills_modulo (modulo_id, estado),
    CONSTRAINT fk_compra_skills_modulo   FOREIGN KEY (modulo_id) REFERENCES modulo_formativo (id),
    CONSTRAINT fk_compra_skills_formador FOREIGN KEY (formador_id) REFERENCES interlocutor (id),
    CONSTRAINT fk_compra_skills_usuario  FOREIGN KEY (usuario_id, interlocutor_id) REFERENCES usuario (id, interlocutor_id),
    CONSTRAINT ck_compra_skills_tipo     CHECK (tipo IN ('EMPRESA','PERSONA')),
    CONSTRAINT ck_compra_skills_estado   CHECK (estado IN ('PENDIENTE','PAGADA','FALLIDA','REEMBOLSADA'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
