<?php

namespace Tests\Unit;

use App\Services\LawCnjConfidentiality;
use PHPUnit\Framework\TestCase;

class LawCnjConfidentialityTest extends TestCase
{
    public function test_internal_code_ignores_source_and_preserves_legacy_literals(): void
    {
        foreach (['public', 'cnj_1', 'cnj_2', 'cnj_3', 'cnj_4', 'cnj_5', 'secret', 'confidential'] as $code) {
            $case = (object) ['internal_confidentiality_level' => $code, 'confidentiality_level' => 'public', 'source_confidentiality_level' => 'cnj_5'];
            $this->assertSame($code, LawCnjConfidentiality::internalCode($case));
            $this->assertSame(in_array($code, ['cnj_5', 'secret'], true), LawCnjConfidentiality::requiresNamedAccess($code));
            $this->assertSame($code, LawCnjConfidentiality::internalCode((object) ['confidentiality_level' => $code]));
        }
    }

    public function test_source_level_mapping_rejects_out_of_range_values(): void
    {
        $this->assertNull(LawCnjConfidentiality::codeForLevel(-1));
        $this->assertNull(LawCnjConfidentiality::codeForLevel(6));
        $this->assertSame('public', LawCnjConfidentiality::codeForLevel(0));
        for ($level = 1; $level <= 5; $level++) {
            $this->assertSame('cnj_'.$level, LawCnjConfidentiality::codeForLevel($level));
        }
    }
}
