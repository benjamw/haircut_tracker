<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Service\Mailer;
use App\Service\Otp;
use App\Service\OtpCooldownException;
use App\Service\RateLimiter;
use App\Support\Env;
use App\Support\Json;
use App\Support\Jwt;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * User accounts. Registration "claims" an existing person by matching email
 * or phone (so a customer inherits their haircut history); otherwise a new
 * person is created. Sessions are HS256 JWTs.
 */
final class AuthController
{
    private const TOKEN_TTL = 60 * 60 * 24 * 30; // 30 days

    private PDO $db;
    private Otp $otp;
    private Mailer $mailer;

    public function __construct()
    {
        $this->db = Database::connect();
        $this->mailer = new Mailer();
        $this->otp = new Otp($this->db, $this->mailer);
    }

    /** POST /auth/register */
    public function register(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $username = strtolower(trim((string) ($b['username'] ?? '')));
        $password = (string) ($b['password'] ?? '');
        $name     = trim((string) ($b['name'] ?? ''));
        $email    = $this->clean($b['email'] ?? null);
        $phone    = $this->clean($b['phone'] ?? null);
        $carrier  = isset($b['carrier_id']) && $b['carrier_id'] !== '' ? (int) $b['carrier_id'] : null;
        $channel  = ($b['preferred_channel'] ?? '') === 'email' ? 'email' : 'sms';

        if (strlen($username) < 3) {
            return Json::error($response, 'Username must be at least 3 characters', 422);
        }
        if (strlen($password) < 6) {
            return Json::error($response, 'Password must be at least 6 characters', 422);
        }
        if (!$email && !$phone) {
            return Json::error($response, 'Provide an email or phone so we can match your history', 422);
        }

        // Username taken?
        $u = $this->db->prepare('SELECT 1 FROM users WHERE username = ?');
        $u->execute([$username]);
        if ($u->fetchColumn()) {
            return Json::error($response, 'That username is taken', 409);
        }

        // SECURITY: never claim an existing client's history from an unverified
        // contact (that was an account-takeover hole). Create a FRESH users row
        // with the login but NO email/phone yet — an OTP to that contact must be
        // verified before any existing history is claimed (see verifyContact()).
        $this->db->prepare(
            "INSERT INTO users (display_name, carrier_id, preferred_channel, username, password_hash, role, status)
             VALUES (:n, :c, :ch, :un, :ph, 'user', 'active')"
        )->execute([
            'n' => $name ?: $username, 'c' => $carrier, 'ch' => $channel,
            'un' => $username, 'ph' => password_hash($password, PASSWORD_BCRYPT),
        ]);
        $userId = (int) $this->db->lastInsertId();

        // Send the claim/verify code to the provided contact.
        $rawContact = $channel === 'email' ? $email : $phone;
        $gateway = $carrier ? $this->carrierGateway($carrier) : null;
        $dest = $this->mailer->addressFor($channel, $email, $phone, $gateway);
        if (!$dest || !$rawContact) {
            return Json::error($response, 'Could not send a verification code to that contact', 422);
        }
        if (!(new RateLimiter($this->db))->allow('register_contact:' . strtolower($dest), 3, 600)) {
            return Json::error($response, 'Too many attempts for this contact — try again later.', 429);
        }

        try {
            $this->otp->issue('claim', ['user_id' => $userId], $rawContact, $channel, $dest, Env::int('OTP_TTL_MINUTES', 10));
        } catch (OtpCooldownException $e) {
            return Json::error($response, $this->cooldownMessage($e), 429);
        } catch (\Throwable $e) {
            error_log('[auth/register] OTP send failed: ' . $e->getMessage());
            return Json::error($response, 'Could not send a verification code — please try again', 502);
        }

        return $this->authResponse($response, $userId, $username, 201, [
            'contact_verification_required' => true,
            'channel' => $channel,
            'sent_to' => $this->mask($dest),
        ]);
    }

    /**
     * POST /me/verify-contact — proves the registrant owns the contact, then
     * claims any existing unclaimed history for that email/phone.
     */
    public function verifyContact(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $userId = (int) $user['user_id'];
        $code = trim((string) (((array) $request->getParsedBody())['code'] ?? ''));

        $token = $this->otp->latest('claim', 'user_id', $userId);
        if ($token === null) {
            return Json::error($response, 'Nothing to verify', 400);
        }

        $result = $this->otp->consume((int) $token['verification_token_id'], $code);
        if ($result['status'] === 'expired') {
            return Json::error($response, 'Code expired — request a new one', 410);
        }
        if ($result['status'] === 'too_many') {
            return Json::error($response, 'Too many attempts — request a new code', 429);
        }
        if ($result['status'] === 'wrong') {
            return Json::error($response, "Incorrect code. {$result['remaining']} attempt(s) left.", 422);
        }

        $contact = $token['contact'];
        $channel = $token['channel'];
        $col = $channel === 'email' ? 'email' : 'phone';
        $verifiedCol = $channel === 'email' ? 'email_verified_at' : 'phone_verified_at';

        // Is there an existing, unclaimed (no login), non-merged client with this
        // contact? If so, fold their history onto THIS account and tombstone it.
        $match = $this->db->prepare(
            "SELECT * FROM users
             WHERE {$col} = :c AND user_id <> :me AND merged_into_id IS NULL AND username IS NULL
             LIMIT 1"
        );
        $match->execute(['c' => $contact, 'me' => $userId]);
        $matched = $match->fetch();

        if ($matched) {
            $other = (int) $matched['user_id'];
            // Move the existing client's data onto this account.
            $this->db->prepare('UPDATE haircuts SET user_id = :me WHERE user_id = :o')->execute(['me' => $userId, 'o' => $other]);
            $this->db->prepare('UPDATE appointments SET user_id = :me WHERE user_id = :o')->execute(['me' => $userId, 'o' => $other]);
            $this->db->prepare('UPDATE reminders SET user_id = :me WHERE user_id = :o')->execute(['me' => $userId, 'o' => $other]);
            // Adopt the known client name + contact + carrier; mark verified.
            $this->db->prepare(
                "UPDATE users
                    SET {$col} = :c, {$verifiedCol} = NOW(),
                        display_name = :dn,
                        carrier_id = COALESCE(carrier_id, :carrier)
                  WHERE user_id = :me"
            )->execute([
                'c' => $contact, 'dn' => $matched['display_name'],
                'carrier' => $matched['carrier_id'], 'me' => $userId,
            ]);
            // Tombstone the old client row.
            $this->db->prepare('UPDATE users SET merged_into_id = :me WHERE user_id = :o')->execute(['me' => $userId, 'o' => $other]);
            $claimed = true;
        } else {
            $this->db->prepare("UPDATE users SET {$col} = :c, {$verifiedCol} = NOW() WHERE user_id = :me")
                ->execute(['c' => $contact, 'me' => $userId]);
            $claimed = false;
        }

        return Json::write($response, ['verified' => true, 'claimed' => $claimed, 'user_id' => $userId]);
    }

    /** POST /auth/login */
    public function login(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $username = strtolower(trim((string) ($b['username'] ?? '')));
        $password = (string) ($b['password'] ?? '');

        $stmt = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user === false || !password_verify($password, $user['password_hash'])) {
            return Json::error($response, 'Wrong username or password', 401);
        }
        if ($user['status'] !== 'active') {
            return Json::error($response, 'This account has been blocked', 403);
        }

        return $this->authResponse($response, (int) $user['user_id'], $user['username']);
    }

    /**
     * POST /me/contact/send-code — send an OTP to the logged-in user's preferred
     * contact so they can verify it (required before self-scheduling). The code
     * is consumed by verifyContact().
     */
    public function sendContactCode(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $person = $this->person((int) $user['user_id']);
        $channel = $person['preferred_channel'];
        $email = $person['email'];
        $phone = $person['phone'];

        $rawContact = $channel === 'email' ? $email : $phone;
        if (!$rawContact) {
            return Json::error($response, 'Add your ' . ($channel === 'email' ? 'email' : 'phone') . ' to your profile first', 422);
        }

        $gateway = $person['carrier_id'] ? $this->carrierGateway((int) $person['carrier_id']) : null;
        $dest = $this->mailer->addressFor($channel, $email, $phone, $gateway);
        if (!$dest) {
            return Json::error($response, 'Could not send a code to your contact', 422);
        }
        if (!(new RateLimiter($this->db))->allow('verify_contact:' . strtolower($dest), 3, 600)) {
            return Json::error($response, 'Too many codes requested — try again later.', 429);
        }

        try {
            $this->otp->issue('claim', ['user_id' => (int) $user['user_id']], $rawContact, $channel, $dest, Env::int('OTP_TTL_MINUTES', 10));
        } catch (OtpCooldownException $e) {
            return Json::error($response, $this->cooldownMessage($e), 429);
        } catch (\Throwable $e) {
            error_log('[me/contact/send-code] OTP send failed: ' . $e->getMessage());
            return Json::error($response, "Couldn't send the code — please try again", 502);
        }

        return Json::write($response, ['sent_to' => $this->mask($dest), 'channel' => $channel]);
    }

    // ---- helpers ----

    private function person(int $id): array
    {
        $s = $this->db->prepare('SELECT * FROM users WHERE user_id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: [];
    }

    private function authResponse(Response $response, int $userId, string $username, int $status = 200, array $extra = []): Response
    {
        $u = $this->db->prepare('SELECT display_name, role FROM users WHERE user_id = ?');
        $u->execute([$userId]);
        $row = $u->fetch() ?: ['display_name' => '', 'role' => 'user'];

        $token = Jwt::issue(['uid' => $userId], self::TOKEN_TTL);

        return Json::write($response, array_merge([
            'token' => $token,
            'user'  => ['user_id' => $userId, 'username' => $username, 'display_name' => $row['display_name'], 'role' => $row['role']],
        ], $extra), $status);
    }

    private function carrierGateway(int $carrierId): ?string
    {
        $s = $this->db->prepare('SELECT sms_gateway_domain FROM carriers WHERE carrier_id = ?');
        $s->execute([$carrierId]);
        $v = $s->fetchColumn();
        return $v === false ? null : (string) $v;
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

    private function clean(mixed $v): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }
}
