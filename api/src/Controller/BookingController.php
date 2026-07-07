<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Service\BotCheck;
use App\Service\Mailer;
use App\Service\Otp;
use App\Service\OtpCooldownException;
use App\Service\RateLimiter;
use App\Service\SlotService;
use App\Support\Env;
use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Anonymous booking flow:
 *   1) POST /book/start  -> validate slot, atomically create a short-lived hold,
 *      send a one-time code to the chosen channel.
 *   2) POST /book/verify -> check the code, confirm the appointment, and
 *      link/create the person record.
 *
 * Anti-abuse: honeypot field + Turnstile (stubbed in dev) here; rate limiting
 * is applied as route middleware.
 */
final class BookingController
{
    private PDO $db;
    private Mailer $mailer;
    private BotCheck $bot;
    private Otp $otp;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->mailer = new Mailer();
        $this->bot = new BotCheck();
        $this->otp = new Otp($this->db, $this->mailer);
    }

    /** POST /book/start */
    public function start(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();

        // Honeypot: real users never fill this hidden field.
        if (trim((string) ($b['website'] ?? '')) !== '') {
            return Json::error($response, 'Invalid submission', 400);
        }

        // Bot score (skipped in dev when no secret configured).
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? null;
        if (!$this->bot->verify($b['turnstile_token'] ?? null, $ip)) {
            return Json::error($response, 'Bot check failed', 403);
        }

        $slotStart = trim((string) ($b['slot_start'] ?? ''));
        $name      = trim((string) ($b['name'] ?? ''));
        $channel   = ($b['channel'] ?? '') === 'email' ? 'email' : 'sms';
        $email     = $this->clean($b['email'] ?? null);
        $phone     = $this->clean($b['phone'] ?? null);
        $carrierId = isset($b['carrier_id']) && $b['carrier_id'] !== '' ? (int) $b['carrier_id'] : null;

        if ($name === '' || $slotStart === '') {
            return Json::error($response, 'name and slot_start are required', 422);
        }
        if ($channel === 'email' && !$email) {
            return Json::error($response, 'email is required for email verification', 422);
        }
        if ($channel === 'sms' && (!$phone || !$carrierId)) {
            return Json::error($response, 'phone and carrier are required for text verification', 422);
        }

        // Where will the code be delivered?
        $gateway = $carrierId ? $this->carrierGateway($carrierId) : null;
        $dest = $this->mailer->addressFor($channel, $email, $phone, $gateway);
        if (!$dest) {
            return Json::error($response, 'Could not determine where to send the code', 422);
        }

        // Per-contact limit: contact is the expensive resource (outbound OTP).
        if (!(new RateLimiter($this->db))->allow('book_contact:' . strtolower($dest), 3, 600)) {
            return Json::error($response, 'Too many codes requested for this contact — try again later.', 429);
        }

        // Slot must still be open.
        $slot = (new SlotService($this->db))->find($slotStart);
        if ($slot === null) {
            return Json::error($response, 'That time is no longer available', 409);
        }

        // Atomically claim the slot with a hold.
        $holdMinutes = Env::int('HOLD_TTL_MINUTES', 10);
        try {
            $this->db->beginTransaction();

            // Lock any rows for this slot; gap lock prevents a concurrent insert.
            $lock = $this->db->prepare(
                'SELECT status, hold_expires_at FROM appointments WHERE slot_start = ? FOR UPDATE'
            );
            $lock->execute([$slot['start']]);
            foreach ($lock->fetchAll() as $row) {
                $live = $row['status'] === 'confirmed'
                    || (in_array($row['status'], ['held', 'pending_verify'], true)
                        && strtotime($row['hold_expires_at']) > time());
                if ($live) {
                    $this->db->rollBack();
                    return Json::error($response, 'That time was just taken', 409);
                }
            }

            $ins = $this->db->prepare(
                "INSERT INTO appointments
                   (slot_start, slot_end, status, hold_expires_at, contact_name,
                    contact_email, contact_phone, contact_carrier_id, notify_channel)
                 VALUES (:start, :end, 'pending_verify',
                    DATE_ADD(NOW(), INTERVAL :mins MINUTE), :name, :email, :phone, :carrier, :channel)"
            );
            $ins->execute([
                'start' => $slot['start'], 'end' => $slot['end'], 'mins' => $holdMinutes,
                'name' => $name, 'email' => $email, 'phone' => $phone,
                'carrier' => $carrierId, 'channel' => $channel,
            ]);
            $holdId = (int) $this->db->lastInsertId();

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Concurrent insert lost the race (deadlock / lock timeout) -> 409.
            $sqlState = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
            if (in_array($sqlState, [1213, 1205], true)) {
                return Json::error($response, 'That time was just taken', 409);
            }
            error_log('[book/start] hold failed: ' . $e->getMessage());
            return Json::error($response, 'Could not hold that slot', 500);
        }

        // Issue + send the one-time code. If the send fails, RELEASE the hold
        // and surface an error — never strand the user on a blocked slot.
        $otpMinutes = Env::int('OTP_TTL_MINUTES', 10);
        try {
            $this->otp->issue('booking', ['appointment_id' => $holdId], $dest, $channel, $dest, $otpMinutes);
        } catch (OtpCooldownException $e) {
            $this->db->prepare("UPDATE appointments SET status = 'cancelled', hold_expires_at = NULL WHERE appointment_id = ?")
                ->execute([$holdId]);
            return Json::error($response, $this->cooldownMessage($e), 429);
        } catch (\Throwable $e) {
            error_log('[book/start] OTP send failed: ' . $e->getMessage());
            $this->db->prepare("UPDATE appointments SET status = 'cancelled', hold_expires_at = NULL WHERE appointment_id = ?")
                ->execute([$holdId]);
            return Json::error($response, "We couldn't send your code — please try again.", 502);
        }

        return Json::write($response, [
            'hold_id'        => $holdId,
            'channel'        => $channel,
            'sent_to'        => $this->mask($dest),
            'hold_expires_in_minutes' => $holdMinutes,
            'code_expires_in_minutes' => $otpMinutes,
        ], 201);
    }

    /** POST /book/verify */
    public function verify(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $holdId = (int) ($b['hold_id'] ?? 0);
        $code   = trim((string) ($b['code'] ?? ''));

        $appt = $this->fetchAppt($holdId);
        if ($appt === null || $appt['status'] !== 'pending_verify') {
            return Json::error($response, 'No pending booking found', 404);
        }
        if (strtotime($appt['hold_expires_at']) <= time()) {
            return Json::error($response, 'This hold expired — please pick a time again', 410);
        }

        $token = $this->otp->latest('booking', 'appointment_id', $holdId);
        if ($token === null) {
            return Json::error($response, 'Code expired — please start over', 410);
        }

        // Atomic attempt-and-check (can't exceed the cap under concurrency).
        $result = $this->otp->consume((int) $token['verification_token_id'], $code);
        if ($result['status'] === 'expired') {
            return Json::error($response, 'Code expired — please start over', 410);
        }
        if ($result['status'] === 'too_many') {
            return Json::error($response, 'Too many attempts — please start over', 429);
        }
        if ($result['status'] === 'wrong') {
            return Json::error($response, "Incorrect code. {$result['remaining']} attempt(s) left.", 422);
        }

        // Success: confirm + link/create the client (a users row).
        $personId = $this->linkOrCreatePerson($appt);

        $this->db->prepare(
            "UPDATE appointments SET status = 'confirmed', hold_expires_at = NULL, user_id = :pid
             WHERE appointment_id = :id"
        )->execute(['pid' => $personId, 'id' => $holdId]);

        return Json::write($response, [
            'status'      => 'confirmed',
            'appointment' => [
                'appointment_id' => $holdId,
                'slot_start' => $appt['slot_start'],
                'slot_end'   => $appt['slot_end'],
            ],
            'user_id'     => $personId,
        ]);
    }

    // ---- helpers ----

    private function linkOrCreatePerson(array $appt): int
    {
        $email = $appt['contact_email'];
        $phone = $appt['contact_phone'];

        // Match an existing (non-merged) client by email, then phone.
        $match = null;
        if ($email) {
            $s = $this->db->prepare('SELECT * FROM users WHERE email = ? AND merged_into_id IS NULL LIMIT 1');
            $s->execute([$email]);
            $match = $s->fetch() ?: null;
        }
        if (!$match && $phone) {
            $s = $this->db->prepare('SELECT * FROM users WHERE phone = ? AND merged_into_id IS NULL LIMIT 1');
            $s->execute([$phone]);
            $match = $s->fetch() ?: null;
        }

        if ($match) {
            // Backfill any missing contact info.
            $this->db->prepare(
                'UPDATE users
                    SET email = COALESCE(email, :email),
                        phone = COALESCE(phone, :phone),
                        carrier_id = COALESCE(carrier_id, :carrier)
                  WHERE user_id = :id'
            )->execute([
                'email' => $email, 'phone' => $phone,
                'carrier' => $appt['contact_carrier_id'], 'id' => $match['user_id'],
            ]);
            return (int) $match['user_id'];
        }

        $this->db->prepare(
            'INSERT INTO users (display_name, email, phone, carrier_id, preferred_channel)
             VALUES (:name, :email, :phone, :carrier, :channel)'
        )->execute([
            'name' => $appt['contact_name'] ?: 'New client',
            'email' => $email, 'phone' => $phone,
            'carrier' => $appt['contact_carrier_id'],
            'channel' => $appt['notify_channel'] ?: 'sms',
        ]);
        return (int) $this->db->lastInsertId();
    }

    private function fetchAppt(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM appointments WHERE appointment_id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    private function carrierGateway(int $carrierId): ?string
    {
        $s = $this->db->prepare('SELECT sms_gateway_domain FROM carriers WHERE carrier_id = ?');
        $s->execute([$carrierId]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string) $v;
    }

    private function clean(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    private function mask(string $dest): string
    {
        if (str_contains($dest, '@')) {
            [$u, $d] = explode('@', $dest, 2);
            $u = strlen($u) <= 2 ? $u[0] . '*' : $u[0] . str_repeat('*', max(1, strlen($u) - 2)) . substr($u, -1);
            return "{$u}@{$d}";
        }
        return $dest;
    }

    private function cooldownMessage(OtpCooldownException $e): string
    {
        return 'Please wait about ' . (int) ceil($e->retryAfterSeconds / 60) . ' minute(s) before requesting another code.';
    }
}
