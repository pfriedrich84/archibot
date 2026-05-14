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

# Run queued Laravel jobs (including Python worker CLI hand-offs) in the background.
echo "Starting Laravel queue worker"
php artisan queue:work --sleep=3 --tries=1 --timeout="${QUEUE_WORKER_TIMEOUT:-900}" &

# Periodically recover lost Laravel worker jobs after queue worker crashes or restarts.
echo "Starting Laravel worker job recovery loop"
(
    while true; do
        php artisan worker-jobs:recover --no-interaction || true
        sleep "${ARCHIBOT_WORKER_RECOVERY_INTERVAL_SECONDS:-30}"
    done
) &

# Start Dramatiq actors and the durable recovery bridge when RabbitMQ is configured.
if [ -n "${DRAMATIQ_BROKER_URL:-}" ]; then
    echo "Starting Dramatiq worker"
    cd /app
    python -m dramatiq app.actors.webhook app.actors.maintenance app.actors.document app.actors.embedding app.actors.review &
    echo "Starting event recovery bridge"
    python -m app.event_worker recovery-scan --interval-seconds "${EVENT_RECOVERY_INTERVAL_SECONDS:-30}" &
    cd /app/laravel
fi

# Start MCP SSE server in background if enabled.
if [ "${ENABLE_MCP:-false}" = "true" ]; then
    MCP_TRANSPORT="${MCP_TRANSPORT:-sse}"
    export MCP_TRANSPORT
    echo "Starting MCP server (transport=${MCP_TRANSPORT}, port=${MCP_PORT:-3001})"
    cd /app
    python -m app.mcp_server &
    cd /app/laravel
fi

# Start the Laravel/Svelte application as the primary web UI/API.
echo "Starting Laravel app on 0.0.0.0:${GUI_PORT:-8088}"
exec php artisan serve --host=0.0.0.0 --port="${GUI_PORT:-8088}"
