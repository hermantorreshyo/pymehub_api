<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Ai\AiException;
use PymeHub\Ai\AiGateway;
use PymeHub\Ai\PayloadGuard;
use PymeHub\Core\ApiException;
use PymeHub\Core\Db;
use PymeHub\Core\Tiempo;
use PymeHub\Repositories\ModuloRepository;

/**
 * Asesor de formación (§12): la única funcionalidad con IA generativa.
 *
 * Flujo de una consulta:
 *  1. Payload con la lista blanca de §12.3 (perfil no identificativo, preferencias tras el
 *     PayloadGuard, catálogo resumido e ids ya hechos). Nada de nombres, empresa ni salud.
 *  2. Según ai.mode (IA-015): caché (IA-014), proveedor en vivo (IA-011/012) o reglas (IA-002).
 *  3. Salida validada (IA-013) y anclada al catálogo (IA-001): ids inexistentes, repetidos o
 *     completados se descartan y, si quedan menos de 3, se completa con las reglas.
 *  4. Registro en solicitud_ia sin contenido. El empleado nunca ve un error: los fallos
 *     del proveedor llegan como aviso (PH-AI-001/002/003) junto al respaldo.
 */
final class AsesorService
{
    public const VERSION_PROMPT = 'advisor.v1';
    public const MAX_DIA_EMPLEADO = 5;     // IA-016
    private const CACHE_DIAS = 7;          // IA-014
    private const MIN = 3;
    private const MAX = 5;

    public const OBJETIVOS = [
        'MEJORAR_TRABAJO'        => 'Hacer mejor mi trabajo actual',
        'SEGURIDAD'              => 'Conducir y trabajar con más seguridad',
        'SALUD_ENERGIA'          => 'Cuidar mi salud y energía',
        'RESPONSABILIDAD'        => 'Prepararme para un puesto de más responsabilidad',
        'HERRAMIENTAS_DIGITALES' => 'Aprender a usar mejor las herramientas digitales',
    ];
    public const FORMATOS = ['TEXTO' => 'Solo texto', 'IMAGENES' => 'Con imágenes', 'VIDEO' => 'Con vídeo'];
    public const MINUTOS = [5, 15, 30];

    /** Módulos y categorías que cada objetivo prioriza en el respaldo por reglas. */
    private const REGLAS_OBJETIVO = [
        'MEJORAR_TRABAJO'        => ['categorias' => [], 'codigos' => []],
        'SEGURIDAD'              => ['categorias' => ['CONDUCCION', 'ALMACEN'], 'codigos' => ['SEGURIDAD_VIAL_CIUDAD', 'CONDUCCION_NOCTURNA', 'MANIPULACION_CARGAS', 'TRANSPALE_SEGURO', 'ESTIBA_SUJECION']],
        'SALUD_ENERGIA'          => ['categorias' => ['BIENESTAR'], 'codigos' => ['GESTION_ESTRES', 'DESCANSO_TURNOS', 'PAUSAS_ACTIVAS']],
        'RESPONSABILIDAD'        => ['categorias' => ['DESARROLLO', 'CLIENTE'], 'codigos' => ['COORDINADOR_RUTA', 'CLIENTES_DIFICILES', 'INCIDENCIAS_RECHAZOS']],
        'HERRAMIENTAS_DIGITALES' => ['categorias' => ['DESARROLLO'], 'codigos' => ['HERRAMIENTAS_DIGITALES', 'VEHICULO_ELECTRICO']],
    ];

    /** Categorías del día a día de cada puesto («hacer mejor mi trabajo»). */
    private const CATEGORIAS_PUESTO = [
        'CONDUCTOR'      => ['CONDUCCION', 'CLIENTE'],
        'REPARTIDOR'     => ['CLIENTE', 'CONDUCCION'],
        'MOZO_ALMACEN'   => ['ALMACEN'],
        'COORDINADOR'    => ['DESARROLLO', 'CLIENTE'],
        'ADMINISTRATIVO' => ['DESARROLLO'],
        'OTRO'           => [],
    ];

    public function __construct(private readonly AiGateway $ai) {}

    /* ------------------------------------------------------------------ Preferencias (LEG-014) */

    public static function preferencias(int $usuarioId): ?array
    {
        $r = Db::one('SELECT objetivo, intereses, formato, minutos_semana, comentario, actualizado_en FROM preferencia_formativa WHERE usuario_id = ?', [$usuarioId]);
        if ($r === null) {
            return null;
        }
        return [
            'objetivo'       => $r['objetivo'],
            'intereses'      => json_decode((string) $r['intereses'], true) ?: [],
            'formato'        => $r['formato'],
            'minutos_semana' => (int) $r['minutos_semana'],
            'comentario'     => $r['comentario'],
            'actualizado_en' => $r['actualizado_en'],
        ];
    }

    public static function guardarPreferencias(int $usuarioId, array $p): void
    {
        $params = [$p['objetivo'], json_encode(array_values($p['intereses'])), $p['formato'], $p['minutos_semana'], $p['comentario']];
        if (Db::value('SELECT 1 FROM preferencia_formativa WHERE usuario_id = ?', [$usuarioId]) === null) {
            Db::run(
                'INSERT INTO preferencia_formativa (objetivo, intereses, formato, minutos_semana, comentario, usuario_id) VALUES (?, ?, ?, ?, ?, ?)',
                [...$params, $usuarioId]
            );
        } else {
            Db::run(
                'UPDATE preferencia_formativa SET objetivo = ?, intereses = ?, formato = ?, minutos_semana = ?, comentario = ? WHERE usuario_id = ?',
                [...$params, $usuarioId]
            );
        }
    }

    /* ------------------------------------------------------------------ Consulta */

    /** @return array respuesta para el empleado */
    public function recomendar(int $usuarioId, int $interlocutorId, ?array $suscripcion): array
    {
        $prefs = self::preferencias($usuarioId);
        if ($prefs === null) {
            throw ApiException::validation(['preferencias' => 'Cuéntanos primero qué te interesa'], 'Configura tus preferencias para recibir recomendaciones');
        }
        $perfil = self::perfil($usuarioId, $interlocutorId);
        $estados = self::estados($usuarioId, $interlocutorId);
        [$payload, $catalogo, $hash] = self::entrada($prefs, $perfil, $interlocutorId, $estados);

        $modo = $this->ai->mode();
        // DMO-NF4: en la demostración comercial, primero la caché (rápido y sin gastar cuota)
        if ($modo === 'live' && \PymeHub\Core\Demo::enSesion()) {
            $modo = 'cache_first';
        }
        $servido = 'REGLAS';
        $aviso = null;
        $salida = null;
        $metricas = ['tokens_entrada' => null, 'tokens_salida' => null, 'latencia_ms' => null];

        if ($modo === 'offline') {
            $salida = self::cache($hash, true);
            $servido = $salida === null ? 'REGLAS' : 'CACHE';
        } else {
            $salida = $modo === 'cache_first' ? self::cache($hash, false) : null;
            if ($salida !== null) {
                $servido = 'CACHE';
            } elseif ($this->cuotaAgotada($usuarioId, $interlocutorId, $suscripcion)) {
                $aviso = 'PH-AI-002';
            } else {
                [$salida, $aviso, $metricas] = $this->enVivo($payload, $catalogo);
                if ($salida !== null) {
                    $servido = 'LIVE';
                    self::guardarCache($hash, $salida, $this->ai, false);
                }
            }
            if ($salida === null) {
                // Respaldo: la última respuesta conocida para este perfil y, si no hay, las reglas
                $salida = self::cache($hash, true);
                $servido = $salida === null ? 'REGLAS' : 'CACHE';
            }
        }

        $reglas = $this->reglas($prefs, $perfil, $catalogo, $estados);
        $recs = $salida === null ? [] : $this->anclar($salida['recommendations'], $catalogo, $estados);
        $conIa = $recs !== [];
        // IA-001: si quedan menos de 3 válidas, se completa con las reglas
        $objetivo = $conIa ? max(self::MIN, count($recs)) : self::objetivo($prefs['minutos_semana']);
        foreach ($reglas as $id => $r) {
            if (count($recs) >= $objetivo) {
                break;
            }
            $recs[$id] ??= $r;
        }
        $recs = array_slice(array_values($recs), 0, self::MAX);
        $plan = $conIa && is_string($salida['weekly_plan'] ?? null) && $salida['weekly_plan'] !== ''
            ? mb_substr(trim($salida['weekly_plan']), 0, 300)
            : self::planSemanal($recs, $catalogo, $prefs['minutos_semana']);

        $solicitud = Db::insert(
            "INSERT INTO solicitud_ia (interlocutor_id, usuario_id, caso_uso, proveedor, modelo, hash_entrada, tokens_entrada, tokens_salida,
                                       latencia_ms, estado, servido_desde, aviso)
             VALUES (?, ?, 'ASESOR_FORMACION', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $interlocutorId, $usuarioId, $servido === 'REGLAS' ? 'reglas' : $this->ai->provider(),
                $servido === 'REGLAS' ? null : $this->ai->model(), $hash,
                $metricas['tokens_entrada'], $metricas['tokens_salida'], $metricas['latencia_ms'],
                $aviso === null ? 'OK' : 'ERROR', $servido, $aviso,
            ]
        );

        $porId = array_column($catalogo, null, 'id');
        return [
            'solicitud_id'    => $solicitud,
            'servido_desde'   => strtolower($servido === 'LIVE' ? 'live' : ($servido === 'CACHE' ? 'cache' : 'reglas')),
            'generado_con_ia' => $conIa,
            'recomendaciones' => array_map(static function (array $r, int $i) use ($porId, $estados) {
                $m = $porId[$r['id']];
                return [
                    'orden'      => $i + 1,
                    'motivo'     => $r['motivo'],
                    'origen'     => $r['origen'],
                    'modulo'     => [
                        'id' => $m['id'], 'titulo' => $m['titulo'], 'resumen' => $m['resumen'], 'categoria' => $m['categoria'],
                        'nivel' => $m['nivel'], 'duracion_min' => $m['duracion_min'],
                    ],
                    'asignacion' => $estados[$m['id']] ?? null,
                ];
            }, $recs, array_keys($recs)),
            'plan_semanal'    => $plan,
            'aviso'           => $aviso,
        ];
    }

    /**
     * Entrada del modelo con la lista blanca de §12.3 y su hash de caché.
     * La caché no depende del avance de la persona: perfiles iguales comparten respuesta (IA-014);
     * lo completado se filtra al servir.
     * @return array{0: array, 1: array, 2: string} payload, catálogo resumido y hash
     */
    public static function entrada(array $prefs, array $perfil, int $interlocutorId, array $estados): array
    {
        $catalogo = array_map(static fn (array $m) => [
            'id' => $m['id'], 'titulo' => $m['titulo'], 'resumen' => $m['resumen'], 'categoria' => $m['categoria'],
            'nivel' => $m['nivel'], 'duracion_min' => $m['duracion_min'], 'puestos' => $m['puestos_destino'], 'etiquetas' => $m['etiquetas'],
        ], ModuloRepository::list($interlocutorId, [], true));
        $completados = array_keys(array_filter($estados, static fn ($e) => $e['estado'] === 'COMPLETADA'));
        $payload = [
            'perfil'       => $perfil,
            'preferencias' => [
                'objetivo'       => self::OBJETIVOS[$prefs['objetivo']] ?? $prefs['objetivo'],
                'intereses'      => array_values(array_intersect(CatalogoService::CATEGORIAS, $prefs['intereses'])),
                'formato'        => $prefs['formato'],
                'minutos_semana' => $prefs['minutos_semana'],
                'comentario'     => $prefs['comentario'],
            ],
            'catalogo'     => $catalogo,
            'ya_hechos'    => array_values(array_map('intval', $completados)),
        ];
        PayloadGuard::verificar($payload);
        $hash = hash('sha256', json_encode([
            self::VERSION_PROMPT, $payload['perfil'], $payload['preferencias'],
            array_map(static fn ($m) => [$m['id'], $m['titulo'], $m['resumen']], $catalogo),
        ], JSON_UNESCAPED_UNICODE));
        return [$payload, $catalogo, $hash];
    }

    /**
     * Pregenera la respuesta de un perfil de la demo (§12.6, cli/ai_warmup.php).
     * @return string 'ok' | código de aviso si el proveedor falla
     */
    public function pregenerar(array $prefs, array $perfil, int $interlocutorId): string
    {
        [$payload, $catalogo, $hash] = self::entrada($prefs, $perfil, $interlocutorId, []);
        [$salida, $aviso] = $this->enVivo($payload, $catalogo);
        if ($salida === null || count($salida['recommendations']) < self::MIN) {
            return $aviso ?? 'PH-AI-003';
        }
        self::guardarCache($hash, $salida, $this->ai, true);
        return 'ok';
    }

    /* ------------------------------------------------------------------ Proveedor en vivo */

    /** @return array{0: ?array, 1: ?string, 2: array} salida validada, aviso y métricas */
    private function enVivo(array $payload, array $catalogo): array
    {
        $system = (string) file_get_contents(PH_ROOT . '/src/Ai/prompts/' . self::VERSION_PROMPT . '.md');
        $user = "Datos de la persona y CATÁLOGO (JSON):\n" . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $metricas = ['tokens_entrada' => null, 'tokens_salida' => null, 'latencia_ms' => null];
        for ($intento = 1; $intento <= 2; $intento++) {
            try {
                $r = $this->ai->chatJson($system, $user);
            } catch (AiException $e) {
                return [null, $e->motivo === 'RESPUESTA_INVALIDA' ? 'PH-AI-003' : 'PH-AI-001', $metricas];
            }
            $metricas = ['tokens_entrada' => $r['tokens_entrada'], 'tokens_salida' => $r['tokens_salida'], 'latencia_ms' => $r['latencia_ms']];
            $salida = self::validar($r['content'], $catalogo);
            if ($salida !== null) {
                return [$salida, null, $metricas];
            }
        }
        return [null, 'PH-AI-003', $metricas];
    }

    /** IA-013: {recommendations: [{module_id, order, reason ≤ 160}], weekly_plan}. Null si no valida. */
    public static function validar(string $content, array $catalogo): ?array
    {
        $content = trim($content);
        if (str_starts_with($content, '```')) {
            $content = trim(preg_replace('/^```(?:json)?|```$/m', '', $content));
        }
        $d = json_decode($content, true);
        if (!is_array($d) || !isset($d['recommendations']) || !is_array($d['recommendations']) || !array_is_list($d['recommendations'])) {
            return null;
        }
        $ids = array_column($catalogo, 'id');
        $out = [];
        foreach ($d['recommendations'] as $r) {
            if (!is_array($r) || !is_int($r['module_id'] ?? null) || !is_string($r['reason'] ?? null) || trim($r['reason']) === '') {
                return null;
            }
            if (!in_array($r['module_id'], $ids, true)) {
                continue; // IA-001: se descarta lo que no está en el catálogo
            }
            $out[] = [
                'module_id' => $r['module_id'],
                'order'     => is_int($r['order'] ?? null) ? $r['order'] : count($out) + 1,
                'reason'    => mb_substr(trim($r['reason']), 0, 160),
            ];
        }
        usort($out, static fn ($a, $b) => $a['order'] <=> $b['order']);
        return ['recommendations' => $out, 'weekly_plan' => is_string($d['weekly_plan'] ?? null) ? $d['weekly_plan'] : ''];
    }

    /** Ids del catálogo, sin repetir ni completados. */
    private function anclar(array $recs, array $catalogo, array $estados): array
    {
        $ids = array_flip(array_column($catalogo, 'id'));
        $out = [];
        foreach ($recs as $r) {
            $id = (int) $r['module_id'];
            if (!isset($ids[$id]) || isset($out[$id]) || ($estados[$id]['estado'] ?? null) === 'COMPLETADA') {
                continue;
            }
            $out[$id] = ['id' => $id, 'motivo' => $r['reason'], 'origen' => 'IA'];
            if (count($out) >= self::MAX) {
                break;
            }
        }
        return $out;
    }

    private function cuotaAgotada(int $usuarioId, int $interlocutorId, ?array $suscripcion): bool
    {
        $desde = (new \DateTimeImmutable('today', new \DateTimeZone(Tiempo::ZONA)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $propias = (int) Db::value("SELECT COUNT(*) FROM solicitud_ia WHERE usuario_id = ? AND servido_desde = 'LIVE' AND creado_en >= ?", [$usuarioId, $desde]);
        if ($propias >= self::MAX_DIA_EMPLEADO) {
            return true;
        }
        $cuota = (int) ($suscripcion['cuota_ia_diaria'] ?? 0);
        $empresa = (int) Db::value("SELECT COUNT(*) FROM solicitud_ia WHERE interlocutor_id = ? AND servido_desde = 'LIVE' AND creado_en >= ?", [$interlocutorId, $desde]);
        return $empresa >= $cuota;
    }

    /* ------------------------------------------------------------------ Caché (IA-014, IA-015) */

    /** @param bool $caducadas true = también las caducadas (respaldo y modo offline) */
    public static function cache(string $hash, bool $caducadas): ?array
    {
        $r = Db::one(
            'SELECT respuesta FROM respuesta_ia WHERE hash_entrada = ?' . ($caducadas ? '' : ' AND (caduca_en IS NULL OR caduca_en > UTC_TIMESTAMP())'),
            [$hash]
        );
        if ($r === null) {
            return null;
        }
        Db::run('UPDATE respuesta_ia SET usos = usos + 1 WHERE hash_entrada = ?', [$hash]);
        $d = json_decode((string) $r['respuesta'], true);
        return is_array($d) ? $d : null;
    }

    public static function guardarCache(string $hash, array $salida, AiGateway $ai, bool $pregenerada): void
    {
        $json = json_encode($salida, JSON_UNESCAPED_UNICODE);
        $caduca = $pregenerada ? null : gmdate('Y-m-d H:i:s', time() + self::CACHE_DIAS * 86400);
        if (Db::value('SELECT 1 FROM respuesta_ia WHERE hash_entrada = ?', [$hash]) === null) {
            Db::run(
                'INSERT INTO respuesta_ia (hash_entrada, version_prompt, proveedor, modelo, respuesta, pregenerada, caduca_en) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$hash, self::VERSION_PROMPT, $ai->provider(), $ai->model(), $json, (int) $pregenerada, $caduca]
            );
        } else {
            Db::run(
                'UPDATE respuesta_ia SET respuesta = ?, proveedor = ?, modelo = ?, pregenerada = ?, caduca_en = ?, creado_en = UTC_TIMESTAMP() WHERE hash_entrada = ?',
                [$json, $ai->provider(), $ai->model(), (int) $pregenerada, $caduca, $hash]
            );
        }
    }

    /* ------------------------------------------------------------------ Respaldo por reglas (IA-002) */

    /**
     * Emparejamiento determinista: etiquetas y categorías del catálogo × objetivo × puesto × turno
     * × temas de interés. Devuelve todos los candidatos ordenados (sin completados).
     */
    public function reglas(array $prefs, array $perfil, array $catalogo, array $estados): array
    {
        $obj = self::REGLAS_OBJETIVO[$prefs['objetivo']] ?? ['categorias' => [], 'codigos' => []];
        $catObjetivo = $prefs['objetivo'] === 'MEJORAR_TRABAJO' ? (self::CATEGORIAS_PUESTO[$perfil['puesto']] ?? []) : $obj['categorias'];
        $codigos = array_flip($obj['codigos']);
        $codigoDe = [];
        foreach (Db::all('SELECT id, codigo FROM modulo_formativo WHERE id IN (' . implode(',', array_map('intval', array_column($catalogo, 'id')) ?: [0]) . ')') as $r) {
            $codigoDe[(int) $r['id']] = $r['codigo'];
        }
        $noche = $perfil['turno'] === 'NOCHE' || $perfil['turno'] === 'ROTATIVO';
        $palabras = self::raices((string) ($prefs['comentario'] ?? ''));

        $candidatos = [];
        foreach ($catalogo as $i => $m) {
            if (($estados[$m['id']]['estado'] ?? null) === 'COMPLETADA') {
                continue;
            }
            $puntos = 0;
            $motivos = [];
            $etiquetas = array_map(static fn ($e) => mb_strtolower($e), $m['etiquetas']);
            if (isset($codigos[$codigoDe[$m['id']] ?? ''])) {
                $puntos += 4;
                $motivos[] = ['o', 4];
            }
            if (in_array($m['categoria'], $catObjetivo, true)) {
                $puntos += 2;
                $motivos[] = ['o', 2];
            }
            if (in_array($m['categoria'], $prefs['intereses'], true)) {
                $puntos += 3;
                $motivos[] = ['t', 3];
            }
            if (in_array($perfil['puesto'], $m['puestos'], true)) {
                $puntos += 2;
                $motivos[] = ['p', 2];
            }
            if ($noche && array_intersect($etiquetas, ['noche', 'turnos', 'fatiga', 'sueño']) !== []) {
                $puntos += 3;
                $motivos[] = ['n', 5];
            }
            if ($palabras !== [] && array_intersect($palabras, self::raices($m['titulo'] . ' ' . implode(' ', $etiquetas))) !== []) {
                $puntos += 3;
                $motivos[] = ['c', 6];
            }
            if ($perfil['antiguedad'] === 'MENOS_3_MESES' && $m['nivel'] === 'BASICO') {
                $puntos += 1;
            }
            if ($prefs['objetivo'] === 'RESPONSABILIDAD' && $m['nivel'] !== 'BASICO') {
                $puntos += 1;
            }
            if (isset($estados[$m['id']]) && $estados[$m['id']]['estado'] !== 'COMPLETADA') {
                $puntos += 1; // ya empezado: terminarlo antes que empezar otro
            }
            if ($puntos < 3) {
                continue;
            }
            usort($motivos, static fn ($a, $b) => $b[1] <=> $a[1]);
            $candidatos[] = ['id' => $m['id'], 'puntos' => $puntos, 'i' => $i, 'motivo' => self::motivo($motivos[0][0] ?? 'p', $m, $prefs, $perfil, $estados)];
        }
        usort($candidatos, static fn ($a, $b) => [$b['puntos'], $a['i']] <=> [$a['puntos'], $b['i']]);
        $out = [];
        foreach ($candidatos as $c) {
            $out[$c['id']] = ['id' => $c['id'], 'motivo' => $c['motivo'], 'origen' => 'REGLAS'];
        }
        return $out;
    }

    private static function motivo(string $clave, array $m, array $prefs, array $perfil, array $estados): string
    {
        $base = match ($clave) {
            'n' => 'Trabajas en turno de ' . ($perfil['turno'] === 'NOCHE' ? 'noche' : 'rotación') . ' y este módulo está pensado para eso.',
            'c' => 'Tiene que ver con lo que nos cuentas que quieres conseguir.',
            'o' => 'Te acerca a tu objetivo: ' . mb_strtolower(self::OBJETIVOS[$prefs['objetivo']] ?? '') . '.',
            't' => 'Es de un tema que te interesa.',
            default => 'Es útil en tu puesto del día a día.',
        };
        if (isset($estados[$m['id']]) && $estados[$m['id']]['estado'] !== 'COMPLETADA') {
            $base = 'Ya lo has empezado. ' . $base;
        }
        return mb_substr($base . ' Son ' . $m['duracion_min'] . ' minutos.', 0, 160);
    }

    /** Palabras de 5+ letras reducidas a sus 6 primeras, sin tildes (para emparejar el comentario). */
    private static function raices(string $texto): array
    {
        $plano = strtr(mb_strtolower($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $vacias = ['quiero', 'gustaria', 'llegar', 'mejor', 'tengo', 'trabajo', 'puedo', 'sobre', 'hacer', 'aprender', 'porque'];
        $out = [];
        foreach (preg_split('/[^a-z]+/', $plano, -1, PREG_SPLIT_NO_EMPTY) as $p) {
            if (strlen($p) >= 5 && !in_array($p, $vacias, true)) {
                $out[substr($p, 0, 6)] = substr($p, 0, 6);
            }
        }
        return array_values($out);
    }

    /** Número de módulos según el tiempo disponible: 3 con 5 o 15 minutos, 4 con 30. */
    private static function objetivo(int $minutos): int
    {
        return $minutos >= 30 ? 4 : self::MIN;
    }

    /** Reparte los módulos en semanas con los minutos disponibles (al menos uno por semana). */
    public static function planSemanal(array $recs, array $catalogo, int $minutos): string
    {
        $porId = array_column($catalogo, null, 'id');
        $semanas = [];
        $actual = [];
        $usado = 0;
        foreach ($recs as $r) {
            $m = $porId[$r['id']] ?? null;
            if ($m === null) {
                continue;
            }
            if ($actual !== [] && $usado + $m['duracion_min'] > $minutos) {
                $semanas[] = $actual;
                $actual = [];
                $usado = 0;
            }
            $actual[] = $m['titulo'];
            $usado += $m['duracion_min'];
        }
        if ($actual !== []) {
            $semanas[] = $actual;
        }
        $partes = [];
        foreach ($semanas as $i => $titulos) {
            $partes[] = 'Semana ' . ($i + 1) . ': ' . implode(' y ', $titulos) . '.';
        }
        $texto = 'Con ' . $minutos . ' minutos a la semana: ' . implode(' ', $partes);
        return mb_strlen($texto) > 300 ? mb_substr($texto, 0, 297) . '…' : $texto;
    }

    /* ------------------------------------------------------------------ Datos de apoyo */

    /** Perfil laboral no identificativo (§12.3): puesto, tramo de antigüedad y turno. */
    public static function perfil(int $usuarioId, int $interlocutorId): array
    {
        $f = Db::one('SELECT puesto, turno, fecha_alta FROM ficha_laboral WHERE usuario_id = ? AND interlocutor_id = ?', [$usuarioId, $interlocutorId]);
        $dias = $f === null ? 0 : (int) (new \DateTimeImmutable($f['fecha_alta']))->diff(new \DateTimeImmutable(Tiempo::hoy()))->days;
        return [
            'puesto'     => $f['puesto'] ?? 'OTRO',
            'antiguedad' => $dias < 90 ? 'MENOS_3_MESES' : ($dias <= 365 ? 'DE_3_A_12_MESES' : 'MAS_DE_1_ANO'),
            'turno'      => $f['turno'] ?? 'MANANA',
        ];
    }

    /** Situación de la persona en cada módulo: [modulo_id => {id, estado, origen}] */
    public static function estados(int $usuarioId, int $interlocutorId): array
    {
        $out = [];
        foreach (Db::all(
            "SELECT id, modulo_id, origen,
                    CASE WHEN estado <> 'COMPLETADA' AND fecha_limite IS NOT NULL AND fecha_limite < ? THEN 'VENCIDA' ELSE estado END AS estado
               FROM asignacion_formativa WHERE usuario_id = ? AND interlocutor_id = ?",
            [Tiempo::hoy(), $usuarioId, $interlocutorId]
        ) as $r) {
            $out[(int) $r['modulo_id']] = ['id' => (int) $r['id'], 'estado' => $r['estado'], 'origen' => $r['origen']];
        }
        return $out;
    }
}
