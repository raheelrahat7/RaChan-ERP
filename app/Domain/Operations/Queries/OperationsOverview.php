<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Operations\Data\OverviewFilters;
use App\Domain\Operations\Services\JobCardAccess;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class OperationsOverview
{
    public function __construct(private JobCardAccess $access) {}

    private const ACTIVE_STATUSES = ['open', 'in_progress', 'on_hold'];

    /** @return array{total: int, active: int, overdue: int, unassigned: int, urgent: int, on_hold: int, completed: int, cancelled: int, due_plans: int} */
    public function summary(Organization $organization, OverviewFilters $filters, CarbonImmutable $at, User $actor): array
    {
        $counts = $this->requests($organization, $filters, $actor)->selectRaw(
            "COUNT(*) AS total,
            COALESCE(SUM(work.status IN ('open', 'in_progress', 'on_hold')), 0) AS active,
            COALESCE(SUM(work.status IN ('open', 'in_progress') AND work.due_at < ?), 0) AS overdue,
            COALESCE(SUM(work.status IN ('open', 'in_progress', 'on_hold') AND work.assigned_to IS NULL), 0) AS unassigned,
            COALESCE(SUM(work.status IN ('open', 'in_progress', 'on_hold') AND work.priority = 'urgent'), 0) AS urgent,
            COALESCE(SUM(work.status = 'on_hold'), 0) AS on_hold,
            COALESCE(SUM(work.status = 'completed'), 0) AS completed,
            COALESCE(SUM(work.status = 'cancelled'), 0) AS cancelled",
            [$at->toDateTimeString()],
        )->firstOrFail();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'overdue' => (int) $counts->overdue,
            'unassigned' => (int) $counts->unassigned,
            'urgent' => (int) $counts->urgent,
            'on_hold' => (int) $counts->on_hold,
            'completed' => (int) $counts->completed,
            'cancelled' => (int) $counts->cancelled,
            'due_plans' => $this->plans($organization, $filters, $at, $actor)->where('plan.next_due_on', '<=', $at->setTimezone($organization->timezone)->toDateString())->count(),
        ];
    }

    /** @return list<array{assignee_id: int|null, assignee: string, active: int, overdue: int, on_hold: int}> */
    public function workload(Organization $organization, OverviewFilters $filters, CarbonImmutable $at, User $actor): array
    {
        return array_values($this->requests($organization, $filters, $actor)
            ->whereIn('work.status', self::ACTIVE_STATUSES)
            ->select('work.assigned_to', 'assignee.name as assignee_name')
            ->selectRaw("COUNT(*) AS active, SUM(work.status IN ('open', 'in_progress') AND work.due_at < ?) AS overdue, SUM(work.status = 'on_hold') AS on_hold", [$at->toDateTimeString()])
            ->groupBy('work.assigned_to', 'assignee.name')->orderByDesc('active')->orderBy('work.assigned_to')->get()
            ->map(fn (object $row): array => [
                'assignee_id' => $row->assigned_to === null ? null : (int) $row->assigned_to,
                'assignee' => $row->assigned_to === null ? 'Unassigned' : ($row->assignee_name ?? 'Unavailable member'),
                'active' => (int) $row->active,
                'overdue' => (int) $row->overdue,
                'on_hold' => (int) $row->on_hold,
            ])->all());
    }

    public function backlog(Organization $organization, OverviewFilters $filters, CarbonImmutable $at, User $actor): Builder
    {
        return $this->requests($organization, $filters, $actor)->whereIn('work.status', self::ACTIVE_STATUSES)
            ->select('work.id', 'work.reference', 'work.title', 'work.priority', 'work.status', 'work.due_at', 'property.name as property', 'vendor.name as vendor')
            ->selectRaw("CASE WHEN work.assigned_to IS NULL THEN 'Unassigned' ELSE COALESCE(assignee.name, 'Unavailable member') END AS assignee")
            ->selectRaw("CASE WHEN work.status IN ('open', 'in_progress') AND work.due_at < ? THEN 1 ELSE 0 END AS overdue", [$at->toDateTimeString()])
            ->orderByDesc('overdue')
            ->orderByRaw("CASE work.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByRaw('work.due_at IS NULL')->orderBy('work.due_at')->orderBy('work.id');
    }

    public function plans(Organization $organization, OverviewFilters $filters, CarbonImmutable $at, User $actor): Builder
    {
        return $this->scope(DB::table('preventive_maintenance_plans as plan'), 'plan', $organization, $filters)
            ->when(! $this->access->manages($organization, $actor), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->where('plan.is_active', true)
            ->where('plan.next_due_on', '<=', $at->setTimezone($organization->timezone)->addDays(7)->toDateString())
            ->select('plan.id', 'plan.title', 'plan.next_due_on', 'property.name as property', 'vendor.name as vendor')
            ->orderBy('plan.next_due_on')->orderBy('plan.id');
    }

    private function requests(Organization $organization, OverviewFilters $filters, User $actor): Builder
    {
        return $this->scope(DB::table('maintenance_requests as work'), 'work', $organization, $filters)
            ->when(! $this->access->manages($organization, $actor), fn (Builder $query) => $query->where('work.assigned_to', $actor->id))
            ->leftJoin('organization_user as membership', function (JoinClause $join) use ($organization): void {
                $join->on('membership.user_id', '=', 'work.assigned_to')->where('membership.organization_id', $organization->id);
            })
            ->leftJoin('users as assignee', 'assignee.id', '=', 'membership.user_id');
    }

    private function scope(Builder $query, string $table, Organization $organization, OverviewFilters $filters): Builder
    {
        return $query->where($table.'.organization_id', $organization->id)
            ->leftJoin('properties as property', function (JoinClause $join) use ($organization, $table): void {
                $join->on('property.id', '=', $table.'.property_id')->where('property.organization_id', $organization->id);
            })
            ->leftJoin('maintenance_vendors as vendor', function (JoinClause $join) use ($organization, $table): void {
                $join->on('vendor.id', '=', $table.'.vendor_id')->where('vendor.organization_id', $organization->id);
            })
            ->when($filters->propertyId !== null, fn (Builder $query) => $query->where($table.'.property_id', $filters->propertyId))
            ->when($filters->vendorId !== null, fn (Builder $query) => $query->where($table.'.vendor_id', $filters->vendorId));
    }
}
