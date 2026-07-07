<?php

declare(strict_types=1);

namespace App\Service;

use App\Support\Env;
use PHPMailer\PHPMailer\PHPMailer;

/**
 * Thin PHPMailer wrapper. In dev, SMTP points at MailHog, which catches every
 * message (UI at http://localhost:8025) so nothing reaches real inboxes.
 *
 * SMS is sent as email to the carrier's email-to-SMS gateway
 * (e.g. 5551234567@vtext.com) — see the `carriers` table.
 */
final class Mailer
{
    public function send(string $toEmail, string $subject, string $body): void
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = Env::get('SMTP_HOST', 'mailhog');
        $mail->Port = Env::int('SMTP_PORT', 1025);

        $secure = strtolower(Env::get('SMTP_SECURE'));
        if ($secure !== '') {
            $mail->SMTPSecure = match ($secure) {
                'smtps', 'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                'starttls', 'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                default => throw new \InvalidArgumentException("Unsupported SMTP_SECURE value: {$secure}"),
            };
        }

        $user = Env::get('SMTP_USER');
        if ($user !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = $user;
            $mail->Password = Env::get('SMTP_PASS');
        }

        $mail->setFrom(Env::get('MAIL_FROM', 'bookings@haircut.local'), Env::get('MAIL_FROM_NAME', 'Haircut Tracker'));
        $mail->addAddress($toEmail);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        try {
            $mail->send();
        } catch (\Throwable $e) {
            $from = Env::get('MAIL_FROM', 'bookings@haircut.local');
            $auth = $user !== '' ? 'yes' : 'no';
            $secureLabel = $secure !== '' ? $secure : 'none';
            throw new \RuntimeException(
                "SMTP send failed (host={$mail->Host} port={$mail->Port} secure={$secureLabel} auth={$auth} from={$from}): {$e->getMessage()}",
                previous: $e
            );
        }
    }

    /**
     * Build the destination address for a channel. For SMS, combine the phone
     * (digits only) with the carrier's gateway domain.
     */
    public function addressFor(string $channel, ?string $email, ?string $phone, ?string $gatewayDomain): ?string
    {
        if ($channel === 'email') {
            return $email ?: null;
        }
        if ($channel === 'sms' && $phone && $gatewayDomain) {
            $digits = preg_replace('/\D+/', '', $phone);
            return $digits === '' ? null : "{$digits}@{$gatewayDomain}";
        }
        return null;
    }
}
