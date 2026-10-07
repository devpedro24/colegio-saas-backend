<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('academico:sincronizar-periodos')->hourly()->withoutOverlapping();
Schedule::command('aula:expirar-intentos')->everyMinute()->withoutOverlapping();
