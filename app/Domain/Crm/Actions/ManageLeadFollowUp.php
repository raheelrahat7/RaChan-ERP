<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\AssignmentHold;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageLeadFollowUp
{
    public function __construct(private RecordOrganizationAuditLog $audit, private LeadVisibility $visibility) {}

    public function assign(Organization $organization, User $actor, CrmLead $lead, ?int $assigneeId): void
    {
        Gate::forUser($actor)->authorize('manageCrm', $organization);
        DB::transaction(function () use ($organization, $actor, $lead, $assigneeId): void {
            $locked = CrmLead::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($lead->id);
            abort_unless($this->visibility->canSeeLead($organization, $actor, $locked->assigned_to), 404);
            abort_if($locked->converted_at !== null, 422, 'Converted leads cannot be reassigned.');
            abort_if($assigneeId !== null && ! $organization->users()->where('users.id', $assigneeId)->exists(), 422, 'Choose a member of this organization.');
            abort_unless($this->visibility->canSeeLead($organization, $actor, $assigneeId), 403);
            $previous = $locked->assigned_to;
            $locked->update(['assigned_to' => $assigneeId]);
            if ($assigneeId !== null) {
                AssignmentHold::where('organization_id', $organization->id)->where('lead_id', $locked->id)->whereNull('resolved_at')->update(['resolved_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->handle($organization, $actor, 'crm.lead.assigned', $locked, ['previous_assigned_to' => $previous, 'assigned_to' => $assigneeId]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Organization $organization, User $actor, CrmLead $lead, array $input): void
    {
        Gate::forUser($actor)->authorize('manageCrm', $organization);
        DB::transaction(function () use ($organization, $actor, $lead, $input): void {
            $locked = CrmLead::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($lead->id);
            abort_unless($this->visibility->canSeeLead($organization, $actor, $locked->assigned_to), 404);
            abort_if($locked->converted_at !== null, 422, 'Converted leads cannot receive new follow-ups.');
            $activity = $locked->activities()->create(['organization_id' => $organization->id, 'created_by' => $actor->id, 'type' => $input['type'], 'notes' => $input['notes'] ?? null, 'due_at' => empty($input['due_at']) ? null : CarbonImmutable::parse($input['due_at'], $organization->timezone)->utc()]);
            $this->audit->handle($organization, $actor, 'crm.activity.created', $activity, ['lead_id' => $locked->id]);
        });
    }

    public function complete(Organization $organization, User $actor, CrmActivity $activity): void
    {
        Gate::forUser($actor)->authorize('manageCrm', $organization);
        DB::transaction(function () use ($organization, $actor, $activity): void {
            $locked = CrmActivity::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($activity->id);
            $lead = CrmLead::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($locked->subject_id);
            abort_unless($locked->subject_type === CrmLead::class && $this->visibility->canSeeLead($organization, $actor, $lead->assigned_to), 404);
            abort_unless($locked->due_at && ! $locked->completed_at, 422, 'Only an open scheduled follow-up can be completed.');
            $locked->update(['completed_at' => now()]);
            $this->audit->handle($organization, $actor, 'crm.follow_up.completed', $locked);
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Organization $organization, User $actor, CrmActivity $activity, array $input): void
    {
        Gate::forUser($actor)->authorize('manageCrm', $organization);
        DB::transaction(function () use ($organization, $actor, $activity, $input): void {
            $locked = CrmActivity::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($activity->id);
            abort_unless($locked->subject_type === (new CrmLead)->getMorphClass(), 404);
            $lead = CrmLead::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($locked->subject_id);
            abort_unless($this->visibility->canSeeLead($organization, $actor, $lead->assigned_to), 404);
            abort_if($lead->converted_at || $locked->completed_at, 422, 'Completed activities and converted leads are read-only.');
            if (! $locked->updated_at?->equalTo(CarbonImmutable::parse($input['expected_updated_at']))) {
                throw ValidationException::withMessages(['activity' => 'This activity changed. Refresh before editing it.']);
            }
            $beforeDue = $locked->due_at?->toIso8601String();
            $data = array_intersect_key($input, array_flip(['type', 'notes', 'due_at']));
            if (array_key_exists('due_at', $data)) {
                $data['due_at'] = empty($data['due_at']) ? null : CarbonImmutable::parse($data['due_at'], $organization->timezone)->utc();
            }
            $locked->fill($data);
            $changed = array_keys($locked->getDirty());
            if ($changed === []) {
                return;
            }
            $locked->save();
            $this->audit->handle($organization, $actor, 'crm.activity.updated', $locked, [
                'lead_id' => $lead->id, 'changed_fields' => $changed,
                'previous_due_at' => $beforeDue, 'due_at' => $locked->due_at?->toIso8601String(),
            ]);
        });
    }
}
