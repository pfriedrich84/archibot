<?php

return [
    'mcp_write_enabled' => env('MCP_ENABLE_WRITE', false),
    'path_prefix' => trim((string) env('APP_PATH_PREFIX', ''), '/'),
    'python_binary' => env('ARCHIBOT_PYTHON_BINARY', 'python3'),
    'data_dir' => env('DATA_DIR', '/data'),
    'paperless_url' => env('PAPERLESS_URL'),
    'paperless_http_timeout_seconds' => (int) env('PAPERLESS_HTTP_TIMEOUT_SECONDS', 10),
    'paperless_http_max_response_bytes' => (int) env('PAPERLESS_HTTP_MAX_RESPONSE_BYTES', 2097152),
    'paperless_http_max_preview_bytes' => (int) env('PAPERLESS_HTTP_MAX_PREVIEW_BYTES', 52428800),
    'setup_rate_limit_per_minute' => (int) env('SETUP_RATE_LIMIT_PER_MINUTE', 5),
    'model_discovery_rate_limit_per_minute' => (int) env('MODEL_DISCOVERY_RATE_LIMIT_PER_MINUTE', 10),
    'paperless_webhook_secret' => env('PAPERLESS_WEBHOOK_SECRET', env('WEBHOOK_SECRET', '')),
    'paperless_webhook_max_bytes' => (int) env('PAPERLESS_WEBHOOK_MAX_BYTES', 262144),
    'paperless_webhook_rate_limit_per_minute' => (int) env('PAPERLESS_WEBHOOK_RATE_LIMIT_PER_MINUTE', 60),
    'paperless_webhook_development_bypass' => env('PAPERLESS_WEBHOOK_DEVELOPMENT_BYPASS', false),
    'poll_interval_seconds' => env('POLL_INTERVAL_SECONDS', 600),
];
