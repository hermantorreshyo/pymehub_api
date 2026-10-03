-- =====================================================================
-- Pyme Hub API · Migración 0002 · Nombre de equipo único solo entre vigentes
-- Motor: MySQL 8.4 LTS
--
-- El índice uq_equipo_nombre (interlocutor_id, nombre) de la 0001 incluía
-- los equipos borrados (borrado lógico, RNF-023), así que no se podía volver
-- a crear un equipo con el nombre de uno eliminado. Se sustituye por una
-- columna generada que solo vale el nombre mientras el equipo está vigente.
-- =====================================================================

ALTER TABLE equipo
    ADD COLUMN nombre_vigente VARCHAR(80)
        GENERATED ALWAYS AS (IF(eliminado_en IS NULL, nombre, NULL)) STORED AFTER nombre,
    ADD UNIQUE KEY uq_equipo_nombre_vigente (interlocutor_id, nombre_vigente);

-- En sentencia aparte: el índice nuevo ya cubre la FK fk_equipo_interlocutor
ALTER TABLE equipo DROP INDEX uq_equipo_nombre;
