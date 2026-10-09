<?php

namespace App\Domain\RealEstate\Actions;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageOffPlan
{
    public function __construct(private LeadVisibility $visibility, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function project(Organization $org, User $actor, array $input): int
    {
        $this->authorize($org, $actor);
        if (! DB::table('offplan_developers')->where('organization_id', $org->id)->where('id', $input['developer_id'])->exists()) {
            throw ValidationException::withMessages(['developer_id' => 'Choose a developer in this organization.']);
        }
        if (isset($input['cost_centre_id']) && ! DB::table('accounting_cost_centres')->where('organization_id', $org->id)->where('id', $input['cost_centre_id'])->exists()) {
            throw ValidationException::withMessages(['cost_centre_id' => 'Choose a cost centre in this organization.']);
        }

        return DB::transaction(function () use ($org, $actor, $input): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $this->unique('offplan_projects', $org->id, 'code', $input['code']);
            $id = DB::table('offplan_projects')->insertGetId([
                'organization_id' => $org->id, 'developer_id' => $input['developer_id'], 'cost_centre_id' => $input['cost_centre_id'] ?? null,
                'code' => $input['code'], 'name' => $input['name'], 'emirate' => $input['emirate'],
                'location' => $input['location'] ?? null, 'completion_on' => $input['completion_on'] ?? null,
                'commission_rate' => $input['commission_rate'] ?? 0, 'status' => 'active',
                'workflow_status' => $input['workflow_status'] ?? 'planning', 'launch_on' => $input['launch_on'] ?? null,
                'handover_on' => $input['handover_on'] ?? null, 'assigned_broker_id' => $input['assigned_broker_id'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'offplan.project.created', $org, ['project_id' => $id]);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function unit(Organization $org, User $actor, int $projectId, array $input): int
    {
        $this->authorize($org, $actor);

        return DB::transaction(function () use ($org, $actor, $projectId, $input): int {
            $project = DB::table('offplan_projects')->where('organization_id', $org->id)->where('id', $projectId)->lockForUpdate()->first();
            abort_unless($project !== null && $project->status === 'active', 404);
            if (DB::table('offplan_units')->where('project_id', $projectId)->where('number', $input['number'])->exists()) {
                throw ValidationException::withMessages(['number' => 'This unit number already exists in the project.']);
            }
            $id = DB::table('offplan_units')->insertGetId([
                'organization_id' => $org->id, 'project_id' => $projectId, 'number' => $input['number'],
                'type' => $input['type'] ?? null, 'area_sqft' => $input['area_sqft'] ?? null,
                'price_aed' => $input['price_aed'], 'status' => 'available', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'offplan.unit.created', $org, ['project_id' => $projectId, 'unit_id' => $id]);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function milestone(Organization $org, User $actor, int $projectId, array $input): void
    {
        $this->authorize($org, $actor);
        DB::transaction(function () use ($org, $actor, $projectId, $input): void {
            $project = DB::table('offplan_projects')->where('organization_id', $org->id)->where('id', $projectId)->lockForUpdate()->first();
            abort_unless($project !== null, 404);
            $sum = (float) DB::table('offplan_payment_milestones')->where('project_id', $projectId)->sum('percentage');
            if ($sum + (float) $input['percentage'] > 100.001) {
                throw ValidationException::withMessages(['percentage' => 'Payment milestone percentages cannot exceed 100% in total.']);
            }
            if (DB::table('offplan_payment_milestones')->where('project_id', $projectId)->where('sequence', $input['sequence'])->exists()) {
                throw ValidationException::withMessages(['sequence' => 'This milestone sequence already exists.']);
            }
            DB::table('offplan_payment_milestones')->insert([
                'organization_id' => $org->id, 'project_id' => $projectId, 'sequence' => $input['sequence'],
                'label' => $input['label'], 'percentage' => $input['percentage'], 'due_on' => $input['due_on'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'offplan.milestone.created', $org, ['project_id' => $projectId]);
        });
    }

    /** @param array<string, mixed> $input */
    public function deal(Organization $org, User $actor, array $input): int
    {
        $this->authorize($org, $actor);
        $lead = CrmLead::where('organization_id', $org->id)->whereKey($input['lead_id'])->first();
        if (! $lead || ! $this->visibility->canSeeLead($org, $actor, $lead->assigned_to)) {
            throw ValidationException::withMessages(['lead_id' => 'Choose a visible lead in this organization.']);
        }

        return DB::transaction(function () use ($org, $actor, $input): int {
            $unit = DB::table('offplan_units')->where('organization_id', $org->id)->where('id', $input['unit_id'])->lockForUpdate()->first();
            abort_unless($unit !== null, 404);
            $this->unique('offplan_deals', $org->id, 'reference', $input['reference']);
            $id = DB::table('offplan_deals')->insertGetId([
                'organization_id' => $org->id, 'project_id' => $unit->project_id,
                'unit_id' => $unit->id, 'lead_id' => $input['lead_id'], 'created_by' => $actor->id,
                'reference' => $input['reference'], 'price_aed' => $input['price_aed'] ?? $unit->price_aed,
                'status' => 'enquiry', 'notes' => $input['notes'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'offplan.deal.created', $org, ['deal_id' => $id]);

            return $id;
        });
    }

    public function status(Organization $org, User $actor, int $dealId, string $status, ?string $contractedOn): void
    {
        $this->authorize($org, $actor);
        DB::transaction(function () use ($org, $actor, $dealId, $status, $contractedOn): void {
            $deal = DB::table('offplan_deals')->where('organization_id', $org->id)->where('id', $dealId)->lockForUpdate()->first();
            abort_unless($deal !== null, 404);
            $unit = DB::table('offplan_units')->where('organization_id', $org->id)->where('id', $deal->unit_id)->lockForUpdate()->first();
            abort_unless($unit !== null, 404);
            $allowed = match ($deal->status) {
                'enquiry' => ['reserved', 'cancelled'],
                'reserved' => ['contracted', 'cancelled'],
                default => [],
            };
            abort_unless(in_array($status, $allowed, true), 422);
            if ($status === 'reserved') {
                abort_unless($unit->status === 'available', 422, 'This unit is no longer available.');
            }
            if ($status === 'contracted') {
                abort_unless($unit->status === 'reserved' && $contractedOn !== null, 422);
            }
            DB::table('offplan_deals')->where('id', $dealId)->update(['status' => $status, 'contracted_on' => $status === 'contracted' ? $contractedOn : null, 'updated_at' => now()]);
            if ($status !== 'cancelled' || $deal->status === 'reserved') {
                DB::table('offplan_units')->where('id', $unit->id)->update(['status' => match ($status) {
                    'reserved' => 'reserved', 'contracted' => 'sold', default => 'available'
                }, 'updated_at' => now()]);
            }
            $this->audit->handle($org, $actor, 'offplan.deal.status_changed', $org, ['deal_id' => $dealId, 'from' => $deal->status, 'to' => $status]);
        });
    }

    private function authorize(Organization $org, User $actor): void
    {
        abort_unless($actor->can('manageCrm', $org), 403);
    }

    private function unique(string $table, int $orgId, string $field, string $value): void
    {
        if (DB::table($table)->where('organization_id', $orgId)->where($field, $value)->exists()) {
            throw ValidationException::withMessages([$field => 'This reference already exists in the organization.']);
        }
    }
}
