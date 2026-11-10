<?php

namespace App\Console\Commands;

use App\Services\LawContactAgeTagService;
use Illuminate\Console\Command;

class SyncLawContactAgeTags extends Command
{
    protected $signature = 'law:sync-contact-age-tags';

    protected $description = 'Recalculates automatic age tags for contacts with a birth date';

    public function handle(LawContactAgeTagService $ageTags): int
    {
        $processed = $ageTags->syncAll();
        $this->info("Age tags synchronized for {$processed} contact(s).");

        return self::SUCCESS;
    }
}
