<?php
declare(strict_types=1);

namespace PymeHub\Services;

use PymeHub\Core\ApiException;
use PymeHub\Core\Catalogo;
use PymeHub\Core\Db;
use PymeHub\Repositories\EmpleadoRepository;
use PymeHub\Repositories\EquipoRepository;
use PymeHub\Repositories\UsuarioRepository;

/**
 * RF-020 · Importación de la plantilla por CSV.
 *
 * - UTF-8 (con o sin BOM), separador ";" o ",", primera fila de cabecera.
 * - Máximo 1 MB y 250 filas de datos.
 * - Todo o nada: con una sola fila errónea no se escribe nada (PH-VAL-002).
 * - codigo_interno existente = cambio de la ficha; nuevo = alta en SIN_ACCESO.
 * - Los equipos deben existir; no se crean solos. No genera invitaciones.
 *
 * "fila" es el número de registro del archivo contando la cabecera como 1,
 * igual que la numeración de filas de Excel.
 */
final class ImportacionEmpleadosService
{
    public const MAX_BYTES    = 1048576;
    public const MAX_FILAS    = 250;
    public const COLUMNAS     = ['codigo_interno', 'nombre', 'apellidos', 'email', 'puesto', 'tipo_contrato', 'turno', 'fecha_alta', 'equipo'];
    public const OBLIGATORIAS = ['codigo_interno', 'nombre', 'puesto', 'tipo_contrato', 'turno', 'fecha_alta'];

    /** Importa o, con $simular, solo valida y devuelve el resumen sin escribir. */
    public static function importar(string $raw, int $interlocutorId, bool $simular): array
    {
        $leido = self::leer($raw);
        // Las filas con formato correcto se contrastan con la base de datos para devolver todos los errores a la vez
        [$plan, $erroresBd] = self::resolver(array_filter($leido['filas'], static fn ($f) => $f['valida']), $interlocutorId);
        $errores = array_merge($leido['errores'], $erroresBd);
        usort($errores, static fn ($a, $b) => ($a['fila'] ?? 0) <=> ($b['fila'] ?? 0));

        $resumen = [
            'simulado'    => $simular,
            'valido'      => $errores === [],
            'filas'       => count($leido['filas']),
            'altas'       => count($plan['altas']),
            'cambios'     => count($plan['cambios']),
            'sin_cambios' => $plan['sin_cambios'],
            'errores'     => $errores,
        ];

        if ($errores !== []) {
            if ($simular) {
                return $resumen;
            }
            throw new ApiException('PH-VAL-002', 'El CSV tiene filas erróneas: no se ha importado ninguna', 422, ['errores' => $errores]);
        }
        if ($simular) {
            EmpleadoService::comprobarLimite($interlocutorId, count($plan['altas']));
            return $resumen;
        }

        try {
            Db::transaction(static function () use ($plan, $interlocutorId): void {
                EmpleadoService::comprobarLimite($interlocutorId, count($plan['altas']));
                foreach ($plan['altas'] as $d) {
                    EmpleadoRepository::create($interlocutorId, $d);
                }
                foreach ($plan['cambios'] as [$id, $cambios]) {
                    EmpleadoRepository::update($id, $interlocutorId, $cambios);
                }
            });
        } catch (\PDOException $e) {
            // Clave única violada por un cambio simultáneo entre la validación y la escritura
            if ((string) $e->getCode() === '23000') {
                throw new ApiException('PH-VAL-002', 'El CSV tiene filas erróneas: no se ha importado ninguna', 422, ['errores' => [
                    self::error(null, null, 'La plantilla ha cambiado mientras se importaba. Vuelve a intentarlo.'),
                ]]);
            }
            throw $e;
        }
        return $resumen;
    }

    /**
     * Fase 1, sin base de datos: decodifica el archivo y valida el formato.
     *
     * @return array{filas: list<array{fila: int, datos: array<string, ?string>, valida: bool}>, errores: list<array{fila: ?int, columna: ?string, motivo: string}>}
     */
    public static function leer(string $raw): array
    {
        if (strlen($raw) > self::MAX_BYTES) {
            return self::fallo('El archivo supera el máximo de 1 MB');
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }
        if (trim($raw) === '') {
            return self::fallo('El archivo está vacío');
        }
        if (!mb_check_encoding($raw, 'UTF-8')) {
            return self::fallo('El archivo no está en UTF-8. En Excel, usa «Guardar como» y elige «CSV UTF-8».');
        }

        $eol = strpos($raw, "\n");
        $primera = $eol === false ? $raw : substr($raw, 0, $eol);
        $sep = substr_count($primera, ';') >= substr_count($primera, ',') && str_contains($primera, ';') ? ';' : ',';

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $raw);
        rewind($fh);

        $cabecera = null;
        $filas = [];
        $errores = [];
        $vistos = ['codigo_interno' => [], 'email' => []];
        $n = 0;
        // Escape '' = RFC 4180 (comillas dobladas) y sin aviso de obsolescencia en PHP 8.4
        while (($celdas = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
            $n++;
            $celdas = array_map(static fn ($c): string => trim((string) $c), $celdas);

            if ($cabecera === null) {
                [$cabecera, $errores] = self::cabecera($celdas);
                if ($errores !== []) {
                    break;
                }
                continue;
            }
            if (implode('', $celdas) === '') {
                continue; // filas vacías, incluidas las ";;;;;" que deja Excel
            }
            if (count($filas) >= self::MAX_FILAS) {
                fclose($fh);
                return self::fallo('El archivo supera el máximo de ' . self::MAX_FILAS . ' filas de datos');
            }

            $datos = [];
            foreach (self::COLUMNAS as $col) {
                $datos[$col] = isset($cabecera[$col]) ? ($celdas[$cabecera[$col]] ?? '') : '';
            }
            [$datos, $erroresFila] = self::validarFila($n, $datos);
            foreach ($celdas as $i => $v) {
                if ($v !== '' && !in_array($i, $cabecera, true)) {
                    $erroresFila[] = self::error($n, null, 'Hay valores fuera de las columnas de la cabecera');
                    break;
                }
            }

            // Duplicados dentro del propio archivo
            foreach (['codigo_interno', 'email'] as $col) {
                if ($datos[$col] === null || $datos[$col] === '') {
                    continue;
                }
                $k = mb_strtolower($datos[$col]);
                if (isset($vistos[$col][$k])) {
                    $erroresFila[] = self::error($n, $col, 'Repetido en la fila ' . $vistos[$col][$k]);
                } else {
                    $vistos[$col][$k] = $n;
                }
            }
            array_push($errores, ...$erroresFila);
            $filas[] = ['fila' => $n, 'datos' => $datos, 'valida' => $erroresFila === []];
        }
        fclose($fh);

        if ($errores === [] && $filas === []) {
            return self::fallo('El archivo no contiene filas de empleados');
        }
        return ['filas' => $filas, 'errores' => $errores];
    }

    /** Valor de un enumerado admitiendo minúsculas, tildes y espacios: "Mozo almacén" → MOZO_ALMACEN. */
    public static function enumerado(string $v, array $permitidos): ?string
    {
        $n = strtr(mb_strtoupper(trim($v)), ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $n = (string) preg_replace('/[\s\-]+/u', '_', $n);
        return in_array($n, $permitidos, true) ? $n : null;
    }

    /** AAAA-MM-DD o DD/MM/AAAA → AAAA-MM-DD; null si no es una fecha real. */
    public static function fecha(string $v): ?string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            [$y, $mo, $d] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $v, $m)) {
            [$d, $mo, $y] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } else {
            return null;
        }
        return ($y >= 1900 && checkdate($mo, $d, $y)) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Fase 2, con base de datos: equipos, códigos y emails existentes.
     *
     * @return array{0: array{altas: list<array>, cambios: list<array{0: int, 1: array}>, sin_cambios: int}, 1: list<array>}
     */
    private static function resolver(array $filas, int $interlocutorId): array
    {
        $plan = ['altas' => [], 'cambios' => [], 'sin_cambios' => 0];
        if ($filas === []) {
            return [$plan, []];
        }
        $equipos = EquipoRepository::mapaPorNombre($interlocutorId);
        $existentes = [];
        foreach (EmpleadoRepository::porCodigo($interlocutorId) as $r) {
            $existentes[mb_strtolower((string) $r['codigo_interno'])] = $r;
        }
        $emails = array_values(array_filter(array_map(static fn ($f) => $f['datos']['email'], $filas)));
        $duenos = UsuarioRepository::emailOwners($emails);

        $errores = [];
        foreach ($filas as ['fila' => $n, 'datos' => $d]) {
            $antes = count($errores);
            $equipoId = null;
            if ($d['equipo'] !== null) {
                $equipoId = $equipos[EquipoRepository::clave($d['equipo'])] ?? null;
                if ($equipoId === null) {
                    $errores[] = self::error($n, 'equipo', 'El equipo «' . $d['equipo'] . '» no existe. Créalo antes de importar.');
                }
            }
            $actual = $existentes[mb_strtolower($d['codigo_interno'])] ?? null;
            if ($d['email'] !== null) {
                $dueno = $duenos[$d['email']] ?? null;
                if ($dueno !== null && $dueno !== ($actual === null ? null : (int) $actual['id'])) {
                    $errores[] = self::error($n, 'email', 'Ese email ya tiene una cuenta');
                }
            }

            if ($actual === null) {
                if (count($errores) === $antes) {
                    $plan['altas'][] = [
                        'codigo_interno' => $d['codigo_interno'], 'nombre' => $d['nombre'], 'apellidos' => $d['apellidos'] ?? '',
                        'email' => $d['email'], 'puesto' => $d['puesto'], 'tipo_contrato' => $d['tipo_contrato'],
                        'turno' => $d['turno'], 'fecha_alta' => $d['fecha_alta'], 'equipo_id' => $equipoId,
                    ];
                }
                continue;
            }
            if ($actual['eliminado_en'] !== null) {
                $errores[] = self::error($n, 'codigo_interno', 'El código pertenece a un empleado suprimido');
                continue;
            }
            if ($actual['fecha_baja'] !== null) {
                $errores[] = self::error($n, 'codigo_interno', 'El empleado está dado de baja; no se modifica por CSV');
                continue;
            }

            // Cambio: solo lo que difiere; las columnas opcionales vacías no borran nada
            $cambios = [];
            foreach (['nombre', 'puesto', 'tipo_contrato', 'turno', 'fecha_alta'] as $c) {
                if ($d[$c] !== (string) $actual[$c]) {
                    $cambios[$c] = $d[$c];
                }
            }
            if ($d['apellidos'] !== null && $d['apellidos'] !== $actual['apellidos']) {
                $cambios['apellidos'] = $d['apellidos'];
            }
            if ($d['email'] !== null && $d['email'] !== $actual['email']) {
                if (!EmpleadoService::emailEditable((string) $actual['estado_acceso'])) {
                    $errores[] = self::error($n, 'email', 'No se puede cambiar el email de un empleado con la cuenta activa');
                }
                $cambios['email'] = $d['email'];
            }
            if ($equipoId !== null && $equipoId !== ($actual['equipo_id'] === null ? null : (int) $actual['equipo_id'])) {
                $cambios['equipo_id'] = $equipoId;
            }
            if (count($errores) > $antes) {
                continue;
            }
            if ($cambios === []) {
                $plan['sin_cambios']++;
            } else {
                $plan['cambios'][] = [(int) $actual['id'], $cambios];
            }
        }
        return [$plan, $errores];
    }

    /** @return array{0: array<string, int>, 1: list<array>} índice de cada columna conocida y errores */
    private static function cabecera(array $celdas): array
    {
        $map = [];
        $errores = [];
        foreach ($celdas as $i => $nombre) {
            if ($nombre === '') {
                continue;
            }
            $col = strtr(mb_strtolower($nombre), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', ' ' => '_']);
            if (!in_array($col, self::COLUMNAS, true)) {
                $errores[] = self::error(1, $nombre, 'Columna no reconocida. Columnas admitidas: ' . implode(', ', self::COLUMNAS));
            } elseif (isset($map[$col])) {
                $errores[] = self::error(1, $col, 'Columna repetida');
            } else {
                $map[$col] = $i;
            }
        }
        foreach (self::OBLIGATORIAS as $col) {
            if (!isset($map[$col])) {
                $errores[] = self::error(1, $col, 'Falta la columna obligatoria');
            }
        }
        return [$map, $errores];
    }

    /** @return array{0: array<string, ?string>, 1: list<array>} datos normalizados y errores */
    private static function validarFila(int $n, array $d): array
    {
        $errores = [];
        foreach (self::OBLIGATORIAS as $col) {
            if ($d[$col] === '') {
                $errores[$col] = 'Obligatorio';
            }
        }
        $largo = ['codigo_interno' => 30, 'nombre' => 80, 'apellidos' => 120, 'equipo' => 80];
        foreach ($largo as $col => $max) {
            if (!isset($errores[$col]) && mb_strlen($d[$col]) > $max) {
                $errores[$col] = "Máximo $max caracteres";
            }
        }
        if (!isset($errores['nombre']) && mb_strlen($d['nombre']) < 2) {
            $errores['nombre'] = 'Mínimo 2 caracteres';
        }
        if ($d['email'] !== '') {
            $d['email'] = mb_strtolower($d['email']);
            if (!filter_var($d['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($d['email']) > 190) {
                $errores['email'] = 'Email no válido';
            }
        }
        foreach (['puesto' => Catalogo::PUESTOS, 'tipo_contrato' => Catalogo::TIPOS_CONTRATO, 'turno' => Catalogo::TURNOS] as $col => $permitidos) {
            if (isset($errores[$col])) {
                continue;
            }
            $v = self::enumerado($d[$col], $permitidos);
            if ($v === null) {
                $errores[$col] = 'Valor «' . $d[$col] . '» no permitido. Usa: ' . implode(', ', $permitidos);
            } else {
                $d[$col] = $v;
            }
        }
        if (!isset($errores['fecha_alta'])) {
            $f = self::fecha($d['fecha_alta']);
            if ($f === null) {
                $errores['fecha_alta'] = 'Fecha no válida (AAAA-MM-DD o DD/MM/AAAA)';
            } else {
                $d['fecha_alta'] = $f;
            }
        }
        foreach (['apellidos', 'email', 'equipo'] as $col) {
            if ($d[$col] === '') {
                $d[$col] = null;
            }
        }

        $lista = [];
        foreach (self::COLUMNAS as $col) {
            if (isset($errores[$col])) {
                $lista[] = self::error($n, $col, $errores[$col]);
            }
        }
        return [$d, $lista];
    }

    private static function error(?int $fila, ?string $columna, string $motivo): array
    {
        return ['fila' => $fila, 'columna' => $columna, 'motivo' => $motivo];
    }

    private static function fallo(string $motivo): array
    {
        return ['filas' => [], 'errores' => [self::error(null, null, $motivo)]];
    }
}
