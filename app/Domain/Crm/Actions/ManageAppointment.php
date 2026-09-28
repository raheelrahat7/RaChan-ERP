<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageAppointment
{
    public function __construct(private LeadVisibility $visibility, private ManageLeadPipeline $pipeline, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function create(Organization $org, User $actor, array $input): int
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        $this->validateLinks($org, $actor, $input);

        return DB::transaction(function () use ($org, $actor, $input): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $id = DB::table('brokerage_appointments')->insertGetId([
                'organization_id' => $org->id, 'created_by' => $actor->id, 'assigned_to' => $input['assigned_to'],
                'lead_id' => $input['lead_id'] ?? null, 'listing_id' => $input['listing_id'] ?? null,
                'cost_centre_id' => $input['cost_centre_id'] ?? null,
                'type' => $input['type'], 'title' => $input['title'], 'starts_at' => $input['starts_at'],
                'ends_at' => $input['ends_at'], 'location' => $input['location'] ?? null,
                'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'crm.appointment.created', $org, ['appointment_id' => $id]);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function outcome(Organization $org, User $actor, int $id, array $input): void
    {
        DB::transaction(function () use ($org, $actor, $id, $input): void {
            $appointment = DB::table('brokerage_appointments')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($appointment !== null, 404);
            abort_unless($actor->id === $appointment->assigned_to || $actor->can('manageCrm', $org), 403);
            abort_unless($appointment->status === 'scheduled', 422);
            if (isset($input['stage_id'])) {
                abort_unless($actor->can('manageCrm', $org), 403);
                abort_unless($appointment->lead_id !== null, 422, 'Only an appointment linked to a lead can move its stage.');
                $lead = CrmLead::where('organization_id', $org->id)->whereKey($appointment->lead_id)->firstOrFail();
                abort_unless($this->visibility->canSeeLead($org, $actor, $lead->assigned_to), 404);
                $this->pipeline->move($org, $actor, $lead, ['stage_id' => $input['stage_id'], 'expected_stage_id' => $input['expected_stage_id']]);
            }
            DB::table('brokerage_appointments')->where('id', $id)->update(['status' => 'completed', 'outcome' => $input['outcome'], 'completed_at' => now(), 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'crm.appointment.completed', $org, ['appointment_id' => $id, 'lead_stage_changed' => isset($input['stage_id'])]);
        });
    }

    /** @param array<string, mixed> $input */
    private function validateLinks(Organization $org, User $actor, array $input): void
    {
        $assignee = $org->users()->whereKey($input['assigned_to'])->first();
        if (! $assignee) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an organization member.']);
        }
        if (isset($input['lead_id'])) {
            $lead = CrmLead::where('organization_id', $org->id)->whereKey($input['lead_id'])->first();
            if (! $lead || ! $this->visibility->canSeeLead($org, $actor, $lead->assigned_to) || ! $this->visibility->canSeeLead($org, $assignee, $lead->assigned_to)) {
                throw ValidationException::withMessages(['lead_id' => 'Choose a lead visible to both users in this organization.']);
            }
        }
        foreach (['listing_id' => 'listings', 'cost_centre_id' => 'accounting_cost_centres'] as $field => $table) {
            if (isset($input[$field]) && ! DB::table($table)->where('organization_id', $org->id)->where('id', $input[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Choose a record in this organization.']);
            }
        }
    }
}
