<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuditRecorder
{
    public function __construct(private readonly AuditSanitizer $sanitizer) {}

    public function platform(?string $adminId, string $action, ?string $entityType = null, ?string $entityId = null, ?string $companyId = null, ?string $reason = null, ?string $ticket = null, ?array $metadata = null, ?array $before = null, ?array $after = null, ?Request $request = null, ?string $actorType = null, ?string $channel = null, ?string $originContext = null, ?string $correlationId = null): void
    {
        $createdAt = now();
        $source = $this->source($request, $channel, $originContext, $correlationId);
        $reason = trim((string) $reason);
        if ($reason === '') $reason = 'Não aplicável a esta classe de evento.';
        $resolvedActorType = $actorType ?: ($adminId ? 'admin' : ($this->anonymousAction($action) ? 'anonymous' : 'system'));
        $notApplicable = [];
        if ($entityType === null && $entityId === null) $notApplicable[] = 'entity';
        if ($companyId === null) $notApplicable[] = 'company_id';
        if (in_array($resolvedActorType, ['anonymous', 'gateway', 'system'], true) && $adminId === null) $notApplicable[] = 'platform_admin_id';
        if ($before === null && $after === null && (str_contains($action, 'viewed') || str_contains($action, 'login') || str_contains($action, 'mfa') || str_contains($action, 'logout'))) $notApplicable[] = 'before_state,after_state';
        $safeMetadata = [...($metadata ?? []), 'not_applicable' => array_values(array_unique([...($metadata['not_applicable'] ?? []), ...$notApplicable]))];
        DB::table('platform_audit_events')->insert([
            'id' => PrefixedUlid::make('PAD'), 'platform_admin_id' => $adminId, 'actor_type' => $resolvedActorType,
            'action' => $action, 'entity_type' => $entityType, 'entity_id' => $entityId, 'company_id' => $companyId,
            'reason' => $this->sanitizer->sanitizeText($reason ?: 'Não aplicável a esta classe de evento.'), 'support_ticket' => $ticket,
            'metadata' => $this->encodeObject($safeMetadata),
            'before_masked' => $this->encodeObject($before ?? []),
            'after_masked' => $this->encodeObject($after ?? []),
            ...$source, 'ip_address' => $request?->ip(), 'user_agent' => $request ? $this->sanitizer->sanitizeText((string) $request->userAgent()) : null,
            'created_at' => $createdAt, 'expires_at' => $createdAt->copy()->addDays(180),
        ]);
    }

    public function company(string $companyId, ?string $actorId, string $entityType, string $entityId, string $operation, ?array $before = null, ?array $after = null, ?string $reason = null, ?Request $request = null, ?string $actorType = null, ?string $channel = null, ?string $originContext = null, ?string $correlationId = null): void
    {
        $createdAt = now();
        $source = $this->source($request, $channel, $originContext, $correlationId);
        $reason = trim((string) $reason);
        if ($reason === '') $reason = 'Não aplicável a esta classe de evento.';
        $safeMetadata = [];
        if ($actorId === null) $safeMetadata[] = 'actor_user_id';
        if ($operation === 'access' && $before === null && $after === null) $safeMetadata[] = 'before_state,after_state';
        DB::table('audit_events')->insert([
            'id' => PrefixedUlid::make('AUD'), 'company_id' => $companyId, 'actor_user_id' => $actorId,
            'actor_type' => $actorType ?: ($actorId ? 'customer' : 'system'), 'entity_type' => $entityType, 'entity_id' => $entityId, 'operation' => $operation,
            'metadata' => $this->encodeObject(['not_applicable' => $safeMetadata]),
            'reason' => $this->sanitizer->sanitizeText($reason ?: 'Não aplicável a esta classe de evento.'),
            'before_masked' => $this->encodeObject($before ?? []),
            'after_masked' => $this->encodeObject($after ?? []),
            ...$source, 'ip_address' => $request?->ip(), 'user_agent' => $request ? $this->sanitizer->sanitizeText((string) $request->userAgent()) : null,
            'expires_at' => $createdAt->copy()->addDays(180), 'created_at' => $createdAt,
        ]);
    }

    public function source(?Request $request, ?string $channel = null, ?string $context = null, ?string $correlationId = null): array
    {
        $request ??= app()->bound('request') && ! app()->runningInConsole() ? request() : null;
        $channel ??= $request ? 'http' : 'cli';
        if (! in_array($channel, ['http', 'webhook', 'scheduler', 'cli'], true)) {
            $channel = app()->runningInConsole() ? 'cli' : 'http';
        }
        $route = $request?->route();
        $context ??= $request ? ($route?->uri() ?: $request->path()) : null;
        $context = $context ? $this->sanitizer->sanitizeText($context) : null;
        $correlationId ??= $request ? ($request->header('X-Request-ID') ?: $request->header('X-Correlation-ID')) : null;
        return ['origin_channel' => $channel, 'origin_context' => $context, 'correlation_id' => $correlationId ? mb_substr($this->sanitizer->sanitizeText($correlationId), 0, 128) : null];
    }

    private function anonymousAction(string $action): bool
    {
        return str_contains($action, 'login') || str_contains($action, 'auth');
    }

    private function encodeObject(array $value): string
    {
        $sanitized = $this->sanitizer->sanitize($value);
        return json_encode($sanitized === [] ? (object) [] : $sanitized, JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}';
    }
}
