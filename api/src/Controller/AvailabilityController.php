<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Service\Mailer;
use App\Support\Env;
use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Admin management of bookable availability:
 *   - recurring weekly windows (availability)
 *   - one-off blocks / custom-hours overrides (schedule_exceptions)
 */
final class AvailabilityController
{
    private PDO $db;
    private Mailer $mailer;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->mailer = new Mailer();
    }

    // ---- Recurring weekly windows ----

    /** GET /admin/availability */
    public function listWindows(Request $request, Response $response): Response
    {
        $rows = $this->db->query('SELECT * FROM availability ORDER BY weekday, start_time')->fetchAll();
        return Json::write($response, ['windows' => array_map([$this, 'shapeWindow'], $rows)]);
    }

    /** POST /admin/availability */
    public function createWindow(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $err = $this->validateWindow($b);
        if ($err) {
            return Json::error($response, $err, 422);
        }

        $stmt = $this->db->prepare(
            'INSERT INTO availability (weekday, start_time, end_time, slot_minutes, active)
             VALUES (:wd, :st, :et, :sm, 1)'
        );
        $stmt->execute([
            'wd' => (int) $b['weekday'],
            'st' => $b['start_time'],
            'et' => $b['end_time'],
            'sm' => (int) ($b['slot_minutes'] ?? 60),
        ]);

        return Json::write($response, $this->shapeWindow($this->windowRow((int) $this->db->lastInsertId())), 201);
    }

    /** PATCH /admin/availability/{id} */
    public function updateWindow(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        if ($this->windowRow($id) === null) {
            return Json::error($response, 'Window not found', 404);
        }
        $b = (array) $request->getParsedBody();

        $set = [];
        $params = ['id' => $id];
        foreach (['weekday', 'start_time', 'end_time', 'slot_minutes', 'active'] as $f) {
            if (array_key_exists($f, $b)) {
                $set[] = "$f = :$f";
                $params[$f] = in_array($f, ['weekday', 'slot_minutes', 'active'], true) ? (int) $b[$f] : $b[$f];
            }
        }
        if ($set) {
            $this->db->prepare('UPDATE availability SET ' . implode(', ', $set) . ' WHERE availability_id = :id')->execute($params);
        }
        return Json::write($response, $this->shapeWindow($this->windowRow($id)));
    }

    /** DELETE /admin/availability/{id} */
    public function deleteWindow(Request $request, Response $response, array $args): Response
    {
        $this->db->prepare('DELETE FROM availability WHERE availability_id = ?')->execute([(int) $args['id']]);
        return $response->withStatus(204);
    }

    // ---- Exceptions (blocks / custom days) ----

    /** GET /admin/exceptions */
    public function listExceptions(Request $request, Response $response): Response
    {
        $rows = $this->db->query(
            'SELECT * FROM schedule_exceptions WHERE end_date >= CURDATE() ORDER BY start_date'
        )->fetchAll();
        return Json::write($response, ['exceptions' => array_map([$this, 'shapeException'], $rows)]);
    }

    /** POST /admin/exceptions */
    public function createException(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $kind = ($b['kind'] ?? '') === 'custom' ? 'custom' : 'block';
        $start = (string) ($b['start_date'] ?? '');
        $end   = (string) ($b['end_date'] ?? $start);

        if (!$this->isDate($start) || !$this->isDate($end)) {
            return Json::error($response, 'start_date (and end_date) must be YYYY-MM-DD', 422);
        }
        if ($end < $start) {
            return Json::error($response, 'end_date cannot be before start_date', 422);
        }

        // custom always has hours; block is all-day unless a window is given.
        $allDay = $kind === 'block' ? (int) (empty($b['start_time']) || empty($b['end_time'])) : 0;

        // Blocking time that already has bookings: warn first, then (on confirm)
        // cancel those appointments and notify the affected people.
        $conflicts = [];
        if ($kind === 'block') {
            $conflicts = $this->blockConflicts($start, $end, (bool) $allDay, $b['start_time'] ?? null, $b['end_time'] ?? null);
            if ($conflicts !== [] && empty($b['confirm'])) {
                return Json::write($response, [
                    'needs_confirmation' => true,
                    'conflicts' => array_map(static fn($c) => [
                        'appointment_id' => (int) $c['appointment_id'],
                        'slot_start'     => $c['slot_start'],
                        'who'            => $c['who'],
                        'notify_channel' => $c['notify_channel'],
                    ], $conflicts),
                ], 409);
            }
        }

        $stmt = $this->db->prepare(
            'INSERT INTO schedule_exceptions (kind, start_date, end_date, all_day, start_time, end_time, slot_minutes, note)
             VALUES (:kind, :sd, :ed, :ad, :st, :et, :sm, :note)'
        );
        $stmt->execute([
            'kind' => $kind,
            'sd' => $start,
            'ed' => $end,
            'ad' => $allDay,
            'st' => $b['start_time'] ?? null,
            'et' => $b['end_time'] ?? null,
            'sm' => $kind === 'custom' ? (int) ($b['slot_minutes'] ?? 60) : null,
            'note' => isset($b['note']) ? trim((string) $b['note']) : null,
        ]);
        $newId = (int) $this->db->lastInsertId(); // capture before other queries reset it

        // Cancel + notify any conflicting appointments.
        foreach ($conflicts as $c) {
            $this->db->prepare("UPDATE appointments SET status = 'cancelled', hold_expires_at = NULL WHERE appointment_id = ?")
                ->execute([$c['appointment_id']]);
            $this->notifyCancellation($c);
        }

        $row = $this->db->query('SELECT * FROM schedule_exceptions WHERE schedule_exception_id = ' . $newId)->fetch();
        $out = $this->shapeException($row);
        $out['cancelled_count'] = count($conflicts);
        return Json::write($response, $out, 201);
    }

    /** DELETE /admin/exceptions/{id} */
    public function deleteException(Request $request, Response $response, array $args): Response
    {
        $this->db->prepare('DELETE FROM schedule_exceptions WHERE schedule_exception_id = ?')->execute([(int) $args['id']]);
        return $response->withStatus(204);
    }

    // ---- helpers ----

    private function validateWindow(array $b): ?string
    {
        $wd = $b['weekday'] ?? null;
        if ($wd === null || (int) $wd < 0 || (int) $wd > 6) {
            return 'weekday must be 0 (Sun) .. 6 (Sat)';
        }
        if (empty($b['start_time']) || empty($b['end_time'])) {
            return 'start_time and end_time are required';
        }
        if ($b['end_time'] <= $b['start_time']) {
            return 'end_time must be after start_time';
        }
        return null;
    }

    private function windowRow(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM availability WHERE availability_id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    private function shapeWindow(array $w): array
    {
        return [
            'availability_id' => (int) $w['availability_id'],
            'weekday'      => (int) $w['weekday'],
            'start_time'   => substr($w['start_time'], 0, 5),
            'end_time'     => substr($w['end_time'], 0, 5),
            'slot_minutes' => (int) $w['slot_minutes'],
            'active'       => (bool) $w['active'],
        ];
    }

    private function shapeException(array $e): array
    {
        return [
            'schedule_exception_id' => (int) $e['schedule_exception_id'],
            'kind'         => $e['kind'],
            'start_date'   => $e['start_date'],
            'end_date'     => $e['end_date'],
            'all_day'      => (bool) $e['all_day'],
            'start_time'   => $e['start_time'] ? substr($e['start_time'], 0, 5) : null,
            'end_time'     => $e['end_time'] ? substr($e['end_time'], 0, 5) : null,
            'slot_minutes' => $e['slot_minutes'] !== null ? (int) $e['slot_minutes'] : null,
            'note'         => $e['note'],
        ];
    }

    private function isDate(string $d): bool
    {
        return (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    }

    /** Confirmed appointments that fall inside a proposed block. */
    private function blockConflicts(string $start, string $end, bool $allDay, ?string $st, ?string $et): array
    {
        $sql = "SELECT a.*, COALESCE(p.display_name, a.contact_name) AS who
                FROM appointments a
                LEFT JOIN users p ON p.user_id = a.user_id
                WHERE a.status = 'confirmed'
                  AND DATE(a.slot_start) BETWEEN :sd AND :ed";
        $params = ['sd' => $start, 'ed' => $end];

        if (!$allDay && $st && $et) {
            $sql .= ' AND TIME(a.slot_start) >= :st AND TIME(a.slot_start) < :et';
            $params['st'] = $st;
            $params['et'] = $et;
        }
        $sql .= ' ORDER BY a.slot_start';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Tell an affected person their appointment was cancelled and to rebook. */
    private function notifyCancellation(array $appt): void
    {
        $gateway = null;
        if ($appt['contact_carrier_id']) {
            $g = $this->db->prepare('SELECT sms_gateway_domain FROM carriers WHERE carrier_id = ?');
            $g->execute([$appt['contact_carrier_id']]);
            $gateway = $g->fetchColumn() ?: null;
        }

        $dest = $this->mailer->addressFor(
            $appt['notify_channel'] ?: 'email',
            $appt['contact_email'],
            $appt['contact_phone'],
            $gateway
        );
        if (!$dest) {
            return;
        }

        $name = $appt['contact_name'] ? explode(' ', trim($appt['contact_name']))[0] : 'there';
        $when = date('D M j, g:i A', strtotime($appt['slot_start']));
        $url  = Env::get('PUBLIC_BASE_URL', '');

        $body = "Hi {$name}, your haircut on {$when} was cancelled because the barber is taking time off. "
              . "Sorry about that! Please pick a new time: {$url}";

        try {
            $this->mailer->send($dest, 'Your haircut appointment was cancelled', $body);
        } catch (\Throwable $e) {
            // best-effort
        }
    }
}

