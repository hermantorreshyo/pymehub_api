<?php
declare(strict_types=1);

namespace PymeHub\Repositories;

use PymeHub\Core\Db;

/**
 * Check-ins de bienestar (§11). Todo acceso filtra por interlocutor_id (SEC-007).
 * Los agregados nunca devuelven filas individuales ni notas (RF-053) y siempre
 * incluyen el número de personas distintas para aplicar el umbral de anonimato (LEG-002).
 */
final class CheckinRepository
{
    /** Índice 0–100 de una fila: media de ánimo, energía y (6 − estrés), escalada de 1–5 a 0–100 (§13.6). */
    private const INDICE_SQL = '((c.animo - 1) + (c.energia - 1) + (5 - c.estres)) / 12 * 100';

    /** Equipo actual del empleado (para guardar el equipo en el momento del check-in). */
    public static function equipoActual(int $usuarioId, int $interlocutorId): ?int
    {
        $id = Db::value('SELECT equipo_id FROM ficha_laboral WHERE usuario_id = ? AND interlocutor_id = ?', [$usuarioId, $interlocutorId]);
        return $id === null ? null : (int) $id;
    }

    /** RF-051: un check-in por día; repetirlo actualiza el del día. Devuelve true si es nuevo. */
    public static function guardar(int $interlocutorId, int $usuarioId, ?int $equipoId, string $fecha, int $animo, int $energia, int $estres, ?string $notaCifrada): bool
    {
        $existe = Db::value('SELECT id FROM checkin_bienestar WHERE usuario_id = ? AND fecha = ?', [$usuarioId, $fecha]);
        if ($existe === null) {
            Db::insert(
                'INSERT INTO checkin_bienestar (interlocutor_id, usuario_id, equipo_id, fecha, animo, energia, estres, nota_cifrada)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                [$interlocutorId, $usuarioId, $equipoId, $fecha, $animo, $energia, $estres, $notaCifrada]
            );
            return true;
        }
        Db::run(
            'UPDATE checkin_bienestar SET animo = ?, energia = ?, estres = ?, nota_cifrada = ?
              WHERE id = ? AND interlocutor_id = ? AND usuario_id = ?',
            [$animo, $energia, $estres, $notaCifrada, (int) $existe, $interlocutorId, $usuarioId]
        );
        return false;
    }

    /** Check-ins propios desde una fecha, del más reciente al más antiguo (RF-052). */
    public static function propios(int $usuarioId, int $interlocutorId, string $desde): array
    {
        return Db::all(
            'SELECT c.fecha, c.animo, c.energia, c.estres, c.nota_cifrada, ' . self::INDICE_SQL . ' AS indice, c.actualizado_en
               FROM checkin_bienestar c
              WHERE c.usuario_id = ? AND c.interlocutor_id = ? AND c.fecha >= ?
              ORDER BY c.fecha DESC',
            [$usuarioId, $interlocutorId, $desde]
        );
    }

    public static function delDia(int $usuarioId, int $interlocutorId, string $fecha): ?array
    {
        return Db::one(
            'SELECT c.fecha, c.animo, c.energia, c.estres, c.nota_cifrada, ' . self::INDICE_SQL . ' AS indice, c.actualizado_en
               FROM checkin_bienestar c WHERE c.usuario_id = ? AND c.interlocutor_id = ? AND c.fecha = ?',
            [$usuarioId, $interlocutorId, $fecha]
        );
    }

    /**
     * Medias semanales (semana de lunes a domingo) de la empresa o de un equipo.
     * Devuelve [lunes => fila] con participantes = personas distintas.
     */
    public static function semanal(int $interlocutorId, ?int $equipoId, string $desde, string $hasta): array
    {
        $params = [$interlocutorId, $desde, $hasta];
        $filtroEquipo = '';
        if ($equipoId !== null) {
            $filtroEquipo = ' AND c.equipo_id = ?';
            $params[] = $equipoId;
        }
        $rows = Db::all(
            'SELECT DATE_SUB(c.fecha, INTERVAL WEEKDAY(c.fecha) DAY) AS semana,
                    COUNT(DISTINCT c.usuario_id) AS participantes,
                    COUNT(*) AS respuestas,
                    AVG(c.animo) AS animo, AVG(c.energia) AS energia, AVG(c.estres) AS estres,
                    AVG(' . self::INDICE_SQL . ') AS indice
               FROM checkin_bienestar c
              WHERE c.interlocutor_id = ? AND c.fecha BETWEEN ? AND ?' . $filtroEquipo . '
              GROUP BY semana
              ORDER BY semana',
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['semana']] = $r;
        }
        return $out;
    }

    /** Agregado por equipo en un periodo (solo equipos vigentes). */
    public static function porEquipo(int $interlocutorId, string $desde, string $hasta): array
    {
        return Db::all(
            'SELECT e.id, e.nombre,
                    COUNT(DISTINCT c.usuario_id) AS participantes,
                    AVG(c.animo) AS animo, AVG(c.energia) AS energia, AVG(c.estres) AS estres,
                    AVG(' . self::INDICE_SQL . ') AS indice
               FROM equipo e
               LEFT JOIN checkin_bienestar c
                      ON c.equipo_id = e.id AND c.interlocutor_id = e.interlocutor_id AND c.fecha BETWEEN ? AND ?
              WHERE e.interlocutor_id = ? AND e.eliminado_en IS NULL
              GROUP BY e.id, e.nombre
              ORDER BY e.nombre',
            [$desde, $hasta, $interlocutorId]
        );
    }

    /** Plantilla activa del ámbito (para mostrar la participación agregada, nunca individual). */
    public static function plantillaActiva(int $interlocutorId, ?int $equipoId): int
    {
        $sql = "SELECT COUNT(*) FROM usuario u JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
                 WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL AND f.fecha_baja IS NULL";
        $params = [$interlocutorId];
        if ($equipoId !== null) {
            $sql .= ' AND f.equipo_id = ?';
            $params[] = $equipoId;
        }
        return (int) Db::value($sql, $params);
    }

    /** LEG-008: borra los check-ins individuales anteriores a una fecha. Devuelve cuántos. */
    public static function purgarAnteriores(string $fecha): int
    {
        return Db::run('DELETE FROM checkin_bienestar WHERE fecha < ?', [$fecha]);
    }
}
