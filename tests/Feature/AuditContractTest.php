<?php

namespace Tests\Feature;

use App\Logging\SensitiveLogTap;
use App\Services\AuditRecorder;
use App\Services\PlatformAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Tests\TestCase;

class AuditContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_and_tenant_audit_use_the_shared_masked_contract(): void
    {
        $companyId = 'COM'.str_repeat('A', 27);
        DB::table('companies')->insert(['id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '12345678000195', 'legal_name' => 'Empresa Exemplo', 'status' => 'ativa', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);

        app(PlatformAudit::class)->record(null, 'backoffice.login_failed', 'platform_admin', null, reason: 'Tentativa: CPF 123.456.789-01; token=segredo Bearer abc.def.ghi',
            metadata: ['nested' => ['identification' => ['number' => '12345678901'], 'card' => ['number' => '4111 1111 1111 1111'], 'password' => 'senha secreta']],
            before: null, after: null, actorType: 'anonymous', channel: 'http', originContext: 'api/backoffice/auth/login', correlationId: 'request-123');
        $platform = DB::table('platform_audit_events')->where('action', 'backoffice.login_failed')->first();

        $this->assertSame('anonymous', $platform->actor_type);
        $this->assertSame('http', $platform->origin_channel);
        $this->assertSame('api/backoffice/auth/login', $platform->origin_context);
        $this->assertSame('request-123', $platform->correlation_id);
        $this->assertSame('{}', $platform->before_masked);
        $this->assertSame('{}', $platform->after_masked);
        $this->assertStringNotContainsString('123.456.789-01', $platform->reason);
        $this->assertStringNotContainsString('segredo', $platform->reason);
        $this->assertStringNotContainsString('12345678901', $platform->metadata);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $platform->metadata);
        $this->assertStringNotContainsString('senha secreta', $platform->metadata);
        $this->assertContains('company_id', json_decode($platform->metadata, true)['not_applicable']);
        $this->assertContains('before_state,after_state', json_decode($platform->metadata, true)['not_applicable']);
        $this->assertSame(now()->addDays(180)->format('Y-m-d'), substr($platform->expires_at, 0, 10));

        app(AuditRecorder::class)->company($companyId, null, 'subscription', 'ASS'.str_repeat('B', 27), 'create', null, ['status' => 'aguardando_pagamento'],
            actorType: 'customer', channel: 'http', originContext: 'api/subscriptions/checkout', correlationId: 'checkout-42');
        $tenant = DB::table('audit_events')->where('entity_type', 'subscription')->first();
        $this->assertSame($companyId, $tenant->company_id);
        $this->assertSame('customer', $tenant->actor_type);
        $this->assertSame('checkout-42', $tenant->correlation_id);
        $this->assertSame('{}', $tenant->before_masked);
        $this->assertSame('Não aplicável a esta classe de evento.', $tenant->reason);
        $this->assertContains('actor_user_id', json_decode($tenant->metadata, true)['not_applicable']);
    }

    public function test_expired_audits_are_pruned_and_current_audits_remain(): void
    {
        $companyId = 'COM'.str_repeat('C', 27);
        DB::table('companies')->insert(['id' => $companyId, 'document_type' => 'cnpj', 'document_number' => '98765432000198', 'legal_name' => 'Empresa Retenção', 'status' => 'ativa', 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        app(PlatformAudit::class)->record(null, 'backoffice.old_event', before: [], after: []);
        app(PlatformAudit::class)->record(null, 'backoffice.current_event', before: [], after: []);
        DB::table('platform_audit_events')->where('action', 'backoffice.old_event')->update(['expires_at' => now()->subSecond()]);
        app(AuditRecorder::class)->company($companyId, null, 'membership', 'MEM'.str_repeat('C', 27), 'create', after: ['status' => 'active']);
        app(AuditRecorder::class)->company($companyId, null, 'membership', 'MEM'.str_repeat('D', 27), 'create', after: ['status' => 'active']);
        DB::table('audit_events')->where('entity_id', 'MEM'.str_repeat('C', 27))->update(['expires_at' => now()->subSecond()]);

        Artisan::call('fokus:prune-expired-data');

        $this->assertDatabaseMissing('platform_audit_events', ['action' => 'backoffice.old_event']);
        $this->assertDatabaseHas('platform_audit_events', ['action' => 'backoffice.current_event']);
        $this->assertDatabaseMissing('audit_events', ['entity_id' => 'MEM'.str_repeat('C', 27)]);
        $this->assertDatabaseHas('audit_events', ['entity_id' => 'MEM'.str_repeat('D', 27)]);
    }

    public function test_log_processor_redacts_secrets_documents_and_card_numbers_from_message_and_context(): void
    {
        $logger = new Logger('audit-test');
        $handler = new TestHandler();
        $logger->pushHandler($handler);
        (new SensitiveLogTap())(new \Illuminate\Log\Logger($logger));
        $logger->error('Falha token=token-sentinela CPF 123.456.789-01 cartão 4111 1111 1111 1111 código de acesso 654321', [
            'password' => 'senha-sentinela', 'nested' => ['mfa_code' => '654321', 'safe' => 'ok'],
            'exception' => new \RuntimeException('access_token=segredo Bearer test-secret 987.654.321-00'),
        ]);
        $output = json_encode($handler->getRecords(), JSON_INVALID_UTF8_SUBSTITUTE);

        foreach (['token-sentinela', '123.456.789-01', '4111 1111 1111 1111', 'senha-sentinela', '654321', 'test-secret', '987.654.321-00'] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        $this->assertStringContainsString('ok', $output);
    }

    public function test_real_file_log_and_audit_pipeline_redact_synthetic_security_canaries(): void
    {
        $canaries = json_decode(file_get_contents(base_path('tests/fixtures/security-log-canaries.json')), true, flags: JSON_THROW_ON_ERROR);
        $path = storage_path('logs/security-canary.log');
        $originalPath = config('logging.channels.single.path');
        @unlink($path);

        Config::set('logging.channels.single.path', $path);
        Log::forgetChannel('single');
        $channel = Log::channel('single');
        $channel->error('Security canary password='.$canaries['password'].' token='.$canaries['token'].' MFA '.$canaries['mfa'], [
            'password' => $canaries['password'],
            'access_token' => $canaries['token'],
            'mfa_code' => $canaries['mfa'],
            'document_number' => $canaries['document'],
            'card_number' => $canaries['card'],
            'raw_payload' => $canaries['gateway'],
        ]);

        app(PlatformAudit::class)->record(null, 'security.canary_pipeline_test', 'security_canary', null,
            reason: 'password='.$canaries['password'].' token='.$canaries['token'],
            metadata: [
                'mfa_code' => $canaries['mfa'],
                'document_number' => $canaries['document'],
                'card_number' => $canaries['card'],
                'raw_payload' => ['body' => $canaries['gateway']],
            ],
            actorType: 'system', channel: 'security-test');

        $event = DB::table('platform_audit_events')->where('action', 'security.canary_pipeline_test')->first();
        $contents = (string) file_get_contents($path).(string) $event->reason.(string) $event->metadata;
        foreach ($canaries as $canary) {
            $this->assertFalse(str_contains($contents, $canary), 'A raw synthetic security canary reached an output.');
        }
        $this->assertStringContainsString('[redigido]', $contents);
        Log::forgetChannel('single');
        Config::set('logging.channels.single.path', $originalPath);
    }

    public function test_historical_audit_rows_are_scrubbed_and_get_180_day_expiration(): void
    {
        $migration = require base_path('database/migrations/2026_09_24_000100_unify_audit_contract_and_scrub_legacy_data.php');
        $migration->down();
        Schema::table('platform_audit_events', function (\Illuminate\Database\Schema\Blueprint $table): void {
            $table->timestamp('expires_at')->nullable()->change();
        });
        $createdAt = now()->subDays(120);
        DB::table('platform_audit_events')->insert([
            'id' => 'PAD'.str_repeat('H', 27), 'action' => 'billing.legacy_event', 'reason' => 'CPF 123.456.789-01 token=historical-secret',
            'metadata' => json_encode(['password' => 'old-password', 'status' => 'approved', 'card' => ['number' => '4111 1111 1111 1111']]),
            'before_masked' => json_encode(['identification' => ['number' => '12345678901']]), 'after_masked' => null,
            'created_at' => $createdAt, 'expires_at' => null,
        ]);
        $migration->up();

        $event = DB::table('platform_audit_events')->where('action', 'billing.legacy_event')->first();
        $this->assertSame('system', $event->actor_type);
        $this->assertSame($createdAt->copy()->addDays(180)->format('Y-m-d'), substr($event->expires_at, 0, 10));
        $this->assertStringNotContainsString('123.456.789-01', $event->reason);
        $this->assertStringNotContainsString('historical-secret', $event->reason);
        $this->assertStringNotContainsString('old-password', $event->metadata);
        $this->assertStringNotContainsString('4111 1111 1111 1111', $event->metadata);
        $this->assertContains('company_id', json_decode($event->metadata, true)['not_applicable']);
        $this->assertFalse(Schema::hasColumn('payments', 'provider_payload'));
    }
}
