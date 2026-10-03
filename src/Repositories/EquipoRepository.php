<?php
declare(strict_types=1);

namespace PymeHub\Repositories;

use PymeHub\Core\Db;

/**
 * Equipos de una empresa. Borrado lógico (RNF-023); todo acceso filtra por
 * interlocutor_id (SEC-007).
 */
final class EquipoRepository
{
    private const SELECT = "SELECT e.id, e.nombre, e.zona, e.responsable_usuario_id, r.nombre AS r_nombre, r.apellidos AS r_apellidos,
                                   e.creado_en,
                                   (SELECT COUNT(*) FROM ficha_laboral f JOIN usuario u ON u.id = f.usuario_id
                                     WHERE f.interlocutor_id = e.interlocutor_id AND f.equipo_id = e.id
                                       AND f.fecha_baja IS NULL AND u.eliminado_en IS NULL) AS empleados
                              FROM equipo e
                              LEFT JOIN usuario r ON r.id = e.responsable_usuario_id AND r.interlocutor_id = e.interlocutor_id
                             WHERE e.interlocutor_id = ? AND e.eliminado_en IS NULL";

    public static function list(int $interlocutorId): array
    {
        return array_map([self::class, 'present'], Db::all(self::SELECT . ' ORDER BY e.nombre', [$interlocutorId]));
    }

    public static function find(int $id, int $interlocutorId): ?array
    {
        $row = Db::one(self::SELECT . ' AND e.id = ?', [$interlocutorId, $id]);
        return $row === null ? null : self::present($row);
    }

    /** ID del equipo vigente con ese nombre (la intercalación ignora mayúsculas y tildes). */
    public static function idPorNombre(int $interlocutorId, string $nombre): ?int
    {
        $id = Db::value('SELECT id FROM equipo WHERE interlocutor_id = ? AND nombre = ? AND eliminado_en IS NULL', [$interlocutorId, $nombre]);
        return $id === null ? null : (int) $id;
    }

    /** Equipos vigentes por nombre normalizado, para la importación CSV. */
    public static function mapaPorNombre(int $interlocutorId): array
    {
        $map = [];
        foreach (Db::all('SELECT id, nombre FROM equipo WHERE interlocutor_id = ? AND eliminado_en IS NULL', [$interlocutorId]) as $r) {
            $map[self::clave($r['nombre'])] = (int) $r['id'];
        }
        return $map;
    }

    /** Clave de comparación equivalente a utf8mb4_0900_ai_ci para nombres cortos. */
    public static function clave(string $nombre): string
    {
        $s = mb_strtolower(trim($nombre));
        return strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', 'à' => 'a', 'è' => 'e', 'ò' => 'o', 'ç' => 'c']);
    }

    public static function create(int $interlocutorId, string $nombre, ?string $zona, ?int $responsableId): int
    {
        return Db::insert(
            'INSERT INTO equipo (interlocutor_id, nombre, zona, responsable_usuario_id) VALUES (?, ?, ?, ?)',
            [$interlocutorId, $nombre, $zona, $responsableId]
        );
    }

    /** @param array<string, mixed> $cambios solo nombre, zona y responsable_usuario_id */
    public static function update(int $id, int $interlocutorId, array $cambios): void
    {
        $set = [];
        $params = [];
        foreach (['nombre', 'zona', 'responsable_usuario_id'] as $c) {
            if (array_key_exists($c, $cambios)) {
                $set[] = "$c = ?";
                $params[] = $cambios[$c];
            }
        }
        if ($set === []) {
            return;
        }
        array_push($params, $id, $interlocutorId);
        Db::run('UPDATE equipo SET ' . implode(', ', $set) . ' WHERE id = ? AND interlocutor_id = ? AND eliminado_en IS NULL', $params);
    }

    public static function softDelete(int $id, int $interlocutorId): void
    {
        Db::run('UPDATE equipo SET eliminado_en = UTC_TIMESTAMP() WHERE id = ? AND interlocutor_id = ? AND eliminado_en IS NULL', [$id, $interlocutorId]);
    }

    private static function present(array $r): array
    {
        return [
            'id'          => (int) $r['id'],
            'nombre'      => $r['nombre'],
            'zona'        => $r['zona'],
            'responsable' => $r['responsable_usuario_id'] === null ? null : [
                'id'        => (int) $r['responsable_usuario_id'],
                'nombre'    => $r['r_nombre'],
                'apellidos' => $r['r_apellidos'],
            ],
            'empleados'   => (int) $r['empleados'],
            'creado_en'   => $r['creado_en'],
        ];
    }
}
