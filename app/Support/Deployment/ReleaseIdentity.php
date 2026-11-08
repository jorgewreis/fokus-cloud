<?php

namespace App\Support\Deployment;

use RuntimeException;

/** A consistency check of a sealed, uniquely addressed release, not fleet attestation. */
final class ReleaseIdentity
{
    public function __construct(private readonly array $identity, private readonly string $root) {}

    public function verifiedRelease(): string
    {
        $lock = @fopen($this->root.'/.release/verify.lock', 'r');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }

            throw new RuntimeException('release_unavailable');
        }

        try {
            return $this->verifySnapshot();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function verifySnapshot(): string
    {
        // This protocol does not attest cached opcodes or long-lived application servers.
        if (filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOL)
            && (PHP_SAPI !== 'cli' && PHP_SAPI !== 'cli-server'
                || filter_var(ini_get('opcache.enable_cli'), FILTER_VALIDATE_BOOL))) {
            throw new RuntimeException('release_unavailable');
        }

        if ((PHP_SAPI === 'cli' && isset($_SERVER['REQUEST_METHOD'])) || ! in_array(PHP_SAPI, ['fpm-fcgi', 'apache2handler', 'cli-server', 'cli'], true)) {
            throw new RuntimeException('release_unavailable');
        }

        $release = $this->identity['release'] ?? null;
        $digest = $this->identity['manifest'] ?? null;
        if (! is_string($release) || preg_match('/\A[0-9a-f]{40}\z/D', $release) !== 1
            || ! is_string($digest) || preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1
            || ($this->identity['root'] ?? null) !== $this->root
            || realpath($this->root) !== $this->root || basename($this->root) !== $release) {
            throw new RuntimeException('release_unavailable');
        }

        $manifestPath = $this->root.'/.release/manifest.json';
        $bytes = @file_get_contents($manifestPath, false, null, 0, 4 * 1024 * 1024 + 1);
        if (! is_string($bytes) || strlen($bytes) > 4 * 1024 * 1024 || ! hash_equals($digest, hash('sha256', $bytes))) {
            throw new RuntimeException('release_unavailable');
        }
        $manifest = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($manifest) || ($manifest['release'] ?? null) !== $release
            || ($manifest['root'] ?? null) !== $this->root || ! is_array($manifest['files'] ?? null)
            || $manifest['files'] === [] || count($manifest['files']) > 20000) {
            throw new RuntimeException('release_unavailable');
        }

        $deadline = microtime(true) + 2;
        $totalBytes = 0;
        foreach ($manifest['files'] as $path => $hash) {
            $size = @filesize($this->root.'/'.$path);
            $totalBytes += is_int($size) ? $size : 0;
            if ($totalBytes > 128 * 1024 * 1024 || microtime(true) > $deadline) {
                throw new RuntimeException('release_unavailable');
            }
            if (! is_string($path) || preg_match('~\A(?:app|bootstrap|config|routes|vendor|public)/[A-Za-z0-9_. /@+,-]+\z~D', $path) !== 1
                || str_contains($path, '..') || ! is_string($hash)
                || preg_match('/\A[0-9a-f]{64}\z/D', $hash) !== 1
                || realpath($this->root.'/'.$path) !== $this->root.'/'.$path
                || ! hash_equals($hash, @hash_file('sha256', $this->root.'/'.$path) ?: '')) {
                throw new RuntimeException('release_unavailable');
            }
        }

        // New executable files and changed/deleted caches must not escape the manifest.
        $actual = [];
        foreach (['app', 'bootstrap', 'config', 'routes', 'vendor', 'public'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $this->root.'/'.$directory, \FilesystemIterator::SKIP_DOTS,
            ));
            foreach ($iterator as $file) {
                if (count($actual) > 20000 || microtime(true) > $deadline) {
                    throw new RuntimeException('release_unavailable');
                }
                $path = substr($file->getPathname(), strlen($this->root) + 1);
                if ($path === 'public/storage' && $file->isLink()) {
                    continue;
                }
                if ($file->isLink()) {
                    throw new RuntimeException('release_unavailable');
                }
                if ($file->isFile() && ($directory !== 'public' || $file->getExtension() === 'php')) {
                    $actual[] = $path;
                }
            }
        }
        $expected = array_keys($manifest['files']);
        sort($actual);
        sort($expected);
        if ($actual !== $expected) {
            throw new RuntimeException('release_unavailable');
        }

        return $release;
    }
}
