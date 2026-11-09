<?php

use App\Jobs\GenerationProbe;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('generation:enqueue', function () {
    GenerationProbe::dispatch()->onConnection('law-datajud')->onQueue('law-datajud');
});
Artisan::command('generation:foreground', function () {
    return getenv('FOKUS_SYNTHETIC_MODE') === 'foreground-fail' ? 7 : 0;
});
Schedule::command('generation:foreground')->everyMinute();
Schedule::command('queue:work law-datajud --queue=law-datajud --stop-when-empty --max-time=50 --timeout=70 --tries=1')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();
