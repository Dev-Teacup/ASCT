# Render Deployment

This project deploys to Render as two services from `render.yaml`:

- `asct`: a Dockerized PHP/Apache web service.
- `asct-mysql`: a private MySQL 8 service with a persistent disk.

## Create The Services

1. Push this repository to GitHub.
2. In Render, create a new Blueprint and select this repository.
3. Render will read `render.yaml` and create the web service and private MySQL service.
4. When prompted, set:
   - `ASCT_APP_BASE_URL` to the final public Render URL, for example `https://asct.onrender.com`.
   - `RESEND_API_KEY` to your Resend API key.
   - `RESEND_FROM_EMAIL` to the verified sender address in Resend.

If Render assigns a different `onrender.com` URL, update `ASCT_APP_BASE_URL` after the first deploy. Passkeys depend on this URL matching the real browser origin.

## Initialize MySQL

The schema file `sql/asct.sql` drops and recreates tables. Run it only for a fresh database.

After both services are live, open a shell on the `asct` web service and run:

```sh
MYSQL_PWD="$DB_PASS" mysql -h "$DB_HOST" -P "$DB_PORT" -u "$DB_USER" "$DB_NAME" < sql/asct.sql
```

Do not run this command on a production database with real data unless you intend to replace that data.

## Local Defaults

Local XAMPP defaults are preserved:

- host: `localhost`
- port: `3306`
- database: `asct`
- user: `root`
- password: empty

Use `.env` locally for overrides. Do not commit `.env`.
