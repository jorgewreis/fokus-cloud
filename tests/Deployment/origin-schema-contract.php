<?php

// Metadata assertions for the disposable upgrade harness, never production DDL.
use Illuminate\Support\Facades\DB;

function originRequire(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('Origin fixture: '.$label);
    }
}

function originMetadata(): array
{
    if (DB::getDriverName() !== 'mysql') {
        return ['driver' => DB::getDriverName()];
    }
    $queries = [
        'columns' => 'SELECT TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,CHARACTER_SET_NAME,COLLATION_NAME,GENERATION_EXPRESSION FROM information_schema.columns WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,ORDINAL_POSITION',
        'indexes' => 'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,COLLATION,SUB_PART,INDEX_TYPE,IS_VISIBLE,EXPRESSION FROM information_schema.statistics WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
        'foreign_keys' => 'SELECT k.TABLE_NAME,k.CONSTRAINT_NAME,k.COLUMN_NAME,k.ORDINAL_POSITION,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,r.UPDATE_RULE,r.DELETE_RULE FROM information_schema.key_column_usage k JOIN information_schema.referential_constraints r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.TABLE_NAME,k.CONSTRAINT_NAME,k.ORDINAL_POSITION',
        'tables' => 'SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.tables WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME',
    ];
    $result = ['driver' => 'mysql'];
    foreach ($queries as $key => $query) {
        $result[$key] = DB::connection()->getPdo()->query($query)->fetchAll(PDO::FETCH_NUM);
    }

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
        'law_beta_applications' => [
            'id' => ['char(30)', 'NO'], 'campaign_code' => ['varchar(80)', 'NO'], 'name' => ['varchar(160)', 'NO'],
            'email' => ['varchar(255)', 'NO'], 'organization' => ['varchar(180)', 'YES'], 'profile' => ['varchar(80)', 'NO'],
            'modules' => ['json', 'NO'], 'feedback' => ['text', 'NO'], 'source_url' => ['varchar(500)', 'YES'],
            'privacy_version' => ['varchar(64)', 'NO'], 'privacy_consented_at' => ['timestamp', 'NO'],
            'beta_program_version' => ['varchar(64)', 'NO'], 'beta_terms_accepted_at' => ['timestamp', 'NO'],
            'status' => ['varchar(32)', 'NO'], 'created_at' => ['timestamp', 'YES'], 'updated_at' => ['timestamp', 'YES'],
        ],
        'interest_product_index' => [
            'interest_id' => ['char(30)', 'NO'], 'product_code' => ['varchar(120)', 'NO'],
        ],
        'interest_product_index_state' => [
            'id' => ['tinyint unsigned', 'NO'], 'ready' => ['tinyint(1)', 'NO'], 'running' => ['tinyint(1)', 'NO'],
        ],
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
    foreach ($tables as $table => $definitions) {
        foreach ($definitions as $name => $definition) {
            $columns[] = [$table, $name, ...$definition];
        }
    }
    foreach (originRei60Schema()['columns'] as $definition) {
        $columns[] = array_slice($definition, 0, 4);
    }
    sort($columns);

    return $columns;
}

function originRei60Schema(): array
{
    // Complete information_schema contract for the two forward-only REI-60 tables.
    return [
        'tables' => [
            ['company_idempotency_requests', 'InnoDB', 'utf8mb4_unicode_ci'],
            ['platform_session_authorities', 'InnoDB', 'utf8mb4_unicode_ci'],
        ],
        'columns' => [
            ['company_idempotency_requests', 'token_hash', 'char(64)', 'NO', null, '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['company_idempotency_requests', 'key_id', 'varchar(32)', 'NO', null, '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['company_idempotency_requests', 'actor_id', 'varchar(128)', 'NO', null, '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['company_idempotency_requests', 'operation', 'varchar(32)', 'NO', null, '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['company_idempotency_requests', 'issued_at_epoch', 'bigint unsigned', 'NO', null, '', null, null, ''],
            ['company_idempotency_requests', 'expires_at_epoch', 'bigint unsigned', 'NO', null, '', null, null, ''],
            ['company_idempotency_requests', 'recorded_at_epoch', 'bigint unsigned', 'NO', null, '', null, null, ''],
            ['company_idempotency_requests', 'state', 'varchar(16)', 'NO', 'issued', '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['company_idempotency_requests', 'payload_digest', 'char(64)', 'YES', null, '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['company_idempotency_requests', 'revoked', 'tinyint(1)', 'NO', '0', '', null, null, ''],
            ['company_idempotency_requests', 'result_id', 'varchar(128)', 'YES', null, '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['company_idempotency_requests', 'result_status', 'smallint unsigned', 'YES', null, '', null, null, ''],
            ['company_idempotency_requests', 'created_at', 'timestamp', 'YES', null, '', null, null, ''],
            ['company_idempotency_requests', 'updated_at', 'timestamp', 'YES', null, '', null, null, ''],
            ['platform_session_authorities', 'session_id', 'varchar(255)', 'NO', null, '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['platform_session_authorities', 'platform_admin_id', 'char(30)', 'NO', null, '', 'ascii', 'ascii_bin', ''],
            ['platform_session_authorities', 'web_principal_state', "enum('none','known','unknown')", 'NO', null, '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['platform_session_authorities', 'web_user_id', 'char(30)', 'YES', null, '', 'ascii', 'ascii_bin', ''],
            ['platform_session_authorities', 'generation', 'bigint unsigned', 'NO', '1', '', null, null, ''],
            ['platform_session_authorities', 'state', "enum('active','revoked')", 'NO', 'active', '', 'utf8mb4', 'utf8mb4_unicode_ci', ''],
            ['platform_session_authorities', 'expires_at', 'timestamp', 'NO', null, '', null, null, ''],
            ['platform_session_authorities', 'revoked_at', 'timestamp', 'YES', null, '', null, null, ''],
            ['platform_session_authorities', 'created_at', 'timestamp', 'YES', null, '', null, null, ''],
            ['platform_session_authorities', 'updated_at', 'timestamp', 'YES', null, '', null, null, ''],
        ],
        'indexes' => [
            ['company_idempotency_requests', 'company_idempotency_requests_actor_id_expires_at_epoch_index', 1, 1, 'actor_id', 'A', null, 'BTREE', 'YES', null],
            ['company_idempotency_requests', 'company_idempotency_requests_actor_id_expires_at_epoch_index', 1, 2, 'expires_at_epoch', 'A', null, 'BTREE', 'YES', null],
            ['company_idempotency_requests', 'company_idempotency_requests_actor_id_recorded_at_epoch_index', 1, 1, 'actor_id', 'A', null, 'BTREE', 'YES', null],
            ['company_idempotency_requests', 'company_idempotency_requests_actor_id_recorded_at_epoch_index', 1, 2, 'recorded_at_epoch', 'A', null, 'BTREE', 'YES', null],
            ['company_idempotency_requests', 'PRIMARY', 0, 1, 'token_hash', 'A', null, 'BTREE', 'YES', null],
            ['platform_session_authorities', 'platform_session_authorities_platform_admin_id_state_index', 1, 1, 'platform_admin_id', 'A', null, 'BTREE', 'YES', null],
            ['platform_session_authorities', 'platform_session_authorities_platform_admin_id_state_index', 1, 2, 'state', 'A', null, 'BTREE', 'YES', null],
            ['platform_session_authorities', 'platform_session_authorities_state_expires_at_index', 1, 1, 'state', 'A', null, 'BTREE', 'YES', null],
            ['platform_session_authorities', 'platform_session_authorities_state_expires_at_index', 1, 2, 'expires_at', 'A', null, 'BTREE', 'YES', null],
            ['platform_session_authorities', 'platform_session_authorities_web_user_id_foreign', 1, 1, 'web_user_id', 'A', null, 'BTREE', 'YES', null],
            ['platform_session_authorities', 'PRIMARY', 0, 1, 'session_id', 'A', null, 'BTREE', 'YES', null],
        ],
        'foreign_keys' => [
            ['platform_session_authorities', 'platform_session_authorities_platform_admin_id_foreign', 'platform_admin_id', 1, 'platform_admins', 'id', 'NO ACTION', 'RESTRICT'],
            ['platform_session_authorities', 'platform_session_authorities_web_user_id_foreign', 'web_user_id', 1, 'users', 'id', 'NO ACTION', 'SET NULL'],
        ],
    ];
}

function originNormalizeMetadata(array $rows): array
{
    return array_map(fn (array $row): array => array_map(fn ($value) => $value === null ? null : (string) $value, $row), $rows);
}

function originRequireRei60Metadata(array $actual, array $expected, string $kind): void
{
    $actual = originNormalizeMetadata($actual);
    $expected = originNormalizeMetadata($expected);
    if ($actual === $expected) {
        return;
    }
    if (in_array($kind, ['indexes', 'foreign_keys'], true)) {
        throw new RuntimeException('Origin fixture: REI-60 '.$kind.' definition changed: '.json_encode([
            'expected' => $expected,
            'observed' => $actual,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    originRequire(false, 'REI-60 '.$kind.' definition changed');
}

function originAssertSchemaDelta(array $before, array $after, bool $reversed = false): array
{
    if (($before['driver'] ?? null) !== 'mysql') {
        return ['scope' => 'Exact origin metadata contract requires MySQL; SQLite exercises functional compatibility only'];
    }
    $rei60 = originRei60Schema();
    $newTables = $reversed ? [] : ['company_idempotency_requests', 'interest_product_index', 'interest_product_index_state', 'law_beta_applications', 'law_case_publication_reviews', 'law_contact_import_batches', 'law_task_events', 'law_tasks', 'platform_session_authorities'];
    $added = [];
    foreach ($after['columns'] as $row) {
        if (! in_array($row, $before['columns'], true)) {
            $added[] = array_slice($row, 0, 4);
        }
    }
    foreach ($before['columns'] as $row) {
        originRequire(in_array($row, $after['columns'], true), 'existing column metadata changed: '.$row[0].'.'.$row[1]);
    }
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
        foreach (['columns', 'indexes', 'foreign_keys', 'tables'] as $kind) {
            $actual = array_values(array_filter($after[$kind], fn ($row) => in_array($row[0], ['company_idempotency_requests', 'platform_session_authorities'], true)));
            originRequireRei60Metadata($actual, $rei60[$kind], $kind);
        }
        $index = array_values(array_filter($after['indexes'], fn ($row) => $row[0] === 'law_cases' && $row[1] === 'law_cases_last_official_movement_at_index'));
        originRequire(count($index) === 1 && $index[0][2] === 1 && $index[0][3] === 1 && $index[0][4] === 'last_official_movement_at', 'projection index definition changed');
        $productIndex = array_values(array_filter($after['indexes'], fn ($row) => $row[0] === 'interest_product_index' && $row[1] === 'interest_product_index_product_code_interest_id_index'));
        originRequire(count($productIndex) === 2 && $productIndex[0][2] === 1 && $productIndex[0][3] === 1 && $productIndex[0][4] === 'product_code' && $productIndex[1][2] === 1 && $productIndex[1][3] === 2 && $productIndex[1][4] === 'interest_id', 'interest code index definition changed');
        $interestPrimary = array_values(array_filter($after['indexes'], fn ($row) => $row[0] === 'interest_product_index' && $row[1] === 'PRIMARY'));
        originRequire(count($interestPrimary) === 2 && $interestPrimary[0][2] === 0 && $interestPrimary[0][3] === 1 && $interestPrimary[0][4] === 'interest_id' && $interestPrimary[1][2] === 0 && $interestPrimary[1][3] === 2 && $interestPrimary[1][4] === 'product_code', 'interest unique key changed');
        $statePrimary = array_values(array_filter($after['indexes'], fn ($row) => $row[0] === 'interest_product_index_state' && $row[1] === 'PRIMARY'));
        originRequire(count($statePrimary) === 1 && $statePrimary[0][2] === 0 && $statePrimary[0][3] === 1 && $statePrimary[0][4] === 'id', 'interest state primary key changed');
        $betaIndexes = array_values(array_filter($after['indexes'], fn ($row) => $row[0] === 'law_beta_applications'));
        originRequire(originNormalizeMetadata($betaIndexes) === originNormalizeMetadata([
            ['law_beta_applications', 'law_beta_applications_campaign_email_uq', 0, 1, 'campaign_code', 'A', null, 'BTREE', 'YES', null],
            ['law_beta_applications', 'law_beta_applications_campaign_email_uq', 0, 2, 'email', 'A', null, 'BTREE', 'YES', null],
            ['law_beta_applications', 'law_beta_applications_campaign_status_idx', 1, 1, 'campaign_code', 'A', null, 'BTREE', 'YES', null],
            ['law_beta_applications', 'law_beta_applications_campaign_status_idx', 1, 2, 'status', 'A', null, 'BTREE', 'YES', null],
            ['law_beta_applications', 'law_beta_applications_campaign_status_idx', 1, 3, 'created_at', 'A', null, 'BTREE', 'YES', null],
            ['law_beta_applications', 'PRIMARY', 0, 1, 'id', 'A', null, 'BTREE', 'YES', null],
        ]), 'beta application index definition changed');
        $interestForeignKey = array_values(array_filter($after['foreign_keys'], fn ($row) => $row[0] === 'interest_product_index' && $row[2] === 'interest_id'));
        originRequire(count($interestForeignKey) === 1 && $interestForeignKey[0][4] === 'product_interests' && $interestForeignKey[0][5] === 'id' && $interestForeignKey[0][7] === 'CASCADE', 'interest index foreign key or cascade changed');
        $interestId = array_values(array_filter($after['columns'], fn ($row) => $row[0] === 'interest_product_index' && $row[1] === 'interest_id'));
        $productCode = array_values(array_filter($after['columns'], fn ($row) => $row[0] === 'interest_product_index' && $row[1] === 'product_code'));
        originRequire(count($interestId) === 1 && $interestId[0][6] === 'ascii' && $interestId[0][7] === 'ascii_bin', 'interest identifier collation changed');
        originRequire(count($productCode) === 1 && $productCode[0][6] === 'utf8mb4' && $productCode[0][7] === 'utf8mb4_bin', 'interest product code collation changed');
    }

    return ['status' => 'passed', 'added_columns' => $added, 'new_tables' => $newTables,
        'existing_columns_indexes_foreign_keys_tables_unchanged' => true,
        'retained_timestamp_columns' => ['audit_events.created_at', 'support_accesses.starts_at', 'support_accesses.ends_at'],
        'rollback_policy' => 'Production forward-fix-only. Laboratory rollback removes repair ledger but deliberately retains payments.provider_checkout_url and all values.',
        'scope' => 'All original column/default/extra/collation/generation, index/FK and engine/collation metadata retained; exact new column names/types/nullability. New-table indexes/FKs captured for review.'];
}

function originAssertTimes(string $sandbox): array
{
    if (DB::getDriverName() !== 'mysql') {
        return ['scope' => 'TIMESTAMP/session-timezone semantics require MySQL'];
    }
    $ids = json_decode(file_get_contents($sandbox.'/origin-temporal-ids.json'), true, flags: JSON_THROW_ON_ERROR);
    $read = function () use ($ids): array {
        $audit = DB::selectOne('SELECT created_at,UNIX_TIMESTAMP(created_at) AS epoch FROM audit_events WHERE id=?', [$ids['audit']]);
        $support = DB::selectOne('SELECT starts_at,ends_at,UNIX_TIMESTAMP(starts_at) AS start_epoch,UNIX_TIMESTAMP(ends_at) AS end_epoch FROM support_accesses WHERE id=?', [$ids['support']]);

        return ['audit' => (array) $audit, 'support' => (array) $support];
    };
    originRequire(DB::selectOne('SELECT @@session.time_zone AS zone')->zone === '+00:00', 'expected UTC session');
    $utc = $read();
    try {
        DB::statement("SET SESSION time_zone='-03:00'");
        $minusThree = $read();
    } finally {
        DB::statement("SET SESSION time_zone='+00:00'");
    }
    originRequire($utc['audit']['created_at'] === '2026-11-07 12:34:56' && $minusThree['audit']['created_at'] === '2026-11-07 09:34:56', 'audit wall clock conversion');
    foreach (['starts_at', 'ends_at'] as $column) {
        originRequire($utc['support'][$column] === '2026-11-07 12:34:56' && $minusThree['support'][$column] === '2026-11-07 09:34:56', 'support wall clock conversion');
    }
    originRequire($utc['audit']['epoch'] === $minusThree['audit']['epoch'] && $utc['support']['start_epoch'] === $minusThree['support']['start_epoch'] && $utc['support']['end_epoch'] === $minusThree['support']['end_epoch'], 'epochs retained across sessions');
    $result = ['utc' => $utc, 'minus03' => $minusThree, 'restored_session_timezone' => '+00:00'];
    if (is_file($sandbox.'/origin-temporal-before.json')) {
        originRequire(json_decode(file_get_contents($sandbox.'/origin-temporal-before.json'), true) === $result, 'fixture instants changed after migrations');
    }

    return $result;
}
