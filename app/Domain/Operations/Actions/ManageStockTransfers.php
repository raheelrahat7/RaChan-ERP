<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Operations\Models\SparePart;
use App\Domain\Operations\Models\StockBalance;
use App\Domain\Operations\Models\StockMovement;
use App\Domain\Operations\Models\StockStore;
use App\Domain\Operations\Services\StockQuantity;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageStockTransfers
{
    public function __construct(private StockQuantity $quantities, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function transfer(Organization $organization, User $actor, array $input): StockMovement
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        Validator::make($input, ['spare_part_id' => ['required', 'integer'], 'source_store_id' => ['required', 'integer'], 'destination_store_id' => ['required', 'integer', 'different:source_store_id'], 'quantity' => ['required', 'string'], 'reference' => ['required', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:2000'], 'operation_key' => ['required', 'uuid']])->validate();
        $quantity = $this->quantities->parse($input['quantity']);
        $reference = trim($input['reference']);
        $reason = trim((string) ($input['reason'] ?? ''));
        $this->require($reference !== '', 'A transfer reference is required.', 'reference');

        return DB::transaction(function () use ($organization, $actor, $input, $quantity, $reference, $reason): StockMovement {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            SparePart::where('organization_id', $organization->id)->lockForUpdate()->findOrFail((int) $input['spare_part_id']);
            $sourceId = (int) $input['source_store_id'];
            $destinationId = (int) $input['destination_store_id'];
            $this->stores($organization, $sourceId, $destinationId);
            $existing = StockMovement::where('organization_id', $organization->id)->where('operation_key', $input['operation_key'])->first();
            if ($existing !== null) {
                $inbound = StockMovement::where('organization_id', $organization->id)->where('related_movement_id', $existing->id)->where('type', 'transfer_in')->first();
                $this->require($existing->type === 'transfer_out' && $existing->spare_part_id === (int) $input['spare_part_id'] && $existing->stock_store_id === $sourceId && $existing->quantity === $quantity && $existing->reference === $reference && $existing->reason === ($reason === '' ? null : $reason) && $inbound?->stock_store_id === $destinationId, 'This operation key was already used for different stock details.', 'operation_key');

                return $existing;
            }
            [$source, $destination] = $this->balances($organization, (int) $input['spare_part_id'], $sourceId, $destinationId);
            $this->move($source, $destination, $quantity);
            $outbound = $this->entry($organization, $actor, $source, 'transfer_out', -$quantity, $reference, $reason, $input['operation_key']);
            $inbound = $this->entry($organization, $actor, $destination, 'transfer_in', $quantity, $reference, $reason, (string) Str::uuid(), $outbound->id);
            $this->audit->handle($organization, $actor, 'operations.stock.transferred', $outbound, ['outbound_id' => $outbound->id, 'inbound_id' => $inbound->id, 'source_store_id' => $sourceId, 'destination_store_id' => $destinationId, 'quantity' => $this->quantities->format($quantity), 'reference' => $reference]);

            return $outbound;
        });
    }

    /** @param array<string, mixed> $input */
    public function reverse(Organization $organization, User $actor, StockMovement $movement, array $input): StockMovement
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        Validator::make($input, ['reference' => ['required', 'string', 'max:255'], 'reason' => ['required', 'string', 'max:2000'], 'operation_key' => ['required', 'uuid']])->validate();
        $reference = trim($input['reference']);
        $reason = trim($input['reason']);
        $this->require($reference !== '' && $reason !== '', 'A correction reference and reason are required.', 'reason');

        return DB::transaction(function () use ($organization, $actor, $movement, $input, $reference, $reason): StockMovement {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $outbound = StockMovement::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($movement->id);
            $this->require($outbound->type === 'transfer_out', 'Select the outbound entry of the original transfer.', 'transfer_movement_id');
            $inbound = StockMovement::where('organization_id', $organization->id)->where('related_movement_id', $outbound->id)->where('type', 'transfer_in')->lockForUpdate()->firstOrFail();
            $existing = StockMovement::where('organization_id', $organization->id)->where('operation_key', $input['operation_key'])->first();
            if ($existing !== null) {
                $this->require($existing->type === 'transfer_void_out' && $existing->related_movement_id === $inbound->id && $existing->reference === $reference && $existing->reason === $reason, 'This operation key was already used for different stock details.', 'operation_key');

                return $existing;
            }
            $this->require($outbound->reversed_by_movement_id === null && $inbound->reversed_by_movement_id === null, 'This transfer has already been corrected.', 'transfer_movement_id');
            $this->stores($organization, $inbound->stock_store_id, $outbound->stock_store_id);
            [$destination, $source] = $this->balances($organization, $outbound->spare_part_id, $inbound->stock_store_id, $outbound->stock_store_id);
            $this->move($destination, $source, $outbound->quantity);
            $reverseOut = $this->entry($organization, $actor, $destination, 'transfer_void_out', -$outbound->quantity, $reference, $reason, $input['operation_key'], $inbound->id);
            $reverseIn = $this->entry($organization, $actor, $source, 'transfer_void_in', $outbound->quantity, $reference, $reason, (string) Str::uuid(), $outbound->id);
            $inbound->update(['reversed_by_movement_id' => $reverseOut->id]);
            $outbound->update(['reversed_by_movement_id' => $reverseIn->id]);
            $this->audit->handle($organization, $actor, 'operations.stock.transfer_corrected', $outbound, ['outbound_id' => $outbound->id, 'inbound_id' => $inbound->id, 'correction_out_id' => $reverseOut->id, 'correction_in_id' => $reverseIn->id, 'reference' => $reference, 'reason' => $reason]);

            return $reverseOut;
        });
    }

    private function stores(Organization $organization, int $first, int $second): void
    {
        $this->require($first !== $second, 'Select two different stores.', 'destination_store_id');
        foreach (array_values(array_unique([min($first, $second), max($first, $second)])) as $id) {
            StockStore::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($id);
        }
    }

    /** @return array{StockBalance, StockBalance} */
    private function balances(Organization $organization, int $partId, int $sourceId, int $destinationId): array
    {
        $source = StockBalance::firstOrCreate(['organization_id' => $organization->id, 'spare_part_id' => $partId, 'stock_store_id' => $sourceId], ['quantity' => 0]);
        $destination = StockBalance::firstOrCreate(['organization_id' => $organization->id, 'spare_part_id' => $partId, 'stock_store_id' => $destinationId], ['quantity' => 0]);

        return [$source, $destination];
    }

    private function move(StockBalance $source, StockBalance $destination, int $quantity): void
    {
        $this->require($source->quantity >= $quantity, 'Insufficient stock in the source store. Negative stock is not allowed.', 'quantity');
        $this->require($destination->quantity + $quantity <= 999999999999, 'The destination balance exceeds the supported limit.', 'quantity');
        $source->update(['quantity' => $source->quantity - $quantity]);
        $destination->update(['quantity' => $destination->quantity + $quantity]);
    }

    private function entry(Organization $organization, User $actor, StockBalance $balance, string $type, int $delta, string $reference, string $reason, string $key, ?int $related = null): StockMovement
    {
        return StockMovement::create(['organization_id' => $organization->id, 'spare_part_id' => $balance->spare_part_id, 'stock_store_id' => $balance->stock_store_id, 'recorded_by' => $actor->id, 'type' => $type, 'quantity' => abs($delta), 'delta' => $delta, 'reference' => $reference, 'reason' => $reason === '' ? null : $reason, 'operation_key' => $key, 'related_movement_id' => $related]);
    }

    private function require(bool $condition, string $message, string $field): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
