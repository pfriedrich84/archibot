<?php

return [
    'python_binary' => env('ARCHIBOT_PYTHON_BINARY', 'python'),
    'queue_worker_timeout' => (int) env('QUEUE_WORKER_TIMEOUT', 21600),
    'stale_cancelling_minutes' => (int) env('ARCHIBOT_STALE_CANCELLING_MINUTES', 30),
    'lease_seconds' => (int) env('ARCHIBOT_WORKER_LEASE_SECONDS', 300),
    'heartbeat_seconds' => (int) env('ARCHIBOT_WORKER_HEARTBEAT_SECONDS', 15),
    'pending_redispatch_seconds' => (int) env('ARCHIBOT_PENDING_REDISPATCH_SECONDS', 900),
    'stale_queued_minutes' => (int) env('ARCHIBOT_STALE_QUEUED_MINUTES', 5),
    'stale_running_minutes' => (int) env('ARCHIBOT_STALE_RUNNING_MINUTES', 10),
    'cancel_grace_seconds' => (int) env('ARCHIBOT_CANCEL_GRACE_SECONDS', 30),
    'max_dispatch_attempts' => (int) env('ARCHIBOT_WORKER_MAX_DISPATCH_ATTEMPTS', 3),
    'queues' => [
        'default' => env('ARCHIBOT_QUEUE_DEFAULT', 'default'),
        'maintenance' => env('ARCHIBOT_QUEUE_MAINTENANCE', 'maintenance'),
        'embeddings' => env('ARCHIBOT_QUEUE_EMBEDDINGS', 'embeddings'),
        'interactive' => env('ARCHIBOT_QUEUE_INTERACTIVE', 'interactive'),
    ],
    'priorities' => [
        'default' => (int) env('ARCHIBOT_PRIORITY_DEFAULT', 50),
        'maintenance' => (int) env('ARCHIBOT_PRIORITY_MAINTENANCE', 40),
        'embeddings' => (int) env('ARCHIBOT_PRIORITY_EMBEDDINGS', 30),
        'interactive' => (int) env('ARCHIBOT_PRIORITY_INTERACTIVE', 80),
    ],
];
