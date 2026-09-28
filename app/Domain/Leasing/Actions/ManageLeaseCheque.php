<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Leasing\Models\LeaseCheque;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManageLeaseCheque
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function create(Organization $org, User $actor, Lease $lease, array $input, ?LeaseCheque $replacement = null): LeaseCheque
    {
        return DB::transaction(function () use ($org, $actor, $lease, $input, $replacement) {
            $locked = Lease::where('organization_id', $org->id)->lockForUpdate()->findOrFail($lease->id);
            if ($replacement) {
                $replacement = LeaseCheque::where('organization_id', $org->id)->where('lease_id', $locked->id)->lockForUpdate()->findOrFail($replacement->id);
                abort_unless($replacement->status === 'bounced', 422, 'Only a bounced cheque can be replaced.');
            } $cheque = LeaseCheque::create(['organization_id' => $org->id, 'lease_id' => $locked->id, 'replacement_of_id' => $replacement?->id, ...$input, 'status' => 'scheduled', 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            if ($replacement) {
                $replacement->update(['status' => 'replaced', 'updated_by' => $actor->id]);
            } $this->audit->handle($org, $actor, $replacement ? 'leasing.cheque.replaced' : 'leasing.cheque.created', $cheque, ['replacement_of_id' => $replacement?->id]);

            return $cheque;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function transition(Organization $org, User $actor, LeaseCheque $cheque, string $action, array $input): void
    {
        DB::transaction(function () use ($org, $actor, $cheque, $action, $input): void {
            $locked = LeaseCheque::where('organization_id', $org->id)->lockForUpdate()->findOrFail($cheque->id);
            $allowed = ['deposit' => ['scheduled', 'deposited', 'deposited_on'], 'clear' => ['deposited', 'cleared', 'cleared_on'], 'bounce' => ['deposited', 'bounced', 'bounced_on']];
            [$from,$to,$dateField] = $allowed[$action] ?? abort(422);
            abort_unless($locked->status === $from, 422, "Only a {$from} cheque can be {$to}.");
            $changes = ['status' => $to, $dateField => $input['occurred_on'], 'updated_by' => $actor->id];
            if ($action === 'bounce') {
                $changes['bounce_reason'] = $input['reason'];
            } $locked->update($changes);
            $this->audit->handle($org, $actor, "leasing.cheque.{$to}", $locked, $input);
        });
    }
}
