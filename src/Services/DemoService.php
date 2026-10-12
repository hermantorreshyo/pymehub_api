<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\Crypto;
use PymeHub\Core\Db;
use PymeHub\Core\Tiempo;

/**
 * Datos de demostración para la defensa del TFM (§18, RF-100, LEG-009: solo datos ficticios).
 *
 * - «Reparto Rápido Castilla S.L.»: plan Completo, 48 empleados en 4 equipos (Zona Norte,
 *   Zona Sur, Nocturno, Almacén), con el equipo Nocturno empeorando en estrés.
 * - «Mensajería Tajo S.L.»: plan Entrada, 15 empleados en 2 equipos.
 * - Usuarios de acceso: admin@, gerente@, rrhh@ y conductor@ (Castilla) y gerente2@ (Tajo),
 *   en el dominio ficticio pymehub.demo, todos con la contraseña indicada al generar.
 *
 * Cada fase añade aquí sus datos (fase 4: bienestar). Es determinista: misma semilla, mismos datos.
 */
final class DemoService
{
    public const DOMINIO = 'pymehub.demo';
    public const NIF_CASTILLA = 'B45000001';
    public const NIF_TAJO = 'B45000002';

    private const NOMBRES = ['Lucía', 'Javier', 'Marta', 'Daniel', 'Sara', 'Pablo', 'Elena', 'Álvaro', 'Carmen', 'Hugo',
        'Laura', 'Diego', 'Paula', 'Adrián', 'Irene', 'Sergio', 'Nuria', 'Raúl', 'Cristina', 'Iván', 'Andrea', 'Rubén',
        'Silvia', 'Óscar', 'Beatriz', 'Mario', 'Rocío', 'Jorge', 'Alba', 'Víctor', 'Noelia', 'Alberto', 'Patricia',
        'Héctor', 'Lorena', 'Samuel', 'Inés', 'Gonzalo', 'Eva', 'Marcos', 'Clara', 'Rafael', 'Teresa', 'Ismael', 'Julia',
        'Nicolás', 'Aitana', 'Miguel', 'Ainhoa', 'Fernando', 'Yolanda', 'Saúl', 'Miriam', 'Tomás', 'Celia'];
    private const APELLIDOS = ['García', 'Martín', 'López', 'Sánchez', 'Gómez', 'Fernández', 'Ruiz', 'Díaz', 'Moreno',
        'Muñoz', 'Álvarez', 'Romero', 'Navarro', 'Torres', 'Domínguez', 'Gil', 'Vázquez', 'Serrano', 'Ramos', 'Blanco',
        'Molina', 'Castro', 'Ortega', 'Delgado', 'Rubio', 'Marín', 'Sanz', 'Iglesias', 'Medina', 'Garrido', 'Cortés',
        'Castillo', 'Santos', 'Lozano', 'Guerrero', 'Cano', 'Prieto', 'Méndez', 'Cruz', 'Calvo', 'Gallego', 'Herrera'];

    private const NOTAS_CONDUCTOR = [
        'Ruta larga, pero buen ambiente en el turno.',
        'Me ha costado dormir esta semana.',
        'Hoy he terminado antes de lo previsto.',
        'Mucho tráfico en la M-30, algo cansado.',
    ];

    private string $hash;
    private int $seq = 0;

    /** @param string $password contraseña común de los usuarios de acceso (mínimo 10 caracteres) */
    public function __construct(private readonly string $password, private readonly int $semilla = 2026)
    {
        $this->hash = password_hash($password, PASSWORD_ARGON2ID);
    }

    /** ¿Existen ya las empresas de demostración? */
    public static function existe(): bool
    {
        return Db::value('SELECT 1 FROM interlocutor WHERE nif IN (?, ?) LIMIT 1', [self::NIF_CASTILLA, self::NIF_TAJO]) !== null;
    }

    /**
     * Borra las empresas de demostración y todo lo que cuelga de ellas, en cualquier tabla con
     * interlocutor_id o usuario_id. Solo toca los NIF de demostración y los usuarios @pymehub.demo.
     */
    public static function borrar(): void
    {
        $ids = array_map('intval', array_column(Db::all('SELECT id FROM interlocutor WHERE nif IN (?, ?)', [self::NIF_CASTILLA, self::NIF_TAJO]), 'id'));
        $usuarios = array_map('intval', array_column(Db::all(
            'SELECT id FROM usuario WHERE email LIKE ?' . ($ids === [] ? '' : ' OR interlocutor_id IN (' . implode(',', $ids) . ')'),
            ['%@' . self::DOMINIO]
        ), 'id'));
        if ($ids === [] && $usuarios === []) {
            return;
        }
        $tablas = Db::all(
            "SELECT TABLE_NAME AS t,
                    SUM(COLUMN_NAME = 'interlocutor_id') AS por_interlocutor,
                    SUM(COLUMN_NAME = 'usuario_id') AS por_usuario
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('interlocutor_id', 'usuario_id')
              GROUP BY TABLE_NAME"
        );
        $pdo = Db::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tablas as $t) {
                $tabla = preg_replace('/[^a-z_]/', '', (string) $t['t']);
                if ((int) $t['por_interlocutor'] > 0 && $ids !== []) {
                    $pdo->exec("DELETE FROM `$tabla` WHERE interlocutor_id IN (" . implode(',', $ids) . ')');
                }
                if ((int) $t['por_usuario'] > 0 && $usuarios !== []) {
                    $pdo->exec("DELETE FROM `$tabla` WHERE usuario_id IN (" . implode(',', $usuarios) . ')');
                }
            }
            if ($usuarios !== []) {
                $pdo->exec('DELETE FROM usuario WHERE id IN (' . implode(',', $usuarios) . ')');
            }
            if ($ids !== []) {
                $pdo->exec('DELETE FROM interlocutor WHERE id IN (' . implode(',', $ids) . ')');
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** Genera todos los datos. Devuelve un resumen para la consola. */
    public function generar(): array
    {
        mt_srand($this->semilla);
        $hoy = Tiempo::hoy();
        $resumen = [];

        Db::transaction(function () use ($hoy, &$resumen): void {
            $this->admin();

            // --- Reparto Rápido Castilla S.L. (plan Completo) ---
            $castilla = $this->empresa('Reparto Rápido Castilla S.L.', 'Reparto Rápido', self::NIF_CASTILLA, 'COMPLETO', 'MENSUAL', 60);
            $this->acceso($castilla, 'GERENTE', 'Ana', 'García Morales', 'gerente@' . self::DOMINIO);
            $this->acceso($castilla, 'RRHH', 'Lucía', 'Prieto Sanz', 'rrhh@' . self::DOMINIO);
            $equiposC = [
                'Zona Norte' => ['zona' => 'Madrid norte y Alcobendas', 'n' => 13, 'puestos' => ['CONDUCTOR', 'REPARTIDOR'], 'turnos' => ['MANANA', 'TARDE']],
                'Zona Sur'   => ['zona' => 'Getafe, Leganés y Parla', 'n' => 13, 'puestos' => ['CONDUCTOR', 'REPARTIDOR'], 'turnos' => ['MANANA', 'TARDE']],
                'Nocturno'   => ['zona' => 'Rutas nocturnas de la Comunidad de Madrid', 'n' => 11, 'puestos' => ['CONDUCTOR'], 'turnos' => ['NOCHE']],
                'Almacén'    => ['zona' => 'Centro de distribución de Coslada', 'n' => 10, 'puestos' => ['MOZO_ALMACEN'], 'turnos' => ['ROTATIVO', 'MANANA']],
            ];
            $plantillaC = $this->plantilla($castilla, $equiposC, $hoy, 'reparto-castilla');
            // El conductor de la demo: turno de noche, 2 meses de antigüedad (§12.6)
            $conductor = $this->empleado($castilla, $plantillaC['equipos']['Nocturno'], 'Javier', 'Ruiz Sánchez', 'conductor@' . self::DOMINIO,
                'CONDUCTOR', 'TEMPORAL', 'NOCHE', Tiempo::sumarDias($hoy, -62), 'EMP-0100', true);
            $plantillaC['empleados'][] = ['id' => $conductor, 'equipo' => 'Nocturno'];
            $resumen['castilla'] = ['id' => $castilla, 'empleados' => count($plantillaC['empleados'])];

            // --- Mensajería Tajo S.L. (plan Entrada) ---
            $tajo = $this->empresa('Mensajería Tajo S.L.', 'Mensajería Tajo', self::NIF_TAJO, 'ENTRADA', 'ANUAL', 25);
            $this->acceso($tajo, 'GERENTE', 'Pedro', 'Navarro Gil', 'gerente2@' . self::DOMINIO);
            $equiposT = [
                'Reparto' => ['zona' => 'Toledo y Talavera', 'n' => 8, 'puestos' => ['REPARTIDOR', 'CONDUCTOR'], 'turnos' => ['MANANA', 'TARDE']],
                'Almacén' => ['zona' => 'Nave de Toledo', 'n' => 7, 'puestos' => ['MOZO_ALMACEN'], 'turnos' => ['MANANA', 'ROTATIVO']],
            ];
            $plantillaT = $this->plantilla($tajo, $equiposT, $hoy, 'mensajeria-tajo');
            $resumen['tajo'] = ['id' => $tajo, 'empleados' => count($plantillaT['empleados'])];

            // --- Fase 4 · Bienestar: 60 días de check-ins ---
            $resumen['checkins'] = $this->checkins($castilla, $plantillaC, $hoy, $conductor)
                + $this->checkins($tajo, $plantillaT, $hoy, null);
        });

        // Alertas de equipo con la última semana completa (RF-054)
        $resumen['alertas'] = BienestarService::evaluarAlertas($resumen['castilla']['id'], $hoy)
            + BienestarService::evaluarAlertas($resumen['tajo']['id'], $hoy);
        return $resumen;
    }

    /* ------------------------------------------------------------------ */

    private function admin(): void
    {
        $email = 'admin@' . self::DOMINIO;
        if (Db::value('SELECT 1 FROM usuario WHERE email = ?', [$email]) !== null) {
            return;
        }
        $plataforma = (int) Db::value("SELECT id FROM interlocutor WHERE tipo = 'PLATAFORMA'");
        Db::insert(
            "INSERT INTO usuario (interlocutor_id, tipo_interlocutor, perfil, nombre, apellidos, email, password_hash, estado_acceso, privacidad_aceptada_en)
             VALUES (?, 'PLATAFORMA', 'ADMIN', 'Administración', 'Pyme Hub', ?, ?, 'ACTIVO', UTC_TIMESTAMP())",
            [$plataforma, $email, $this->hash]
        );
    }

    private function empresa(string $nombre, string $comercial, string $nif, string $plan, string $ciclo, int $limite): int
    {
        $id = Db::insert(
            "INSERT INTO interlocutor (tipo, nombre, nombre_comercial, nif, sector, email_contacto, fecha_contrato_encargado, estado)
             VALUES ('EMPRESA', ?, ?, ?, 'Logística de última milla', ?, ?, 'PILOTO')",
            [$nombre, $comercial, $nif, 'contacto@' . self::DOMINIO, Tiempo::dia(-120)]
        );
        Db::insert(
            'INSERT INTO suscripcion (interlocutor_id, plan, ciclo_cobro, precio_empleado_mes, limite_empleados, fecha_inicio)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $plan, $ciclo, \PymeHub\Core\Plan::PRECIO[$plan], $limite, Tiempo::dia(-120)]
        );
        return $id;
    }

    private function acceso(int $empresa, string $perfil, string $nombre, string $apellidos, string $email): int
    {
        return Db::insert(
            "INSERT INTO usuario (interlocutor_id, tipo_interlocutor, perfil, nombre, apellidos, email, password_hash, estado_acceso, privacidad_aceptada_en)
             VALUES (?, 'EMPRESA', ?, ?, ?, ?, ?, 'ACTIVO', UTC_TIMESTAMP())",
            [$empresa, $perfil, $nombre, $apellidos, $email, $this->hash]
        );
    }

    /** Equipos y empleados de una empresa. Devuelve ['equipos' => nombre => id, 'empleados' => [...]]. */
    private function plantilla(int $empresa, array $equipos, string $hoy, string $dominioEmpresa): array
    {
        $out = ['equipos' => [], 'empleados' => []];
        foreach ($equipos as $nombre => $cfg) {
            $eq = Db::insert('INSERT INTO equipo (interlocutor_id, nombre, zona) VALUES (?, ?, ?)', [$empresa, $nombre, $cfg['zona']]);
            $out['equipos'][$nombre] = $eq;
            for ($i = 0; $i < $cfg['n']; $i++) {
                $nom = self::NOMBRES[mt_rand(0, count(self::NOMBRES) - 1)];
                $ape = self::APELLIDOS[mt_rand(0, count(self::APELLIDOS) - 1)] . ' ' . self::APELLIDOS[mt_rand(0, count(self::APELLIDOS) - 1)];
                // Antigüedad mixta: ~25 % con menos de 90 días (§18)
                $r = mt_rand(1, 100);
                $dias = $r <= 25 ? mt_rand(10, 89) : ($r <= 55 ? mt_rand(90, 365) : mt_rand(366, 2400));
                $contrato = ['INDEFINIDO', 'INDEFINIDO', 'TEMPORAL', 'ETT'][mt_rand(0, 3)];
                $puesto = $i === 0 && $nombre !== 'Almacén' ? 'COORDINADOR' : $cfg['puestos'][mt_rand(0, count($cfg['puestos']) - 1)];
                $turno = $cfg['turnos'][mt_rand(0, count($cfg['turnos']) - 1)];
                $email = $this->slug($nom) . '.' . $this->slug(explode(' ', $ape)[0]) . '.' . (++$this->seq) . '@' . $dominioEmpresa . '.' . self::DOMINIO;
                $id = $this->empleado($empresa, $eq, $nom, $ape, $email, $puesto, $contrato, $turno,
                    Tiempo::sumarDias($hoy, -$dias), sprintf('EMP-%04d', $this->seq), false);
                $out['empleados'][] = ['id' => $id, 'equipo' => $nombre];
            }
        }
        return $out;
    }

    private function empleado(int $empresa, int $equipo, string $nombre, string $apellidos, string $email, string $puesto,
        string $contrato, string $turno, string $alta, string $codigo, bool $conAcceso): int
    {
        // Todos activos para que puedan hacer check-in; solo los de acceso tienen una contraseña conocida
        $hash = $conAcceso ? $this->hash : password_hash(bin2hex(random_bytes(16)), PASSWORD_ARGON2ID, ['memory_cost' => 8192, 'time_cost' => 1]);
        $id = Db::insert(
            "INSERT INTO usuario (interlocutor_id, tipo_interlocutor, perfil, nombre, apellidos, email, password_hash, estado_acceso, privacidad_aceptada_en)
             VALUES (?, 'EMPRESA', 'EMPLEADO', ?, ?, ?, ?, 'ACTIVO', UTC_TIMESTAMP())",
            [$empresa, $nombre, $apellidos, $email, $hash]
        );
        Db::insert(
            'INSERT INTO ficha_laboral (usuario_id, interlocutor_id, equipo_id, codigo_interno, puesto, tipo_contrato, turno, fecha_alta)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$id, $empresa, $equipo, $codigo, $puesto, $contrato, $turno, $alta]
        );
        return $id;
    }

    /** 60 días de check-ins voluntarios (~65 % de participación). El equipo Nocturno empeora en las últimas 3 semanas. */
    private function checkins(int $empresa, array $plantilla, string $hoy, ?int $conductor): int
    {
        $n = 0;
        $stmt = Db::pdo()->prepare(
            'INSERT INTO checkin_bienestar (interlocutor_id, usuario_id, equipo_id, fecha, animo, energia, estres, nota_cifrada)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $lunesActual = Tiempo::lunes($hoy);
        foreach ($plantilla['empleados'] as $e) {
            $equipoId = $plantilla['equipos'][$e['equipo']];
            $esConductor = $e['id'] === $conductor;
            for ($d = 59; $d >= 0; $d--) {
                $fecha = Tiempo::sumarDias($hoy, -$d);
                if ($esConductor && $d === 0) {
                    continue; // el conductor hace su check-in de hoy en directo durante la demo
                }
                $finde = (int) (new \DateTimeImmutable($fecha))->format('N') >= 6;
                if (mt_rand(1, 100) > ($esConductor ? 80 : ($finde ? 40 : 68))) {
                    continue;
                }
                // Valores base del equipo
                [$a, $en, $es] = [3.9, 3.7, 2.3];
                if ($e['equipo'] === 'Almacén') {
                    [$a, $en, $es] = [3.6, 3.3, 2.6];
                }
                if ($e['equipo'] === 'Nocturno') {
                    [$a, $en, $es] = [3.5, 3.1, 2.6];
                    // Empeora desde hace tres semanas, con un salto en la última semana completa
                    if ($fecha >= Tiempo::sumarDias($lunesActual, -21)) {
                        [$a, $en, $es] = [3.0, 2.7, 3.3];
                    }
                    if ($fecha >= Tiempo::sumarDias($lunesActual, -7)) {
                        [$a, $en, $es] = [2.4, 2.4, 4.1];
                    }
                }
                $animo = $this->escala($a);
                $energia = $this->escala($en);
                $estres = $this->escala($es);
                $nota = null;
                if ($esConductor && mt_rand(1, 100) <= 30) {
                    $nota = Crypto::encrypt(self::NOTAS_CONDUCTOR[mt_rand(0, count(self::NOTAS_CONDUCTOR) - 1)]);
                }
                $stmt->execute([$empresa, $e['id'], $equipoId, $fecha, $animo, $energia, $estres, $nota]);
                $n++;
            }
        }
        return $n;
    }

    /** Valor 1–5 alrededor de una media, con ruido. */
    private function escala(float $media): int
    {
        $v = $media + (mt_rand(-100, 100) / 100) * 1.1;
        return max(1, min(5, (int) round($v)));
    }

    private function slug(string $s): string
    {
        $s = mb_strtolower($s);
        return preg_replace('/[^a-z]/', '', strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']));
    }
}
