<?php

namespace App\Integrations\Lead;

use Illuminate\Database\Connection;

class LeadUsageReader
{
    public function __construct(private readonly LeadConnections $connections) {}

    public function forCompany(string $companyId): array
    {
        try {
            $row = $this->connections->metrics(fn (Connection $connection) => $connection
                ->table('lead_admin.company_usage')->where('company_id', $companyId)
                ->first(['properties_count', 'developments_count', 'contacts_count', 'inquiries_count', 'referenced_users_count', 'storage_bytes']));
        } catch (LeadUnavailable $exception) {
            return ['available' => false, 'reason' => $exception->reason, 'metrics' => null];
        }
        if ($row === null) {
            return ['available' => false, 'reason' => 'not_provisioned', 'metrics' => null];
        }

        return [
            'available' => true,
            'reason' => null,
            'metrics' => [
                'properties_count' => (int) $row->properties_count,
                'developments_count' => (int) $row->developments_count,
                'contacts_count' => (int) $row->contacts_count,
                'inquiries_count' => (int) $row->inquiries_count,
                'referenced_users_count' => (int) $row->referenced_users_count,
                'storage_bytes' => (int) $row->storage_bytes,
            ],
            'measured_at' => now()->toIso8601String(),
        ];
    }
}
