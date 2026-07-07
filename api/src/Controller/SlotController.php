<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\SlotService;
use App\Support\Json;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/** Public, no-PII slot listing for the booking UI. */
final class SlotController
{
    /** GET /slots?days=14 */
    public function list(Request $request, Response $response): Response
    {
        $days = (int) ($request->getQueryParams()['days'] ?? 14);
        $service = new SlotService();
        return Json::write($response, ['days' => $service->availableDays($days)]);
    }

    /** GET /carriers — public list for the SMS carrier dropdown (no PII). */
    public function carriers(Request $request, Response $response): Response
    {
        $rows = \App\Database::connect()
            ->query('SELECT carrier_id, name FROM carriers ORDER BY name')
            ->fetchAll();
        return Json::write($response, ['carriers' => array_map(
            static fn($c) => ['carrier_id' => (int) $c['carrier_id'], 'name' => $c['name']],
            $rows
        )]);
    }
}
