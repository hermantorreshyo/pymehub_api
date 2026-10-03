<?php
declare(strict_types=1);

namespace PymeHub\Repositories;

use PymeHub\Core\Db;

/**
 * Incidencias de un empleado (LEG-012: sin bajas médicas ni su causa).
 */
final class IncidenciaRepository
{
    private const SELECT = 'SELECT i.id, i.usuario_id, i.tipo, i.fecha, i.nota, i.registrada_por, i.creado_en,
                                   r.nombre AS r_nombre, r.apellidos AS r_apellidos
                              FROM incidencia i
                              LEFT JOIN usuario r ON r.id = i.registrada_por
                             WHERE i.interlocutor_id = ?';

    /**
     * @param array{tipo?: ?string, desde?: ?string, hasta?: ?string} $filtros
     * @return array{0: array, 1: int} filas y total
     */
    public static function list(int $interlocutorId, int $usuarioId, array $filtros, int $page, int $perPage): array
    {
        $where = ' AND i.usuario_id = ?';
        $params = [$interlocutorId, $usuarioId];
        foreach (['tipo' => 'i.tipo = ?', 'desde' => 'i.fecha >= ?', 'hasta' => 'i.fecha <= ?'] as $k => $cond) {
            if (!empty($filtros[$k])) {
                $where .= " AND $cond";
                $params[] = $filtros[$k];
            }
        }
        $total = (int) Db::value('SELECT COUNT(*) FROM incidencia i WHERE i.interlocutor_id = ?' . $where, $params);
        $sql = self::SELECT . $where . ' ORDER BY i.fecha DESC, i.id DESC LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage);
        return [array_map([self::class, 'present'], Db::all($sql, $params)), $total];
    }

    public static function create(int $interlocutorId, int $usuarioId, string $tipo, string $fecha, ?string $nota, int $registradaPor): int
    {
        return Db::insert(
            'INSERT INTO incidencia (interlocutor_id, usuario_id, tipo, fecha, nota, registrada_por) VALUES (?, ?, ?, ?, ?, ?)',
            [$interlocutorId, $usuarioId, $tipo, $fecha, $nota, $registradaPor]
        );
    }

    public static function find(int $id, int $interlocutorId): ?array
    {
        $row = Db::one(self::SELECT . ' AND i.id = ?', [$interlocutorId, $id]);
        return $row === null ? null : self::present($row);
    }

    private static function present(array $r): array
    {
        return [
            'id'             => (int) $r['id'],
            'empleado_id'    => (int) $r['usuario_id'],
            'tipo'           => $r['tipo'],
            'fecha'          => $r['fecha'],
            'nota'           => $r['nota'],
            'registrada_por' => $r['registrada_por'] === null ? null : [
                'id'        => (int) $r['registrada_por'],
                'nombre'    => $r['r_nombre'],
                'apellidos' => $r['r_apellidos'],
            ],
            'creado_en'      => $r['creado_en'],
        ];
    }
}
