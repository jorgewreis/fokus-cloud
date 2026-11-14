<?php

namespace App\Console\Commands;

use App\Services\InterestProductIndex;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

class BackfillInterestProductIndex extends Command
{
    protected $signature = 'fokus:backfill-interest-products {--confirm-drained : Confirm old writers and importers are stopped or upgraded}';

    protected $description = 'Rebuild the product-interest index after all writers use transactional synchronization';

    public function handle(InterestProductIndex $index): int
    {
        if (! $this->option('confirm-drained')) {
            $this->error('Old writers and importers must be drained or upgraded before rebuilding.');

            return self::FAILURE;
        }
        $acquired = DB::table('interest_product_index_state')->where('id', 1)->where('running', false)
            ->update(['running' => true, 'ready' => false]);
        if ($acquired !== 1) {
            $this->error('Index rebuild is already running or its state is missing.');

            return self::FAILURE;
        }
        $count = 0;
        try {
            DB::table('product_interests')->select('id')->orderBy('id')->chunkById(250, function ($batch) use ($index, &$count): void {
                foreach ($batch as $item) {
                    DB::transaction(function () use ($item, $index, &$count): void {
                        $row = DB::table('product_interests')->where('id', $item->id)->lockForUpdate()->first(['id', 'products']);
                        if (! $row) {
                            return;
                        }
                        try {
                            $codes = json_decode((string) $row->products, true, 512, JSON_THROW_ON_ERROR);
                        } catch (JsonException $error) {
                            throw new \RuntimeException("Invalid product JSON for interest {$row->id}.", previous: $error);
                        }
                        $index->replace($row->id, $codes);
                        $count++;
                    });
                }
            }, 'id');
            DB::table('interest_product_index_state')->where('id', 1)->update(['ready' => true]);
            $this->info("Rebuilt index for {$count} interests.");

            return self::SUCCESS;
        } catch (\Throwable $error) {
            $this->error('Rebuild failed: '.$error->getMessage());

            return self::FAILURE;
        } finally {
            DB::table('interest_product_index_state')->where('id', 1)->update(['running' => false]);
        }
    }
}
