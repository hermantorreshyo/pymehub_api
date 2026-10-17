<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\Crypto;
use PymeHub\Core\Db;
use PymeHub\Core\Tiempo;
use PymeHub\Core\Zip;

/**
 * Exportaciones (LEG-017, RF-110 y derechos RGPD).
 *
 * - empresa(): ZIP con CSV y JSON de toda la información de la empresa, para su portabilidad
 *   antes de la baja. Bienestar solo agregado por equipo y semana con el umbral de anonimato
 *   (LEG-002); nunca respuestas individuales ni notas cifradas (LEG-003).
 * - persona(): JSON con los datos de una persona (derecho de acceso y portabilidad, RGPD art. 15
 *   y 20), incluidas sus notas del check-in descifradas, que solo ella puede leer.
 */
final class ExportacionService
{
    private const UMBRAL = 5;

    public static function empresa(int $iid): string
    {
        $empresa = Db::one(
            'SELECT id, nombre, nombre_comercial, nif, sector, email_contacto, telefono_contacto,
                    fecha_contrato_encargado, estado, fecha_baja, creado_en
               FROM interlocutor WHERE id = ?',
            [$iid]
        );
        $suscripciones = Db::all(
            'SELECT plan, ciclo_cobro, precio_empleado_mes, limite_empleados, fecha_inicio, fecha_fin, estado
               FROM suscripcion WHERE interlocutor_id = ? ORDER BY fecha_inicio',
            [$iid]
        );
        $lineaBase = Db::all(
            'SELECT periodo_inicio, periodo_fin, plantilla_media, bajas_voluntarias, dias_absentismo, fuente
               FROM linea_base WHERE interlocutor_id = ?',
            [$iid]
        );
        $equipos = Db::all(
            'SELECT id, nombre, zona, creado_en, eliminado_en FROM equipo WHERE interlocutor_id = ? ORDER BY nombre',
            [$iid]
        );
        $personas = Db::all(
            "SELECT u.id, u.perfil, u.nombre, u.apellidos, u.email, u.estado_acceso, u.ultimo_acceso_en,
                    f.codigo_interno, f.puesto, f.tipo_contrato, f.turno, f.fecha_alta, f.fecha_baja, f.motivo_baja,
                    e.nombre AS equipo
               FROM usuario u
               LEFT JOIN ficha_laboral f ON f.usuario_id = u.id AND f.interlocutor_id = u.interlocutor_id
               LEFT JOIN equipo e ON e.id = f.equipo_id
              WHERE u.interlocutor_id = ? AND u.eliminado_en IS NULL
              ORDER BY u.perfil, u.apellidos, u.nombre",
            [$iid]
        );
        $empleados = array_values(array_filter($personas, static fn ($p) => $p['perfil'] === 'EMPLEADO'));
        $gestion = array_values(array_map(
            static fn ($p) => array_intersect_key($p, array_flip(['id', 'perfil', 'nombre', 'apellidos', 'email', 'estado_acceso', 'ultimo_acceso_en'])),
            array_filter($personas, static fn ($p) => $p['perfil'] !== 'EMPLEADO')
        ));
        $incidencias = Db::all(
            'SELECT i.id, i.usuario_id, u.nombre, u.apellidos, i.tipo, i.fecha, i.nota, i.creado_en
               FROM incidencia i JOIN usuario u ON u.id = i.usuario_id
              WHERE i.interlocutor_id = ? ORDER BY i.fecha',
            [$iid]
        );
        $formacion = Db::all(
            'SELECT a.id, a.usuario_id, u.nombre, u.apellidos, m.codigo AS modulo_codigo, m.titulo AS modulo, a.origen,
                    a.estado, a.fecha_limite, a.progreso_pct, a.intentos, a.mejor_nota, a.completada_en, a.creado_en
               FROM asignacion_formativa a
               JOIN usuario u ON u.id = a.usuario_id
               JOIN modulo_formativo m ON m.id = a.modulo_id
              WHERE a.interlocutor_id = ? ORDER BY a.creado_en',
            [$iid]
        );
        $modulos = Db::all(
            'SELECT id, codigo, titulo, resumen, categoria, nivel, duracion_min, contenido, estado, creado_en
               FROM modulo_formativo WHERE interlocutor_id = ?',
            [$iid]
        );
        foreach ($modulos as &$m) {
            $m['contenido'] = json_decode((string) $m['contenido'], true);
            $m['preguntas'] = array_map(static function ($q) {
                $q['opciones'] = json_decode((string) $q['opciones'], true);
                return $q;
            }, Db::all('SELECT enunciado, opciones, indice_correcto FROM pregunta_modulo WHERE modulo_id = ? ORDER BY orden', [$m['id']]));
        }
        unset($m);
        $alertas = Db::all(
            'SELECT id, tipo, entidad, entidad_id, severidad, mensaje, estado, gestionada_en, nota_gestion, creado_en
               FROM alerta WHERE interlocutor_id = ? ORDER BY creado_en',
            [$iid]
        );
        $riesgo = Db::all(
            'SELECT p.usuario_id, u.nombre, u.apellidos, p.fecha, p.puntuacion, p.nivel, p.factores, p.version_reglas
               FROM puntuacion_riesgo p
               JOIN (SELECT usuario_id, MAX(fecha) AS f FROM puntuacion_riesgo WHERE interlocutor_id = ? GROUP BY usuario_id) m
                 ON m.usuario_id = p.usuario_id AND m.f = p.fecha
               JOIN usuario u ON u.id = p.usuario_id
              WHERE p.interlocutor_id = ?',
            [$iid, $iid]
        );

        $zip = (new Zip())
            ->add('LEEME.txt', self::leeme($empresa))
            ->add('empresa.json', self::json(['empresa' => $empresa, 'suscripciones' => $suscripciones, 'linea_base' => $lineaBase]))
            ->add('equipos.csv', Zip::csv($equipos, ['id', 'nombre', 'zona', 'creado_en', 'eliminado_en']))
            ->add('empleados.csv', Zip::csv($empleados, ['id', 'perfil', 'nombre', 'apellidos', 'email', 'estado_acceso', 'ultimo_acceso_en', 'codigo_interno', 'puesto', 'tipo_contrato', 'turno', 'fecha_alta', 'fecha_baja', 'motivo_baja', 'equipo']))
            ->add('cuentas_gestion.csv', Zip::csv($gestion, ['id', 'perfil', 'nombre', 'apellidos', 'email', 'estado_acceso', 'ultimo_acceso_en']))
            ->add('incidencias.csv', Zip::csv($incidencias, ['id', 'usuario_id', 'nombre', 'apellidos', 'tipo', 'fecha', 'nota', 'creado_en']))
            ->add('formacion.csv', Zip::csv($formacion, ['id', 'usuario_id', 'nombre', 'apellidos', 'modulo_codigo', 'modulo', 'origen', 'estado', 'fecha_limite', 'progreso_pct', 'intentos', 'mejor_nota', 'completada_en', 'creado_en']))
            ->add('modulos_propios.json', self::json($modulos))
            ->add('bienestar_semanal_por_equipo.csv', Zip::csv(self::bienestar($iid), ['semana', 'equipo', 'participantes', 'indice', 'animo', 'energia', 'estres']))
            ->add('alertas.csv', Zip::csv($alertas, ['id', 'tipo', 'entidad', 'entidad_id', 'severidad', 'mensaje', 'estado', 'gestionada_en', 'nota_gestion', 'creado_en']))
            ->add('riesgo_rotacion_ultimo.csv', Zip::csv($riesgo, ['usuario_id', 'nombre', 'apellidos', 'fecha', 'puntuacion', 'nivel', 'factores', 'version_reglas']))
            ->add('pagos.csv', Zip::csv(Db::all('SELECT id, origen, numero, estado, plan, ciclo_cobro, empleados, base, iva, total, moneda, periodo_inicio, periodo_fin, url_factura, creado_en
                                                  FROM pago WHERE interlocutor_id = ? ORDER BY creado_en', [$iid]),
                ['id', 'origen', 'numero', 'estado', 'plan', 'ciclo_cobro', 'empleados', 'base', 'iva', 'total', 'moneda', 'periodo_inicio', 'periodo_fin', 'url_factura', 'creado_en']));
        return $zip->build();
    }

    /** Medias semanales por equipo con al menos 5 personas (LEG-002). */
    private static function bienestar(int $iid): array
    {
        $rows = Db::all(
            'SELECT DATE_SUB(c.fecha, INTERVAL WEEKDAY(c.fecha) DAY) AS semana, e.nombre AS equipo,
                    COUNT(DISTINCT c.usuario_id) AS participantes,
                    AVG(((c.animo - 1) + (c.energia - 1) + (5 - c.estres)) / 12 * 100) AS indice,
                    AVG(c.animo) AS animo, AVG(c.energia) AS energia, AVG(c.estres) AS estres
               FROM checkin_bienestar c
               LEFT JOIN equipo e ON e.id = c.equipo_id
              WHERE c.interlocutor_id = ?
              GROUP BY DATE_SUB(c.fecha, INTERVAL WEEKDAY(c.fecha) DAY), e.nombre
              ORDER BY semana, equipo',
            [$iid]
        );
        $out = [];
        foreach ($rows as $r) {
            if ((int) $r['participantes'] < self::UMBRAL) {
                continue;
            }
            $out[] = [
                'semana' => $r['semana'], 'equipo' => $r['equipo'] ?? 'Sin equipo', 'participantes' => (int) $r['participantes'],
                'indice' => (int) round((float) $r['indice']), 'animo' => round((float) $r['animo'], 1),
                'energia' => round((float) $r['energia'], 1), 'estres' => round((float) $r['estres'], 1),
            ];
        }
        return $out;
    }

    private static function leeme(array $empresa): string
    {
        return "Exportación de datos de Pyme Hub\n"
            . "Empresa: {$empresa['nombre']} (NIF {$empresa['nif']})\n"
            . 'Generada: ' . gmdate('Y-m-d H:i') . " UTC\n\n"
            . "Contenido (CSV en UTF-8 separados por punto y coma; JSON en UTF-8):\n"
            . "- empresa.json: datos de la empresa, suscripciones y línea base de impacto\n"
            . "- equipos.csv, empleados.csv y cuentas_gestion.csv: organización y personas\n"
            . "- incidencias.csv: ausencias injustificadas, retrasos, siniestros, quejas y reconocimientos\n"
            . "- formacion.csv y modulos_propios.json: formación asignada y módulos creados por la empresa\n"
            . "- bienestar_semanal_por_equipo.csv: medias semanales de equipos con 5 personas o más\n"
            . "- alertas.csv y riesgo_rotacion_ultimo.csv: alertas y última puntuación de riesgo de cada persona\n"
            . "- pagos.csv: facturas y pagos de la suscripción (base, IVA y total)\n\n"
            . "No se incluyen respuestas individuales ni notas del check-in de bienestar: son anónimas para la\n"
            . "empresa y cada persona puede descargar las suyas desde su cuenta.\n";
    }

    /** Datos de una persona (derecho de acceso y portabilidad). */
    public static function persona(int $uid): string
    {
        $u = Db::one(
            "SELECT u.id, u.interlocutor_id, u.perfil, u.nombre, u.apellidos, u.email, u.estado_acceso,
                    u.privacidad_aceptada_en, u.privacidad_version, u.ultimo_acceso_en, u.creado_en, i.nombre AS empresa
               FROM usuario u JOIN interlocutor i ON i.id = u.interlocutor_id WHERE u.id = ?",
            [$uid]
        );
        $ficha = Db::one(
            'SELECT f.codigo_interno, f.puesto, f.tipo_contrato, f.turno, f.fecha_alta, f.fecha_baja, f.motivo_baja, e.nombre AS equipo
               FROM ficha_laboral f LEFT JOIN equipo e ON e.id = f.equipo_id WHERE f.usuario_id = ?',
            [$uid]
        );
        $checkins = array_map(static function ($c) {
            $c['nota'] = $c['nota_cifrada'] === null ? null : Crypto::decrypt($c['nota_cifrada']);
            unset($c['nota_cifrada']);
            return $c;
        }, Db::all('SELECT fecha, animo, energia, estres, nota_cifrada FROM checkin_bienestar WHERE usuario_id = ? ORDER BY fecha', [$uid]));
        $data = [
            'generado_en'  => gmdate('c'),
            'aviso'        => 'Datos personales que Pyme Hub trata sobre ti (RGPD, art. 15 y 20).',
            'persona'      => $u,
            'ficha_laboral' => $ficha,
            'checkins_bienestar' => $checkins,
            'incidencias'  => Db::all('SELECT tipo, fecha, nota FROM incidencia WHERE usuario_id = ? ORDER BY fecha', [$uid]),
            'formacion'    => Db::all(
                'SELECT m.titulo AS modulo, a.origen, a.estado, a.fecha_limite, a.progreso_pct, a.intentos, a.mejor_nota, a.completada_en
                   FROM asignacion_formativa a JOIN modulo_formativo m ON m.id = a.modulo_id WHERE a.usuario_id = ? ORDER BY a.creado_en',
                [$uid]
            ),
            'preferencias_asesor' => Db::one('SELECT objetivo, intereses, formato, minutos_semana, comentario, actualizado_en FROM preferencia_formativa WHERE usuario_id = ?', [$uid]),
            'consultas_asesor' => Db::all('SELECT servido_desde, util, creado_en FROM solicitud_ia WHERE usuario_id = ? ORDER BY creado_en', [$uid]),
            'puntuaciones_riesgo_rotacion' => array_map(static function ($p) {
                $p['factores'] = json_decode((string) $p['factores'], true);
                return $p;
            }, Db::all('SELECT fecha, puntuacion, nivel, factores, version_reglas FROM puntuacion_riesgo WHERE usuario_id = ? ORDER BY fecha DESC LIMIT 90', [$uid])),
            'nota_riesgo'  => 'La puntuación de riesgo de rotación la usa tu empresa para orientar una conversación; no decide nada sobre ti (LEG-005). La metodología está en la hoja técnica del modelo.',
        ];
        return self::json($data);
    }

    private static function json(mixed $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n";
    }

    public static function nombreArchivo(string $prefijo): string
    {
        return $prefijo . '-' . str_replace('-', '', Tiempo::hoy()) . '.zip';
    }
}
