#!/bin/sh
set -e

# Start the embedded Laravel app in background if available. It is exposed by
# FastAPI under /laravel via an internal reverse proxy.
if [ "${ENABLE_LARAVEL:-true}" = "true" ] && [ -f /app/laravel/artisan ] && command -v php >/dev/null 2>&1; then
    export APP_PATH_PREFIX="${APP_PATH_PREFIX:-laravel}"
    export DB_CONNECTION="${DB_CONNECTION:-sqlite}"
    export DB_DATABASE="${DB_DATABASE:-/data/laravel/database.sqlite}"
    mkdir -p /data/laravel "$(dirname "$DB_DATABASE")"
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

    echo "Preparing Laravel app at /${APP_PATH_PREFIX}"
    php /app/laravel/artisan migrate --force --no-interaction
    echo "Starting Laravel app on 127.0.0.1:8089"
    php /app/laravel/artisan serve --host=127.0.0.1 --port=8089 &
fi

# Start MCP SSE server in background if enabled
if [ "${ENABLE_MCP:-false}" = "true" ]; then
    MCP_TRANSPORT="${MCP_TRANSPORT:-sse}"
    export MCP_TRANSPORT
    echo "Starting MCP server (transport=${MCP_TRANSPORT}, port=${MCP_PORT:-3001})"
    python -m app.mcp_server &
fi

# Start main application
exec uvicorn app.main:app --host 0.0.0.0 --port 8088
