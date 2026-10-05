<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\AutomationExecution;
use App\Domain\Crm\Models\AutomationRule;
use App\Domain\Crm\Models\CustomField;
use App\Domain\Crm\Models\CustomFieldValue;
use App\Domain\Crm\Models\LeadStageHistory;
use App\Domain\Crm\Models\Pipeline;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ExecuteAutomationRule
{
    public function __construct(private RecordOrganizationAuditLog $audit, private LeadVisibility $visibility) {}

    /** @return array<string, mixed> */
    public function configuration(AutomationRule $rule): array
    {
        return $rule->only('name', 'pipeline_id', 'stage_id', 'trigger', 'condition_field', 'condition_operator', 'condition_value', 'action', 'due_days', 'delay_minutes', 'activity_type', 'target_stage_id', 'created_by');
    }

    public function handle(int $executionId): void
    {
        $execution = AutomationExecution::findOrFail($executionId);
        DB::transaction(function () use ($execution): void {
            $org = Organization::whereKey($execution->organization_id)->lockForUpdate()->firstOrFail();
            $history = LeadStageHistory::where('organization_id', $org->id)->find($execution->stage_history_id);
            $lead = $history ? CrmLead::where('organization_id', $org->id)->lockForUpdate()->find($history->lead_id) : null;
            $execution = AutomationExecution::where('organization_id', $org->id)->lockForUpdate()->findOrFail($execution->id);
            if ($execution->outcome !== 'pending' || $execution->scheduled_at?->isFuture()) {
                return;
            }
            $rule = AutomationRule::where('organization_id', $org->id)->find($execution->rule_id);
            $creator = $rule ? $org->users()->where('users.id', $rule->created_by)->first() : null;
            $outcome = null;
            if (! $rule?->active || ! $creator || (! $creator->hasOrganizationRole($org, OrganizationRole::Owner) && ! $creator->hasOrganizationRole($org, OrganizationRole::Administrator))) {
                $outcome = 'skipped_disabled';
            } elseif ($this->configuration($rule) != ($execution->configuration['rule'] ?? null)) {
                $outcome = 'skipped_changed';
            } elseif (! $lead || $lead->converted_at || $lead->pipeline_id !== $rule->pipeline_id || $lead->current_stage_id !== $history->to_stage_id
                || LeadStageHistory::where('organization_id', $org->id)->where('lead_id', $lead->id)->max('id') !== $history->id
                || ! Pipeline::where('organization_id', $org->id)->whereKey($rule->pipeline_id)->where('active', true)->exists()) {
                $outcome = 'skipped_stale';
            } elseif (! $this->matches($rule, $lead)) {
                $outcome = 'skipped_condition';
            }
            if ($outcome !== null) {
                $execution->update(['outcome' => $outcome]);

                return;
            }
            $recipients = [];
            if ($rule->action === 'notify_assignee') {
                if ($lead->assigned_to === ($execution->configuration['assigned_to'] ?? null)) {
                    $recipient = $org->users()->where('users.id', $lead->assigned_to)->first();
                    $recipients = $recipient ? [$recipient] : [];
                }
            } elseif ($rule->action === 'notify_managers') {
                $recipients = $org->users()->wherePivotIn('role', ['owner', 'administrator', 'manager'])->get()
                    ->filter(fn ($member) => $this->visibility->canSeeLead($org, $member, $lead->assigned_to))->all();
            } elseif ($rule->action === 'create_follow_up') {
                $lead->activities()->create(['organization_id' => $org->id, 'created_by' => null, 'type' => $rule->activity_type,
                    'notes' => Str::limit($rule->name, 5000), 'due_at' => now()->addDays((int) $rule->due_days)]);
            } elseif ($rule->action === 'change_stage') {
                if (! $lead->pipeline->stages()->whereKey($rule->target_stage_id)->where('active', true)->where('type', 'normal')->exists()) {
                    $execution->update(['outcome' => 'blocked', 'failure_reason' => 'The destination must be an active nonterminal stage.']);

                    return;
                }
                try {
                    app(ManageLeadPipeline::class)->move($org, $creator, $lead, [
                        'stage_id' => $rule->target_stage_id, 'expected_stage_id' => $history->to_stage_id,
                        'notes' => 'Automatic stage change: '.$rule->name, 'automation_rule_id' => $rule->id,
                    ]);
                } catch (ValidationException $exception) {
                    $execution->update(['outcome' => 'blocked', 'failure_reason' => Str::limit(implode(' ', $exception->validator->errors()->all()), 255)]);

                    return;
                }
            }
            foreach ($recipients as $recipient) {
                OrganizationNotification::firstOrCreate(['organization_id' => $org->id, 'user_id' => $recipient->id,
                    'event_key' => 'crm_rule:'.$rule->id.':'.$history->id], ['category' => 'crm_automation',
                        'title' => Str::limit('Lead '.$lead->first_name.' '.$lead->last_name.': '.$rule->name, 255), 'count' => 1, 'href' => '/crm/leads/'.$lead->id]);
            }
            $execution->update(['outcome' => str_starts_with($rule->action, 'notify_') && $recipients === [] ? 'skipped_recipient' : 'completed']);
            $this->audit->handle($org, null, 'crm.automation_rule.executed', $lead, ['rule_id' => $rule->id, 'stage_history_id' => $history->id, 'action' => $rule->action, 'outcome' => $execution->outcome]);
        });
    }

    public function matches(AutomationRule $rule, CrmLead $lead): bool
    {
        if (! $rule->condition_field) {
            return true;
        }
        if (str_starts_with($rule->condition_field, 'custom:')) {
            $field = CustomField::where('organization_id', $lead->organization_id)->where('key', substr($rule->condition_field, 7))->where('active', true)->first();
            if (! $field) {
                return false;
            }
            $value = CustomFieldValue::where('organization_id', $lead->organization_id)->where('lead_id', $lead->id)->where('field_id', $field->id)->value('search_text');
        } else {
            $value = $lead->getAttribute($rule->condition_field);
        }

        return match ($rule->condition_operator) {
            'empty' => $value === null || $value === '',
            'not_empty' => $value !== null && $value !== '',
            'equals' => mb_strtolower((string) $value) === mb_strtolower((string) $rule->condition_value),
            'not_equals' => mb_strtolower((string) $value) !== mb_strtolower((string) $rule->condition_value),
            'contains' => $rule->condition_value !== null && $rule->condition_value !== '' && str_contains(mb_strtolower((string) $value), mb_strtolower($rule->condition_value)),
            default => false,
        };
    }

    /** @return array{processed: int, failed: int} */
    public function due(): array
    {
        $ids = AutomationExecution::where('outcome', 'pending')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->orderBy('id')->limit(100)->pluck('id');
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $this->handle((int) $id);
            } catch (\Throwable $exception) {
                report($exception);
                AutomationExecution::whereKey($id)->where('outcome', 'pending')->update(['outcome' => 'failed', 'failure_reason' => 'Execution failed; inspect the application log.', 'updated_at' => now()]);
                $failed++;
            }
        }

        return ['processed' => $ids->count(), 'failed' => $failed];
    }
}
