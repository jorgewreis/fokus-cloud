<?php
declare(strict_types=1);

// This harness is destructive ONLY to the disposable CI database named below.
if (getenv('CI') !== 'true' || getenv('DB_HOST') !== '127.0.0.1' || getenv('DB_DATABASE') !== 'fokuscloud_schema_test') {
    throw new RuntimeException('Refusing to run outside the disposable CI database.');
}
$root = realpath($argv[1]);
$script = realpath($argv[2]);
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (config('database.default') !== 'mysql' || config('database.connections.mysql.host') !== '127.0.0.1' || config('database.connections.mysql.database') !== 'fokuscloud_schema_test') {
    throw new RuntimeException('Unexpected configured database.');
}
$name = '2026_10_30_160000_add_encrypted_details_to_law_contacts';
$schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
$db = Illuminate\Support\Facades\DB::getFacadeRoot();
$run = static function (int $expected) use ($root, $script): void {
    $process = new Symfony\Component\Process\Process([PHP_BINARY, $script, $root]);
    $process->run();
    $ok = $process->getExitCode() === 0;
    if (!$ok && $expected !== 0 && !str_contains($process->getErrorOutput() . $process->getOutput(), 'Schema and migration ledger disagree')) {
        throw new RuntimeException('Failure was not the expected schema divergence guard.');
    }
    if ($ok !== ($expected === 0)) {
        throw new RuntimeException('Unexpected prepublication check exit status.');
    }
};
if ($schema->hasColumn('law_contacts', 'sensitive_details_encrypted')) {
    throw new RuntimeException('The old release unexpectedly already contains the new column.');
}
$run(0); // Upgrade using the OLD release runtime.
if (!$schema->hasColumn('law_contacts', 'sensitive_details_encrypted') || !$db->table('migrations')->where('migration', $name)->exists()) {
    throw new RuntimeException('Migration result missing.');
}
$run(0); // Repeat is harmless.
$db->table('migrations')->where('migration', $name)->delete();
$run(1); // Column exists, ledger missing: refuse.
$db->table('migrations')->insert(['migration' => $name, 'batch' => 999]);
$schema->table('law_contacts', static fn (Illuminate\Database\Schema\Blueprint $table) => $table->dropColumn('sensitive_details_encrypted'));
$run(1); // Ledger exists, column missing: refuse.
echo "Old-runtime upgrade, repeated run and both divergence guards passed.\n";
