<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\AutomationRule;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Models\PipelineStage;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageAutomationRule
{
    public function __construct(private ManageCustomFields $fields, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input */
    public function save(Organization $org, User $actor, array $input, ?AutomationRule $rule = null): AutomationRule
    {
        $this->authorize($org, $actor);
        if ($rule) {
            abort_unless($rule->organization_id === $org->id, 404);
        }
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'pipeline_id' => ['required', 'integer'],
            'stage_id' => ['nullable', 'integer'],
            'trigger' => ['required', 'in:stage_entered,lead_created'],
            'condition_field' => ['nullable', 'string', 'max:100'],
            'condition_operator' => ['nullable', 'in:equals,not_equals,contains,empty,not_empty'],
            'condition_value' => ['nullable', 'string', 'max:255'],
            'action' => ['required', 'in:notify_assignee,create_follow_up'],
            'due_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'active' => ['sometimes', 'boolean'],
        ])->validate();
        Pipeline::where('organization_id', $org->id)->findOrFail((int) $data['pipeline_id']);
        if (isset($data['stage_id']) && ! PipelineStage::where('pipeline_id', $data['pipeline_id'])->whereKey($data['stage_id'])->exists()) {
            throw ValidationException::withMessages(['stage_id' => 'Choose a stage in this pipeline.']);
        }
        $allowed = ['first_name', 'last_name', 'source', 'status', 'city', 'company', 'assigned_to'];
        $allowed = [...$allowed, ...array_map(fn ($field) => 'custom:'.$field->key, $this->fields->visible($org, $actor))];
        if (isset($data['condition_field']) && ! in_array($data['condition_field'], $allowed, true)) {
            throw ValidationException::withMessages(['condition_field' => 'Choose a visible supported lead field.']);
        }
        if (isset($data['condition_field']) && ! isset($data['condition_operator'])) {
            throw ValidationException::withMessages(['condition_operator' => 'Choose a condition operator.']);
        }
        if ($data['action'] === 'create_follow_up' && ! isset($data['due_days'])) {
            throw ValidationException::withMessages(['due_days' => 'Choose when the follow-up is due.']);
        }
        if ($rule) {
            $rule->update($data);
        } else {
            $rule = AutomationRule::create(['organization_id' => $org->id, 'created_by' => $actor->id, ...$data]);
        }
        $this->audit->handle($org, $actor, 'crm.automation_rule.saved', $rule, ['name' => $rule->name, 'active' => $rule->active]);

        return $rule;
    }

    public function disable(Organization $org, User $actor, AutomationRule $rule): void
    {
        $this->authorize($org, $actor);
        abort_unless($rule->organization_id === $org->id, 404);
        $rule->update(['active' => false]);
        $this->audit->handle($org, $actor, 'crm.automation_rule.disabled', $rule);
    }

    private function authorize(Organization $org, User $actor): void
    {
        abort_unless($actor->hasOrganizationRole($org, OrganizationRole::Owner) || $actor->hasOrganizationRole($org, OrganizationRole::Administrator), 403);
    }
}
