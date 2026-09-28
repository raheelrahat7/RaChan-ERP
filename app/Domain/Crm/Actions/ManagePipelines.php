<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\LeadStageHistory;
use App\Domain\Crm\Models\LostReason;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManagePipelines
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function savePipeline(Organization $org, User $actor, array $data, ?int $id = null): Pipeline
    {
        return DB::transaction(function () use ($org, $actor, $data, $id): Pipeline {
            $this->lock($org);
            $pipeline = $id ? Pipeline::where('organization_id', $org->id)->findOrFail($id) : new Pipeline(['organization_id' => $org->id, 'created_by' => $actor->id]);
            if ($pipeline->is_default && ! $data['active']) {
                $this->fail('active', 'The default pipeline must remain active.');
            }
            $pipeline->fill($data)->save();
            if (! $id) {
                $pipeline->stages()->create(['name' => 'New Leads', 'position' => 1, 'type' => 'normal', 'is_initial' => true]);
            }
            $this->audit->handle($org, $actor, 'crm.pipeline.saved', $pipeline, $data);

            return $pipeline;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveItem(Organization $org, User $actor, int $pipelineId, string $kind, array $data, ?int $id = null): Model
    {
        return DB::transaction(function () use ($org, $actor, $pipelineId, $kind, $data, $id): Model {
            $this->lock($org);
            $pipeline = Pipeline::where('organization_id', $org->id)->findOrFail($pipelineId);
            $relation = $kind === 'stage' ? $pipeline->stages() : $pipeline->reasons();
            $item = $id ? $relation->findOrFail($id) : $relation->make();
            if ($item instanceof PipelineStage) {
                if ($item->is_initial && (! $data['active'] || $data['type'] !== 'normal')) {
                    $this->fail('active', 'The initial stage must remain active and normal.');
                }
                if ($id && $item->type !== $data['type'] && $this->stageUsed($id)) {
                    $this->fail('type', 'A used stage cannot change outcome type.');
                }
            }
            $item->fill($data)->save();
            $this->audit->handle($org, $actor, 'crm.'.$kind.'.saved', $item, $data);

            return $item;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveRules(Organization $org, User $actor, int $pipelineId, int $stageId, array $data): void
    {
        DB::transaction(function () use ($org, $actor, $pipelineId, $stageId, $data): void {
            $this->lock($org);
            $pipeline = Pipeline::where('organization_id', $org->id)->findOrFail($pipelineId);
            $stage = $pipeline->stages()->findOrFail($stageId);
            $ids = array_map('intval', $data['allowed_from_stage_ids']);
            if (count($ids) !== $pipeline->stages()->whereKey($ids)->where('id', '!=', $stageId)->count()) {
                $this->fail('allowed_from_stage_ids', 'Select other source stages belonging to this pipeline.');
            }
            $before = $stage->only('allowed_from_stage_ids', 'entry_roles', 'required_fields');
            $stage->update(['allowed_from_stage_ids' => $data['restrict_transitions'] ? $ids : null, 'entry_roles' => $data['restrict_roles'] ? $data['entry_roles'] : null, 'required_fields' => $data['required_fields']]);
            $this->audit->handle($org, $actor, 'crm.stage.rules_updated', $stage, ['before' => $before, 'after' => $stage->only('allowed_from_stage_ids', 'entry_roles', 'required_fields')]);
        });
    }

    public function saveAssigneeNotification(Organization $org, User $actor, int $pipelineId, int $stageId, bool $enabled): void
    {
        DB::transaction(function () use ($org, $actor, $pipelineId, $stageId, $enabled): void {
            $this->lock($org);
            $pipeline = Pipeline::where('organization_id', $org->id)->findOrFail($pipelineId);
            $stage = $pipeline->stages()->findOrFail($stageId);
            $before = $stage->notify_assignee_on_entry;
            $stage->update(['notify_assignee_on_entry' => $enabled]);
            $this->audit->handle($org, $actor, 'crm.stage.assignee_notification_updated', $stage, ['before' => $before, 'after' => $enabled]);
        });
    }

    public function saveFollowUpRule(Organization $org, User $actor, int $pipelineId, int $stageId, ?int $dueDays): void
    {
        DB::transaction(function () use ($org, $actor, $pipelineId, $stageId, $dueDays): void {
            $this->lock($org);
            $pipeline = Pipeline::where('organization_id', $org->id)->findOrFail($pipelineId);
            $stage = $pipeline->stages()->findOrFail($stageId);
            $before = $stage->follow_up_due_days;
            $stage->update(['follow_up_due_days' => $dueDays]);
            $this->audit->handle($org, $actor, 'crm.stage.follow_up_rule_updated', $stage, ['before_due_days' => $before, 'after_due_days' => $dueDays]);
        });
    }

    /** @param list<int> $memberIds */
    public function saveAssignmentRule(Organization $org, User $actor, int $pipelineId, int $stageId, array $memberIds): void
    {
        DB::transaction(function () use ($org, $actor, $pipelineId, $stageId, $memberIds): void {
            $this->lock($org);
            $pipeline = Pipeline::where('organization_id', $org->id)->findOrFail($pipelineId);
            $stage = $pipeline->stages()->lockForUpdate()->findOrFail($stageId);
            $ids = array_values(array_unique(array_map('intval', $memberIds)));
            if (count($ids) !== $org->users()->whereIn('users.id', $ids)->count()) {
                $this->fail('member_ids', 'Select members of this organization.');
            }
            sort($ids);
            $before = $stage->assignment_member_ids ?? [];
            $stage->update(['assignment_member_ids' => $ids, 'assignment_last_user_id' => null]);
            $this->audit->handle($org, $actor, 'crm.stage.assignment_rule_updated', $stage, ['before_member_ids' => $before, 'after_member_ids' => $ids]);
        });
    }

    public function delete(Organization $org, User $actor, string $kind, int $id): void
    {
        DB::transaction(function () use ($org, $actor, $kind, $id): void {
            $this->lock($org);
            $pipelineIds = Pipeline::where('organization_id', $org->id)->pluck('id');
            $item = match ($kind) {
                'pipeline' => Pipeline::where('organization_id', $org->id)->findOrFail($id),
                'stage' => PipelineStage::whereIn('pipeline_id', $pipelineIds)->findOrFail($id),
                'reason' => LostReason::whereIn('pipeline_id', $pipelineIds)->findOrFail($id),
                default => $this->fail('configuration', 'Select a valid configuration type.'),
            };
            $used = match (true) {
                $item instanceof Pipeline => $item->is_default || CrmLead::where('pipeline_id', $id)->exists() || LeadStageHistory::where('pipeline_id', $id)->exists(),
                $item instanceof PipelineStage => $item->is_initial || $this->stageUsed($id),
                default => CrmLead::where('lost_reason_id', $id)->exists() || LeadStageHistory::where('lost_reason_id', $id)->exists(),
            };
            if ($used) {
                $this->fail('configuration', 'This configuration is required or has been used. Deactivate it instead where permitted.');
            }
            $this->audit->handle($org, $actor, 'crm.'.$kind.'.deleted', $item, ['name' => $item->name]);
            if ($item instanceof Pipeline) {
                $item->stages()->delete();
                $item->reasons()->delete();
            }
            $item->delete();
        });
    }

    private function stageUsed(int $id): bool
    {
        return PipelineStage::whereJsonContains('allowed_from_stage_ids', $id)->exists() || CrmLead::where('current_stage_id', $id)->exists() || LeadStageHistory::where(fn ($query) => $query->where('from_stage_id', $id)->orWhere('to_stage_id', $id))->exists();
    }

    private function lock(Organization $org): void
    {
        Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
