<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\Db;

/**
 * Borrado definitivo de empresas (LEG-017, cli/tenant_purge.php) y de la demo comercial
 * (cli/demo_seed.php --reset).
 *
 * Recorre todas las tablas con interlocutor_id o usuario_id, de modo que una tabla nueva
 * queda cubierta sin tocar este código. El registro de auditoría no se borra: se anonimiza
 * (sin empresa ni persona), para conservar la traza de que la acción existió (LEG-004).
 */
final class BorradoService
{
    /** Días entre la baja de una empresa y su borrado definitivo (LEG-017; parámetro operacion.dias_borrado_tras_baja). */
    public static function diasTrasBaja(): int
    {
        return max(1, \PymeHub\Core\Parametros::int('operacion.dias_borrado_tras_baja', 30));
    }

    /** Fecha (AAAA-MM-DD) del borrado definitivo de una empresa dada de baja, o null. */
    public static function fechaBorrado(?string $fechaBaja): ?string
    {
        return $fechaBaja === null ? null : gmdate('Y-m-d', strtotime($fechaBaja . ' UTC') + self::diasTrasBaja() * 86400);
    }

    /**
     * @param list<int> $interlocutores empresas a borrar
     * @param list<int> $usuarios        personas a borrar además de las de esas empresas
     * @return array{empresas: int, usuarios: int}
     */
    public static function borrar(array $interlocutores, array $usuarios = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $interlocutores)));
        $uids = array_map('intval', $usuarios);
        if ($ids !== []) {
            $uids = array_merge($uids, array_map('intval', array_column(Db::all(
                "SELECT id FROM usuario WHERE perfil <> 'ADMIN' AND interlocutor_id IN (" . implode(',', $ids) . ')'
            ), 'id')));
        }
        $uids = array_values(array_unique($uids));
        if ($ids === [] && $uids === []) {
            return ['empresas' => 0, 'usuarios' => 0];
        }
        $tablas = Db::all(
            "SELECT TABLE_NAME AS t,
                    SUM(COLUMN_NAME = 'interlocutor_id') AS por_interlocutor,
                    SUM(COLUMN_NAME = 'usuario_id') AS por_usuario
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('interlocutor_id', 'usuario_id')
              GROUP BY TABLE_NAME"
        );
        $inI = implode(',', $ids);
        $inU = implode(',', $uids);
        $pdo = Db::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            // Auditoría: se conserva sin datos que identifiquen a la empresa o a la persona
            $cond = [];
            if ($ids !== []) {
                $cond[] = "interlocutor_id IN ($inI)";
            }
            if ($uids !== []) {
                $cond[] = "usuario_id IN ($inU)";
                $cond[] = "(entidad = 'usuario' AND entidad_id IN ($inU))";
            }
            $pdo->exec('UPDATE registro_auditoria SET usuario_id = NULL, interlocutor_id = NULL, entidad_id = NULL, ip = NULL WHERE ' . implode(' OR ', $cond));
            foreach ($tablas as $t) {
                $tabla = preg_replace('/[^a-z_]/', '', (string) $t['t']);
                if ($tabla === 'registro_auditoria') {
                    continue;
                }
                if ((int) $t['por_interlocutor'] > 0 && $ids !== []) {
                    $pdo->exec("DELETE FROM `$tabla` WHERE interlocutor_id IN ($inI)");
                }
                if ((int) $t['por_usuario'] > 0 && $uids !== []) {
                    $pdo->exec("DELETE FROM `$tabla` WHERE usuario_id IN ($inU)");
                }
            }
            if ($uids !== []) {
                $pdo->exec("DELETE FROM alerta WHERE entidad = 'USUARIO' AND entidad_id IN ($inU)");
                $pdo->exec("DELETE FROM usuario WHERE id IN ($inU)");
            }
            if ($ids !== []) {
                $pdo->exec("DELETE FROM interlocutor WHERE id IN ($inI)");
            }
            // Preguntas de los módulos propios borrados (sin comprobación de claves no hay ON DELETE CASCADE)
            $pdo->exec('DELETE FROM pregunta_modulo WHERE modulo_id NOT IN (SELECT id FROM modulo_formativo)');
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
        return ['empresas' => count($ids), 'usuarios' => count($uids)];
    }
}
