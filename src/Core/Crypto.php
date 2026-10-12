<?php
declare(strict_types=1);

namespace PymeHub\Core;

/**
 * Cifrado simétrico de datos sensibles en reposo con libsodium (LEG-003).
 * Formato almacenado: nonce (24 bytes) + texto cifrado. La clave vive en config.php
 * (crypto.key, base64) y nunca se registra ni sale de la API.
 */
final class Crypto
{
    private static function key(): string
    {
        $key = base64_decode((string) Config::get('crypto.key', ''), true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Clave de cifrado no configurada (crypto.key)');
        }
        return $key;
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return $nonce . sodium_crypto_secretbox($plain, $nonce, self::key());
    }

    /** Devuelve null si el dato no se puede descifrar (clave rotada o dato dañado). */
    public static function decrypt(?string $blob): ?string
    {
        if ($blob === null || strlen($blob) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($blob, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($blob, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? null : $plain;
    }
}
