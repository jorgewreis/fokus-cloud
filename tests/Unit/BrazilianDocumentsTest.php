<?php

namespace Tests\Unit;

use App\Support\BrazilianDocuments;
use PHPUnit\Framework\TestCase;

class BrazilianDocumentsTest extends TestCase
{
    public function test_validates_known_cpf_and_cnpj(): void
    {
        $this->assertTrue(BrazilianDocuments::cpf('111.444.777-35'));
        $this->assertTrue(BrazilianDocuments::cnpj('11.222.333/0001-81'));
        $this->assertTrue(BrazilianDocuments::cnpj('12.ABC.345/01DE-35'));
    }

    public function test_rejects_repeated_and_invalid_documents(): void
    {
        $this->assertFalse(BrazilianDocuments::cpf('111.111.111-11'));
        $this->assertFalse(BrazilianDocuments::cnpj('11.222.333/0001-80'));
        $this->assertFalse(BrazilianDocuments::cnpj('12.ABC.345/01DE-36'));
    }

    public function test_cpf_requires_eleven_ascii_digits_after_normalization(): void
    {
        foreach (['52998224725', '529.982.247-25'] as $cpf) {
            $this->assertTrue(BrazilianDocuments::cpf($cpf), $cpf);
        }
        foreach (['52998224724', '00000000000', '11111111111', '5299822472', '529982247250', 'AAAAAAAAA00', 'aaaaaaaaa00', 'AAA.AAA.AAA-00', '5299822472A', 'A52998224725'] as $cpf) {
            $this->assertFalse(BrazilianDocuments::cpf($cpf), $cpf);
        }
        $this->assertSame('12ABC34501DE35', BrazilianDocuments::digits('12.abc.345/01de-35'));
        $this->assertTrue(BrazilianDocuments::cnpj('12.abc.345/01de-35'));
    }
}
