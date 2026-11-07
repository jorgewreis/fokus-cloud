<?php

namespace Tests\Unit;

use App\Services\LawDjenClient;
use App\Support\LawDjenClassName;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class LawDjenClientTest extends TestCase
{
    public function test_djen_class_names_follow_the_cnj_catalog_and_existing_metadata_is_presented_consistently(): void
    {
        $raw = 'AçãO PENAL - PROCEDIMENTO ORDINáRIO';
        $metadata = ['suggested_case_class' => $raw, 'process_data' => ['case_class' => $raw], 'publications' => [['case_class' => $raw, 'id' => '1']], 'complete' => true];
        $presented = LawDjenClassName::metadata($metadata);
        $expected = 'Ação Penal - Procedimento Ordinário';
        $this->assertSame($expected, $presented['suggested_case_class']);
        $this->assertSame($expected, $presented['process_data']['case_class']);
        $this->assertSame($expected, $presented['publications'][0]['case_class']);
        $this->assertSame($raw, $metadata['suggested_case_class']);
        $this->assertSame('Classe DJEN', LawDjenClassName::format('Classe DJEN'));
        $this->assertSame('Procedimento Especial do TJBA', LawDjenClassName::format('PROCEDIMENTO ESPECIAL DO TJBA'));
        $this->assertNull(LawDjenClassName::format(null));
    }

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

    public function test_it_rejects_a_publication_for_a_different_process(): void
    {
        Http::fake(['djen.test/*' => Http::response(['count' => 1, 'items' => [['id' => 1, 'numeroProcesso' => '00000000000000000000']]])]);
        $result = app(LawDjenClient::class)->lookup('10016228320244013301');
        $this->assertSame('error', $result['status']);
        $this->assertSame('invalid_result', $result['code']);
        $this->assertArrayNotHasKey('publications', $result['metadata']);
    }

    public function test_it_does_not_return_a_partial_list_when_a_later_page_fails(): void
    {
        $first = array_map(fn ($i) => ['id' => $i, 'numeroProcesso' => '10016228320244013301'], range(1, 50));
        Http::fake(function (Request $request) use ($first) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            return (int) ($query['pagina'] ?? 1) === 1 ? Http::response(['count' => 51, 'items' => $first]) : Http::response([], 503);
        });
        $result = app(LawDjenClient::class)->lookup('10016228320244013301');
        $this->assertSame('error', $result['status']);
        $this->assertSame('service_unavailable', $result['code']);
        $this->assertArrayNotHasKey('publications', $result['metadata']);
        Http::assertSentCount(2);
    }

    public function test_full_content_identity_keeps_divergent_versions_with_same_id_and_excerpt(): void
    {
        foreach ([null, 'stable', 'changed'] as $providerHash) {
            $base = ['id' => 'same-id', 'numeroProcesso' => '10016228320244013301'];
            $original = $base + ['texto' => str_repeat('á', 1100).' versão original', 'hash' => $providerHash === null ? null : 'stable'];
            $changed = $base + ['texto' => str_repeat('á', 1100).' versão corrigida', 'hash' => $providerHash];
            Http::fake(['djen.test/*' => Http::response(['count' => 3, 'items' => [$original, $changed, $original]])]);
            $rows = app(LawDjenClient::class)->lookup('10016228320244013301')['metadata']['publications'];
            $this->assertCount(2, $rows);
            $this->assertSame($rows[0]['excerpt'], $rows[1]['excerpt']);
            $this->assertSame(1000, mb_strlen($rows[0]['excerpt']));
            $this->assertNotSame($rows[0]['content_fingerprint'], $rows[1]['content_fingerprint']);
            $this->assertNotSame(\App\Services\LawCaseWorkflow::publicationKey($rows[0]), \App\Services\LawCaseWorkflow::publicationKey($rows[1]));
            $this->assertSame(1, $rows[0]['content_fingerprint_version']);
            $this->assertArrayNotHasKey('texto', $rows[0]);
            $this->assertStringNotContainsString('versão original', json_encode($rows));
        }
    }

    public function test_html_entities_whitespace_and_canonical_unicode_share_identity(): void
    {
        $base = ['id' => 'same-id', 'numeroProcesso' => '10016228320244013301'];
        Http::fake(['djen.test/*' => Http::response(['count' => 3, 'items' => [
            $base + ['texto' => '<p>Intimação&nbsp; <b>ação</b></p><p>A &amp; B</p>'],
            $base + ['texto' => " Intimação ação\n A & B "],
            $base + ['texto' => "Intimação ação A & B"],
        ]])]);
        $rows = app(LawDjenClient::class)->lookup('10016228320244013301')['metadata']['publications'];
        $this->assertCount(1, $rows);
        $this->assertSame(hash('sha256', 'Intimação ação A & B'), $rows[0]['content_fingerprint']);
    }

    public function test_missing_empty_or_invalid_text_never_gets_a_content_fingerprint(): void
    {
        $normalize = new \ReflectionMethod(LawDjenClient::class, 'normalizePublication');
        foreach ([null, '', ' <p>&nbsp;</p> ', ['invalid'], "\xFF"] as $text) {
            $row = $normalize->invoke(new LawDjenClient, ['id' => 'item', 'texto' => $text, 'hash' => 'provider']);
            $this->assertNull($row['content_fingerprint']);
            $this->assertNull($row['excerpt']);
            $this->assertFalse(\App\Services\LawCaseWorkflow::hasContentFingerprint($row));
        }
    }

    public function test_descriptive_only_items_are_rejected_without_silently_merging_different_courts(): void
    {
        $base = ['numeroProcesso' => '10016228320244013301', 'tipoComunicacao' => 'Intimação', 'siglaTribunal' => 'TJBA'];
        foreach ([
            [$base + ['nomeOrgao' => 'Vara A'], $base + ['nomeOrgao' => 'Vara B']],
            [$base + ['dataPublicacao' => '2026-11-06']],
        ] as $items) {
            Http::fake(['djen.test/*' => Http::response(['count' => count($items), 'items' => $items])]);
            $result = app(LawDjenClient::class)->lookup('10016228320244013301');
            $this->assertSame('error', $result['status']);
            $this->assertSame('invalid_result', $result['code']);
            $this->assertArrayNotHasKey('publications', $result['metadata']);
        }
    }
}
