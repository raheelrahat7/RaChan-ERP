<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Support\Facades\DB;

class RegisterFixedAsset
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Organization $organization, User $actor, array $input): FixedAsset
    {
        return DB::transaction(function () use ($organization, $actor, $input): FixedAsset {
            $costCents = $this->cents($input['cost']);
            $registeredCents = FixedAsset::where('organization_id', $organization->id)
                ->when($input['source_type'] === 'vendor_bill', fn ($query) => $query->where('vendor_bill_id', $input['vendor_bill_id']))
                ->when($input['source_type'] === 'opening_balance', fn ($query) => $query->where('opening_source_reference', $input['opening_source_reference']))
                ->lockForUpdate()->get()->sum(fn (FixedAsset $asset) => $this->cents($asset->cost));

            if ($input['source_type'] === 'vendor_bill') {
                $source = VendorBill::where('organization_id', $organization->id)->whereKey($input['vendor_bill_id'])->lockForUpdate()->firstOrFail();
                abort_unless($source->status !== 'draft' && $source->accounting_treatment === 'capital_asset' && $source->currency === 'AED', 422, 'Select a posted AED capital-asset vendor bill.');
                $sourceCents = $this->cents($source->total);
            } else {
                $entry = JournalEntry::where('organization_id', $organization->id)->where('event', 'opening.balance')->where('source_reference', $input['opening_source_reference'])->where('currency', 'AED')->with('lines.account')->lockForUpdate()->firstOrFail();
                $sourceCents = $entry->lines->filter(fn ($line) => $line->account?->code === '1500')->sum(fn ($line) => $this->cents($line->debit));
                abort_if($sourceCents <= 0, 422, 'The opening balance must debit Capital Assets (1500).');
            }
            abort_if($registeredCents + $costCents > $sourceCents, 422, 'Registered asset costs exceed the selected source amount.');

            $asset = FixedAsset::create([
                'organization_id' => $organization->id,
                'vendor_bill_id' => $input['source_type'] === 'vendor_bill' ? $input['vendor_bill_id'] : null,
                'opening_source_reference' => $input['source_type'] === 'opening_balance' ? trim($input['opening_source_reference']) : null,
                'reference' => trim($input['reference']), 'name' => trim($input['name']), 'asset_class' => trim($input['asset_class']),
                'classification' => 'ias16_cost_model', 'cost' => $input['cost'], 'residual_value' => $input['residual_value'],
                'useful_life_months' => $input['useful_life_months'], 'available_for_use_on' => $input['available_for_use_on'], 'created_by' => $actor->id,
            ]);
            $this->audit->handle($organization, $actor, 'accounting.fixed_asset.registered', $asset, ['source_type' => $input['source_type']]);

            return $asset;
        });
    }

    public function dispose(Organization $organization, User $actor, FixedAsset $asset, string $disposedOn): void
    {
        abort_if($asset->disposed_on !== null, 422, 'This asset already has a disposal date.');
        abort_if($disposedOn < $asset->available_for_use_on->toDateString(), 422, 'Disposal cannot precede the available-for-use date.');
        $asset->update(['disposed_on' => $disposedOn]);
        $this->audit->handle($organization, $actor, 'accounting.fixed_asset.disposal_recorded', $asset, ['disposed_on' => $disposedOn, 'automatic_journal' => false]);
    }

    private function cents(string|int|float $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
