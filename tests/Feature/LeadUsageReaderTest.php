<?php

namespace Tests\Feature;

use App\Integrations\Lead\LeadConnections;
use App\Integrations\Lead\LeadUnavailable;
use App\Integrations\Lead\LeadUsageReader;
use Illuminate\Database\SQLiteConnection;
use PDO;
use Tests\TestCase;

class LeadUsageReaderTest extends TestCase
{
    public function test_unconfigured_metrics_are_unavailable_without_connecting(): void
    {
        config(['lead.metrics_enabled' => false]);
        $this->assertSame(['available' => false, 'reason' => 'not_configured', 'metrics' => null], app(LeadUsageReader::class)->forCompany('company-a'));
    }

    public function test_reader_filters_company_and_distinguishes_missing_company_from_zero_usage(): void
    {
        $connection = new SQLiteConnection(new PDO('sqlite::memory:'));
        $connection->statement("ATTACH DATABASE ':memory:' AS lead_admin");
        $connection->statement('CREATE TABLE lead_admin.company_usage (company_id TEXT, properties_count INTEGER, developments_count INTEGER, contacts_count INTEGER, inquiries_count INTEGER, referenced_users_count INTEGER, storage_bytes INTEGER)');
        $connection->table('lead_admin.company_usage')->insert([
            ['company_id' => 'company-a', 'properties_count' => 3, 'developments_count' => 1, 'contacts_count' => 8, 'inquiries_count' => 4, 'referenced_users_count' => 2, 'storage_bytes' => 1024],
            ['company_id' => 'company-b', 'properties_count' => 999, 'developments_count' => 999, 'contacts_count' => 999, 'inquiries_count' => 999, 'referenced_users_count' => 999, 'storage_bytes' => 999],
            ['company_id' => 'company-empty', 'properties_count' => 0, 'developments_count' => 0, 'contacts_count' => 0, 'inquiries_count' => 0, 'referenced_users_count' => 0, 'storage_bytes' => 0],
        ]);
        $connections = \Mockery::mock(LeadConnections::class);
        $connections->shouldReceive('metrics')->andReturnUsing(fn ($operation) => $operation($connection));
        $reader = new LeadUsageReader($connections);
        $result = $reader->forCompany('company-a');
        $this->assertTrue($result['available']);
        $this->assertSame(['properties_count' => 3, 'developments_count' => 1, 'contacts_count' => 8, 'inquiries_count' => 4, 'referenced_users_count' => 2, 'storage_bytes' => 1024], $result['metrics']);
        $this->assertSame(['available' => false, 'reason' => 'not_provisioned', 'metrics' => null], $reader->forCompany('missing'));
        $this->assertTrue($reader->forCompany('company-empty')['available']);
        $this->assertSame(0, $reader->forCompany('company-empty')['metrics']['properties_count']);
    }

    public function test_outage_has_no_fabricated_counts(): void
    {
        $connections = \Mockery::mock(LeadConnections::class);
        $connections->shouldReceive('metrics')->once()->andThrow(new LeadUnavailable);
        $result = (new LeadUsageReader($connections))->forCompany('company-a');
        $this->assertFalse($result['available']);
        $this->assertNull($result['metrics']);
    }
}
