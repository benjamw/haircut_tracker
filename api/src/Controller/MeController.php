<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Service\Cadence;
use App\Service\SlotService;
use App\Support\Json;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Logged-in user ("me") endpoints. A user sees their own profile + history
 * (dates/cadence, NO amounts) and can book instantly (no OTP verification).
 */
final class MeController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    /** GET /me — profile, own history (no amounts), next upcoming appointment. */
    public function me(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $person = $this->person((int) $user['user_id']);

        $stmt = $this->db->prepare(
            'SELECT haircut_date, haircut_time, notes FROM haircuts
             WHERE user_id = ? ORDER BY haircut_date DESC'
        );
        $stmt->execute([$person['user_id']]);
        $haircuts = $stmt->fetchAll();

        $dates = array_map(static fn($h) => $h['haircut_date'], $haircuts);
        $override = $person['usual_cadence_days'] !== null ? (int) $person['usual_cadence_days'] : null;

        $hasPasskey = (bool) (function () use ($user) {
            $s = $this->db->prepare('SELECT 1 FROM credentials WHERE user_id = ? LIMIT 1');
            $s->execute([(int) $user['user_id']]);
            return $s->fetchColumn();
        })();

        return Json::write($response, [
            'user' => ['user_id' => (int) $user['user_id'], 'username' => $user['username'], 'role' => $user['role'], 'has_passkey' => $hasPasskey],
            'person' => [
                'user_id' => (int) $person['user_id'],
                'display_name' => $person['display_name'],
                'email' => $person['email'],
                'phone' => $person['phone'],
                'carrier_id' => $person['carrier_id'] !== null ? (int) $person['carrier_id'] : null,
                'preferred_channel' => $person['preferred_channel'],
                'email_verified' => $person['email_verified_at'] !== null,
                'phone_verified' => $person['phone_verified_at'] !== null,
                'preferred_verified' => $this->preferredVerified($person),
            ],
            'stats' => Cadence::compute($dates, $override),   // no amounts
            'next_appointment' => $this->nextAppointment((int) $person['user_id']),
            // history without amounts
            'haircuts' => array_map(static fn($h) => [
                'haircut_date' => $h['haircut_date'],
                'haircut_time' => $h['haircut_time'],
                'notes' => $h['notes'],
            ], $haircuts),
        ]);
    }

    /** POST /me/book { slot_start } — instant booking, no verification. */
    public function book(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $person = $this->person((int) $user['user_id']);

        // Must have a verified preferred contact before self-scheduling.
        if (!$this->preferredVerified($person)) {
            return Json::write($response, [
                'error' => 'Please verify your ' . ($person['preferred_channel'] === 'email' ? 'email' : 'phone') . ' before booking.',
                'needs_verification' => true,
                'channel' => $person['preferred_channel'],
            ], 403);
        }

        $slotStart = trim((string) (((array) $request->getParsedBody())['slot_start'] ?? ''));
        $slot = (new SlotService($this->db))->find($slotStart);
        if ($slot === null) {
            return Json::error($response, 'That time is no longer available', 409);
        }

        try {
            $this->db->beginTransaction();

            $lock = $this->db->prepare('SELECT status, hold_expires_at FROM appointments WHERE slot_start = ? FOR UPDATE');
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

            $this->db->prepare(
                "INSERT INTO appointments
                   (user_id, slot_start, slot_end, status, contact_name, contact_email,
                    contact_phone, contact_carrier_id, notify_channel)
                 VALUES (:pid,:start,:end,'confirmed',:name,:email,:phone,:carrier,:channel)"
            )->execute([
                'pid' => $person['user_id'], 'start' => $slot['start'], 'end' => $slot['end'],
                'name' => $person['display_name'], 'email' => $person['email'],
                'phone' => $person['phone'], 'carrier' => $person['carrier_id'],
                'channel' => $person['preferred_channel'],
            ]);
            $id = (int) $this->db->lastInsertId();

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $sqlState = $e instanceof \PDOException ? (int) ($e->errorInfo[1] ?? 0) : 0;
            if (in_array($sqlState, [1213, 1205], true)) {
                return Json::error($response, 'That time was just taken', 409);
            }
            error_log('[me/book] booking failed: ' . $e->getMessage());
            return Json::error($response, 'Could not book that slot', 500);
        }

        return Json::write($response, [
            'status' => 'confirmed',
            'appointment' => ['appointment_id' => $id, 'slot_start' => $slot['start'], 'slot_end' => $slot['end']],
        ], 201);
    }

    /**
     * PATCH /me/profile — update own profile. Changing email/phone resets that
     * contact's verified flag (the new value must be re-verified).
     */
    public function updateProfile(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (int) $user['user_id'];
        $current = $this->person($userId);
        $b = (array) $request->getParsedBody();

        $set = [];
        $params = ['id' => $userId];

        if (array_key_exists('display_name', $b)) {
            $name = trim((string) $b['display_name']);
            if ($name === '') {
                return Json::error($response, 'Name cannot be empty', 422);
            }
            $set[] = 'display_name = :dn';
            $params['dn'] = $name;
        }
        if (array_key_exists('preferred_channel', $b)) {
            $set[] = 'preferred_channel = :pc';
            $params['pc'] = $b['preferred_channel'] === 'email' ? 'email' : 'sms';
        }
        if (array_key_exists('carrier_id', $b)) {
            $set[] = 'carrier_id = :ca';
            $params['ca'] = ($b['carrier_id'] === null || $b['carrier_id'] === '') ? null : (int) $b['carrier_id'];
        }
        // Contact changes reset verification.
        foreach (['email' => 'email_verified_at', 'phone' => 'phone_verified_at'] as $field => $verifiedCol) {
            if (array_key_exists($field, $b)) {
                $val = trim((string) $b[$field]);
                $val = $val === '' ? null : $val;
                if ($val !== $current[$field]) {
                    $set[] = "$field = :$field";
                    $set[] = "$verifiedCol = NULL";
                    $params[$field] = $val;
                }
            }
        }

        if ($set) {
            $this->db->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE user_id = :id')->execute($params);
        }
        return Json::write($response, ['ok' => true]);
    }

    /** POST /me/password { current_password, new_password } */
    public function changePassword(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $b = (array) $request->getParsedBody();
        $currentPw = (string) ($b['current_password'] ?? '');
        $newPw = (string) ($b['new_password'] ?? '');

        if (strlen($newPw) < 6) {
            return Json::error($response, 'New password must be at least 6 characters', 422);
        }
        if ($user['password_hash'] && !password_verify($currentPw, $user['password_hash'])) {
            return Json::error($response, 'Current password is incorrect', 401);
        }

        $this->db->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
            ->execute([password_hash($newPw, PASSWORD_BCRYPT), (int) $user['user_id']]);
        return Json::write($response, ['ok' => true]);
    }

    /** POST /me/appointments/{id}/cancel — cancel one of my own upcoming appts. */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $user = $request->getAttribute('user');
        $id = (int) $args['id'];

        $stmt = $this->db->prepare(
            "UPDATE appointments SET status = 'cancelled'
             WHERE appointment_id = :id AND user_id = :pid AND status = 'confirmed'"
        );
        $stmt->execute(['id' => $id, 'pid' => (int) $user['user_id']]);

        if ($stmt->rowCount() === 0) {
            return Json::error($response, 'Appointment not found', 404);
        }
        return Json::write($response, ['ok' => true]);
    }

    // ---- helpers ----

    private function person(int $id): array
    {
        $s = $this->db->prepare('SELECT * FROM users WHERE user_id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: [];
    }

    /** True if the person's preferred contact method has been OTP-verified. */
    private function preferredVerified(array $person): bool
    {
        return $person['preferred_channel'] === 'email'
            ? $person['email_verified_at'] !== null
            : $person['phone_verified_at'] !== null;
    }

    private function nextAppointment(int $personId): ?array
    {
        $s = $this->db->prepare(
            "SELECT appointment_id, slot_start, slot_end FROM appointments
             WHERE user_id = ? AND status = 'confirmed' AND slot_start >= NOW()
             ORDER BY slot_start ASC LIMIT 1"
        );
        $s->execute([$personId]);
        $row = $s->fetch();
        return $row === false ? null : [
            'appointment_id' => (int) $row['appointment_id'],
            'slot_start' => $row['slot_start'],
            'slot_end' => $row['slot_end'],
        ];
    }
}
