<?php

namespace App\Domain\Crm\Listeners;

use App\Domain\Crm\Actions\ExecuteAutomationRule;
use App\Domain\Crm\Events\LeadStageChanged;
use App\Domain\Crm\Models\AutomationExecution;
use App\Domain\Crm\Models\AutomationRule;
use App\Models\CrmLead;

class RunAutomationRules
{
    public function __construct(private ExecuteAutomationRule $execute) {}

    public function handle(LeadStageChanged $event): void
    {
        $history = $event->history;
        // Automatic moves retain history and stage workflows, but never chain generic rules.
        if (! empty($history->snapshot['automation_rule_id'])) {
            return;
        }
        $lead = CrmLead::where('organization_id', $history->organization_id)->find($history->lead_id);
        if (! $lead || $lead->converted_at || $lead->pipeline_id !== $history->pipeline_id || $lead->current_stage_id !== $history->to_stage_id) {
            return;
        }
        $rules = AutomationRule::where('organization_id', $history->organization_id)->where('pipeline_id', $history->pipeline_id)
            ->where('active', true)->where(fn ($query) => $query->whereNull('stage_id')->orWhere('stage_id', $history->to_stage_id))
            ->whereIn('trigger', $history->from_stage_id === null ? ['stage_entered', 'lead_created'] : ['stage_entered'])->get();
        foreach ($rules as $rule) {
            if (! $this->execute->matches($rule, $lead)) {
                continue;
            }
            $execution = AutomationExecution::firstOrCreate(['organization_id' => $history->organization_id, 'rule_id' => $rule->id, 'stage_history_id' => $history->id], [
                'outcome' => 'pending', 'scheduled_at' => $history->changed_at->copy()->addMinutes($rule->delay_minutes),
                'configuration' => ['rule' => $this->execute->configuration($rule), 'assigned_to' => $lead->assigned_to],
            ]);
            if ($execution->wasRecentlyCreated && $rule->delay_minutes === 0) {
                $this->execute->handle($execution->id);
            }
        }
    }
}
