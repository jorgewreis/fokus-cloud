<?php

namespace Tests\Unit;

use App\Services\LawDatajudClient;
use Illuminate\Http\Client\Request;
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

    public function test_all_91_public_tribunal_endpoints_are_routed_by_cnj_number(): void
    {
        $states = ['01' => 'ac', '02' => 'al', '03' => 'ap', '04' => 'am', '05' => 'ba', '06' => 'ce', '07' => 'df', '08' => 'es', '09' => 'go', '10' => 'ma', '11' => 'mt', '12' => 'ms', '13' => 'mg', '14' => 'pa', '15' => 'pb', '16' => 'pr', '17' => 'pe', '18' => 'pi', '19' => 'rj', '20' => 'rn', '21' => 'rs', '22' => 'ro', '23' => 'rr', '24' => 'sc', '25' => 'se', '26' => 'sp', '27' => 'to'];
        $routes = [];
        foreach ($states as $code => $uf) {
            $routes[] = ['8', (string) $code, $code === '07' ? 'tjdft' : 'tj'.$uf];
            $routes[] = ['6', (string) $code, 'tre-'.($code === '07' ? 'dft' : $uf)];
        }
        for ($i = 1; $i <= 6; $i++) $routes[] = ['4', sprintf('%02d', $i), 'trf'.$i];
        for ($i = 1; $i <= 24; $i++) $routes[] = ['5', sprintf('%02d', $i), 'trt'.$i];
        array_push($routes, ['5', '00', 'tst'], ['6', '00', 'tse'], ['3', '00', 'stj'], ['7', '00', 'stm'], ['9', '13', 'tjmmg'], ['9', '21', 'tjmrs'], ['9', '26', 'tjmsp']);
        $this->assertCount(91, $routes);
        $urls = [];
        Http::fake(function (Request $request) use (&$urls) {
            $urls[] = $request->url();
            return Http::response(['hits' => ['hits' => []]]);
        });
        foreach ($routes as [$segment, $court, $alias]) {
            $this->assertSame('not_found', app(LawDatajudClient::class)->lookup('0000000000000'.$segment.$court.'0000')['status']);
            $this->assertSame('https://datajud.test/api_publica_'.$alias.'/_search', array_pop($urls));
        }
    }

    public function test_pagination_collects_all_returned_records(): void
    {
        config(['services.datajud.page_size' => 10]);
        $hits = [];
        for ($i = 1; $i <= 10; $i++) $hits[] = ['sort' => [$i], '_source' => ['numeroProcesso' => '10016228320244013301', 'grau' => 'G1']];
        Http::fake(function (Request $request) use ($hits) {
            return Http::response(['hits' => ['hits' => isset($request['search_after'])
                ? [['_source' => ['numeroProcesso' => '10016228320244013301', 'grau' => 'G2']]] : $hits]]);
        });
        $result = app(LawDatajudClient::class)->lookup('10016228320244013301');
        $this->assertSame('synced', $result['status']);
        $this->assertCount(11, $result['metadata']['records']);
        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => ($request['search_after'] ?? null) === [10]);
    }

    public function test_pagination_without_a_cursor_fails_without_returning_partial_metadata(): void
    {
        config(['services.datajud.page_size' => 10]);
        $hits = array_fill(0, 10, ['_source' => ['numeroProcesso' => '10016228320244013301', 'grau' => 'G1']]);
        Http::fake(['datajud.test/*' => Http::response(['hits' => ['hits' => $hits]])]);
        $result = app(LawDatajudClient::class)->lookup('10016228320244013301');
        $this->assertSame('error', $result['status']);
        $this->assertSame('invalid_response', $result['code']);
        $this->assertArrayNotHasKey('metadata', $result);
    }

    public function test_movement_only_results_are_preserved_and_truncation_is_explicit(): void
    {
        $movements = array_fill(0, 5001, ['nome' => 'Movimentação', 'dataHora' => '2026-01-01T12:00:00Z']);
        Http::fake(['datajud.test/*' => Http::response(['hits' => ['hits' => [['_source' => ['numeroProcesso' => '10016228320244013301', 'movimentos' => $movements]]]]])]);
        $result = app(LawDatajudClient::class)->lookup('10016228320244013301');
        $this->assertSame('synced', $result['status']);
        $this->assertCount(5000, $result['metadata']['recent_movements']);
        $this->assertTrue($result['metadata']['movements_truncated']);
    }
}
