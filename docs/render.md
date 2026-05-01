# Render Deployment

This project deploys to Render as a Docker web service from `render.yaml` and connects to TiDB Cloud for MySQL-compatible database hosting.

- `asct`: a Dockerized PHP/Apache web service on Render.
- `act2`: a TiDB Cloud Starter database, AWS N. Virginia (`us-east-1`).

## Create The Services

1. Push this repository to GitHub.
2. In Render, create a new Blueprint and select this repository, or update the existing `ASCT` web service.
3. Render will read `render.yaml` and create or update the free Docker web service.
4. In Render environment variables, set:
   - `DB_HOST` to the host from TiDB Cloud's Connect dialog.
   - `DB_USER` to the username from TiDB Cloud's Connect dialog.
   - `DB_PASS` to the password from TiDB Cloud.
   - `ASCT_APP_BASE_URL` to the public Render URL, for example `https://asct-mcf5.onrender.com`.
   - `RESEND_API_KEY` to your Resend API key.
   - `RESEND_FROM_EMAIL` to the verified sender address in Resend.

Keep these TiDB values:

- `DB_PORT=4000`
- `DB_NAME=asct`
- `DB_CHARSET=utf8mb4`
- `DB_SSL_MODE=required`
- `DB_SSL_CA=/etc/ssl/certs/ca-certificates.crt`
- `DB_SSL_VERIFY_SERVER_CERT=true`

If Render assigns a different `onrender.com` URL, update `ASCT_APP_BASE_URL`. Passkeys depend on this URL matching the real browser origin.

## Initialize TiDB

The schema file `sql/asct.sql` drops and recreates tables. Run it only for a fresh database.

From your Windows machine, use the XAMPP MySQL client with the TiDB connection values:

```powershell
& "C:\xampp\mysql\bin\mysql.exe" --comments --connect-timeout=150 -h "TIDB_HOST" -P 4000 -u "TIDB_USER" --ssl-mode=VERIFY_IDENTITY -p -e "source C:/xampp/htdocs/ASCT/sql/asct.sql"
```

Do not run this command on a production database with real data unless you intend to replace that data.

After import, verify the tables:

```powershell
& "C:\xampp\mysql\bin\mysql.exe" -h "TIDB_HOST" -P 4000 -u "TIDB_USER" --ssl-mode=VERIFY_IDENTITY -p asct -e "SHOW TABLES; SELECT COUNT(*) AS users FROM users; SELECT COUNT(*) AS students FROM students;"
```

## Local Defaults

Local XAMPP defaults are preserved:

- host: `localhost`
- port: `3306`
- database: `asct`
- user: `root`
- password: empty

Use `.env` locally for overrides. Do not commit `.env`.
