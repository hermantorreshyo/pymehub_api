<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Parámetros de la plataforma (tabla `parametro`, migración 0008), editables desde el panel de
 * administración sin desplegar: datos del titular (pendientes de la constitución de la sociedad),
 * precios sin IVA, IVA, descuento anual, prueba, gracia, borrado tras la baja, comisión del
 * marketplace y versión de los textos legales.
 */
final class Parametros
{
    /** Definición: tipo, grupo, etiqueta y límites. El orden es el del panel. */
    public const DEF = [
        'titular.constituida'          => ['bool', 'titular', 'La sociedad está constituida'],
        'titular.razon_social'         => ['string', 'titular', 'Razón social', 150],
        'titular.nif'                  => ['string', 'titular', 'NIF', 20],
        'titular.domicilio'            => ['string', 'titular', 'Domicilio social', 250],
        'titular.registro_mercantil'   => ['string', 'titular', 'Datos registrales', 250],
        'titular.email'                => ['email', 'titular', 'Correo de contacto'],
        'titular.telefono'             => ['string', 'titular', 'Teléfono', 30],
        'titular.email_privacidad'     => ['email', 'titular', 'Correo para protección de datos'],
        'precios.entrada_mes'          => ['money', 'precios', 'Plan Entrada: € por empleado y mes (sin IVA)', 0, 1000],
        'precios.completo_mes'         => ['money', 'precios', 'Plan Completo: € por empleado y mes (sin IVA)', 0, 1000],
        'precios.iva_pct'              => ['money', 'precios', 'IVA (%)', 0, 30],
        'precios.descuento_anual_pct'  => ['money', 'precios', 'Descuento del pago anual (%)', 0, 50],
        'suscripcion.dias_prueba'      => ['int', 'suscripcion', 'Días de prueba gratuita', 0, 90],
        'suscripcion.dias_gracia'      => ['int', 'suscripcion', 'Días de gracia tras el vencimiento o un impago', 0, 30],
        'operacion.dias_borrado_tras_baja' => ['int', 'suscripcion', 'Días hasta el borrado definitivo tras la baja', 1, 365],
        'marketplace.comision_pct'     => ['money', 'marketplace', 'Comisión de Pyme Hub en Skills (%)', 0, 50],
        'marketplace.formacion_exenta_iva' => ['bool', 'marketplace', 'La formación de Skills está exenta de IVA (confirmar con la gestoría)'],
        'legal.version'                => ['string', 'legal', 'Versión vigente de los textos legales', 20],
    ];

    /** Lo que puede leer cualquier visitante (precios y titular para la web y los textos legales). */
    private const PUBLICOS = ['titular.', 'precios.', 'suscripcion.dias_prueba', 'marketplace.comision_pct', 'marketplace.formacion_exenta_iva', 'legal.version', 'operacion.dias_borrado_tras_baja'];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = [];
            try {
                foreach (Db::all('SELECT clave, valor FROM parametro') as $r) {
                    self::$cache[$r['clave']] = json_decode((string) $r['valor'], true);
                }
            } catch (\PDOException) {
                // Antes de la migración 0008: valores por defecto
            }
        }
        return self::$cache;
    }

    public static function get(string $clave, mixed $default = null): mixed
    {
        return self::all()[$clave] ?? $default;
    }

    public static function float(string $clave, float $default): float
    {
        $v = self::get($clave, $default);
        return is_numeric($v) ? (float) $v : $default;
    }

    public static function int(string $clave, int $default): int
    {
        $v = self::get($clave, $default);
        return is_numeric($v) ? (int) $v : $default;
    }

    public static function publicos(): array
    {
        $out = [];
        foreach (self::DEF as $k => $_) {
            foreach (self::PUBLICOS as $p) {
                if ($k === $p || (str_ends_with($p, '.') && str_starts_with($k, $p))) {
                    $out[$k] = self::get($k);
                }
            }
        }
        return $out;
    }

    /**
     * Valida y guarda un conjunto parcial de parámetros.
     * @return array<string, string> errores por clave
     */
    public static function guardar(array $cambios, int $usuarioId): array
    {
        $errores = [];
        $limpios = [];
        foreach ($cambios as $k => $v) {
            $d = self::DEF[$k] ?? null;
            if ($d === null) {
                $errores[$k] = 'Parámetro desconocido';
                continue;
            }
            [$tipo] = $d;
            $ok = match ($tipo) {
                'bool'   => is_bool($v),
                'int'    => is_int($v) && $v >= $d[3] && $v <= $d[4],
                'money'  => (is_int($v) || is_float($v)) && $v >= $d[3] && $v <= $d[4],
                'email'  => is_string($v) && ($v === '' || filter_var($v, FILTER_VALIDATE_EMAIL)),
                'string' => is_string($v) && mb_strlen($v) <= $d[3],
                default  => false,
            };
            if (!$ok) {
                $errores[$k] = match ($tipo) {
                    'bool'  => 'Debe ser sí o no',
                    'int', 'money' => "Entre {$d[3]} y {$d[4]}",
                    'email' => 'Correo no válido',
                    default => "Máximo {$d[3]} caracteres",
                };
                continue;
            }
            $limpios[$k] = $tipo === 'money' ? round((float) $v, 2) : (is_string($v) ? trim($v) : $v);
        }
        if ($errores !== []) {
            return $errores;
        }
        foreach ($limpios as $k => $v) {
            Db::run(
                'INSERT INTO parametro (clave, valor, actualizado_por) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE valor = ?, actualizado_por = ?',
                [$k, $j = json_encode($v, JSON_UNESCAPED_UNICODE), $usuarioId, $j, $usuarioId]
            );
        }
        self::$cache = null;
        return [];
    }

    /* ------------------------------------------------------------------ Precios */

    /** Precio por empleado y mes, sin IVA. */
    public static function precioMes(string $plan): float
    {
        return $plan === 'COMPLETO' ? self::float('precios.completo_mes', 29.0) : self::float('precios.entrada_mes', 15.0);
    }

    public static function iva(): float
    {
        return self::float('precios.iva_pct', 21.0);
    }

    /**
     * Importe de un periodo de suscripción: base, descuento, IVA y total (con dos decimales).
     * Anual = 12 meses menos el descuento anual parametrizado.
     */
    public static function importe(string $plan, string $ciclo, int $empleados): array
    {
        $precio = self::precioMes($plan);
        $meses = $ciclo === 'ANUAL' ? 12 : 1;
        $bruto = round($precio * $meses * $empleados, 2);
        $descPct = $ciclo === 'ANUAL' ? self::float('precios.descuento_anual_pct', 0.0) : 0.0;
        $descuento = round($bruto * $descPct / 100, 2);
        $base = round($bruto - $descuento, 2);
        $iva = round($base * self::iva() / 100, 2);
        return [
            'plan' => $plan, 'ciclo' => $ciclo, 'empleados' => $empleados, 'precio_empleado_mes' => $precio, 'meses' => $meses,
            'importe_bruto' => $bruto, 'descuento_pct' => $descPct, 'descuento' => $descuento,
            'base' => $base, 'iva_pct' => self::iva(), 'iva' => $iva, 'total' => round($base + $iva, 2), 'moneda' => 'EUR',
        ];
    }
}
