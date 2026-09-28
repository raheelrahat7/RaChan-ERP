<?php

namespace App\Domain\RealEstate\Actions;

use App\Domain\Crm\Actions\ManageLeadPipeline;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\User;

class RecordListingInquiry
{
    public function __construct(private ManageLeadPipeline $leads) {}

    /** @param array<string, string|null> $details */
    public function handle(Organization $organization, User $actor, int $listingId, array $details): CrmLead
    {
        $listing = Listing::where('organization_id', $organization->id)->findOrFail($listingId);

        return $this->leads->create($organization, $actor, [
            ...$details,
            'listing_id' => $listing->id,
            'source' => 'Listing inquiry',
        ]);
    }

    /** @param array<string, string|null> $details */
    public function handlePublic(Listing $listing, array $details): CrmLead
    {
        abort_unless($listing->status === 'active', 404);

        return $this->leads->createPublicInquiry($listing->organization, [
            ...$details,
            'listing_id' => $listing->id,
            'source' => 'Public listing inquiry',
        ]);
    }
}
