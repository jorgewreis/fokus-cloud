<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;

class GenerationProbe implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 70;

    public function handle(): void
    {
        usleep(300000);
        if (getenv('FOKUS_SYNTHETIC_MODE') === 'queue-fail') {
            throw new RuntimeException('synthetic queue failure');
        }
        file_put_contents(getenv('FOKUS_SYNTHETIC_MARKER'), getenv('FOKUS_GENERATION_SHA')."\n", FILE_APPEND | LOCK_EX);
    }
}
