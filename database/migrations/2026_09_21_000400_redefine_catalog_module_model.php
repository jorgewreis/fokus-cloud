<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->clearCommercialAlphaData();

        Schema::table('modules', function (Blueprint $table): void {
            $table->dropIndex('modules_product_id_context_code_variant_code_index');
            $table->dropIndex('modules_product_id_module_code_segment_code_context_code_index');
            $table->dropColumn([
                'segment_code',
                'variant_code',
                'capabilities',
                'dependencies',
                'incompatibilities',
                'capacity_unit',
                'default_capacity',
                'capacity_options',
            ]);
        });

        Schema::create('catalog_custom_module_families', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('product_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('code', 64);
            $table->string('name', 120);
            $table->timestamps();
            $table->unique(['product_id', 'code']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('catalog_custom_capabilities', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('product_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('module_code', 64);
            $table->string('code', 100);
            $table->string('name', 180);
            $table->timestamps();
            $table->unique(['product_id', 'module_code', 'code']);
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
        });

        Schema::create('module_segments', function (Blueprint $table): void {
            $table->char('module_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('segment_code', 32);
            $table->timestamps();
            $table->primary(['module_id', 'segment_code']);
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
        });

        Schema::create('module_capabilities', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('module_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('code', 100);
            $table->string('name', 180);
            $table->boolean('optional')->default(false);
            $table->timestamps();
            $table->unique(['module_id', 'code']);
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
        });

        Schema::create('module_dependencies', function (Blueprint $table): void {
            $table->char('module_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('dependency_module_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->primary(['module_id', 'dependency_module_id']);
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
            $table->foreign('dependency_module_id')->references('id')->on('modules')->cascadeOnDelete();
        });

        Schema::create('module_incompatibilities', function (Blueprint $table): void {
            $table->char('module_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('incompatible_module_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->timestamps();
            $table->primary(['module_id', 'incompatible_module_id']);
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
            $table->foreign('incompatible_module_id')->references('id')->on('modules')->cascadeOnDelete();
        });

        Schema::create('module_personalizations', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('module_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('type_code', 64);
            $table->string('unit', 64);
            $table->boolean('required')->default(true);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('display_order')->default(1);
            $table->timestamps();
            $table->unique(['module_id', 'type_code']);
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
        });

        Schema::create('module_personalization_tiers', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('personalization_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->unsignedInteger('value');
            $table->decimal('additional_monthly_amount', 12, 2)->default(0);
            $table->boolean('active')->default(true);
            $table->unsignedInteger('display_order')->default(1);
            $table->timestamps();
            $table->unique(['personalization_id', 'value']);
            $table->foreign('personalization_id')->references('id')->on('module_personalizations')->cascadeOnDelete();
        });

        Schema::create('plan_personalization_defaults', function (Blueprint $table): void {
            $table->char('plan_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('personalization_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('tier_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamps();
            $table->primary(['plan_id', 'personalization_id']);
            $table->foreign('plan_id')->references('id')->on('plans')->cascadeOnDelete();
            $table->foreign('personalization_id')->references('id')->on('module_personalizations')->cascadeOnDelete();
            $table->foreign('tier_id')->references('id')->on('module_personalization_tiers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        throw new \RuntimeException('A redefinição do catálogo alpha é irreversível por decisão de produto.');
    }

    private function clearCommercialAlphaData(): void
    {
        $tables = [
            'payment_reconciliation_alerts',
            'refund_requests',
            'billing_provider_events',
            'billing_checkout_attempts',
            'voucher_redemption_reservations',
            'voucher_redemptions',
            'subscription_changes',
            'subscription_items',
            'payments',
            'usage_snapshots',
            'subscriptions',
            'vouchers',
            'catalog_publications',
            'plan_modules',
            'plans',
            'modules',
            'products',
        ];

        $driver = DB::getDriverName();
        if ($driver === 'mysql') DB::statement('SET FOREIGN_KEY_CHECKS=0');
        if ($driver === 'sqlite') DB::statement('PRAGMA foreign_keys = OFF');

        foreach ($tables as $table) {
            if (Schema::hasTable($table)) DB::table($table)->delete();
        }

        if (Schema::hasTable('platform_audit_events')) {
            DB::table('platform_audit_events')
                ->where(function ($query): void {
                    foreach (['backoffice.catalog%', 'backoffice.plan%', 'backoffice.voucher%', 'backoffice.subscription%', 'backoffice.payment%', 'backoffice.refund%', 'billing%'] as $pattern) {
                        $query->orWhere('action', 'like', $pattern);
                    }
                })
                ->delete();
        }

        if ($driver === 'mysql') DB::statement('SET FOREIGN_KEY_CHECKS=1');
        if ($driver === 'sqlite') DB::statement('PRAGMA foreign_keys = ON');
    }
};
