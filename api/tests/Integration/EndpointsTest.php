<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * HTTP smoke/integration coverage for every route, run against the live server
 * inside the api container (http://localhost:8080) with MailHog for OTPs. Best
 * run against a freshly-seeded DB (docker compose down -v && up).
 *
 *   docker compose exec api composer test
 */
final class EndpointsTest extends TestCase
{
    private const API = 'http://localhost:8080';
    private const MAILHOG = 'http://mailhog:8025';

    /** @return array{status:int,json:mixed,raw:string} */
    private function req(string $method, string $path, array $opts = []): array
    {
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        if (!empty($opts['admin'])) {
            $headers[] = 'X-Admin-Token: ' . (getenv('ADMIN_TOKEN') ?: 'dev-admin-token');
        }
        if (!empty($opts['token'])) {
            $headers[] = 'Authorization: Bearer ' . $opts['token'];
        }
        $ctx = stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => isset($opts['json']) ? json_encode($opts['json']) : '',
            'ignore_errors' => true,
            'timeout' => 10,
        ]]);
        $raw = @file_get_contents(self::API . $path, false, $ctx);
        $status = 0;
        foreach (($http_response_header ?? []) as $h) {
            if (preg_match('#HTTP/\S+\s+(\d+)#', $h, $m)) {
                $status = (int) $m[1];
            }
        }
        return ['status' => $status, 'json' => json_decode((string) $raw, true), 'raw' => (string) $raw];
    }

    private function clearMail(): void
    {
        @file_get_contents(self::MAILHOG . '/api/v1/messages', false, stream_context_create([
            'http' => ['method' => 'DELETE', 'ignore_errors' => true, 'timeout' => 5],
        ]));
    }

    private function latestOtp(): string
    {
        $raw = @file_get_contents(self::MAILHOG . '/api/v2/messages');
        foreach ((json_decode((string) $raw, true)['items'] ?? []) as $m) {
            if (preg_match('/code is (\d{6})/', $m['Content']['Body'] ?? '', $mm)) {
                return $mm[1];
            }
        }
        return '';
    }

    private function adminToken(): string
    {
        $r = $this->req('POST', '/auth/login', ['json' => ['username' => 'admin', 'password' => 'admin123']]);
        return $r['json']['token'] ?? '';
    }

    private function firstOpenSlot(): ?string
    {
        $r = $this->req('GET', '/slots?days=21');
        foreach ($r['json']['days'] ?? [] as $day) {
            foreach ($day['slots'] as $s) {
                return $s['start'];
            }
        }
        return null;
    }

    // ---- public ----

    public function testHealth(): void
    {
        $r = $this->req('GET', '/health');
        $this->assertSame(200, $r['status']);
        $this->assertSame('connected', $r['json']['db']);
    }

    public function testSlotsAndCarriers(): void
    {
        $this->assertSame(200, $this->req('GET', '/slots')['status']);
        $c = $this->req('GET', '/carriers');
        $this->assertSame(200, $c['status']);
        $this->assertNotEmpty($c['json']['carriers']);
    }

    public function testOptOutInvalidToken(): void
    {
        $this->assertSame(400, $this->req('GET', '/optout?token=nonsense')['status']);
    }

    // ---- admin auth gating ----

    public function testAdminRoutesRequireAuth(): void
    {
        $this->assertSame(401, $this->req('GET', '/admin/persons')['status']);
        $this->assertSame(200, $this->req('GET', '/admin/persons', ['admin' => true])['status']);
        // A normal user's token must NOT pass the admin guard.
        $login = $this->req('POST', '/auth/login', ['json' => ['username' => 'jayden', 'password' => 'secret123']]);
        $this->assertSame(401, $this->req('GET', '/admin/persons', ['token' => $login['json']['token']])['status']);
    }

    public function testAdminAcceptsAdminJwt(): void
    {
        $this->assertSame(200, $this->req('GET', '/admin/persons', ['token' => $this->adminToken()])['status']);
    }

    // ---- admin person + haircut CRUD ----

    public function testPersonAndHaircutCrud(): void
    {
        $create = $this->req('POST', '/admin/persons', ['admin' => true, 'json' => ['display_name' => 'Test Person']]);
        $this->assertContains($create['status'], [200, 201]); // create delegates to detail() (200)
        $pid = $create['json']['user_id'];

        $this->assertSame(200, $this->req('GET', "/admin/persons/$pid", ['admin' => true])['status']);
        $this->assertSame(200, $this->req('PATCH', "/admin/persons/$pid", ['admin' => true, 'json' => ['notes' => 'hi']])['status']);

        $hc = $this->req('POST', "/admin/persons/$pid/haircuts", ['admin' => true, 'json' => ['haircut_date' => '2026-07-01', 'amount_cents' => 2000]]);
        $this->assertSame(201, $hc['status']);
        $hid = $hc['json']['haircut_id'];
        $this->assertSame(200, $this->req('PATCH', "/admin/haircuts/$hid", ['admin' => true, 'json' => ['amount_cents' => 2500]])['status']);
        $this->assertSame(204, $this->req('DELETE', "/admin/haircuts/$hid", ['admin' => true])['status']);

        $this->assertSame(204, $this->req('DELETE', "/admin/persons/$pid", ['admin' => true])['status']);
    }

    public function testDueAndMarkContacted(): void
    {
        $this->assertSame(200, $this->req('GET', '/admin/due?within=30', ['admin' => true])['status']);
        // seeded user 2 (Marcus) exists
        $this->assertSame(200, $this->req('POST', '/admin/persons/2/mark-contacted', ['admin' => true])['status']);
    }

    public function testAvailabilityCrud(): void
    {
        $this->assertSame(200, $this->req('GET', '/admin/availability', ['admin' => true])['status']);
        $c = $this->req('POST', '/admin/availability', ['admin' => true, 'json' => ['weekday' => 3, 'start_time' => '09:00', 'end_time' => '12:00', 'slot_minutes' => 60]]);
        $this->assertSame(201, $c['status']);
        $wid = $c['json']['availability_id'];
        $this->assertSame(200, $this->req('PATCH', "/admin/availability/$wid", ['admin' => true, 'json' => ['slot_minutes' => 30]])['status']);
        $this->assertSame(204, $this->req('DELETE', "/admin/availability/$wid", ['admin' => true])['status']);
    }

    public function testExceptionsCrud(): void
    {
        $this->assertSame(200, $this->req('GET', '/admin/exceptions', ['admin' => true])['status']);
        $c = $this->req('POST', '/admin/exceptions', ['admin' => true, 'json' => ['kind' => 'block', 'start_date' => '2027-01-01', 'end_date' => '2027-01-01', 'note' => 'test']]);
        $this->assertSame(201, $c['status']);
        $this->assertSame(204, $this->req('DELETE', "/admin/exceptions/{$c['json']['schedule_exception_id']}", ['admin' => true])['status']);
    }

    public function testAppointmentsAndUsersLists(): void
    {
        $this->assertSame(200, $this->req('GET', '/admin/appointments', ['admin' => true])['status']);
        $this->assertSame(200, $this->req('GET', '/admin/users', ['admin' => true])['status']);
    }

    public function testMerge(): void
    {
        $a = $this->req('POST', '/admin/persons', ['admin' => true, 'json' => ['display_name' => 'Merge A']])['json']['user_id'];
        $b = $this->req('POST', '/admin/persons', ['admin' => true, 'json' => ['display_name' => 'Merge B']])['json']['user_id'];
        $this->assertSame(200, $this->req('POST', '/admin/persons/merge', ['admin' => true, 'json' => ['source_id' => $a, 'target_id' => $b]])['status']);
        $this->req('DELETE', "/admin/persons/$b", ['admin' => true]);
    }

    // ---- anonymous booking ----

    public function testBookingHoneypotRejected(): void
    {
        $slot = $this->firstOpenSlot();
        if ($slot === null) {
            $this->markTestSkipped('no open slot');
        }
        $r = $this->req('POST', '/book/start', ['json' => [
            'slot_start' => $slot, 'name' => 'Bot', 'channel' => 'email', 'email' => 'bot@x.com', 'website' => 'spam',
        ]]);
        $this->assertSame(400, $r['status']);
    }

    public function testAnonymousBookingFlow(): void
    {
        $slot = $this->firstOpenSlot();
        if ($slot === null) {
            $this->markTestSkipped('no open slot');
        }
        $this->clearMail();
        $email = 'booktest_' . uniqid() . '@example.com';
        $start = $this->req('POST', '/book/start', ['json' => [
            'slot_start' => $slot, 'name' => 'Book Test', 'channel' => 'email', 'email' => $email, 'website' => '',
        ]]);
        $this->assertSame(201, $start['status']);
        $holdId = $start['json']['hold_id'];

        $code = $this->latestOtp();
        $this->assertNotSame('', $code, 'OTP should arrive in MailHog');
        $verify = $this->req('POST', '/book/verify', ['json' => ['hold_id' => $holdId, 'code' => $code]]);
        $this->assertSame(200, $verify['status']);
        $this->assertSame('confirmed', $verify['json']['status']);
    }

    // ---- accounts / me ----

    public function testRegisterVerifyAndMeFlows(): void
    {
        $this->clearMail();
        $uniq = uniqid();
        $reg = $this->req('POST', '/auth/register', ['json' => [
            'username' => 'u' . $uniq, 'password' => 'secret123', 'name' => 'New User',
            'preferred_channel' => 'email', 'email' => "reg_$uniq@example.com",
        ]]);
        $this->assertSame(201, $reg['status']);
        $this->assertTrue($reg['json']['contact_verification_required']);
        $token = $reg['json']['token'];

        // /me works, not yet verified -> booking blocked (403)
        $me = $this->req('GET', '/me', ['token' => $token]);
        $this->assertSame(200, $me['status']);
        $this->assertFalse($me['json']['person']['preferred_verified']);
        $slot = $this->firstOpenSlot();
        if ($slot !== null) {
            $blocked = $this->req('POST', '/me/book', ['token' => $token, 'json' => ['slot_start' => $slot]]);
            $this->assertSame(403, $blocked['status']);
        }

        // verify the contact
        $code = $this->latestOtp();
        $this->assertNotSame('', $code);
        $v = $this->req('POST', '/me/verify-contact', ['token' => $token, 'json' => ['code' => $code]]);
        $this->assertSame(200, $v['status']);
        $this->assertTrue($v['json']['verified']);

        // profile + password
        $this->assertSame(200, $this->req('PATCH', '/me/profile', ['token' => $token, 'json' => ['display_name' => 'Renamed']])['status']);
        $this->assertSame(401, $this->req('POST', '/me/password', ['token' => $token, 'json' => ['current_password' => 'wrong', 'new_password' => 'abcdef']])['status']);
        $this->assertSame(200, $this->req('POST', '/me/password', ['token' => $token, 'json' => ['current_password' => 'secret123', 'new_password' => 'secret123']])['status']);

        // now booking allowed
        if ($slot !== null) {
            $ok = $this->req('POST', '/me/book', ['token' => $token, 'json' => ['slot_start' => $slot]]);
            $this->assertContains($ok['status'], [201, 409], 'verified user can book (or slot just taken)');
        }
    }

    public function testLoginRejectsBadPassword(): void
    {
        $this->assertSame(401, $this->req('POST', '/auth/login', ['json' => ['username' => 'jayden', 'password' => 'nope']])['status']);
    }

    public function testPasskeyOptions(): void
    {
        // server-side options generation (full ceremony needs a browser)
        $token = $this->adminToken();
        $reg = $this->req('POST', '/me/passkey/options', ['token' => $token]);
        $this->assertSame(200, $reg['status']);
        $this->assertArrayHasKey('publicKey', $reg['json']);
        // login options for an account with no passkey -> 404
        $this->assertSame(404, $this->req('POST', '/auth/passkey/options', ['json' => ['username' => 'admin']])['status']);
    }
}
