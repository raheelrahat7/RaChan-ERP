<?php

namespace App\Domain\Accounting\Actions;

use App\Domain\Accounting\Models\FixedAsset;
use App\Domain\Accounting\Models\FixedAssetTransfer;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransferFixedAsset
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function handle(Organization $organization, User $actor, FixedAsset $asset, array $input): FixedAssetTransfer
    {
        return DB::transaction(function () use ($organization, $actor, $asset, $input): FixedAssetTransfer {
            $locked = FixedAsset::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($asset->id);
            abort_if($locked->disposed_on !== null, 422, 'A disposed asset cannot be transferred.');
            abort_if($input['transferred_on'] < $locked->available_for_use_on->toDateString(), 422, 'Transfer cannot precede the available-for-use date.');

            $to = ['property_id' => $input['property_id'] ?? null, 'location' => $this->clean($input['location'] ?? null), 'custodian' => $this->clean($input['custodian'] ?? null), 'asset_class' => trim($input['asset_class'])];
            $from = ['property_id' => $locked->property_id, 'location' => $locked->location, 'custodian' => $locked->custodian, 'asset_class' => $locked->asset_class];
            abort_if($from === $to, 422, 'Change at least one asset assignment field.');

            $transfer = FixedAssetTransfer::create([
                'organization_id' => $organization->id, 'fixed_asset_id' => $locked->id, 'transferred_on' => $input['transferred_on'],
                'from_property_id' => $from['property_id'], 'to_property_id' => $to['property_id'],
                'from_location' => $from['location'], 'to_location' => $to['location'], 'from_custodian' => $from['custodian'], 'to_custodian' => $to['custodian'],
                'from_asset_class' => $from['asset_class'], 'to_asset_class' => $to['asset_class'], 'reason' => trim($input['reason']), 'approved_by' => $actor->id,
            ]);
            $locked->update($to);
            $this->audit->handle($organization, $actor, 'accounting.fixed_asset.transferred', $transfer, ['from' => $from, 'to' => $to, 'transferred_on' => $input['transferred_on'], 'reason' => trim($input['reason']), 'automatic_journal' => false]);

            return $transfer;
        });
    }

    private function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
