# HeadAhhBlendz — Haircut Tracker

A mobile-first booking + client-tracking app for a barber. Track each 
client's haircut history and cadence, surface a "due for
a cut" reach-out list, and let clients book themselves from a public page.

- **API:** PHP 8.3 + Slim 4, MySQL 8
- **Web:** React + Vite + TypeScript (mobile-first)
- **Dev mail:** MailHog (catches all outgoing email/SMS-gateway messages)
- **Runtime:** Docker Compose

## Quick start

```bash
cp .env.example .env
docker compose up -d --build
```

| What                     | URL                         |
|--------------------------|-----------------------------|
| Booking page (customers) | http://localhost:5173       |
| Admin (barber)           | http://localhost:5173/admin |
| API                      | http://localhost:8080       |
| MailHog inbox (dev mail) | http://localhost:8025       |

**Dev logins (seeded):**
- Admin panel (`/admin`): `admin` / `admin123` (account with `role=admin`).
- Customer (`/`): `jayden` / `secret123` (linked to Jayden Brooks' history).
- Break-glass admin token `dev-admin-token` (sent as `X-Admin-Token`) still works
  for API access; the panel itself now uses the admin account login.

Admin is just a `users` row with `role='admin'` — grant it directly in the DB.

The DB schema/seed in `db/init/` runs automatically the first time the `db`
volume is created. To reset from scratch: `docker compose down -v && docker compose up -d`.

## Tests

```bash
docker compose exec api composer test
```

Unit tests cover the cadence math (`tests/Unit`); integration tests cover the
OTP state machine and **every HTTP endpoint** (`tests/Integration`) against the
live server + MailHog. Best run against a fresh DB (`docker compose down -v && up`).

## Housekeeping sweeper

Expire stale holds and prune rate-limit / verification rows. Run on a schedule
(cron / Task Scheduler):

```bash
docker compose exec api php bin/sweep.php
```

## Schema changes / migrations

`db/init/*.sql` is the **baseline** — it only runs on a fresh DB volume. For
changes to a populated database, use Phinx (see `api/phinx.php`, `api/migrations/`):

```bash
docker compose exec api vendor/bin/phinx migrate
docker compose exec api vendor/bin/phinx create MyChange   # new migration
```

## Security / config notes

- `ADMIN_TOKEN` and `AUTH_SECRET` are **required** in any real deployment. The
  API fails closed: admin routes return 503 with no `ADMIN_TOKEN`, and it
  refuses to start with an empty `AUTH_SECRET`, unless `ADMIN_AUTH_DISABLED=1`
  (dev only).
- Registration only claims an existing client's history after an **OTP proves
  ownership** of the email/phone.
- Rate limits are per-IP (from `REMOTE_ADDR`; `X-Forwarded-For` honored only
  behind `TRUSTED_PROXY`) **and** per-contact.
- Bot check (Cloudflare Turnstile) is wired end-to-end but off by default. To
  enable: set `TURNSTILE_SECRET` (API) and `VITE_TURNSTILE_SITE_KEY` (frontend
  build). Both blank = disabled.
- `SHOP_TZ` keeps PHP and MySQL on the shop's wall clock.

## Deploying to shared hosting

No Docker on the server — Apache + PHP 8.2+ + MySQL, config via a `.env` file
(no real env vars needed; `src/Support/Dotenv.php` reads it).

**Single-domain layout** (`somedomain.com`): the built SPA sits at the web root
(`/` for customers, `/admin` is a client route), and the API lives in an `api/`
sub-directory (`/api`). Everything is same-origin — no CORS.

```
public_html/            <- web docroot
  index.html, assets/   <- contents of web/dist
  .htaccess             <- SPA fallback (excludes /api)
  api/                  <- the whole api/ folder
    .htaccess           <- forwards to public/, blocks internals
    public/index.php ...
```

**1. API**
- Upload the `api/` folder into the web root as `api/`. Run
  `composer install --no-dev` there (or upload `vendor/` if there's no Composer).
- Create **`api/.env`** with the host's real values (see `.env.example`). At
  minimum: `DB_*`, a long random `AUTH_SECRET`, a random `ADMIN_TOKEN`,
  `SHOP_TZ`, `WEBAUTHN_RPID` = your domain, `PUBLIC_BASE_URL=https://somedomain.com`,
  `API_PUBLIC_URL=https://somedomain.com/api`, and **`API_BASE_PATH=/api`**
  (so Slim strips the sub-path).

**2. Database**
- Create a MySQL database in the host panel, then import
  `db/init/001_schema.sql` and `db/init/002_seed.sql` once (phpMyAdmin →
  Import). Put the resulting credentials in `api/.env`.
- For later schema changes, use Phinx (`vendor/bin/phinx migrate`).

**3. Frontend**
- Build locally with the API path baked in (relative = same origin):
  `VITE_API_BASE=/api npm --prefix web run build`.
- Upload the contents of `web/dist/` to the web root (alongside the `api/`
  folder). The SPA-fallback `.htaccess` ships in the build and now excludes
  `/api`, so customer/`/admin` deep-links work and API calls pass through.

**4. HTTPS & passkeys**
- Enable the host's free SSL (Let's Encrypt / AutoSSL). Passkeys **require**
  HTTPS on a real domain, and `WEBAUTHN_RPID` must equal that hostname
  (e.g. `yourdomain.com`, no scheme/port).

**5. Cron (cPanel → Cron Jobs)**
- Daily reminders: `php /home/you/api/bin/reminders.php`
- Housekeeping (expire holds, prune rows): `php /home/you/api/bin/sweep.php`

**6. Mail** — MailHog is dev-only; set `SMTP_*` in `.env` to the host's mailbox.

## Status by phase

| Phase | Scope                                                                                                         | Status |
|-------|---------------------------------------------------------------------------------------------------------------|--------|
| A     | Roster, haircut history, cadence, reach-out list                                                              | ✅ done |
| B     | Public self-booking (holds + OTP + anti-abuse), availability mgmt, accounts, logged-in instant booking        | ✅ done |
| —     | Security/hardening remediation (verified claim, fail-closed auth, interval overlap, timezone, tests, sweeper) | ✅ done |
| C     | Passkeys/WebAuthn, person merge, admin block/remove users, day-before reminders                               | ✅ done |
| —     | Admin account login (role-based), unified login + redirects, shared-hosting `.env`/`.htaccess`                | ✅ done |

See `docs/PLAN.md` for the full product/architecture plan and `docs/CODE_REVIEW.md`
for the review that drove the remediation pass.

Optional/before-public: enable Cloudflare Turnstile (free) on the booking form,
and add a CI workflow to run `composer test`.

## Layout

```
api/            PHP/Slim API (src/, public/index.php, tests/, migrations/, bin/sweep.php)
web/            React + Vite frontend (src/)
db/init/        Baseline schema + seed (runs on fresh volume)
docs/           PLAN.md, CODE_REVIEW.md, BUILD_PLAN_V2.md
```
