<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Operations\Services\JobCardAccess;
use App\Models\CrmLead;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RecordSearch
{
    public function __construct(private LeadVisibility $leads, private JobCardAccess $jobs) {}

    /** @return list<array{id:int,label:string,sublabel:string}> */
    public function search(Organization $org, User $actor, User $assignee, string $type, string $term): array
    {
        $pattern = '%'.$term.'%';
        $prefix = $term.'%';

        if ($type === 'lead') {
            if (! $actor->can('viewCrm', $org) || ! $assignee->can('viewCrm', $org)) {
                return [];
            }
            $query = $this->leads->scope(CrmLead::where('organization_id', $org->id), $org, $actor);
            $query = $this->leads->scope($query, $org, $assignee);

            return array_values($query->where(fn ($q) => $q->where('first_name', 'like', $pattern)->orWhere('last_name', 'like', $pattern)
                ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$pattern])
                ->orWhere('company', 'like', $pattern)->orWhere('email', 'like', $pattern))
                ->orderByRaw('CASE WHEN first_name = ? OR last_name = ? THEN 0 WHEN first_name LIKE ? OR last_name LIKE ? THEN 1 ELSE 2 END', [$term, $term, $prefix, $prefix])
                ->latest()->limit(20)->get(['id', 'first_name', 'last_name', 'company', 'email'])
                ->map(fn (CrmLead $lead) => ['id' => (int) $lead->id, 'label' => trim($lead->first_name.' '.$lead->last_name),
                    'sublabel' => (string) ($lead->company ?: $lead->email ?: '')])->all());
        }

        if ($type === 'job') {
            $query = $this->jobs->scope(MaintenanceRequest::query(), $org, $actor);
            $query = $this->jobs->scope($query, $org, $assignee);

            return array_values($query->where(fn ($q) => $q->where('reference', 'like', $pattern)->orWhere('title', 'like', $pattern))
                ->orderByRaw('CASE WHEN reference = ? THEN 0 WHEN reference LIKE ? THEN 1 ELSE 2 END', [$term, $prefix])
                ->latest()->limit(20)->get(['id', 'reference', 'title'])
                ->map(fn (MaintenanceRequest $job) => ['id' => (int) $job->id, 'label' => (string) $job->reference,
                    'sublabel' => (string) $job->title])->all());
        }

        $ability = in_array($type, ['listing', 'unit'], true) ? 'viewInventory' : 'viewTransactions';
        if (! $actor->can($ability, $org) || ! $assignee->can($ability, $org)) {
            return [];
        }

        if ($type === 'listing') {
            $rows = DB::table('listings as record')->join('units as unit', 'unit.id', '=', 'record.unit_id')
                ->join('properties as property', 'property.id', '=', 'unit.property_id')
                ->where('record.organization_id', $org->id)->where('unit.organization_id', $org->id)
                ->where('property.organization_id', $org->id)
                ->where(fn ($q) => $q->where('record.reference', 'like', $pattern)
                    ->orWhere('property.name', 'like', $pattern)->orWhere('property.city', 'like', $pattern))
                ->orderByRaw('CASE WHEN record.reference = ? THEN 0 WHEN record.reference LIKE ? THEN 1 ELSE 2 END', [$term, $prefix])
                ->orderByDesc('record.id')->limit(20)
                ->get(['record.id', 'record.reference', 'property.name as property_name', 'property.city']);

            return array_values($rows->map(fn ($row) => ['id' => (int) $row->id, 'label' => (string) $row->reference,
                'sublabel' => trim((string) $row->property_name.($row->city ? ' · '.$row->city : ''))])->all());
        }

        if ($type === 'unit') {
            $rows = DB::table('units as record')->join('properties as property', 'property.id', '=', 'record.property_id')
                ->where('record.organization_id', $org->id)->where('property.organization_id', $org->id)
                ->where(fn ($q) => $q->where('record.number', 'like', $pattern)->orWhere('property.name', 'like', $pattern))
                ->orderByRaw('CASE WHEN record.number = ? THEN 0 WHEN record.number LIKE ? THEN 1 ELSE 2 END', [$term, $prefix])
                ->orderByDesc('record.id')->limit(20)->get(['record.id', 'record.number', 'property.name as property_name']);

            return array_values($rows->map(fn ($row) => ['id' => (int) $row->id, 'label' => (string) $row->number,
                'sublabel' => (string) $row->property_name])->all());
        }

        $table = match ($type) {
            'reservation' => 'reservations',
            'lease' => 'leases',
            'sale' => 'sales_contracts',
            default => throw new \InvalidArgumentException('Unsupported record type.'),
        };
        $rows = DB::table($table.' as record')->join('units as unit', 'unit.id', '=', 'record.unit_id')
            ->where('record.organization_id', $org->id)->where('unit.organization_id', $org->id)
            ->where(fn ($q) => $q->where('record.reference', 'like', $pattern)->orWhere('unit.number', 'like', $pattern))
            ->orderByRaw('CASE WHEN record.reference = ? THEN 0 WHEN record.reference LIKE ? THEN 1 ELSE 2 END', [$term, $prefix])
            ->orderByDesc('record.id')->limit(20)->get(['record.id', 'record.reference', 'unit.number as unit_number']);

        return array_values($rows->map(fn ($row) => ['id' => (int) $row->id, 'label' => (string) $row->reference,
            'sublabel' => (string) $row->unit_number])->all());
    }
}
