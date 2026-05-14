<?php

return [
    'python_binary' => env('ARCHIBOT_PYTHON_BINARY', 'python'),
    'stale_cancelling_minutes' => (int) env('ARCHIBOT_STALE_CANCELLING_MINUTES', 30),
    'lease_seconds' => (int) env('ARCHIBOT_WORKER_LEASE_SECONDS', 300),
    'heartbeat_seconds' => (int) env('ARCHIBOT_WORKER_HEARTBEAT_SECONDS', 15),
    'pending_redispatch_seconds' => (int) env('ARCHIBOT_PENDING_REDISPATCH_SECONDS', 30),
    'stale_running_minutes' => (int) env('ARCHIBOT_STALE_RUNNING_MINUTES', 10),
    'max_dispatch_attempts' => (int) env('ARCHIBOT_WORKER_MAX_DISPATCH_ATTEMPTS', 3),
];
