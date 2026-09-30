<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('release_version', 16)->nullable()->after('published_catalog_version');
        });
        Schema::table('modules', function (Blueprint $table): void {
            $table->string('release_version', 16)->nullable()->after('published_version');
        });
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('release_version', 16)->nullable()->after('published_version');
        });
        Schema::table('catalog_publications', function (Blueprint $table): void {
            $table->string('release_version', 16)->nullable()->after('version');
        });

        DB::table('products')->where('published_catalog_version', '>', 0)->update(['release_version' => '1.00']);
        DB::table('modules')->where('published_version', '>', 0)->update(['release_version' => '1.00']);
        DB::table('plans')->where('published_version', '>', 0)->update(['release_version' => '1.00']);

        DB::table('products')->where('published_catalog_version', '>', 0)->get(['id'])->each(function (object $product): void {
            $latest = DB::table('catalog_publications')->where('product_id', $product->id)->orderByDesc('version')->first();
            if ($latest) {
                DB::table('catalog_publications')->where('id', $latest->id)->update(['release_version' => '1.00']);
            }
        });

        DB::table('catalog_publications')->orderBy('id')->get(['id', 'snapshot', 'release_version'])->each(function (object $publication): void {
            $snapshot = json_decode((string) $publication->snapshot, true);
            if (! is_array($snapshot)) {
                return;
            }
            $snapshot['release_version'] ??= '1.00';
            foreach (['modules', 'plans'] as $key) {
                foreach (($snapshot[$key] ?? []) as $index => $item) {
                    if (is_array($item) && (int) ($item['published_version'] ?? 0) > 0) {
                        $snapshot[$key][$index]['release_version'] ??= '1.00';
                    }
                }
            }
            DB::table('catalog_publications')->where('id', $publication->id)->update([
                'release_version' => $publication->release_version ?? '1.00',
                'snapshot' => json_encode($snapshot),
            ]);
        });
    }

    public function down(): void
    {
        Schema::table('catalog_publications', function (Blueprint $table): void { $table->dropColumn('release_version'); });
        Schema::table('plans', function (Blueprint $table): void { $table->dropColumn('release_version'); });
        Schema::table('modules', function (Blueprint $table): void { $table->dropColumn('release_version'); });
        Schema::table('products', function (Blueprint $table): void { $table->dropColumn('release_version'); });
    }
};
