<?php

namespace App\Domain\RealEstate\Queries;

use App\Domain\Finance\Services\InvoiceBalance;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

class BuildingSkyline
{
    public function __construct(private InvoiceBalance $balances) {}

    /** @return list<array<string, mixed>> */
    public function forProperty(Organization $org, Property $property, bool $canViewFinance = false): array
    {
        abort_unless($property->organization_id === $org->id, 404);
        $buildings = DB::table('buildings')->where('organization_id', $org->id)->where('property_id', $property->id)
            ->orderBy('name')->get(['id', 'name', 'floors']);
        if ($buildings->isEmpty()) {
            return [];
        }

        $buildingIds = $buildings->pluck('id')->all();
        $units = DB::table('units')->where('organization_id', $org->id)->where('property_id', $property->id)
            ->whereIn('building_id', $buildingIds)->orderBy('number')->get(['id', 'building_id', 'number', 'floor', 'status']);
        $unitIds = array_values(array_map(intval(...), $units->pluck('id')->all()));
        $today = now($org->timezone ?: 'UTC')->toDateString();
        $now = now()->toDateTimeString();

        $sales = $this->unitSet('sales_contracts', $org->id, $unitIds, fn ($query) => $query->where('status', 'active'));
        $leases = $this->unitSet('leases', $org->id, $unitIds, fn ($query) => $query->where('status', 'active')
            ->where('starts_on', '<=', $today)->where('ends_on', '>=', $today));
        $reservations = $this->unitSet('reservations', $org->id, $unitIds, fn ($query) => $query->where('status', 'active')->where('expires_at', '>=', $now));
        $maintenance = $this->unitSet('maintenance_requests', $org->id, $unitIds, fn ($query) => $query->whereIn('status', ['open', 'in_progress', 'on_hold']));
        $overdue = $canViewFinance ? $this->overdueRentUnits($org->id, $unitIds, $today) : [];

        $result = [];
        foreach ($buildings as $building) {
            $floors = [];
            $buildingUnits = $units->where('building_id', $building->id);
            $configuredFloors = (int) ($building->floors ?? 0);
            $maxAssignedFloor = $buildingUnits->max(fn ($unit) => ctype_digit((string) $unit->floor) ? (int) $unit->floor : 0);
            $floorCount = max($configuredFloors, (int) $maxAssignedFloor);
            for ($number = 1; $number <= $floorCount; $number++) {
                $floors[(string) $number] = [];
            }
            foreach ($buildingUnits as $unit) {
                $label = $unit->floor ?: 'Unassigned';
                $status = match (true) {
                    isset($sales[$unit->id]) || $unit->status === 'sold' => 'sold',
                    isset($overdue[$unit->id]) => 'rent_overdue',
                    isset($maintenance[$unit->id]) || $unit->status === 'unavailable' => 'maintenance',
                    isset($leases[$unit->id]) || $unit->status === 'leased' => 'let',
                    isset($reservations[$unit->id]) || $unit->status === 'reserved' => 'reserved',
                    default => 'vacant',
                };
                $floors[$label][] = ['id' => (int) $unit->id, 'number' => (string) $unit->number,
                    'status' => $status, 'href' => route('inventory.units.show', $unit->id)];
            }
            uksort($floors, fn (string $a, string $b): int => $this->floorRank($b) <=> $this->floorRank($a));
            $result[] = ['building' => ['id' => (int) $building->id, 'name' => (string) $building->name,
                'property' => (string) $property->name, 'floors' => $floorCount],
                'floors' => array_map(fn (string $label, array $items): array => ['label' => $label, 'units' => $items], array_keys($floors), $floors)];
        }

        return $result;
    }

    /** @param list<int> $unitIds
     * @return array<int, bool>
     */
    private function unitSet(string $table, int $orgId, array $unitIds, callable $scope): array
    {
        if ($unitIds === []) {
            return [];
        }
        $query = DB::table($table)->where('organization_id', $orgId)->whereIn('unit_id', $unitIds);

        return array_fill_keys($scope($query)->distinct()->pluck('unit_id')->all(), true);
    }

    /** @param list<int> $unitIds
     * @return array<int, bool>
     */
    private function overdueRentUnits(int $orgId, array $unitIds, string $today): array
    {
        if ($unitIds === []) {
            return [];
        }
        $links = DB::table('lease_rent_invoices as link')->join('leases as lease', 'lease.id', '=', 'link.lease_id')
            ->join('invoices as invoice', 'invoice.id', '=', 'link.invoice_id')
            ->where('link.organization_id', $orgId)->where('lease.organization_id', $orgId)->where('invoice.organization_id', $orgId)
            ->whereIn('lease.unit_id', $unitIds)->where('lease.status', 'active')
            ->where('lease.starts_on', '<=', $today)->where('lease.ends_on', '>=', $today)
            ->whereNot('invoice.status', 'draft')->where('invoice.due_on', '<', $today)
            ->get(['lease.unit_id', 'invoice.id as invoice_id']);
        if ($links->isEmpty()) {
            return [];
        }
        $invoices = Invoice::whereIn('id', $links->pluck('invoice_id')->all())->withSum('payments', 'amount')->get()->keyBy('id');
        $overdue = [];
        foreach ($links as $link) {
            $invoice = $invoices->get($link->invoice_id);
            if ($invoice !== null && $this->balances->outstandingCents($invoice) > 0) {
                $overdue[(int) $link->unit_id] = true;
            }
        }

        return $overdue;
    }

    private function floorRank(string $label): int
    {
        if ($label === 'Unassigned') {
            return -1000;
        }
        if ($label === 'G') {
            return 0;
        }
        if (str_starts_with($label, 'P')) {
            return -(int) substr($label, 1);
        }

        return (int) $label;
    }
}
