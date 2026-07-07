<?php

declare(strict_types=1);

namespace App\Service;

use PDO;

/**
 * One-time codes for proving contact ownership (booking + account claim).
 * Verification is atomic: the attempt counter is incremented with a guard so
 * concurrent requests can't exceed the cap.
 */
final class Otp
{
    public const MAX_ATTEMPTS = 5;
    public const RESEND_COOLDOWN_SECONDS = 120;

    public function __construct(
        private PDO $db,
        private Mailer $mailer,
    ) {
    }

    /**
     * Create a code and send it to $dest. $target is ['appointment_id'=>id] or
     * ['user_id'=>id]. Returns nothing; throws if the mail send fails so the
     * caller can decide how to recover.
     */
    public function issue(string $purpose, array $target, string $contact, string $channel, string $dest, int $ttlMinutes): void
    {
        $this->enforceCooldown($purpose, $contact, $channel);

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $hash = password_hash($code, PASSWORD_BCRYPT);

        $this->db->prepare(
            'INSERT INTO verification_tokens (purpose, appointment_id, user_id, code_hash, contact, channel, expires_at)
             VALUES (:pu, :aid, :uid, :hash, :contact, :channel, DATE_ADD(NOW(), INTERVAL :m MINUTE))'
        )->execute([
            'pu' => $purpose,
            'aid' => $target['appointment_id'] ?? null,
            'uid' => $target['user_id'] ?? null,
            'hash' => $hash,
            'contact' => $contact,
            'channel' => $channel,
            'm' => $ttlMinutes,
        ]);

        $tokenId = (int) $this->db->lastInsertId();
        $row = $this->row($tokenId);
        if (!$row || !password_verify($code, (string) $row['code_hash'])) {
            error_log(sprintf(
                '[otp/issue] stored hash self-check failed token=%d hash_len=%d stored_hash_len=%d',
                $tokenId,
                strlen($hash),
                $row ? strlen((string) $row['code_hash']) : 0
            ));
        }

        // Throws on failure — caller handles (e.g. release hold, surface error).
        $this->mailer->send($dest, 'Your HeadAhhBlendz code', "Your verification code is {$code}. It expires in {$ttlMinutes} minutes.");
    }

    /** Newest unverified token for a target, or null. */
    public function latest(string $purpose, string $targetCol, int $targetId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM verification_tokens
             WHERE purpose = :pu AND {$targetCol} = :id AND verified_at IS NULL
             ORDER BY verification_token_id DESC LIMIT 1"
        );
        $stmt->execute(['pu' => $purpose, 'id' => $targetId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Atomically consume an attempt and check the code.
     * @return array{status:'ok'|'expired'|'too_many'|'wrong', remaining:int}
     */
    public function consume(int $tokenId, string $code): array
    {
        // Increment only if still live and under the cap — the guard makes it atomic.
        $upd = $this->db->prepare(
            'UPDATE verification_tokens
                SET attempts = attempts + 1
              WHERE verification_token_id = :id AND verified_at IS NULL AND attempts < :max AND expires_at > NOW()'
        );
        $upd->execute(['id' => $tokenId, 'max' => self::MAX_ATTEMPTS]);

        if ($upd->rowCount() === 0) {
            $row = $this->row($tokenId);
            if ($row === null || $row['verified_at'] !== null || strtotime($row['expires_at']) <= time()) {
                return ['status' => 'expired', 'remaining' => 0];
            }
            return ['status' => 'too_many', 'remaining' => 0];
        }

        $row = $this->row($tokenId);
        $remaining = max(0, self::MAX_ATTEMPTS - (int) $row['attempts']);

        if (!password_verify($code, $row['code_hash'])) {
            error_log(sprintf(
                '[otp/consume] wrong code token=%d code_len=%d hash_len=%d attempts=%d expires_at=%s',
                $tokenId,
                strlen($code),
                strlen((string) $row['code_hash']),
                (int) $row['attempts'],
                (string) $row['expires_at']
            ));
            return ['status' => 'wrong', 'remaining' => $remaining];
        }

        $this->db->prepare('UPDATE verification_tokens SET verified_at = NOW() WHERE verification_token_id = ?')->execute([$tokenId]);
        return ['status' => 'ok', 'remaining' => $remaining];
    }

    private function row(int $id): ?array
    {
        $s = $this->db->prepare('SELECT * FROM verification_tokens WHERE verification_token_id = ?');
        $s->execute([$id]);
        return $s->fetch() ?: null;
    }

    private function enforceCooldown(string $purpose, string $contact, string $channel): void
    {
        $stmt = $this->db->prepare(
            'SELECT GREATEST(1, :cooldown - TIMESTAMPDIFF(SECOND, created_at, NOW())) AS retry_after
               FROM verification_tokens
              WHERE purpose = :purpose
                AND contact = :contact
                AND channel = :channel
                AND verified_at IS NULL
                AND created_at > DATE_SUB(NOW(), INTERVAL 120 SECOND)
              ORDER BY verification_token_id DESC
              LIMIT 1'
        );
        $stmt->execute([
            'cooldown' => self::RESEND_COOLDOWN_SECONDS,
            'purpose' => $purpose,
            'contact' => $contact,
            'channel' => $channel,
        ]);

        $retryAfter = $stmt->fetchColumn();
        if ($retryAfter !== false) {
            throw new OtpCooldownException((int) $retryAfter);
        }
    }
}

final class OtpCooldownException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfterSeconds)
    {
        parent::__construct('Please wait before requesting another code.');
    }
}
