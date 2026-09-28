<?php

namespace App\Domain\Finance\Actions;

use App\Domain\Accounting\Models\AccountingPeriod;
use App\Domain\Accounting\Models\LedgerAccount;
use App\Domain\Finance\Models\OperatingBudget;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageOperatingBudget
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    public function createDraft(Organization $org, User $actor, int $year, string $effectiveFrom, string $reason): OperatingBudget
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);

        return DB::transaction(function () use ($org, $actor, $year, $effectiveFrom, $reason): OperatingBudget {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $this->check($year >= (int) today()->format('Y') && $year <= 9999, 'year', 'Budgets can be drafted for the current or a future year.');
            $this->check(trim($reason) !== '', 'reason', 'A reason is required.');
            $this->check($effectiveFrom !== '' && $effectiveFrom >= today()->toDateString() && $effectiveFrom <= $year.'-12-31' && (int) substr($effectiveFrom, 0, 4) === $year && substr($effectiveFrom, 8, 2) === '01', 'effective_from', 'Choose the first day of a current or future month in the budget year.');
            $this->check(! OperatingBudget::where('organization_id', $org->id)->where('year', $year)->whereIn('status', ['draft', 'submitted'])->exists(), 'budget', 'An editable or submitted budget already exists for this year.');
            $version = (int) OperatingBudget::where('organization_id', $org->id)->where('year', $year)->max('version') + 1;
            $superseded = OperatingBudget::where('organization_id', $org->id)->where('year', $year)->where('status', 'approved')->where('effective_from', '<=', $effectiveFrom)->orderByDesc('effective_from')->first();
            $budget = OperatingBudget::create(['organization_id' => $org->id, 'year' => $year, 'version' => $version, 'status' => 'draft', 'currency' => 'AED', 'effective_from' => $effectiveFrom, 'supersedes_id' => $superseded?->id, 'reason' => trim($reason), 'created_by' => $actor->id]);
            if ($superseded) {
                foreach ($superseded->lines as $line) {
                    $budget->lines()->create(['ledger_account_id' => $line->ledger_account_id, 'month' => $line->month, 'amount' => $line->amount]);
                }
            }
            $this->audit->handle($org, $actor, 'finance.operating_budget.created', $budget, ['year' => $year, 'version' => $version, 'effective_from' => $effectiveFrom, 'supersedes_id' => $superseded?->id, 'reason' => $reason]);

            return $budget;
        });
    }

    /** @param list<string|int|float> $amounts */
    public function saveAccount(Organization $org, User $actor, OperatingBudget $budget, int $accountId, array $amounts): void
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        DB::transaction(function () use ($org, $actor, $budget, $accountId, $amounts): void {
            $locked = $this->lockBudget($org, $budget);
            $this->check($locked->status === 'draft', 'budget', 'Only a draft budget can be edited.');
            $account = LedgerAccount::where('organization_id', $org->id)->whereKey($accountId)->lockForUpdate()->first();
            $this->check($account !== null && $account->type === 'expense' && $account->is_active, 'account_id', 'Choose an active expense account in this organization.');
            $this->check(count($amounts) === 12, 'monthly_amounts', 'Enter one monthly amount for each month.');
            foreach ($amounts as $amount) {
                $this->check(is_numeric($amount) && (float) $amount >= 0 && round((float) $amount, 2) === (float) $amount && (float) $amount < 1000000000000, 'monthly_amounts', 'Monthly amounts must be non-negative AED values with at most two decimal places.');
            }
            foreach ($amounts as $index => $amount) {
                $locked->lines()->updateOrCreate(['ledger_account_id' => $accountId, 'month' => $index + 1], ['amount' => $amount]);
            }
            $this->audit->handle($org, $actor, 'finance.operating_budget.line_updated', $locked, ['year' => $locked->year, 'version' => $locked->version, 'account_id' => $accountId]);
        });
    }

    public function removeAccount(Organization $org, User $actor, OperatingBudget $budget, int $accountId): void
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        DB::transaction(function () use ($org, $actor, $budget, $accountId): void {
            $locked = $this->lockBudget($org, $budget);
            $this->check($locked->status === 'draft', 'budget', 'Only a draft budget can be edited.');
            $deleted = $locked->lines()->where('ledger_account_id', $accountId)->delete();
            $this->check($deleted > 0, 'account_id', 'This account is not in the budget.');
            $this->audit->handle($org, $actor, 'finance.operating_budget.line_removed', $locked, ['year' => $locked->year, 'version' => $locked->version, 'account_id' => $accountId]);
        });
    }

    public function rebase(Organization $org, User $actor, OperatingBudget $budget, string $effectiveFrom): void
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        DB::transaction(function () use ($org, $actor, $budget, $effectiveFrom): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockBudget($org, $budget);
            $this->check($locked->status === 'draft', 'budget', 'Only a draft budget can be rescheduled.');
            $this->check($effectiveFrom !== '' && $effectiveFrom >= today()->toDateString() && $effectiveFrom <= $locked->year.'-12-31' && (int) substr($effectiveFrom, 0, 4) === $locked->year && substr($effectiveFrom, 8, 2) === '01', 'effective_from', 'Choose the first day of a current or future month in the budget year.');
            $source = OperatingBudget::where('organization_id', $org->id)->where('year', $locked->year)->where('status', 'approved')->where('effective_from', '<=', $effectiveFrom)->orderByDesc('effective_from')->with('lines')->first();
            $oldEffective = $locked->effective_from?->toDateString();
            $locked->lines()->delete();
            if ($source) {
                foreach ($source->lines as $line) {
                    $locked->lines()->create(['ledger_account_id' => $line->ledger_account_id, 'month' => $line->month, 'amount' => $line->amount]);
                }
            }
            $locked->update(['effective_from' => $effectiveFrom, 'supersedes_id' => $source?->id]);
            $this->audit->handle($org, $actor, 'finance.operating_budget.rescheduled', $locked, ['year' => $locked->year, 'version' => $locked->version, 'prior_effective_from' => $oldEffective, 'effective_from' => $effectiveFrom, 'rebased_from_version' => $source?->version]);
        });
    }

    public function submit(Organization $org, User $actor, OperatingBudget $budget): void
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        DB::transaction(function () use ($org, $actor, $budget): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockBudget($org, $budget);
            $this->check($locked->status === 'draft', 'budget', 'Only a draft budget can be submitted.');
            $this->check($locked->lines()->distinct('ledger_account_id')->count('ledger_account_id') > 0, 'budget', 'Add at least one expense account before submitting.');
            $effectiveFrom = $locked->effective_from?->toDateString() ?? '';
            $this->assertEffectiveDate($locked, $effectiveFrom);
            $locked->update(['status' => 'submitted', 'submitted_by' => $actor->id, 'submitted_at' => now()]);
            $this->audit->handle($org, $actor, 'finance.operating_budget.submitted', $locked, ['year' => $locked->year, 'version' => $locked->version, 'effective_from' => $effectiveFrom, 'supersedes_id' => $locked->supersedes_id, 'reason' => $locked->reason]);
        });
    }

    public function approve(Organization $org, User $actor, OperatingBudget $budget): void
    {
        $this->authorizeApprover($org, $actor);
        DB::transaction(function () use ($org, $actor, $budget): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockBudget($org, $budget);
            $this->check($locked->status === 'submitted', 'budget', 'Only a submitted budget can be approved.');
            $this->check($locked->submitted_by !== $actor->id, 'budget', 'The submitter cannot approve their own budget.');
            $this->assertEffectiveDate($locked, $locked->effective_from?->toDateString() ?? '');
            $effective = $locked->effective_from;
            $this->check($effective !== null, 'budget', 'A budget effective month is required.');
            $monthStart = $effective->toDateString();
            $yearEnd = $effective->copy()->endOfYear()->toDateString();
            $closed = AccountingPeriod::where('organization_id', $org->id)->where('status', 'closed')->whereDate('starts_on', '<=', $yearEnd)->whereDate('ends_on', '>=', $monthStart)->exists();
            $this->check(! $closed, 'budget', 'A replacement cannot change months in a closed accounting period.');
            $locked->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $this->audit->handle($org, $actor, 'finance.operating_budget.approved', $locked, ['year' => $locked->year, 'version' => $locked->version, 'effective_from' => $monthStart, 'supersedes_id' => $locked->supersedes_id, 'reason' => $locked->reason]);
            if ($locked->supersedes_id) {
                $this->audit->handle($org, $actor, 'finance.operating_budget.superseded', $locked, ['year' => $locked->year, 'version' => $locked->version, 'supersedes_id' => $locked->supersedes_id, 'effective_from' => $monthStart]);
            }
        });
    }

    public function reject(Organization $org, User $actor, OperatingBudget $budget, string $reason): OperatingBudget
    {
        $this->authorizeApprover($org, $actor);

        return DB::transaction(function () use ($org, $actor, $budget, $reason): OperatingBudget {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $locked = $this->lockBudget($org, $budget);
            $this->check($locked->status === 'submitted', 'budget', 'Only a submitted budget can be rejected.');
            $this->check($locked->submitted_by !== $actor->id, 'budget', 'The submitter cannot reject their own budget.');
            $this->check(trim($reason) !== '', 'reason', 'A rejection reason is required.');
            $locked->update(['status' => 'rejected', 'rejection_reason' => trim($reason), 'rejected_by' => $actor->id, 'rejected_at' => now()]);
            $version = (int) OperatingBudget::where('organization_id', $org->id)->where('year', $locked->year)->max('version') + 1;
            $draft = OperatingBudget::create(['organization_id' => $org->id, 'year' => $locked->year, 'version' => $version, 'status' => 'draft', 'currency' => 'AED', 'effective_from' => $locked->effective_from, 'supersedes_id' => $locked->supersedes_id, 'reason' => 'Revision after version '.$locked->version.' rejection: '.trim($reason), 'created_by' => $locked->created_by]);
            foreach ($locked->lines as $line) {
                $draft->lines()->create(['ledger_account_id' => $line->ledger_account_id, 'month' => $line->month, 'amount' => $line->amount]);
            }
            $this->audit->handle($org, $actor, 'finance.operating_budget.rejected', $locked, ['year' => $locked->year, 'version' => $locked->version, 'reason' => trim($reason), 'replacement_draft_id' => $draft->id]);
            $this->audit->handle($org, $actor, 'finance.operating_budget.revision_created', $draft, ['year' => $draft->year, 'version' => $draft->version, 'source_version' => $locked->version, 'reason' => $draft->reason]);

            return $draft;
        });
    }

    private function assertEffectiveDate(OperatingBudget $budget, string $date): void
    {
        $this->check($date !== '' && $date >= today()->toDateString() && $date <= $budget->year.'-12-31' && (int) substr($date, 0, 4) === $budget->year && substr($date, 8, 2) === '01', 'effective_from', 'Choose the first day of a current or future month in the budget year, no earlier than today.');
    }

    private function authorizeApprover(Organization $org, User $actor): void
    {
        Gate::forUser($actor)->authorize('viewFinance', $org);
        $this->check($actor->hasOrganizationRole($org, OrganizationRole::Owner) || $actor->hasOrganizationRole($org, OrganizationRole::Administrator), 'budget', 'Only an organization owner or administrator can approve budgets.');
    }

    private function lockBudget(Organization $org, OperatingBudget $budget): OperatingBudget
    {
        return OperatingBudget::where('organization_id', $org->id)->lockForUpdate()->findOrFail($budget->id);
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
