<?php

return [
    'python_binary' => env('ARCHIBOT_PYTHON_BINARY', 'python'),
    'stale_cancelling_minutes' => (int) env('ARCHIBOT_STALE_CANCELLING_MINUTES', 30),
    'lease_seconds' => (int) env('ARCHIBOT_WORKER_LEASE_SECONDS', 300),
    'heartbeat_seconds' => (int) env('ARCHIBOT_WORKER_HEARTBEAT_SECONDS', 15),
];
