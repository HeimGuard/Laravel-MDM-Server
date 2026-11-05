#!/bin/sh
# Starts nginx, PHP-FPM, Reverb and the scheduler under supervisord.
set -e

# PID 1 ignores signals without a handler; stop promptly even during startup.
trap 'exit 143' TERM INT QUIT

# Named volumes start empty, recreate the storage skeleton.
mkdir -p storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs

# A volume mounted over database/ (to keep database.sqlite there) still holds the migrations of
# the image it was created from, so new migrations would never run. Always use this image's ones.
for dir in migrations seeders factories; do
    if ! diff -rq "/usr/share/laravel-mdm-database/$dir" "database/$dir" >/dev/null 2>&1; then
        if rm -rf "database/$dir" && cp -r "/usr/share/laravel-mdm-database/$dir" "database/$dir"; then
            echo "Updated database/$dir from the image"
        else
            echo "Warning: could not update database/$dir, new migrations may not run" >&2
        fi
    fi
done

# Without DB_CONNECTION the database is SQLite (the Laravel default), so the image runs with no
# settings at all. SQLite without an explicit path would live in the image
# (database/database.sqlite): not writable by the app user and lost with every image update. Keep it
# in the storage volume instead, unless a database file was mounted there.
# A mounted .env keeps its DB_CONNECTION (the environment would otherwise hide it).
if [ -z "${DB_CONNECTION:-}" ] && [ -f .env ]; then
    DB_CONNECTION=$(sed -n 's/^DB_CONNECTION=["'"'"']\{0,1\}\([A-Za-z0-9_-]*\).*/\1/p' .env | tail -n 1)
fi
: "${DB_CONNECTION:=sqlite}"
export DB_CONNECTION
if [ -d database/database.sqlite ]; then
    # Docker creates a directory for a bind mount of a file that does not exist on the host.
    echo "Warning: database/database.sqlite is a directory (a mounted file missing on the host?), using storage/database.sqlite" >&2
fi
if [ "$DB_CONNECTION" = "sqlite" ] && [ -z "${DB_DATABASE:-}" ] && [ ! -f database/database.sqlite ]; then
    export DB_DATABASE=/var/www/storage/database.sqlite
fi
if [ "$DB_CONNECTION" = "sqlite" ] && [ -n "${DB_DATABASE:-}" ] && [ ! -e "$DB_DATABASE" ]; then
    touch "$DB_DATABASE"
    echo "Created SQLite database $DB_DATABASE"
fi

# Secrets that are not configured are generated once and kept in the storage volume,
# so they survive restarts and image updates (a new APP_KEY would log everyone out).
# Values set in the environment always win.
secrets=storage/secrets.env

ensure_secret() {
    name=$1
    eval "value=\${$name:-}"
    if [ -z "$value" ] && [ -f "$secrets" ]; then
        value=$(sed -n "s/^$name='\(.*\)'\$/\1/p" "$secrets" | tail -n 1)
    fi
    if [ -z "$value" ]; then
        value=$2
        echo "$name='$value'" >> "$secrets"
        chmod 600 "$secrets"
        echo "Generated $name (stored in $secrets)"
    fi
    export "$name=$value"
}

random() {
    head -c "$1" /dev/urandom | base64 | tr -dc 'A-Za-z0-9' | head -c "$1"
}

ensure_secret APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
ensure_secret REVERB_APP_ID mdm
ensure_secret REVERB_APP_KEY "$(random 20)"
ensure_secret REVERB_APP_SECRET "$(random 32)"

# Reverb runs in this container: broadcast to it directly unless configured otherwise. Agents are
# told to connect to the address they reach the server on (see /api/device/realtime), so no
# REVERB_* settings are needed behind a reverse proxy.
# BROADCAST_CONNECTION defaults to reverb (config/broadcasting.php).
if [ "${REVERB_ENABLED:-true}" = "true" ]; then
    if [ -z "${REVERB_HOST:-}" ]; then
        REVERB_HOST=127.0.0.1 REVERB_PORT=8080 REVERB_SCHEME=http
    fi
    export REVERB_HOST REVERB_PORT REVERB_SCHEME
else
    # Without Reverb agents must not be told to use the WebSocket.
    : "${BROADCAST_CONNECTION:=null}"
    export BROADCAST_CONNECTION
fi

# Background jobs run in the queue worker of this container (database queue). Without the worker
# (QUEUE_ENABLED=false) they run once the response has been sent, unless a queue is configured.
if [ "${QUEUE_ENABLED:-true}" != "true" ]; then
    : "${QUEUE_CONNECTION:=sync}"
    export QUEUE_CONNECTION
fi

# Logs go to a file in the storage volume (storage/logs, shown in System > Logs) and to the
# container output (docker logs). The channel comes from the environment or from a mounted .env
# (the environment would otherwise hide it), daily files by default; stderr is added next to it.
# LOG_CHANNEL=stderr logs to the container output only.
if [ -z "${LOG_CHANNEL:-}" ] && [ -f .env ]; then
    LOG_CHANNEL=$(sed -n 's/^LOG_CHANNEL=["'"'"']\{0,1\}\([A-Za-z0-9_,-]*\).*/\1/p' .env | tail -n 1)
    if [ -z "${LOG_STACK:-}" ]; then
        LOG_STACK=$(sed -n 's/^LOG_STACK=["'"'"']\{0,1\}\([A-Za-z0-9_,-]*\).*/\1/p' .env | tail -n 1)
        [ -n "$LOG_STACK" ] || unset LOG_STACK
    fi
fi
case "${LOG_CHANNEL:=daily}" in
    stderr) ;;
    stack)
        case ",${LOG_STACK:=single}," in
            *,stderr,*) ;;
            *) LOG_STACK="$LOG_STACK,stderr" ;;
        esac
        ;;
    *)
        LOG_STACK="$LOG_CHANNEL,stderr"
        LOG_CHANNEL=stack
        ;;
esac
export LOG_CHANNEL LOG_STACK

if [ $# -gt 0 ]; then
    exec "$@"
fi

php artisan optimize

# The key agents pin: created once in the storage volume (storage/mdm-signing.key), never in the
# database. Losing it means reinstalling the agents, keep it in the backup of the volume.
php artisan mdm:signing-key

if [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    # The database container may still be starting.
    attempt=1
    until php artisan migrate --force; do
        if [ "$attempt" -ge "${MIGRATION_RETRIES:-30}" ]; then
            echo "Migrations failed, giving up" >&2
            exit 1
        fi
        echo "Database not ready, retrying in 2 s ($attempt)..." >&2
        attempt=$((attempt + 1))
        sleep 2
    done
fi

# DB_CONNECTION was changed from SQLite to PostgreSQL: the migrations above have created the tables, now the
# data of the SQLite database is copied into them once (pgloader, data only, tables truncated first, sequences
# reset). `php artisan mdm:sqlite-import-source` names the file, and only when the PostgreSQL database has no user
# yet, so a database that is in use is never overwritten. The SQLite file stays next to its place as *.imported.
# SQLITE_IMPORT=off switches it off. pgloader takes the connection from PG* (no password in a file or arguments);
# it disables the triggers of the tables while it loads, which PostgreSQL allows a superuser only (the user of
# POSTGRES_USER of the postgres image is one).
if [ "$DB_CONNECTION" = "pgsql" ] && [ "${SQLITE_IMPORT:-auto}" != "off" ] && [ "${RUN_MIGRATIONS:-true}" = "true" ]; then
    source=$(php artisan mdm:sqlite-import-source 2>/dev/null) || source=""
    source=$(printf '%s\n' "$source" | tail -n 1)
    if [ -n "$source" ] && [ -f "$source" ]; then
        if [ -n "${DB_URL:-}" ]; then
            echo "Warning: DB_URL is set, the SQLite database $source is not copied (set DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD instead)" >&2
        else
            echo "Copying $source into PostgreSQL (${DB_DATABASE:-laravel} on ${DB_HOST:-127.0.0.1}) with pgloader"
            echo "started $source" > storage/sqlite-import.state
            load=storage/sqlite-import.load
            cat > "$load" <<EOF_LOAD
LOAD DATABASE
    FROM sqlite://$source
    INTO postgresql:///${DB_DATABASE:-laravel}
WITH include no drop, data only, truncate, reset sequences, create no tables, create no indexes
EXCLUDING TABLE NAMES MATCHING 'migrations', 'sqlite_sequence', ~/^sqlite_/
;
EOF_LOAD
            if PGHOST="${DB_HOST:-127.0.0.1}" PGPORT="${DB_PORT:-5432}" PGUSER="${DB_USERNAME:-}" PGPASSWORD="${DB_PASSWORD:-}" pgloader --on-error-stop "$load"; then
                echo "done $source" > storage/sqlite-import.state
                mv "$source" "$source.imported" 2>/dev/null || echo "Warning: could not rename $source, it is not copied again (storage/sqlite-import.state)" >&2
                echo "SQLite database copied, the file is kept as $source.imported"
            else
                echo "Copying the SQLite database failed (the next start tries again; SQLITE_IMPORT=off skips it)" >&2
                exit 1
            fi
        fi
    fi
fi

exec supervisord -c /etc/supervisord.conf
