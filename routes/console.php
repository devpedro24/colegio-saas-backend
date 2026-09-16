<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('purge:soft-deleted')
    ->dailyAt((string) config('retention.purge_time', '02:30'))
    ->withoutOverlapping();
