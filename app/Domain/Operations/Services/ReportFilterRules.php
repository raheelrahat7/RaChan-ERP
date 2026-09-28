<?php

namespace App\Domain\Operations\Services;

use Illuminate\Validation\Rule;

class ReportFilterRules
{
    /** @return array<string,list<mixed>> */
    public function for(?int $organizationId, bool $hasFrom): array
    {
        return [
            'property_id' => ['nullable', 'integer', Rule::exists('properties', 'id')->where('organization_id', $organizationId)],
            'vendor_id' => ['nullable', 'integer', Rule::exists('maintenance_vendors', 'id')->where('organization_id', $organizationId)],
            'assigned_to' => ['nullable', 'integer', Rule::exists('organization_user', 'user_id')->where('organization_id', $organizationId)],
            'status' => ['nullable', 'in:open,in_progress,on_hold,completed,cancelled'], 'priority' => ['nullable', 'in:low,medium,high,urgent'],
            'created_from' => ['nullable', 'date_format:Y-m-d'], 'created_to' => ['nullable', 'date_format:Y-m-d', Rule::when($hasFrom, 'after_or_equal:created_from')],
        ];
    }
}
