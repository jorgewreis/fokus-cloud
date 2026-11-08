<?php

namespace Tests\Unit\Deployment;

use App\Support\Deployment\ReleaseIdentity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReleaseIdentityTest extends TestCase
{
    public function test_missing_identity_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new ReleaseIdentity([], '/nonexistent'))->verifiedRelease();
    }

    public function test_invalid_release_is_rejected_without_echoing_input(): void
    {
        try {
            (new ReleaseIdentity(['release' => 'secret'], '/nonexistent'))->verifiedRelease();
            $this->fail('Invalid release accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('release_unavailable', $error->getMessage());
        }
    }
}
