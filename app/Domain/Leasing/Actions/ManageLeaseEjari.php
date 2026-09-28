<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Leasing\Models\LeaseEjariRegistration;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManageLeaseEjari
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function apply(Organization $org, User $actor, Lease $lease, array $input, ?LeaseEjariRegistration $renewal = null): LeaseEjariRegistration
    {
        return DB::transaction(function () use ($org, $actor, $lease, $input, $renewal) {
            $lockedLease = Lease::where('organization_id', $org->id)->lockForUpdate()->findOrFail($lease->id);
            abort_unless(in_array($lockedLease->status, ['draft', 'active'], true), 422, 'Ejari may only be recorded for a draft or active lease.');

            if ($renewal) {
                $renewal = LeaseEjariRegistration::where('organization_id', $org->id)->where('lease_id', $lockedLease->id)->lockForUpdate()->findOrFail($renewal->id);
                abort_unless($renewal->status === 'registered', 422, 'Only a registered Ejari record can be renewed.');
            } else {
                abort_if(LeaseEjariRegistration::where('organization_id', $org->id)->where('lease_id', $lockedLease->id)->whereIn('status', ['pending', 'registered'])->exists(), 422, 'This lease already has an open Ejari record.');
            }

            $registration = LeaseEjariRegistration::create(['organization_id' => $org->id, 'lease_id' => $lockedLease->id, 'renewal_of_id' => $renewal?->id, 'status' => 'pending', 'applied_on' => $input['applied_on'], 'notes' => $input['notes'] ?? null, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            if ($renewal) {
                $renewal->update(['status' => 'renewed', 'updated_by' => $actor->id]);
            }
            $this->audit->handle($org, $actor, $renewal ? 'leasing.ejari.renewal_applied' : 'leasing.ejari.applied', $registration, ['renewal_of_id' => $renewal?->id]);

            return $registration;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function register(Organization $org, User $actor, LeaseEjariRegistration $registration, array $input): void
    {
        DB::transaction(function () use ($org, $actor, $registration, $input): void {
            $locked = LeaseEjariRegistration::where('organization_id', $org->id)->lockForUpdate()->findOrFail($registration->id);
            abort_unless($locked->status === 'pending', 422, 'Only a pending Ejari application can be registered.');
            $locked->update([...$input, 'status' => 'registered', 'updated_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'leasing.ejari.registered', $locked, $input);
        });
    }
}
