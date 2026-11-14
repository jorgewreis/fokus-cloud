<?php

use App\Services\InterestProductIndex;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
if (is_file($root.'/.env') || is_file($root.'/.env.testing') || is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('refuse inherited environment');
}
$sandbox = getenv('UPGRADE_SANDBOX');
$local = getenv('DB_HOST') === 'mysql' && getenv('DB_DATABASE') === 'synthetic';
$harness = getenv('RELEASE_DISPOSABLE_TESTS') === '1' && is_string($sandbox)
    && is_file($sandbox.'/.disposable-upgrade') && getenv('DB_HOST') === '127.0.0.1'
    && preg_match('/^fokus_upgrade_[a-f0-9]{16}$/D', (string) getenv('DB_DATABASE')) === 1;
// DB_URL overrides the individual connection fields, and a Unix socket can
// override the hostname. Reject both before Laravel or any database is loaded.
$url = getenv('DB_URL');
$socket = getenv('DB_SOCKET');
$port = getenv('DB_PORT');
if (getenv('FOKUS_INTEREST_INDEX_DISPOSABLE') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'mysql' || ($url !== false && $url !== '')
    || ($socket !== false && $socket !== '') || ($port !== false && $port !== '' && $port !== '3306')
    || (! $local && ! $harness)) {
    throw new RuntimeException('refuse non-disposable context');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
require $root.'/tests/Deployment/release-disposable-mysql.php';
assertReleaseDisposableMysql();
$index = app(InterestProductIndex::class);
$runId = bin2hex(random_bytes(6));
$directory = sys_get_temp_dir().'/interest-index-'.$runId;
if (! mkdir($directory, 0700)) {
    throw new RuntimeException('cannot create private synchronization directory');
}
$pid = null;
$ownedIds = [];
try {
    foreach (['backfill-first', 'writer-first'] as $scenario) {
        $id = 'QA_'.($scenario === 'backfill-first' ? 'B_' : 'W_').$runId;
        DB::table('product_interests')->insert(['id' => $id, 'name' => 'QA', 'email' => $id.'@example.test', 'products' => '["legacy"]', 'profiles' => '[]', 'privacy_version' => '2.0', 'status' => 'novo', 'consented_at' => now(), 'last_submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $ownedIds[] = $id;
        DB::transaction(fn () => $index->replace($id, ['legacy']));
        DB::purge();
        $lock = $directory.'/'.$scenario.'-locked';
        $attempt = $directory.'/'.$scenario.'-attempt';
        $done = $directory.'/'.$scenario.'-done';
        $pid = pcntl_fork();
        if ($pid < 0) {
            throw new RuntimeException('fork failed');
        }
        if ($pid === 0) {
            try {
                $deadline = microtime(true) + 10;
                while (! file_exists($lock) && microtime(true) < $deadline) {
                    usleep(10000);
                }
                if (! file_exists($lock)) {
                    throw new RuntimeException('parent did not acquire lock');
                }
                touch($attempt);
                assertReleaseDisposableMysql();
                DB::transaction(function () use ($id, $scenario, $index) {
                    $row = DB::table('product_interests')->where('id', $id)->lockForUpdate()->first();
                    if ($scenario === 'backfill-first') {
                        DB::table('product_interests')->where('id', $id)->update(['products' => '["new"]']);
                        $index->replace($id, ['new']);
                    } else {
                        $index->replace($id, json_decode($row->products, true, 512, JSON_THROW_ON_ERROR));
                    }
                });
                touch($done);
                exit(0);
            } catch (Throwable $e) {
                fwrite(STDERR, $e->getMessage());
                exit(2);
            }
        }
        assertReleaseDisposableMysql();
        DB::transaction(function () use ($id, $scenario, $index, $lock, $attempt, $done) {
            $row = DB::table('product_interests')->where('id', $id)->lockForUpdate()->first();
            if ($scenario === 'backfill-first') {
                $index->replace($id, json_decode($row->products, true, 512, JSON_THROW_ON_ERROR));
            } else {
                DB::table('product_interests')->where('id', $id)->update(['products' => '["new"]']);
                $index->replace($id, ['new']);
            } touch($lock);
            $deadline = microtime(true) + 10;
            while (! file_exists($attempt) && microtime(true) < $deadline) {
                usleep(10000);
            } if (! file_exists($attempt)) {
                throw new RuntimeException('child did not start');
            } usleep(300000);
            if (file_exists($done)) {
                throw new RuntimeException('writer not blocked');
            }
        });
        $deadline = microtime(true) + 15;
        do {
            $waited = pcntl_waitpid($pid, $status, WNOHANG);
            if ($waited === 0) {
                usleep(10000);
            }
        } while ($waited === 0 && microtime(true) < $deadline);
        if ($waited !== $pid) {
            throw new RuntimeException('child did not finish');
        }
        $pid = null;
        if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('child failed');
        }
        $row = DB::table('product_interests')->where('id', $id)->first();
        $codes = DB::table('interest_product_index')->where('interest_id', $id)->pluck('product_code')->all();
        if (json_decode($row->products, true) !== ['new'] || $codes !== ['new']) {
            throw new RuntimeException('index mismatch');
        }
        try {
            DB::transaction(function () use ($id, $index) {
                DB::table('product_interests')->where('id', $id)->update(['products' => '["rollback"]']);
                $index->replace($id, ['rollback']);
                throw new RuntimeException('expected');
            });
        } catch (RuntimeException $e) {
            if ($e->getMessage() !== 'expected') {
                throw $e;
            }
        }
        if (DB::table('interest_product_index')->where('interest_id', $id)->pluck('product_code')->all() !== ['new']) {
            throw new RuntimeException('rollback mismatch');
        }
        assertReleaseDisposableMysql();
        DB::table('product_interests')->where('id', $id)->delete();
        $ownedIds = array_values(array_diff($ownedIds, [$id]));
        echo $scenario." lock and rollback passed\n";
    }
} finally {
    if ($pid !== null && $pid > 0) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
    if ($ownedIds !== []) {
        assertReleaseDisposableMysql();
        DB::table('product_interests')->whereIn('id', $ownedIds)->delete();
    }
    foreach (glob($directory.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($directory);
}
