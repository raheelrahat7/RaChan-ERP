<?php

namespace App\Domain\Platform\Actions;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Services\JobCardAccess;
use App\Models\CrmLead;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageWorkTask
{
    public function __construct(private RecordOrganizationAuditLog $audit, private LeadVisibility $leads, private JobCardAccess $jobs) {}

    public function manages(Organization $org, User $actor): bool
    {
        return $actor->hasOrganizationRole($org, OrganizationRole::Owner)
            || $actor->hasOrganizationRole($org, OrganizationRole::Administrator)
            || $actor->hasOrganizationRole($org, OrganizationRole::Manager);
    }

    /** @param array<string, mixed> $input */
    public function create(Organization $org, User $actor, array $input): int
    {
        abort_unless($this->manages($org, $actor), 403);
        $assignee = $org->users()->whereKey($input['assigned_to'])->first();
        if (! $assignee) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an organization member.']);
        }
        $this->validateRelated($org, $actor, $assignee, $input['related_type'] ?? null, isset($input['related_id']) ? (int) $input['related_id'] : null);

        return DB::transaction(function () use ($org, $actor, $input): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $id = DB::table('work_tasks')->insertGetId([
                'organization_id' => $org->id, 'assigned_to' => $input['assigned_to'], 'created_by' => $actor->id,
                'title' => $input['title'], 'description' => $input['description'] ?? null,
                'priority' => $input['priority'] ?? 'normal', 'due_at' => $input['due_at'] ?? null,
                'related_type' => $input['related_type'] ?? null, 'related_id' => $input['related_id'] ?? null,
                'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'tasks.created', $org, ['task_id' => $id]);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Organization $org, User $actor, int $id, array $input): void
    {
        abort_unless($this->manages($org, $actor), 403);
        DB::transaction(function () use ($org, $actor, $id, $input): void {
            $task = DB::table('work_tasks')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($task !== null, 404);
            abort_unless($task->status === 'open', 422);
            $assigneeId = $input['assigned_to'] ?? $task->assigned_to;
            $assignee = $org->users()->whereKey($assigneeId)->first();
            if (! $assignee) {
                throw ValidationException::withMessages(['assigned_to' => 'Choose an organization member.']);
            }
            $relatedType = $input['related_type'] ?? $task->related_type;
            $relatedId = $input['related_id'] ?? $task->related_id;
            $this->validateRelated($org, $actor, $assignee, $relatedType, $relatedId ? (int) $relatedId : null);
            DB::table('work_tasks')->where('id', $id)->update([
                'assigned_to' => $assigneeId, 'title' => $input['title'] ?? $task->title,
                'description' => array_key_exists('description', $input) ? $input['description'] : $task->description,
                'priority' => $input['priority'] ?? $task->priority,
                'due_at' => array_key_exists('due_at', $input) ? $input['due_at'] : $task->due_at,
                'related_type' => $relatedType, 'related_id' => $relatedId,
                'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'tasks.updated', $org, ['task_id' => $id]);
        });
    }

    public function complete(Organization $org, User $actor, int $id): void
    {
        DB::transaction(function () use ($org, $actor, $id): void {
            $task = DB::table('work_tasks')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($task !== null, 404);
            abort_unless($task->assigned_to === $actor->id || $this->manages($org, $actor), 403);
            abort_unless($task->status === 'open', 422);
            DB::table('work_tasks')->where('id', $id)->update(['status' => 'completed', 'completed_at' => now(), 'completed_by' => $actor->id, 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'tasks.completed', $org, ['task_id' => $id]);
        });
    }

    private function validateRelated(Organization $org, User $actor, User $assignee, ?string $type, ?int $id): void
    {
        if ($type === null && $id === null) {
            return;
        }
        if ($type === null || $id === null) {
            throw ValidationException::withMessages(['related_id' => 'Choose both a related type and record.']);
        }
        if ($type === 'lead') {
            $lead = CrmLead::where('organization_id', $org->id)->find($id);
            $allowed = $lead && $actor->can('viewCrm', $org) && $assignee->can('viewCrm', $org)
                && $this->leads->canSeeLead($org, $actor, $lead->assigned_to)
                && $this->leads->canSeeLead($org, $assignee, $lead->assigned_to);
        } elseif ($type === 'job') {
            $job = MaintenanceRequest::where('organization_id', $org->id)->find($id);
            $allowed = $job && $this->jobs->canView($org, $actor, $job) && $this->jobs->canView($org, $assignee, $job);
        } else {
            $table = match ($type) {
                'reservation' => 'reservations', 'lease' => 'leases', 'sale' => 'sales_contracts', 'unit' => 'units',
                default => null,
            };
            $allowed = $table && DB::table($table)->where('organization_id', $org->id)->where('id', $id)->exists()
                && $actor->can($type === 'unit' ? 'viewInventory' : 'viewTransactions', $org)
                && $assignee->can($type === 'unit' ? 'viewInventory' : 'viewTransactions', $org);
        }
        if (! $allowed) {
            throw ValidationException::withMessages(['related_id' => 'This related record is not visible to both users in this organization.']);
        }
    }
}
