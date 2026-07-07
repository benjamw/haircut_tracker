<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Database;
use App\Support\Env;
use App\Support\Json;
use App\Support\Jwt;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Admin guard: checks the `X-Admin-Token` header against ADMIN_TOKEN.
 *
 * Fails CLOSED — if no token is configured the guard denies every request,
 * unless `ADMIN_AUTH_DISABLED=1` is explicitly set for local dev. (Previously
 * an empty token silently disabled the guard, which is a footgun on a public
 * deployment.) This is still the temporary token scheme; passkeys replace it.
 */
final class AdminAuth implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        // Primary: a logged-in account with role=admin (Bearer JWT).
        if ($this->isAdminJwt($request)) {
            return $handler->handle($request);
        }

        // Break-glass: the shared admin token (fail-closed if unset).
        $expected = Env::get('ADMIN_TOKEN');
        if ($expected !== '' && hash_equals($expected, $request->getHeaderLine('X-Admin-Token'))) {
            return $handler->handle($request);
        }

        // Dev-only escape hatch when nothing is configured.
        if ($expected === '' && Env::get('ADMIN_AUTH_DISABLED') === '1') {
            return $handler->handle($request);
        }

        return Json::error(new SlimResponse(), 'Unauthorized (admin)', 401);
    }

    private function isAdminJwt(Request $request): bool
    {
        $auth = $request->getHeaderLine('Authorization');
        if (!str_starts_with($auth, 'Bearer ')) {
            return false;
        }
        $claims = Jwt::verify(substr($auth, 7));
        if ($claims === null || empty($claims['uid'])) {
            return false;
        }
        $stmt = Database::connect()->prepare('SELECT role, status FROM users WHERE user_id = ?');
        $stmt->execute([(int) $claims['uid']]);
        $u = $stmt->fetch();
        return $u !== false && $u['status'] === 'active' && $u['role'] === 'admin';
    }
}
