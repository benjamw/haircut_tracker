<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Minimal HS256 JWT (no external dependency). Used for user sessions.
 */
final class Jwt
{
    public static function issue(array $claims, int $ttlSeconds): string
    {
        $now = time();
        $header  = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = $claims + ['iat' => $now, 'exp' => $now + $ttlSeconds];

        $h = self::b64(json_encode($header));
        $p = self::b64(json_encode($payload));
        $sig = self::b64(hash_hmac('sha256', "{$h}.{$p}", self::secret(), true));

        return "{$h}.{$p}.{$sig}";
    }

    /** @return array<string,mixed>|null decoded claims, or null if invalid/expired */
    public static function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        [$h, $p, $sig] = $parts;

        $expected = self::b64(hash_hmac('sha256', "{$h}.{$p}", self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }

        $payload = json_decode(self::b64d($p), true);
        if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) {
            return null;
        }
        return $payload;
    }

    private static function secret(): string
    {
        return Env::get('AUTH_SECRET', 'dev-secret-change-me');
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function b64d(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/')) ?: '';
    }
}
