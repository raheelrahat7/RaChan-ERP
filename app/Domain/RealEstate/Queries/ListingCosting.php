<?php

namespace App\Domain\RealEstate\Queries;

use App\Models\Organization;
use Illuminate\Support\Facades\DB;

class ListingCosting
{
    /** @return list<array<string, mixed>> */
    public function for(Organization $org): array
    {
        $publications = DB::table('portal_publications')->where('organization_id', $org->id)->get(['id', 'listing_id', 'portal']);
        $spend = DB::table('listing_spend')->where('organization_id', $org->id)
            ->selectRaw('channel, source, SUM(amount_aed) AS amount')->groupBy('channel', 'source')->get();
        $leads = DB::table('portal_enquiries as enquiry')->join('portal_publications as publication', 'publication.id', '=', 'enquiry.publication_id')
            ->where('enquiry.organization_id', $org->id)->where('publication.organization_id', $org->id)
            ->selectRaw('publication.portal, COUNT(DISTINCT enquiry.lead_id) AS total')->groupBy('publication.portal')->get()->keyBy('portal');
        $portals = ['bayut', 'property_finder', 'dubizzle'];

        return array_map(function (string $portal) use ($publications, $spend, $leads): array {
            $listingCount = $publications->where('portal', $portal)->unique('listing_id')->count();
            $leadCount = (int) ($leads[$portal]->total ?? 0);
            $actual = round((float) $spend->where('channel', $portal)->where('source', 'bill_linked')->sum('amount'), 2);
            $estimated = round((float) $spend->where('channel', $portal)->where('source', 'estimate')->sum('amount'), 2);

            return [
                'portal' => $portal, 'published_listings' => 0, 'local_validated_listings' => $listingCount,
                'total_leads' => $leadCount, 'actual_spend_aed' => $actual, 'estimated_spend_aed' => $estimated,
                'cost_per_listing' => $listingCount > 0 ? round($actual / $listingCount, 2) : null,
                'cost_per_lead' => $leadCount > 0 ? round($actual / $leadCount, 2) : null,
                'cost_per_deal' => null, 'roi' => null,
            ];
        }, $portals);
    }
}
