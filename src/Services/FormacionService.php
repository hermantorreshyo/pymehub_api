<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\ApiException;
use PymeHub\Core\Db;
use PymeHub\Core\Tiempo;
use PymeHub\Repositories\AsignacionRepository;
use PymeHub\Repositories\ModuloRepository;

/**
 * Reglas del módulo de formación (§10): corrección del cuestionario, validación de los
 * módulos propios, recomendaciones por reglas y resumen para la gestión.
 */
final class FormacionService
{
    /** RF-044: aprobado con ≥ 70 %. */
    public const APROBADO = 70;

    /** Ventana de las reglas de recomendación basadas en incidencias (RF-043). */
    private const DIAS_INCIDENCIAS = 90;
    private const DIAS_ACOGIDA = 30;

    private const TIPOS_BLOQUE = ['texto', 'lista', 'consejo', 'imagen'];

    /* ------------------------------------------------------------------ Cuestionario */

    /**
     * Corrige un intento. $respuestas: índice elegido por pregunta, en orden.
     * Devuelve la nota (0–100), si aprueba y el detalle por pregunta. La solución solo se
     * muestra cuando se aprueba, para que el cuestionario siga teniendo sentido.
     */
    public static function corregir(array $preguntas, array $respuestas): array
    {
        $aciertos = 0;
        $detalle = [];
        foreach ($preguntas as $i => $p) {
            $ok = isset($respuestas[$i]) && (int) $respuestas[$i] === $p['correcta'];
            $aciertos += $ok ? 1 : 0;
            $detalle[] = ['pregunta_id' => $p['id'], 'correcta' => $ok, 'elegida' => (int) $respuestas[$i]];
        }
        $nota = (int) round($aciertos / max(1, count($preguntas)) * 100);
        $aprobado = $nota >= self::APROBADO;
        if ($aprobado) {
            foreach ($detalle as $i => &$d) {
                $d['solucion'] = $preguntas[$i]['correcta'];
            }
            unset($d);
        }
        return ['nota' => $nota, 'aprobado' => $aprobado, 'aciertos' => $aciertos, 'total' => count($preguntas), 'detalle' => $detalle];
    }

    /** Comprueba que las respuestas encajan con las preguntas (una por pregunta, índice válido). */
    public static function validarRespuestas(array $preguntas, mixed $respuestas): array
    {
        if (!is_array($respuestas) || !array_is_list($respuestas) || count($respuestas) !== count($preguntas)) {
            throw ApiException::validation(['respuestas' => 'Responde a las ' . count($preguntas) . ' preguntas']);
        }
        foreach ($respuestas as $i => $r) {
            if (!is_int($r) || $r < 0 || $r >= count($preguntas[$i]['opciones'])) {
                throw ApiException::validation(['respuestas' => 'Respuesta no válida en la pregunta ' . ($i + 1)]);
            }
        }
        return $respuestas;
    }

    /* ------------------------------------------------------------------ Módulos propios (RF-046) */

    /**
     * Valida el contenido y las preguntas de un módulo propio. Sin IA: lo escribe la empresa.
     * @return array{contenido: array, preguntas: array}
     */
    public static function normalizarModulo(mixed $contenido, mixed $preguntas, string $categoria): array
    {
        $errores = [];
        $objetivos = [];
        $bloques = [];
        if (!is_array($contenido)) {
            $errores['contenido'] = 'Debe ser un objeto con objetivos y bloques';
        } else {
            foreach (array_keys($contenido) as $k) {
                if (!in_array($k, ['objetivos', 'bloques'], true)) {
                    $errores["contenido.$k"] = 'Campo no permitido';
                }
            }
            $obj = $contenido['objetivos'] ?? [];
            if (!is_array($obj) || !array_is_list($obj) || count($obj) < 1 || count($obj) > 5) {
                $errores['contenido.objetivos'] = 'Entre 1 y 5 objetivos';
            } else {
                foreach ($obj as $i => $o) {
                    $t = is_string($o) ? trim($o) : '';
                    if ($t === '' || mb_strlen($t) > 200) {
                        $errores["contenido.objetivos.$i"] = 'Texto de 1 a 200 caracteres';
                    }
                    $objetivos[] = $t;
                }
            }
            $bl = $contenido['bloques'] ?? [];
            if (!is_array($bl) || !array_is_list($bl) || count($bl) < 1 || count($bl) > 8) {
                $errores['contenido.bloques'] = 'Entre 1 y 8 bloques';
            } else {
                foreach ($bl as $i => $b) {
                    [$bloque, $error] = self::bloque($b, $categoria);
                    if ($error !== null) {
                        $errores["contenido.bloques.$i"] = $error;
                    }
                    $bloques[] = $bloque;
                }
            }
        }

        $salida = [];
        if (!is_array($preguntas) || !array_is_list($preguntas) || count($preguntas) < 2 || count($preguntas) > 4) {
            $errores['preguntas'] = 'Entre 2 y 4 preguntas (RF-041)';
        } else {
            foreach ($preguntas as $i => $p) {
                $enunciado = is_array($p) && is_string($p['enunciado'] ?? null) ? trim($p['enunciado']) : '';
                $opciones = is_array($p) && is_array($p['opciones'] ?? null) && array_is_list($p['opciones']) ? $p['opciones'] : [];
                $correcta = is_array($p) ? ($p['correcta'] ?? null) : null;
                $extra = is_array($p) ? array_diff(array_keys($p), ['enunciado', 'opciones', 'correcta']) : [];
                $ops = array_map(static fn ($o) => is_string($o) ? trim($o) : '', $opciones);
                if ($extra !== []) {
                    $errores["preguntas.$i"] = 'Campo no permitido: ' . implode(', ', $extra);
                } elseif ($enunciado === '' || mb_strlen($enunciado) > 300) {
                    $errores["preguntas.$i"] = 'El enunciado debe tener de 1 a 300 caracteres';
                } elseif (count($ops) < 2 || count($ops) > 4 || in_array('', $ops, true) || max(array_map('mb_strlen', $ops)) > 150) {
                    $errores["preguntas.$i"] = 'Entre 2 y 4 opciones de 1 a 150 caracteres';
                } elseif (!is_int($correcta) || $correcta < 0 || $correcta >= count($ops)) {
                    $errores["preguntas.$i"] = 'Indica cuál es la opción correcta';
                }
                $salida[] = ['enunciado' => $enunciado, 'opciones' => $ops, 'correcta' => is_int($correcta) ? $correcta : 0];
            }
        }
        if ($errores !== []) {
            throw ApiException::validation($errores);
        }
        return ['contenido' => ['objetivos' => $objetivos, 'bloques' => $bloques], 'preguntas' => $salida];
    }

    /** @return array{0: array, 1: ?string} bloque normalizado y error */
    private static function bloque(mixed $b, string $categoria): array
    {
        if (!is_array($b) || !in_array($b['tipo'] ?? null, self::TIPOS_BLOQUE, true)) {
            return [[], 'Tipo de bloque no válido (texto, lista, consejo o imagen)'];
        }
        $permitidos = match ($b['tipo']) {
            'texto'   => ['tipo', 'titulo', 'texto'],
            'lista'   => ['tipo', 'titulo', 'items'],
            'consejo' => ['tipo', 'texto'],
            'imagen'  => ['tipo', 'ilustracion', 'alt'],
        };
        $extra = array_diff(array_keys($b), $permitidos);
        if ($extra !== []) {
            return [[], 'Campo no permitido: ' . implode(', ', $extra)];
        }
        $txt = static fn (string $k, int $max) => is_string($b[$k] ?? null) && trim($b[$k]) !== '' && mb_strlen(trim($b[$k])) <= $max;
        switch ($b['tipo']) {
            case 'texto':
                if (!$txt('titulo', 80) || !$txt('texto', 1200)) {
                    return [[], 'Título (hasta 80) y texto (hasta 1.200 caracteres)'];
                }
                return [['tipo' => 'texto', 'titulo' => trim($b['titulo']), 'texto' => trim($b['texto'])], null];
            case 'lista':
                $items = $b['items'] ?? null;
                if (!$txt('titulo', 80) || !is_array($items) || !array_is_list($items) || count($items) < 1 || count($items) > 8) {
                    return [[], 'Título (hasta 80) y de 1 a 8 elementos'];
                }
                $out = [];
                foreach ($items as $it) {
                    if (!is_string($it) || trim($it) === '' || mb_strlen(trim($it)) > 200) {
                        return [[], 'Cada elemento, de 1 a 200 caracteres'];
                    }
                    $out[] = trim($it);
                }
                return [['tipo' => 'lista', 'titulo' => trim($b['titulo']), 'items' => $out], null];
            case 'consejo':
                if (!$txt('texto', 300)) {
                    return [[], 'Consejo de 1 a 300 caracteres'];
                }
                return [['tipo' => 'consejo', 'texto' => trim($b['texto'])], null];
            default:
                // Ilustración de marca local por categoría (RNF-048): no se suben imágenes en el MVP
                $ilu = $b['ilustracion'] ?? $categoria;
                if (!in_array($ilu, CatalogoService::CATEGORIAS, true) || !$txt('alt', 150)) {
                    return [[], 'Ilustración de una categoría y texto alternativo (hasta 150)'];
                }
                return [['tipo' => 'imagen', 'ilustracion' => $ilu, 'alt' => trim($b['alt'])], null];
        }
    }

    /* ------------------------------------------------------------------ Recomendaciones (RF-043) */

    /**
     * Recomendaciones por reglas, sin datos individuales de bienestar (§9.2):
     *  - siniestro en los últimos 90 días → seguridad vial (y conducción nocturna si trabaja de noche);
     *  - queja de cliente en los últimos 90 días → atención al cliente (y clientes difíciles si hay más de una);
     *  - su equipo tiene una alerta de bienestar abierta (dato agregado) → gestión del estrés;
     *  - menos de 30 días en la empresa → acogida (herramientas digitales y el módulo básico de su puesto).
     * Se omiten los módulos en los que la persona ya está (asignados, en curso o completados).
     */
    public static function recomendaciones(int $interlocutorId, ?int $usuarioId = null): array
    {
        $hoy = Tiempo::hoy();
        $desde = Tiempo::dia(-self::DIAS_INCIDENCIAS);
        $filtroUsuario = $usuarioId === null ? '' : ' AND u.id = ' . (int) $usuarioId;

        $personas = [];
        foreach (Db::all(
            "SELECT u.id, u.nombre, u.apellidos, f.puesto, f.turno, f.fecha_alta, f.equipo_id, e.nombre AS equipo_nombre
               FROM usuario u
               JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
               LEFT JOIN equipo e ON e.id = f.equipo_id AND e.interlocutor_id = u.interlocutor_id
              WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL AND f.fecha_baja IS NULL" . $filtroUsuario,
            [$interlocutorId]
        ) as $p) {
            $personas[(int) $p['id']] = $p;
        }
        if ($personas === []) {
            return [];
        }

        $incidencias = [];
        foreach (Db::all(
            "SELECT usuario_id, tipo, COUNT(*) AS n, MAX(fecha) AS ultima
               FROM incidencia
              WHERE interlocutor_id = ? AND tipo IN ('SINIESTRO', 'QUEJA_CLIENTE') AND fecha >= ?
              GROUP BY usuario_id, tipo",
            [$interlocutorId, $desde]
        ) as $r) {
            $incidencias[(int) $r['usuario_id']][$r['tipo']] = ['n' => (int) $r['n'], 'ultima' => $r['ultima']];
        }
        $equiposConAlerta = [];
        foreach (Db::all(
            "SELECT DISTINCT entidad_id FROM alerta
              WHERE interlocutor_id = ? AND tipo = 'BIENESTAR_EQUIPO' AND entidad = 'EQUIPO' AND estado = 'ABIERTA'",
            [$interlocutorId]
        ) as $r) {
            $equiposConAlerta[(int) $r['entidad_id']] = true;
        }

        $ya = AsignacionRepository::mapaPorUsuario($interlocutorId);
        $modulos = [];
        $modulo = static function (string $codigo) use (&$modulos): ?array {
            if (!array_key_exists($codigo, $modulos)) {
                $row = Db::one(
                    "SELECT m.id, m.codigo, m.titulo, m.categoria, m.duracion_min FROM modulo_formativo m
                       JOIN interlocutor i ON i.id = m.interlocutor_id
                      WHERE i.tipo = 'PLATAFORMA' AND m.codigo = ? AND m.estado = 'PUBLICADO' AND m.eliminado_en IS NULL",
                    [$codigo]
                );
                $modulos[$codigo] = $row === null ? null : [
                    'id' => (int) $row['id'], 'codigo' => $row['codigo'], 'titulo' => $row['titulo'],
                    'categoria' => $row['categoria'], 'duracion_min' => (int) $row['duracion_min'],
                ];
            }
            return $modulos[$codigo];
        };

        $out = [];
        $visto = [];
        $add = static function (array $p, string $codigo, string $regla, string $motivo) use (&$out, &$visto, $ya, $modulo): void {
            $m = $modulo($codigo);
            $uid = (int) $p['id'];
            if ($m === null || isset($ya[$uid][$m['id']]) || isset($visto[$uid][$m['id']])) {
                return;
            }
            $visto[$uid][$m['id']] = true;
            $out[] = [
                'empleado' => [
                    'id' => $uid, 'nombre' => $p['nombre'], 'apellidos' => $p['apellidos'], 'puesto' => $p['puesto'],
                    'equipo_id' => $p['equipo_id'] === null ? null : (int) $p['equipo_id'], 'equipo_nombre' => $p['equipo_nombre'],
                ],
                'modulo' => $m,
                'regla'  => $regla,
                'motivo' => $motivo,
            ];
        };

        foreach ($personas as $uid => $p) {
            $inc = $incidencias[$uid] ?? [];
            if (isset($inc['SINIESTRO'])) {
                $motivo = $inc['SINIESTRO']['n'] > 1
                    ? "{$inc['SINIESTRO']['n']} siniestros en los últimos 90 días."
                    : 'Un siniestro en los últimos 90 días.';
                $add($p, 'SEGURIDAD_VIAL_CIUDAD', 'SINIESTRO', $motivo);
                if ($p['turno'] === 'NOCHE') {
                    $add($p, 'CONDUCCION_NOCTURNA', 'SINIESTRO', $motivo . ' Trabaja en turno de noche.');
                }
            }
            if (isset($inc['QUEJA_CLIENTE'])) {
                $n = $inc['QUEJA_CLIENTE']['n'];
                $motivo = $n > 1 ? "$n quejas de cliente en los últimos 90 días." : 'Una queja de cliente en los últimos 90 días.';
                $add($p, 'ATENCION_CLIENTE', 'QUEJA_CLIENTE', $motivo);
                if ($n > 1) {
                    $add($p, 'CLIENTES_DIFICILES', 'QUEJA_CLIENTE', $motivo);
                }
            }
            if ($p['equipo_id'] !== null && isset($equiposConAlerta[(int) $p['equipo_id']])) {
                $add($p, 'GESTION_ESTRES', 'BIENESTAR_EQUIPO',
                    "Su equipo ({$p['equipo_nombre']}) tiene una alerta de bienestar abierta. Dato agregado del equipo, no de la persona.");
            }
            if ($p['fecha_alta'] !== null && $p['fecha_alta'] > Tiempo::sumarDias($hoy, -self::DIAS_ACOGIDA)) {
                $motivo = 'Lleva menos de 30 días en la empresa (acogida).';
                $add($p, 'HERRAMIENTAS_DIGITALES', 'ACOGIDA', $motivo);
                $add($p, match ($p['puesto']) {
                    'MOZO_ALMACEN' => 'MANIPULACION_CARGAS',
                    'COORDINADOR'  => 'COORDINADOR_RUTA',
                    default        => 'SEGURIDAD_VIAL_CIUDAD',
                }, 'ACOGIDA', $motivo);
            }
        }
        return $out;
    }

    /* ------------------------------------------------------------------ Resumen para la gestión */

    /** Indicadores de formación de la empresa: solo personas de alta. */
    public static function resumen(int $interlocutorId): array
    {
        $hoy = Tiempo::hoy();
        $estado = AsignacionRepository::ESTADO_EFECTIVO;
        $base = "FROM asignacion_formativa a
                 JOIN usuario u ON u.id = a.usuario_id AND u.interlocutor_id = a.interlocutor_id AND u.eliminado_en IS NULL
                 JOIN ficha_laboral f ON f.usuario_id = a.usuario_id AND f.interlocutor_id = a.interlocutor_id AND f.fecha_baja IS NULL
                WHERE a.interlocutor_id = ?";

        $t = Db::one(
            "SELECT COUNT(*) AS total,
                    SUM(a.origen = 'GESTION') AS asignadas,
                    SUM(a.origen <> 'GESTION') AS voluntarias,
                    SUM($estado = 'PENDIENTE') AS pendientes,
                    SUM($estado = 'EN_CURSO') AS en_curso,
                    SUM($estado = 'COMPLETADA') AS completadas,
                    SUM($estado = 'VENCIDA') AS vencidas,
                    AVG(CASE WHEN a.estado = 'COMPLETADA' THEN a.mejor_nota END) AS nota_media,
                    SUM(CASE WHEN a.estado = 'COMPLETADA' THEN 1 ELSE 0 END) AS completadas_total,
                    COUNT(DISTINCT a.usuario_id) AS personas
             $base",
            [$hoy, $hoy, $hoy, $hoy, $interlocutorId]
        ) ?? [];
        $total = (int) ($t['total'] ?? 0);
        $completadas = (int) ($t['completadas'] ?? 0);

        $porModulo = Db::all(
            "SELECT m.id, m.titulo, m.categoria,
                    COUNT(*) AS total,
                    SUM($estado = 'COMPLETADA') AS completadas,
                    SUM($estado = 'VENCIDA') AS vencidas
               FROM asignacion_formativa a
               JOIN modulo_formativo m ON m.id = a.modulo_id
               JOIN usuario u ON u.id = a.usuario_id AND u.interlocutor_id = a.interlocutor_id AND u.eliminado_en IS NULL
               JOIN ficha_laboral f ON f.usuario_id = a.usuario_id AND f.interlocutor_id = a.interlocutor_id AND f.fecha_baja IS NULL
              WHERE a.interlocutor_id = ?
              GROUP BY m.id, m.titulo, m.categoria
              ORDER BY total DESC, m.titulo
              LIMIT 8",
            [$hoy, $hoy, $interlocutorId]
        );
        $porEquipo = Db::all(
            "SELECT e.id, e.nombre,
                    COUNT(a.id) AS total,
                    SUM($estado = 'COMPLETADA') AS completadas,
                    SUM($estado = 'VENCIDA') AS vencidas
               FROM equipo e
               JOIN ficha_laboral f ON f.equipo_id = e.id AND f.interlocutor_id = e.interlocutor_id AND f.fecha_baja IS NULL
               JOIN usuario u ON u.id = f.usuario_id AND u.interlocutor_id = f.interlocutor_id AND u.eliminado_en IS NULL
               JOIN asignacion_formativa a ON a.usuario_id = u.id AND a.interlocutor_id = u.interlocutor_id
              WHERE e.interlocutor_id = ? AND e.eliminado_en IS NULL
              GROUP BY e.id, e.nombre
              ORDER BY e.nombre",
            [$hoy, $hoy, $interlocutorId]
        );
        $fila = static fn (array $r) => [
            'id'          => (int) $r['id'],
            'nombre'      => $r['nombre'] ?? $r['titulo'],
            'categoria'   => $r['categoria'] ?? null,
            'total'       => (int) $r['total'],
            'completadas' => (int) $r['completadas'],
            'vencidas'    => (int) $r['vencidas'],
            'tasa'        => (int) $r['total'] === 0 ? 0 : (int) round((int) $r['completadas'] / (int) $r['total'] * 100),
        ];

        return [
            'total'           => $total,
            'asignadas'       => (int) ($t['asignadas'] ?? 0),
            'voluntarias'     => (int) ($t['voluntarias'] ?? 0),
            'pendientes'      => (int) ($t['pendientes'] ?? 0),
            'en_curso'        => (int) ($t['en_curso'] ?? 0),
            'completadas'     => $completadas,
            'vencidas'        => (int) ($t['vencidas'] ?? 0),
            'tasa_finalizacion' => $total === 0 ? null : (int) round($completadas / $total * 100),
            'nota_media'      => $t['nota_media'] === null ? null : (int) round((float) $t['nota_media']),
            'personas'        => (int) ($t['personas'] ?? 0),
            'por_modulo'      => array_map(static fn ($r) => array_diff_key($fila($r), ['nombre' => 1]) + ['titulo' => $r['titulo']], $porModulo),
            'por_equipo'      => array_map(static fn ($r) => array_diff_key($fila($r), ['categoria' => 1]), $porEquipo),
            'recomendaciones' => count(self::recomendaciones($interlocutorId)),
        ];
    }

    /** Módulo visible para la empresa o PH-TENANT-001. */
    public static function moduloVisible(int $id, int $interlocutorId): array
    {
        $m = ModuloRepository::find($id, $interlocutorId);
        if ($m === null) {
            throw ApiException::notFound();
        }
        return $m;
    }
}
