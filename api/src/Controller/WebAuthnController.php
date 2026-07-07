<?php

declare(strict_types=1);

namespace App\Controller;

use App\Database;
use App\Support\Env;
use App\Support\Json;
use App\Support\Jwt;
use lbuchs\WebAuthn\Binary\ByteBuffer;
use lbuchs\WebAuthn\WebAuthn;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * Passkey (WebAuthn) enrollment + login, built on lbuchs/webauthn.
 *
 * Flow is username-first (non-resident): the pending challenge is stashed on
 * the user row between the options and verify calls. localhost is a secure
 * context, so this works over http in dev; production needs HTTPS and the
 * correct WEBAUTHN_RPID.
 *
 * NOTE: requires a real browser authenticator to exercise end-to-end.
 */
final class WebAuthnController
{
    private const TOKEN_TTL = 60 * 60 * 24 * 30;

    private PDO $db;

    public function __construct()
    {
        $this->db = Database::connect();
    }

    private function wa(): WebAuthn
    {
        // 4th arg = true -> ByteBuffers (challenge, ids) serialize as base64url
        // strings the browser can decode (default is an RFC-1342 binary wrapper).
        return new WebAuthn('HeadAhhBlendz', Env::get('WEBAUTHN_RPID', 'localhost'), null, true);
    }

    /** POST /me/passkey/options — enrollment options for the logged-in user. */
    public function registerOptions(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $wa = $this->wa();

        // Exclude already-registered credentials so the same device isn't added twice.
        $exclude = [];
        $rows = $this->db->prepare('SELECT webauthn_credential_id FROM credentials WHERE user_id = ?');
        $rows->execute([(int) $user['user_id']]);
        foreach ($rows->fetchAll() as $r) {
            $exclude[] = base64_decode($r['webauthn_credential_id']);
        }

        $args = $wa->getCreateArgs(
            (string) $user['user_id'],
            $user['username'],
            $user['username'],
            60,
            false,
            false,
            null,
            $exclude
        );

        $this->storeChallenge((int) $user['user_id'], $wa->getChallenge());
        return Json::write($response, $args);
    }

    /** POST /me/passkey/verify — store the new credential. */
    public function registerVerify(Request $request, Response $response): Response
    {
        $user = $request->getAttribute('user');
        $b = (array) $request->getParsedBody();

        $challenge = $this->loadChallenge((int) $user['user_id']);
        if ($challenge === null) {
            return Json::error($response, 'No pending passkey enrollment', 400);
        }

        try {
            $data = $this->wa()->processCreate(
                base64_decode($this->fromB64Url($b['clientDataJSON'] ?? '')),
                base64_decode($this->fromB64Url($b['attestationObject'] ?? '')),
                $challenge,
                false,   // requireUserVerification
                true,    // requireUserPresent
                false    // don't fail on unknown attestation root (platform authenticators)
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'Passkey enrollment failed: ' . $e->getMessage(), 422);
        }

        $this->db->prepare(
            'INSERT INTO credentials (user_id, webauthn_credential_id, public_key, sign_count)
             VALUES (:u, :cid, :pk, :sc)'
        )->execute([
            'u' => (int) $user['user_id'],
            'cid' => base64_encode($data->credentialId),
            'pk' => $data->credentialPublicKey,
            'sc' => (int) $data->signatureCounter,
        ]);

        $this->clearChallenge((int) $user['user_id']);
        return Json::write($response, ['ok' => true]);
    }

    /** POST /auth/passkey/options { username } — login challenge. */
    public function loginOptions(Request $request, Response $response): Response
    {
        $username = strtolower(trim((string) (((array) $request->getParsedBody())['username'] ?? '')));
        $user = $this->userByName($username);
        if ($user === null) {
            return Json::error($response, 'No passkey found for that account', 404);
        }

        $ids = [];
        $rows = $this->db->prepare('SELECT webauthn_credential_id FROM credentials WHERE user_id = ?');
        $rows->execute([(int) $user['user_id']]);
        foreach ($rows->fetchAll() as $r) {
            $ids[] = base64_decode($r['webauthn_credential_id']);
        }
        if ($ids === []) {
            return Json::error($response, 'No passkey registered for that account', 404);
        }

        $wa = $this->wa();
        $args = $wa->getGetArgs($ids, 60);
        $this->storeChallenge((int) $user['user_id'], $wa->getChallenge());
        return Json::write($response, $args);
    }

    /** POST /auth/passkey/verify — verify assertion, issue a session. */
    public function loginVerify(Request $request, Response $response): Response
    {
        $b = (array) $request->getParsedBody();
        $username = strtolower(trim((string) ($b['username'] ?? '')));
        $user = $this->userByName($username);
        if ($user === null) {
            return Json::error($response, 'Unknown account', 404);
        }
        if ($user['status'] !== 'active') {
            return Json::error($response, 'This account has been blocked', 403);
        }

        $challenge = $this->loadChallenge((int) $user['user_id']);
        if ($challenge === null) {
            return Json::error($response, 'No pending passkey login', 400);
        }

        // Find the credential the authenticator used.
        $cid = base64_encode(base64_decode($this->fromB64Url((string) ($b['id'] ?? ''))));
        $cred = $this->db->prepare('SELECT * FROM credentials WHERE user_id = ? AND webauthn_credential_id = ?');
        $cred->execute([(int) $user['user_id'], $cid]);
        $credential = $cred->fetch();
        if ($credential === false) {
            return Json::error($response, 'Unknown passkey', 404);
        }

        try {
            $this->wa()->processGet(
                base64_decode($this->fromB64Url($b['clientDataJSON'] ?? '')),
                base64_decode($this->fromB64Url($b['authenticatorData'] ?? '')),
                base64_decode($this->fromB64Url($b['signature'] ?? '')),
                $credential['public_key'],
                $challenge,
                (int) $credential['sign_count'],
                false,
                true
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'Passkey login failed', 401);
        }

        $this->clearChallenge((int) $user['user_id']);
        $this->db->prepare('UPDATE credentials SET sign_count = sign_count + 1 WHERE credential_id = ?')
            ->execute([(int) $credential['credential_id']]);

        $token = Jwt::issue(['uid' => (int) $user['user_id']], self::TOKEN_TTL);
        return Json::write($response, [
            'token' => $token,
            'user' => ['user_id' => (int) $user['user_id'], 'username' => $user['username'], 'display_name' => $user['display_name'], 'role' => $user['role']],
        ]);
    }

    // ---- helpers ----

    private function userByName(string $username): ?array
    {
        $s = $this->db->prepare('SELECT * FROM users WHERE username = ?');
        $s->execute([$username]);
        return $s->fetch() ?: null;
    }

    private function storeChallenge(int $userId, ByteBuffer $challenge): void
    {
        $this->db->prepare('UPDATE users SET webauthn_challenge = :c WHERE user_id = :id')
            ->execute(['c' => base64_encode($challenge->getBinaryString()), 'id' => $userId]);
    }

    private function loadChallenge(int $userId): ?ByteBuffer
    {
        $s = $this->db->prepare('SELECT webauthn_challenge FROM users WHERE user_id = ?');
        $s->execute([$userId]);
        $v = $s->fetchColumn();
        return $v ? new ByteBuffer(base64_decode((string) $v)) : null;
    }

    private function clearChallenge(int $userId): void
    {
        $this->db->prepare('UPDATE users SET webauthn_challenge = NULL WHERE user_id = ?')->execute([$userId]);
    }

    /** Browser sends base64url; normalize to standard base64 for base64_decode. */
    private function fromB64Url(string $s): string
    {
        $s = strtr($s, '-_', '+/');
        return $s . str_repeat('=', (4 - strlen($s) % 4) % 4);
    }
}
