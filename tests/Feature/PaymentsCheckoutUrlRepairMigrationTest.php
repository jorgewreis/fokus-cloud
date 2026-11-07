<?php

namespace Tests\Feature;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class PaymentsCheckoutUrlRepairMigrationTest extends TestCase
{
    private const CONNECTION = 'checkout_url_repair_test';

    private const COLUMN = 'provider_checkout_url';

    private ?Connection $connection = null;

    private ?Builder $originalSchema = null;

    private bool $ownsTable = false;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();

        $configuration = config('database.connections.'.config('database.default'));
        $driver = $configuration['driver'] ?? null;

        if (! app()->environment('testing') || ! in_array($driver, ['sqlite', 'mysql'], true)) {
            throw new RuntimeException('Repair tests require a synthetic SQLite/MySQL testing connection.');
        }

        if ($driver === 'mysql' && (
            ($configuration['host'] ?? null) !== '127.0.0.1'
            || (string) ($configuration['port'] ?? '') !== '3306'
            || ($configuration['database'] ?? null) !== 'fokuscloud_security'
            || ! empty($configuration['url'])
            || ! empty($configuration['unix_socket'])
            || isset($configuration['read'])
            || isset($configuration['write'])
        )) {
            throw new RuntimeException('Repair tests refuse a non-disposable MySQL target.');
        }

        if ($driver === 'sqlite') {
            $configuration['database'] = ':memory:';
            $configuration['url'] = null;
        }

        // A separate connection and private table prefix avoid touching app payments.
        $configuration['prefix'] = 'checkout_repair_'.bin2hex(random_bytes(6)).'_';
        config(['database.connections.'.self::CONNECTION => $configuration]);
        $this->connection = DB::connection(self::CONNECTION);
        $this->originalSchema = Schema::getFacadeRoot();
        Schema::swap($this->connection->getSchemaBuilder());
        $this->connection->enableQueryLog();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->ownsTable) {
                $this->connection->getSchemaBuilder()->drop('payments');
            }
        } finally {
            if ($this->originalSchema !== null) {
                Schema::swap($this->originalSchema);
            }

            DB::purge(self::CONNECTION);
            parent::tearDown();
        }
    }

    public function test_missing_column_is_added_without_changing_existing_values_or_timestamp_definitions(): void
    {
        $this->createPayments();
        $columns = Schema::getColumns('payments');
        $rows = $this->rows();

        $this->migration()->up();

        $column = collect(Schema::getColumns('payments'))->firstWhere('name', self::COLUMN);
        $this->assertSame($this->connection->getDriverName() === 'sqlite' ? 'varchar' : 'varchar(512)', $column['type']);
        $this->assertTrue($column['nullable']);
        $this->assertNull($column['default']);
        $this->assertNull($column['generation']);
        $this->assertSame($columns, array_values(array_filter(Schema::getColumns('payments'), fn (array $item): bool => $item['name'] !== self::COLUMN)));
        $this->assertSame(array_map(fn (array $row): array => [...$row, self::COLUMN => null], $rows), $this->rows());
    }

    public function test_fresh_laravel_definition_is_a_no_op_and_preserves_checkout_urls(): void
    {
        // Same definition used by the historical fresh-install migration.
        $this->createPayments(fn (Blueprint $table) => $table->string(self::COLUMN, 512)->nullable());
        $this->connection->table('payments')->update([self::COLUMN => 'https://checkout.invalid/synthetic-existing']);
        $this->assertNoOpUp();
    }

    public function test_explicit_varchar_512_definition_is_a_no_op(): void
    {
        $this->createPayments(fn (Blueprint $table) => $table->rawColumn(self::COLUMN, 'varchar(512)')->nullable());
        $this->assertNoOpUp();
    }

    public function test_explicit_sql_null_default_is_a_no_op(): void
    {
        $this->createPayments(fn (Blueprint $table) => $table->rawColumn(self::COLUMN, 'varchar(512)')->nullable()->default(DB::raw('NULL')));
        $this->assertNoOpUp();
    }

    public function test_repeated_repair_is_idempotent(): void
    {
        $this->createPayments();
        $this->migration()->up();
        $this->connection->table('payments')->update([self::COLUMN => 'https://checkout.invalid/synthetic-after-repair']);
        $this->assertNoOpUp();
    }

    #[DataProvider('invalidDefinitions')]
    public function test_unexpected_definition_fails_without_mutating_schema_or_rows(string $type, bool $nullable): void
    {
        // Explicit types retain the requested length in SQLite, unlike string().
        $this->createPayments(fn (Blueprint $table) => $table->rawColumn(self::COLUMN, $type)->nullable($nullable), $nullable ? [] : [self::COLUMN => 'synthetic']);
        $this->assertRejectedWithoutMutation();
    }

    public static function invalidDefinitions(): array
    {
        return [
            'shorter length' => ['varchar(255)', true],
            'longer length' => ['varchar(1024)', true],
            'text instead of varchar' => ['text', true],
            'fixed length instead of varchar' => ['char(255)', true],
            'integer instead of varchar' => ['integer', true],
            'not nullable' => ['varchar(512)', false],
        ];
    }

    public function test_non_null_default_is_rejected_without_exposing_its_value(): void
    {
        $this->createPayments(fn (Blueprint $table) => $table->string(self::COLUMN, 512)->nullable()->default('https://checkout.invalid/unexpected-default'));
        $this->assertRejectedWithoutMutation();
    }

    public function test_generated_column_is_rejected_without_mutation(): void
    {
        $this->createPayments(fn (Blueprint $table) => $table->string(self::COLUMN, 512)->nullable()->storedAs("'https://checkout.invalid/generated'"));
        $this->assertRejectedWithoutMutation();
    }

    public function test_down_preserves_repaired_column_and_existing_checkout_urls(): void
    {
        $this->createPayments();
        $this->migration()->up();
        $this->connection->table('payments')->update([self::COLUMN => 'https://checkout.invalid/forward-only']);
        $columns = Schema::getColumns('payments');
        $rows = $this->rows();
        $this->connection->flushQueryLog();

        $this->migration()->down();

        $this->assertSame([], $this->connection->getQueryLog());
        $this->assertSame($columns, Schema::getColumns('payments'));
        $this->assertSame($rows, $this->rows());
        $this->assertNoOpUp();
    }

    public function test_down_does_not_add_a_missing_column(): void
    {
        $this->createPayments();
        $columns = Schema::getColumns('payments');
        $this->connection->flushQueryLog();

        $this->migration()->down();

        $this->assertSame([], $this->connection->getQueryLog());
        $this->assertSame($columns, Schema::getColumns('payments'));
    }

    public function test_missing_payments_table_fails_closed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checkout URL repair requires the payments table.');
        $this->migration()->up();
    }

    public function test_unsupported_driver_fails_before_inspecting_or_changing_the_schema(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        Schema::shouldReceive('getConnection')->once()->andReturn($connection);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checkout URL repair requires MySQL or SQLite.');
        $this->migration()->up();
    }

    private function createPayments(?Closure $checkoutDefinition = null, array $extraRowValues = []): void
    {
        if (Schema::hasTable('payments')) {
            throw new RuntimeException('Synthetic repair table must not already exist.');
        }

        Schema::create('payments', function (Blueprint $table) use ($checkoutDefinition): void {
            $table->unsignedInteger('id')->primary();
            $table->decimal('amount', 12, 2);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $checkoutDefinition?->__invoke($table);
        });
        $this->ownsTable = true;
        $this->connection->table('payments')->insert([
            'id' => 1,
            'amount' => '42.75',
            'paid_at' => null,
            'created_at' => '2026-01-01 12:00:00',
            'updated_at' => '2026-01-01 12:00:00',
            ...$extraRowValues,
        ]);
    }

    private function migration(): Migration
    {
        return require base_path('database/migrations/2026_11_07_000100_repair_payments_provider_checkout_url.php');
    }

    private function rows(): array
    {
        return $this->connection->table('payments')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
    }

    private function assertNoOpUp(): void
    {
        $columns = Schema::getColumns('payments');
        $rows = $this->rows();
        $this->connection->flushQueryLog();

        $this->migration()->up();

        $this->assertNoWrites();
        $this->assertSame($columns, Schema::getColumns('payments'));
        $this->assertSame($rows, $this->rows());
    }

    private function assertRejectedWithoutMutation(): void
    {
        $columns = Schema::getColumns('payments');
        $rows = $this->rows();
        $this->connection->flushQueryLog();

        try {
            $this->migration()->up();
            $this->fail('Unexpected checkout column definition was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Unexpected payments.provider_checkout_url definition; expected nullable varchar(512), no non-null default or generation.', $exception->getMessage());
        }

        $this->assertNoWrites();
        $this->assertSame($columns, Schema::getColumns('payments'));
        $this->assertSame($rows, $this->rows());
    }

    private function assertNoWrites(): void
    {
        $writes = array_filter($this->connection->getQueryLog(), fn (array $query): bool => preg_match('/^\s*(?:alter|create|drop|insert|update|delete|replace|truncate)\b/i', $query['query']) === 1);
        $this->assertSame([], array_values($writes));
    }
}
