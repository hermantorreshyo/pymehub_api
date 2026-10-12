<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\Db;
use PymeHub\Core\Logger;
use PymeHub\Core\Tiempo;
use PymeHub\Repositories\AlertaRepository;
use PymeHub\Repositories\CheckinRepository;

/**
 * Módulo de Bienestar (§11): agregados anónimos y alertas de equipo.
 *
 * - Umbral de anonimato: un agregado solo se muestra con ≥ 5 personas distintas en el periodo (LEG-002).
 * - Índice de bienestar 0–100: media de ánimo, energía y (6 − estrés), escalada de 1–5 a 0–100 (§13.6).
 * - La participación en el check-in nunca interviene en un cálculo sobre una persona (RF-056, LEG-013).
 */
final class BienestarService
{
    public const UMBRAL = 5;

    /** RF-054: alerta si el estrés medio sube ≥ 1 punto frente a las 4 semanas anteriores… */
    public const SUBIDA_ESTRES = 1.0;
    /** …o si el ánimo medio baja de 2,5. */
    public const ANIMO_MINIMO = 2.5;

    /** RF-055: valores bajos que muestran un mensaje de apoyo al empleado (sin intervención automática). */
    public static function necesitaApoyo(int $animo, int $energia, int $estres): bool
    {
        return $animo <= 2 || $estres >= 4 || ($energia === 1 && $animo <= 3);
    }

    public static function recursosApoyo(): array
    {
        return [
            'mensaje'  => 'Gracias por contarnos cómo estás. Si te viene bien hablar con alguien, aquí tienes ayuda. Es confidencial y nadie de tu empresa ve tus respuestas.',
            'recursos' => [
                ['nombre' => 'Línea 024', 'detalle' => 'Atención gratuita y confidencial, 24 horas, todos los días.', 'telefono' => '024'],
                ['nombre' => 'Servicio de prevención de tu empresa', 'detalle' => 'Puedes pedir apoyo sobre carga de trabajo, turnos o salud laboral.', 'telefono' => null],
            ],
        ];
    }

    /** Redondeo de presentación. */
    public static function r(mixed $v, int $dec = 1): ?float
    {
        return $v === null ? null : round((float) $v, $dec);
    }

    /**
     * Resumen agregado de la empresa o de un equipo (§8.6, RF-053).
     * Devuelve [datos, meta].
     */
    public static function resumen(int $interlocutorId, ?int $equipoId, int $semanas): array
    {
        $hoy = Tiempo::hoy();
        $lunesActual = Tiempo::lunes($hoy);
        $desde = Tiempo::sumarDias($lunesActual, -7 * ($semanas - 1));

        $filas = CheckinRepository::semanal($interlocutorId, $equipoId, $desde, $hoy);
        $serie = [];
        for ($i = 0; $i < $semanas; $i++) {
            $lunes = Tiempo::sumarDias($desde, 7 * $i);
            $f = $filas[$lunes] ?? null;
            $participantes = (int) ($f['participantes'] ?? 0);
            $ok = $participantes >= self::UMBRAL;
            $serie[] = [
                'semana'        => $lunes,
                'en_curso'      => $lunes === $lunesActual,
                'participantes' => $ok ? $participantes : null, // por debajo del umbral ni siquiera se da el recuento exacto
                'disponible'    => $ok,
                'indice'        => $ok ? self::r($f['indice'], 0) : null,
                'animo'         => $ok ? self::r($f['animo']) : null,
                'energia'       => $ok ? self::r($f['energia']) : null,
                'estres'        => $ok ? self::r($f['estres']) : null,
            ];
        }

        // Semana de referencia: la más reciente con datos suficientes
        $disponibles = array_values(array_filter($serie, static fn ($s) => $s['disponible']));
        $actual = $disponibles === [] ? null : $disponibles[count($disponibles) - 1];
        $anterior = count($disponibles) >= 2 ? $disponibles[count($disponibles) - 2] : null;

        // Equipos en las últimas 4 semanas (solo vista de empresa)
        $equipos = [];
        if ($equipoId === null) {
            $desdeEquipos = Tiempo::sumarDias($lunesActual, -21);
            foreach (CheckinRepository::porEquipo($interlocutorId, $desdeEquipos, $hoy) as $e) {
                $p = (int) $e['participantes'];
                $ok = $p >= self::UMBRAL;
                $equipos[] = [
                    'id'            => (int) $e['id'],
                    'nombre'        => $e['nombre'],
                    'disponible'    => $ok,
                    'participantes' => $ok ? $p : null,
                    'indice'        => $ok ? self::r($e['indice'], 0) : null,
                    'animo'         => $ok ? self::r($e['animo']) : null,
                    'energia'       => $ok ? self::r($e['energia']) : null,
                    'estres'        => $ok ? self::r($e['estres']) : null,
                    'nivel'         => $ok ? self::nivel((float) $e['indice']) : null,
                ];
            }
        }

        $plantilla = CheckinRepository::plantillaActiva($interlocutorId, $equipoId);
        $datos = [
            'umbral_anonimato' => self::UMBRAL,
            'semanas'          => $serie,
            'actual'           => $actual === null ? null : [
                'semana'        => $actual['semana'],
                'en_curso'      => $actual['en_curso'],
                'indice'        => $actual['indice'],
                'nivel'         => self::nivel((float) $actual['indice']),
                'variacion'     => $anterior === null ? null : (int) $actual['indice'] - (int) $anterior['indice'],
                'participantes' => $actual['participantes'],
                'plantilla'     => $plantilla,
                'animo'         => $actual['animo'],
                'energia'       => $actual['energia'],
                'estres'        => $actual['estres'],
            ],
            'equipos'          => $equipos,
        ];
        $meta = ['semanas' => $semanas, 'desde' => $desde, 'hasta' => $hoy];
        if ($actual === null) {
            $meta['aviso'] = [
                'code'    => 'PH-WB-001',
                'message' => 'Todavía no hay respuestas suficientes para mostrar el bienestar sin identificar a nadie (mínimo ' . self::UMBRAL . ' personas por semana).',
            ];
        }
        return [$datos, $meta];
    }

    /** Nivel del índice, siempre acompañado de texto e icono en la interfaz (RNF-044). */
    public static function nivel(float $indice): string
    {
        return $indice >= 65 ? 'BUENO' : ($indice >= 45 ? 'ATENCION' : 'BAJO');
    }

    /**
     * RF-054: evalúa las alertas de bienestar de los equipos de una empresa con la última semana
     * completa (lunes a domingo) frente a las 4 anteriores. No duplica una alerta abierta del mismo
     * equipo creada en los últimos 6 días. Devuelve el número de alertas creadas.
     */
    public static function evaluarAlertas(int $interlocutorId, ?string $hoy = null): int
    {
        $hoy ??= Tiempo::hoy();
        $lunesActual = Tiempo::lunes($hoy);
        $lunesEvaluada = Tiempo::sumarDias($lunesActual, -7);
        $finEvaluada = Tiempo::sumarDias($lunesActual, -1);
        $inicioBase = Tiempo::sumarDias($lunesEvaluada, -28);
        $creadas = 0;

        $equipos = Db::all('SELECT id, nombre FROM equipo WHERE interlocutor_id = ? AND eliminado_en IS NULL', [$interlocutorId]);
        foreach ($equipos as $eq) {
            $eqId = (int) $eq['id'];
            $semanas = CheckinRepository::semanal($interlocutorId, $eqId, $inicioBase, $finEvaluada);
            $evaluada = $semanas[$lunesEvaluada] ?? null;
            if ($evaluada === null || (int) $evaluada['participantes'] < self::UMBRAL) {
                continue; // sin datos suficientes no se alerta: protegería mal el anonimato
            }
            $base = array_filter(
                $semanas,
                static fn ($s, $k) => $k !== $lunesEvaluada && (int) $s['participantes'] >= self::UMBRAL,
                ARRAY_FILTER_USE_BOTH
            );

            $motivos = [];
            $severidad = 'MEDIA';
            $estres = (float) $evaluada['estres'];
            $animo = (float) $evaluada['animo'];
            if ($base !== []) {
                $estresBase = array_sum(array_map(static fn ($s) => (float) $s['estres'], $base)) / count($base);
                $subida = $estres - $estresBase;
                if ($subida >= self::SUBIDA_ESTRES) {
                    $motivos[] = sprintf('el estrés medio ha subido %s puntos frente a las semanas anteriores (de %s a %s sobre 5)',
                        self::num($subida), self::num($estresBase), self::num($estres));
                    if ($subida >= 1.5) {
                        $severidad = 'ALTA';
                    }
                }
            }
            if ($animo < self::ANIMO_MINIMO) {
                $motivos[] = sprintf('el ánimo medio está en %s sobre 5', self::num($animo));
                if ($animo < 2.0) {
                    $severidad = 'ALTA';
                }
            }
            if ($motivos === []) {
                continue;
            }
            $desdeUtc = gmdate('Y-m-d H:i:s', time() - 6 * 86400);
            if (AlertaRepository::abiertaDesde($interlocutorId, 'BIENESTAR_EQUIPO', 'EQUIPO', $eqId, $desdeUtc)) {
                continue;
            }
            $mensaje = sprintf('Equipo %s: %s. Conviene hablar con el equipo y revisar cargas y turnos.', $eq['nombre'], implode(' y ', $motivos));
            AlertaRepository::create($interlocutorId, 'BIENESTAR_EQUIPO', 'EQUIPO', $eqId, $severidad, $mensaje);
            Logger::info('Alerta de bienestar creada', ['interlocutor_id' => $interlocutorId, 'equipo_id' => $eqId]);
            $creadas++;
        }
        return $creadas;
    }

    private static function num(float $v): string
    {
        return number_format($v, 1, ',', '');
    }
}
