<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\Db;

/**
 * Catálogo global de micro-módulos (RF-040): lo publica la plataforma y lo ven todas las empresas.
 *
 * El contenido vive en data/catalogo_formativo.php y se sincroniza con la base de datos por
 * `codigo`: crea los que faltan y, si el contenido cambia, actualiza el módulo y sube su versión.
 * El avance y las notas de quien ya lo cursa no se tocan.
 */
final class CatalogoService
{
    public const CATEGORIAS = ['CONDUCCION', 'ALMACEN', 'CLIENTE', 'BIENESTAR', 'DESARROLLO'];
    public const NIVELES = ['BASICO', 'INTERMEDIO', 'AVANZADO'];
    public const PUESTOS = ['CONDUCTOR', 'REPARTIDOR', 'MOZO_ALMACEN', 'COORDINADOR', 'ADMINISTRATIVO', 'OTRO'];

    /** @return array{creados: int, actualizados: int, total: int} */
    public static function sincronizar(): array
    {
        $plataforma = (int) Db::value("SELECT id FROM interlocutor WHERE tipo = 'PLATAFORMA'");
        if ($plataforma === 0) {
            throw new \RuntimeException('No existe el interlocutor de la plataforma: instala la API primero.');
        }
        $catalogo = require PH_ROOT . '/data/catalogo_formativo.php';
        $creados = $actualizados = 0;

        Db::transaction(function () use ($catalogo, $plataforma, &$creados, &$actualizados): void {
            foreach ($catalogo as $m) {
                $preguntas = self::barajar($m['codigo'], $m['preguntas']);
                $contenido = json_encode($m['contenido'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $huella = hash('sha256', $contenido . json_encode($preguntas, JSON_UNESCAPED_UNICODE)
                    . $m['titulo'] . $m['resumen'] . $m['categoria'] . $m['nivel'] . $m['duracion']
                    . json_encode($m['puestos']) . json_encode($m['etiquetas'], JSON_UNESCAPED_UNICODE));
                $actual = Db::one(
                    'SELECT id, huella FROM modulo_formativo WHERE interlocutor_id = ? AND codigo = ?',
                    [$plataforma, $m['codigo']]
                );
                $campos = [
                    $m['titulo'], $m['resumen'], $m['categoria'], $m['nivel'],
                    json_encode($m['puestos']), json_encode($m['etiquetas'], JSON_UNESCAPED_UNICODE),
                    (int) $m['duracion'], $contenido,
                ];
                if ($actual === null) {
                    $id = Db::insert(
                        "INSERT INTO modulo_formativo (interlocutor_id, codigo, titulo, resumen, categoria, nivel, puestos_destino,
                                                       etiquetas, duracion_min, contenido, huella, estado)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PUBLICADO')",
                        [$plataforma, $m['codigo'], ...$campos, $huella]
                    );
                    self::guardarPreguntas($id, $preguntas);
                    $creados++;
                    continue;
                }
                if ($actual['huella'] === $huella) {
                    continue;
                }
                $id = (int) $actual['id'];
                Db::run(
                    "UPDATE modulo_formativo
                        SET titulo = ?, resumen = ?, categoria = ?, nivel = ?, puestos_destino = ?, etiquetas = ?,
                            duracion_min = ?, contenido = ?, huella = ?, estado = 'PUBLICADO', version = version + 1,
                            eliminado_en = NULL
                      WHERE id = ?",
                    [...$campos, $huella, $id]
                );
                Db::run('DELETE FROM pregunta_modulo WHERE modulo_id = ?', [$id]);
                self::guardarPreguntas($id, $preguntas);
                $actualizados++;
            }
        });
        return ['creados' => $creados, 'actualizados' => $actualizados, 'total' => count($catalogo)];
    }

    /** Inserta las preguntas de un módulo: [['enunciado', 'opciones' => [...], 'correcta' => i], ...] */
    public static function guardarPreguntas(int $moduloId, array $preguntas): void
    {
        foreach (array_values($preguntas) as $i => $p) {
            Db::insert(
                'INSERT INTO pregunta_modulo (modulo_id, orden, enunciado, opciones, indice_correcto) VALUES (?, ?, ?, ?, ?)',
                [$moduloId, $i + 1, $p['enunciado'], json_encode(array_values($p['opciones']), JSON_UNESCAPED_UNICODE), (int) $p['correcta']]
            );
        }
    }

    /**
     * Ordena las opciones de forma determinista por módulo y pregunta, para que la respuesta
     * correcta no esté siempre en la misma posición. Mismo código, mismo orden.
     */
    private static function barajar(string $codigo, array $preguntas): array
    {
        $out = [];
        foreach ($preguntas as $i => $p) {
            $orden = array_keys($p['opciones']);
            mt_srand(crc32($codigo . '#' . $i));
            shuffle($orden);
            $out[] = [
                'enunciado' => $p['enunciado'],
                'opciones'  => array_map(static fn (int $k) => $p['opciones'][$k], $orden),
                'correcta'  => array_search($p['correcta'], $orden, true),
            ];
        }
        mt_srand();
        return $out;
    }
}
