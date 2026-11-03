<?php
declare(strict_types=1);

// Run against the existing release. Never print environment values or decrypt data.
$root = $argv[1] ?? '';
$migration = __DIR__ . '/2026_10_30_160000_add_encrypted_details_to_law_contacts.php';
if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/.env') || !is_file($migration)) {
    throw new RuntimeException('Existing release or staged migration missing.');
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
if (!is_string(config('app.key')) || config('app.key') === '') {
    throw new RuntimeException('Application encryption key is not configured.');
}
$name = '2026_10_30_160000_add_encrypted_details_to_law_contacts';
$schema = Illuminate\Support\Facades\Schema::getFacadeRoot();
if (!$schema->hasTable('law_contacts') || !$schema->hasTable('migrations')) {
    throw new RuntimeException('Expected production tables missing.');
}
$column = $schema->hasColumn('law_contacts', 'sensitive_details_encrypted');
$recorded = Illuminate\Support\Facades\DB::table('migrations')->where('migration', $name)->exists();
if ($column !== $recorded) {
    throw new RuntimeException('Schema and migration ledger disagree; stop for investigation.');
}
if (!$column) {
    $status = $kernel->call('migrate', ['--path' => [$migration], '--realpath' => true, '--force' => true, '--no-interaction' => true]);
    if ($status !== 0) {
        throw new RuntimeException('Additive migration failed; application publication must remain blocked.');
    }
}
if (!$schema->hasColumn('law_contacts', 'sensitive_details_encrypted') || !Illuminate\Support\Facades\DB::table('migrations')->where('migration', $name)->exists()) {
    throw new RuntimeException('Post-migration verification failed.');
}
echo "Law schema prerequisite verified.\n";
