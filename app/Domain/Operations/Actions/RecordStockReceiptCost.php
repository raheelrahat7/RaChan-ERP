<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Operations\Models\StockMovement;
use App\Domain\Operations\Models\StockReceiptCost;
use App\Domain\Operations\Services\JobCostAmount;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class RecordStockReceiptCost
{
    public function __construct(private JobCostAmount $amounts, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function handle(Organization $organization, User $actor, StockMovement $movement, array $input): StockReceiptCost
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        abort_unless($actor->hasOrganizationPermission($organization, OrganizationPermission::ManageInventoryCosts), 403);
        Validator::make($input, ['amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/'], 'currency' => ['required', 'regex:/^[A-Z]{3}$/'], 'reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($input['reason']);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Record the source or correction reason.']);
        }

        return DB::transaction(function () use ($organization, $actor, $movement, $input, $reason): StockReceiptCost {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $receipt = StockMovement::where('organization_id', $organization->id)->findOrFail($movement->id);
            if ($receipt->type !== 'receipt') {
                throw ValidationException::withMessages(['stock_movement_id' => 'Only physical receipts can have a receipt cost.']);
            }
            $otherCurrency = StockReceiptCost::where('organization_id', $organization->id)->whereIn('stock_movement_id', StockMovement::where('organization_id', $organization->id)->where('spare_part_id', $receipt->spare_part_id)->select('id'))->where('currency', '!=', $input['currency'])->exists();
            if ($otherCurrency) {
                throw ValidationException::withMessages(['currency' => 'Use the existing valuation currency for this part. Currency conversion is not supported.']);
            }
            $previous = StockReceiptCost::where('organization_id', $organization->id)->where('stock_movement_id', $receipt->id)->first();
            $cents = $this->amounts->hundredths($input['amount']);
            if ($previous !== null && $previous->amount_cents === $cents && $previous->currency === $input['currency'] && $previous->reason === $reason) {
                return $previous;
            }
            $before = $previous?->only(['amount_cents', 'currency', 'reason']);
            $record = StockReceiptCost::updateOrCreate(['organization_id' => $organization->id, 'stock_movement_id' => $receipt->id], ['amount_cents' => $cents, 'currency' => $input['currency'], 'recorded_by' => $actor->id, 'reason' => $reason]);
            $this->audit->handle($organization, $actor, 'operations.stock.receipt_cost_recorded', $receipt, ['previous' => $before, 'amount_cents' => $cents, 'currency' => $input['currency'], 'reason' => $reason]);

            return $record;
        });
    }
}
