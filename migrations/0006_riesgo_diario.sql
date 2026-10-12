-- =====================================================================
-- Pyme Hub API · Migración 0006 · Puntuación de riesgo diaria (fase 7, §9)
-- Motores: MariaDB 11.8 LTS y MySQL 8.4 LTS (RNF-009)
--
-- - puntuacion_riesgo.fecha: día (hora de España) al que corresponde la puntuación.
--   Una por persona y día: el recálculo bajo demanda sustituye la del día y el cron
--   diario (cli/recalc_risk.php) añade la del día siguiente. Así el histórico de la
--   ficha muestra la evolución sin duplicados (RF-031, RF-032).
-- =====================================================================

ALTER TABLE puntuacion_riesgo
    ADD COLUMN fecha DATE NULL AFTER usuario_id,
    ADD UNIQUE KEY uq_riesgo_dia (usuario_id, fecha),
    ADD KEY ix_riesgo_fecha (interlocutor_id, fecha, nivel);
