# ASCT Student Information Management System

ASCT is a PHP and vanilla JavaScript student information management system for Aurora State College of Technology. It provides account authentication, student record management, user administration, profile pictures, passkeys, email verification codes, audit logs, and Render deployment support.

## Tech Stack

- PHP 8.3 with Apache
- MySQL-compatible database, including local MySQL or TiDB Cloud
- Vanilla JavaScript frontend
- XAMPP for local Windows development
- Docker and Render for deployment
- Resend for email delivery

## Repository Structure

- `index.php` - main application shell, markup, and embedded styles.
- `assets/js/app.js` - frontend state, API calls, views, and interactions.
- `api/` - JSON endpoints, authentication, users, students, passkeys, audit logs, and uploads.
- `config/` - environment, database, and email configuration.
- `sql/` - schema and migration scripts.
- `docs/render.md` - Render and TiDB deployment notes.
- `storage/` - local sessions and profile picture uploads. Runtime data should not be committed.
- `tools/` - local maintenance checks.

## Local Setup

1. Clone the repository into your XAMPP web root:

   ```powershell
   C:\xampp\htdocs\ASCT
   ```

2. Copy the environment template:

   ```powershell
   Copy-Item .env.example .env
   ```

3. For local XAMPP MySQL defaults, use:

   ```dotenv
   DB_HOST=localhost
   DB_PORT=3306
   DB_NAME=asct
   DB_USER=root
   DB_PASS=
   DB_CHARSET=utf8mb4
   ```

4. Create the local database and import the schema:

   ```powershell
   & "C:\xampp\mysql\bin\mysql.exe" -u root -e "CREATE DATABASE IF NOT EXISTS asct CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
   & "C:\xampp\mysql\bin\mysql.exe" -u root asct -e "source C:/xampp/htdocs/ASCT/sql/asct.sql"
   ```

5. Start Apache and MySQL from XAMPP, then open:

   ```text
   http://localhost/ASCT/
   ```

Do not commit `.env`, session files, profile picture uploads, database dumps with real data, or local archive files.

## Environment Variables

Use `.env.example` as the source of truth for supported variables.

- `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DB_CHARSET` configure the database connection.
- `DB_SSL_MODE`, `DB_SSL_CA`, and `DB_SSL_VERIFY_SERVER_CERT` are required for TiDB Cloud style SSL connections.
- `ASCT_APP_NAME` controls the app name used by server-side code.
- `ASCT_APP_BASE_URL` must match the browser origin, especially for passkeys.
- `RESEND_API_KEY`, `RESEND_FROM_EMAIL`, and `RESEND_FROM_NAME` configure email delivery.

## Deployment

Render deployment is configured through `Dockerfile`, `render.yaml`, and `docker/render-entrypoint.sh`.

See [docs/render.md](docs/render.md) for the full Render and TiDB setup, including required environment variables and database initialization commands.

## Security Notes

- Server-side endpoints bootstrap through `api/bootstrap.php`.
- Security headers include CSP, frame protection, referrer policy, permissions policy, and content type protection.
- Session cookies are HTTP-only, strict-mode sessions are enabled, and session files are stored under `storage/sessions`.
- CSRF protection is required for state-changing requests.
- Authentication includes password login protections, email OTP flows, passkey support, and rate limiting.
- Authorization checks must remain server-side. Do not trust client-provided roles, permissions, ownership, IDs, or status values.
- Keep secrets in environment variables only. Never commit `.env` or production credentials.

## Maintenance Checks

Run PHP syntax checks on changed PHP files before committing:

```powershell
php -l api/bootstrap.php
php -l tools/detect-god-files.php
```

If PHP is not on your Windows PATH, use the XAMPP executable:

```powershell
& "C:\xampp\php\php.exe" -l tools/detect-god-files.php
```

Run the god-file detector:

```powershell
php tools/detect-god-files.php
```

Or with XAMPP PHP:

```powershell
& "C:\xampp\php\php.exe" tools/detect-god-files.php
```

The detector scans PHP, JS, and CSS files only. SQL files are intentionally skipped. Warnings are advisory and exit successfully, similar to framework complexity warnings. A warning means the file may be too large, too broad in responsibility, or too hard to maintain safely.

Current refactor candidates are expected because the application still has large entrypoint files. Treat new warnings as a signal to split UI, API routing, validation, persistence, and reusable helpers before the file becomes harder to review.

## Database Notes

`sql/asct.sql` drops and recreates tables. Use it for fresh local databases or disposable environments only.

For production-like environments, prefer targeted migration scripts such as:

- `sql/add_login_rate_limits.sql`
- `sql/add_audit_logs.sql`

Review destructive schema changes before running them against any database with real data.

## Definition Of Done

Before merging changes, use the strongest practical checks for the affected area:

- Syntax-check changed PHP files.
- Smoke test login, signup or OTP, student records, user administration, and profile picture flows when touched.
- Verify invalid input, permission failures, empty states, and database errors for backend changes.
- Keep runtime data and secrets out of Git.
