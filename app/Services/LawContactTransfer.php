<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/** Reconstructed, bounded interchange format. Not a general PDF parser. */
final class LawContactTransfer
{
    public const MAX_BYTES = 2097152;

    public const MAX_ROWS = 500;

    public const COLUMNS = ['display_name', 'legal_nature', 'legal_name', 'cpf', 'cnpj', 'email', 'phone'];

    public function decode(string $format, string $encoded): array
    {
        if (strlen($encoded) > 2796204 || ($bytes = base64_decode($encoded, true)) === false || strlen($bytes) > self::MAX_BYTES || $bytes === '') {
            $this->fail('Arquivo inválido ou maior que 2 MiB.');
        }
        if ($format === 'pdf') {
            if (! preg_match('/\A%PDF-1\.4\n%FOKUS-CONTACTS-1\n.*\n%FOKUS-DATA:([A-Za-z0-9+\/=]+)\n%%EOF\n\z/sD', $bytes, $matches)) {
                $this->fail('Importe somente o PDF exportado pelo Fokus, versão 1.');
            }
            $csv = base64_decode($matches[1], true);
            if ($csv === false) {
                $this->fail('PDF Fokus inválido.');
            }
            // Canonical byte comparison rejects modified, arbitrary, compressed and active PDF content.
            $rows = $this->parseCsv($csv);
            if (! hash_equals($this->pdf($rows), $bytes)) {
                $this->fail('PDF Fokus modificado ou inválido.');
            }

            return $rows;
        }
        if ($format !== 'csv') {
            $this->fail('Formato não suportado.');
        }

        return $this->parseCsv($bytes);
    }

    public function csv(array $rows): string
    {
        $stream = fopen('php://temp', 'w+');
        fputcsv($stream, self::COLUMNS, ',', '"', '', "\n");
        foreach ($rows as $row) {
            $values = array_map(function ($key) use ($row): string {
                $value = (string) ($row[$key] ?? '');

                // Escape a leading apostrophe too, making formula shielding reversible.
                return preg_match('/^(?:[\s]*[=+@-]|[\t\r\n]|\')/u', $value) ? "'".$value : $value;
            }, self::COLUMNS);
            fputcsv($stream, $values, ',', '"', '', "\n");
        }
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        return $csv;
    }

    public function pdf(array $rows): string
    {
        $csv = $this->csv($rows);
        $lines = ['Fokus - Contatos (formato de intercambio v1)', 'Registros: '.count($rows)];
        foreach ($rows as $row) {
            $lines[] = ($row['display_name'] ?? '').' | '.($row['legal_nature'] ?? '');
        }
        $pages = array_chunk($lines, 45);
        $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>', 2 => '', 3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>'];
        $kids = [];
        foreach ($pages as $pageLines) {
            $id = count($objects) + 1;
            $contentId = $id + 1;
            $kids[] = "$id 0 R";
            $content = "BT /F1 10 Tf 40 800 Td 16 TL\n";
            foreach ($pageLines as $line) {
                $line = iconv('UTF-8', 'Windows-1252//TRANSLIT', $line) ?: '?';
                $line = str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $line);
                $content .= '('.$line.") Tj T*\n";
            }
            $content .= 'ET';
            $objects[$id] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents $contentId 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($content).">>\nstream\n".$content."\nendstream";
        }
        $objects[2] = '<< /Type /Pages /Count '.count($kids).' /Kids ['.implode(' ', $kids).'] >>';
        $pdf = "%PDF-1.4\n%FOKUS-CONTACTS-1\n";
        $offsets = [0];
        foreach ($objects as $id => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= "$id 0 obj\n$object\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf.'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n$xref\n%FOKUS-DATA:".base64_encode($csv)."\n%%EOF\n";
    }

    private function parseCsv(string $csv): array
    {
        if (strlen($csv) > self::MAX_BYTES || ! mb_check_encoding($csv, 'UTF-8') || str_contains($csv, "\0")) {
            $this->fail('CSV deve ser UTF-8 válido.');
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $first = strtok($csv, "\r\n") ?: '';
        $delimiter = str_contains($first, ';') ? ';' : ',';
        $state = 'start';
        for ($i = 0, $length = strlen($csv); $i < $length; $i++) {
            $char = $csv[$i];
            if ($state === 'quoted') {
                if ($char === '"') {
                    if (($csv[$i + 1] ?? '') === '"') {
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                }
            } elseif ($char === $delimiter || $char === "\r" || $char === "\n") {
                $state = 'start';
            } elseif ($state === 'closed' || ($char === '"' && $state !== 'start')) {
                $this->fail('Aspas CSV inválidas.');
            } elseif ($char === '"') {
                $state = 'quoted';
            } else {
                $state = 'plain';
            }
        }
        if ($state === 'quoted') {
            $this->fail('Aspas CSV não encerradas.');
        }
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        try {
            $header = fgetcsv($stream, null, $delimiter, '"', '');
            if ($header !== self::COLUMNS) {
                $this->fail('Cabeçalho CSV inválido. Use o modelo exportado.');
            }
            $rows = [];
            while (($values = fgetcsv($stream, null, $delimiter, '"', '')) !== false) {
                if ($values === [null]) {
                    continue;
                }
                if (count($values) !== count(self::COLUMNS) || count($rows) >= self::MAX_ROWS) {
                    $this->fail('CSV inválido ou excede 500 linhas.');
                }
                $values = array_map(function ($value): string {
                    $value = (string) $value;
                    if (strlen($value) > 4096) {
                        $this->fail('Campo CSV muito longo.');
                    }
                    if (str_starts_with($value, "'") && preg_match('/^(?:[\s]*[=+@-]|[\t\r\n]|\')/u', substr($value, 1))) {
                        return substr($value, 1);
                    }

                    return $value;
                }, $values);
                $rows[] = array_combine(self::COLUMNS, $values);
            }
            if (! $rows) {
                $this->fail('Nenhum contato no arquivo.');
            }

            return $rows;
        } finally {
            fclose($stream);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['content_base64' => $message]);
    }
}
