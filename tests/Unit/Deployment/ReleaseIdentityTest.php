<?php

namespace Tests\Unit\Deployment;

use App\Support\Deployment\ReleaseIdentity;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class ReleaseIdentityTest extends TestCase
{
    private string $root;

    private array $identity;

    protected function setUp(): void
    {
        parent::setUp();
        $release = str_repeat('a', 40);
        $this->root = sys_get_temp_dir().'/release-unit-'.bin2hex(random_bytes(8)).'/'.$release;
        mkdir($this->root.'/.release', 0777, true);
        touch($this->root.'/.release/verify.lock');
        $files = [];
        foreach (['app', 'bootstrap', 'config', 'routes', 'vendor', 'public'] as $directory) {
            mkdir($this->root.'/'.$directory);
            $path = $directory.'/fixture.php';
            file_put_contents($this->root.'/'.$path, '<?php return [];');
            $files[$path] = hash_file('sha256', $this->root.'/'.$path);
        }
        $bytes = json_encode(['release' => $release, 'root' => $this->root, 'files' => $files]);
        file_put_contents($this->root.'/.release/manifest.json', $bytes);
        $this->identity = ['release' => $release, 'root' => $this->root, 'manifest' => hash('sha256', $bytes)];
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname($this->root), \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }
        rmdir(dirname($this->root));
        parent::tearDown();
    }

    public function test_valid_snapshot_is_accepted(): void
    {
        $this->assertSame(str_repeat('a', 40), (new ReleaseIdentity($this->identity, $this->root))->verifiedRelease());
    }

    public function test_missing_identity_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        (new ReleaseIdentity([], $this->root))->verifiedRelease();
    }

    public function test_invalid_release_is_rejected_without_echoing_input(): void
    {
        $this->identity['release'] = 'secret';
        try {
            (new ReleaseIdentity($this->identity, $this->root))->verifiedRelease();
            $this->fail('Invalid release accepted');
        } catch (RuntimeException $error) {
            $this->assertSame('release_unavailable', $error->getMessage());
        }
    }

    public function test_stale_captured_manifest_is_rejected(): void
    {
        $captured = new ReleaseIdentity($this->identity, $this->root);
        file_put_contents($this->root.'/.release/manifest.json', '{}');
        $this->expectException(RuntimeException::class);
        $captured->verifiedRelease();
    }

    public function test_in_place_code_change_is_rejected(): void
    {
        file_put_contents($this->root.'/app/fixture.php', '<?php return ["changed"];');
        $this->expectException(RuntimeException::class);
        (new ReleaseIdentity($this->identity, $this->root))->verifiedRelease();
    }

    public function test_added_code_is_rejected(): void
    {
        file_put_contents($this->root.'/app/added.php', '<?php return [];');
        $this->expectException(RuntimeException::class);
        (new ReleaseIdentity($this->identity, $this->root))->verifiedRelease();
    }

    public function test_asset_storage_link_does_not_change_runtime_identity(): void
    {
        symlink(sys_get_temp_dir(), $this->root.'/public/storage');
        $this->assertSame(str_repeat('a', 40), (new ReleaseIdentity($this->identity, $this->root))->verifiedRelease());
    }
}
