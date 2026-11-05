<?php

namespace Tests\Unit;

use App\Services\LawDjenClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LawDjenClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['services.djen.base_url' => 'https://djen.test/api/v1', 'services.djen.timeout' => 10]);
    }

    public function test_it_reads_every_page_and_keeps_availability_and_publication_dates_separate(): void
    {
        $pages = [1 => [], 2 => []];
        for ($i = 1; $i <= 51; $i++) {
            $pages[$i <= 50 ? 1 : 2][] = [
                'id' => $i, 'hash' => 'hash-'.$i, 'numeroProcesso' => '10016228320244013301',
                'dataDisponibilizacao' => '2026-11-01', 'dataPublicacao' => '2026-11-03',
                'tipoComunicacao' => 'Intimação', 'siglaTribunal' => 'TJBA',
            ];
        }
        Http::fake(function (Request $request) use ($pages) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $page = (int) ($query['pagina'] ?? 1);
            return Http::response(['count' => 51, 'items' => $pages[$page] ?? []]);
        });

        $result = app(LawDjenClient::class)->lookup('1001622-83.2024.4.01.3301');

        $this->assertSame('synced', $result['status']);
        $this->assertCount(51, $result['metadata']['publications']);
        $this->assertSame('2026-11-01', $result['metadata']['publications'][0]['availability_date']);
        $this->assertSame('2026-11-03', $result['metadata']['publications'][0]['publication_date']);
        $this->assertTrue($result['metadata']['complete']);
        Http::assertSentCount(2);
    }

    public function test_unexpected_empty_payload_is_an_error_and_not_a_successful_empty_result(): void
    {
        Http::fake(['djen.test/*' => Http::response(['data' => []])]);

        $result = app(LawDjenClient::class)->lookup('1001622-83.2024.4.01.3301');

        $this->assertSame('error', $result['status']);
        $this->assertSame('invalid_response', $result['code']);
        $this->assertArrayNotHasKey('publications', $result['metadata']);
    }

    public function test_a_confirmed_empty_result_is_distinct_from_an_invalid_response(): void
    {
        Http::fake(['djen.test/*' => Http::response(['count' => 0, 'items' => []])]);

        $result = app(LawDjenClient::class)->lookup('1001622-83.2024.4.01.3301');

        $this->assertSame('not_found', $result['status']);
        $this->assertSame([], $result['metadata']['publications']);
        $this->assertTrue($result['metadata']['complete']);
    }
}
