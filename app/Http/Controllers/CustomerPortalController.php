<?php

namespace App\Http\Controllers;

use App\Domain\CustomerPortal\Actions\ManagePortalAccess;
use App\Domain\CustomerPortal\Models\PortalGrant;
use App\Domain\CustomerPortal\Models\PortalInvitation;
use App\Domain\CustomerPortal\Models\PortalLeaseInvoice;
use App\Domain\CustomerPortal\Queries\PortalOverview;
use App\Domain\Operations\Actions\SubmitCustomerMaintenance;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class CustomerPortalController extends Controller
{
    public function index(Request $request): Response
    {
        $grants = PortalGrant::where('user_id', $request->user()->id)->whereNull('revoked_at')->orderBy('id')->get()->map(function (PortalGrant $grant): array {
            return [...$grant->only(['id', 'role']), 'organization_name' => Organization::whereKey($grant->organization_id)->value('name')];
        });

        return Inertia::render('portal/Index', ['grants' => $grants]);
    }

    public function show(Request $request, PortalGrant $grant, PortalOverview $overview): Response
    {
        $input = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'invoice_page' => ['nullable', 'integer', 'min:1'], 'request_page' => ['nullable', 'integer', 'min:1'], 'page' => ['nullable', 'integer', 'min:1']]);
        $from = $input['from'] ?? now()->startOfYear()->format('Y-m-d');
        $to = $input['to'] ?? today()->format('Y-m-d');
        abort_if($from > $to || Carbon::parse($from)->diffInDays(Carbon::parse($to)) > 3660, 422, __('Select a period of at most ten years.'));

        return Inertia::render('portal/Overview', $overview->for($request->user(), $grant, $from, $to));
    }

    public function invitation(Request $request, string $token, ManagePortalAccess $access): Response|RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        $invite = $access->invitation($request->user(), $token);

        return Inertia::render('portal/Invitation', ['token' => $token, 'role' => $invite->role, 'organizationName' => Organization::whereKey($invite->organization_id)->value('name')]);
    }

    public function accept(Request $request, string $token, ManagePortalAccess $access): RedirectResponse
    {
        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        $grant = $access->accept($request->user(), $token);

        return redirect()->route('portal.show', $grant);
    }

    public function service(Request $request, PortalGrant $grant, SubmitCustomerMaintenance $service): RedirectResponse
    {
        $service->handle($request->user(), $grant, $request->only(['lease_id', 'title', 'description', 'priority', 'operation_key']));

        return back();
    }

    public function manage(Request $request): Response
    {
        $org = $this->organization($request);
        $this->authorize('manageMembers', $org);

        return Inertia::render('organization/PortalAccess', [
            'tenants' => Tenant::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name', 'reference']),
            'owners' => Owner::where('organization_id', $org->id)->orderBy('name')->get(['id', 'name', 'reference']),
            'invitations' => PortalInvitation::where('organization_id', $org->id)->latest('id')->paginate(25)->withQueryString(),
            'invoiceLinks' => PortalLeaseInvoice::where('organization_id', $org->id)->latest('id')->paginate(25, ['*'], 'link_page')->withQueryString(),
            'invitationUrl' => $request->session()->get('portal_invitation_url'),
        ]);
    }

    public function invite(Request $request, ManagePortalAccess $access): RedirectResponse
    {
        $result = $access->invite($this->organization($request), $request->user(), $request->only(['email', 'role', 'entity_id']));

        return back()->with('portal_invitation_url', route('portal.invitation', $result['token']));
    }

    public function revoke(Request $request, PortalInvitation $invitation, ManagePortalAccess $access): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $access->revoke($this->organization($request), $request->user(), $invitation, $input['reason']);

        return back();
    }

    public function revokeInvoice(Request $request, PortalLeaseInvoice $link, ManagePortalAccess $access): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $access->revokeInvoice($this->organization($request), $request->user(), $link, $input['reason']);

        return back();
    }

    public function linkInvoice(Request $request, ManagePortalAccess $access): RedirectResponse
    {
        $input = $request->validate(['lease_id' => ['required', 'integer'], 'invoice_id' => ['required', 'integer']]);
        $access->linkInvoice($this->organization($request), $request->user(), (int) $input['lease_id'], (int) $input['invoice_id']);

        return back();
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }
}
