<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageAccountingDimensions
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array{code:string,name:string,active?:bool,company_id?:int,branch_id?:int} $data */
    public function create(Organization $organization, User $actor, string $level, array $data): int
    {
        $table = match ($level) {
            'company' => 'accounting_companies',
            'branch' => 'accounting_branches',
            'cost_centre' => 'accounting_cost_centres',
            default => abort(404),
        };

        return DB::transaction(function () use ($organization, $actor, $level, $data, $table): int {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $parentColumn = match ($level) {
                'branch' => 'company_id',
                'cost_centre' => 'branch_id',
                default => null,
            };
            if ($parentColumn) {
                $parentTable = $level === 'branch' ? 'accounting_companies' : 'accounting_branches';
                $parent = DB::table($parentTable)->where('organization_id', $organization->id)->where('active', true)->where('id', (int) ($data[$parentColumn] ?? 0))->first();
                if (! $parent) {
                    throw ValidationException::withMessages([$parentColumn => 'Choose an active parent in this organization.']);
                }
            }
            $unique = DB::table($table)->where($parentColumn ?? 'organization_id', $parentColumn ? (int) ($data[$parentColumn] ?? 0) : $organization->id)->where('code', $data['code'])->exists();
            if ($unique) {
                throw ValidationException::withMessages(['code' => 'This code is already used within its parent.']);
            }
            $id = DB::table($table)->insertGetId([
                'organization_id' => $organization->id,
                ...($parentColumn ? [$parentColumn => (int) ($data[$parentColumn] ?? 0)] : []),
                'code' => $data['code'], 'name' => $data['name'], 'active' => $data['active'] ?? true,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($organization, $actor, 'accounting.dimension.created', $organization, ['level' => $level, 'id' => $id]);

            return $id;
        });
    }

    /** @param array<string, mixed> $line */
    public function validateLine(Organization $organization, array $line): void
    {
        $companyId = $line['company_id'] ?? null;
        $branchId = $line['branch_id'] ?? null;
        $centreId = $line['cost_centre_id'] ?? null;
        if (! $companyId && ! $branchId && ! $centreId) {
            return;
        }
        $company = $companyId ? DB::table('accounting_companies')->where('organization_id', $organization->id)->where('active', true)->where('id', $companyId)->first() : null;
        $branch = $branchId ? DB::table('accounting_branches')->where('organization_id', $organization->id)->where('active', true)->where('id', $branchId)->first() : null;
        $centre = $centreId ? DB::table('accounting_cost_centres')->where('organization_id', $organization->id)->where('active', true)->where('id', $centreId)->first() : null;
        if (! $company || ($branchId && (! $branch || $branch->company_id !== $company->id))
            || ($centreId && (! $branch || ! $centre || $centre->branch_id !== $branch->id))) {
            throw ValidationException::withMessages(['lines' => 'Choose active accounting dimensions from one company and branch in this organization.']);
        }
    }
}
