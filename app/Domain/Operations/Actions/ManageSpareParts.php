<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Operations\Models\SparePart;
use App\Domain\Operations\Models\StockBalance;
use App\Domain\Operations\Models\StockMovement;
use App\Domain\Operations\Models\StockStore;
use App\Domain\Operations\Services\StockQuantity;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageSpareParts
{
    public function __construct(private StockQuantity $quantities, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function catalogue(Organization $organization, User $actor, array $input, bool $store = false): Model
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        $values = ['code' => strtoupper(trim((string) $input['code'])), 'name' => trim((string) $input['name'])];
        $rules = ['code' => ['required', 'regex:/^[A-Z0-9_-]{1,50}$/'], 'name' => ['required', 'string', 'max:255']];
        if (! $store) {
            $values['unit'] = trim((string) ($input['unit'] ?? ''));
            $rules['unit'] = ['required', 'string', 'max:30'];
        }
        Validator::make($values, $rules)->validate();

        return DB::transaction(function () use ($organization, $actor, $store, $values): Model {
            // The organization lock serializes catalogue uniqueness checks.
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $query = $store ? StockStore::query() : SparePart::query();
            $this->check(! $query->where('organization_id', $organization->id)->where('code', $values['code'])->exists(), 'This code is already in use.', 'code');
            $record = $store ? StockStore::create([...$values, 'organization_id' => $organization->id]) : SparePart::create([...$values, 'organization_id' => $organization->id]);
            $this->audit->handle($organization, $actor, $store ? 'operations.stock.store_created' : 'operations.stock.part_created', $record);

            return $record;
        });
    }

    /** @param array<string, mixed> $input */
    public function movement(Organization $organization, User $actor, array $input): StockMovement
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        Validator::make($input, ['type' => ['required', 'in:receipt,issue,return,reversal'], 'operation_key' => ['required', 'uuid'], 'reference' => ['required', 'string', 'max:255'], 'reason' => ['nullable', 'string', 'max:2000']])->validate();
        $reference = trim((string) $input['reference']);
        $reason = trim((string) ($input['reason'] ?? ''));
        $this->check($reference !== '', 'A receipt or work reference is required.', 'reference');
        $type = (string) $input['type'];
        $related = isset($input['related_movement_id']) ? StockMovement::where('organization_id', $organization->id)->findOrFail((int) $input['related_movement_id']) : null;
        $this->check(in_array($type, ['return', 'reversal'], true) === ($related !== null), 'Returns and reversals must refer to an original movement.', 'related_movement_id');
        $jobId = $related !== null ? $related->maintenance_request_id : ($type === 'issue' && isset($input['maintenance_request_id']) ? (int) $input['maintenance_request_id'] : null);
        $this->check($type !== 'issue' || $jobId !== null, 'Select the job receiving these parts.', 'maintenance_request_id');
        $partId = $related !== null ? $related->spare_part_id : (int) ($input['spare_part_id'] ?? 0);
        $storeId = $related !== null ? $related->stock_store_id : (int) ($input['stock_store_id'] ?? 0);
        $quantity = $type === 'reversal' ? $related->quantity : $this->quantities->parse((string) ($input['quantity'] ?? ''));
        $this->check($type !== 'reversal' || $reason !== '', 'A reason is required for correction.', 'reason');

        return DB::transaction(function () use ($organization, $actor, $input, $reference, $reason, $type, $related, $jobId, $partId, $storeId, $quantity): StockMovement {
            $job = $jobId === null ? null : MaintenanceRequest::where('organization_id', $organization->id)->lockForUpdate()->findOrFail((int) $jobId);
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            SparePart::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($partId);
            StockStore::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($storeId);
            $payload = ['spare_part_id' => $partId, 'stock_store_id' => $storeId, 'maintenance_request_id' => $job?->id, 'type' => $type, 'quantity' => $quantity, 'reference' => $reference, 'reason' => $reason === '' ? null : $reason, 'related_movement_id' => $related?->id];
            $existing = StockMovement::where('organization_id', $organization->id)->where('operation_key', $input['operation_key'])->first();
            if ($existing !== null) {
                foreach ($payload as $field => $value) {
                    $this->check($existing->getAttribute($field) === $value, 'This operation key was already used for different stock movement details.', 'operation_key');
                }

                return $existing;
            }
            $this->check($job === null || (! in_array($job->status, ['completed', 'cancelled'], true) && $job->submitted_at === null), 'Reopen the submitted or closed job before changing its parts.', 'maintenance_request_id');
            $balance = StockBalance::firstOrCreate(['organization_id' => $organization->id, 'spare_part_id' => $partId, 'stock_store_id' => $storeId], ['quantity' => 0]);
            $delta = $type === 'issue' ? -$quantity : $quantity;
            if ($related !== null) {
                $original = StockMovement::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($related->id);
                $this->check($original->reversed_by_movement_id === null, 'The original movement has already been reversed.', 'related_movement_id');
                if ($type === 'return') {
                    $this->check($original->type === 'issue', 'Unused parts must be returned against their original issue.', 'related_movement_id');
                    $returned = StockMovement::where('organization_id', $organization->id)->where('related_movement_id', $original->id)->where('type', 'return')->whereNull('reversed_by_movement_id')->sum('quantity');
                    $this->check((int) $returned + $quantity <= $original->quantity, 'Return quantity exceeds the remaining issued quantity.', 'quantity');
                } else {
                    $this->check(in_array($original->type, ['receipt', 'issue', 'return'], true), 'Use the transfer correction action for transfers. A correction cannot itself be reversed.', 'related_movement_id');
                    if ($original->type === 'issue') {
                        $this->check(! StockMovement::where('organization_id', $organization->id)->where('related_movement_id', $original->id)->where('type', 'return')->whereNull('reversed_by_movement_id')->exists(), 'Reverse the unused-part returns before correcting their original issue.', 'related_movement_id');
                    }
                    $delta = -$original->delta;
                }
            }
            $this->check($balance->quantity + $delta >= 0, 'Insufficient stock in this store. Negative stock is not allowed.', 'quantity');
            $this->check($balance->quantity + $delta <= 999999999999, 'This movement exceeds the supported stock balance.', 'quantity');
            $record = StockMovement::create([...$payload, 'organization_id' => $organization->id, 'recorded_by' => $actor->id, 'operation_key' => $input['operation_key'], 'delta' => $delta]);
            $balance->update(['quantity' => $balance->quantity + $delta]);
            if ($type === 'reversal') {
                $related->update(['reversed_by_movement_id' => $record->id]);
            }
            $properties = ['movement_id' => $record->id, 'type' => $type, 'part_id' => $partId, 'store_id' => $storeId, 'quantity' => $this->quantities->format($quantity), 'reason' => $reason, 'reference' => $reference];
            $this->audit->handle($organization, $actor, 'operations.stock.'.$type.'_recorded', $record, $properties);
            if ($job !== null) {
                $this->audit->handle($organization, $actor, 'operations.job.parts_updated', $job, $properties);
            }

            return $record;
        });
    }

    private function check(bool $condition, string $message, string $field): void
    {
        if (! $condition) {
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
