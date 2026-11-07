<?php

namespace Tests\Unit;

use App\Services\LawContactTransfer;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LawContactTransferTest extends TestCase
{
    private function row(string $name = 'Contato sintético'): array
    {
        return array_replace(array_fill_keys(LawContactTransfer::COLUMNS, ''), ['display_name' => $name, 'legal_nature' => 'pf']);
    }

    public function test_csv_round_trip_unicode_quotes_newlines_and_formula_prefixes(): void
    {
        $service = new LawContactTransfer;
        $rows = array_map(fn ($name) => $this->row($name), ['João "Teste", Silva', "Maria\nSintética", '=1+1', '+123', '-123', '@SUM(A1)', "'literal", "\t=1", '  =1']);
        $csv = $service->csv($rows);
        $this->assertStringContainsString("'=1+1", $csv);
        $this->assertSame($rows, $service->decode('csv', base64_encode($csv)));
        $this->assertSame($rows, $service->decode('pdf', base64_encode($service->pdf($rows))));
    }

    public function test_bom_and_semicolon_are_supported(): void
    {
        $csv = "\xEF\xBB\xBF".implode(';', LawContactTransfer::COLUMNS)."\r\nPessoa Sintética;pf;;;;;\r\n";
        $this->assertSame([$this->row('Pessoa Sintética')], (new LawContactTransfer)->decode('csv', base64_encode($csv)));
    }

    public function test_rejects_malformed_encoded_csv_pdf_and_archive_inputs(): void
    {
        $service = new LawContactTransfer;
        $header = implode(',', LawContactTransfer::COLUMNS)."\n";
        $inputs = [['csv', '%%%'], ['csv', base64_encode($header.'"unterminated,pf,,,,,')], ['csv', base64_encode($header.'Na"me,pf,,,,,')], ['csv', base64_encode($header."Name,pf,,,,,extra,extra\n")], ['csv', base64_encode("\xFF")], ['csv', base64_encode($header)], ['pdf', base64_encode('%PDF-1.4 arbitrary')], ['pdf', base64_encode("PK\x03\x04")], ['zip', base64_encode('archive')], ['csv', base64_encode(str_repeat('x', LawContactTransfer::MAX_BYTES + 1))], ['csv', base64_encode($header.str_repeat("Name,pf,,,,,\n", 501))]];
        foreach ($inputs as [$format, $encoded]) {
            try {
                $service->decode($format, $encoded);
                $this->fail('Invalid file accepted');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('content_base64', $exception->errors());
            }
        }
        $pdf = str_replace('Helvetica', 'CourierXX', $service->pdf([$this->row()]));
        $this->expectException(ValidationException::class);
        $service->decode('pdf', base64_encode($pdf));
    }

    public function test_accepts_exact_row_limit_without_compression(): void
    {
        $service = new LawContactTransfer;
        $rows = array_fill(0, 500, $this->row());
        $this->assertCount(500, $service->decode('csv', base64_encode($service->csv($rows))));
        $this->assertCount(500, $service->decode('pdf', base64_encode($service->pdf($rows))));
    }
}
