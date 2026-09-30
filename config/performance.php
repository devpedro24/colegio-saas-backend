<?php

return [
    'enabled' => (bool) env('PERFORMANCE_LOG_ENABLED', true),
    'slow_ms' => (int) env('PERFORMANCE_SLOW_MS', 750),
    'sample_rate' => (float) env('PERFORMANCE_SAMPLE_RATE', 0),
    'realtime_queue' => env('REALTIME_QUEUE', 'default'),
];
