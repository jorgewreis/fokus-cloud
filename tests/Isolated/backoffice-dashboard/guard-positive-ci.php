<?php

// This entry point is executed only inside the disposable CI network namespace.
require __DIR__.'/laravel-synthetic-bootstrap.php';

$marker = getenv('DASHBOARD_PROOF_MARKER');
if (! is_string($marker) || strlen($marker) !== 32) {
    throw new RuntimeException('Expected CI marker was not supplied');
}
$differentMarker = str_repeat($marker[0] === '0' ? '1' : '0', 32);
putenv('DASHBOARD_PROOF_MARKER='.$differentMarker);
try {
    dashboardProofTarget();
    throw new RuntimeException('The guard accepted a different schema marker');
} catch (RuntimeException $exception) {
    if ($exception->getMessage() !== 'Synthetic server identity or schema marker mismatch') {
        throw $exception;
    }
} finally {
    putenv('DASHBOARD_PROOF_MARKER='.$marker);
}

[$direct, $verifyTarget] = dashboardProofTarget();
[$connection, $second] = dashboardProofBootstrap();

foreach ([$direct, $second, $connection->getPdo(), $connection->getReadPdo()] as $pdo) {
    $verifyTarget($pdo);
    if ((string) $pdo->query('SELECT 1')->fetchColumn() !== '1') {
        throw new RuntimeException('Synthetic connection did not return the expected result');
    }
}

echo "Verified synthetic PDO and Laravel read/write connections.\n";
