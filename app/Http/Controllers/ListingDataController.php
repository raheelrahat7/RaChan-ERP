<?php

namespace App\Http\Controllers;

use App\Domain\RealEstate\Actions\ManageListingDetails;
use App\Domain\RealEstate\Queries\ListingOverview;
use App\Models\Listing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListingDataController extends Controller
{
    public function index(Request $request, ListingOverview $overview, ManageListingDetails $details): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $filters = $this->filters($request);
        $canManage = $request->user()->can('manageCrm', $org);
        $listings = $overview->query($org, $filters)->with(['unit.property', 'unit.building'])->paginate(50)->withQueryString();
        $listings->through(fn (Listing $listing) => $overview->serialize($listing, $canManage));

        return response()->json(['listings' => $listings, 'workflowStatuses' => $details->statuses($org), 'emirateSummary' => $overview->emirateSummary($org, $filters), 'filters' => $filters, 'permissions' => ['read' => true, 'create' => $canManage, 'configure' => $canManage]]);
    }

    public function show(Request $request, Listing $listing, ListingOverview $overview, ManageListingDetails $details): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $listing->organization_id === $org->id, 404);
        $this->authorize('viewCrm', $org);

        return response()->json(['listing' => $overview->serialize($listing, $request->user()->can('manageCrm', $org)), 'workflowStatuses' => $details->statuses($org)]);
    }

    public function update(Request $request, Listing $listing, ManageListingDetails $details, ListingOverview $overview): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $listing->organization_id === $org->id, 404);
        $saved = $details->update($org, $request->user(), $listing, $request->all());

        return response()->json(['listing' => $overview->serialize($saved, true)]);
    }

    public function saveStatus(Request $request, ManageListingDetails $details, ?int $status = null): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $row = $details->saveStatus($org, $request->user(), $request->all(), $status);

        return response()->json(['workflowStatus' => $row], $status ? 200 : 201);
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate(['market_segment' => ['nullable', 'in:primary,secondary'], 'status' => ['nullable', 'in:draft,active,paused,closed'], 'workflow_status' => ['nullable', 'string', 'max:40'], 'listing_category' => ['nullable', 'string', 'max:80'], 'broker_id' => ['nullable', 'integer'], 'cost_centre_id' => ['nullable', 'integer'], 'emirate' => ['nullable', 'string', 'max:100'], 'community' => ['nullable', 'string', 'max:160'], 'q' => ['nullable', 'string', 'max:100'], 'sort' => ['nullable', 'in:latest,price_asc,price_desc'], 'page' => ['nullable', 'integer', 'min:1']]);
    }
}
