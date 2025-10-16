<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote')->hourly();

Artisan::command('liquidation:auto-daily', function () {
    Artisan::call('liquidation:auto-daily');
})->dailyAt('23:55');

Artisan::command('liquidation:historical', function () {
    Artisan::call('liquidation:historical');
})->dailyAt('23:55');

Artisan::command('liquidation:notify-pending', function () {
    Artisan::call('liquidation:notify-pending');
})->dailyAt('21:52');
