<?php
declare(strict_types=1);

namespace PymeHub\Core;

final class Request
{
    public array $params = [];
    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private readonly string $rawBody,
        private readonly array $headers,
        public readonly string $ip
    ) {}

    public static function fromGlobals(): self
    {
        $uri  = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = '/' . trim($uri, '/');
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $_SERVER['CONTENT_LENGTH'];
        }
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            (string) file_get_contents('php://input'),
            $headers,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0')
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Cuerpo JSON como array. Rechaza cuerpos no JSON. */
    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if ($this->rawBody === '') {
            return $this->json = [];
        }
        $ct = $this->header('content-type') ?? '';
        if (!str_contains($ct, 'application/json')) {
            throw new ApiException('PH-VAL-001', 'El cuerpo debe ser application/json', 415);
        }
        $data = json_decode($this->rawBody, true);
        if (!is_array($data)) {
            throw new ApiException('PH-VAL-001', 'JSON mal formado', 400);
        }
        return $this->json = $data;
    }

    /** Cuerpo sin interpretar (importación CSV). */
    public function body(): string
    {
        return $this->rawBody;
    }

    public function param(string $name): string
    {
        return (string) ($this->params[$name] ?? '');
    }

    public function intParam(string $name): int
    {
        $v = $this->param($name);
        if (!ctype_digit($v)) {
            throw ApiException::notFound();
        }
        return (int) $v;
    }
}
