<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Schedule::command('store:expire-unpaid-orders')->everyMinute()->withoutOverlapping();
Schedule::command('carts:remind')->hourly()->withoutOverlapping();
Schedule::command('queue:monitor '.config('queue.default').':default --max=100')->everyFiveMinutes();

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
