<?php

namespace App\Http\Controllers;

use App\Domain\RealEstate\Actions\ManagePortalMarketing;
use App\Domain\RealEstate\Queries\ListingCosting;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PortalMarketingController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $this->org($request);
        $this->authorize('viewCrm', $org);

        return Inertia::render('marketing/Portal', [
            'campaigns' => DB::table('marketing_campaigns')->where('organization_id', $org->id)->orderByDesc('id')->paginate(20),
            'publications' => DB::table('portal_publications')->where('organization_id', $org->id)->orderByDesc('id')->limit(100)->get(),
            'canManage' => $request->user()->can('manageCrm', $org),
            'providerSelected' => false,
        ]);
    }

    public function subscriptions(Request $request): Response
    {
        $org = $this->org($request);
        $this->authorize('viewFinance', $org);

        return Inertia::render('marketing/Subscriptions', [
            'subscriptions' => DB::table('portal_subscriptions')->where('organization_id', $org->id)->orderByDesc('id')->paginate(30),
            'bills' => DB::table('portal_subscription_bills')->where('organization_id', $org->id)->orderByDesc('id')->limit(100)->get(),
            'canManage' => $request->user()->can('manageCrm', $org),
            'canLinkBill' => $request->user()->can('manageFinance', $org),
        ]);
    }

    public function costing(Request $request, ListingCosting $costing): Response
    {
        $org = $this->org($request);
        $this->authorize('viewFinance', $org);

        return Inertia::render('marketing/Costing', [
            'portals' => $costing->for($org),
            'spend' => DB::table('listing_spend')->where('organization_id', $org->id)->orderByDesc('incurred_on')->paginate(30),
            'canManage' => $request->user()->can('manageCrm', $org),
        ]);
    }

    public function campaign(Request $request, ManagePortalMarketing $marketing): RedirectResponse
    {
        $org = $this->org($request);
        $marketing->campaign($org, $request->user(), $request->validate([
            'name' => ['required', 'string', 'max:255'], 'type' => ['required', 'string', 'max:30'],
            'vendor_id' => ['nullable', 'integer'], 'cost_centre_id' => ['nullable', 'integer'],
            'budget_aed' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'starts_on' => ['nullable', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]));

        return back();
    }

    public function publication(Request $request, ManagePortalMarketing $marketing): RedirectResponse
    {
        $org = $this->org($request);
        $marketing->publication($org, $request->user(), $request->validate([
            'listing_id' => ['required', 'integer'], 'campaign_id' => ['nullable', 'integer'],
            'portal' => ['required', 'in:bayut,property_finder,dubizzle'],
        ]));

        return back();
    }

    public function enquiry(Request $request, int $publication, ManagePortalMarketing $marketing): RedirectResponse
    {
        $org = $this->org($request);
        $marketing->enquiry($org, $request->user(), $publication, $request->validate([
            'lead_id' => ['required', 'integer'], 'source_reference' => ['required', 'string', 'max:100'],
            'received_at' => ['required', 'date'],
        ]));

        return back();
    }

    public function subscription(Request $request, ManagePortalMarketing $marketing): RedirectResponse
    {
        $org = $this->org($request);
        $marketing->subscription($org, $request->user(), $request->validate([
            'portal' => ['required', 'in:bayut,property_finder,dubizzle'], 'package' => ['required', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer'], 'branch_id' => ['nullable', 'integer'], 'cost_centre_id' => ['nullable', 'integer'],
            'contract_value_aed' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'billing_cycle' => ['required', 'in:monthly,quarterly,annual'], 'credits_total' => ['nullable', 'integer', 'min:0'],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'renews_on' => ['nullable', 'date_format:Y-m-d', 'after:starts_on'],
        ]));

        return back();
    }

    public function subscriptionBill(Request $request, int $subscription, ManagePortalMarketing $marketing): RedirectResponse
    {
        $org = $this->org($request);
        $marketing->linkBill($org, $request->user(), $subscription, $request->validate([
            'vendor_bill_id' => ['required', 'integer'], 'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'],
        ]));

        return back();
    }

    public function spend(Request $request, ManagePortalMarketing $marketing): RedirectResponse
    {
        $org = $this->org($request);
        $marketing->spend($org, $request->user(), $request->validate([
            'listing_id' => ['required', 'integer'], 'publication_id' => ['nullable', 'integer'],
            'campaign_id' => ['nullable', 'integer'], 'vendor_bill_id' => ['nullable', 'integer'],
            'channel' => ['required', 'in:bayut,property_finder,dubizzle,other'],
            'source' => ['required', 'in:estimate,bill_linked'],
            'amount_aed' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'incurred_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:2000'],
        ]));

        return back();
    }

    private function org(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }
}
