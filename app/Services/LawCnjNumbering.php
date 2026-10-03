<?php

namespace App\Services;

/** CNJ Resolution 65/2008 segment and court codes for process numbering. */
final class LawCnjNumbering
{
    public static function segments(): array
    {
        return [
            ['code' => '1', 'name' => 'Supremo Tribunal Federal'],
            ['code' => '2', 'name' => 'Conselho Nacional de Justiça'],
            ['code' => '3', 'name' => 'Superior Tribunal de Justiça'],
            ['code' => '4', 'name' => 'Justiça Federal'],
            ['code' => '5', 'name' => 'Justiça do Trabalho'],
            ['code' => '6', 'name' => 'Justiça Eleitoral'],
            ['code' => '7', 'name' => 'Justiça Militar da União'],
            ['code' => '8', 'name' => 'Justiça dos Estados e do Distrito Federal e Territórios'],
            ['code' => '9', 'name' => 'Justiça Militar Estadual'],
        ];
    }

    public static function courts(): array
    {
        $courts = [
            '1' => [['00', 'Supremo Tribunal Federal']],
            '2' => [['00', 'Conselho Nacional de Justiça']],
            '3' => [['00', 'Superior Tribunal de Justiça']],
            '4' => array_merge(
                array_map(fn (int $number): array => [str_pad((string) $number, 2, '0', STR_PAD_LEFT), "Tribunal Regional Federal da {$number}ª Região (TRF{$number})"], range(1, 6)),
                [['90', 'Conselho da Justiça Federal (CJF)']],
            ),
            '5' => array_merge(
                [['00', 'Tribunal Superior do Trabalho (TST)']],
                array_map(fn (int $number): array => [str_pad((string) $number, 2, '0', STR_PAD_LEFT), "Tribunal Regional do Trabalho da {$number}ª Região (TRT{$number})"], range(1, 24)),
                [['90', 'Conselho Superior da Justiça do Trabalho (CSJT)']],
            ),
            '6' => array_merge(
                [['00', 'Tribunal Superior Eleitoral (TSE)']],
                self::stateCourts('Tribunal Regional Eleitoral', 'TRE'),
            ),
            '7' => array_merge(
                [['00', 'Superior Tribunal Militar (STM)']],
                array_map(fn (int $number): array => [str_pad((string) $number, 2, '0', STR_PAD_LEFT), "{$number}ª Circunscrição Judiciária Militar"], range(1, 12)),
            ),
            '8' => self::stateCourts('Tribunal de Justiça', 'TJ'),
            '9' => [
                ['13', 'Tribunal de Justiça Militar do Estado de Minas Gerais (TJMMG)'],
                ['21', 'Tribunal de Justiça Militar do Estado do Rio Grande do Sul (TJMRS)'],
                ['26', 'Tribunal de Justiça Militar do Estado de São Paulo (TJMSP)'],
            ],
        ];

        $result = [];
        foreach ($courts as $segment => $items) {
            foreach ($items as [$code, $name]) $result[] = ['segment' => (string) $segment, 'code' => $code, 'name' => $name];
        }
        return $result;
    }

    public static function isValidCourt(string $segment, string $court): bool
    {
        foreach (self::courts() as $item) {
            if ($item['segment'] === $segment && $item['code'] === $court) return true;
        }
        return false;
    }

    private static function stateCourts(string $institution, string $acronym): array
    {
        $states = [
            ['AC', 'Acre'], ['AL', 'Alagoas'], ['AP', 'Amapá'], ['AM', 'Amazonas'], ['BA', 'Bahia'], ['CE', 'Ceará'],
            ['DF', 'Distrito Federal'], ['ES', 'Espírito Santo'], ['GO', 'Goiás'], ['MA', 'Maranhão'], ['MT', 'Mato Grosso'],
            ['MS', 'Mato Grosso do Sul'], ['MG', 'Minas Gerais'], ['PA', 'Pará'], ['PB', 'Paraíba'], ['PR', 'Paraná'],
            ['PE', 'Pernambuco'], ['PI', 'Piauí'], ['RJ', 'Rio de Janeiro'], ['RN', 'Rio Grande do Norte'],
            ['RS', 'Rio Grande do Sul'], ['RO', 'Rondônia'], ['RR', 'Roraima'], ['SC', 'Santa Catarina'],
            ['SP', 'São Paulo'], ['SE', 'Sergipe'], ['TO', 'Tocantins'],
        ];

        return array_map(function (array $state, int $index) use ($institution, $acronym): array {
            $code = str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT);
            $shortName = $state[0] === 'DF' ? ($acronym === 'TJ' ? 'TJDFT' : 'TRE-DFT') : ($acronym === 'TJ' ? "TJ{$state[0]}" : "TRE-{$state[0]}");
            return [$code, "{$institution} do {$state[1]} ({$shortName})"];
        }, $states, array_keys($states));
    }
}
