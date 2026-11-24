<?php

use Pdo\Mysql;

// Synthetic-only metadata. Exact SQL/ordering/allowlist of the reviewed read-only origin probe.
try {
    if (getenv('APP_ENV') !== 'testing' || getenv('DB_HOST') !== '127.0.0.1' || ! preg_match('/^fokus_upgrade_[a-f0-9]{16}$/D', getenv('DB_DATABASE'))) {
        throw new RuntimeException('guard');
    }
    if (! getenv('RELEASE_MYSQL_INIT_COMMAND')) {
        throw new RuntimeException('missing session guard');
    }
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.getenv('DB_DATABASE').';charset=utf8mb4', getenv('DB_USERNAME'), getenv('DB_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5, Mysql::ATTR_MULTI_STATEMENTS => false, Mysql::ATTR_INIT_COMMAND => getenv('RELEASE_MYSQL_INIT_COMMAND')]);
    $pdo->exec('START TRANSACTION READ ONLY');
    $tables = ['companies', 'company_memberships', 'customer_permissions', 'law_access_role_permissions', 'law_case_publication_reviews', 'law_cases', 'law_contact_import_batches', 'law_task_events', 'law_tasks', 'law_units', 'users'];
    $marks = implode(',', array_fill(0, count($tables), '?'));
    $queries = [
        'tables' => "SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks) ORDER BY TABLE_NAME",
        'columns' => "SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,CHARACTER_SET_NAME,COLLATION_NAME,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks) ORDER BY TABLE_NAME,ORDINAL_POSITION",
        'indexes' => "SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ($marks) ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX",
        'foreign_keys' => "SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME IN ($marks) AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION",
    ];
    foreach ($queries as $kind => $sql) {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($tables);
        $metadata = $stmt->fetchAll(PDO::FETCH_NUM);
        $encoded = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        echo 'target_metadata_', $kind, '_sha256=', hash('sha256', $encoded), "\n";
        echo 'target_metadata_', $kind, '_json=', $encoded, "\n";
    }
    $pdo->rollBack();
} catch (Throwable $error) {
    fwrite(STDERR, 'Synthetic detailed metadata capture failed'.PHP_EOL);
    exit(1);
}
