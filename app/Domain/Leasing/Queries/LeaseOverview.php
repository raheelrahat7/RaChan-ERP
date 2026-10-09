<?php

namespace App\Domain\Leasing\Queries;

use App\Models\Lease;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

class LeaseOverview
{
    /** @param array<string, mixed> $filters
     * @return Builder<Lease>
     */
    public function query(Organization $org, array $filters): Builder
    {
        $cutoff = today()->addDays(60)->toDateString();

        return Lease::where('organization_id', $org->id)
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($inner) => $inner->where('reference', 'like', '%'.$q.'%')->orWhere('tenancy_number', 'like', '%'.$q.'%')))
            ->when($filters['tab'] ?? null, function ($query, $tab) use ($cutoff): void {
                match ($tab) {
                    'draft' => $query->where('status', 'draft'),
                    'active' => $query->where('status', 'active')->whereNull('last_renewed_on')->whereRaw('COALESCE(renewal_due_on, ends_on) > ?', [$cutoff]),
                    'renewal_due' => $query->where('status', 'active')->whereRaw('COALESCE(renewal_due_on, ends_on) <= ?', [$cutoff]),
                    'renewed' => $query->where('status', 'active')->whereNotNull('last_renewed_on')->whereRaw('COALESCE(renewal_due_on, ends_on) > ?', [$cutoff]),
                    'moved_out' => $query->where('status', 'completed')->whereHas('handovers', fn ($handovers) => $handovers->where('type', 'move_out')->where('status', 'completed')),
                    default => $query,
                };
            })->orderByDesc('id');
    }

    /** @return array<string, mixed> */
    public function serialize(Lease $lease, bool $canManage): array
    {
        $lease->loadMissing(['tenant', 'unit', 'securityDeposit', 'cheques', 'ejariRegistrations', 'handovers']);
        $currentEjari = $lease->ejariRegistrations->where('status', 'registered')->sortByDesc('registered_on')->first();
        $moveOut = $lease->handovers->where('type', 'move_out')->sortByDesc('id')->first();
        $values = $lease->only('id', 'reference', 'version', 'status', 'unit_id', 'tenant_id', 'broker_id', 'contact_id', 'reservation_id', 'starts_on', 'ends_on', 'rent_amount', 'currency', 'tenancy_number', 'renewal_due_on', 'last_renewed_on', 'advance_amount');
        $values['tenant'] = $lease->tenant?->only('id', 'name');
        $values['unit'] = $lease->unit?->only('id', 'number');
        $values['security_deposit'] = $lease->securityDeposit?->only('id', 'invoice_id', 'required_amount', 'due_on', 'notes');
        $values['ejari'] = $currentEjari?->only('id', 'status', 'ejari_number', 'registered_on', 'expires_on');
        $values['cheques'] = $lease->cheques->map(fn ($cheque) => $cheque->only('id', 'cheque_number', 'amount', 'due_on', 'status', 'replacement_of_id'))->values()->all();
        $values['move_out'] = $moveOut?->only('id', 'status', 'scheduled_on', 'completed_at');
        $values['renewal_due'] = $lease->status === 'active' && ($lease->renewal_due_on ?? $lease->ends_on)->toDateString() <= today()->addDays(60)->toDateString();
        $values['permissions'] = ['read' => true, 'edit' => $canManage, 'renew' => $canManage && $lease->status === 'active', 'schedule_move_out' => $canManage && $lease->status === 'active' && $moveOut === null, 'manage_deposit' => $canManage && $lease->securityDeposit === null, 'manage_cheques' => $canManage];

        return $values;
    }

    /** @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function tabCounts(Organization $org, array $filters): array
    {
        $counts = [];
        unset($filters['tab']);
        foreach (['draft', 'active', 'renewal_due', 'renewed', 'moved_out'] as $tab) {
            $counts[$tab] = $this->query($org, [...$filters, 'tab' => $tab])->count();
        }

        return $counts;
    }
}
