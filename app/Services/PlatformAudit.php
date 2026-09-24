<?php

namespace App\Services;

use Illuminate\Http\Request;

class PlatformAudit
{
    public function __construct(private readonly AuditRecorder $recorder) {}

    public function record(?string $adminId, string $action, ?string $entityType = null, ?string $entityId = null, ?string $companyId = null, ?string $reason = null, ?string $ticket = null, ?array $metadata = null, ?array $before = null, ?array $after = null, ?Request $request = null, ?string $actorType = null, ?string $channel = null, ?string $originContext = null, ?string $correlationId = null): void
    {
        $this->recorder->platform($adminId, $action, $entityType, $entityId, $companyId, $reason, $ticket, $metadata, $before, $after, $request, $actorType, $channel, $originContext, $correlationId);
    }
}
