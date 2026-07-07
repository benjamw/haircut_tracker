<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Database;
use App\Support\Json;
use App\Support\Jwt;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Authenticates a logged-in user via Bearer JWT. On success, attaches the
 * user row as the `user` request attribute. Blocked users are rejected.
 */
final class UserAuth implements MiddlewareInterface
{
    public function process(Request $request, Handler $handler): Response
    {
        $auth = $request->getHeaderLine('Authorization');
        $token = str_starts_with($auth, 'Bearer ') ? substr($auth, 7) : '';

        $claims = $token !== '' ? Jwt::verify($token) : null;
        if ($claims === null || empty($claims['uid'])) {
            return $this->deny('Not signed in');
        }

        $stmt = Database::connect()->prepare('SELECT * FROM users WHERE user_id = ?');
        $stmt->execute([(int) $claims['uid']]);
        $user = $stmt->fetch();

        if ($user === false || $user['status'] !== 'active') {
            return $this->deny('Account unavailable');
        }

        return $handler->handle($request->withAttribute('user', $user));
    }

    private function deny(string $msg): Response
    {
        return Json::error(new SlimResponse(), $msg, 401);
    }
}
