<?php

namespace App\Libraries;

/**
 * JWT HS256 minimalista — sem dependencia externa.
 *
 * Por que nao uma lib: o OSPOS nao tem nenhuma dependencia de JWT e adicionar
 * uma exigiria composer update no ambiente do cliente. HS256 e ~60 linhas de
 * base64url + hash_hmac, e o segredo ja vive no .env como as outras chaves.
 *
 * Formato: header.payload.signature (base64url, sem padding).
 */
class Jwt
{
    private string $secret;

    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? (string) env('JWT_SECRET', '');
    }

    /**
     * Gera um token. $ttl em segundos (default: 12h).
     *
     * @param array<string, mixed> $claims
     */
    public function encode(array $claims, int $ttl = 43200): string
    {
        $now = time();

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = array_merge($claims, [
            'iat' => $now,
            'exp' => $now + $ttl,
        ]);

        $segments = [
            self::b64url(json_encode($header, JSON_UNESCAPED_SLASHES)),
            self::b64url(json_encode($payload, JSON_UNESCAPED_SLASHES)),
        ];

        $segments[] = self::b64url($this->sign(implode('.', $segments)));

        return implode('.', $segments);
    }

    /**
     * Valida assinatura e expiracao. Retorna as claims, ou null se invalido.
     *
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$h, $p, $s] = $parts;

        // assinatura em tempo constante
        $expected = self::b64url($this->sign($h . '.' . $p));
        if (!hash_equals($expected, $s)) {
            return null;
        }

        $payload = json_decode(self::b64url_decode($p), true);
        if (!is_array($payload)) {
            return null;
        }

        // alg confere? (evita alg confusion)
        $header = json_decode(self::b64url_decode($h), true);
        if (($header['alg'] ?? '') !== 'HS256') {
            return null;
        }

        if (($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret, true);
    }

    private static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64url_decode(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
