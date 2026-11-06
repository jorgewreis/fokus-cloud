<?php

namespace App\Services;

class LawCnjConfidentiality
{
    public static function codeForLevel(int $level): ?string
    {
        if ($level < 0 || $level > 5) return null;
        return $level === 0 ? 'public' : 'cnj_'.$level;
    }

    public static function requiresNamedAccess(?string $code): bool
    {
        return $code === 'cnj_5';
    }

    public static function labelForCode(?string $code): string
    {
        return match ($code) {
            'public' => 'Público', 'cnj_1' => 'Segredo de Justiça', 'cnj_2' => 'Sigilo mínimo',
            'cnj_3' => 'Sigilo médio', 'cnj_4' => 'Sigilo intenso', 'cnj_5' => 'Sigilo absoluto',
            'secret' => 'Secreto (legado)', 'confidential' => 'Sigiloso (legado)', default => 'Nível não informado',
        };
    }

    /** @return list<array{level: int, label: string, description: string}> */
    public static function levels(): array
    {
        return [
            ['level' => 0, 'label' => 'Público', 'description' => 'Acessível a servidores do Judiciário e dos órgãos de colaboração na administração da Justiça, advogados e cidadãos.'],
            ['level' => 1, 'label' => 'Segredo de Justiça', 'description' => 'Acessível a servidores do Judiciário e dos órgãos de colaboração na administração da Justiça e às partes do processo.'],
            ['level' => 2, 'label' => 'Sigilo mínimo', 'description' => 'Acessível a servidores do Judiciário e dos demais órgãos de colaboração na administração da Justiça.'],
            ['level' => 3, 'label' => 'Sigilo médio', 'description' => 'Acessível a servidores do órgão em que tramita o processo, às partes que provocaram o incidente e às pessoas expressamente incluídas.'],
            ['level' => 4, 'label' => 'Sigilo intenso', 'description' => 'Acessível a servidores qualificados do órgão em que tramita o processo, às partes que provocaram o incidente e às pessoas expressamente incluídas.'],
            ['level' => 5, 'label' => 'Sigilo absoluto', 'description' => 'Acessível ao magistrado, aos servidores e demais usuários por ele indicados e às partes que provocaram o incidente.'],
        ];
    }
}
