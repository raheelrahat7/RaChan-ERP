<?php

namespace App\Domain\Operations\Data;

final readonly class OverviewFilters
{
    public function __construct(public ?int $propertyId = null, public ?int $vendorId = null) {}

    /** @return array{property_id: int|null, vendor_id: int|null} */
    public function toArray(): array
    {
        return ['property_id' => $this->propertyId, 'vendor_id' => $this->vendorId];
    }
}
