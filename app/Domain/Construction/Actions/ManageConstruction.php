<?php

namespace App\Domain\Construction\Actions;

use App\Domain\Construction\Models\BoqItem;
use App\Domain\Construction\Models\BoqProgress;
use App\Domain\Construction\Models\ConstructionProject;
use App\Domain\Construction\Models\ContractorClaim;
use App\Domain\Construction\Models\ContractorClaimLine;
use App\Domain\Construction\Services\BoqAmounts;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Domain\Operations\Services\JobCostAmount;
use App\Domain\Operations\Services\StockQuantity;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Brick\Math\BigInteger;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageConstruction
{
    public function __construct(private StockQuantity $quantities, private JobCostAmount $money, private BoqAmounts $amounts, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input */
    public function project(Organization $org, User $actor, array $input): ConstructionProject
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $values = Validator::make($input, ['reference' => ['required', 'string', 'max:100'], 'title' => ['required', 'string', 'max:255'], 'budget' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'], 'property_id' => ['nullable', 'integer', Rule::exists('properties', 'id')->where('organization_id', $org->id)]])->validate();

        return DB::transaction(function () use ($org, $actor, $values): ConstructionProject {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $reference = trim($values['reference']);
            $title = trim($values['title']);
            $this->check($reference !== '' && $title !== '', 'reference', 'Reference and title are required.');
            $this->check(! ConstructionProject::where('organization_id', $org->id)->where('reference', $reference)->exists(), 'reference', 'This project reference already exists.');
            $project = ConstructionProject::create(['organization_id' => $org->id, 'property_id' => $values['property_id'] ?? null, 'reference' => $reference, 'title' => $title, 'budget_cents' => $this->money->hundredths($values['budget']), 'created_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'construction.project.created', $project);

            return $project;
        });
    }

    /** @param array<string,mixed> $input */
    public function item(Organization $org, User $actor, ConstructionProject $project, array $input): BoqItem
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $values = Validator::make($input, ['reference' => ['required', 'string', 'max:100'], 'description' => ['required', 'string', 'max:255'], 'unit' => ['required', 'string', 'max:30'], 'quantity' => ['required', 'string'], 'unit_rate' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/']])->validate();
        $quantity = $this->quantities->parse($values['quantity']);
        $rate = $this->money->hundredths($values['unit_rate']);

        return DB::transaction(function () use ($org, $actor, $project, $values, $quantity, $rate): BoqItem {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $project = $this->active($org, $project);
            $reference = trim($values['reference']);
            $description = trim($values['description']);
            $unit = trim($values['unit']);
            $this->check($reference !== '' && $description !== '' && $unit !== '', 'reference', 'Reference, description and unit are required.');
            $this->check(! BoqItem::where('organization_id', $org->id)->where('construction_project_id', $project->id)->where('reference', $reference)->exists(), 'reference', 'This BOQ reference already exists.');
            $amount = $this->amounts->total($quantity, $rate);
            $this->check($amount + (int) BoqItem::where('organization_id', $org->id)->where('construction_project_id', $project->id)->sum('amount_cents') <= 99999999999999, 'unit_rate', 'The project BOQ exceeds the supported amount.');
            $item = BoqItem::create(['organization_id' => $org->id, 'construction_project_id' => $project->id, 'reference' => $reference, 'description' => $description, 'unit' => $unit, 'quantity' => $quantity, 'unit_rate_cents' => $rate, 'amount_cents' => $amount]);
            $this->audit->handle($org, $actor, 'construction.boq.created', $item);

            return $item;
        });
    }

    /** @param array<string,mixed> $input */
    public function progress(Organization $org, User $actor, BoqItem $item, array $input): BoqProgress
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $values = Validator::make($input, ['quantity' => ['required', 'string'], 'note' => ['required', 'string', 'max:2000'], 'operation_key' => ['required', 'uuid']])->validate();
        $quantity = $this->quantities->parse($values['quantity']);
        $note = trim($values['note']);
        $this->check($note !== '', 'note', 'Describe the completed work.');

        return DB::transaction(function () use ($org, $actor, $item, $values, $quantity, $note): BoqProgress {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $item = BoqItem::where('organization_id', $org->id)->findOrFail($item->id);
            $existing = BoqProgress::where('organization_id', $org->id)->where('operation_key', $values['operation_key'])->first();
            if ($existing !== null) {
                $this->check($existing->construction_boq_item_id === $item->id && $existing->quantity === $quantity && $existing->note === $note, 'operation_key', 'This progress key already has different details.');

                return $existing;
            }
            $this->active($org, ConstructionProject::where('organization_id', $org->id)->findOrFail($item->construction_project_id));
            $this->check($this->completed($org, $item) + $quantity <= $item->quantity, 'quantity', 'Completed quantity exceeds the BOQ quantity.');
            $record = BoqProgress::create(['organization_id' => $org->id, 'construction_boq_item_id' => $item->id, 'quantity' => $quantity, 'note' => $note, 'operation_key' => $values['operation_key'], 'recorded_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'construction.boq.progress_recorded', $record);

            return $record;
        });
    }

    public function voidProgress(Organization $org, User $actor, BoqProgress $progress, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $reason = $this->reason($reason);
        DB::transaction(function () use ($org, $actor, $progress, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $progress = BoqProgress::where('organization_id', $org->id)->findOrFail($progress->id);
            if ($progress->voided_at !== null) {
                return;
            }
            $item = BoqItem::where('organization_id', $org->id)->findOrFail($progress->construction_boq_item_id);
            $this->check($this->completed($org, $item) - $progress->quantity >= (int) $this->reserved($org, $item)->sum('quantity'), 'progress', 'Reject unapproved claims before reducing work reserved for claims. Approved claims require a separate finance correction.');
            $progress->update(['voided_at' => now(), 'voided_by' => $actor->id, 'void_reason' => $reason]);
            $this->audit->handle($org, $actor, 'construction.boq.progress_voided', $progress, ['reason' => $reason]);
        });
    }

    /** @param array<string,mixed> $input */
    public function claim(Organization $org, User $actor, ConstructionProject $project, array $input): ContractorClaim
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        $values = Validator::make($input, ['vendor_id' => ['required', 'integer'], 'claimed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'reason' => ['required', 'string', 'max:2000'], 'operation_key' => ['required', 'uuid'], 'lines' => ['required', 'array', 'min:1', 'max:100'], 'lines.*.item_id' => ['required', 'integer', 'distinct'], 'lines.*.quantity' => ['required', 'string']])->validate();
        $reason = trim($values['reason']);
        $this->check($reason !== '', 'reason', 'Describe the contractor claim.');

        return DB::transaction(function () use ($org, $actor, $project, $values, $reason): ContractorClaim {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $project = ConstructionProject::where('organization_id', $org->id)->findOrFail($project->id);
            MaintenanceVendor::where('organization_id', $org->id)->findOrFail((int) $values['vendor_id']);
            $existing = ContractorClaim::where('organization_id', $org->id)->where('operation_key', $values['operation_key'])->first();
            $requested = [];
            foreach ($values['lines'] as $line) {
                $requested[(int) $line['item_id']] = $this->quantities->parse($line['quantity']);
            } ksort($requested);
            if ($existing !== null) {
                $stored = ContractorClaimLine::where('organization_id', $org->id)->where('contractor_claim_id', $existing->id)->orderBy('construction_boq_item_id')->pluck('quantity', 'construction_boq_item_id')->all();
                $this->check($existing->construction_project_id === $project->id && $existing->requested_by === $actor->id && $existing->vendor_id === (int) $values['vendor_id'] && $existing->reason === $reason && $existing->claimed_on->format('Y-m-d') === $values['claimed_on'] && $stored === $requested, 'operation_key', 'This claim key already has different details.');

                return $existing;
            }
            $this->active($org, $project);
            $lines = [];
            $total = 0;
            foreach ($requested as $id => $quantity) {
                $item = BoqItem::where('organization_id', $org->id)->where('construction_project_id', $project->id)->findOrFail($id);
                $reserved = $this->reserved($org, $item);
                $previousQuantity = (int) (clone $reserved)->sum('quantity');
                $previousAmount = (int) $reserved->sum('amount_cents');
                $this->check($previousQuantity + $quantity <= $this->completed($org, $item), 'lines', 'Claimed quantity exceeds completed, unclaimed BOQ work.');
                $amount = $this->amounts->total($previousQuantity + $quantity, $item->unit_rate_cents) - $previousAmount;
                $this->check($amount > 0, 'lines', 'Increase the quantity to produce at least one cent after cumulative rounding.');
                $lines[] = ['organization_id' => $org->id, 'construction_boq_item_id' => $item->id, 'quantity' => $quantity, 'unit_rate_cents' => $item->unit_rate_cents, 'amount_cents' => $amount];
                $total += $amount;
            }
            $this->check($total <= 99999999999999, 'lines', 'The claim exceeds the supported financial amount.');
            $claim = ContractorClaim::create(['organization_id' => $org->id, 'construction_project_id' => $project->id, 'vendor_id' => (int) $values['vendor_id'], 'reference' => 'CLM-'.Str::upper(Str::random(12)), 'operation_key' => $values['operation_key'], 'claimed_on' => $values['claimed_on'], 'amount_cents' => $total, 'reason' => $reason, 'requested_by' => $actor->id]);
            foreach ($lines as $line) {
                ContractorClaimLine::create([...$line, 'contractor_claim_id' => $claim->id]);
            }
            $this->audit->handle($org, $actor, 'construction.claim.submitted', $claim, ['amount' => $this->money->format($total)]);

            return $claim;
        });
    }

    public function decide(Organization $org, User $actor, ContractorClaim $claim, bool $approve, ?string $reason = null): void
    {
        Gate::forUser($actor)->authorize('viewOperations', $org);
        abort_unless($actor->hasOrganizationRole($org, OrganizationRole::Owner), 403);
        if (! $approve) {
            $reason = $this->reason($reason ?? '');
        }
        DB::transaction(function () use ($org, $actor, $claim, $approve, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $claim = ContractorClaim::where('organization_id', $org->id)->findOrFail($claim->id);
            $this->check($claim->status === 'submitted' && $claim->requested_by !== $actor->id, 'claim', 'A different owner must decide a submitted claim.');
            $claim->update($approve ? ['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()] : ['status' => 'rejected', 'rejected_by' => $actor->id, 'rejection_reason' => $reason]);
            $this->audit->handle($org, $actor, $approve ? 'construction.claim.approved' : 'construction.claim.rejected', $claim, ['reason' => $reason]);
        });
    }

    /** @param array<string,mixed> $input */
    public function bill(Organization $org, User $actor, ContractorClaim $claim, array $input): VendorBill
    {
        Gate::forUser($actor)->authorize('manageFinance', $org);
        $values = Validator::make($input, ['bill_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bill_date'], 'accounting_treatment' => ['required', 'in:operating_expense,capital_asset'], 'vat_treatment' => [Rule::requiredIf($org->vat_enabled), 'nullable', 'in:standard,zero_rated,exempt,out_of_scope'], 'input_vat_recoverable' => [Rule::requiredIf($org->vat_enabled && ($input['vat_treatment'] ?? null) === 'standard'), 'nullable', 'boolean']])->validate();

        return DB::transaction(function () use ($org, $actor, $claim, $values): VendorBill {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $claim = ContractorClaim::where('organization_id', $org->id)->findOrFail($claim->id);
            if ($claim->vendor_bill_id !== null) {
                return VendorBill::where('organization_id', $org->id)->findOrFail($claim->vendor_bill_id);
            }
            $this->check($claim->status === 'approved' && $claim->approved_by !== null, 'claim', 'Owner approval is required before creating the finance bill.');
            $this->check($values['bill_date'] >= $claim->claimed_on->format('Y-m-d'), 'bill_date', 'The bill cannot predate the claim.');
            $project = ConstructionProject::where('organization_id', $org->id)->findOrFail($claim->construction_project_id);
            $standard = $org->vat_enabled && $values['vat_treatment'] === 'standard';
            $vat = $standard ? BigInteger::of($claim->amount_cents)->multipliedBy(5)->dividedBy(105, RoundingMode::HalfUp)->toInt() : 0;
            $bill = VendorBill::create(['organization_id' => $org->id, 'vendor_id' => $claim->vendor_id, 'property_id' => $project->property_id, 'reference' => 'BIL-'.Str::upper(Str::random(12)), 'description' => 'Contractor claim '.$claim->reference, 'bill_date' => $values['bill_date'], 'due_on' => $values['due_on'] ?? null, 'total' => $this->money->format($claim->amount_cents), 'currency' => 'AED', 'accounting_treatment' => $values['accounting_treatment'], 'vat_treatment' => $org->vat_enabled ? $values['vat_treatment'] : null, 'vat_rate' => $org->vat_enabled ? ($standard ? 5 : 0) : null, 'vat_amount' => $org->vat_enabled ? $this->money->format($vat) : null, 'input_vat_recoverable' => $standard ? (bool) $values['input_vat_recoverable'] : false]);
            $claim->update(['status' => 'billed', 'vendor_bill_id' => $bill->id]);
            $this->audit->handle($org, $actor, 'construction.claim.bill_created', $claim, ['vendor_bill_id' => $bill->id]);

            return $bill;
        });
    }

    public function status(Organization $org, User $actor, ConstructionProject $project, string $status, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $org);
        Validator::make(['status' => $status], ['status' => ['required', 'in:active,completed,cancelled']])->validate();
        $reason = $this->reason($reason);
        DB::transaction(function () use ($org, $actor, $project, $status, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $project = ConstructionProject::where('organization_id', $org->id)->findOrFail($project->id);
            $project->update(['status' => $status]);
            $this->audit->handle($org, $actor, 'construction.project.status_changed', $project, ['status' => $status, 'reason' => $reason]);
        });
    }

    public function completed(Organization $org, BoqItem $item): int
    {
        return (int) BoqProgress::where('organization_id', $org->id)->where('construction_boq_item_id', $item->id)->whereNull('voided_at')->sum('quantity');
    }

    /** @return Builder<ContractorClaimLine> */
    public function reserved(Organization $org, BoqItem $item): Builder
    {
        return ContractorClaimLine::where('organization_id', $org->id)->where('construction_boq_item_id', $item->id)->whereIn('contractor_claim_id', ContractorClaim::where('organization_id', $org->id)->whereIn('status', ['submitted', 'approved', 'billed'])->select('id'));
    }

    private function active(Organization $org, ConstructionProject $project): ConstructionProject
    {
        $project = ConstructionProject::where('organization_id', $org->id)->findOrFail($project->id);
        $this->check($project->status === 'active', 'project', 'Reopen the project before recording new work.');

        return $project;
    }

    private function reason(string $reason): string
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($reason);
        $this->check($reason !== '', 'reason', 'Record a reason.');

        return $reason;
    }

    private function check(bool $condition, string $field, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
