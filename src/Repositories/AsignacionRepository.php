<?php
declare(strict_types=1);

namespace PymeHub\Repositories;

use PymeHub\Core\Db;
use PymeHub\Core\Tiempo;

/**
 * Formación de cada empleado (asignacion_formativa). Origen: GESTION (asignada por gerencia
 * o RR. HH., con fecha límite), AUTOINSCRIPCION (el empleado, sin fecha límite, RF-047) o
 * ASESOR_IA (fase 6).
 *
 * Estado efectivo: una formación no completada con la fecha límite pasada se muestra como
 * VENCIDA. Las autoinscripciones no tienen fecha límite, así que nunca vencen (RF-047).
 */
final class AsignacionRepository
{
    public const ESTADO_EFECTIVO = "CASE WHEN a.estado <> 'COMPLETADA' AND a.fecha_limite IS NOT NULL AND a.fecha_limite < ?
                                         THEN 'VENCIDA' ELSE a.estado END";

    private const SELECT = "SELECT a.id, a.usuario_id, a.modulo_id, a.origen, a.fecha_limite, a.progreso_pct, a.bloque_actual,
                                   a.intentos, a.mejor_nota, a.ultima_nota, a.completada_en, a.creado_en, a.actualizado_en,
                                   %s AS estado_actual,
                                   m.titulo AS modulo_titulo, m.resumen AS modulo_resumen, m.categoria AS modulo_categoria,
                                   m.nivel AS modulo_nivel, m.duracion_min AS modulo_duracion, m.codigo AS modulo_codigo,
                                   u.nombre, u.apellidos, f.equipo_id, e.nombre AS equipo_nombre, f.puesto
                              FROM asignacion_formativa a
                              JOIN modulo_formativo m ON m.id = a.modulo_id
                              JOIN usuario u ON u.id = a.usuario_id AND u.interlocutor_id = a.interlocutor_id
                              LEFT JOIN ficha_laboral f ON f.usuario_id = a.usuario_id AND f.interlocutor_id = a.interlocutor_id
                              LEFT JOIN equipo e ON e.id = f.equipo_id AND e.interlocutor_id = a.interlocutor_id
                             WHERE a.interlocutor_id = ?";

    private static function select(): string
    {
        return sprintf(self::SELECT, self::ESTADO_EFECTIVO);
    }

    public static function find(int $id, int $interlocutorId): ?array
    {
        $row = Db::one(self::select() . ' AND a.id = ?', [Tiempo::hoy(), $interlocutorId, $id]);
        return $row === null ? null : self::present($row);
    }

    /** Asignación del empleado autenticado (PH-TENANT-001 si no es suya). */
    public static function propia(int $id, int $usuarioId, int $interlocutorId): ?array
    {
        $row = Db::one(self::select() . ' AND a.id = ? AND a.usuario_id = ?', [Tiempo::hoy(), $interlocutorId, $id, $usuarioId]);
        return $row === null ? null : self::present($row);
    }

    public static function existente(int $usuarioId, int $moduloId): ?array
    {
        return Db::one('SELECT id, origen, estado FROM asignacion_formativa WHERE usuario_id = ? AND modulo_id = ?', [$usuarioId, $moduloId]);
    }

    /** Formación de un empleado: primero lo pendiente con fecha más próxima, después lo completado. */
    public static function delUsuario(int $usuarioId, int $interlocutorId): array
    {
        $rows = Db::all(
            self::select() . " AND a.usuario_id = ?
             ORDER BY a.estado = 'COMPLETADA', a.fecha_limite IS NULL, a.fecha_limite, a.actualizado_en DESC",
            [Tiempo::hoy(), $interlocutorId, $usuarioId]
        );
        return array_map([self::class, 'present'], $rows);
    }

    /**
     * Seguimiento para gestión. Filtros: modulo_id, equipo_id, usuario_id, estado (efectivo), origen, q.
     * Solo personas de alta. @return array{0: array, 1: int}
     */
    public static function list(int $interlocutorId, array $f, int $page, int $perPage): array
    {
        $hoy = Tiempo::hoy();
        $where = ' AND f.fecha_baja IS NULL AND u.eliminado_en IS NULL';
        $params = [];
        foreach (['modulo_id' => 'a.modulo_id', 'equipo_id' => 'f.equipo_id', 'usuario_id' => 'a.usuario_id', 'origen' => 'a.origen'] as $k => $col) {
            if (!empty($f[$k])) {
                $where .= " AND $col = ?";
                $params[] = $f[$k];
            }
        }
        if (!empty($f['estado'])) {
            $where .= ' AND ' . self::ESTADO_EFECTIVO . ' = ?';
            $params[] = $hoy;
            $params[] = $f['estado'];
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $where .= ' AND (u.nombre LIKE ? OR u.apellidos LIKE ? OR m.titulo LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $total = (int) Db::value(
            'SELECT COUNT(*) FROM asignacion_formativa a
               JOIN modulo_formativo m ON m.id = a.modulo_id
               JOIN usuario u ON u.id = a.usuario_id AND u.interlocutor_id = a.interlocutor_id
               LEFT JOIN ficha_laboral f ON f.usuario_id = a.usuario_id AND f.interlocutor_id = a.interlocutor_id
              WHERE a.interlocutor_id = ?' . $where,
            [$interlocutorId, ...$params]
        );
        $offset = ($page - 1) * $perPage;
        $rows = Db::all(
            self::select() . $where . "
             ORDER BY FIELD(estado_actual, 'VENCIDA', 'EN_CURSO', 'PENDIENTE', 'COMPLETADA'), a.fecha_limite IS NULL, a.fecha_limite,
                      u.apellidos, u.nombre, a.id
             LIMIT $perPage OFFSET $offset",
            [$hoy, $interlocutorId, ...$params]
        );
        return [array_map([self::class, 'present'], $rows), $total];
    }

    /** Crea o, si ya existe, la convierte en asignación de gestión. Devuelve 'creada' | 'actualizada' | 'completada'. */
    public static function asignar(int $interlocutorId, int $usuarioId, int $moduloId, ?string $fechaLimite, int $autorId): string
    {
        $prev = self::existente($usuarioId, $moduloId);
        if ($prev === null) {
            Db::insert(
                "INSERT INTO asignacion_formativa (interlocutor_id, usuario_id, modulo_id, origen, asignada_por, fecha_limite)
                 VALUES (?, ?, ?, 'GESTION', ?, ?)",
                [$interlocutorId, $usuarioId, $moduloId, $autorId, $fechaLimite]
            );
            return 'creada';
        }
        if ($prev['estado'] === 'COMPLETADA') {
            return 'completada';
        }
        Db::run(
            "UPDATE asignacion_formativa SET origen = 'GESTION', asignada_por = ?, fecha_limite = ?
              WHERE id = ? AND interlocutor_id = ?",
            [$autorId, $fechaLimite, (int) $prev['id'], $interlocutorId]
        );
        return 'actualizada';
    }

    public static function inscribir(int $interlocutorId, int $usuarioId, int $moduloId, string $origen): int
    {
        return Db::insert(
            'INSERT INTO asignacion_formativa (interlocutor_id, usuario_id, modulo_id, origen) VALUES (?, ?, ?, ?)',
            [$interlocutorId, $usuarioId, $moduloId, $origen]
        );
    }

    /** Avance monótono: nunca retrocede si llega tarde un avance antiguo de la cola sin conexión. */
    public static function avanzar(int $id, int $interlocutorId, int $bloque, int $pct): void
    {
        Db::run(
            "UPDATE asignacion_formativa
                SET bloque_actual = GREATEST(bloque_actual, ?),
                    progreso_pct  = GREATEST(progreso_pct, ?),
                    estado        = IF(estado = 'PENDIENTE', 'EN_CURSO', estado)
              WHERE id = ? AND interlocutor_id = ?",
            [$bloque, $pct, $id, $interlocutorId]
        );
    }

    /** Registra un intento del cuestionario (RF-044: intentos ilimitados, se guarda la mejor nota). */
    public static function registrarIntento(int $id, int $interlocutorId, int $nota, bool $aprobado, string $envio): void
    {
        Db::run(
            "UPDATE asignacion_formativa
                SET intentos      = intentos + 1,
                    ultima_nota   = ?,
                    ultimo_envio  = ?,
                    mejor_nota    = GREATEST(COALESCE(mejor_nota, 0), ?),
                    progreso_pct  = IF(? = 1, 100, GREATEST(progreso_pct, 90)),
                    completada_en = IF(? = 1 AND completada_en IS NULL, UTC_TIMESTAMP(), completada_en),
                    estado        = IF(? = 1, 'COMPLETADA', IF(estado = 'COMPLETADA', 'COMPLETADA', 'EN_CURSO'))
              WHERE id = ? AND interlocutor_id = ?",
            [$nota, $envio, $nota, (int) $aprobado, (int) $aprobado, (int) $aprobado, $id, $interlocutorId]
        );
    }

    public static function ultimoEnvio(int $id): ?string
    {
        $v = Db::value('SELECT ultimo_envio FROM asignacion_formativa WHERE id = ?', [$id]);
        return $v === null ? null : (string) $v;
    }

    public static function delete(int $id, int $interlocutorId): void
    {
        Db::run('DELETE FROM asignacion_formativa WHERE id = ? AND interlocutor_id = ?', [$id, $interlocutorId]);
    }

    /** Módulos en los que ya está cada persona: [usuario_id => [modulo_id => true]] */
    public static function mapaPorUsuario(int $interlocutorId): array
    {
        $out = [];
        foreach (Db::all('SELECT usuario_id, modulo_id FROM asignacion_formativa WHERE interlocutor_id = ?', [$interlocutorId]) as $r) {
            $out[(int) $r['usuario_id']][(int) $r['modulo_id']] = true;
        }
        return $out;
    }

    public static function present(array $r): array
    {
        return [
            'id'            => (int) $r['id'],
            'origen'        => $r['origen'],
            'estado'        => $r['estado_actual'],
            'fecha_limite'  => $r['fecha_limite'],
            'progreso_pct'  => (int) $r['progreso_pct'],
            'bloque_actual' => (int) $r['bloque_actual'],
            'intentos'      => (int) $r['intentos'],
            'mejor_nota'    => $r['mejor_nota'] === null ? null : (int) $r['mejor_nota'],
            'ultima_nota'   => $r['ultima_nota'] === null ? null : (int) $r['ultima_nota'],
            'completada_en' => $r['completada_en'],
            'asignada_en'   => $r['creado_en'],
            'modulo'        => [
                'id'           => (int) $r['modulo_id'],
                'codigo'       => $r['modulo_codigo'],
                'titulo'       => $r['modulo_titulo'],
                'resumen'      => $r['modulo_resumen'],
                'categoria'    => $r['modulo_categoria'],
                'nivel'        => $r['modulo_nivel'],
                'duracion_min' => (int) $r['modulo_duracion'],
            ],
            'empleado'      => [
                'id'            => (int) $r['usuario_id'],
                'nombre'        => $r['nombre'],
                'apellidos'     => $r['apellidos'],
                'puesto'        => $r['puesto'],
                'equipo_id'     => $r['equipo_id'] === null ? null : (int) $r['equipo_id'],
                'equipo_nombre' => $r['equipo_nombre'],
            ],
        ];
    }
}
