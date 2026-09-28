<?php

namespace App\Domain\RealEstate\Queries;

use App\Models\Organization;
use App\Models\Unit;

class InventoryExport
{
    /** @return array<int, list<mixed>> */
    public function rows(Organization $organization): array
    {
        return Unit::where('organization_id', $organization->id)->orderBy('id')->get(['number', 'type', 'status', 'asking_price', 'currency'])
            ->map(fn (Unit $item): array => [$item->number, $item->type, $item->status, $item->asking_price, $item->currency])->values()->all();
    }
}
