<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Validación estricta de entrada (SEC-006).
 * Reglas: required, string, int, bool, email, date, in:A,B, min:n, max:n, nullable
 * Cualquier campo no declarado se rechaza.
 */
final class Validator
{
    public static function validate(array $input, array $rules): array
    {
        $errors = [];
        $out = [];

        foreach (array_keys($input) as $field) {
            if (!array_key_exists($field, $rules)) {
                $errors[$field] = 'Campo no permitido';
            }
        }

        foreach ($rules as $field => $ruleString) {
            $rulesList = explode('|', $ruleString);
            $present = array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '';
            if (!$present) {
                if (in_array('required', $rulesList, true)) {
                    $errors[$field] = 'Obligatorio';
                } elseif (array_key_exists($field, $input)) {
                    $out[$field] = null;
                }
                continue;
            }
            $value = $input[$field];
            foreach ($rulesList as $rule) {
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                $error = self::check($name, $arg, $value);
                if ($error !== null) {
                    $errors[$field] = $error;
                    break;
                }
                if ($name === 'string') {
                    $value = trim((string) $value);
                } elseif ($name === 'int') {
                    $value = (int) $value;
                } elseif ($name === 'email') {
                    $value = mb_strtolower(trim((string) $value));
                }
            }
            if (!isset($errors[$field])) {
                $out[$field] = $value;
            }
        }

        if ($errors !== []) {
            throw ApiException::validation($errors);
        }
        return $out;
    }

    private static function check(string $name, ?string $arg, mixed $v): ?string
    {
        return match ($name) {
            'required', 'nullable' => null,
            'string'  => is_string($v) ? null : 'Debe ser texto',
            'int'     => (is_int($v) || (is_string($v) && ctype_digit($v))) ? null : 'Debe ser un número entero',
            'bool'    => is_bool($v) ? null : 'Debe ser verdadero o falso',
            'array'   => is_array($v) ? null : 'Debe ser un objeto',
            'email'   => (is_string($v) && filter_var(trim($v), FILTER_VALIDATE_EMAIL) && mb_strlen($v) <= 190) ? null : 'Email no válido',
            'date'    => (is_string($v) && ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v)) && $d->format('Y-m-d') === $v) ? null : 'Fecha no válida (AAAA-MM-DD)',
            'in'      => in_array((string) $v, explode(',', (string) $arg), true) ? null : 'Valor no permitido',
            'min'     => is_string($v) ? (mb_strlen(trim($v)) >= (int) $arg ? null : "Mínimo {$arg} caracteres")
                                       : ((int) $v >= (int) $arg ? null : "Mínimo {$arg}"),
            'max'     => is_string($v) ? (mb_strlen(trim($v)) <= (int) $arg ? null : "Máximo {$arg} caracteres")
                                       : ((int) $v <= (int) $arg ? null : "Máximo {$arg}"),
            'nif'     => (is_string($v) && preg_match('/^[A-Z0-9]{8,10}$/', strtoupper(trim($v)))) ? null : 'NIF no válido',
            default   => 'Regla desconocida: ' . $name,
        };
    }
}
