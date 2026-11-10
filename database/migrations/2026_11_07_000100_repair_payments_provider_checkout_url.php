<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if (! in_array($driver, ['mysql', 'sqlite'], true)) {
            throw new RuntimeException('Checkout URL repair requires MySQL or SQLite.');
        }

        if (! Schema::hasTable('payments')) {
            throw new RuntimeException('Checkout URL repair requires the payments table.');
        }

        $column = $this->checkoutUrlColumn();

        if ($column === null) {
            // Repair schema drift without changing the historical migration ledger.
            Schema::table('payments', function (Blueprint $table): void {
                $table->string('provider_checkout_url', 512)->nullable();
            });

            $column = $this->checkoutUrlColumn();
        }

        $type = strtolower(preg_replace('/\s+/', '', (string) ($column['type'] ?? '')));
        // Laravel's SQLite string grammar emits plain varchar, without a length.
        // This is the native fresh-schema representation, not proof of a 512 limit.
        // MySQL/Percona must report the actual varchar(512) definition.
        $validType = $type === 'varchar(512)' || ($driver === 'sqlite' && $type === 'varchar');
        // SQLite exposes an explicit DEFAULT NULL as the SQL token "NULL".
        $validDefault = array_key_exists('default', $column ?? [])
            && ($column['default'] === null || ($driver === 'sqlite' && strtoupper(trim((string) $column['default'])) === 'NULL'));

        if (! $validType
            || ($column['type_name'] ?? null) !== 'varchar'
            || ($column['nullable'] ?? null) !== true
            || ! $validDefault
            || ! array_key_exists('generation', $column ?? [])
            || $column['generation'] !== null) {
            // Do not alter, truncate, regenerate, or expose unexpected existing data.
            throw new RuntimeException('Unexpected payments.provider_checkout_url definition; expected nullable varchar(512), no non-null default or generation.');
        }
    }

    private function checkoutUrlColumn(): ?array
    {
        foreach (Schema::getColumns('payments') as $column) {
            if ($column['name'] === 'provider_checkout_url') {
                return $column;
            }
        }

        return null;
    }

    public function down(): void
    {
        // Forward-only repair: rollback must preserve the column and checkout URLs.
        // A rollback can remove the migration's ledger entry, never this stored data.
    }
};
