<?php

declare(strict_types=1);

namespace App\Service;

use App\Database;
use PDO;

/**
 * Fixed-window counter backed by the rate_limits table. Shared by the per-IP
 * middleware and per-contact checks (contact is the expensive resource — it's
 * what triggers outbound OTP mail).
 */
final class RateLimiter
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** Record a hit; return true if still within the limit for this window. */
    public function allow(string $key, int $limit, int $windowSeconds): bool
    {
        $windowStart = date('Y-m-d H:i:s', (int) (floor(time() / $windowSeconds) * $windowSeconds));

        $this->db->prepare(
            'INSERT INTO rate_limits (rate_key, window_start, hits) VALUES (:k, :w, 1)
             ON DUPLICATE KEY UPDATE hits = hits + 1'
        )->execute(['k' => $key, 'w' => $windowStart]);

        $stmt = $this->db->prepare('SELECT hits FROM rate_limits WHERE rate_key = :k AND window_start = :w');
        $stmt->execute(['k' => $key, 'w' => $windowStart]);

        return (int) $stmt->fetchColumn() <= $limit;
    }
}
