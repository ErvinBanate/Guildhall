# Railway trial deployment

Use the existing GitHub repository `ErvinBanate/Guildhall`, branch `main`, repository root `/`. Do not deploy a generic Laravel template: it would create a different application. Railpack detects Laravel and builds the Vite frontend. No Dockerfile or Vite development server is needed.

The trial includes $5 of credit for up to 30 days; services consume credit while running, so this does not guarantee 30 days of uptime. Limited Trial accounts can have restricted outbound access, affecting Discord and Albion. Connect GitHub and check verification at <https://railway.com/verify>. Stay on Trial; no paid upgrade is needed for this setup. Track usage in the Railway dashboard. Export the database before credits expire: Railway documents deletion of trial volumes 30 days after credit expiration.

## 1. Create the database and web service

1. Create a Railway project and add **MySQL** from the database menu. Leave its persistent volume attached. There is no need for a public database endpoint for the app.
2. Add a service from the GitHub repository. Name it `Guildhall`. Use Railpack and the committed `/railway.toml` configuration file. Leave custom build/start commands empty; the default PHP server serves `public/` and builds React.
3. In Guildhall's Settings → Networking, generate a public domain. Set the target port to the port shown by the server logs if Railway asks. Use this exact HTTPS origin as APP_URL below, without a trailing slash.

## 2. Configure Guildhall variables

Add these in the **Guildhall service**, not the MySQL service. In Railway's Variables editor, add reference variables for MySQL. The examples assume the database service is named `MySQL`; select the actual service in the reference picker if different.

```dotenv
APP_NAME=Guildhall
APP_ENV=production
APP_DEBUG=false
APP_URL=https://YOUR-GENERATED-DOMAIN.up.railway.app
APP_KEY=YOUR-STABLE-LARAVEL-KEY
DB_CONNECTION=mysql
DB_HOST=${{MySQL.MYSQLHOST}}
DB_PORT=${{MySQL.MYSQLPORT}}
DB_DATABASE=${{MySQL.MYSQLDATABASE}}
DB_USERNAME=${{MySQL.MYSQLUSER}}
DB_PASSWORD=${{MySQL.MYSQLPASSWORD}}
SESSION_DRIVER=database
SESSION_ENCRYPT=true
SESSION_SECURE_COOKIE=true
SESSION_LIFETIME=576000
CACHE_STORE=database
QUEUE_CONNECTION=database
LOG_CHANNEL=stderr
LOG_LEVEL=warning
RAILPACK_PHP_EXTENSIONS=sodium,pdo_mysql,curl,mbstring
RAILPACK_SKIP_MIGRATIONS=true
```

Keep APP_KEY stable across deployments and identical on the worker. For a **fresh empty trial database**, generate a new key locally with `php artisan key:generate --show` and paste it privately into Railway. If migrating your existing database, copy the current local APP_KEY privately instead so encrypted data stays readable. Never commit the key or other secrets. Do not replace your local `.env` with Railway settings.

Copy the `DISCORD_*` variables from your local `.env` into Railway privately, including client ID/secret, guild ID, bot token, public key, ticket category, and member/officer/admin role IDs. Keep `ALBION_GUILD_ID=2up23a2ER3y_RUGEpAtNHQ` for Conscripted. No secrets belong in VITE variables.

The web service runs migrations during pre-deploy. Railpack's default startup migration is disabled to avoid running it twice. Redeploy after configuring variables. Check that `/up` returns successfully and the login page loads.

## 3. Add the decision worker

Discord approval/denial/revocation commands are queued: the worker is required for officers to verify members.

1. Add a second service from the same repository, named `Guildhall Worker`.
2. Set **Config File Path** to `/railway-worker.toml`. Do not use `/railway.toml` for the worker; it has a web health check and migrations.
3. Copy/reference all app variables from Guildhall, especially APP_KEY, database references and Discord credentials. Do not generate a second key. Keep `QUEUE_CONNECTION=database`.
4. Deploy after the web service has completed its migrations. Do not generate a public domain for the worker. It polls the database queue; no separate Discord gateway bot service or scheduler is needed by the current implementation. The trial supports only limited on-failure restarts, so the worker has no scheduled clean exit. `--memory=0` disables Laravel's clean-exit memory threshold, not PHP's own memory limit. Check service logs if a crash exhausts restart attempts.

This is three running services total: web, worker, and MySQL. They all count toward trial usage. Do not switch the queue to sync: Discord needs a prompt interaction acknowledgement while decisions run asynchronously.

## 4. Connect Discord and test

In Discord Developer Portal → OAuth2, add `https://YOUR-DOMAIN/api/auth/callback`. Keep the local callback too if you still develop locally.

Set the application's **Interactions Endpoint URL** to `https://YOUR-DOMAIN/api/discord/interactions` and save it. This moves command handling for that Discord application away from your local instance. Keep the existing bot installed in the guild, with its existing permissions. Slash command registration only needs repeating if commands are missing (`npm run discord:commands` locally using the matching application settings).

An empty Railway database has no local character approvals, saved loot sessions or energy accounts. Members need verification again unless you intentionally migrate existing data. Test login, character selection, ticket creation, and an eligible officer's approval. Check the worker logs if decisions stay pending. Then test character stats and a small loot import/save/reopen. A failed Discord endpoint validation can indicate a wrong public key, missing extension, unavailable deployment or restricted trial networking.

## Troubleshooting

- Build failure: inspect build logs, confirm Railpack selected PHP and Node and the frontend manifest was built. Do not set `NODE_ENV=production` or disable development dependency installation before the Vite build.
- Missing extension: confirm `RAILPACK_PHP_EXTENSIONS` is set before rebuilding.
- Database connection failure: use private MySQL reference variables, not localhost or the external TCP proxy port. Confirm MySQL is running in the same project/environment.
- 403 on a browser mutation: ensure APP_URL exactly matches the generated HTTPS origin. Clear stale cookies and sign in again after a hostname change.
- 503 at login or role checks: verify server-side Discord variables and outbound network access. Never enable mock authentication to bypass it.
- Don't delete/recreate the MySQL volume to fix a deployment: it contains application data. Back up before any database changes.

References: <https://railway.com/guides/laravel>, <https://railpack.com/languages/php/>, <https://docs.railway.com/databases/mysql>, <https://docs.railway.com/pricing/free-trial>.
