<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('fokus:prune-expired-data')->daily();
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
