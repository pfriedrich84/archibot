<?php

return [
    'mcp_write_enabled' => env('MCP_ENABLE_WRITE', false),
    'path_prefix' => trim((string) env('APP_PATH_PREFIX', ''), '/'),
    'python_binary' => env('ARCHIBOT_PYTHON_BINARY', 'python3'),
    'chat_timeout' => env('ARCHIBOT_CHAT_TIMEOUT', 120),
    'data_dir' => env('DATA_DIR', '/data'),
    'paperless_webhook_secret' => env('PAPERLESS_WEBHOOK_SECRET', env('WEBHOOK_SECRET', '')),
    'webhook_direct_enqueue_enabled' => env('ARCHIBOT_WEBHOOK_DIRECT_ENQUEUE', false),
    'poll_interval_seconds' => env('POLL_INTERVAL_SECONDS', 600),
    'absurd_database_url' => env('ABSURD_DATABASE_URL', env('DATABASE_URL', '')),
];
