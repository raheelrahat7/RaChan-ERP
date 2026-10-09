<?php

namespace App\Domain\RealEstate\Queries;

use App\Models\Organization;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class OffPlanOverview
{
    /** @param array<string, mixed> $filters */
    public function query(Organization $org, array $filters): Builder
    {
        return DB::table('offplan_projects as project')
            ->join('offplan_developers as developer', 'developer.id', '=', 'project.developer_id')
            ->leftJoin('brokers as agent', 'agent.id', '=', 'project.assigned_broker_id')
            ->where('project.organization_id', $org->id)
            ->when($filters['workflow_status'] ?? null, fn ($q, $value) => $q->where('project.workflow_status', $value))
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($scope) => $scope->where('project.name', 'like', '%'.$value.'%')->orWhere('project.code', 'like', '%'.$value.'%')->orWhere('project.location', 'like', '%'.$value.'%')))
            ->select('project.*', 'developer.name as developer_name', 'agent.name as assigned_broker_name')
            ->selectSub(DB::table('offplan_units')->selectRaw('COUNT(*)')->whereColumn('project_id', 'project.id'), 'units_total')
            ->selectSub(DB::table('offplan_units')->selectRaw('COUNT(*)')->whereColumn('project_id', 'project.id')->where('status', 'available'), 'units_available')
            ->selectSub(DB::table('offplan_units')->selectRaw('COUNT(*)')->whereColumn('project_id', 'project.id')->where('status', 'sold'), 'units_sold')
            ->selectSub(DB::table('offplan_units')->selectRaw('MIN(price_aed)')->whereColumn('project_id', 'project.id')->where('status', 'available'), 'starting_price_aed')
            ->orderBy('project.name');
    }

    /** @return array<string, mixed> */
    public function serialize(object $project, bool $canManage): array
    {
        $values = (array) $project;
        foreach (['units_total', 'units_available', 'units_sold', 'version'] as $field) {
            $values[$field] = (int) $values[$field];
        }
        $values['permissions'] = ['read' => true, 'edit' => $canManage, 'configure_statuses' => $canManage];

        return $values;
    }

    /** @param array<string, mixed> $filters
     * @return array<int, array{code: string, total: int}>
     */
    public function statusCounts(Organization $org, array $filters): array
    {
        return DB::table('offplan_projects as project')->where('project.organization_id', $org->id)
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($scope) => $scope->where('project.name', 'like', '%'.$value.'%')->orWhere('project.code', 'like', '%'.$value.'%')->orWhere('project.location', 'like', '%'.$value.'%')))
            ->selectRaw('project.workflow_status as code, COUNT(*) as total')->groupBy('project.workflow_status')->get()
            ->map(fn ($row) => ['code' => $row->code, 'total' => (int) $row->total])->all();
    }
}
