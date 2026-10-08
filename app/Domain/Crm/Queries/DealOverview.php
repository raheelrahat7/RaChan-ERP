<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Actions\ManageCrmSettings;
use App\Domain\Crm\Actions\ManageDealFinancialLinks;
use App\Domain\Crm\Actions\ManageRecordFields;
use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\DealPipeline;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Crm\Services\DealConditions;
use App\Domain\Crm\Services\LeadVisibility;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class DealOverview
{
    public function __construct(private DealAccess $access, private LeadVisibility $leadVisibility, private ManageRecordFields $fields) {}

    /** @param array<string, mixed> $filters
     * @return Builder<Deal>
     */
    public function query(Organization $org, User $actor, array $filters): Builder
    {
        $query = $this->access->query($org, $actor)
            ->when($filters['pipeline_id'] ?? null, fn ($q, $id) => $q->where('pipeline_id', $id))
            ->when($filters['stage_id'] ?? null, fn ($q, $id) => $q->where('current_stage_id', $id))
            ->when($filters['assigned_to'] ?? null, fn ($q, $id) => $q->where('assigned_to', $id))
            ->when($filters['category'] ?? null, fn ($q, $category) => $q->where('category', $category))
            ->when($filters['q'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('title', 'like', '%'.$search.'%')->orWhere('first_name', 'like', '%'.$search.'%')->orWhere('last_name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%')->orWhere('phone', 'like', '%'.$search.'%')));
        app(DealConditions::class)->apply($query, $org, $actor, $filters['custom_filters'] ?? []);

        return $query;
    }

    /** @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function index(Organization $org, User $actor, array $filters): array
    {
        $query = $this->query($org, $actor, $filters);
        $deals = (clone $query)->with(['stage', 'assignee:id,name', 'pipeline'])->latest('id')->paginate(50)->withQueryString();
        $deals->through(fn ($deal) => $this->serialize($org, $actor, $deal));
        $counts = (clone $query)->selectRaw('pipeline_id, current_stage_id, COUNT(*) as total')->groupBy('pipeline_id', 'current_stage_id')->get()
            ->map(function ($row) use ($org, $actor, $query): array {
                $pipeline = DealPipeline::where('organization_id', $org->id)->findOrFail($row->pipeline_id);
                $amountScopes = $this->access->scopes($org, $actor, $pipeline, 'amount');
                $amount = null;
                if ($amountScopes !== []) {
                    $amountQuery = (clone $query)->where('pipeline_id', $row->pipeline_id)->where('current_stage_id', $row->current_stage_id);
                    if (! in_array('organization', $amountScopes, true)) {
                        $amountQuery->whereIn('assigned_to', $this->access->assignees($org, $actor, $amountScopes));
                    }
                    $amount = (string) $amountQuery->sum('amount');
                    $amount = str_contains($amount, '.') ? $amount : $amount.'.00';
                }

                return ['pipeline_id' => $row->pipeline_id, 'current_stage_id' => $row->current_stage_id, 'total' => (int) $row->getAttribute('total'), 'amount' => $amount];
            });

        return ['deals' => $deals, 'pipelines' => $this->pipelines($org, $actor), 'stageCounts' => $counts, 'filters' => $filters, 'categories' => app(ManageCrmSettings::class)->activeCategories($org), 'categoryOptions' => app(ManageCrmSettings::class)->categories($org), 'dealStatusOptions' => app(ManageCrmSettings::class)->dealOptions($org, 'deal_statuses'), 'dealScenarioOptions' => app(ManageCrmSettings::class)->dealOptions($org, 'deal_scenarios'), 'timezone' => $org->timezone, 'filterFields' => app(DealConditions::class)->catalog($org, $actor), 'canConfigure' => $this->access->administrator($org, $actor)];
    }

    /** @return array<string, mixed> */
    public function show(Organization $org, User $actor, Deal $deal): array
    {
        $deal = $this->access->query($org, $actor)->with(['stage', 'assignee:id,name', 'pipeline'])->findOrFail($deal->id);
        $lead = $deal->lead;
        $canSeeLead = $lead && $this->leadVisibility->canSeeLead($org, $actor, $lead->assigned_to);

        return ['deal' => $this->serialize($org, $actor, $deal), 'sourceLead' => $canSeeLead ? $lead->only('id', 'first_name', 'last_name', 'current_stage_id', 'converted_at') : null,
            'financialRecords' => app(ManageDealFinancialLinks::class)->summary($org, $actor, $deal), 'customFields' => $this->fields->values($org, $actor, $deal), 'stages' => $deal->pipeline->stages()->get(), 'pipelines' => $this->pipelines($org, $actor), 'dealStatusOptions' => app(ManageCrmSettings::class)->dealOptions($org, 'deal_statuses'), 'dealScenarioOptions' => app(ManageCrmSettings::class)->dealOptions($org, 'deal_scenarios'),
            'history' => $deal->history()->where('organization_id', $org->id)->paginate(100, ['*'], 'history_page'),
            'timeline' => AuditLog::where('organization_id', $org->id)->where('subject_type', $deal->getMorphClass())->where('subject_id', $deal->id)->with('actor:id,name')->latest('id')->paginate(100, ['*'], 'timeline_page'),
            'activities' => $deal->activities()->where('organization_id', $org->id)->with('creator:id,name')->latest('id')->paginate(50, ['*'], 'activity_page'), 'timezone' => $org->timezone];
    }

    /** @return array<string, mixed> */
    public function serialize(Organization $org, User $actor, Deal $deal): array
    {
        $deal->load(['stage', 'pipeline', 'assignee:id,name']);
        $values = $deal->toArray();
        unset($values['lead']);
        if (! $this->access->allows($org, $actor, $deal->pipeline, 'amount', $deal->assigned_to)) {
            unset($values['amount'], $values['currency'], $values['gross_commission'], $values['co_broker_share'], $values['agent_share']);
        }
        if ($deal->lead_id && ! $this->leadVisibility->canSeeLead($org, $actor, $deal->lead->assigned_to)) {
            unset($values['lead_id']);
        }
        $values['permissions'] = collect(DealAccess::ACTIONS)->mapWithKeys(fn ($action) => [$action => $this->access->allows($org, $actor, $deal->pipeline, $action, $deal->assigned_to)])->all();

        return $values;
    }

    /** @return array<int, array<string, mixed>> */
    public function pipelines(Organization $org, User $actor): array
    {
        return DealPipeline::where('organization_id', $org->id)->with('stages')->orderBy('position')->orderBy('id')->get()
            ->filter(fn ($pipeline) => $this->access->scopes($org, $actor, $pipeline, 'read') !== [])
            ->map(function ($pipeline) use ($org, $actor): array {
                $read = $this->access->scopes($org, $actor, $pipeline, 'read');
                $members = $org->users()->when(! in_array('organization', $read, true), fn ($q) => $q->whereIn('users.id', $this->access->assignees($org, $actor, $read)))->orderBy('name')->get(['users.id', 'users.name'])->map->only(['id', 'name']);

                return [...$pipeline->toArray(), 'members' => $members, 'permissions' => collect(DealAccess::ACTIONS)->mapWithKeys(fn ($action) => [$action => $this->access->scopes($org, $actor, $pipeline, $action)])->all()];
            })->values()->all();
    }
}
