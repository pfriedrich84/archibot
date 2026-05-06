#!/bin/sh
set -e

export APP_PATH_PREFIX="${APP_PATH_PREFIX:-}"
export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
export DB_DATABASE="${DB_DATABASE:-/data/laravel/database.sqlite}"
export QUEUE_CONNECTION="${QUEUE_CONNECTION:-database}"
export CACHE_STORE="${CACHE_STORE:-database}"
export SESSION_DRIVER="${SESSION_DRIVER:-database}"
export ARCHIBOT_PYTHON_BINARY="${ARCHIBOT_PYTHON_BINARY:-python}"

mkdir -p /data/laravel "$(dirname "$DB_DATABASE")" /app/laravel/storage/app /app/laravel/storage/framework/cache /app/laravel/storage/framework/sessions /app/laravel/storage/framework/views /app/laravel/bootstrap/cache
touch "$DB_DATABASE"

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

cd /app/laravel

echo "Preparing Laravel app database at ${DB_DATABASE}"
php artisan migrate --force --no-interaction
php artisan storage:link >/dev/null 2>&1 || true

# Run queued Laravel jobs (including Python worker CLI hand-offs) in the background.
echo "Starting Laravel queue worker"
php artisan queue:work --sleep=3 --tries=1 --timeout="${QUEUE_WORKER_TIMEOUT:-900}" &

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
