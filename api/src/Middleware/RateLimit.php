<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Service\RateLimiter;
use App\Support\Env;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Psr7\Response as SlimResponse;

/**
 * Fixed-window rate limiter, keyed by action + client IP, backed by the
 * rate_limits table. Applied to public booking endpoints to blunt abuse of
 * the open (Instagram-linked) booking form.
 */
final class RateLimit implements MiddlewareInterface
{
    public function __construct(
        private string $action,
        private int $limit,
        private int $windowSeconds,
    ) {
    }

    public function process(Request $request, Handler $handler): Response
    {
        $ip = $this->clientIp($request);
        $allowed = (new RateLimiter())->allow("{$this->action}:{$ip}", $this->limit, $this->windowSeconds);

        if (!$allowed) {
            return Json::error(new SlimResponse(), 'Too many requests — slow down and try again shortly.', 429);
        }

        return $handler->handle($request);
    }

    /**
     * Trust X-Forwarded-For ONLY behind an explicit trusted proxy; otherwise a
     * client can spoof the header and bypass all limits. Default: REMOTE_ADDR.
     */
    private function clientIp(Request $request): string
    {
        $server = $request->getServerParams();
        $remote = $server['REMOTE_ADDR'] ?? 'unknown';

        if (Env::get('TRUSTED_PROXY') !== '') {
            $fwd = $request->getHeaderLine('X-Forwarded-For');
            if ($fwd !== '') {
                return trim(explode(',', $fwd)[0]);
            }
        }
        return $remote;
    }
}
