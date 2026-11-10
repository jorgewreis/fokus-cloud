<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;

$case = 'disposable database guard';
try {
    // This harness is destructive ONLY to the disposable CI database named below.
    if (getenv('CI') !== 'true' || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'fokuscloud_schema_test') {
        throw new RuntimeException;
    }
    $case = 'bootstrap';
    $root = realpath($argv[1] ?? '');
    $script = realpath($argv[2] ?? '');
    if ($root === false || $script === false || ! is_file($root.'/vendor/autoload.php')) {
        throw new RuntimeException;
    }
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.host') !== '127.0.0.1' || config('database.connections.mysql.database') !== 'fokuscloud_schema_test') {
        throw new RuntimeException;
    }
    // DB_URL/read-write/socket configuration can override the nominal host/database.
    // Inspect Laravel's resolved connection before any schema inspection or mutation.
    $case = 'resolved database guard';
    $connection = DB::connection();
    if ($connection->getDriverName() !== 'mysql' || $connection->getConfig('host') !== '127.0.0.1' || $connection->getDatabaseName() !== 'fokuscloud_schema_test' || $connection->getConfig('unix_socket') || $connection->getConfig('read') || $connection->getConfig('write')) {
        throw new RuntimeException;
    }
    $name = '2026_10_30_160000_add_encrypted_details_to_law_contacts';
    $schema = Schema::getFacadeRoot();
    $db = DB::getFacadeRoot();
    $assert = static function (bool $condition): void {
        if (! $condition) {
            throw new RuntimeException;
        }
    };
    $run = static function (string $label, int $expected, string $reason = '', ?string $helper = null) use ($root, $script, $assert, &$case): void {
        $case = $label;
        $process = new Process([PHP_BINARY, $helper ?? $script, $root]);
        $process->setTimeout(30);
        $process->run();
        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        // Only synthetic test data is used, but never echo exception details from a failed helper.
        echo sprintf("Case %s: exit=%s expected=%d stdout_bytes=%d stderr_bytes=%d\n", $case, var_export($process->getExitCode(), true), $expected, strlen($stdout), strlen($stderr));
        $assert($process->getExitCode() === $expected);
        $assert($stdout === ($expected === 0 ? "Law schema prerequisite verified.\n" : ''));
        $assert($stderr === ($expected === 0 ? '' : 'Law schema prepublication failed: '.$reason."\n"));
    };
    $case = 'old release initial state';
    $assert(! $schema->hasColumn('law_contacts', 'sensitive_details_encrypted'));
    $assert(! $db->table('migrations')->where('migration', $name)->exists());

    // Exercise migration failures through the real old Laravel/Symfony Console runtime.
    // Fixtures are staged separately. The reviewed migration and production payload never change.
    $fixture = sys_get_temp_dir().'/law-schema-fixture-'.bin2hex(random_bytes(8));
    $assert(mkdir($fixture, 0700));
    try {
        $assert(copy($script, $fixture.'/prepublish-law-schema.php'));
        foreach (['RuntimeException', 'Error'] as $throwable) {
            $source = '<?php return new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void { throw new '.$throwable.'("SCHEMA_SYNTHETIC_SECRET"); } };';
            $assert(file_put_contents($fixture.'/'.$name.'.php', $source) !== false);
            $run('migration '.$throwable, 1, 'Additive migration failed; application publication must remain blocked.', $fixture.'/prepublish-law-schema.php');
            $assert(! $schema->hasColumn('law_contacts', 'sensitive_details_encrypted'));
            $assert(! $db->table('migrations')->where('migration', $name)->exists());
        }
        $source = '<?php return new class extends Illuminate\\Database\\Migrations\\Migration { public function up(): void {} };';
        $assert(file_put_contents($fixture.'/'.$name.'.php', $source) !== false);
        $run('migration without postcondition', 1, 'Post-migration verification failed.', $fixture.'/prepublish-law-schema.php');
        $assert(! $schema->hasColumn('law_contacts', 'sensitive_details_encrypted'));
        $assert($db->table('migrations')->where('migration', $name)->count() === 1);
        $db->table('migrations')->where('migration', $name)->delete();
    } finally {
        foreach ([$fixture.'/prepublish-law-schema.php', $fixture.'/'.$name.'.php'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($fixture);
    }

    $run('old-runtime upgrade', 0);
    $assert($schema->hasColumn('law_contacts', 'sensitive_details_encrypted'));
    $assert($db->table('migrations')->where('migration', $name)->count() === 1);
    $ledger = $db->table('migrations')->where('migration', $name)->first();
    $run('idempotent repeat', 0);
    $assert($ledger == $db->table('migrations')->where('migration', $name)->first());
    $assert($db->table('migrations')->where('migration', $name)->count() === 1);

    $db->table('migrations')->where('migration', $name)->delete();
    $run('column without ledger', 1, 'Schema and migration ledger disagree; stop for investigation.');
    $assert($schema->hasColumn('law_contacts', 'sensitive_details_encrypted'));
    $assert(! $db->table('migrations')->where('migration', $name)->exists());

    $db->table('migrations')->insert(['migration' => $name, 'batch' => 999]);
    $schema->table('law_contacts', static fn (Blueprint $table) => $table->dropColumn('sensitive_details_encrypted'));
    $run('ledger without column', 1, 'Schema and migration ledger disagree; stop for investigation.');
    $assert(! $schema->hasColumn('law_contacts', 'sensitive_details_encrypted'));
    $assert($db->table('migrations')->where('migration', $name)->count() === 1);
    $assert($db->table('migrations')->where('migration', $name)->value('batch') === 999);

    $schema->rename('migrations', 'schema_test_migrations');
    try {
        $run('missing ledger table', 1, 'Expected production tables missing.');
    } finally {
        $schema->rename('schema_test_migrations', 'migrations');
    }
    echo "Old-runtime upgrade, repeated run and both divergence guards passed.\n";
} catch (Throwable) {
    // Do not let Laravel render an assertion and return exit 0.
    fwrite(STDERR, 'Schema preflight regression failed: '.$case."\n");
    exit(1);
}
exit(0);
