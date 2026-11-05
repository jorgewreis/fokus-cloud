<?php

namespace Tests\Unit;

use App\Services\LawDatajudClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LawDatajudClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.datajud.api_key' => 'test-key', 'services.datajud.base_url' => 'https://datajud.test', 'services.datajud.page_size' => 100]);
    }

    public function test_it_uses_all_records_in_a_page_and_flattens_nested_subjects(): void
    {
        Http::fake(['datajud.test/*' => Http::response(['timed_out' => false, '_shards' => ['failed' => 0], 'hits' => ['hits' => [
            ['_id' => 'one', '_source' => ['numeroProcesso' => '10016228320244013301', 'grau' => 'G1', '@timestamp' => '2025-01-01T00:00:00Z', 'assuntos' => [[['codigo' => 1, 'nome' => 'Assunto A']]], 'movimentos' => [['codigo' => 2, 'nome' => 'Movimento A', 'dataHora' => '2025-01-01T00:00:00Z']]]],
            ['_id' => 'two', '_source' => ['numeroProcesso' => '10016228320244013301', 'grau' => 'G2', '@timestamp' => '2025-02-01T00:00:00Z', 'assuntos' => [[['codigo' => 3, 'nome' => 'Assunto B']]], 'movimentos' => [['codigo' => 4, 'nome' => 'Movimento B', 'dataHora' => '2025-02-01T00:00:00Z']]]],
        ]]] )]);

        $result = app(LawDatajudClient::class)->lookup('1001622-83.2024.4.01.3301');

        $this->assertSame('synced', $result['status']);
        $this->assertCount(2, $result['metadata']['records']);
        $this->assertCount(2, $result['metadata']['subjects']);
        $this->assertCount(2, $result['metadata']['recent_movements']);
        $this->assertArrayNotHasKey('official_status_text', $result['metadata']);
    }

    public function test_it_rejects_partial_search_responses_without_applying_metadata(): void
    {
        Http::fake(['datajud.test/*' => Http::response(['timed_out' => true, 'hits' => ['hits' => []]])]);

        $result = app(LawDatajudClient::class)->lookup('1001622-83.2024.4.01.3301');

        $this->assertSame('error', $result['status']);
        $this->assertSame('source_timeout', $result['code']);
        $this->assertArrayNotHasKey('metadata', $result);
    }
}
