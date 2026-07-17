#!/bin/sh
set -e

export APP_PATH_PREFIX="${APP_PATH_PREFIX:-}"
export DB_CONNECTION="${DB_CONNECTION:-pgsql}"
export DB_DATABASE="${DB_DATABASE:-archibot}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"
export CACHE_STORE="${CACHE_STORE:-database}"
export SESSION_DRIVER="${SESSION_DRIVER:-database}"
export ARCHIBOT_PYTHON_BINARY="${ARCHIBOT_PYTHON_BINARY:-python}"

wait_for_tcp() {
    name="$1"
    host="$2"
    port="$3"
    timeout_seconds="${4:-60}"
    start_time="$(date +%s)"

    echo "Waiting for ${name} at ${host}:${port}"
    while ! php -r '$host = $argv[1]; $port = (int) $argv[2]; $timeout = (float) $argv[3]; $errno = 0; $errstr = ""; $fp = @fsockopen($host, $port, $errno, $errstr, $timeout); if ($fp) { fclose($fp); exit(0); } exit(1);' "$host" "$port" 1; do
        now="$(date +%s)"
        if [ $((now - start_time)) -ge "$timeout_seconds" ]; then
            echo "Timed out waiting for ${name} at ${host}:${port}" >&2
            return 1
        fi
        sleep 1
    done
}

mkdir -p /data/laravel /app/laravel/storage/app /app/laravel/storage/framework/cache /app/laravel/storage/framework/sessions /app/laravel/storage/framework/views /app/laravel/bootstrap/cache

if [ -z "${APP_KEY:-}" ]; then
    if [ -f /data/laravel/app_key ]; then
        APP_KEY="$(cat /data/laravel/app_key)"
    else
        APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
        printf '%s' "$APP_KEY" > /data/laravel/app_key
        chmod 600 /data/laravel/app_key || true
    fi
    export APP_KEY
fi

if [ "$DB_CONNECTION" = "pgsql" ]; then
    wait_for_tcp "PostgreSQL" "${DB_HOST:-postgres}" "${DB_PORT:-5432}" "${POSTGRES_WAIT_TIMEOUT_SECONDS:-90}"
fi

cd /app/laravel

echo "Preparing Laravel app database at ${DB_DATABASE}"
php artisan migrate --force --no-interaction
php artisan storage:link >/dev/null 2>&1 || true

# Hand long-running processes to supervisord instead of backgrounding them with
# bare ``&``. This keeps the single-container deployment model while making the
# Laravel queue worker, Laravel scheduler, Laravel recovery loop, optional MCP
# server, and web server independently restartable
# and visible in container logs.
echo "Starting supervised ArchiBot processes"
exec /usr/bin/supervisord -c /app/docker/supervisord.conf
