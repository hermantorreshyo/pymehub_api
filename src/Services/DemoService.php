<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\Crypto;
use PymeHub\Core\Db;
use PymeHub\Core\Tiempo;
use PymeHub\Repositories\ModuloRepository;

/**
 * Datos de demostración para la defensa del TFM (§18, RF-100, LEG-009: solo datos ficticios).
 *
 * - «Reparto Rápido Castilla S.L.»: plan Completo, 48 empleados en 4 equipos (Zona Norte,
 *   Zona Sur, Nocturno, Almacén), con el equipo Nocturno empeorando en estrés.
 * - «Mensajería Tajo S.L.»: plan Entrada, 15 empleados en 2 equipos.
 * - Usuarios de acceso: admin@, gerente@, rrhh@ y conductor@ (Castilla) y gerente2@ (Tajo),
 *   en el dominio ficticio pymehub.demo, todos con la contraseña indicada al generar.
 *
 * Cada fase añade aquí sus datos (fase 4: bienestar; fase 5: incidencias y formación).
 * Es determinista: misma semilla, mismos datos.
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

    /**
     * Perfiles de la demo del asesor (§12.6). cli/ai_warmup.php pregenera sus respuestas;
     * conductor@ tiene precargado el primero.
     */
    public const PERFILES_ASESOR = [
        'Conductor de noche' => [
            'perfil'       => ['puesto' => 'CONDUCTOR', 'antiguedad' => 'MENOS_3_MESES', 'turno' => 'NOCHE'],
            'preferencias' => ['objetivo' => 'SALUD_ENERGIA', 'intereses' => ['CONDUCCION', 'BIENESTAR'], 'formato' => 'TEXTO', 'minutos_semana' => 15, 'comentario' => null],
        ],
        'Repartidor hacia coordinador' => [
            'perfil'       => ['puesto' => 'REPARTIDOR', 'antiguedad' => 'MAS_DE_1_ANO', 'turno' => 'MANANA'],
            'preferencias' => ['objetivo' => 'RESPONSABILIDAD', 'intereses' => [], 'formato' => 'TEXTO', 'minutos_semana' => 30, 'comentario' => 'Me gustaría llegar a coordinar un equipo'],
        ],
        'Mozo de almacén' => [
            'perfil'       => ['puesto' => 'MOZO_ALMACEN', 'antiguedad' => 'DE_3_A_12_MESES', 'turno' => 'ROTATIVO'],
            'preferencias' => ['objetivo' => 'MEJORAR_TRABAJO', 'intereses' => ['ALMACEN'], 'formato' => 'TEXTO', 'minutos_semana' => 5, 'comentario' => null],
        ],
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
            // Preguntas de los módulos propios borrados (sin comprobación de claves no se aplica el ON DELETE CASCADE)
            $pdo->exec('DELETE FROM pregunta_modulo WHERE modulo_id NOT IN (SELECT id FROM modulo_formativo)');
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /** Genera todos los datos. Devuelve un resumen para la consola. */
    public function generar(): array
    {
        // Catálogo global de formación (RF-040) antes que nada: tiene su propia transacción
        CatalogoService::sincronizar();
        mt_srand($this->semilla);
        $hoy = Tiempo::hoy();
        $resumen = [];

        Db::transaction(function () use ($hoy, &$resumen): void {
            $this->admin();

            // --- Reparto Rápido Castilla S.L. (plan Completo) ---
            $castilla = $this->empresa('Reparto Rápido Castilla S.L.', 'Reparto Rápido', self::NIF_CASTILLA, 'COMPLETO', 'MENSUAL', 60);
            $this->acceso($castilla, 'GERENTE', 'Ana', 'García Morales', 'gerente@' . self::DOMINIO);
            $rrhh = $this->acceso($castilla, 'RRHH', 'Lucía', 'Prieto Sanz', 'rrhh@' . self::DOMINIO);
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
            $gerenteTajo = $this->acceso($tajo, 'GERENTE', 'Pedro', 'Navarro Gil', 'gerente2@' . self::DOMINIO);
            $equiposT = [
                'Reparto' => ['zona' => 'Toledo y Talavera', 'n' => 8, 'puestos' => ['REPARTIDOR', 'CONDUCTOR'], 'turnos' => ['MANANA', 'TARDE']],
                'Almacén' => ['zona' => 'Nave de Toledo', 'n' => 7, 'puestos' => ['MOZO_ALMACEN'], 'turnos' => ['MANANA', 'ROTATIVO']],
            ];
            $plantillaT = $this->plantilla($tajo, $equiposT, $hoy, 'mensajeria-tajo');
            $resumen['tajo'] = ['id' => $tajo, 'empleados' => count($plantillaT['empleados'])];

            // --- Fase 4 · Bienestar: 60 días de check-ins ---
            $resumen['checkins'] = $this->checkins($castilla, $plantillaC, $hoy, $conductor)
                + $this->checkins($tajo, $plantillaT, $hoy, null);

            // --- Fase 5 · Incidencias de los últimos 6 meses y formación ---
            $resumen['incidencias'] = $this->incidencias($castilla, $plantillaC, $hoy, $conductor, $rrhh)
                + $this->incidencias($tajo, $plantillaT, $hoy, null, $gerenteTajo);
            $resumen['formacion'] = $this->formacionCastilla($castilla, $plantillaC, $hoy, $conductor, $rrhh)
                + $this->formacionTajo($tajo, $plantillaT, $hoy, $gerenteTajo);

            // --- Fase 6 · Asesor: preferencias (demanda formativa) e historial de consultas sin contenido ---
            $resumen['preferencias'] = $this->asesor($castilla, $plantillaC, $conductor) + $this->asesor($tajo, $plantillaT, null);
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

    /**
     * Seis meses de incidencias (§18): siniestros y quejas concentrados fuera de la Zona Norte
     * (que ya tiene asignada la formación de seguridad vial), para que las recomendaciones por
     * reglas (RF-043) tengan casos que enseñar. Solo ausencias injustificadas (LEG-012).
     */
    private function incidencias(int $empresa, array $plantilla, string $hoy, ?int $conductor, int $autor): int
    {
        $n = 0;
        $porEquipo = [];
        foreach ($plantilla['empleados'] as $e) {
            if ($e['id'] !== $conductor) {
                $porEquipo[$e['equipo']][] = $e['id'];
            }
        }
        $equipos = array_keys($porEquipo);
        $pick = function (array $nombres) use ($porEquipo): int {
            $nombres = array_values(array_filter($nombres, static fn ($x) => isset($porEquipo[$x])));
            $lista = $porEquipo[$nombres[mt_rand(0, count($nombres) - 1)]];
            return $lista[mt_rand(0, count($lista) - 1)];
        };
        $reparto = array_values(array_filter($equipos, static fn ($x) => $x !== 'Almacén' && $x !== 'Zona Norte'));
        $insert = function (int $uid, string $tipo, int $diasAtras, ?string $nota) use ($empresa, $hoy, $autor, &$n): void {
            Db::insert(
                'INSERT INTO incidencia (interlocutor_id, usuario_id, tipo, fecha, nota, registrada_por) VALUES (?, ?, ?, ?, ?, ?)',
                [$empresa, $uid, $tipo, Tiempo::sumarDias($hoy, -$diasAtras), $nota, $autor]
            );
            $n++;
        };
        $grande = count($plantilla['empleados']) > 30;
        // Siniestros recientes (seguridad vial) y antiguos (fuera de la ventana de 90 días)
        foreach (range(1, $grande ? 4 : 2) as $i) {
            $insert($pick($reparto), 'SINIESTRO', mt_rand(5, 80), ['Golpe leve al aparcar en zona de carga.', 'Roce con un vehículo en una rotonda.', 'Retrovisor dañado en calle estrecha.', 'Alcance leve en un atasco.'][$i % 4]);
        }
        $insert($pick($equipos), 'SINIESTRO', mt_rand(120, 170), 'Arañazo en el lateral al maniobrar.');
        // Quejas de cliente: una persona con dos (comunicación con clientes difíciles)
        $repetidor = $pick($reparto);
        $insert($repetidor, 'QUEJA_CLIENTE', mt_rand(10, 40), 'El cliente indica que no se le avisó del retraso.');
        $insert($repetidor, 'QUEJA_CLIENTE', mt_rand(45, 85), 'Trato poco amable en la entrega, según el cliente.');
        foreach (range(1, $grande ? 2 : 1) as $i) {
            $insert($pick($reparto), 'QUEJA_CLIENTE', mt_rand(5, 85), 'Paquete dejado en el portal sin autorización.');
        }
        // Retrasos, ausencias injustificadas y reconocimientos repartidos en 6 meses
        foreach (range(1, $grande ? 14 : 5) as $i) {
            $insert($pick($equipos), 'RETRASO', mt_rand(1, 180), null);
        }
        foreach (range(1, $grande ? 4 : 2) as $i) {
            $insert($pick($equipos), 'AUSENCIA_INJUSTIFICADA', mt_rand(1, 180), null);
        }
        foreach (range(1, $grande ? 8 : 3) as $i) {
            $insert($pick($equipos), 'RECONOCIMIENTO', mt_rand(1, 180), ['Felicitación de un cliente por la entrega.', 'Cubrió una ruta de un compañero.', 'Cero incidencias en el trimestre.'][$i % 3]);
        }
        return $n;
    }

    /**
     * Formación de Castilla: campañas por equipo con distinto grado de avance, autoinscripciones
     * y, para conductor@, un módulo completado, uno en curso y el catálogo por explorar.
     */
    private function formacionCastilla(int $empresa, array $plantilla, string $hoy, int $conductor, int $autor): int
    {
        $n = 0;
        $miembros = [];
        foreach ($plantilla['empleados'] as $e) {
            $miembros[$e['equipo']][] = $e['id'];
        }
        // Zona Norte · Seguridad vial: campaña cerrada hace 10 días; la mayoría la completó
        $n += $this->campana($empresa, $miembros['Zona Norte'], 'SEGURIDAD_VIAL_CIUDAD', $autor, $hoy, -40, -10, 80, 10);
        // Almacén · Manipulación de cargas: en marcha, vence en 2 semanas
        $n += $this->campana($empresa, $miembros['Almacén'], 'MANIPULACION_CARGAS', $autor, $hoy, -12, 14, 50, 30);
        // Zona Sur · Atención al cliente: recién lanzada
        $n += $this->campana($empresa, $miembros['Zona Sur'], 'ATENCION_CLIENTE', $autor, $hoy, -4, 21, 20, 30);
        // Nocturno · Conducción nocturna: vence en una semana (el conductor la tiene a medias)
        $nocturno = array_values(array_filter($miembros['Nocturno'], static fn ($id) => $id !== $conductor));
        $n += $this->campana($empresa, $nocturno, 'CONDUCCION_NOCTURNA', $autor, $hoy, -9, 7, 45, 35);
        $this->asignacion($empresa, $conductor, 'CONDUCCION_NOCTURNA', 'GESTION', $autor, Tiempo::sumarDias($hoy, -9), Tiempo::sumarDias($hoy, 7), 'EN_CURSO', 2, null, null);
        $n++;
        // Módulo propio de la empresa (RF-046), publicado en su catálogo
        $this->moduloPropio($empresa);
        // conductor@: acogida completada y una autoinscripción completada
        $this->asignacion($empresa, $conductor, 'HERRAMIENTAS_DIGITALES', 'GESTION', $autor, Tiempo::sumarDias($hoy, -60), Tiempo::sumarDias($hoy, -46), 'COMPLETADA', 4, 100, Tiempo::sumarDias($hoy, -55));
        $this->asignacion($empresa, $conductor, 'PAUSAS_ACTIVAS', 'AUTOINSCRIPCION', null, Tiempo::sumarDias($hoy, -30), null, 'COMPLETADA', 4, 100, Tiempo::sumarDias($hoy, -29));
        $n += 2;
        // Autoinscripciones voluntarias (RF-047)
        $todos = array_values(array_filter(array_merge(...array_values($miembros)), static fn ($id) => $id !== $conductor));
        $voluntarios = ['PAUSAS_ACTIVAS', 'DESCANSO_TURNOS', 'CONDUCCION_EFICIENTE', 'COORDINADOR_RUTA', 'VEHICULO_ELECTRICO', 'GESTION_ESTRES'];
        foreach (range(1, 14) as $i) {
            $uid = $todos[mt_rand(0, count($todos) - 1)];
            $codigo = $voluntarios[mt_rand(0, count($voluntarios) - 1)];
            if ($this->yaTiene($uid, $codigo)) {
                continue;
            }
            $hecho = mt_rand(1, 100) <= 60;
            $alta = mt_rand(3, 50);
            $this->asignacion($empresa, $uid, $codigo, 'AUTOINSCRIPCION', null, Tiempo::sumarDias($hoy, -$alta), null,
                $hecho ? 'COMPLETADA' : 'EN_CURSO', $hecho ? 4 : mt_rand(1, 3), $hecho ? [70, 100, 100][mt_rand(0, 2)] : null,
                $hecho ? Tiempo::sumarDias($hoy, -mt_rand(1, $alta - 1)) : null);
            $n++;
        }
        return $n;
    }

    private function moduloPropio(int $empresa): void
    {
        $id = Db::insert(
            "INSERT INTO modulo_formativo (interlocutor_id, codigo, titulo, resumen, categoria, nivel, puestos_destino, etiquetas,
                                           duracion_min, contenido, estado)
             VALUES (?, 'PROPIO_COSLADA', ?, ?, 'ALMACEN', 'BASICO', ?, ?, 3, ?, 'PUBLICADO')",
            [
                $empresa,
                'Normas del centro de Coslada',
                'Horarios de muelle, zonas de carga y a quién avisar si algo falla en el centro de distribución.',
                json_encode(['CONDUCTOR', 'REPARTIDOR', 'MOZO_ALMACEN']),
                json_encode(['acogida', 'almacén', 'normas'], JSON_UNESCAPED_UNICODE),
                json_encode([
                    'objetivos' => ['Conocer los horarios y las zonas del centro.', 'Saber a quién avisar ante una incidencia.'],
                    'bloques' => [
                        ['tipo' => 'imagen', 'ilustracion' => 'ALMACEN', 'alt' => 'Muelles de carga del centro de distribución'],
                        ['tipo' => 'texto', 'titulo' => 'Horarios de muelle', 'texto' => 'La carga de las rutas de mañana se hace de 6:00 a 7:30 en los muelles 1 a 4. Las rutas de noche cargan a partir de las 21:00 en los muelles 5 y 6. Fuera de esos horarios, consulta con el jefe de almacén antes de entrar con la furgoneta.'],
                        ['tipo' => 'lista', 'titulo' => 'En el centro', 'items' => ['Chaleco reflectante siempre en la zona de muelles.', 'Velocidad máxima de 10 km/h en el patio.', 'Los pasillos peatonales están marcados en verde.']],
                        ['tipo' => 'consejo', 'texto' => 'Ante cualquier incidencia en el centro, avisa primero al jefe de turno. Su extensión está en el tablón de la entrada.'],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]
        );
        CatalogoService::guardarPreguntas($id, [
            ['enunciado' => '¿Cuál es la velocidad máxima en el patio del centro?', 'opciones' => ['20 km/h', '10 km/h', '30 km/h'], 'correcta' => 1],
            ['enunciado' => '¿A quién avisas primero ante una incidencia en el centro?', 'opciones' => ['Al jefe de turno', 'A un compañero de ruta', 'A nadie si no es grave'], 'correcta' => 0],
        ]);
    }

    private function formacionTajo(int $empresa, array $plantilla, string $hoy, int $autor): int
    {
        $miembros = [];
        foreach ($plantilla['empleados'] as $e) {
            $miembros[$e['equipo']][] = $e['id'];
        }
        return $this->campana($empresa, $miembros['Almacén'], 'MANIPULACION_CARGAS', $autor, $hoy, -20, 5, 55, 25)
            + $this->campana($empresa, $miembros['Reparto'], 'ATENCION_CLIENTE', $autor, $hoy, -6, 24, 25, 25);
    }

    /**
     * Asigna un módulo a un grupo. $pctHecho: % que lo ha completado; $pctCurso: % a medias;
     * el resto, pendiente (o vencido si la fecha límite ya pasó).
     */
    private function campana(int $empresa, array $personas, string $codigo, int $autor, string $hoy, int $desde, int $limite, int $pctHecho, int $pctCurso): int
    {
        foreach ($personas as $uid) {
            $r = mt_rand(1, 100);
            $estado = $r <= $pctHecho ? 'COMPLETADA' : ($r <= $pctHecho + $pctCurso ? 'EN_CURSO' : 'PENDIENTE');
            $creado = Tiempo::sumarDias($hoy, $desde);
            $fin = $estado === 'COMPLETADA'
                ? Tiempo::sumarDias($hoy, $desde + mt_rand(1, max(1, min(-$desde - 1, $limite - $desde))))
                : null;
            if ($fin !== null && $fin > $hoy) {
                $fin = $hoy;
            }
            $this->asignacion($empresa, $uid, $codigo, 'GESTION', $autor, $creado, Tiempo::sumarDias($hoy, $limite), $estado,
                $estado === 'COMPLETADA' ? 4 : ($estado === 'EN_CURSO' ? mt_rand(1, 3) : 0),
                $estado === 'COMPLETADA' ? [70, 100, 100, 100][mt_rand(0, 3)] : null, $fin);
        }
        return count($personas);
    }

    private function asignacion(int $empresa, int $uid, string $codigo, string $origen, ?int $autor, string $creado, ?string $limite,
        string $estado, int $bloque, ?int $nota, ?string $completada): void
    {
        $modulo = ModuloRepository::idPorCodigo($codigo);
        $bloques = 4;
        $pct = $estado === 'COMPLETADA' ? 100 : (int) min(90, round($bloque / $bloques * 90));
        $intentos = $estado === 'COMPLETADA' ? ($nota === 100 ? 1 : mt_rand(1, 2)) : 0;
        Db::insert(
            'INSERT INTO asignacion_formativa (interlocutor_id, usuario_id, modulo_id, origen, asignada_por, fecha_limite, estado,
                                               progreso_pct, bloque_actual, intentos, mejor_nota, ultima_nota, completada_en, creado_en)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$empresa, $uid, $modulo, $origen, $autor, $limite, $estado, $pct, $bloque, $intentos, $nota, $nota,
                $completada === null ? null : $completada . ' 1' . mt_rand(0, 9) . ':' . sprintf('%02d', mt_rand(0, 59)) . ':00',
                $creado . ' 09:00:00']
        );
    }

    private function yaTiene(int $uid, string $codigo): bool
    {
        return Db::value(
            'SELECT 1 FROM asignacion_formativa a JOIN modulo_formativo m ON m.id = a.modulo_id WHERE a.usuario_id = ? AND m.codigo = ?',
            [$uid, $codigo]
        ) !== null;
    }

    /**
     * Preferencias de ~40 % de la plantilla (para la demanda formativa agregada, IA-021) y un
     * historial de consultas atendidas por reglas: no se simula ningún uso real del proveedor de IA.
     */
    private function asesor(int $empresa, array $plantilla, ?int $conductor): int
    {
        $n = 0;
        $objetivos = ['SALUD_ENERGIA', 'SALUD_ENERGIA', 'SEGURIDAD', 'MEJORAR_TRABAJO', 'RESPONSABILIDAD', 'HERRAMIENTAS_DIGITALES'];
        $temas = ['CONDUCCION', 'ALMACEN', 'CLIENTE', 'BIENESTAR', 'DESARROLLO'];
        foreach ($plantilla['empleados'] as $e) {
            if ($e['id'] === $conductor) {
                $p = self::PERFILES_ASESOR['Conductor de noche']['preferencias'];
            } elseif (mt_rand(1, 100) <= 40) {
                $obj = $objetivos[mt_rand(0, count($objetivos) - 1)];
                if ($e['equipo'] === 'Nocturno' && mt_rand(1, 100) <= 60) {
                    $obj = 'SALUD_ENERGIA';
                }
                $mis = [];
                foreach ($temas as $t) {
                    if (mt_rand(1, 100) <= 35) {
                        $mis[] = $t;
                    }
                }
                $p = ['objetivo' => $obj, 'intereses' => $mis, 'formato' => ['TEXTO', 'IMAGENES', 'VIDEO'][mt_rand(0, 2)],
                    'minutos_semana' => [5, 15, 15, 30][mt_rand(0, 3)], 'comentario' => null];
            } else {
                continue;
            }
            Db::insert(
                'INSERT INTO preferencia_formativa (usuario_id, objetivo, intereses, formato, minutos_semana, comentario) VALUES (?, ?, ?, ?, ?, ?)',
                [$e['id'], $p['objetivo'], json_encode($p['intereses']), $p['formato'], $p['minutos_semana'], $p['comentario']]
            );
            $n++;
            // 1–2 consultas en el último mes, atendidas por reglas (sin contenido, IA-012)
            if ($e['id'] !== $conductor) {
                foreach (range(1, mt_rand(1, 2)) as $k) {
                    Db::insert(
                        "INSERT INTO solicitud_ia (interlocutor_id, usuario_id, proveedor, hash_entrada, estado, servido_desde, util, creado_en)
                         VALUES (?, ?, 'reglas', ?, 'OK', 'REGLAS', ?, ?)",
                        [$empresa, $e['id'], hash('sha256', 'demo' . $e['id'] . $k), [null, 1, 1, 0][mt_rand(0, 3)],
                            gmdate('Y-m-d H:i:s', time() - mt_rand(1, 29) * 86400 - mt_rand(0, 36000))]
                    );
                }
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
