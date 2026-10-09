<?php

namespace App\Domain\Brokerage\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordCommissionClawback
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input
     */
    public function handle(Organization $org, User $actor, int $commissionId, array $input): object
    {
        abort_unless($actor->can('manageTransactions', $org), 403);
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:1'],
            'amount' => ['required', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'reason' => ['required', 'string', 'max:2000'],
        ])->validate();
        if ($this->toCents($data['amount']) <= 0 || trim($data['reason']) === '') {
            throw ValidationException::withMessages(['amount' => 'Amount must be greater than zero.', 'reason' => 'Record a clawback reason.']);
        }

        return DB::transaction(function () use ($org, $actor, $commissionId, $data): object {
            $commission = DB::table('commission_transactions')->where('organization_id', $org->id)->where('id', $commissionId)->lockForUpdate()->first();
            abort_unless($commission !== null, 404);
            if ($commission->version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'This commission changed. Refresh before recording a clawback.']);
            }
            if ($commission->currency !== 'AED') {
                throw ValidationException::withMessages(['commission' => 'Only AED commissions are supported.']);
            }
            $already = DB::table('commission_clawbacks')->where('organization_id', $org->id)->where('commission_transaction_id', $commissionId)->sum('amount');
            if ($this->toCents((string) $already) + $this->toCents($data['amount']) > $this->toCents((string) $commission->commission_amount)) {
                throw ValidationException::withMessages(['amount' => 'Total clawbacks cannot exceed the commission amount.']);
            }
            $id = DB::table('commission_clawbacks')->insertGetId([
                'organization_id' => $org->id, 'commission_transaction_id' => $commissionId,
                'amount' => $data['amount'], 'reason' => trim($data['reason']), 'recorded_by' => $actor->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('commission_transactions')->where('id', $commissionId)->update(['version' => $commission->version + 1, 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'brokerage.commission.clawback_recorded', $org, ['commission_id' => $commissionId, 'clawback_id' => $id, 'amount' => $data['amount']]);

            return DB::table('commission_clawbacks')->where('id', $id)->first();
        });
    }

    private function toCents(string $amount): int
    {
        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
    }
}
