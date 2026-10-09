<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageLeaseDetails
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validateDetails(Organization $org, array $input, ?Lease $lease = null): array
    {
        $data = Validator::make($input, [
            'tenancy_number' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('leases')->where('organization_id', $org->id)->ignore($lease?->id)],
            'renewal_due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'advance_amount' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999999999.99'],
        ])->validate();

        return $data;
    }

    /** @param array<string, mixed> $input */
    public function update(Organization $org, User $actor, Lease $lease, array $input): Lease
    {
        abort_unless($actor->can('manageTransactions', $org), 403);
        $version = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1']])->validate()['expected_version'];
        $data = $this->validateDetails($org, $input, $lease);

        return DB::transaction(function () use ($org, $actor, $lease, $version, $data): Lease {
            $locked = Lease::where('organization_id', $org->id)->lockForUpdate()->findOrFail($lease->id);
            if ($locked->version !== (int) $version) {
                throw ValidationException::withMessages(['expected_version' => 'This lease changed. Refresh before saving.']);
            }
            $before = $locked->only(array_keys($data));
            $locked->fill($data);
            $locked->version++;
            $locked->save();
            $this->audit->handle($org, $actor, 'leasing.details.updated', $locked, ['before' => $before, 'after' => $data]);

            return $locked->refresh();
        });
    }
}
