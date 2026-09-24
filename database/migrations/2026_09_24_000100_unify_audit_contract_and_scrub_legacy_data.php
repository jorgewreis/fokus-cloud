<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->string('actor_type', 20)->default('system');
            $table->text('reason')->nullable();
            $table->json('metadata')->nullable();
            $table->string('origin_channel', 20)->default('cli');
            $table->string('origin_context', 255)->nullable();
            $table->string('correlation_id', 128)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
        });
        Schema::table('platform_audit_events', function (Blueprint $table): void {
            $table->string('actor_type', 20)->default('system');
            $table->string('origin_channel', 20)->default('cli');
            $table->string('origin_context', 255)->nullable();
            $table->string('correlation_id', 128)->nullable();
        });

        DB::table('audit_events')->orderBy('id')->chunk(200, function ($events): void {
            foreach ($events as $event) {
                DB::table('audit_events')->where('id', $event->id)->update([
                    'actor_type' => $event->actor_user_id ? 'customer' : 'system',
                    'reason' => 'Não aplicável a evento histórico sem motivo registrado.',
                    'metadata' => $this->withNotApplicable(null, $event->actor_user_id ? [] : ['actor_user_id']),
                    'origin_channel' => $event->ip_address || $event->user_agent ? 'http' : 'cli',
                    'before_masked' => $this->cleanJson($event->before_masked),
                    'after_masked' => $this->cleanJson($event->after_masked),
                    'expires_at' => now()->parse($event->created_at)->addDays(180),
                ]);
            }
        });
        DB::table('platform_audit_events')->orderBy('id')->chunk(200, function ($events): void {
            foreach ($events as $event) {
                DB::table('platform_audit_events')->where('id', $event->id)->update([
                    'actor_type' => $event->platform_admin_id ? 'admin' : (str_contains($event->action, 'login') || str_contains($event->action, 'auth') ? 'anonymous' : 'system'),
                    'origin_channel' => $event->ip_address || $event->user_agent ? 'http' : 'cli',
                    'reason' => $this->cleanText((string) ($event->reason ?: 'Não aplicável a evento histórico sem motivo registrado.')),
                    'metadata' => $this->withNotApplicable($event->metadata, array_values(array_filter([
                        $event->platform_admin_id ? null : 'platform_admin_id', $event->entity_type ? null : 'entity', $event->company_id ? null : 'company_id',
                        ! $event->before_masked && ! $event->after_masked && (str_contains($event->action, 'viewed') || str_contains($event->action, 'login') || str_contains($event->action, 'mfa') || str_contains($event->action, 'logout')) ? 'before_state,after_state' : null,
                    ]))),
                    'before_masked' => $this->cleanJson($event->before_masked),
                    'after_masked' => $this->cleanJson($event->after_masked),
                    'user_agent' => $event->user_agent ? $this->cleanText($event->user_agent) : null,
                    'expires_at' => now()->parse($event->created_at)->addDays(180),
                ]);
            }
        });

        foreach (['billing_provider_events', 'billing_checkout_attempts'] as $table) {
            if (! Schema::hasTable($table)) continue;
            foreach (['error_message', 'payload_sanitized', 'request_snapshot_sanitized', 'response_snapshot_sanitized'] as $column) {
                if (! Schema::hasColumn($table, $column)) continue;
                DB::table($table)->orderBy('id')->chunk(200, function ($rows) use ($table, $column): void {
                    foreach ($rows as $row) {
                        if ($row->{$column} === null) continue;
                        $value = str_contains($column, 'payload') || str_contains($column, 'snapshot')
                            ? $this->cleanJson($row->{$column})
                            : $this->cleanText((string) $row->{$column});
                        DB::table($table)->where('id', $row->id)->update([$column => $value]);
                    }
                });
            }
        }
        if (Schema::hasTable('billing_provider_events')) {
            foreach (['request_id', 'notification_id', 'resource_id', 'event_type', 'action'] as $column) {
                DB::table('billing_provider_events')->whereNotNull($column)->orderBy('id')->chunk(200, function ($events) use ($column): void {
                    foreach ($events as $event) {
                        DB::table('billing_provider_events')->where('id', $event->id)->update([$column => $this->cleanText((string) $event->{$column})]);
                    }
                });
            }
        }
        foreach ([
            'subscriptions' => ['provider_payload_sanitized'],
            'payments' => ['provider_payload_sanitized'],
            'platform_support_sessions' => ['start_user_agent', 'end_user_agent', 'reason'],
            'subscription_changes' => ['reason'],
            'refund_requests' => ['provider_payload_sanitized', 'reason'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) continue;
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) continue;
                DB::table($table)->whereNotNull($column)->orderBy('id')->chunk(200, function ($rows) use ($table, $column): void {
                    foreach ($rows as $row) {
                        $value = str_contains($column, 'payload') ? $this->providerJson((string) $row->{$column}) : $this->cleanText((string) $row->{$column});
                        DB::table($table)->where('id', $row->id)->update([$column => $value]);
                    }
                });
            }
        }

        if (Schema::hasColumn('payments', 'provider_payload')) {
            if (Schema::hasColumn('payments', 'provider_payload_sanitized')) {
                DB::table('payments')->orderBy('id')->chunk(200, function ($payments): void {
                    foreach ($payments as $payment) {
                        if (! $payment->provider_payload) continue;
                        $clean = $this->providerJson($payment->provider_payload);
                        DB::table('payments')->where('id', $payment->id)->update(['provider_payload_sanitized' => $clean]);
                    }
                });
            }
            Schema::table('payments', function (Blueprint $table): void {
                $table->dropColumn('provider_payload');
            });
        }

        Schema::table('platform_audit_events', function (Blueprint $table): void {
            $table->text('reason')->nullable(false)->change();
            $table->timestamp('expires_at')->nullable(false)->change();
        });
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->text('reason')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // Sensitive historical payloads are intentionally not restored.
        if (Schema::hasTable('payments') && ! Schema::hasColumn('payments', 'provider_payload')) {
            Schema::table('payments', function (Blueprint $table): void {
                $table->json('provider_payload')->nullable();
            });
        }
        Schema::table('platform_audit_events', function (Blueprint $table): void {
            $table->text('reason')->nullable()->change();
            $table->timestamp('expires_at')->nullable()->change();
            $table->dropColumn(['actor_type', 'origin_channel', 'origin_context', 'correlation_id']);
        });
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropColumn(['actor_type', 'reason', 'metadata', 'origin_channel', 'origin_context', 'correlation_id', 'ip_address', 'user_agent']);
        });
    }

    private function cleanJson(?string $json): string
    {
        $decoded = $json ? json_decode($json, true) : [];
        $clean = $this->cleanValue(is_array($decoded) ? $decoded : []);
        return json_encode($clean === [] ? (object) [] : $clean, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    private function withNotApplicable(?string $json, array $fields): string
    {
        $decoded = json_decode($this->cleanJson($json), true);
        $decoded = is_array($decoded) ? $decoded : [];
        $decoded['not_applicable'] = array_values(array_unique([...($decoded['not_applicable'] ?? []), ...$fields]));
        return json_encode($decoded, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }

    private function cleanValue(mixed $value, ?string $key = null): mixed
    {
        if ($key && preg_match('/password|passwd|secret|token|authorization|cookie|mfa|otp|code|cpf|cnpj|document|identification|cvv|cvc|card|raw.?payload/i', $key)) return '[redigido]';
        if (is_array($value)) {
            foreach ($value as $childKey => $child) $value[$childKey] = $this->cleanValue($child, (string) $childKey);
            return $value;
        }
        return is_string($value) ? $this->cleanText($value) : $value;
    }

    private function cleanText(string $value): string
    {
        $value = preg_replace('/(mfa|otp|code|c[oó]digo(?:\s+de\s+acesso)?)\s+\d{4,8}\b/iu', '$1 [redigido]', $value) ?? $value;
        $value = preg_replace('/(password|senha|token|secret|authorization|mfa|otp|codigo|código)\s*[:= ]\s*[^\s,;]+/iu', '$1=[redigido]', $value) ?? $value;
        $value = preg_replace('/\bBearer\s+[A-Za-z0-9._~+\/-]+=*/i', 'Bearer [redigido]', $value) ?? $value;
        $value = preg_replace('/(?<!\d)(?:\d[ .\/-]?){10}\d(?!\d)|(?<!\d)(?:\d[ .\/-]?){13,18}\d(?!\d)/', '[dado sensível redigido]', $value) ?? $value;
        return preg_replace('/([\w.+-])[\w.+-]*(@[\w.-]+\.[A-Za-z]{2,})/', '$1***$2', $value) ?? $value;
    }

    private function providerJson(string $json): string
    {
        $value = json_decode($json, true);
        if (! is_array($value)) return '{}';
        $allowed = '/^(id|type|topic|action|status|status_detail|external_reference|preapproval_id|subscription_id|payment_id|refund_id|date_[a-z_]+|transaction_amount|amount|currency_id|currency|payer_email|reason|collector_id|live_mode|api_version)$/i';
        $filtered = [];
        foreach ($value as $key => $item) {
            if (preg_match($allowed, (string) $key)) $filtered[$key] = is_string($item) ? $this->cleanText($item) : $item;
        }
        return json_encode($filtered, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
};
