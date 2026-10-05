<?php

namespace App\Domain\RealEstate\Actions;

use App\Domain\RealEstate\Services\ListingMarket;
use App\Models\Building;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateListingWithInventory
{
    public function __construct(private readonly ListingMarket $market) {}

    /** @param array<string, mixed> $input */
    public function handle(Organization $organization, array $input): Listing
    {
        return DB::transaction(function () use ($organization, $input): Listing {
            $unit = $input['inventory_mode'] === 'new_unit'
                ? $this->createUnit($organization, $input)
                : Unit::where('organization_id', $organization->id)->where('status', 'available')->findOrFail((int) $input['unit_id']);

            return Listing::create([
                'organization_id' => $organization->id,
                'unit_id' => $unit->id,
                'broker_id' => $input['broker_id'] ?? null,
                'reference' => 'LST-'.Str::upper(Str::random(8)),
                'public_token' => Str::random(48),
                'purpose' => $input['purpose'],
                'market_segment' => $this->market->forNewListing($input['purpose'], $input['market_segment'] ?? null),
                'price' => $input['price'],
            ]);
        });
    }

    /** @param array<string, mixed> $input */
    private function createUnit(Organization $organization, array $input): Unit
    {
        $property = ! empty($input['property_id'])
            ? Property::where('organization_id', $organization->id)->findOrFail((int) $input['property_id'])
            : $this->createProperty($organization, $input);

        $building = null;
        if (! empty($input['building_id'])) {
            $building = Building::where('organization_id', $organization->id)->where('property_id', $property->id)->findOrFail((int) $input['building_id']);
        } elseif (! empty($input['building_name'])) {
            if (Building::where('property_id', $property->id)->where('name', $input['building_name'])->exists()) {
                throw ValidationException::withMessages(['building_name' => 'This building already exists. Select it instead.']);
            }
            $building = Building::create(['organization_id' => $organization->id, 'property_id' => $property->id, 'name' => $input['building_name'], 'floors' => $input['building_floors'] ?? null]);
        }

        $floor = $input['floor'] ?? null;
        if ($floor !== null && ($building === null || (ctype_digit($floor) && $building->floors !== null && (int) $floor > $building->floors))) {
            throw ValidationException::withMessages(['floor' => 'Choose a floor within the selected building.']);
        }
        if (Unit::where('property_id', $property->id)->where('number', $input['unit_number'])->exists()) {
            throw ValidationException::withMessages(['unit_number' => 'This unit number already exists for the property. Select an existing unit or use a different number.']);
        }

        return Unit::create([
            'organization_id' => $organization->id,
            'property_id' => $property->id,
            'building_id' => $building?->id,
            'floor' => $floor,
            'number' => $input['unit_number'],
            'type' => $input['unit_type'],
            'status' => 'available',
        ]);
    }

    /** @param array<string, mixed> $input */
    private function createProperty(Organization $organization, array $input): Property
    {
        if (Property::where('organization_id', $organization->id)->where('name', $input['property_name'])->exists()) {
            throw ValidationException::withMessages(['property_name' => 'This property already exists. Select it from the list instead.']);
        }

        return Property::create([
            'organization_id' => $organization->id,
            'name' => $input['property_name'],
            'type' => $input['property_type'],
            'city' => $input['property_city'] ?? null,
        ]);
    }
}
