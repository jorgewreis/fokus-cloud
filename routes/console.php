<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fokus:prune-expired-data')->daily();
Schedule::command('law:sync-case-datajud')->dailyAt('03:15')->withoutOverlapping(120);
Schedule::command('queue:work law-datajud --queue=law-datajud --stop-when-empty --max-time=50 --timeout=70 --tries=1')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();
Schedule::command('fokus:expire-support-sessions')->everyFiveMinutes();
Schedule::command('fokus:compensate-checkout-orphans')->everyFiveMinutes();
Schedule::command('fokus:apply-subscription-changes')->hourly();
Schedule::command('fokus:expire-voucher-reservations')->everyFiveMinutes();
Schedule::command('fokus:expire-subscription-tolerance')->hourly();
Schedule::command('fokus:expire-free-voucher-subscriptions')->hourly();
Schedule::command('fokus:reconcile-mercado-pago')->daily();

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
