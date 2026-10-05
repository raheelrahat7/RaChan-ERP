<?php

namespace App\Domain\Crm\Queries;

use App\Models\CrmActivity;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class LeadActivityBoard
{
    public function __construct(private PipelineOverview $overview) {}

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function for(Organization $org, User $actor, array $filters): array
    {
        $now = CarbonImmutable::now($org->timezone);
        $todayEnd = $now->endOfDay()->utc();
        $weekEnd = $now->endOfWeek()->utc();
        $nextWeekEnd = $now->addWeek()->endOfWeek()->utc();
        $nextDue = CrmActivity::where('organization_id', $org->id)->where('subject_type', (new CrmLead)->getMorphClass())
            ->whereNull('completed_at')->whereNotNull('due_at')->selectRaw('subject_id, MIN(due_at) as next_due_at')->groupBy('subject_id');
        $base = $this->overview->filteredLeadQuery($org, $actor, $filters)->whereNull('converted_at')
            ->leftJoinSub($nextDue, 'next_activity', fn ($join) => $join->on('next_activity.subject_id', '=', 'crm_leads.id'));
        $lanes = [];
        foreach (['overdue', 'due_today', 'due_this_week', 'due_next_week', 'idle', 'due_later'] as $bucket) {
            $query = clone $base;
            match ($bucket) {
                'overdue' => $query->where('next_due_at', '<', $now->utc()),
                'due_today' => $query->whereBetween('next_due_at', [$now->utc(), $todayEnd]),
                'due_this_week' => $query->where('next_due_at', '>', $todayEnd)->where('next_due_at', '<=', $weekEnd),
                'due_next_week' => $query->where('next_due_at', '>', $weekEnd)->where('next_due_at', '<=', $nextWeekEnd),
                'idle' => $query->whereNull('next_due_at'),
                default => $query->where('next_due_at', '>', $nextWeekEnd),
            };
            $total = (clone $query)->count();
            $page = max(1, (int) ($filters['activity_page'] ?? 1));
            $leads = $query->select('crm_leads.*', 'next_activity.next_due_at')->with('assignee:id,name')
                ->orderBy('next_due_at')->orderBy('crm_leads.id')->offset(($page - 1) * 20)->limit(20)->get()
                ->map(fn (CrmLead $lead) => [...$lead->only('id', 'first_name', 'last_name', 'email', 'phone', 'assigned_to', 'current_stage_id', 'next_due_at'),
                    'assignee' => $lead->assignee?->only('id', 'name')])->all();
            $lanes[] = ['key' => $bucket, 'total' => $total, 'leads' => $leads, 'page' => $page, 'per_page' => 20];
        }

        return ['timezone' => $org->timezone, 'as_of' => $now->toIso8601String(), 'lanes' => $lanes];
    }

    /** @param array<string, mixed> $filters
     * @return Builder<CrmActivity>
     */
    public function activities(Organization $org, User $actor, array $filters): Builder
    {
        return CrmActivity::where('organization_id', $org->id)->where('subject_type', (new CrmLead)->getMorphClass())
            ->whereIn('subject_id', $this->overview->filteredLeadQuery($org, $actor, $filters)->select('crm_leads.id'));
    }
}
