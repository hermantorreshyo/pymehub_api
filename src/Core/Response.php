<?php
declare(strict_types=1);

namespace PymeHub\Core;

final class Response
{
    public static function json(mixed $data, int $status = 200, array $meta = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Correlation-Id: ' . Logger::correlationId());
        if ($status === 204) {
            return;
        }
        $body = ['data' => $data];
        if ($meta !== []) {
            $body['meta'] = $meta;
        }
        echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /** Descarga de un archivo generado en memoria (exportaciones, RF-110). */
    public static function file(string $bytes, string $filename, string $mime): void
    {
        http_response_code(200);
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: no-store');
        header('X-Correlation-Id: ' . Logger::correlationId());
        echo $bytes;
    }

    public static function error(string $code, string $message, int $status, array $details = []): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Correlation-Id: ' . Logger::correlationId());
        echo json_encode([
            'error' => [
                'code'           => $code,
                'message'        => $message,
                'details'        => (object) $details,
                'correlation_id' => Logger::correlationId(),
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
