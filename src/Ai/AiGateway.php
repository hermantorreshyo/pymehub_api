<?php
declare(strict_types=1);

namespace PymeHub\Ai;

use PymeHub\Core\Config;

/**
 * Pasarela al proveedor de IA generativa (IA-011, IA-012).
 *
 * Proveedor intercambiable por configuración: ai.provider, ai.base_url, ai.model y ai.api_key
 * en config.php. Mistral (elegido, §12.4) y cualquier API compatible con el formato
 * chat/completions de OpenAI usan el mismo adaptador.
 *
 * - cURL de PHP, timeout de 20 s y un reintento ante 429 o 5xx.
 * - Nunca registra la clave, el prompt ni la respuesta (IA-020, RNF-040).
 */
final class AiGateway
{
    private const TIMEOUT = 20;

    /** Proveedores con formato chat/completions compatible con OpenAI. */
    private const COMPATIBLES = ['mistral', 'openai_compatible'];

    public function __construct(private readonly array $cfg) {}

    public static function fromConfig(): self
    {
        $ia = (array) Config::get('ia', []);
        return new self([
            'provider' => (string) ($ia['provider'] ?? 'mistral'),
            'base_url' => rtrim((string) ($ia['base_url'] ?? 'https://api.mistral.ai/v1'), '/'),
            'model'    => (string) ($ia['model'] ?? 'mistral-small-latest'),
            'api_key'  => (string) ($ia['api_key'] ?? ''),
            'mode'     => (string) ($ia['mode'] ?? 'offline'),
        ]);
    }

    public function mode(): string
    {
        $mode = $this->cfg['mode'];
        if (!in_array($mode, ['live', 'cache_first', 'offline'], true) || !$this->configured()) {
            return 'offline';
        }
        return $mode;
    }

    public function configured(): bool
    {
        return $this->cfg['api_key'] !== '' && in_array($this->cfg['provider'], self::COMPATIBLES, true) && function_exists('curl_init');
    }

    public function provider(): string
    {
        return $this->cfg['provider'];
    }

    public function model(): string
    {
        return $this->cfg['model'];
    }

    /**
     * Llamada de chat con salida JSON.
     * @return array{content: string, tokens_entrada: ?int, tokens_salida: ?int, latencia_ms: int}
     * @throws AiException con motivo NO_DISPONIBLE (red, timeout, 5xx, 429 tras el reintento) o RESPUESTA_INVALIDA
     */
    public function chatJson(string $system, string $user): array
    {
        if (!$this->configured()) {
            throw new AiException('NO_DISPONIBLE', 'Proveedor de IA sin configurar');
        }
        $body = json_encode([
            'model'           => $this->cfg['model'],
            'temperature'     => 0.3,
            'max_tokens'      => 700,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $inicio = hrtime(true);
        $intento = 0;
        do {
            $intento++;
            [$status, $raw, $error] = $this->post($this->cfg['base_url'] . '/chat/completions', $body);
            $reintentar = $error !== null || $status === 429 || $status >= 500;
            if ($reintentar && $intento === 1) {
                usleep(800_000);
            }
        } while ($reintentar && $intento < 2);

        if ($error !== null || $status === 429 || $status >= 500 || $status === 0) {
            throw new AiException('NO_DISPONIBLE', 'Proveedor no disponible (HTTP ' . $status . ')');
        }
        if ($status >= 400) {
            // 401/403/400: clave o petición rechazadas; se trata como no disponible para servir el respaldo
            throw new AiException('NO_DISPONIBLE', 'Petición rechazada por el proveedor (HTTP ' . $status . ')');
        }
        $data = json_decode((string) $raw, true);
        $content = $data['choices'][0]['message']['content'] ?? null;
        if (!is_string($content) || $content === '') {
            throw new AiException('RESPUESTA_INVALIDA', 'Respuesta del proveedor sin contenido');
        }
        return [
            'content'        => $content,
            'tokens_entrada' => isset($data['usage']['prompt_tokens']) ? (int) $data['usage']['prompt_tokens'] : null,
            'tokens_salida'  => isset($data['usage']['completion_tokens']) ? (int) $data['usage']['completion_tokens'] : null,
            'latencia_ms'    => (int) ((hrtime(true) - $inicio) / 1_000_000),
        ];
    }

    /** @return array{0: int, 1: ?string, 2: ?string} estado HTTP, cuerpo, error de red */
    private function post(string $url, string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $this->cfg['api_key'],
            ],
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? (curl_error($ch) ?: 'error de red') : null;
        return [$status, $raw === false ? null : (string) $raw, $error];
    }
}
