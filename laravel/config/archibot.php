<?php

return [
    'mcp_write_enabled' => env('MCP_ENABLE_WRITE', false),
    'path_prefix' => trim((string) env('APP_PATH_PREFIX', ''), '/'),
    'python_binary' => env('ARCHIBOT_PYTHON_BINARY', 'python3'),
    'chat_timeout' => env('ARCHIBOT_CHAT_TIMEOUT', 120),
    'data_dir' => env('DATA_DIR', '/data'),
];
