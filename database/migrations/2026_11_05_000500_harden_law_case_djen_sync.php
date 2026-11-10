<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->unsignedSmallInteger('djen_retry_count')->default(0)->after('last_djen_synced_at');
            $table->boolean('djen_retryable')->default(true)->after('djen_retry_count');
            $table->string('djen_sync_message', 1000)->nullable()->after('djen_retryable');
        });

        DB::table('law_cases')->whereNotNull('djen_metadata')->select(['id', 'djen_metadata'])->orderBy('id')->chunk(250, function ($cases): void {
            foreach ($cases as $case) {
                $metadata = json_decode((string) $case->djen_metadata, true);
                if (! is_array($metadata) || ! is_array($metadata['publications'] ?? null)) continue;
                foreach ($metadata['publications'] as &$publication) {
                    if (! is_array($publication)) continue;
                    if (! array_key_exists('availability_date', $publication) && isset($publication['publication_date'])) $publication['availability_date'] = $publication['publication_date'];
                    $publication['publication_date'] = null;
                }
                unset($publication);
                $metadata['complete'] = false;
                $metadata['coverage_note'] = 'Registro legado: a consulta anterior não confirmou todas as páginas; atualize para reconciliar publicações.';
                DB::table('law_cases')->where('id', $case->id)->update(['djen_metadata' => json_encode($metadata, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}']);
            }
        });
    }

    public function down(): void
    {
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->dropColumn(['djen_retry_count', 'djen_retryable', 'djen_sync_message']);
        });
    }
};
