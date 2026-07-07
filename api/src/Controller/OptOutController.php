<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Support\Jwt;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Public one-click opt-out from appointment reminders. The link in each
 * reminder carries a signed token (purpose=optout, pid=personId). This is the
 * reliable alternative to parsing "STOP" replies over carrier SMS gateways.
 */
final class OptOutController
{
    /** GET /optout?token=... */
    public function handle(Request $request, Response $response): Response
    {
        $token = (string) ($request->getQueryParams()['token'] ?? '');
        $claims = $token !== '' ? Jwt::verify($token) : null;

        $ok = false;
        if ($claims !== null && ($claims['purpose'] ?? '') === 'optout' && !empty($claims['pid'])) {
            $stmt = Database::connect()->prepare('UPDATE users SET notify_opt_out = 1 WHERE user_id = ?');
            $stmt->execute([(int) $claims['pid']]);
            $ok = true;
        }

        $msg = $ok
            ? "You're unsubscribed — we won't send you appointment reminders anymore."
            : 'This opt-out link is invalid or has expired.';

        $html = "<!doctype html><html><head><meta name='viewport' content='width=device-width,initial-scale=1'>"
              . "<title>HeadAhhBlendz</title></head><body style='font-family:system-ui;max-width:480px;margin:3rem auto;padding:0 1rem;text-align:center'>"
              . "<h1>✂️ HeadAhhBlendz</h1><p style='font-size:1.1rem'>{$msg}</p></body></html>";

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html')->withStatus($ok ? 200 : 400);
    }
}
