<?php

namespace App\Domain\RealEstate\Services;

use App\Domain\Crm\Services\LeadVisibility;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\Reservation;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class SecondaryDealLink
{
    public function __construct(private readonly LeadVisibility $visibility) {}

    /** @return array{listing_id: int|null, lead_id: int|null} */
    public function validateReservation(Organization $organization, User $actor, Unit $unit, ?int $listingId, ?int $leadId): array
    {
        if ($leadId !== null && $listingId === null) {
            throw ValidationException::withMessages(['lead_id' => 'Select the lead’s listing first.']);
        }

        if ($listingId === null) {
            return ['listing_id' => null, 'lead_id' => null];
        }

        $listing = Listing::where('organization_id', $organization->id)->find($listingId);
        if (! $listing || $listing->unit_id !== $unit->id || $listing->status !== 'active' || ! ($listing->purpose === 'rent' || $listing->market_segment === 'secondary')) {
            throw ValidationException::withMessages(['listing_id' => 'Select an active secondary-market listing for this unit.']);
        }

        if ($leadId !== null) {
            $lead = CrmLead::where('organization_id', $organization->id)->find($leadId);
            if (! $lead || $lead->listing_id !== $listing->id || ! $actor->can('viewCrm', $organization) || ! $this->visibility->canSeeLead($organization, $actor, $lead->assigned_to)) {
                throw ValidationException::withMessages(['lead_id' => 'Select a visible lead for this listing.']);
            }
        }

        return ['listing_id' => $listing->id, 'lead_id' => $leadId];
    }

    public function assertAgreementPurpose(Reservation $reservation, string $purpose): void
    {
        if ($reservation->listing_id !== null && $reservation->listing?->purpose !== $purpose) {
            throw ValidationException::withMessages(['reservation_id' => 'The listing purpose does not match this agreement.']);
        }
    }
}
