-- =====================================================================
-- Pyme Hub API · Migración 0009 · Vigencia de suscripciones y pagos con Stripe (modo prueba)
-- Motores: MariaDB 11.8 LTS y MySQL 8.4 LTS (RNF-009)
--
-- - suscripcion.vigencia: PRUEBA, VIGENTE, GRACIA o VENCIDA (fase 9b). Se calcula a partir de
--   periodo_fin (último día pagado o de prueba) y de los días de gracia del parámetro
--   suscripcion.dias_gracia. periodo_fin NULL = sin vencimiento (empresas existentes, demo,
--   acuerdos gestionados a mano por la plataforma).
-- - suscripcion.stripe_*: enlace con la suscripción de Stripe (fase 9c); empleados_facturados es
--   la cantidad que tiene Stripe, sincronizada a diario con los empleados activos.
-- - interlocutor.stripe_customer_id y aceptación de los términos de contratación.
-- - pago: facturas y pagos (de Stripe o registrados a mano), con base, IVA y total.
-- - evento_stripe: eventos de webhook ya procesados (idempotencia).
-- =====================================================================

ALTER TABLE suscripcion
    ADD COLUMN vigencia               VARCHAR(10)  NOT NULL DEFAULT 'VIGENTE' AFTER estado,
    ADD COLUMN periodo_fin            DATE         NULL AFTER vigencia,
    ADD COLUMN empleados_facturados   INT UNSIGNED NULL AFTER periodo_fin,
    ADD COLUMN stripe_subscription_id VARCHAR(64)  NULL AFTER empleados_facturados,
    ADD COLUMN stripe_item_id         VARCHAR(64)  NULL AFTER stripe_subscription_id,
    ADD COLUMN cancelar_al_final      TINYINT(1)   NOT NULL DEFAULT 0 AFTER stripe_item_id,
    ADD CONSTRAINT ck_suscripcion_vigencia CHECK (vigencia IN ('PRUEBA','VIGENTE','GRACIA','VENCIDA')),
    ADD KEY ix_suscripcion_stripe (stripe_subscription_id);

ALTER TABLE interlocutor
    ADD COLUMN stripe_customer_id     VARCHAR(64)     NULL AFTER fecha_baja,
    ADD COLUMN terminos_version       VARCHAR(20)     NULL AFTER stripe_customer_id,
    ADD COLUMN terminos_aceptados_en  DATETIME        NULL AFTER terminos_version,
    ADD COLUMN terminos_aceptados_por BIGINT UNSIGNED NULL AFTER terminos_aceptados_en,
    ADD UNIQUE KEY uq_interlocutor_stripe (stripe_customer_id);

CREATE TABLE pago (
    id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    interlocutor_id   BIGINT UNSIGNED NOT NULL,
    origen            VARCHAR(10)   NOT NULL DEFAULT 'STRIPE',
    stripe_invoice_id VARCHAR(64)   NULL,
    numero            VARCHAR(60)   NULL,
    estado            VARCHAR(10)   NOT NULL,
    plan              VARCHAR(10)   NULL,
    ciclo_cobro       VARCHAR(10)   NULL,
    empleados         INT UNSIGNED  NULL,
    base              DECIMAL(10,2) NOT NULL DEFAULT 0,
    iva               DECIMAL(10,2) NOT NULL DEFAULT 0,
    total             DECIMAL(10,2) NOT NULL DEFAULT 0,
    moneda            CHAR(3)       NOT NULL DEFAULT 'EUR',
    periodo_inicio    DATE          NULL,
    periodo_fin       DATE          NULL,
    url_factura       VARCHAR(500)  NULL,
    url_pdf           VARCHAR(500)  NULL,
    nota              VARCHAR(200)  NULL,
    creado_en         DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pago_stripe (stripe_invoice_id),
    KEY ix_pago_interlocutor (interlocutor_id, creado_en),
    CONSTRAINT fk_pago_interlocutor FOREIGN KEY (interlocutor_id) REFERENCES interlocutor (id),
    CONSTRAINT ck_pago_origen CHECK (origen IN ('STRIPE','MANUAL')),
    CONSTRAINT ck_pago_estado CHECK (estado IN ('PAGADO','FALLIDO','PENDIENTE','ANULADO'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE evento_stripe (
    id          VARCHAR(80)  NOT NULL,
    tipo        VARCHAR(80)  NOT NULL,
    resultado   VARCHAR(20)  NOT NULL,
    recibido_en DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ix_evento_stripe_fecha (recibido_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
