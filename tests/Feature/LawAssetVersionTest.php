<?php

namespace Tests\Feature;

use App\Support\FokusLawAssets;
use Illuminate\Support\Facades\Blade;
use RuntimeException;
use Tests\TestCase;

class LawAssetVersionTest extends TestCase
{
    private const ASSETS = [
        'assets/css/shared/fokus.css',
        'marketing/products/fokus-law.css',
        'portal/assets/fokus-law-shell.css',
        'assets/js/fokus.min.js',
        'assets/js/api-client.js',
        'assets/js/brazilian-documents.js',
        'portal/assets/fokus-law-select.js',
        'portal/assets/fokus-law-access.js',
        'portal/assets/fokus-law-bank-catalog.js',
        'portal/assets/fokus-law-contacts.js',
        'portal/assets/law-record-ui.js',
        'portal/assets/fokus-law-processes.js',
        'portal/assets/fokus-law-tasks.js',
        'portal/assets/fokus-law-shell.js',
        'assets/js/portal-profile.js',
    ];

    public function test_all_shell_assets_use_the_deterministic_content_manifest(): void
    {
        $manifestPath = config_path('fokus-law-assets.json');
        $this->assertFileExists($manifestPath, 'Run npm run law:assets:prepare before serving Fokus Law.');
        $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $manifest['version'] ?? null);
        $this->assertSame('sha256', $manifest['algorithm'] ?? null);
        $this->assertSame(self::ASSETS, array_keys($manifest['assets'] ?? []));

        $template = file_get_contents(resource_path('views/portal/fokus-law.blade.php'));
        foreach (self::ASSETS as $source) {
            $reference = "FokusLawAssets::url('{$source}')";
            $this->assertSame(1, substr_count($template, $reference), "Expected one Blade reference for {$source}.");

            $entry = $manifest['assets'][$source];
            $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $entry['sha256']);
            $this->assertSame($entry['sha256'], hash_file('sha256', public_path($source)), "Manifest digest must match original asset {$source}.");
            $expectedPath = $this->versionedPath($source, $entry['sha256']);
            $this->assertSame($expectedPath, $entry['path']);
            $this->assertFileExists(public_path($expectedPath));
            $this->assertSame($entry['sha256'], hash_file('sha256', public_path($expectedPath)));

            $rendered = Blade::render('<script src="{{ \\App\\Support\\FokusLawAssets::url(\''.$source.'\') }}"></script>');
            $this->assertStringContainsString('src="/'.$expectedPath.'"', $rendered);
            $this->assertStringNotContainsString('?v=', $rendered);
        }
    }

    public function test_missing_content_addressed_asset_fails_closed(): void
    {
        $source = 'portal/assets/fokus-law-contacts.js';
        $manifest = json_decode((string) file_get_contents(config_path('fokus-law-assets.json')), true, 512, JSON_THROW_ON_ERROR);
        $target = public_path($manifest['assets'][$source]['path']);
        $quarantined = $target.'.temporarily-missing-test';
        $this->assertFileExists($target);

        if (! rename($target, $quarantined)) {
            $this->fail('Could not temporarily quarantine a generated test asset.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Fokus Law content-addressed asset is missing or has changed.');
            FokusLawAssets::url($source);
        } finally {
            if (is_file($quarantined)) {
                rename($quarantined, $target);
            }
        }
    }

    private function versionedPath(string $source, string $digest): string
    {
        $directory = dirname($source);
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $basename = pathinfo($source, PATHINFO_FILENAME);
        $filename = $basename.'.'.$digest.'.'.$extension;

        return $directory === '.' ? $filename : $directory.'/'.$filename;
    }
}
