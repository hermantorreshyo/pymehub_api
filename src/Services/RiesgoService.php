<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\Db;
use PymeHub\Core\Tiempo;
use PymeHub\Repositories\AlertaRepository;

/**
 * Motor de riesgo de rotación v1.1 (§9): reglas ponderadas por la fuerza de la evidencia,
 * definidas en config/risk_rules.php. No es un modelo estadístico (§9.5).
 *
 * - Solo datos que la gerencia ya maneja: alta, contrato, turno, incidencias laborales y
 *   formación. Nunca bienestar individual, salud ni participación en el check-in (§9.2, LEG-013).
 * - Cada puntuación guarda factores, puntos y versión de reglas (RF-032), una por persona y día.
 * - El paso a nivel alto crea una alerta para RR. HH. y gerencia (RF-033); la decisión es
 *   siempre humana (LEG-005).
 */
final class RiesgoService
{
    private static ?array $reglas = null;

    public static function reglas(): array
    {
        return self::$reglas ??= require PH_ROOT . '/config/risk_rules.php';
    }

    public static function nivel(int $puntos): string
    {
        $n = self::reglas()['niveles'];
        return $puntos >= $n['ALTO'] ? 'ALTO' : ($puntos >= $n['MEDIO'] ? 'MEDIO' : 'BAJO');
    }

    /* ------------------------------------------------------------------ Cálculo */

    /**
     * Puntuación de una persona a partir de sus datos a una fecha.
     * @param array $p fecha_alta, tipo_contrato, turno, ausencias, vencidas, siniestros_quejas, reconocimientos
     * @return array{puntuacion: int, nivel: string, factores: list<array>}
     */
    public static function calcular(array $p, string $fecha): array
    {
        $r = self::reglas()['factores'];
        $f = [];
        $add = static function (string $codigo, int $puntos, string $detalle) use (&$f, $r): void {
            $f[] = [
                'codigo' => $codigo, 'nombre' => $r[$codigo]['nombre'], 'puntos' => $puntos, 'detalle' => $detalle,
                'evidencia' => $r[$codigo]['evidencia'], 'fuentes' => $r[$codigo]['fuentes'],
            ];
        };

        $dias = (int) (new \DateTimeImmutable($p['fecha_alta']))->diff(new \DateTimeImmutable($fecha))->days;
        foreach ($r['F1']['tramos'] as $t) {
            if ($dias <= $t['max_dias']) {
                $add('F1', $t['puntos'], $dias < 90
                    ? "Lleva $dias días en la empresa: los primeros meses concentran buena parte de las bajas."
                    : 'Lleva menos de un año en la empresa, el periodo con más bajas voluntarias.');
                break;
            }
        }
        foreach ($r['F2']['tramos'] as $t) {
            if ($p['ausencias'] >= $t['min']) {
                $add('F2', $t['puntos'], $p['ausencias'] === 1
                    ? 'Una ausencia injustificada en los últimos ' . $r['F2']['ventana_dias'] . ' días.'
                    : "{$p['ausencias']} ausencias injustificadas en los últimos " . $r['F2']['ventana_dias'] . ' días.');
                break;
            }
        }
        if (in_array($p['tipo_contrato'], $r['F3']['contratos'], true)) {
            $add('F3', $r['F3']['puntos'], $p['tipo_contrato'] === 'ETT' ? 'Contrato a través de una ETT.' : 'Contrato temporal.');
        }
        if ($p['vencidas'] > 0) {
            $add('F4', $r['F4']['puntos'], $p['vencidas'] === 1
                ? 'Tiene una formación asignada vencida sin completar.'
                : "Tiene {$p['vencidas']} formaciones asignadas vencidas sin completar.");
        }
        if (in_array($p['turno'], $r['F5']['turnos'], true)) {
            $add('F5', $r['F5']['puntos'], $p['turno'] === 'NOCHE' ? 'Trabaja en turno de noche.' : 'Trabaja en turno rotativo.');
        }
        if ($p['siniestros_quejas'] > 0) {
            $add('F6', $r['F6']['puntos'], $p['siniestros_quejas'] === 1
                ? 'Un siniestro o una queja de cliente en los últimos ' . $r['F6']['ventana_dias'] . ' días.'
                : "{$p['siniestros_quejas']} siniestros o quejas de cliente en los últimos " . $r['F6']['ventana_dias'] . ' días.');
        }
        if ($p['reconocimientos'] > 0) {
            $add('F7', $r['F7']['puntos'], 'Ha recibido reconocimiento en los últimos ' . $r['F7']['ventana_dias'] . ' días: resta puntos.');
        }
        $total = max(0, min(100, array_sum(array_column($f, 'puntos'))));
        usort($f, static fn ($a, $b) => abs($b['puntos']) <=> abs($a['puntos']));
        return ['puntuacion' => $total, 'nivel' => self::nivel($total), 'factores' => $f];
    }

    /**
     * Datos de las personas de alta a una fecha (hora de España). Ventanas relativas a esa fecha,
     * para poder reconstruir el histórico de la demo y reproducir cualquier puntuación.
     * @return array<int, array> usuario_id => datos
     */
    public static function datos(int $interlocutorId, string $fecha, ?int $usuarioId = null): array
    {
        $r = self::reglas()['factores'];
        $filtro = $usuarioId === null ? '' : ' AND u.id = ' . (int) $usuarioId;
        $out = [];
        foreach (Db::all(
            "SELECT u.id, f.fecha_alta, f.tipo_contrato, f.turno
               FROM usuario u
               JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
              WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL
                AND f.fecha_alta <= ? AND (f.fecha_baja IS NULL OR f.fecha_baja > ?)" . $filtro,
            [$interlocutorId, $fecha, $fecha]
        ) as $e) {
            $out[(int) $e['id']] = [
                'fecha_alta' => $e['fecha_alta'], 'tipo_contrato' => $e['tipo_contrato'], 'turno' => $e['turno'],
                'ausencias' => 0, 'vencidas' => 0, 'siniestros_quejas' => 0, 'reconocimientos' => 0,
            ];
        }
        if ($out === []) {
            return [];
        }
        $ventana = static fn (int $dias) => Tiempo::sumarDias($fecha, -$dias);
        $contar = static function (string $tipos, int $dias, string $campo) use (&$out, $interlocutorId, $fecha, $ventana, $filtro): void {
            foreach (Db::all(
                "SELECT i.usuario_id, COUNT(*) AS n FROM incidencia i JOIN usuario u ON u.id = i.usuario_id
                  WHERE i.interlocutor_id = ? AND i.tipo IN ($tipos) AND i.fecha > ? AND i.fecha <= ?" . $filtro . '
                  GROUP BY i.usuario_id',
                [$interlocutorId, $ventana($dias), $fecha]
            ) as $c) {
                if (isset($out[(int) $c['usuario_id']])) {
                    $out[(int) $c['usuario_id']][$campo] = (int) $c['n'];
                }
            }
        };
        $contar("'AUSENCIA_INJUSTIFICADA'", $r['F2']['ventana_dias'], 'ausencias');
        $contar("'SINIESTRO','QUEJA_CLIENTE'", $r['F6']['ventana_dias'], 'siniestros_quejas');
        $contar("'RECONOCIMIENTO'", $r['F7']['ventana_dias'], 'reconocimientos');
        // F4: asignaciones de la empresa vencidas a esa fecha y sin completar entonces
        foreach (Db::all(
            "SELECT a.usuario_id, COUNT(*) AS n FROM asignacion_formativa a JOIN usuario u ON u.id = a.usuario_id
              WHERE a.interlocutor_id = ? AND a.origen = 'GESTION' AND a.fecha_limite IS NOT NULL AND a.fecha_limite < ?
                AND DATE(a.creado_en) <= ? AND (a.completada_en IS NULL OR DATE(a.completada_en) > ?)" . $filtro . '
              GROUP BY a.usuario_id',
            [$interlocutorId, $fecha, $fecha, $fecha]
        ) as $c) {
            if (isset($out[(int) $c['usuario_id']])) {
                $out[(int) $c['usuario_id']]['vencidas'] = (int) $c['n'];
            }
        }
        return $out;
    }

    /**
     * Recalcula la empresa a una fecha (RF-031) y crea las alertas de paso a nivel alto (RF-033).
     * @return array{personas: int, alto: int, medio: int, bajo: int, alertas: int}
     */
    public static function recalcular(int $interlocutorId, ?string $fecha = null, bool $alertas = true): array
    {
        $fecha ??= Tiempo::hoy();
        $version = self::reglas()['version'];
        $res = ['personas' => 0, 'ALTO' => 0, 'MEDIO' => 0, 'BAJO' => 0, 'alertas' => 0];
        $datos = self::datos($interlocutorId, $fecha);
        $previos = [];
        foreach (Db::all(
            'SELECT p.usuario_id, p.nivel FROM puntuacion_riesgo p
               JOIN (SELECT usuario_id, MAX(fecha) AS f FROM puntuacion_riesgo WHERE interlocutor_id = ? AND fecha < ? GROUP BY usuario_id) u
                 ON u.usuario_id = p.usuario_id AND u.f = p.fecha',
            [$interlocutorId, $fecha]
        ) as $p) {
            $previos[(int) $p['usuario_id']] = $p['nivel'];
        }
        Db::transaction(function () use ($datos, $fecha, $interlocutorId, $version, $alertas, $previos, &$res): void {
            foreach ($datos as $uid => $d) {
                $c = self::calcular($d, $fecha);
                Db::run('DELETE FROM puntuacion_riesgo WHERE usuario_id = ? AND fecha = ?', [$uid, $fecha]);
                Db::insert(
                    'INSERT INTO puntuacion_riesgo (interlocutor_id, usuario_id, fecha, puntuacion, nivel, factores, version_reglas) VALUES (?, ?, ?, ?, ?, ?, ?)',
                    [$interlocutorId, $uid, $fecha, $c['puntuacion'], $c['nivel'], json_encode($c['factores'], JSON_UNESCAPED_UNICODE), $version]
                );
                $res['personas']++;
                $res[$c['nivel']]++;
                if ($alertas && $c['nivel'] === 'ALTO' && ($previos[$uid] ?? null) !== 'ALTO' && self::alertar($interlocutorId, $uid, $c)) {
                    $res['alertas']++;
                }
            }
        });
        return ['personas' => $res['personas'], 'alto' => $res['ALTO'], 'medio' => $res['MEDIO'], 'bajo' => $res['BAJO'], 'alertas' => $res['alertas']];
    }

    /**
     * Alerta de rotación para RR. HH. y gerencia (RF-033), salvo que ya haya una abierta.
     * @param array $c puntuacion y factores, como los devuelve calcular()
     */
    public static function alertar(int $interlocutorId, int $usuarioId, array $c): bool
    {
        if (AlertaRepository::abiertaDesde($interlocutorId, 'RIESGO_ROTACION', 'USUARIO', $usuarioId, '2000-01-01 00:00:00')) {
            return false;
        }
        $principales = array_map(static fn ($x) => mb_strtolower($x['nombre']), array_slice(array_values(array_filter($c['factores'], static fn ($x) => $x['puntos'] > 0)), 0, 3));
        AlertaRepository::create($interlocutorId, 'RIESGO_ROTACION', 'USUARIO', $usuarioId, $c['puntuacion'] >= 70 ? 'ALTA' : 'MEDIA',
            "Riesgo de rotación alto ({$c['puntuacion']}/100). Factores principales: " . implode(', ', $principales)
            . '. Orienta una conversación, no decide nada sobre la persona.');
        return true;
    }

    /**
     * Comprobación retrospectiva: bajas voluntarias desde el alta en Pyme Hub y el nivel de su
     * última puntuación antes de irse. Con pocas bajas es solo orientativa (§9.5).
     */
    public static function retrospectiva(int $interlocutorId): array
    {
        $alta = (string) Db::value('SELECT MIN(fecha_inicio) FROM suscripcion WHERE interlocutor_id = ?', [$interlocutorId]);
        $out = ['bajas_voluntarias' => 0, 'alto' => 0, 'medio' => 0, 'bajo' => 0, 'sin_puntuacion' => 0];
        if ($alta === '') {
            return $out;
        }
        foreach (Db::all(
            "SELECT f.usuario_id,
                    (SELECT p.nivel FROM puntuacion_riesgo p WHERE p.usuario_id = f.usuario_id AND p.fecha < f.fecha_baja ORDER BY p.fecha DESC LIMIT 1) AS nivel
               FROM ficha_laboral f
              WHERE f.interlocutor_id = ? AND f.motivo_baja = 'VOLUNTARIA' AND f.fecha_baja >= ?",
            [$interlocutorId, $alta]
        ) as $b) {
            $out['bajas_voluntarias']++;
            $out[$b['nivel'] === null ? 'sin_puntuacion' : strtolower($b['nivel'])]++;
        }
        return $out;
    }

    /* ------------------------------------------------------------------ Consulta */

    /** Última puntuación de cada persona de alta. Filtros: equipo_id, nivel, q. */
    public static function ultimas(int $interlocutorId, array $f = []): array
    {
        $where = '';
        $params = [$interlocutorId, $interlocutorId];
        if (!empty($f['equipo_id'])) {
            $where .= ' AND fl.equipo_id = ?';
            $params[] = (int) $f['equipo_id'];
        }
        if (!empty($f['nivel'])) {
            $where .= ' AND p.nivel = ?';
            $params[] = $f['nivel'];
        }
        if (!empty($f['q'])) {
            $like = '%' . addcslashes((string) $f['q'], '%_\\') . '%';
            $where .= ' AND (us.nombre LIKE ? OR us.apellidos LIKE ? OR fl.codigo_interno LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $rows = Db::all(
            "SELECT p.usuario_id, p.fecha, p.puntuacion, p.nivel, p.factores, p.version_reglas,
                    us.nombre, us.apellidos, fl.puesto, fl.turno, fl.tipo_contrato, fl.fecha_alta, fl.equipo_id, e.nombre AS equipo_nombre
               FROM puntuacion_riesgo p
               JOIN (SELECT usuario_id, MAX(fecha) AS f FROM puntuacion_riesgo WHERE interlocutor_id = ? GROUP BY usuario_id) m
                 ON m.usuario_id = p.usuario_id AND m.f = p.fecha
               JOIN usuario us ON us.id = p.usuario_id AND us.interlocutor_id = p.interlocutor_id AND us.eliminado_en IS NULL
               JOIN ficha_laboral fl ON fl.usuario_id = p.usuario_id AND fl.interlocutor_id = p.interlocutor_id AND fl.fecha_baja IS NULL
               LEFT JOIN equipo e ON e.id = fl.equipo_id AND e.interlocutor_id = fl.interlocutor_id
              WHERE p.interlocutor_id = ?" . $where . '
              ORDER BY p.puntuacion DESC, us.apellidos, us.nombre',
            $params
        );
        return array_map([self::class, 'present'], $rows);
    }

    public static function present(array $r): array
    {
        return [
            'empleado'  => [
                'id' => (int) $r['usuario_id'], 'nombre' => $r['nombre'], 'apellidos' => $r['apellidos'], 'puesto' => $r['puesto'],
                'turno' => $r['turno'], 'tipo_contrato' => $r['tipo_contrato'], 'fecha_alta' => $r['fecha_alta'],
                'equipo_id' => $r['equipo_id'] === null ? null : (int) $r['equipo_id'], 'equipo_nombre' => $r['equipo_nombre'],
            ],
            'fecha'          => $r['fecha'],
            'puntuacion'     => (int) $r['puntuacion'],
            'nivel'          => $r['nivel'],
            'factores'       => json_decode((string) $r['factores'], true) ?: [],
            'version_reglas' => $r['version_reglas'],
        ];
    }

    /** Ficha de riesgo de una persona: última puntuación e histórico (RF-034). */
    public static function ficha(int $usuarioId, int $interlocutorId, int $dias = 90): ?array
    {
        $u = self::ultimas($interlocutorId);
        $actual = null;
        foreach ($u as $x) {
            if ($x['empleado']['id'] === $usuarioId) {
                $actual = $x;
                break;
            }
        }
        if ($actual === null) {
            return null;
        }
        $historico = array_map(static fn ($r) => ['fecha' => $r['fecha'], 'puntuacion' => (int) $r['puntuacion'], 'nivel' => $r['nivel']], Db::all(
            'SELECT fecha, puntuacion, nivel FROM puntuacion_riesgo WHERE usuario_id = ? AND interlocutor_id = ? AND fecha >= ? ORDER BY fecha',
            [$usuarioId, $interlocutorId, Tiempo::dia(-$dias)]
        ));
        return $actual + ['historico' => $historico];
    }

    /** Metodología pública para gerencia y RR. HH. (GET /v1/risk/methodology). */
    public static function metodologia(): array
    {
        $r = self::reglas();
        $condiciones = [
            'F1' => '< 90 días: 30 · 90–365 días: 15',
            'F2' => '≥ 2 en los últimos 60 días: 25 · 1: 10',
            'F3' => 'Temporal o ETT: 15',
            'F4' => '≥ 1 asignación de la empresa vencida sin completar: 10',
            'F5' => 'Noche o rotativo: 10',
            'F6' => '≥ 1 en los últimos 60 días: 10',
            'F7' => '≥ 1 en los últimos 90 días: −15',
        ];
        $factores = [];
        foreach ($r['factores'] as $codigo => $f) {
            $max = isset($f['tramos']) ? max(array_column($f['tramos'], 'puntos')) : $f['puntos'];
            $factores[] = [
                'codigo' => $codigo, 'nombre' => $f['nombre'], 'condicion' => $condiciones[$codigo] ?? '', 'puntos_max' => $max,
                'evidencia' => $f['evidencia'], 'fuentes' => $f['fuentes'],
            ];
        }
        return [
            'version'      => $r['version'],
            'niveles'      => [
                ['nivel' => 'BAJO', 'desde' => 0, 'hasta' => $r['niveles']['MEDIO'] - 1],
                ['nivel' => 'MEDIO', 'desde' => $r['niveles']['MEDIO'], 'hasta' => $r['niveles']['ALTO'] - 1],
                ['nivel' => 'ALTO', 'desde' => $r['niveles']['ALTO'], 'hasta' => 100],
            ],
            'factores'     => $factores,
            'excluidos'    => $r['excluidos'],
            'fuentes'      => array_map(static fn ($k, $v) => ['id' => $k, 'referencia' => $v], array_keys($r['fuentes']), $r['fuentes']),
            'limitaciones' => $r['limitaciones'],
            'supervision'  => 'Esta puntuación orienta una conversación, no decide nada sobre la persona. El sistema solo alerta; ninguna acción es automática (LEG-005).',
        ];
    }

    /* ------------------------------------------------------------------ Revisión de sesgos (LEG-015) */

    /**
     * Distribución de niveles por equipo, puesto, turno y tipo de contrato. Señala los grupos cuyo
     * porcentaje en nivel alto se aparta más de 20 puntos del total, para revisión humana.
     */
    public static function sesgos(int $interlocutorId): array
    {
        $u = self::ultimas($interlocutorId);
        $n = count($u);
        $altoTotal = $n === 0 ? 0 : round(count(array_filter($u, static fn ($x) => $x['nivel'] === 'ALTO')) / $n * 100, 1);
        $dims = [
            'equipo' => static fn ($x) => $x['empleado']['equipo_nombre'] ?? 'Sin equipo',
            'puesto' => static fn ($x) => $x['empleado']['puesto'],
            'turno' => static fn ($x) => $x['empleado']['turno'],
            'tipo_contrato' => static fn ($x) => $x['empleado']['tipo_contrato'],
        ];
        // Diferencias esperables: el grupo es un factor del propio modelo (F3, F5)
        $esperables = ['turno' => ['NOCHE', 'ROTATIVO'], 'tipo_contrato' => ['TEMPORAL', 'ETT']];
        $out = [];
        foreach ($dims as $dim => $clave) {
            $grupos = [];
            foreach ($u as $x) {
                $g = (string) $clave($x);
                $grupos[$g] ??= ['ALTO' => 0, 'MEDIO' => 0, 'BAJO' => 0, 'n' => 0];
                $grupos[$g][$x['nivel']]++;
                $grupos[$g]['n']++;
            }
            ksort($grupos);
            $filas = [];
            foreach ($grupos as $g => $c) {
                $pctAlto = round($c['ALTO'] / $c['n'] * 100, 1);
                $dif = round($pctAlto - $altoTotal, 1);
                $filas[] = [
                    'grupo' => $g, 'personas' => $c['n'], 'alto' => $c['ALTO'], 'medio' => $c['MEDIO'], 'bajo' => $c['BAJO'],
                    'pct_alto' => $pctAlto, 'diferencia' => $dif,
                    'revisar' => abs($dif) > 20 && $c['n'] >= 5,
                    'muestra_pequena' => $c['n'] < 5,
                    'esperable' => in_array($g, $esperables[$dim] ?? [], true),
                ];
            }
            $out[] = ['dimension' => $dim, 'grupos' => $filas];
        }
        return ['personas' => $n, 'pct_alto_total' => $altoTotal, 'umbral_pp' => 20, 'dimensiones' => $out, 'version_reglas' => self::reglas()['version']];
    }

    /* ------------------------------------------------------------------ Panel (RF-030) y tendencias */

    public static function resumen(int $interlocutorId, bool $conRiesgo): array
    {
        $hoy = Tiempo::hoy();
        $hace12 = Tiempo::sumarDias($hoy, -365);
        $activos = (int) Db::value(
            "SELECT COUNT(*) FROM usuario u JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
              WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL AND f.fecha_baja IS NULL",
            [$interlocutorId]
        );
        $activosHace12 = (int) Db::value(
            "SELECT COUNT(*) FROM usuario u JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
              WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND f.fecha_alta <= ? AND (f.fecha_baja IS NULL OR f.fecha_baja > ?)",
            [$interlocutorId, $hace12, $hace12]
        );
        $bajas = Db::one(
            "SELECT COUNT(*) AS total, SUM(motivo_baja = 'VOLUNTARIA') AS voluntarias FROM ficha_laboral
              WHERE interlocutor_id = ? AND fecha_baja IS NOT NULL AND fecha_baja > ?",
            [$interlocutorId, $hace12]
        ) ?? [];
        $media = max(1, ($activos + $activosHace12) / 2);
        $antig = Db::value(
            "SELECT AVG(DATEDIFF(?, f.fecha_alta)) FROM usuario u JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
              WHERE u.interlocutor_id = ? AND u.perfil = 'EMPLEADO' AND u.eliminado_en IS NULL AND f.fecha_baja IS NULL",
            [$hoy, $interlocutorId]
        );
        $form = Db::one(
            "SELECT COUNT(*) AS vencidas_plazo, SUM(a.completada_en IS NOT NULL AND DATE(a.completada_en) <= a.fecha_limite) AS en_plazo
               FROM asignacion_formativa a
               JOIN ficha_laboral f ON f.usuario_id = a.usuario_id AND f.interlocutor_id = a.interlocutor_id AND f.fecha_baja IS NULL
              WHERE a.interlocutor_id = ? AND a.origen = 'GESTION' AND a.fecha_limite IS NOT NULL AND a.fecha_limite < ? AND a.fecha_limite >= ?",
            [$interlocutorId, $hoy, $hace12]
        ) ?? [];
        $out = [
            'plantilla_activa'        => $activos,
            'rotacion_12m'            => round((int) ($bajas['total'] ?? 0) / $media * 100, 1),
            'rotacion_voluntaria_12m' => round((int) ($bajas['voluntarias'] ?? 0) / $media * 100, 1),
            'bajas_12m'               => (int) ($bajas['total'] ?? 0),
            'antiguedad_media_meses'  => $antig === null ? null : round((float) $antig / 30.44, 1),
            'formacion_en_plazo'      => (int) ($form['vencidas_plazo'] ?? 0) === 0 ? null : (int) round((int) $form['en_plazo'] / (int) $form['vencidas_plazo'] * 100),
            'riesgo'                  => null,
        ];
        if ($conRiesgo) {
            $niveles = ['ALTO' => 0, 'MEDIO' => 0, 'BAJO' => 0];
            $u = self::ultimas($interlocutorId);
            foreach ($u as $x) {
                $niveles[$x['nivel']]++;
            }
            $out['riesgo'] = [
                'alto' => $niveles['ALTO'], 'medio' => $niveles['MEDIO'], 'bajo' => $niveles['BAJO'],
                'calculado' => $u === [] ? null : max(array_column($u, 'fecha')),
                'version_reglas' => self::reglas()['version'],
                'retrospectiva' => self::retrospectiva($interlocutorId),
            ];
        }
        return $out;
    }

    /** Tendencias mensuales: altas, bajas, rotación, ausencias, reconocimientos y riesgo medio. */
    public static function tendencias(int $interlocutorId, int $meses): array
    {
        $out = [];
        $primero = new \DateTimeImmutable(substr(Tiempo::hoy(), 0, 7) . '-01');
        for ($i = $meses - 1; $i >= 0; $i--) {
            $ini = $primero->modify("-$i months");
            $fin = $ini->modify('last day of this month');
            [$a, $b] = [$ini->format('Y-m-d'), $fin->format('Y-m-d')];
            $mov = Db::one(
                "SELECT SUM(fecha_alta BETWEEN ? AND ?) AS altas,
                        SUM(fecha_baja BETWEEN ? AND ?) AS bajas,
                        SUM(fecha_baja BETWEEN ? AND ? AND motivo_baja = 'VOLUNTARIA') AS voluntarias,
                        SUM(fecha_alta <= ? AND (fecha_baja IS NULL OR fecha_baja > ?)) AS plantilla
                   FROM ficha_laboral WHERE interlocutor_id = ?",
                [$a, $b, $a, $b, $a, $b, $b, $b, $interlocutorId]
            ) ?? [];
            $inc = Db::one(
                "SELECT SUM(tipo = 'AUSENCIA_INJUSTIFICADA') AS ausencias, SUM(tipo = 'RECONOCIMIENTO') AS reconocimientos
                   FROM incidencia WHERE interlocutor_id = ? AND fecha BETWEEN ? AND ?",
                [$interlocutorId, $a, $b]
            ) ?? [];
            $rie = Db::one(
                "SELECT AVG(puntuacion) AS media, AVG(nivel = 'ALTO') AS alto FROM puntuacion_riesgo WHERE interlocutor_id = ? AND fecha BETWEEN ? AND ?",
                [$interlocutorId, $a, $b]
            ) ?? [];
            $plantilla = (int) ($mov['plantilla'] ?? 0);
            $out[] = [
                'mes' => $ini->format('Y-m'),
                'altas' => (int) ($mov['altas'] ?? 0), 'bajas' => (int) ($mov['bajas'] ?? 0), 'bajas_voluntarias' => (int) ($mov['voluntarias'] ?? 0),
                'plantilla' => $plantilla,
                'rotacion' => $plantilla === 0 ? null : round((int) ($mov['bajas'] ?? 0) / $plantilla * 100, 1),
                'ausencias' => (int) ($inc['ausencias'] ?? 0), 'reconocimientos' => (int) ($inc['reconocimientos'] ?? 0),
                'riesgo_medio' => $rie['media'] === null ? null : (int) round((float) $rie['media']),
                'pct_alto' => $rie['alto'] === null ? null : (int) round((float) $rie['alto'] * 100),
            ];
        }
        return $out;
    }

    /* ------------------------------------------------------------------ Línea base (RF-037) */

    /** Línea base registrada y comparación con la evolución desde el alta en Pyme Hub. */
    public static function lineaBase(int $interlocutorId): array
    {
        $alta = (string) Db::value('SELECT MIN(fecha_inicio) FROM suscripcion WHERE interlocutor_id = ?', [$interlocutorId]);
        $lb = Db::one('SELECT periodo_inicio, periodo_fin, plantilla_media, bajas_voluntarias, dias_absentismo, fuente, creado_en
                         FROM linea_base WHERE interlocutor_id = ? ORDER BY periodo_inicio DESC LIMIT 1', [$interlocutorId]);
        $hoy = Tiempo::hoy();
        $dias = $alta === '' ? 0 : max(1, (int) (new \DateTimeImmutable($alta))->diff(new \DateTimeImmutable($hoy))->days);
        $desde = Db::one(
            "SELECT SUM(motivo_baja = 'VOLUNTARIA' AND fecha_baja >= ?) AS voluntarias,
                    SUM(fecha_alta <= ? AND (fecha_baja IS NULL OR fecha_baja > ?)) AS al_alta,
                    SUM(fecha_baja IS NULL) AS hoy
               FROM ficha_laboral WHERE interlocutor_id = ?",
            [$alta, $alta, $alta, $interlocutorId]
        ) ?? [];
        $ausencias = (int) Db::value("SELECT COUNT(*) FROM incidencia WHERE interlocutor_id = ? AND tipo = 'AUSENCIA_INJUSTIFICADA' AND fecha >= ?", [$interlocutorId, $alta]);
        $media = max(1, ((int) ($desde['al_alta'] ?? 0) + (int) ($desde['hoy'] ?? 0)) / 2);
        $anual = 365 / $dias;
        $actual = [
            'desde' => $alta, 'dias' => $dias, 'plantilla_media' => round($media, 1),
            'bajas_voluntarias' => (int) ($desde['voluntarias'] ?? 0),
            'rotacion_voluntaria_anualizada' => round((int) ($desde['voluntarias'] ?? 0) / $media * $anual * 100, 1),
            'dias_absentismo' => $ausencias,
            'absentismo_anualizado_por_persona' => round($ausencias / $media * $anual, 2),
        ];
        if ($lb === null) {
            return ['linea_base' => null, 'actual' => $actual, 'comparacion' => null];
        }
        $plantillaLb = max(1.0, (float) $lb['plantilla_media']);
        $base = [
            'periodo_inicio' => $lb['periodo_inicio'], 'periodo_fin' => $lb['periodo_fin'], 'plantilla_media' => (float) $lb['plantilla_media'],
            'bajas_voluntarias' => (int) $lb['bajas_voluntarias'], 'dias_absentismo' => (int) $lb['dias_absentismo'], 'fuente' => $lb['fuente'],
            'rotacion_voluntaria' => round((int) $lb['bajas_voluntarias'] / $plantillaLb * 100, 1),
            'absentismo_por_persona' => round((int) $lb['dias_absentismo'] / $plantillaLb, 2),
        ];
        $rel = static fn (float $antes, float $ahora) => $antes == 0.0 ? null : round(($ahora - $antes) / $antes * 100, 1);
        return [
            'linea_base'  => $base,
            'actual'      => $actual,
            'comparacion' => [
                'rotacion_relativa'   => $rel($base['rotacion_voluntaria'], $actual['rotacion_voluntaria_anualizada']),
                'absentismo_relativo' => $rel($base['absentismo_por_persona'], $actual['absentismo_anualizado_por_persona']),
                'objetivo_piloto'     => -15,
                'periodo_corto'       => $dias < 90,
            ],
        ];
    }
}
