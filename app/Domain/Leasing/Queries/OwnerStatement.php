<?php

namespace App\Domain\Leasing\Queries;

use App\Domain\Finance\Queries\CreditNoteActivity;
use App\Models\Organization;
use App\Models\Owner;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OwnerStatement
{
    /**
     * @return array{owner: array{id: int, name: string, reference: string|null}, rows: list<array<string, int|float|string|null>>, totals: array{income: string, expenses: string, net: string}}
     */
    public function for(Organization $organization, Owner $owner, Carbon $from, Carbon $to): array
    {
        abort_unless($owner->organization_id === $organization->id, 404);
        $shares = DB::table('property_owner')->join('properties', 'properties.id', '=', 'property_owner.property_id')
            ->where('property_owner.owner_id', $owner->id)->where('properties.organization_id', $organization->id)
            ->pluck('property_owner.ownership_share', 'property_owner.property_id');

        $rows = [];
        if ($shares->isNotEmpty()) {
            $income = DB::table('lease_service_charges')
                ->join('invoices', 'invoices.id', '=', 'lease_service_charges.invoice_id')
                ->join('leases', 'leases.id', '=', 'lease_service_charges.lease_id')
                ->join('units', 'units.id', '=', 'leases.unit_id')
                ->join('properties', 'properties.id', '=', 'units.property_id')
                ->where('lease_service_charges.organization_id', $organization->id)
                ->whereIn('units.property_id', $shares->keys())
                ->whereIn('invoices.status', ['posted', 'partial', 'paid'])
                ->whereBetween('invoices.issued_on', [$from->toDateString(), $to->toDateString()])
                ->get(['invoices.issued_on as activity_date', 'invoices.reference', 'lease_service_charges.category as description', 'lease_service_charges.net_amount as amount', 'units.property_id', 'properties.name as property_name']);

            foreach ($income as $item) {
                $values = (array) $item;
                $rows[] = $this->row($values, 'income', (float) $shares[$values['property_id']]);
            }

            $credits = app(CreditNoteActivity::class)->for($organization, $from->toDateString(), $to->toDateString());
            $creditProperties = DB::table('lease_service_charges')
                ->join('leases', 'leases.id', '=', 'lease_service_charges.lease_id')
                ->join('units', 'units.id', '=', 'leases.unit_id')
                ->join('properties', 'properties.id', '=', 'units.property_id')
                ->where('lease_service_charges.organization_id', $organization->id)
                ->whereIn('lease_service_charges.invoice_id', array_column($credits, 'invoice_id'))
                ->whereIn('units.property_id', $shares->keys())
                ->get(['lease_service_charges.invoice_id', 'units.property_id', 'properties.name as property_name'])->keyBy('invoice_id');
            foreach ($credits as $credit) {
                $property = $creditProperties->get($credit['invoice_id']);
                if ($property) {
                    $rows[] = $this->row(['activity_date' => $credit['date'], 'reference' => $credit['reference'], 'description' => 'Credit note activity', 'amount' => $credit['net'], 'property_id' => $property->property_id, 'property_name' => $property->property_name], 'income', (float) $shares[$property->property_id]);
                }
            }

            $expenses = DB::table('vendor_bills')->join('properties', 'properties.id', '=', 'vendor_bills.property_id')
                ->where('vendor_bills.organization_id', $organization->id)
                ->whereIn('vendor_bills.property_id', $shares->keys())
                ->where('vendor_bills.accounting_treatment', 'operating_expense')
                ->whereIn('vendor_bills.status', ['posted', 'partial', 'paid'])
                ->whereBetween('vendor_bills.bill_date', [$from->toDateString(), $to->toDateString()])
                ->get(['vendor_bills.bill_date as activity_date', 'vendor_bills.reference', 'vendor_bills.description', 'vendor_bills.total', 'vendor_bills.vat_amount', 'vendor_bills.input_vat_recoverable', 'vendor_bills.property_id', 'properties.name as property_name']);

            foreach ($expenses as $item) {
                $values = (array) $item;
                $values['amount'] = (float) $values['total'] - ($values['input_vat_recoverable'] ? (float) $values['vat_amount'] : 0);
                $rows[] = $this->row($values, 'expense', (float) $shares[$values['property_id']]);
            }
        }

        usort($rows, fn (array $left, array $right): int => [$left['activity_date'], $left['reference']] <=> [$right['activity_date'], $right['reference']]);
        $incomeTotal = array_sum(array_column(array_filter($rows, fn (array $row): bool => $row['type'] === 'income'), 'owner_amount'));
        $expenseTotal = array_sum(array_column(array_filter($rows, fn (array $row): bool => $row['type'] === 'expense'), 'owner_amount'));

        return [
            'owner' => ['id' => $owner->id, 'name' => $owner->name, 'reference' => $owner->reference],
            'rows' => $rows,
            'totals' => ['income' => $this->money($incomeTotal), 'expenses' => $this->money($expenseTotal), 'net' => $this->money($incomeTotal - $expenseTotal)],
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, int|float|string|null>
     */
    private function row(array $item, string $type, float $share): array
    {
        $amount = (float) $item['amount'];

        return [
            'activity_date' => (string) $item['activity_date'],
            'type' => $type,
            'reference' => (string) $item['reference'],
            'property_id' => (int) $item['property_id'],
            'property_name' => (string) $item['property_name'],
            'description' => str_replace('_', ' ', (string) $item['description']),
            'property_amount' => $this->money($amount),
            'ownership_share' => $this->money($share),
            'owner_amount' => (float) $this->money($amount * $share / 100),
        ];
    }

    private function money(float $amount): string
    {
        return number_format(round($amount, 2), 2, '.', '');
    }
}
