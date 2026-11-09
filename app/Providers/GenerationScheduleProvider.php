<?php

namespace App\Providers;

use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/** Opt-in result evidence for an externally supervised scheduler generation. */
class GenerationScheduleProvider extends ServiceProvider
{
    public function boot(): void
    {
        $run = getenv('FOKUS_GENERATION_RUN');
        if ($run === false) {
            return;
        }
        $release = getenv('FOKUS_GENERATION_SHA');
        $root = getenv('FOKUS_GENERATION_ROOT');
        $path = getenv('FOKUS_GENERATION_EVENTS');
        if (! preg_match('/\A[0-9a-f]{32}\z/', $run) || ! is_string($release)
            || ! preg_match('/\A[0-9a-f]{40}\z/', $release)
            || $root !== realpath(base_path()) || basename($root) !== $release
            || ! is_string($path) || is_link($path) || realpath($path) !== $path || ! is_file($path)) {
            throw new RuntimeException('generation_observer_unavailable');
        }
        $write = static function (string $state, ?object $task = null, ?int $code = null) use ($path, $run, $release): void {
            $record = ['run' => $run, 'release' => $release, 'state' => $state];
            if ($task !== null) {
                $record['event'] = hash('sha256', $task->mutexName());
                $record['code'] = $code;
            }
            $stream = fopen($path, 'ab');
            if ($stream === false || ! flock($stream, LOCK_EX)) {
                throw new RuntimeException('generation_observer_unavailable');
            }
            try {
                $line = json_encode($record, JSON_THROW_ON_ERROR)."\n";
                if (fwrite($stream, $line) !== strlen($line) || ! fflush($stream) || ! fsync($stream)) {
                    throw new RuntimeException('generation_observer_unavailable');
                }
            } finally {
                flock($stream, LOCK_UN);
                fclose($stream);
            }
        };
        $write('ready');
        Event::listen(JobFailed::class, static fn () => $write('queue_failure'));
        Event::listen(JobExceptionOccurred::class, static fn () => $write('queue_failure'));
        Event::listen(ScheduledTaskStarting::class, static function ($event) use ($write): void {
            // Laravel acquires its overlap mutex before invoking before callbacks.
            $event->task->before(static fn () => $write('start', $event->task));
        });
        Event::listen(ScheduledTaskFinished::class, static function ($event) use ($write): void {
            if ($event->task->skippedBecauseOverlapping) {
                $write('skipped', $event->task);
            } elseif (! $event->task->runInBackground) {
                $write('finish', $event->task, $event->task->exitCode);
            }
        });
        Event::listen(ScheduledBackgroundTaskFinished::class, static fn ($event) => $write('finish', $event->task, $event->task->exitCode));
        Event::listen(ScheduledTaskFailed::class, static fn ($event) => $write('failure', $event->task));
    }
}
