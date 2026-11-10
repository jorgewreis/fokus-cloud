<?php

namespace Tests\Unit;

use App\Services\LawCaseWorkflow;
use PHPUnit\Framework\TestCase;

class LawCaseWorkflowTest extends TestCase
{
    public function test_publication_identity_distinguishes_hashes_text_and_versions(): void
    {
        $first = ['id' => 'same', 'hash' => 'A', 'excerpt' => 'same'];
        $this->assertNotSame(LawCaseWorkflow::publicationKey($first), LawCaseWorkflow::publicationKey(array_replace($first, ['hash' => 'B'])));
        $this->assertNotSame(LawCaseWorkflow::publicationKey($first), LawCaseWorkflow::publicationKey(array_replace($first, ['excerpt' => 'changed'])));
        $this->assertSame(LawCaseWorkflow::legacyPublicationKey(['id' => 'same']), LawCaseWorkflow::publicationKey(['id' => 'same', 'hash' => '']));
    }

    public function test_latest_movement_rejects_invalid_dates_and_compares_utc_instants(): void
    {
        $this->assertSame('2026-11-04 14:00:00', LawCaseWorkflow::latestOfficialMovement(['recent_movements' => [
            ['occurred_at' => '2026-11-04T12:30:00Z'], ['occurred_at' => '2026-11-04T11:00:00-03:00'],
            ['occurred_at' => '2099-02-31T00:00:00Z'], ['occurred_at' => 'tomorrow'],
        ]]));
    }

    public function test_source_retry_schedule_does_not_invent_pending_times(): void
    {
        $this->assertNull(LawCaseWorkflow::nextAttempt((object) ['djen_sync_status' => 'pending'], 'djen'));
        $this->assertSame('2026-11-06T18:00:00+00:00', LawCaseWorkflow::nextAttempt((object) ['djen_sync_status' => 'error', 'djen_retryable' => true, 'djen_retry_count' => 2, 'last_djen_checked_at' => '2026-11-06 12:00:00'], 'djen'));
        $this->assertSame('2026-12-06T12:00:00+00:00', LawCaseWorkflow::nextAttempt((object) ['datajud_sync_status' => 'synced', 'last_datajud_synced_at' => '2026-11-06 12:00:00'], 'datajud'));
    }

    public function test_fingerprint_is_versioned_and_never_inferred_from_legacy_or_invalid_values(): void
    {
        $legacy = ['id' => 'item', 'excerpt' => 'Resumo legado', 'hash' => 'provider'];
        $this->assertFalse(LawCaseWorkflow::hasContentFingerprint($legacy));
        $current = $legacy + ['content_fingerprint_version' => 1, 'content_fingerprint' => hash('sha256', 'Texto integral')];
        $this->assertTrue(LawCaseWorkflow::hasContentFingerprint($current));
        $this->assertNotSame(LawCaseWorkflow::publicationKey($legacy), LawCaseWorkflow::publicationKey($current));
        foreach ([null, '', 'invalid', str_repeat('a', 63), ['bad']] as $fingerprint) {
            $invalid = array_replace($current, ['content_fingerprint' => $fingerprint]);
            $this->assertFalse(LawCaseWorkflow::hasContentFingerprint($invalid));
            $this->assertNotSame(LawCaseWorkflow::publicationKey($legacy), LawCaseWorkflow::publicationKey($invalid));
            $this->assertNotSame(LawCaseWorkflow::publicationKey($current), LawCaseWorkflow::publicationKey($invalid));
        }
        $this->assertFalse(LawCaseWorkflow::hasContentFingerprint(array_replace($current, ['content_fingerprint_version' => 2])));
        $this->assertSame(LawCaseWorkflow::publicationKey($current), LawCaseWorkflow::publicationKey(array_replace($current, ['excerpt' => 'Outro resumo de apresentação'])));
    }
}
