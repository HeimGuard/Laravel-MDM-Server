# Server

```bash
cd src/laravel
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run build
```

Open the portal and create the first account on the setup page. Without `APP_SYSTEM_ADMINS` the
first user (ID 1) is the system admin; set `APP_SYSTEM_ADMINS` in `.env` to a comma-separated list of
user IDs to choose others. Users and new passwords can also be set from the command line:

```bash
php artisan mdm:user admin@example.com            # asks for the password
docker exec -it mdm php artisan mdm:user admin@example.com
```

The user interface is in English, Czech, German, Spanish, French and Italian (`lang/*.json`). The
setup page asks for the language of the first account, which is also the default for everyone who
has not picked one; each user changes their own in the profile. Logs, alerts and notifications
stay in English.

Installations from before the setup page got a default account (`the-email@example.com` /
`the-password-of-choice`). Change its e-mail and password in the profile; the portal warns after
logging in with that password.

Run the scheduler every minute (it removes CPU/RAM history older than 7 days and runs backups;
the Docker image runs it for you):

```cron
* * * * * cd /path/to/src/laravel && php artisan schedule:run >> /dev/null 2>&1
```

## Docker

A small Alpine-based image (running as a non-root user) is built by GitHub Actions and published to
`ghcr.io/heimguard/laravel-mdm-server`. A single container runs everything under supervisord:

| Process | Description | Disable with |
|---------|-------------|--------------|
| nginx + PHP-FPM | Web application on port 8000, migrations run on start | `RUN_MIGRATIONS=false` skips migrations |
| Reverb | WebSocket server, served by nginx on the same port under `/app` | `REVERB_ENABLED=false` |
| Queue worker | `php artisan queue:work --queue=security,default`: background jobs (security collections of the agents, rules feed) | `QUEUE_ENABLED=false` (jobs then run once the response has been sent) |
| Scheduler | `php artisan schedule:work` | `SCHEDULER_ENABLED=false` |
| Syslog | `rsyslogd` receives syslog on port 5514 (UDP and TCP, published as 514), see [Syslog](security-scanner.md#syslog-from-other-servers-and-devices) | `SYSLOG_ENABLED=false` |

`docker-compose.yml` runs the image with SQLite and no settings (see [Quick start](../README.md#quick-start)).
Settings are passed as `environment:` of the service, e.g. `APP_SYSTEM_ADMINS: 1,2`. For MySQL:

```bash
cp src/laravel/.env.example src/laravel/.env   # set DB_*
docker compose -f docker-compose.mysql.yml --env-file src/laravel/.env up -d
```

Links are `https` behind a TLS proxy (any domain name). Opened directly by an IP address or
`localhost` over plain `http` (e.g. `http://192.168.1.10:8000`), they stay `http`.

Only port 8000 is exposed: nginx serves the web and proxies `/app` (agent WebSockets) to Reverb
inside the container. No Reverb settings are needed: the app publishes to Reverb directly inside the
container, and agents connect to `/app` at the address they reach the server on (e.g.
`wss://mdm.example.com/app` behind a TLS proxy such as Nginx Proxy Manager with WebSocket support
enabled).

On start the container waits for the database and runs the migrations. `APP_KEY`, `REVERB_APP_KEY`
and `REVERB_APP_SECRET` are generated on the first start when they are not set, and kept in
`storage/secrets.env` in the `storage` volume, so they stay the same across restarts and updates.
Values set in the environment always take precedence. Uploaded files and logs are stored in the
`storage` volume.

Without `DB_CONNECTION` (or with `DB_CONNECTION=sqlite`) and no `DB_DATABASE`, the database is SQLite
in the `storage` volume (`storage/database.sqlite`). A volume mounted over `/var/www/database` keeps working: its
`database.sqlite` is used, and on every start the migrations in it are replaced with the ones
from the image, so new migrations are applied after an update.

**PostgreSQL.** `docker-compose.postgres.yml` runs the image with PostgreSQL (`DB_CONNECTION=pgsql`, the image has
`pdo_pgsql`). When `DB_CONNECTION` is changed from SQLite to `pgsql` on an installation that has been used, the
data is copied once on the next start: after the migrations have created the tables, `pgloader` loads the SQLite
database (`database/database.sqlite` or `storage/database.sqlite`, or `SQLITE_IMPORT_FILE`) into them (data only,
tables truncated first, sequences reset). It is done only when the SQLite database has users or devices and the
PostgreSQL database has no user yet, so a database that is in use is never overwritten. The SQLite file is kept as
`*.bkp`; `storage/sqlite-import.state` says what was done. A copy that failed stops the start with the error of
pgloader and is tried again on the next one; `SQLITE_IMPORT=off` skips it. It needs `DB_HOST`, `DB_PORT`,
`DB_DATABASE`, `DB_USERNAME` and `DB_PASSWORD` (not `DB_URL`), and a user that may disable triggers while the data
is loaded (a superuser, such as `POSTGRES_USER` of the official image). Make a copy of the `storage` volume first.

The application logs to daily files in the `storage` volume (`storage/logs`, shown in
**System → Logs**) and to `docker logs` at the same time. Another file channel in the environment or
in `.env` (e.g. `LOG_CHANNEL=single`) is used instead of the daily files; `LOG_CHANNEL=stderr` logs
to `docker logs` only. Database backups from the system pages are not supported in the image (no
`mysqldump`).

## Real-time commands (WebSocket)

Commands (turn off, restart, install updates) are pushed to agents instantly over a WebSocket
using [Laravel Reverb](https://laravel.com/docs/reverb). Without it, commands are delivered with
the agent's next periodic report.

Reverb is required for the portal: its pages never poll. When something about a device changes (a
report, a heartbeat, the progress of a command, a ping, a script result, an alert, a device going
offline) the server announces it on the private `devices` channel (only the device id), and the
components showing that device reload (`resources/js/live.js`, Laravel Echo). Open menus and
forms stay as they are. Browsers connect to the address of the portal under `/app` (the Docker
image proxies it), or to `REVERB_HOST` / `REVERB_PORT` / `REVERB_SCHEME` when they name a public
address.

1. Set `REVERB_APP_KEY` and `REVERB_APP_SECRET` in `.env` to random strings.
2. Point `REVERB_HOST`, `REVERB_PORT` and `REVERB_SCHEME` to where the app publishes to Reverb
   (`127.0.0.1:8080` on the same machine). Agents 1.16.0+ always connect to `/app` at the address
   of the portal, older ones to these values when they are public.
3. Keep the Reverb server running, e.g. with Supervisor (the Docker image already does):

   ```bash
   php artisan reverb:start
   ```

4. Without Docker, forward the `/app` (WebSocket) and `/apps` paths to Reverb, e.g. for nginx:

   ```nginx
   location /app/ {
       proxy_http_version 1.1;
       proxy_set_header Host $http_host;
       proxy_set_header Upgrade $http_upgrade;
       proxy_set_header Connection "Upgrade";
       proxy_read_timeout 1h;
       proxy_pass http://127.0.0.1:8080;
   }

   location /apps/ {
       proxy_set_header Host $http_host;
       proxy_pass http://127.0.0.1:8080;
   }
   ```

The portal can be installed as an app (PWA, `steelants/laravel-general`): open it in Chrome,
Edge or Safari over HTTPS and choose *Install* / *Add to Home Screen*. The service worker caches
nothing but an offline page. Change the icon with
`php artisan pwa:make-icons --icon=resources/images/icon.png` (the vector source is
`resources/images/icon.svg`, also served as `favicon.svg`).
