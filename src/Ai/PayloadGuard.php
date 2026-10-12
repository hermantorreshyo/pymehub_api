<?php
declare(strict_types=1);

namespace PymeHub\Ai;

use PymeHub\Core\ApiException;
use PymeHub\Core\Db;

/**
 * PayloadGuard (IA-010, LEG-010): nada que identifique a una persona ni datos de salud
 * sale hacia el proveedor de IA.
 *
 * 1. Lista blanca: el payload se construye solo con los campos de §12.3 (lo hace
 *    AsesorService); aquí se comprueba que no se ha colado ningún otro.
 * 2. El comentario libre se rechaza (PH-AI-004) si contiene un email, un teléfono,
 *    un DNI/NIE, un nombre o apellido de alguien de la empresa, o términos de salud.
 */
final class PayloadGuard
{
    public const CAMPOS_PERFIL = ['puesto', 'antiguedad', 'turno'];
    public const CAMPOS_PREFERENCIAS = ['objetivo', 'intereses', 'formato', 'minutos_semana', 'comentario'];
    public const CAMPOS_MODULO = ['id', 'titulo', 'resumen', 'categoria', 'nivel', 'duracion_min', 'puestos', 'etiquetas'];

    /** Términos de salud: el asesor no los necesita y son categoría especial (RGPD art. 9). */
    private const SALUD = [
        'depresion', 'ansiedad', 'diagnostico', 'medicacion', 'medicamento', 'pastillas', 'baja medica',
        'enfermedad', 'embarazo', 'embarazada', 'lesion', 'hernia', 'operacion', 'terapia', 'psicologo',
        'psiquiatra', 'tratamiento', 'diabetes', 'cancer', 'discapacidad', 'suicid', 'autolesion',
    ];

    /** Comprueba el comentario libre. Devuelve el texto normalizado o null si está vacío. */
    public static function comentario(?string $texto, int $interlocutorId): ?string
    {
        $texto = $texto === null ? '' : trim(preg_replace('/\s+/u', ' ', $texto));
        if ($texto === '') {
            return null;
        }
        $motivos = [];
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $texto)) {
            $motivos[] = 'un email';
        }
        if (preg_match('/(?:\+?\d[\s.-]?){9,}/', $texto)) {
            $motivos[] = 'un teléfono';
        }
        if (preg_match('/\b(?:\d{8}\s?-?[A-Z]|[XYZ]\s?-?\d{7}\s?-?[A-Z])\b/i', $texto)) {
            $motivos[] = 'un DNI o NIE';
        }
        $plano = self::plano($texto);
        foreach (self::SALUD as $t) {
            if (str_contains($plano, $t)) {
                $motivos[] = 'datos de salud';
                break;
            }
        }
        $palabras = array_flip(preg_split('/[^a-z]+/', $plano, -1, PREG_SPLIT_NO_EMPTY));
        foreach (self::nombresEmpresa($interlocutorId) as $nombre) {
            if (isset($palabras[$nombre])) {
                $motivos[] = 'el nombre de una persona';
                break;
            }
        }
        if ($motivos !== []) {
            throw new ApiException('PH-AI-004', 'El comentario incluye ' . implode(', ', array_unique($motivos)) . '. Quítalo y vuelve a enviarlo.', 422, [
                'comentario' => 'No incluyas datos personales ni de salud',
            ]);
        }
        return $texto;
    }

    /** Comprueba que el payload solo lleva los campos permitidos (§12.3). */
    public static function verificar(array $payload): void
    {
        $permitidos = ['perfil' => self::CAMPOS_PERFIL, 'preferencias' => self::CAMPOS_PREFERENCIAS];
        foreach ($payload as $clave => $valor) {
            if (isset($permitidos[$clave])) {
                if (array_diff(array_keys($valor), $permitidos[$clave]) !== []) {
                    throw new \LogicException("PayloadGuard: campo no permitido en $clave");
                }
            } elseif ($clave === 'catalogo') {
                foreach ($valor as $m) {
                    if (array_diff(array_keys($m), self::CAMPOS_MODULO) !== []) {
                        throw new \LogicException('PayloadGuard: campo no permitido en el catálogo');
                    }
                }
            } elseif ($clave === 'ya_hechos') {
                if (array_filter($valor, static fn ($v) => !is_int($v)) !== []) {
                    throw new \LogicException('PayloadGuard: ya_hechos solo admite ids');
                }
            } else {
                throw new \LogicException("PayloadGuard: bloque no permitido $clave");
            }
        }
    }

    /** Nombres y apellidos (palabras de 3 letras o más) de las personas de la empresa. */
    private static function nombresEmpresa(int $interlocutorId): array
    {
        $out = [];
        foreach (Db::all('SELECT nombre, apellidos FROM usuario WHERE interlocutor_id = ? AND eliminado_en IS NULL', [$interlocutorId]) as $u) {
            foreach (preg_split('/[^a-z]+/', self::plano($u['nombre'] . ' ' . ($u['apellidos'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) as $p) {
                if (strlen($p) >= 3 && !in_array($p, ['del', 'las', 'los'], true)) {
                    $out[$p] = $p;
                }
            }
        }
        return array_values($out);
    }

    /** Minúsculas sin tildes. */
    private static function plano(string $s): string
    {
        return strtr(mb_strtolower($s), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    }
}
