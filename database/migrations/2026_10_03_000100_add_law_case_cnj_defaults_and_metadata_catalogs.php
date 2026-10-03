<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Services\PrefixedUlid;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('law_case_cnj_defaults', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('law_unit_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->char('segment', 1);
            $table->char('court', 2);
            $table->char('origin', 4);
            $table->char('updated_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'law_unit_id'], 'law_case_cnj_defaults_unit_uq');
            $table->foreign(['company_id', 'law_unit_id'])->references(['company_id', 'id'])->on('law_units')->cascadeOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('law_case_metadata_options', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->char('company_id', 30)->charset('ascii')->collation('ascii_bin');
            $table->string('type', 16);
            $table->string('code', 32);
            $table->string('name', 180);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'type', 'code'], 'law_case_metadata_option_code_uq');
            $table->index(['company_id', 'type', 'name'], 'law_case_metadata_option_name_idx');
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('law_cnj_metadata_options', function (Blueprint $table): void {
            $table->char('id', 30)->charset('ascii')->collation('ascii_bin')->primary();
            $table->string('type', 16);
            $table->string('code', 32);
            $table->string('name', 180);
            $table->string('source', 16)->default('cnj_sgt');
            $table->boolean('is_active')->default(true);
            $table->timestamp('source_updated_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'code'], 'law_cnj_metadata_option_code_uq');
            $table->index(['type', 'is_active', 'name'], 'law_cnj_metadata_option_active_name_idx');
        });

        $catalog = json_decode((string) file_get_contents(database_path('seeders/data/cnj-tpu-2026-09-12.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach ($catalog['items'] as $type => $items) {
            foreach (array_chunk($items, 100) as $chunk) {
                $now = now();
                DB::table('law_cnj_metadata_options')->insertOrIgnore(array_map(static fn (array $item): array => [
                    'id' => PrefixedUlid::make('LCN'), 'type' => $type, 'code' => $item['code'], 'name' => $item['name'],
                    'source' => 'cnj_sgt', 'is_active' => true, 'source_updated_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ], $chunk));
            }
        }

        DB::table('law_cases')->whereNotNull('case_class_code')->whereNotNull('case_class')->lazyById(500, 'id')->each(function (object $case): void {
            $code = trim((string) $case->case_class_code); $name = trim((string) $case->case_class);
            if ($code === '' || $name === '') return;
            DB::table('law_case_metadata_options')->insertOrIgnore([
                'id' => PrefixedUlid::make('LCO'), 'company_id' => $case->company_id, 'type' => 'class', 'code' => $code,
                'name' => $name, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
        DB::table('law_cases')->whereNotNull('subjects')->lazyById(500, 'id')->each(function (object $case): void {
            foreach (json_decode((string) $case->subjects, true) ?: [] as $subject) {
                if (! is_array($subject)) continue;
                $code = trim((string) ($subject['code'] ?? '')); $name = trim((string) ($subject['name'] ?? ''));
                if ($code === '' || $name === '') continue;
                DB::table('law_case_metadata_options')->insertOrIgnore([
                    'id' => PrefixedUlid::make('LCO'), 'company_id' => $case->company_id, 'type' => 'subject', 'code' => $code,
                    'name' => $name, 'created_by' => null, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('law_cnj_metadata_options');
        Schema::dropIfExists('law_case_metadata_options');
        Schema::dropIfExists('law_case_cnj_defaults');
    }
};
