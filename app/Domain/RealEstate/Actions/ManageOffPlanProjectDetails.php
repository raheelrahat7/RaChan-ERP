<?php

namespace App\Domain\RealEstate\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageOffPlanProjectDetails
{
    public const DEFAULT_STATUSES = ['planning' => 'Planning', 'launch_ready' => 'Launch Ready', 'launched' => 'Launched', 'selling' => 'Selling', 'sold_out' => 'Sold Out', 'on_hold' => 'On Hold', 'completed' => 'Completed'];

    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @return list<array<string, mixed>> */
    public function statuses(Organization $org): array
    {
        $rows = DB::table('offplan_project_statuses')->where('organization_id', $org->id)->orderBy('position')->orderBy('id')->get();
        if ($rows->isNotEmpty()) {
            return array_values($rows->map(fn ($row) => ['id' => $row->id, 'code' => $row->code, 'name' => $row->name, 'position' => $row->position, 'active' => (bool) $row->active, 'version' => $row->version])->all());
        }
        $statuses = [];
        foreach (self::DEFAULT_STATUSES as $code => $name) {
            $statuses[] = ['id' => null, 'code' => $code, 'name' => $name, 'position' => count($statuses), 'active' => true, 'version' => null];
        }

        return $statuses;
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validateDetails(Organization $org, array $input): array
    {
        $data = Validator::make($input, ['workflow_status' => ['sometimes', 'string', 'max:40'], 'launch_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'handover_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'assigned_broker_id' => ['sometimes', 'nullable', 'integer'], 'completion_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'], 'commission_rate' => ['sometimes', 'numeric', 'between:0,100', 'decimal:0,2']])->validate();
        if (isset($data['workflow_status']) && ! in_array($data['workflow_status'], array_column(array_filter($this->statuses($org), fn ($status) => $status['active']), 'code'), true)) {
            throw ValidationException::withMessages(['workflow_status' => 'Select an active organization off-plan status.']);
        }
        if (isset($data['assigned_broker_id']) && ! DB::table('brokers')->where('organization_id', $org->id)->where('id', $data['assigned_broker_id'])->exists()) {
            throw ValidationException::withMessages(['assigned_broker_id' => 'Select a broker in this organization.']);
        }
        if (isset($data['launch_on'], $data['handover_on']) && $data['handover_on'] < $data['launch_on']) {
            throw ValidationException::withMessages(['handover_on' => 'Handover date must be on or after launch date.']);
        }

        return $data;
    }

    /** @param array<string, mixed> $input
     */
    public function update(Organization $org, User $actor, int $projectId, array $input): object
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        $version = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1']])->validate()['expected_version'];
        $data = $this->validateDetails($org, $input);

        return DB::transaction(function () use ($org, $actor, $projectId, $version, $data): object {
            $project = DB::table('offplan_projects')->where('organization_id', $org->id)->where('id', $projectId)->lockForUpdate()->first();
            abort_unless($project !== null, 404);
            if ($project->version !== (int) $version) {
                throw ValidationException::withMessages(['expected_version' => 'This project changed. Refresh before saving.']);
            }
            if (($data['handover_on'] ?? $project->handover_on) && ($data['launch_on'] ?? $project->launch_on) && ($data['handover_on'] ?? $project->handover_on) < ($data['launch_on'] ?? $project->launch_on)) {
                throw ValidationException::withMessages(['handover_on' => 'Handover date must be on or after launch date.']);
            }
            DB::table('offplan_projects')->where('id', $projectId)->update([...$data, 'version' => $project->version + 1, 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'offplan.project.updated', $org, ['project_id' => $projectId, 'changes' => $data]);

            return DB::table('offplan_projects')->where('id', $projectId)->first();
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function saveStatus(Organization $org, User $actor, array $input, ?int $id = null): array
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        $data = Validator::make($input, ['code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'], 'name' => ['required', 'string', 'max:120'], 'position' => ['required', 'integer', 'between:0,10000'], 'active' => ['required', 'boolean'], 'expected_version' => [$id ? 'required' : 'prohibited', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($org, $actor, $data, $id): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (! DB::table('offplan_project_statuses')->where('organization_id', $org->id)->exists()) {
                foreach (self::DEFAULT_STATUSES as $code => $name) {
                    DB::table('offplan_project_statuses')->insert(['organization_id' => $org->id, 'code' => $code, 'name' => $name, 'position' => array_search($code, array_keys(self::DEFAULT_STATUSES), true), 'active' => true, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $row = $id ? DB::table('offplan_project_statuses')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first() : null;
            abort_if($id && $row === null, 404);
            if ($row && $row->version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'This status changed. Refresh before saving.']);
            }
            if ($row && $row->code !== $data['code']) {
                throw ValidationException::withMessages(['code' => 'Existing status codes cannot change.']);
            }
            if (! $row && DB::table('offplan_project_statuses')->where('organization_id', $org->id)->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages(['code' => 'This status code already exists; update it by ID and version.']);
            }
            unset($data['expected_version']);
            if ($row) {
                DB::table('offplan_project_statuses')->where('id', $id)->update([...$data, 'version' => $row->version + 1, 'updated_at' => now()]);
            } else {
                $id = DB::table('offplan_project_statuses')->insertGetId(['organization_id' => $org->id, ...$data, 'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            }
            $saved = (array) DB::table('offplan_project_statuses')->where('id', $id)->first();
            $saved['active'] = (bool) $saved['active'];
            $this->audit->handle($org, $actor, 'offplan.project_status.saved', $org, ['status_id' => $id]);

            return $saved;
        });
    }
}
