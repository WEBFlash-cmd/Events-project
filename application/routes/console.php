<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('orders:expire')->everyMinute()->withoutOverlapping();
Schedule::command('events:remind')->everyMinute()->withoutOverlapping();
Schedule::command('events:sync-statuses')->everyMinute()->withoutOverlapping();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
