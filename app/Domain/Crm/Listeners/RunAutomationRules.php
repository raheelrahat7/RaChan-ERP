<?php

namespace App\Domain\Crm\Listeners;

use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Models\AutomationRule;
use App\Domain\Crm\Models\CustomField;
use App\Domain\Crm\Models\CustomFieldValue;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\CrmLead;
use App\Models\Organization;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RunAutomationRules
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function handle(LeadStageChanged $event): void
    {
        $history = $event->history;
        $rules = AutomationRule::where('organization_id', $history->organization_id)->where('pipeline_id', $history->pipeline_id)
            ->where('active', true)->where(fn ($query) => $query->whereNull('stage_id')->orWhere('stage_id', $history->to_stage_id))
            ->whereIn('trigger', $history->from_stage_id === null ? ['stage_entered', 'lead_created'] : ['stage_entered'])->get();
        foreach ($rules as $rule) {
            DB::transaction(function () use ($history, $rule): void {
                $lead = CrmLead::where('organization_id', $history->organization_id)->whereKey($history->lead_id)->lockForUpdate()->first();
                if (! $lead || $lead->current_stage_id !== $history->to_stage_id || ! $this->matches($rule, $lead)) {
                    return;
                }
                $created = DB::table('crm_automation_executions')->insertOrIgnore(['organization_id' => $history->organization_id, 'rule_id' => $rule->id, 'stage_history_id' => $history->id, 'outcome' => 'started', 'created_at' => now(), 'updated_at' => now()]);
                if (! $created) {
                    return;
                }
                $org = Organization::findOrFail($history->organization_id);
                if ($rule->action === 'notify_assignee' && $lead->assigned_to && $org->users()->where('users.id', $lead->assigned_to)->exists()) {
                    OrganizationNotification::create(['organization_id' => $org->id, 'user_id' => $lead->assigned_to, 'category' => 'crm_automation', 'event_key' => 'crm_rule:'.$rule->id.':'.$history->id, 'title' => Str::limit('Lead '.$lead->first_name.' '.$lead->last_name.': '.$rule->name, 255), 'count' => 1, 'href' => '/crm/leads/'.$lead->id]);
                } elseif ($rule->action === 'create_follow_up') {
                    $lead->activities()->create(['organization_id' => $org->id, 'created_by' => null, 'type' => 'task', 'notes' => Str::limit($rule->name, 5000), 'due_at' => $history->changed_at->addDays((int) $rule->due_days)]);
                }
                DB::table('crm_automation_executions')->where('rule_id', $rule->id)->where('stage_history_id', $history->id)->update(['outcome' => 'completed', 'updated_at' => now()]);
                $this->audit->handle($org, null, 'crm.automation_rule.executed', $lead, ['rule_id' => $rule->id, 'stage_history_id' => $history->id, 'action' => $rule->action]);
            });
        }
    }

    private function matches(AutomationRule $rule, CrmLead $lead): bool
    {
        if (! $rule->condition_field) {
            return true;
        }
        if (str_starts_with($rule->condition_field, 'custom:')) {
            $field = CustomField::where('organization_id', $lead->organization_id)->where('key', substr($rule->condition_field, 7))->where('active', true)->first();
            $value = $field ? CustomFieldValue::where('organization_id', $lead->organization_id)->where('lead_id', $lead->id)->where('field_id', $field->id)->value('search_text') : null;
        } else {
            $value = $lead->getAttribute($rule->condition_field);
        }
        $expected = $rule->condition_value;

        return match ($rule->condition_operator) {
            'empty' => $value === null || $value === '',
            'not_empty' => $value !== null && $value !== '',
            'equals' => mb_strtolower((string) $value) === mb_strtolower((string) $expected),
            'not_equals' => mb_strtolower((string) $value) !== mb_strtolower((string) $expected),
            'contains' => $expected !== null && $expected !== '' && str_contains(mb_strtolower((string) $value), mb_strtolower($expected)),
            default => false,
        };
    }
}
