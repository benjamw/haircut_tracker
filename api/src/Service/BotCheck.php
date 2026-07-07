<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Env;

/**
 * Cloudflare Turnstile verification. If TURNSTILE_SECRET is unset (dev), the
 * check is skipped so local testing isn't blocked. Wire a real key before the
 * booking URL goes public.
 */
final class BotCheck
{
    public function verify(?string $token, ?string $remoteIp): bool
    {
        $secret = Env::get('TURNSTILE_SECRET');
        if ($secret === '') {
            return true; // disabled in dev
        }
        if (!$token) {
            return false;
        }

        $payload = http_build_query([
            'secret'   => $secret,
            'response' => $token,
            'remoteip' => $remoteIp ?? '',
        ]);

        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $payload,
                'timeout'       => 5,
                'ignore_errors' => true,
            ],
        ]);

        $res = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
        if ($res === false) {
            return false;
        }
        $data = json_decode($res, true);
        return (bool) ($data['success'] ?? false);
    }
}
