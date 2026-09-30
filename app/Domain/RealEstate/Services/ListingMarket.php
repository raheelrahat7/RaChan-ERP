<?php

namespace App\Domain\RealEstate\Services;

use App\Models\Listing;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

class ListingMarket
{
    /** @return Builder<Listing> */
    public function forSegment(Organization $organization, ?string $segment): Builder
    {
        return Listing::where('organization_id', $organization->id)
            ->when($segment === 'secondary', fn ($query) => $query->where(fn ($scope) => $scope->where('market_segment', 'secondary')->orWhere('purpose', 'rent')))
            ->when($segment === 'primary', fn ($query) => $query->where('purpose', 'sale')->where('market_segment', 'primary'));
    }

    public function forNewListing(string $purpose, ?string $segment): ?string
    {
        if ($purpose !== 'rent') {
            return $segment;
        }
        if ($segment !== null && $segment !== 'secondary') {
            throw ValidationException::withMessages(['market_segment' => 'Rental listings belong to the secondary market.']);
        }

        return 'secondary';
    }
}
