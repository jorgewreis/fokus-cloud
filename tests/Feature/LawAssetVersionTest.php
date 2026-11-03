<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class LawAssetVersionTest extends TestCase
{
    public function test_contact_assets_are_versioned_from_their_current_content(): void
    {
        $template = file_get_contents(resource_path('views/portal/fokus-law.blade.php'));

        foreach (['portal/assets/fokus-law-contacts.js', 'portal/assets/fokus-law-bank-catalog.js', 'portal/assets/fokus-law-shell.css'] as $asset) {
            $lines = array_values(array_filter(
                explode("\n", $template),
                static fn (string $line): bool => str_contains($line, '/'.$asset.'?v=')
            ));

            $this->assertCount(1, $lines, 'Each asset must have one versioned reference.');
            $this->assertStringContainsString("hash_file('sha256'", $lines[0]);
            $this->assertStringContainsString("public_path('".$asset."')", $lines[0]);

            $rendered = Blade::render($lines[0]);
            $expectedVersion = substr(hash_file('sha256', public_path($asset)), 0, 16);
            $this->assertStringContainsString('/'.$asset.'?v='.$expectedVersion.'"', $rendered);
            $this->assertStringNotContainsString('{{', $rendered);
        }
    }
}
