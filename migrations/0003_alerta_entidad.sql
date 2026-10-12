-- =====================================================================
-- Pyme Hub API · Migración 0003 · Índice de alertas por entidad
-- Motor: MySQL 8.4 LTS
--
-- El cálculo semanal de alertas de bienestar (RF-054) y, más adelante, el del
-- riesgo de rotación (RF-033) comprueban si ya hay una alerta abierta para el
-- mismo equipo o persona antes de crear otra. Este índice cubre esa consulta.
-- =====================================================================

ALTER TABLE alerta
    ADD KEY ix_alerta_entidad (interlocutor_id, tipo, entidad, entidad_id, estado, creado_en);
