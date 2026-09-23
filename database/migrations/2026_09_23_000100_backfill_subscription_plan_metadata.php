<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('subscriptions')->whereNotNull('commercial_snapshot')->orderBy('id')->chunk(100, function ($subscriptions): void {
            foreach ($subscriptions as $subscription) {
                $snapshot = json_decode((string) $subscription->commercial_snapshot, true);
                if (! is_array($snapshot)) {
                    continue;
                }

                $planCode = $snapshot['plan_code'] ?? null;
                if (! $planCode) {
                    $item = DB::table('subscription_items')->where('subscription_id', $subscription->id)->whereNull('deleted_at')->orderBy('created_at')->first(['conditions_snapshot']);
                    $conditions = json_decode((string) ($item->conditions_snapshot ?? ''), true) ?: [];
                    $planCode = $conditions['plan_code'] ?? null;
                }
                if (! $planCode) {
                    continue;
                }

                $plan = DB::table('plans')->where('product_id', $subscription->product_id)->where('code', $planCode)->first(['id', 'code', 'name']);
                if (! $plan) {
                    continue;
                }

                $changed = false;
                foreach (['plan_id' => $plan->id, 'plan_code' => $plan->code, 'plan_name' => $plan->name] as $key => $value) {
                    if (($snapshot[$key] ?? null) !== $value) {
                        $snapshot[$key] = $value;
                        $changed = true;
                    }
                }

                if ($changed) {
                    DB::table('subscriptions')->where('id', $subscription->id)->update([
                        'commercial_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Metadata backfill is intentionally irreversible; it preserves historical contract data.
    }
};
