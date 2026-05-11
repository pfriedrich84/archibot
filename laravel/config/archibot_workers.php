<?php

return [
    'python_binary' => env('ARCHIBOT_PYTHON_BINARY', 'python'),
    'stale_cancelling_minutes' => (int) env('ARCHIBOT_STALE_CANCELLING_MINUTES', 30),
];
