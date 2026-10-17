<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * ZIP mínimo en memoria (método «deflate» si zlib está disponible; si no, «store»).
 * Sin dependencias: no exige la extensión zip de PHP en el servidor (RNF-001).
 * Pensado para exportaciones de unos pocos MB (RF-110).
 */
final class Zip
{
    /** @var list<array{name: string, data: string}> */
    private array $files = [];

    public function add(string $name, string $data): self
    {
        $this->files[] = ['name' => $name, 'data' => $data];
        return $this;
    }

    public function build(): string
    {
        $out = '';
        $central = '';
        [$time, $date] = self::dosTime(time());
        foreach ($this->files as $f) {
            $raw = $f['data'];
            $crc = crc32($raw);
            $method = 0;
            $body = $raw;
            if (function_exists('gzdeflate')) {
                $deflated = gzdeflate($raw, 6);
                if ($deflated !== false && strlen($deflated) < strlen($raw)) {
                    $method = 8;
                    $body = $deflated;
                }
            }
            $name = $f['name'];
            $offset = strlen($out);
            // Bit 11: nombres en UTF-8
            $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, $method, $time, $date, $crc, strlen($body), strlen($raw), strlen($name), 0);
            $out .= $local . $name . $body;
            $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, $method, $time, $date, $crc, strlen($body), strlen($raw), strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        }
        $end = pack('VvvvvVVv', 0x06054b50, 0, 0, count($this->files), count($this->files), strlen($central), strlen($out), 0);
        return $out . $central . $end;
    }

    /** @return array{0: int, 1: int} hora y fecha en formato MS-DOS */
    private static function dosTime(int $ts): array
    {
        $d = getdate($ts);
        return [
            ($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2),
            (($d['year'] - 1980) << 9) | ($d['mon'] << 5) | $d['mday'],
        ];
    }

    /** CSV en UTF-8 con BOM (Excel lo abre con acentos), separado por punto y coma. */
    public static function csv(array $rows, ?array $headers = null): string
    {
        $headers ??= $rows === [] ? [] : array_keys($rows[0]);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");
        fputcsv($fh, $headers, ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, array_map(static function ($v) {
                $v = is_array($v) ? json_encode($v, JSON_UNESCAPED_UNICODE) : (string) ($v ?? '');
                // Evita la inyección de fórmulas al abrir el CSV en una hoja de cálculo
                return !is_numeric($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            }, array_values($r)), ';', '"', '');
        }
        rewind($fh);
        $s = stream_get_contents($fh);
        fclose($fh);
        return $s;
    }
}
