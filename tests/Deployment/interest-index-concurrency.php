<?php

use App\Services\InterestProductIndex;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$root = dirname(__DIR__, 2);
if (is_file($root.'/.env') || is_file($root.'/.env.testing') || is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('refuse inherited environment');
}
if (getenv('FOKUS_INTEREST_INDEX_DISPOSABLE') !== '1' || getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== 'mysql' || getenv('DB_DATABASE') !== 'synthetic') {
    throw new RuntimeException('refuse non-disposable context');
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$index = app(InterestProductIndex::class);
foreach (['backfill-first', 'writer-first'] as $scenario) {
    $id = 'QA_INTEREST_'.($scenario === 'backfill-first' ? 'BACK' : 'WRITE');
    DB::table('product_interests')->where('id', $id)->delete();
    DB::table('product_interests')->insert(['id' => $id, 'name' => 'QA', 'email' => $id.'@example.test', 'products' => '["legacy"]', 'profiles' => '[]', 'privacy_version' => '2.0', 'status' => 'novo', 'consented_at' => now(), 'last_submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    DB::transaction(fn () => $index->replace($id, ['legacy']));
    DB::purge();
    $lock = '/tmp/interest-'.$scenario.'-locked';
    $attempt = '/tmp/interest-'.$scenario.'-attempt';
    $done = '/tmp/interest-'.$scenario.'-done';
    @unlink($lock);
    @unlink($attempt);
    @unlink($done);
    $pid = pcntl_fork();
    if ($pid < 0) {
        throw new RuntimeException('fork failed');
    }
    if ($pid === 0) {
        try {
            while (! file_exists($lock)) {
                usleep(10000);
            } touch($attempt);
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
    pcntl_waitpid($pid, $status);
    if (pcntl_wexitstatus($status) !== 0) {
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
    DB::table('product_interests')->where('id', $id)->delete();
    echo $scenario." lock and rollback passed\n";
}
