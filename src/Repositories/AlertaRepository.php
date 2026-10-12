<?php
declare(strict_types=1);

namespace PymeHub\Repositories;

use PymeHub\Core\Db;

/**
 * Alertas de la empresa: bienestar de equipo (RF-054) y, con el plan Completo,
 * riesgo de rotación (RF-033). Todo acceso filtra por interlocutor_id (SEC-007).
 */
final class AlertaRepository
{
    private const SELECT = "SELECT a.id, a.tipo, a.entidad, a.entidad_id, a.severidad, a.mensaje, a.estado,
                                   a.gestionada_en, a.nota_gestion, a.creado_en,
                                   g.nombre AS g_nombre, g.apellidos AS g_apellidos,
                                   CASE a.entidad
                                     WHEN 'EQUIPO'  THEN (SELECT e.nombre FROM equipo e WHERE e.id = a.entidad_id AND e.interlocutor_id = a.interlocutor_id)
                                     WHEN 'USUARIO' THEN (SELECT CONCAT(u.nombre, ' ', u.apellidos) FROM usuario u WHERE u.id = a.entidad_id AND u.interlocutor_id = a.interlocutor_id)
                                   END AS entidad_nombre
                              FROM alerta a
                              LEFT JOIN usuario g ON g.id = a.gestionada_por
                             WHERE a.interlocutor_id = ?";

    /** @param string[] $tipos tipos visibles según el plan */
    public static function list(int $interlocutorId, array $tipos, ?string $estado, int $page, int $perPage): array
    {
        if ($tipos === []) {
            return [[], 0];
        }
        $marks = implode(',', array_fill(0, count($tipos), '?'));
        $where = " AND a.tipo IN ($marks)";
        $params = array_merge([$interlocutorId], $tipos);
        if ($estado !== null) {
            $where .= ' AND a.estado = ?';
            $params[] = $estado;
        }
        $total = (int) Db::value('SELECT COUNT(*) FROM alerta a WHERE a.interlocutor_id = ?' . $where, $params);
        $rows = Db::all(
            self::SELECT . $where . " ORDER BY a.estado = 'ABIERTA' DESC, FIELD(a.severidad, 'ALTA', 'MEDIA', 'BAJA'), a.creado_en DESC LIMIT ? OFFSET ?",
            array_merge($params, [$perPage, ($page - 1) * $perPage])
        );
        return [array_map([self::class, 'present'], $rows), $total];
    }

    public static function find(int $id, int $interlocutorId): ?array
    {
        $row = Db::one(self::SELECT . ' AND a.id = ?', [$interlocutorId, $id]);
        return $row === null ? null : self::present($row);
    }

    /** ¿Hay ya una alerta abierta de ese tipo para esa entidad desde una fecha? (evita duplicados) */
    public static function abiertaDesde(int $interlocutorId, string $tipo, string $entidad, int $entidadId, string $desdeUtc): bool
    {
        return Db::value(
            "SELECT 1 FROM alerta WHERE interlocutor_id = ? AND tipo = ? AND entidad = ? AND entidad_id = ?
                AND estado = 'ABIERTA' AND creado_en >= ? LIMIT 1",
            [$interlocutorId, $tipo, $entidad, $entidadId, $desdeUtc]
        ) !== null;
    }

    public static function create(int $interlocutorId, string $tipo, string $entidad, int $entidadId, string $severidad, string $mensaje): int
    {
        return Db::insert(
            'INSERT INTO alerta (interlocutor_id, tipo, entidad, entidad_id, severidad, mensaje) VALUES (?, ?, ?, ?, ?, ?)',
            [$interlocutorId, $tipo, $entidad, $entidadId, $severidad, mb_substr($mensaje, 0, 300)]
        );
    }

    public static function gestionar(int $id, int $interlocutorId, string $estado, ?string $nota, int $usuarioId): void
    {
        if ($estado === 'ABIERTA') {
            Db::run(
                'UPDATE alerta SET estado = ?, nota_gestion = NULL, gestionada_por = NULL, gestionada_en = NULL WHERE id = ? AND interlocutor_id = ?',
                [$estado, $id, $interlocutorId]
            );
            return;
        }
        Db::run(
            'UPDATE alerta SET estado = ?, nota_gestion = ?, gestionada_por = ?, gestionada_en = UTC_TIMESTAMP()
              WHERE id = ? AND interlocutor_id = ?',
            [$estado, $nota, $usuarioId, $id, $interlocutorId]
        );
    }

    public static function contarAbiertas(int $interlocutorId, array $tipos): int
    {
        if ($tipos === []) {
            return 0;
        }
        $marks = implode(',', array_fill(0, count($tipos), '?'));
        return (int) Db::value(
            "SELECT COUNT(*) FROM alerta WHERE interlocutor_id = ? AND estado = 'ABIERTA' AND tipo IN ($marks)",
            array_merge([$interlocutorId], $tipos)
        );
    }

    private static function present(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'tipo'           => $r['tipo'],
            'entidad'        => $r['entidad'],
            'entidad_id'     => (int) $r['entidad_id'],
            'entidad_nombre' => $r['entidad_nombre'],
            'severidad'      => $r['severidad'],
            'mensaje'        => $r['mensaje'],
            'estado'         => $r['estado'],
            'creado_en'      => self::iso($r['creado_en']),
            'gestion'        => $r['gestionada_en'] === null ? null : [
                'por'  => trim(($r['g_nombre'] ?? '') . ' ' . ($r['g_apellidos'] ?? '')) ?: null,
                'en'   => self::iso($r['gestionada_en']),
                'nota' => $r['nota_gestion'],
            ],
        ];
    }

    private static function iso(?string $utc): ?string
    {
        return $utc === null ? null : str_replace(' ', 'T', $utc) . 'Z';
    }
}
