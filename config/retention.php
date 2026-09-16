<?php

declare(strict_types=1);

return [
    'soft_deleted_days' => (int) env('SOFT_DELETE_RETENTION_DAYS', 30),
    'purge_time' => env('SOFT_DELETE_PURGE_TIME', '02:30'),
];
