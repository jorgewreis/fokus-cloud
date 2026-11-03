<?php

namespace App\Support;

use JsonException;
use RuntimeException;

final class FokusLawAssets
{
    private const ALLOWLIST = [
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
        'portal/assets/fokus-law-shell.js',
        'assets/js/portal-profile.js',
    ];

    private static ?array $manifest = null;

    public static function url(string $source): string
    {
        if (! in_array($source, self::ALLOWLIST, true)) {
            throw new RuntimeException('Fokus Law asset is not allowlisted.');
        }

        $manifest = self::$manifest ??= self::readManifest();
        $entry = $manifest['assets'][$source] ?? null;
        if (! is_array($entry)) {
            throw new RuntimeException('Fokus Law asset is missing from its generated manifest.');
        }

        $digest = $entry['sha256'] ?? null;
        $path = $entry['path'] ?? null;
        if (! is_string($digest) || ! preg_match('/\A[a-f0-9]{64}\z/', $digest) || ! is_string($path)) {
            throw new RuntimeException('Fokus Law asset manifest entry is invalid.');
        }

        $expectedPath = self::versionedPath($source, $digest);
        if ($path !== $expectedPath) {
            throw new RuntimeException('Fokus Law asset manifest path does not match its content digest.');
        }

        $absolutePath = public_path($path);
        if (! is_file($absolutePath) || hash_file('sha256', $absolutePath) !== $digest) {
            throw new RuntimeException('Fokus Law content-addressed asset is missing or has changed.');
        }

        return '/'.$path;
    }

    private static function readManifest(): array
    {
        $path = config_path('fokus-law-assets.json');
        if (! is_file($path)) {
            throw new RuntimeException('Fokus Law content-addressed asset manifest is missing.');
        }

        try {
            $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Fokus Law content-addressed asset manifest is invalid.', previous: $exception);
        }

        if (! is_array($manifest) || ($manifest['version'] ?? null) !== 1 || ! is_array($manifest['assets'] ?? null)) {
            throw new RuntimeException('Fokus Law content-addressed asset manifest has an unsupported format.');
        }

        return $manifest;
    }

    private static function versionedPath(string $source, string $digest): string
    {
        $directory = dirname($source);
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $basename = pathinfo($source, PATHINFO_FILENAME);
        $filename = $basename.'.'.$digest.'.'.$extension;

        return $directory === '.' ? $filename : $directory.'/'.$filename;
    }
}
