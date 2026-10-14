<?php

return [
    'queue' => 'event-mail',
    // A separate worker must process this queue; never send a school-wide batch in HTTP.
    'connection' => env('EVENT_MAIL_QUEUE_CONNECTION', 'event_mail'),
    'batch_size' => (int) env('EVENT_MAIL_BATCH_SIZE', 10),
    'batch_interval' => (int) env('EVENT_MAIL_BATCH_INTERVAL', 60),
    'max_attempts' => 5,
    'backoff' => [60, 300, 1800, 7200, 21600],
];
