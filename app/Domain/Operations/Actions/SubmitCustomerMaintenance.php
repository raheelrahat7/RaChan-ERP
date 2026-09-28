<?php

namespace App\Domain\Operations\Actions;

use App\Domain\CustomerPortal\Models\PortalGrant;
use App\Domain\CustomerPortal\Models\PortalServiceRequest;
use App\Domain\CustomerPortal\Services\PortalAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Unit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmitCustomerMaintenance
{
    public function __construct(private PortalAccess $access, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input */
    public function handle(User $actor, PortalGrant $grant, array $input): PortalServiceRequest
    {
        $grant = $this->access->grant($actor, $grant->id);
        abort_unless($grant->role === 'tenant', 403);
        $values = Validator::make($input, ['lease_id' => ['required', 'integer'], 'title' => ['required', 'string', 'max:255'], 'description' => ['nullable', 'string', 'max:5000'], 'priority' => ['required', 'in:low,medium,high,urgent'], 'operation_key' => ['required', 'uuid']])->validate();

        return DB::transaction(function () use ($actor, $grant, $values): PortalServiceRequest {
            $org = Organization::whereKey($grant->organization_id)->lockForUpdate()->firstOrFail();
            $grant = $this->access->grant($actor, $grant->id, true);
            abort_unless($grant->role === 'tenant', 403);
            $title = trim($values['title']);
            $description = trim((string) ($values['description'] ?? ''));
            if ($title === '') {
                throw ValidationException::withMessages(['title' => __('Describe the requested service.')]);
            }
            $existing = PortalServiceRequest::where('organization_id', $org->id)->where('operation_key', $values['operation_key'])->first();
            if ($existing !== null) {
                if ($existing->portal_grant_id !== $grant->id || $existing->lease_id !== (int) $values['lease_id'] || $existing->title !== $title || $existing->description !== ($description === '' ? null : $description) || $existing->priority !== $values['priority']) {
                    throw ValidationException::withMessages(['operation_key' => __('This request key already has different details.')]);
                }

                return $existing;
            }
            $lease = Lease::where('organization_id', $org->id)->where('tenant_id', $grant->tenant_id)->findOrFail((int) $values['lease_id']);
            $today = CarbonImmutable::now($org->timezone)->format('Y-m-d');
            if ($lease->status !== 'active' || $lease->starts_on->format('Y-m-d') > $today || $lease->ends_on->format('Y-m-d') < $today) {
                throw ValidationException::withMessages(['lease_id' => __('Service requests require a currently active lease.')]);
            }
            $unit = Unit::where('organization_id', $org->id)->findOrFail($lease->unit_id);
            $job = MaintenanceRequest::create(['organization_id' => $org->id, 'property_id' => $unit->property_id, 'unit_id' => $unit->id, 'reference' => 'MNT-'.Str::upper(Str::random(8)), 'title' => $title, 'description' => $description === '' ? null : $description, 'priority' => $values['priority'], 'requires_manager_confirmation' => true]);
            $record = PortalServiceRequest::create(['organization_id' => $org->id, 'portal_grant_id' => $grant->id, 'lease_id' => $lease->id, 'maintenance_request_id' => $job->id, 'operation_key' => $values['operation_key'], 'title' => $title, 'description' => $description === '' ? null : $description, 'priority' => $values['priority']]);
            $this->audit->handle($org, $actor, 'operations.customer_service.requested', $job, ['portal_grant_id' => $grant->id, 'lease_id' => $lease->id]);

            return $record;
        });
    }
}
