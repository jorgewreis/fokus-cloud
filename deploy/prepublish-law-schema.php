<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Run against the existing release. Never print environment values or decrypt data.
// Bootstrap installs Laravel's exception handler, which does not guarantee CLI exit 1.
// Keep the complete operation inside our own process boundary, including bootstrap.
$failure = 'Existing release or staged migration missing.';
try {
    $root = $argv[1] ?? '';
    $migration = __DIR__.'/2026_10_30_160000_add_encrypted_details_to_law_contacts.php';
    if (! is_file($root.'/vendor/autoload.php') || ! is_file($root.'/.env') || ! is_file($migration)) {
        throw new RuntimeException;
    }
    $failure = 'Could not bootstrap the existing release.';
    require $root.'/vendor/autoload.php';
    $app = require $root.'/bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (! is_string(config('app.key')) || config('app.key') === '') {
        $failure = 'Application encryption key is not configured.';
        throw new RuntimeException;
    }
    $name = '2026_10_30_160000_add_encrypted_details_to_law_contacts';
    $failure = 'Could not inspect the existing schema.';
    $schema = Schema::getFacadeRoot();
    if (! $schema->hasTable('law_contacts') || ! $schema->hasTable('migrations')) {
        $failure = 'Expected production tables missing.';
        throw new RuntimeException;
    }
    $column = $schema->hasColumn('law_contacts', 'sensitive_details_encrypted');
    $recorded = DB::table('migrations')->where('migration', $name)->exists();
    if ($column !== $recorded) {
        $failure = 'Schema and migration ledger disagree; stop for investigation.';
        throw new RuntimeException;
    }
    if (! $column) {
        $failure = 'Additive migration failed; application publication must remain blocked.';
        $status = $kernel->call('migrate', ['--path' => [$migration], '--realpath' => true, '--force' => true, '--no-interaction' => true]);
        if ($status !== 0) {
            throw new RuntimeException;
        }
    }
    $failure = 'Post-migration verification failed.';
    if (! $schema->hasColumn('law_contacts', 'sensitive_details_encrypted') || ! DB::table('migrations')->where('migration', $name)->exists()) {
        throw new RuntimeException;
    }
    echo "Law schema prerequisite verified.\n";
} catch (Throwable) {
    // Exception messages, SQL and Console output may contain secrets. Only fixed reasons leave this process.
    fwrite(STDERR, 'Law schema prepublication failed: '.$failure."\n");
    exit(1);
}
exit(0);
