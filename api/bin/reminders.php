<?php

declare(strict_types=1);

/**
 * Day-before appointment reminders. Run daily (cron / Task Scheduler):
 *   docker compose exec api php bin/reminders.php
 *
 * Transactional (the customer booked, so a reminder is expected). Sends to the
 * person's preferred channel via the carrier gateway for SMS, skips opted-out
 * people, records reminder_sent_at + a reminders-log row (idempotent — a given
 * appointment is reminded once), and includes a one-click opt-out link.
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Database;
use App\Service\Mailer;
use App\Support\Dotenv;
use App\Support\Env;
use App\Support\Jwt;

Dotenv::load(__DIR__ . '/../.env');
date_default_timezone_set(Env::get('SHOP_TZ', 'America/Denver'));

$db = Database::connect();
$mailer = new Mailer();
$apiBase = Env::get('API_PUBLIC_URL', 'http://localhost:8080');

// Confirmed appointments happening TOMORROW (shop-local) not yet reminded.
$rows = $db->query(
    "SELECT a.appointment_id, a.slot_start, a.notify_channel,
            a.contact_name, a.contact_email, a.contact_phone, a.contact_carrier_id,
            p.user_id AS user_id, p.notify_opt_out, c.sms_gateway_domain
     FROM appointments a
     JOIN users p ON p.user_id = a.user_id
     LEFT JOIN carriers c ON c.carrier_id = a.contact_carrier_id
     WHERE a.status = 'confirmed'
       AND a.reminder_sent_at IS NULL
       AND DATE(a.slot_start) = DATE(DATE_ADD(CURDATE(), INTERVAL 1 DAY))"
)->fetchAll();

$sent = 0;
$skipped = 0;

foreach ($rows as $r) {
    if ((int) $r['notify_opt_out'] === 1) {
        $skipped++;
        continue;
    }

    $channel = $r['notify_channel'] ?: 'email';
    $dest = $mailer->addressFor($channel, $r['contact_email'], $r['contact_phone'], $r['sms_gateway_domain']);
    if (!$dest) {
        $skipped++;
        continue;
    }

    $name = $r['contact_name'] ? explode(' ', trim($r['contact_name']))[0] : 'there';
    $when = date('D M j \a\t g:i A', strtotime($r['slot_start']));
    $optOut = $apiBase . '/optout?token=' . Jwt::issue(['purpose' => 'optout', 'pid' => (int) $r['user_id']], 60 * 60 * 24 * 90);

    $body = "Hi {$name}, reminder: your haircut is {$when}. See you then! "
          . "Reply STOP or opt out: {$optOut}";

    try {
        $mailer->send($dest, 'Haircut reminder', $body);
    } catch (\Throwable $e) {
        error_log('[reminders] send failed for appt ' . $r['appointment_id'] . ': ' . $e->getMessage());
        $skipped++;
        continue;
    }

    $db->prepare('UPDATE appointments SET reminder_sent_at = NOW() WHERE appointment_id = ?')->execute([$r['appointment_id']]);
    $db->prepare('INSERT INTO reminders (user_id, appointment_id, channel) VALUES (:p,:a,:c)')
        ->execute(['p' => (int) $r['user_id'], 'a' => (int) $r['appointment_id'], 'c' => $channel]);
    $sent++;
}

fwrite(STDOUT, sprintf("[reminders %s] sent=%d skipped=%d\n", date('c'), $sent, $skipped));
