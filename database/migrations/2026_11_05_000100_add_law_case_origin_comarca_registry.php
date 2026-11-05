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
            $table->string('comarca', 180)->nullable()->after('court_code');
            $table->index(['case_number'], 'law_cases_comarca_number_idx');
        });

        Schema::create('law_case_origin_comarcas', function (Blueprint $table): void {
            $table->char('justice_branch', 1)->charset('ascii')->collation('ascii_bin');
            $table->char('tribunal_code', 2)->charset('ascii')->collation('ascii_bin');
            $table->char('origin_code', 4)->charset('ascii')->collation('ascii_bin');
            $table->string('comarca_name', 180);
            $table->char('created_by', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->char('created_company_id', 30)->charset('ascii')->collation('ascii_bin')->nullable();
            $table->timestamps();
            $table->primary(['justice_branch', 'tribunal_code', 'origin_code'], 'law_case_origin_comarcas_pk');
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('created_company_id')->references('id')->on('companies')->nullOnDelete();
        });

        // Preserve manually entered legacy values, then seed only unambiguous nationwide mappings.
        $rows = DB::table('law_cases')->whereNotNull('manual_metadata')->get(['id', 'case_number', 'manual_metadata']);
        $groups = [];
        foreach ($rows as $row) {
            $manual = json_decode((string) $row->manual_metadata, true) ?: [];
            $name = trim((string) ($manual['comarca'] ?? ''));
            $identity = $this->identity((string) $row->case_number);
            if ($name === '' || $identity === null) continue;
            DB::table('law_cases')->where('id', $row->id)->whereNull('comarca')->update(['comarca' => $name]);
            $key = implode(':', $identity);
            $groups[$key]['identity'] = $identity;
            $groups[$key]['names'][mb_strtolower($name)] = $name;
        }
        foreach ($groups as $group) {
            if (count($group['names']) !== 1) continue;
            [$branch, $tribunal, $origin] = $group['identity'];
            DB::table('law_case_origin_comarcas')->insertOrIgnore([
                'justice_branch' => $branch, 'tribunal_code' => $tribunal, 'origin_code' => $origin,
                'comarca_name' => array_values($group['names'])[0], 'created_by' => null, 'created_company_id' => null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        foreach (DB::table('law_case_origin_comarcas')->get() as $mapping) {
            DB::table('law_cases')->whereNull('comarca')
                ->whereRaw('SUBSTRING(case_number, 14, 1) = ?', [$mapping->justice_branch])
                ->whereRaw('SUBSTRING(case_number, 15, 2) = ?', [$mapping->tribunal_code])
                ->whereRaw('SUBSTRING(case_number, 17, 4) = ?', [$mapping->origin_code])
                ->update(['comarca' => $mapping->comarca_name]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('law_case_origin_comarcas');
        Schema::table('law_cases', function (Blueprint $table): void {
            $table->dropIndex('law_cases_comarca_number_idx');
            $table->dropColumn('comarca');
        });
    }

    private function identity(string $number): ?array
    {
        $digits = preg_replace('/\D+/', '', $number) ?? '';
        if (strlen($digits) !== 20) return null;
        return [substr($digits, 13, 1), substr($digits, 14, 2), substr($digits, 16, 4)];
    }
};
