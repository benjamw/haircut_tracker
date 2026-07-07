<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database;
use App\Service\Mailer;
use App\Service\Otp;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Integration test for the atomic OTP state machine against the dev MySQL.
 * Inserts its own verification_tokens rows (bound to the seeded admin user,
 * id 1) and cleans them up — no mail is sent (consume() is exercised directly).
 *
 * Run inside the api container: `docker compose exec api composer test`.
 */
final class OtpConsumeTest extends TestCase
{
    private PDO $db;
    private Otp $otp;
    private const USER_ID = 1; // seeded barber/admin user

    protected function setUp(): void
    {
        $this->db = Database::connect();
        $this->otp = new Otp($this->db, new Mailer());
        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
    }

    private function clean(): void
    {
        $this->db->prepare("DELETE FROM verification_tokens WHERE purpose='claim' AND user_id = ?")
            ->execute([self::USER_ID]);
    }

    /** @param int $expiresInMin negative = already expired */
    private function insertToken(string $code, int $attempts = 0, int $expiresInMin = 10): int
    {
        $this->db->prepare(
            "INSERT INTO verification_tokens (purpose, user_id, code_hash, contact, channel, attempts, expires_at)
             VALUES ('claim', :u, :h, 'x@example.com', 'email', :a, DATE_ADD(NOW(), INTERVAL :m MINUTE))"
        )->execute([
            'u' => self::USER_ID,
            'h' => password_hash($code, PASSWORD_BCRYPT),
            'a' => $attempts,
            'm' => $expiresInMin,
        ]);
        return (int) $this->db->lastInsertId();
    }

    public function testWrongThenCorrect(): void
    {
        $id = $this->insertToken('123456');

        $wrong = $this->otp->consume($id, '000000');
        $this->assertSame('wrong', $wrong['status']);
        $this->assertSame(4, $wrong['remaining']);

        $ok = $this->otp->consume($id, '123456');
        $this->assertSame('ok', $ok['status']);

        // Once verified, it can't be reused.
        $again = $this->otp->consume($id, '123456');
        $this->assertSame('expired', $again['status']);
    }

    public function testAttemptCapIsEnforced(): void
    {
        $id = $this->insertToken('123456');

        for ($i = 0; $i < Otp::MAX_ATTEMPTS; $i++) {
            $this->assertSame('wrong', $this->otp->consume($id, '999999')['status']);
        }
        // Cap reached — further attempts are refused, even with the right code.
        $this->assertSame('too_many', $this->otp->consume($id, '123456')['status']);
    }

    public function testExpiredCodeRejected(): void
    {
        $id = $this->insertToken('123456', 0, -1); // already expired
        $this->assertSame('expired', $this->otp->consume($id, '123456')['status']);
    }

    public function testLatestReturnsNewestUnverified(): void
    {
        $this->insertToken('111111');
        $second = $this->insertToken('222222');

        $latest = $this->otp->latest('claim', 'user_id', self::USER_ID);
        $this->assertNotNull($latest);
        $this->assertSame($second, (int) $latest['verification_token_id']);
    }
}
