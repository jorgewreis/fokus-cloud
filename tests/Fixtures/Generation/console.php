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
                if (! $event->mutex->create($event) || ! $event->mutex->exists($event)) {
                    throw new RuntimeException('synthetic_mutex_not_acquired');
                }
            } else {
                $event->mutex->forget($event);
                if ($event->mutex->exists($event)) {
                    throw new RuntimeException('synthetic_mutex_not_released');
                }
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

Artisan::command('generation:filtered', function () {
    file_put_contents(getenv('FOKUS_SYNTHETIC_MARKER').'.filtered', 'unexpected');

    return 0;
});
Artisan::command('generation:distributed', function () {
    return 0;
});
if (getenv('FOKUS_SYNTHETIC_MODE') === 'filter-skip') {
    Schedule::command('generation:filtered')->everyMinute()->when(static fn () => false);
}
if (getenv('FOKUS_SYNTHETIC_MODE') === 'distributed') {
    Schedule::command('generation:distributed')->everyMinute()->onOneServer();
}
