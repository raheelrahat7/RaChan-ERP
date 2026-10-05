<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\DealAutomationExecution;
use App\Domain\Crm\Models\DealAutomationRule;
use App\Domain\Crm\Models\DealPipeline;
use App\Domain\Crm\Models\DealStageHistory;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Crm\Services\DealConditions;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Notifications\Models\OrganizationNotification;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageDealAutomation
{
    public const ACTIONS = ['notify_assignee', 'notify_managers', 'create_follow_up', 'change_stage'];

    public function __construct(private DealAccess $access, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function save(Organization $org, User $actor, array $input, ?int $id = null): DealAutomationRule
    {
        abort_unless($this->access->administrator($org, $actor), 403);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:120'], 'pipeline_id' => ['required', 'integer'], 'stage_id' => ['required', 'integer'], 'action' => ['required', Rule::in(self::ACTIONS)], 'active' => ['required', 'boolean'], 'conditions' => ['sometimes', 'array', 'max:20'], 'working_hours_only' => ['sometimes', 'boolean'], 'target_stage_id' => ['nullable', 'integer'], 'delay_minutes' => ['required', 'integer', 'between:0,525600'], 'due_days' => ['sometimes', 'integer', 'between:0,365'], 'activity_type' => ['sometimes', Rule::in(['call', 'email', 'meeting', 'task', 'note'])]])->validate();

        return DB::transaction(function () use ($org, $actor, $data, $id): DealAutomationRule {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $pipeline = DealPipeline::where('organization_id', $org->id)->findOrFail((int) $data['pipeline_id']);
            if (! $pipeline->stages()->whereKey($data['stage_id'])->where('active', true)->exists()) {
                throw ValidationException::withMessages(['stage_id' => 'Select an active stage in this deal pipeline.']);
            }
            $rule = $id ? DealAutomationRule::where('organization_id', $org->id)->findOrFail($id) : new DealAutomationRule(['organization_id' => $org->id]);
            if (isset($data['conditions'])) {
                $data['conditions'] = app(DealConditions::class)->validate($org, $actor, $data['conditions']);
            }
            if ($data['working_hours_only'] ?? $rule->working_hours_only) {
                app(ManageWorkingCalendar::class)->deadline($org, (int) $data['delay_minutes']);
            }
            if ($data['action'] === 'change_stage' && ! $pipeline->stages()->whereKey($data['target_stage_id'] ?? $rule->target_stage_id)->where('active', true)->where('type', 'normal')->where('id', '!=', $data['stage_id'])->exists()) {
                throw ValidationException::withMessages(['target_stage_id' => 'Choose another active normal stage in this pipeline.']);
            }
            $rule->fill([...$data, 'created_by' => $actor->id])->save();
            $this->audit->handle($org, $actor, 'crm.deal_automation.saved', $rule, $data);

            return $rule;
        });
    }

    public function enqueue(DealStageHistory $history): void
    {
        $rules = DealAutomationRule::where('organization_id', $history->organization_id)->where('pipeline_id', $history->pipeline_id)->where('stage_id', $history->to_stage_id)->where('active', true)->get();
        foreach ($rules as $rule) {
            $execution = DealAutomationExecution::firstOrCreate(['organization_id' => $history->organization_id, 'rule_id' => $rule->id, 'history_id' => $history->id], ['configuration' => ['rule' => $this->config($rule), 'assigned_to' => $history->snapshot['assigned_to']], 'scheduled_at' => $rule->working_hours_only ? app(ManageWorkingCalendar::class)->deadline(Organization::findOrFail($history->organization_id), (int) $rule->delay_minutes) : now()->addMinutes((int) $rule->delay_minutes)]);
            if ($rule->delay_minutes === 0) {
                $this->execute($execution->id);
            }
        }
    }

    public function execute(int $id): void
    {
        $execution = DealAutomationExecution::findOrFail($id);
        DB::transaction(function () use ($execution): void {
            $org = Organization::whereKey($execution->organization_id)->lockForUpdate()->firstOrFail();
            $execution = DealAutomationExecution::lockForUpdate()->findOrFail($execution->id);
            if ($execution->outcome !== 'pending' || $execution->scheduled_at->isFuture()) {
                return;
            }
            $rule = DealAutomationRule::where('organization_id', $org->id)->findOrFail($execution->rule_id);
            $history = DealStageHistory::where('organization_id', $org->id)->findOrFail($execution->history_id);
            $creator = $org->users()->where('users.id', $rule->created_by)->first();
            $deal = Deal::where('organization_id', $org->id)->lockForUpdate()->find($history->deal_id);
            $outcome = null;
            if (! $rule->active || ! $creator || ! $this->access->administrator($org, $creator)) {
                $outcome = 'skipped_disabled';
            } elseif ($this->config($rule) != $execution->configuration['rule']) {
                $outcome = 'skipped_changed';
            } elseif (! $deal || $deal->pipeline_id !== $history->pipeline_id || $deal->current_stage_id !== $history->to_stage_id || $deal->history()->max('id') !== $history->id || ! $deal->pipeline->active || ! $deal->stage->active) {
                $outcome = 'skipped_stale';
            }
            if (! $outcome && $rule->conditions) {
                try {
                    $query = Deal::where('organization_id', $org->id)->whereKey($deal->id);
                    app(DealConditions::class)->apply($query, $org, $creator, $rule->conditions);
                    if (! $query->exists()) {
                        $outcome = 'skipped_condition';
                    }
                } catch (ValidationException) {
                    $outcome = 'skipped_condition';
                }
            }
            if ($outcome) {
                $execution->update(['outcome' => $outcome]);

                return;
            }
            $recipients = [];
            if ($rule->action === 'change_stage') {
                if (! $deal->pipeline->stages()->whereKey($rule->target_stage_id)->where('active', true)->where('type', 'normal')->exists()) {
                    $execution->update(['outcome' => 'blocked']);

                    return;
                }
                try {
                    app(ManageDeals::class)->move($org, $creator, $deal, ['stage_id' => $rule->target_stage_id, 'expected_version' => $deal->version, 'notes' => 'Automatic stage change: '.$rule->name], false, true);
                } catch (ValidationException) {
                    $execution->update(['outcome' => 'blocked']);

                    return;
                }
            } elseif ($rule->action === 'create_follow_up') {
                $deal->activities()->create(['organization_id' => $org->id, 'created_by' => null, 'type' => $rule->activity_type, 'notes' => $rule->name, 'due_at' => now()->addDays((int) $rule->due_days)]);
            } elseif ($rule->action === 'notify_assignee') {
                if ($deal->assigned_to === $execution->configuration['assigned_to']) {
                    $recipients = $org->users()->where('users.id', $deal->assigned_to)->get()->all();
                }
            } else {
                $recipients = $org->users()->wherePivotIn('role', ['owner', 'administrator', 'manager'])->get()->all();
            }
            $recipients = array_filter($recipients, fn ($member) => $this->access->allows($org, $member, $deal->pipeline, 'read', $deal->assigned_to));
            foreach ($recipients as $recipient) {
                OrganizationNotification::firstOrCreate(['organization_id' => $org->id, 'user_id' => $recipient->id, 'event_key' => 'crm_deal_rule:'.$rule->id.':'.$history->id], ['category' => 'crm_automation', 'title' => Str::limit('Deal '.$deal->title.': '.$rule->name, 255), 'count' => 1, 'href' => '/crm/deals/'.$deal->id]);
            }
            $execution->update(['outcome' => str_starts_with($rule->action, 'notify_') && $recipients === [] ? 'skipped_recipient' : 'completed']);
            $this->audit->handle($org, null, 'crm.deal_automation.executed', $deal, ['rule_id' => $rule->id, 'history_id' => $history->id, 'outcome' => $execution->outcome]);
        });
    }

    /** @return array{processed: int, failed: int} */
    public function due(): array
    {
        $ids = DealAutomationExecution::where('outcome', 'pending')->where('scheduled_at', '<=', now())->orderBy('scheduled_at')->orderBy('id')->limit(100)->pluck('id');
        $failed = 0;
        foreach ($ids as $id) {
            try {
                $this->execute((int) $id);
            } catch (\Throwable $exception) {
                report($exception);
                DealAutomationExecution::whereKey($id)->where('outcome', 'pending')->update(['outcome' => 'failed']);
                $failed++;
            }
        }

        return ['processed' => $ids->count(), 'failed' => $failed];
    }

    /** @return array<string, mixed> */
    private function config(DealAutomationRule $rule): array
    {
        return $rule->only('name', 'pipeline_id', 'stage_id', 'created_by', 'action', 'delay_minutes', 'due_days', 'activity_type', 'active', 'conditions', 'working_hours_only', 'target_stage_id');
    }
}
