<?php
declare(strict_types=1);

namespace PymeHub\Repositories;

use PymeHub\Core\Db;

/**
 * Empleado = usuario con perfil EMPLEADO + ficha_laboral 1:1 (RF-122).
 * Todo acceso filtra por interlocutor_id (SEC-007).
 */
final class EmpleadoRepository
{
    public const COLS_USUARIO = ['nombre', 'apellidos', 'email'];
    public const COLS_FICHA   = ['codigo_interno', 'puesto', 'tipo_contrato', 'turno', 'fecha_alta', 'equipo_id'];

    private const SELECT = "SELECT u.id, u.nombre, u.apellidos, u.email, u.estado_acceso, u.ultimo_acceso_en, u.creado_en,
                                   f.codigo_interno, f.puesto, f.tipo_contrato, f.turno, f.fecha_alta, f.fecha_baja, f.motivo_baja,
                                   f.equipo_id, e.nombre AS equipo_nombre
                              FROM usuario u
                              JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
                              LEFT JOIN equipo e ON e.id = f.equipo_id AND e.interlocutor_id = f.interlocutor_id
                             WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL";

    public static function find(int $id, int $interlocutorId): ?array
    {
        $row = Db::one(self::SELECT . ' AND u.id = ?', [$interlocutorId, $id]);
        return $row === null ? null : self::present($row);
    }

    /**
     * @param array{situacion?: ?string, equipo_id?: ?int, q?: ?string} $filtros
     * @return array{0: array, 1: int} filas y total
     */
    public static function list(int $interlocutorId, array $filtros, int $page, int $perPage): array
    {
        $where = '';
        $params = [$interlocutorId];
        $situacion = $filtros['situacion'] ?? 'ALTA';
        if ($situacion === 'ALTA') {
            $where .= ' AND f.fecha_baja IS NULL';
        } elseif ($situacion === 'BAJA') {
            $where .= ' AND f.fecha_baja IS NOT NULL';
        }
        if (!empty($filtros['equipo_id'])) {
            $where .= ' AND f.equipo_id = ?';
            $params[] = (int) $filtros['equipo_id'];
        }
        if (!empty($filtros['q'])) {
            $like = '%' . addcslashes((string) $filtros['q'], '%_\\') . '%';
            $where .= ' AND (u.nombre LIKE ? OR u.apellidos LIKE ? OR f.codigo_interno LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like, $like, $like);
        }

        $total = (int) Db::value(
            "SELECT COUNT(*) FROM usuario u JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
              WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL" . $where,
            $params
        );
        // Enteros ya validados y convertidos: seguros para LIMIT/OFFSET
        $sql = self::SELECT . $where . ' ORDER BY u.apellidos, u.nombre, u.id LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage);
        return [array_map([self::class, 'present'], Db::all($sql, $params)), $total];
    }

    /** Empleados de alta (cuentan para limite_empleados). */
    public static function contarActivos(int $interlocutorId): int
    {
        return (int) Db::value(
            "SELECT COUNT(*) FROM usuario u JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
              WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL AND f.fecha_baja IS NULL",
            [$interlocutorId]
        );
    }

    /** ID del empleado que usa ese código interno en la empresa (incluidos bajas y suprimidos). */
    public static function idPorCodigo(int $interlocutorId, string $codigo): ?int
    {
        $id = Db::value('SELECT usuario_id FROM ficha_laboral WHERE interlocutor_id = ? AND codigo_interno = ?', [$interlocutorId, $codigo]);
        return $id === null ? null : (int) $id;
    }

    /** Fichas con código interno de la empresa, para la importación CSV. */
    public static function porCodigo(int $interlocutorId): array
    {
        return Db::all(
            'SELECT u.id, u.nombre, u.apellidos, u.email, u.estado_acceso, u.eliminado_en,
                    f.codigo_interno, f.puesto, f.tipo_contrato, f.turno, f.fecha_alta, f.fecha_baja, f.equipo_id
               FROM ficha_laboral f JOIN usuario u ON u.id = f.usuario_id AND u.interlocutor_id = f.interlocutor_id
              WHERE f.interlocutor_id = ? AND f.codigo_interno IS NOT NULL',
            [$interlocutorId]
        );
    }

    /** Crea usuario EMPLEADO en SIN_ACCESO y su ficha laboral. Llamar dentro de una transacción. */
    public static function create(int $interlocutorId, array $d): int
    {
        $id = UsuarioRepository::create($interlocutorId, 'EMPRESA', 'EMPLEADO', $d['nombre'], $d['apellidos'] ?? '', $d['email'] ?? null);
        Db::insert(
            'INSERT INTO ficha_laboral (usuario_id, interlocutor_id, equipo_id, codigo_interno, puesto, tipo_contrato, turno, fecha_alta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $interlocutorId, $d['equipo_id'] ?? null, $d['codigo_interno'] ?? null, $d['puesto'], $d['tipo_contrato'], $d['turno'], $d['fecha_alta']]
        );
        return $id;
    }

    /** Actualiza solo las columnas recibidas (lista blanca). */
    public static function update(int $id, int $interlocutorId, array $cambios): void
    {
        foreach ([['usuario', 'id', self::COLS_USUARIO], ['ficha_laboral', 'usuario_id', self::COLS_FICHA]] as [$tabla, $pk, $cols]) {
            $set = [];
            $params = [];
            foreach ($cols as $c) {
                if (array_key_exists($c, $cambios)) {
                    $set[] = "$c = ?";
                    $params[] = $c === 'apellidos' ? (string) $cambios[$c] : $cambios[$c];
                }
            }
            if ($set !== []) {
                array_push($params, $id, $interlocutorId);
                Db::run("UPDATE $tabla SET " . implode(', ', $set) . " WHERE $pk = ? AND interlocutor_id = ?", $params);
            }
        }
    }

    /** Baja laboral: conserva el usuario, retira el acceso y anula invitaciones pendientes. */
    public static function registrarBaja(int $id, int $interlocutorId, string $fecha, string $motivo): void
    {
        Db::run('UPDATE ficha_laboral SET fecha_baja = ?, motivo_baja = ? WHERE usuario_id = ? AND interlocutor_id = ?', [$fecha, $motivo, $id, $interlocutorId]);
        Db::run("UPDATE usuario SET estado_acceso = 'BAJA', password_hash = NULL WHERE id = ? AND interlocutor_id = ?", [$id, $interlocutorId]);
        Db::run('UPDATE invitacion SET usada_en = UTC_TIMESTAMP() WHERE usuario_id = ? AND usada_en IS NULL', [$id]);
    }

    private static function present(array $r): array
    {
        return [
            'id'               => (int) $r['id'],
            'nombre'           => $r['nombre'],
            'apellidos'        => $r['apellidos'],
            'email'            => $r['email'],
            'estado_acceso'    => $r['estado_acceso'],
            'codigo_interno'   => $r['codigo_interno'],
            'puesto'           => $r['puesto'],
            'tipo_contrato'    => $r['tipo_contrato'],
            'turno'            => $r['turno'],
            'fecha_alta'       => $r['fecha_alta'],
            'fecha_baja'       => $r['fecha_baja'],
            'motivo_baja'      => $r['motivo_baja'],
            'equipo'           => $r['equipo_id'] === null ? null : ['id' => (int) $r['equipo_id'], 'nombre' => $r['equipo_nombre']],
            'ultimo_acceso_en' => $r['ultimo_acceso_en'],
            'creado_en'        => $r['creado_en'],
        ];
    }
}
