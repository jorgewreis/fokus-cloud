<?php

// Metadata assertions for the disposable upgrade harness, never production DDL.
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function originRequire(bool $condition, string $label): void
{
    if (! $condition) throw new RuntimeException('Origin fixture: '.$label);
}

function originMetadata(): array
{
    if (DB::getDriverName() !== 'mysql') return ['driver' => DB::getDriverName()];
    $queries = [
        'columns' => 'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION',
        'indexes' => 'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
        'foreign_keys' => 'SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION',
        'tables' => 'SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME',
    ];
    $result = ['driver' => 'mysql'];
    foreach ($queries as $key => $query) $result[$key] = DB::connection()->getPdo()->query($query)->fetchAll(PDO::FETCH_NUM);
    return $result;
}

function originAllowedColumns(): array
{
    // Exact reviewed additions, including type/nullability. No wildcard columns.
    $columns = [
        ['payments', 'provider_checkout_url', 'varchar(512)', 'YES'],
        ['law_cases', 'internal_confidentiality_level', 'varchar(24)', 'YES'],
        ['law_cases', 'source_confidentiality_level', 'varchar(24)', 'YES'],
        ['law_cases', 'last_official_movement_at', 'timestamp', 'YES'],
    ];
    $tables = [
        'law_case_publication_reviews' => [
            'id' => ['char(30)', 'NO'], 'company_id' => ['char(30)', 'NO'], 'law_case_id' => ['char(30)', 'NO'],
            'publication_key' => ['varchar(64)', 'NO'], 'status' => ['varchar(20)', 'NO'], 'next_action' => ['text', 'YES'],
            'reviewed_by' => ['char(30)', 'YES'], 'reviewed_at' => ['timestamp', 'YES'], 'version' => ['int unsigned', 'NO'],
            'created_at' => ['timestamp', 'YES'], 'updated_at' => ['timestamp', 'YES'],
        ],
        'law_contact_import_batches' => [
            'id' => ['char(30)', 'NO'], 'company_id' => ['char(30)', 'NO'], 'user_id' => ['char(30)', 'NO'],
            'payload_encrypted' => ['longtext', 'NO'], 'expires_at' => ['timestamp', 'NO'],
            'created_at' => ['timestamp', 'YES'], 'updated_at' => ['timestamp', 'YES'],
        ],
        'law_tasks' => [
            'id' => ['char(30)', 'NO'], 'company_id' => ['char(30)', 'NO'], 'law_unit_id' => ['char(30)', 'NO'],
            'created_by' => ['char(30)', 'NO'], 'updated_by' => ['char(30)', 'NO'], 'law_case_id' => ['char(30)', 'YES'],
            'responsible_membership_id' => ['char(30)', 'YES'], 'title' => ['varchar(180)', 'NO'], 'description' => ['text', 'YES'],
            'due_on' => ['date', 'YES'], 'status' => ['varchar(20)', 'NO'], 'version' => ['int unsigned', 'NO'],
            'request_key' => ['char(36)', 'YES'], 'request_fingerprint' => ['char(64)', 'YES'],
            'created_at' => ['timestamp', 'YES'], 'updated_at' => ['timestamp', 'YES'], 'deleted_at' => ['timestamp', 'YES'],
        ],
        'law_task_events' => [
            'id' => ['char(30)', 'NO'], 'company_id' => ['char(30)', 'NO'], 'law_task_id' => ['char(30)', 'NO'],
            'actor_user_id' => ['char(30)', 'NO'], 'event_type' => ['varchar(32)', 'NO'], 'before_state' => ['json', 'YES'],
            'after_state' => ['json', 'YES'], 'created_at' => ['timestamp', 'NO'],
        ],
    ];
    foreach ($tables as $table => $definitions) foreach ($definitions as $name => $definition) $columns[] = [$table, $name, ...$definition];
    sort($columns);
    return $columns;
}

function originAssertSchemaDelta(array $before, array $after, bool $reversed = false): array
{
    if (($before['driver'] ?? null) !== 'mysql') return ['scope' => 'Exact origin metadata contract requires MySQL; SQLite exercises functional compatibility only'];
    $newTables = $reversed ? [] : ['law_case_publication_reviews', 'law_contact_import_batches', 'law_task_events', 'law_tasks'];
    $added = [];
    foreach ($after['columns'] as $row) if (! in_array($row, $before['columns'], true)) $added[] = array_slice($row, 0, 4);
    foreach ($before['columns'] as $row) originRequire(in_array($row, $after['columns'], true), 'existing column metadata changed: '.$row[0].'.'.$row[1]);
    sort($added);
    $expected = $reversed ? [['payments', 'provider_checkout_url', 'varchar(512)', 'YES']] : originAllowedColumns();
    originRequire($added === $expected, 'column additions differ from exact authorized allowlist');
    $checkout = array_values(array_filter($after['columns'], fn ($row) => $row[0] === 'payments' && $row[1] === 'provider_checkout_url'));
    originRequire($checkout === [['payments', 'provider_checkout_url', 'varchar(512)', 'YES', null, '', 'utf8mb4', 'utf8mb4_unicode_ci', '']], 'checkout full definition/default/extra/collation/generation changed');
    foreach (['indexes', 'foreign_keys', 'tables'] as $kind) {
        $retained = array_values(array_filter($after[$kind], fn ($row) => ! in_array($row[0], $newTables, true)
            && ! ($kind === 'indexes' && ! $reversed && $row[0] === 'law_cases' && $row[1] === 'law_cases_last_official_movement_at_index')));
        originRequire($retained === $before[$kind], 'existing '.$kind.' metadata changed');
    }
    if (! $reversed) {
        $tables = array_values(array_map(fn ($row) => $row[0], array_filter($after['tables'], fn ($row) => in_array($row[0], $newTables, true))));
        originRequire($tables === $newTables, 'new table allowlist changed');
        $index = array_values(array_filter($after['indexes'], fn ($row) => $row[0] === 'law_cases' && $row[1] === 'law_cases_last_official_movement_at_index'));
        originRequire(count($index) === 1 && $index[0][2] === 1 && $index[0][3] === 1 && $index[0][4] === 'last_official_movement_at', 'projection index definition changed');
    }
    return ['status' => 'passed', 'added_columns' => $added, 'new_tables' => $newTables,
        'existing_columns_indexes_foreign_keys_tables_unchanged' => true,
        'retained_timestamp_columns' => ['audit_events.created_at', 'support_accesses.starts_at', 'support_accesses.ends_at'],
        'rollback_policy' => 'Production forward-fix-only. Laboratory rollback removes repair ledger but deliberately retains payments.provider_checkout_url and all values.',
        'scope' => 'All original column/default/extra/collation/generation, index/FK and engine/collation metadata retained; exact new column names/types/nullability. New-table indexes/FKs captured for review.'];
}

function originAssertTimes(string $sandbox): array
{
    if (DB::getDriverName() !== 'mysql') return ['scope' => 'TIMESTAMP/session-timezone semantics require MySQL'];
    $ids = json_decode(file_get_contents($sandbox.'/origin-temporal-ids.json'), true, flags: JSON_THROW_ON_ERROR);
    $read = function () use ($ids): array {
        $audit = DB::selectOne('SELECT created_at,UNIX_TIMESTAMP(created_at) AS epoch FROM audit_events WHERE id=?', [$ids['audit']]);
        $support = DB::selectOne('SELECT starts_at,ends_at,UNIX_TIMESTAMP(starts_at) AS start_epoch,UNIX_TIMESTAMP(ends_at) AS end_epoch FROM support_accesses WHERE id=?', [$ids['support']]);
        return ['audit' => (array) $audit, 'support' => (array) $support];
    };
    originRequire(DB::selectOne('SELECT @@session.time_zone AS zone')->zone === '+00:00', 'expected UTC session');
    $utc = $read();
    try { DB::statement("SET SESSION time_zone='-03:00'"); $minusThree = $read(); }
    finally { DB::statement("SET SESSION time_zone='+00:00'"); }
    originRequire($utc['audit']['created_at'] === '2026-11-07 12:34:56' && $minusThree['audit']['created_at'] === '2026-11-07 09:34:56', 'audit wall clock conversion');
    foreach (['starts_at', 'ends_at'] as $column) originRequire($utc['support'][$column] === '2026-11-07 12:34:56' && $minusThree['support'][$column] === '2026-11-07 09:34:56', 'support wall clock conversion');
    originRequire($utc['audit']['epoch'] === $minusThree['audit']['epoch'] && $utc['support']['start_epoch'] === $minusThree['support']['start_epoch'] && $utc['support']['end_epoch'] === $minusThree['support']['end_epoch'], 'epochs retained across sessions');
    $result = ['utc' => $utc, 'minus03' => $minusThree, 'restored_session_timezone' => '+00:00'];
    if (is_file($sandbox.'/origin-temporal-before.json')) originRequire(json_decode(file_get_contents($sandbox.'/origin-temporal-before.json'), true) === $result, 'fixture instants changed after migrations');
    return $result;
}
