<?php

declare(strict_types=1);

/**
 * Housekeeping sweeper — run periodically (cron / scheduled task):
 *   docker compose exec api php bin/sweep.php
 *
 *   1. Expire stale holds so abandoned bookings free their slot promptly
 *      (slots are already treated as free once hold_expires_at passes, but this
 *      keeps the table clean and the admin view honest).
 *   2. Prune old rate_limits rows (they'd otherwise grow unbounded).
 *   3. Prune long-expired verification tokens.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Support\Dotenv;
use App\Support\Env;

Dotenv::load(__DIR__ . '/../.env');
date_default_timezone_set(Env::get('SHOP_TZ', 'America/Denver'));

$db = Database::connect();

$holds = $db->prepare(
    "UPDATE appointments SET status = 'cancelled', hold_expires_at = NULL
     WHERE status IN ('held','pending_verify')
       AND hold_expires_at IS NOT NULL AND hold_expires_at <= NOW()"
);
$holds->execute();

$rate = $db->prepare('DELETE FROM rate_limits WHERE window_start < DATE_SUB(NOW(), INTERVAL 1 DAY)');
$rate->execute();

$tokens = $db->prepare('DELETE FROM verification_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
$tokens->execute();

fwrite(STDOUT, sprintf(
    "[sweep %s] expired_holds=%d pruned_rate_limits=%d pruned_tokens=%d\n",
    date('c'),
    $holds->rowCount(),
    $rate->rowCount(),
    $tokens->rowCount(),
));
