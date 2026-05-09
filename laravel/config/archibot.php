<?php

return [
    'mcp_write_enabled' => env('MCP_ENABLE_WRITE', false),
    'path_prefix' => trim((string) env('APP_PATH_PREFIX', ''), '/'),
    'python_binary' => env('ARCHIBOT_PYTHON_BINARY', 'python3'),
    'chat_timeout' => env('ARCHIBOT_CHAT_TIMEOUT', 120),
    'data_dir' => env('DATA_DIR', '/data'),
    'paperless_webhook_secret' => env('PAPERLESS_WEBHOOK_SECRET', env('WEBHOOK_SECRET', '')),
    'webhook_enqueue_command' => env('ARCHIBOT_WEBHOOK_ENQUEUE_COMMAND', ''),
    'poll_interval_seconds' => env('POLL_INTERVAL_SECONDS', 600),
];
