<?php
declare(strict_types=1);

namespace PymeHub\Repositories;

use PymeHub\Core\Db;

/**
 * Micro-módulos de formación (§10). El propietario es un interlocutor: la plataforma publica
 * el catálogo global y cada empresa puede crear módulos propios (RF-046).
 * Una empresa solo ve el catálogo global y sus propios módulos (RNF-022).
 */
final class ModuloRepository
{
    private const SELECT = "SELECT m.id, m.interlocutor_id, m.codigo, m.titulo, m.resumen, m.categoria, m.nivel,
                                   m.puestos_destino, m.etiquetas, m.duracion_min, m.contenido, m.estado, m.version,
                                   m.creado_en, m.actualizado_en, i.tipo AS propietario_tipo
                              FROM modulo_formativo m
                              JOIN interlocutor i ON i.id = m.interlocutor_id
                             WHERE m.eliminado_en IS NULL
                               AND (i.tipo = 'PLATAFORMA' OR m.interlocutor_id = ?
                                    OR m.id IN (SELECT c.modulo_id FROM compra_skills c
                                                 WHERE c.interlocutor_id = ? AND c.estado = 'PAGADA'
                                                   AND (c.tipo = 'EMPRESA' OR c.usuario_id = ?)))";

    /**
     * Parámetros de visibilidad: catálogo global, módulos propios y cursos de Pyme Hub Skills
     * comprados (fase 9d) por la empresa (licencia para toda la plantilla) o por la propia persona.
     */
    private static function vis(int $interlocutorId): array
    {
        return [$interlocutorId, $interlocutorId, \PymeHub\Core\Context::usuarioId()];
    }

    /**
     * Módulos visibles para una empresa.
     * @param array{categoria?: ?string, nivel?: ?string, estado?: ?string, q?: ?string, puesto?: ?string} $f
     * @param bool $soloPublicados empleados: solo módulos publicados
     */
    public static function list(int $interlocutorId, array $f, bool $soloPublicados): array
    {
        $sql = self::SELECT;
        $params = self::vis($interlocutorId);
        if ($soloPublicados) {
            $sql .= " AND m.estado = 'PUBLICADO'";
        } elseif (!empty($f['estado'])) {
            $sql .= ' AND m.estado = ?';
            $params[] = $f['estado'];
        } else {
            $sql .= " AND m.estado <> 'ARCHIVADO'";
        }
        // Los borradores de la plataforma no existen; los de la empresa solo los ve la gestión
        $sql .= " AND (i.tipo <> 'PLATAFORMA' OR m.estado = 'PUBLICADO')";
        foreach (['categoria', 'nivel'] as $campo) {
            if (!empty($f[$campo])) {
                $sql .= " AND m.$campo = ?";
                $params[] = $f[$campo];
            }
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $sql .= ' AND (m.titulo LIKE ? OR m.resumen LIKE ? OR m.etiquetas LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $sql .= " ORDER BY FIELD(m.categoria, 'CONDUCCION', 'ALMACEN', 'CLIENTE', 'BIENESTAR', 'DESARROLLO'), m.titulo";
        $rows = array_map(static fn (array $r) => self::present($r, false, $interlocutorId), Db::all($sql, $params));
        if (!empty($f['puesto'])) {
            $rows = array_values(array_filter($rows, static fn (array $m) => in_array($f['puesto'], $m['puestos_destino'], true)));
        }
        return $rows;
    }

    /** Módulo visible para la empresa (global o propio), con su contenido. */
    public static function find(int $id, int $interlocutorId): ?array
    {
        $row = Db::one(self::SELECT . ' AND m.id = ?', [...self::vis($interlocutorId), $id]);
        return $row === null ? null : self::present($row, true, $interlocutorId);
    }

    public static function idPorCodigo(string $codigo): ?int
    {
        $id = Db::value(
            "SELECT m.id FROM modulo_formativo m JOIN interlocutor i ON i.id = m.interlocutor_id
              WHERE i.tipo = 'PLATAFORMA' AND m.codigo = ? AND m.eliminado_en IS NULL",
            [$codigo]
        );
        return $id === null ? null : (int) $id;
    }

    /** Preguntas en orden. Con $conSolucion = false no se incluye el índice correcto (vista del empleado). */
    public static function preguntas(int $moduloId, bool $conSolucion): array
    {
        $rows = Db::all('SELECT id, orden, enunciado, opciones, indice_correcto FROM pregunta_modulo WHERE modulo_id = ? ORDER BY orden', [$moduloId]);
        return array_map(static function (array $r) use ($conSolucion): array {
            $p = [
                'id'        => (int) $r['id'],
                'orden'     => (int) $r['orden'],
                'enunciado' => $r['enunciado'],
                'opciones'  => json_decode((string) $r['opciones'], true) ?: [],
            ];
            if ($conSolucion) {
                $p['correcta'] = (int) $r['indice_correcto'];
            }
            return $p;
        }, $rows);
    }

    public static function create(int $interlocutorId, array $d): int
    {
        return Db::insert(
            "INSERT INTO modulo_formativo (interlocutor_id, codigo, titulo, resumen, categoria, nivel, puestos_destino,
                                           etiquetas, duracion_min, contenido, estado)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'BORRADOR')",
            [
                $interlocutorId, $d['codigo'], $d['titulo'], $d['resumen'], $d['categoria'], $d['nivel'],
                json_encode($d['puestos_destino']), json_encode($d['etiquetas'], JSON_UNESCAPED_UNICODE),
                $d['duracion_min'], json_encode($d['contenido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );
    }

    /** Actualiza un módulo propio. Si estaba publicado, sube la versión. */
    public static function update(int $id, int $interlocutorId, array $d): void
    {
        $sets = [];
        $params = [];
        // La versión va primero: MySQL evalúa las asignaciones de izquierda a derecha
        if (isset($d['contenido']) || isset($d['preguntas'])) {
            $sets[] = "version = version + IF(estado = 'PUBLICADO', 1, 0)";
        }
        foreach (['titulo', 'resumen', 'categoria', 'nivel', 'duracion_min', 'estado'] as $c) {
            if (array_key_exists($c, $d)) {
                $sets[] = "$c = ?";
                $params[] = $d[$c];
            }
        }
        foreach (['puestos_destino', 'etiquetas', 'contenido'] as $c) {
            if (array_key_exists($c, $d)) {
                $sets[] = "$c = ?";
                $params[] = json_encode($d[$c], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        if ($sets === []) {
            return;
        }
        $params[] = $id;
        $params[] = $interlocutorId;
        Db::run('UPDATE modulo_formativo SET ' . implode(', ', $sets) . ' WHERE id = ? AND interlocutor_id = ?', $params);
    }

    /** propio: el módulo es del interlocutor que consulta (empresa o formador); skills: curso de un formador. */
    public static function present(array $r, bool $detalle = false, ?int $interlocutorId = null): array
    {
        $contenido = json_decode((string) $r['contenido'], true) ?: [];
        $m = [
            'id'              => (int) $r['id'],
            'codigo'          => $r['codigo'],
            'titulo'          => $r['titulo'],
            'resumen'         => $r['resumen'],
            'categoria'       => $r['categoria'],
            'nivel'           => $r['nivel'],
            'puestos_destino' => json_decode((string) $r['puestos_destino'], true) ?: [],
            'etiquetas'       => json_decode((string) $r['etiquetas'], true) ?: [],
            'duracion_min'    => (int) $r['duracion_min'],
            'estado'          => $r['estado'],
            'version'         => (int) $r['version'],
            'propio'          => $interlocutorId !== null ? (int) $r['interlocutor_id'] === $interlocutorId : $r['propietario_tipo'] === 'EMPRESA',
            'skills'          => $r['propietario_tipo'] === 'FORMADOR',
            'bloques'         => count($contenido['bloques'] ?? []),
        ];
        if ($detalle) {
            $m['contenido'] = [
                'objetivos' => array_values($contenido['objetivos'] ?? []),
                'bloques'   => array_values($contenido['bloques'] ?? []),
            ];
            $m['interlocutor_id'] = (int) $r['interlocutor_id'];
        }
        return $m;
    }
}
