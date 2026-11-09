<?php

use App\Jobs\GenerationProbe;
use Illuminate\Console\Scheduling\Schedule as ScheduleInstance;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('generation:enqueue', function () {
    GenerationProbe::dispatch()->onConnection('law-datajud')->onQueue('law-datajud');
});
Artisan::command('generation:mutex {action}', function () {
    $events = app(ScheduleInstance::class)->events();
    foreach ($events as $event) {
        if ($event->runInBackground) {
            if ($this->argument('action') === 'lock') {
                $event->mutex->create($event);
            } else {
                $event->mutex->forget($event);
            }
        }
    }
});
Artisan::command('generation:foreground', function () {
    return getenv('FOKUS_SYNTHETIC_MODE') === 'foreground-fail' ? 7 : 0;
});
Schedule::command('generation:foreground')->everyMinute();
Schedule::command('queue:work law-datajud --queue=law-datajud --stop-when-empty --max-time=50 --timeout=70 --tries=1')
    ->everyMinute()->withoutOverlapping(5)->runInBackground();
