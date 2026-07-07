# Deploy Runbook — fresh checkout → live on shared hosting

Target layout (single domain, e.g. `headahhblendz.welker.space
`):

```
public_html/            <- web docroot (SSL enabled)
  index.html            \
  assets/                > contents of web/dist   ( / and /admin )
  .htaccess             /  SPA fallback, excludes /api
  api/                  <- the api/ folder        ( /api backend )
    .htaccess              forwards to public/, blocks internals
    public/  src/  vendor/  bin/  migrations/  phinx.php  composer.json
    .env                   <- you create this (never committed)
```

Everything is same-origin, so there is **no CORS** and no root router to write.
You upload the **built** SPA files (not the `web/` source) plus the `api/` folder.

---

## 0. Prerequisites (local machine)

You need Docker (already used for dev). No local Node/PHP required — the build
steps below run in throwaway containers. Commands assume Git Bash / WSL from the
repo root.

---

## 1. Build the frontend (relative API path)

```bash
docker run --rm -v "$PWD/web:/app" -w /app node:22-alpine \
  sh -c "npm install && VITE_API_BASE=/api npm run build"
```

Produces `web/dist/` (index.html, assets/, .htaccess) with the API path baked in
as `/api`. Confirm `web/dist/index.html` exists.

## 2. Vendor the API dependencies (production only)

```bash
docker run --rm -v "$PWD/api:/app" -w /app composer:2 \
  install --no-dev --optimize-autoloader
```

Produces `api/vendor/`. `--no-dev` drops PHPUnit/Phinx (fine — you import SQL
directly below; re-run without `--no-dev` locally if you want Phinx later).

## 3. Create the production `api/.env`

Generate two secrets:

```bash
openssl rand -hex 32   # use for AUTH_SECRET
openssl rand -hex 24   # use for ADMIN_TOKEN
```

Create `api/.env` (this file is git-ignored — it only exists on your machine and
the host). Use your host's DB credentials:

```
# Database (from your host's control panel)
DB_HOST=localhost
DB_PORT=3306
DB_NAME=youruser_haircut
DB_USER=youruser_haircut
DB_PASSWORD=your-db-password

# Security
AUTH_SECRET=<paste rand -hex 32>
ADMIN_TOKEN=<paste rand -hex 24>
APP_DEBUG=

# App
SHOP_TZ=America/Denver
API_BASE_PATH=/api
WEBAUTHN_RPID=somedomain.com
PUBLIC_BASE_URL=https://somedomain.com
API_PUBLIC_URL=https://somedomain.com/api

# Mail (your host's mailbox — needed for booking codes + reminders)
SMTP_HOST=localhost
SMTP_PORT=587
SMTP_USER=bookings@somedomain.com
SMTP_PASS=your-mailbox-password
MAIL_FROM=bookings@somedomain.com
MAIL_FROM_NAME=HeadAhhBlendz

# Bot check (leave blank until you add Cloudflare keys)
TURNSTILE_SECRET=

# Only if behind a proxy/CDN that sets X-Forwarded-For
TRUSTED_PROXY=
```

Do **not** set `ADMIN_AUTH_DISABLED` and leave `APP_DEBUG` blank — those are
dev-only. The `MYSQL_*` and `*_PORT` vars from `.env.example` are Docker-only;
omit them here.

## 4. Create the database (host control panel)

1. In cPanel → **MySQL Databases**, create a database + user, and add the user to
   the database with all privileges. Put those into `api/.env` (step 3).
2. In **phpMyAdmin**, select that database and **Import** these in order:
   - `db/init/001_schema.sql` (tables)
   - `db/prod_seed.sql` (carriers + starter hours — **not** the demo `002_seed.sql`)

## 5. Create your admin login

**If your host has SSH/Terminal:**
```bash
php api/bin/create_admin.php <username> <a-strong-password>
```

**If not**, generate a hash locally and insert via phpMyAdmin:
```bash
docker run --rm php:8.3-cli php -r 'echo password_hash("your-password", PASSWORD_BCRYPT), "\n";'
```
Then in phpMyAdmin (SQL tab), replacing the hash:
```sql
INSERT INTO users (display_name, username, password_hash, role, status)
VALUES ('Barber', 'yourname', '<paste-hash>', 'admin', 'active');
```

## 6. (Optional) Import your real client history

Edit `db/import_haircuts.sql` if needed, then Import it in phpMyAdmin. It creates
a person per client with their cut history and prices.

## 7. Assemble and upload

Upload to `public_html/` (via cPanel File Manager or SFTP):

- **Contents of `web/dist/`** → straight into `public_html/` (so `index.html` is
  at the root).
- **The `api/` folder** → `public_html/api/` (include `vendor/` and your `.env`).

Do **not** upload: `web/` (source), `node_modules/`, `docker-compose.yml`,
`db/`, `docs/`, `.git/`. (The `db/*.sql` files are only used for the phpMyAdmin
import in steps 4/6 — they don't go on the server.)

## 8. Docroot + HTTPS

- Point the domain's document root at `public_html` (usually the default).
- Enable **SSL** (Let's Encrypt / AutoSSL). HTTPS is required for passkeys, and
  `WEBAUTHN_RPID` must equal the bare hostname (`somedomain.com`).

## 9. Cron jobs (cPanel → Cron Jobs)

```
# daily, morning — day-before reminders
0 8 * * *  php /home/youruser/public_html/api/bin/reminders.php >/dev/null 2>&1
# hourly — expire stale holds, prune rows
0 * * * *  php /home/youruser/public_html/api/bin/sweep.php   >/dev/null 2>&1
```

## 10. Smoke test

- `https://somedomain.com/api/health` → `{"status":"ok","db":"connected"}`.
- `https://somedomain.com/` → booking page loads; pick a slot, get a code (check
  the mailbox), confirm.
- `https://somedomain.com/admin` → log in with your admin account; set your real
  hours under **Hours**.
- Enroll a passkey (needs the live HTTPS domain).

## 11. Later: enable Cloudflare Turnstile (free, optional)

1. Cloudflare dash → Turnstile → add widget for `somedomain.com` → get site +
   secret keys.
2. Set `TURNSTILE_SECRET` in `api/.env`.
3. Rebuild the frontend with the site key and re-upload `web/dist/`:
   ```bash
   docker run --rm -v "$PWD/web:/app" -w /app node:22-alpine \
     sh -c "npm install && VITE_API_BASE=/api VITE_TURNSTILE_SITE_KEY=<site-key> npm run build"
   ```

---

## Updating later

- **Frontend change:** rebuild (step 1), re-upload `web/dist/` contents.
- **API change:** re-upload the changed files under `api/` (keep `.env` + `vendor/`).
- **Schema change:** write a Phinx migration and run `vendor/bin/phinx migrate`
  on the host (needs dev deps), or apply the SQL via phpMyAdmin.
