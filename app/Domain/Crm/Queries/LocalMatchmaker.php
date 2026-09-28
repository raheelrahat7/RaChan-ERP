<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Services\LeadVisibility;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class LocalMatchmaker
{
    public function __construct(private LeadVisibility $visibility) {}

    /** @return list<array<string,mixed>> */
    public function forLead(Organization $org, User $actor, int $leadId): array
    {
        abort_unless($actor->can('viewCrm', $org), 403);
        $lead = CrmLead::where('organization_id', $org->id)->whereKey($leadId)->first();
        abort_unless($lead !== null && $this->visibility->canSeeLead($org, $actor, $lead->assigned_to), 404);
        $preference = DB::table('lead_search_preferences')->where('organization_id', $org->id)->where('lead_id', $leadId)->first();
        if (! $preference) {
            return [];
        }
        $listings = DB::table('listings as listing')->join('units as unit', 'unit.id', '=', 'listing.unit_id')
            ->join('properties as property', 'property.id', '=', 'unit.property_id')
            ->where('listing.organization_id', $org->id)->where('unit.organization_id', $org->id)->where('property.organization_id', $org->id)
            ->where('listing.status', 'active')->where('listing.currency', 'AED')->where('listing.purpose', $preference->purpose)
            ->where('unit.status', 'available')
            ->when($preference->city, fn ($query) => $query->where('property.city', $preference->city))
            ->when($preference->property_type, fn ($query) => $query->where('unit.type', $preference->property_type))
            ->when($preference->min_price_aed !== null, fn ($query) => $query->where('listing.price', '>=', $preference->min_price_aed))
            ->when($preference->max_price_aed !== null, fn ($query) => $query->where('listing.price', '<=', $preference->max_price_aed))
            ->orderBy('listing.price')->limit(25)
            ->get(['listing.id', 'listing.reference', 'listing.price', 'listing.purpose', 'property.name as property_name', 'property.city', 'unit.number as unit_number', 'unit.type as unit_type']);

        return array_values($listings->map(fn ($listing) => [
            'listing_id' => (int) $listing->id, 'reference' => $listing->reference,
            'property' => $listing->property_name, 'city' => $listing->city, 'unit' => $listing->unit_number,
            'purpose' => $listing->purpose, 'price_aed' => round((float) $listing->price, 2),
            'reasons' => array_values(array_filter(['Matching purpose', $preference->city ? 'Matching city' : null,
                $preference->property_type ? 'Matching property type' : null,
                $preference->max_price_aed !== null ? 'Within budget' : null])),
        ])->all());
    }
}
