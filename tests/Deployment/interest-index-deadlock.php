<?php

use App\Http\Controllers\Api\ProductInterestController;
use App\Mail\ProductInterestNotification;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;

$root = dirname(__DIR__, 2);
$sandbox = getenv('UPGRADE_SANDBOX');
$url = getenv('DB_URL');
$socket = getenv('DB_SOCKET');
$port = getenv('DB_PORT');
if (getenv('FOKUS_INTEREST_INDEX_DISPOSABLE') !== '1' || getenv('APP_ENV') !== 'testing'
    || getenv('RELEASE_DISPOSABLE_TESTS') !== '1' || getenv('DB_CONNECTION') !== 'mysql'
    || ($url !== false && $url !== '') || ($socket !== false && $socket !== '')
    || ($port !== false && $port !== '' && $port !== '3306') || ! is_string($sandbox)
    || ! is_file($sandbox.'/.disposable-upgrade') || getenv('DB_HOST') !== '127.0.0.1'
    || preg_match('/^fokus_upgrade_[a-f0-9]{16}$/D', (string) getenv('DB_DATABASE')) !== 1
    || is_file($root.'/.env') || is_file($root.'/.env.testing') || is_file($root.'/bootstrap/cache/config.php')) {
    throw new RuntimeException('refuse non-disposable context');
}
require $root.'/vendor/autoload.php';
// Capture the database error before Laravel's transaction retry consumes it.
// QueryExecuted/DB::listen is not emitted for a statement that throws.
class DeadlockObservedConnection extends MySqlConnection
{
    public static string $evidencePath;

    protected function runQueryCallback($query, $bindings, Closure $callback)
    {
        try {
            return parent::runQueryCallback($query, $bindings, $callback);
        } catch (QueryException $error) {
            $previous = $error->getPrevious();
            if ($previous instanceof PDOException && ($previous->errorInfo[1] ?? null) === 1213) {
                file_put_contents(self::$evidencePath, "mysql-deadlock-1213\n", FILE_APPEND | LOCK_EX);
            }
            throw $error;
        }
    }
}
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
    exit(1);
});
$run = bin2hex(random_bytes(6));
$dir = sys_get_temp_dir().'/interest-deadlock-'.$run;
if (! mkdir($dir, 0700)) {
    throw new RuntimeException('cannot create synchronization directory');
}
$ballast = 'qa_interest_deadlock_'.$run;
$ids = ['!'.$run, '"'.$run];
$children = [];
$ownsBallast = false;
$ownedIds = [];
try {
    if (DB::table('product_interests')->where('id', '<', $ids[1])->exists()) {
        throw new RuntimeException('fixture range is occupied');
    }
    DB::statement("CREATE TABLE `$ballast` (id INT PRIMARY KEY) ENGINE=InnoDB");
    $ownsBallast = true;
    foreach (['backfill', 'writer'] as $victim) {
        if ($ownedIds) {
            DB::table('product_interests')->whereIn('id', $ownedIds)->delete();
            $ownedIds = [];
        }
        DB::table($ballast)->delete();
        DB::table($ballast)->insert([['id' => 1000], ['id' => 1001]]);
        foreach ($ids as $i => $id) {
            DB::table('product_interests')->insert(['id' => $id, 'name' => 'Synthetic', 'email' => "qa-deadlock-$i-$run@example.test", 'products' => '["legacy"]', 'profiles' => '[]', 'privacy_version' => ProductInterestController::PRIVACY_VERSION, 'status' => 'novo', 'consented_at' => now(), 'last_submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $ownedIds[] = $id;
        }
        $expectedCount = DB::table('product_interests')->count();
        DB::purge();
        foreach (['backfill', 'writer'] as $actor) {
            $pid = pcntl_fork();
            if ($pid < 0) {
                throw new RuntimeException('fork failed');
            }
            if ($pid === 0) {
                try {
                    DB::purge();
                    DeadlockObservedConnection::$evidencePath = "$dir/$actor-deadlocks";
                    Connection::resolverFor('mysql', fn ($connection, $database, $prefix, $config) => new DeadlockObservedConnection($connection, $database, $prefix, $config));
                    Mail::fake();
                    $attempts = 0;
                    DB::connection()->beforeExecuting(function ($query, $bindings) use ($actor, $dir, $ids): void {
                        if (str_starts_with(strtolower($query), 'delete from `interest_product_index`') && ($bindings[0] ?? null) === $ids[$actor === 'backfill' ? 0 : 1]) {
                            file_put_contents("$dir/$actor-attempts", "attempt\n", FILE_APPEND | LOCK_EX);
                        }
                    });
                    DB::listen(function ($query) use ($actor, $victim, $dir, $ids, $ballast, &$attempts): void {
                        if (! str_starts_with(strtolower($query->sql), 'delete from `interest_product_index`') || ($query->bindings[0] ?? null) !== $ids[$actor === 'backfill' ? 0 : 1]) {
                            return;
                        }
                        if (++$attempts !== 1) {
                            return;
                        }
                        // Force a real cycle inside the application transactions.
                        // Depending on incidental index gap locks is nondeterministic.
                        $ownLock = $actor === 'backfill' ? 1000 : 1001;
                        $otherLock = $actor === 'backfill' ? 1001 : 1000;
                        DB::table($ballast)->where('id', $ownLock)->lockForUpdate()->first();
                        if ($actor !== $victim) {
                            $rows = [];
                            for ($i = 0; $i < 200; $i++) {
                                $rows[] = ['id' => $i];
                            }
                            DB::table($ballast)->insert($rows);
                        }
                        touch("$dir/$actor-ready");
                        $deadline = microtime(true) + 45;
                        while (! file_exists("$dir/".($actor === 'writer' ? 'backfill' : 'writer').'-ready') && microtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (microtime(true) >= $deadline) {
                            throw new RuntimeException('barrier timed out');
                        }
                        DB::table($ballast)->where('id', $otherLock)->lockForUpdate()->first();
                    });
                    if ($actor === 'backfill') {
                        $status = Artisan::call('fokus:backfill-interest-products', ['--confirm-drained' => true]);
                        if ($status !== 0 || ! str_contains(Artisan::output(), "Rebuilt index for $expectedCount interests.")) {
                            throw new RuntimeException('backfill failed or count incorrect: '.Artisan::output());
                        }
                    } else {
                        $request = Request::create('/api/product-interests', 'POST', ['name' => 'Synthetic', 'email' => "qa-deadlock-1-$run@example.test", 'products' => ['law'], 'profiles' => ['qa'], 'main_difficulties' => 'Synthetic', 'privacy_accepted' => true, 'privacy_version' => ProductInterestController::PRIVACY_VERSION]);
                        $response = app(ProductInterestController::class)->store($request);
                        if ($response->getStatusCode() !== 201 || Mail::sent(ProductInterestNotification::class)->count() !== 1) {
                            throw new RuntimeException('writer response or fake notification count');
                        }
                    }
                    exit(0);
                } catch (Throwable $error) {
                    fwrite(STDERR, "$actor: ".$error->getMessage().PHP_EOL);
                    exit(1);
                }
            }
            $children[$pid] = $actor;
        }
        $deadline = microtime(true) + 120;
        while ($children && microtime(true) < $deadline) {
            foreach ($children as $pid => $actor) {
                $result = pcntl_waitpid($pid, $status, WNOHANG);
                if ($result === $pid) {
                    unset($children[$pid]);
                    if (! pcntl_wifexited($status) || pcntl_wexitstatus($status) !== 0) {
                        throw new RuntimeException("$actor failed");
                    }
                } elseif ($result === -1) {
                    throw new RuntimeException("cannot wait for $actor");
                }
            }
            usleep(10000);
        }
        if ($children) {
            throw new RuntimeException('actors timed out');
        }
        DB::purge();
        $attempts = count(file("$dir/$victim-attempts", FILE_IGNORE_NEW_LINES));
        $other = $victim === 'writer' ? 'backfill' : 'writer';
        $victimDeadlocks = is_file("$dir/$victim-deadlocks") ? count(file("$dir/$victim-deadlocks", FILE_IGNORE_NEW_LINES)) : 0;
        $otherDeadlocks = is_file("$dir/$other-deadlocks") ? count(file("$dir/$other-deadlocks", FILE_IGNORE_NEW_LINES)) : 0;
        if ($attempts < 2 || $victimDeadlocks !== 1 || $otherDeadlocks !== 0) {
            throw new RuntimeException("expected $victim deadlock 1213 and retry; victim attempts=$attempts victim deadlocks=$victimDeadlocks other deadlocks=$otherDeadlocks");
        }
        foreach ($ids as $i => $id) {
            $row = DB::table('product_interests')->where('id', $id)->first();
            $expected = $i === 0 ? ['legacy'] : ['law'];
            if (! $row || json_decode($row->products, true) !== $expected || DB::table('interest_product_index')->where('interest_id', $id)->pluck('product_code')->all() !== $expected) {
                throw new RuntimeException('index does not match source');
            }
        }
        if (DB::table('product_interests')->where('email', "qa-deadlock-1-$run@example.test")->count() !== 1 || ! DB::table('interest_product_index_state')->where('id', 1)->where('ready', true)->where('running', false)->exists()) {
            throw new RuntimeException('duplicate record or invalid state');
        }
        echo "$victim: mysql deadlocks=$victimDeadlocks other deadlocks=$otherDeadlocks attempts=$attempts; count, notification and index passed\n";
        foreach (['backfill', 'writer'] as $actor) {
            unlink("$dir/$actor-attempts");
            unlink("$dir/$actor-ready");
            if (is_file("$dir/$actor-deadlocks")) {
                unlink("$dir/$actor-deadlocks");
            }
        }
    }
} finally {
    foreach ($children as $pid => $actor) {
        posix_kill($pid, SIGKILL);
        pcntl_waitpid($pid, $status);
    }
    DB::purge();
    if ($ownedIds) {
        DB::table('product_interests')->whereIn('id', $ownedIds)->delete();
    }
    if ($ownsBallast) {
        DB::statement("DROP TABLE `$ballast`");
    }
    foreach (glob($dir.'/*') ?: [] as $path) {
        unlink($path);
    }
    rmdir($dir);
}
