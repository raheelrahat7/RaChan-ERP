<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageParties;
use App\Domain\Crm\Queries\PartyOverview;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class CrmContactController extends Controller
{
    public function index(Request $request): Response|JsonResponse
    {
        $organization = $this->currentOrganization($request);
        $this->authorize('viewCrm', $organization);

        if ($request->expectsJson()) {
            $filters = $request->validate(['q' => ['sometimes', 'string', 'max:150'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
            $q = $filters['q'] ?? null;
            $contacts = CrmContact::where('organization_id', $organization->id)->with('account:id,name')
                ->when($q, fn ($query) => $query->where(fn ($query) => $query->where('first_name', 'like', "%{$q}%")->orWhere('last_name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
                ->latest('id')->paginate($filters['per_page'] ?? 25)->withQueryString();
            $contacts->through(fn (CrmContact $contact) => [...$contact->toArray(), 'permissions' => ['read' => true, 'edit' => $request->user()->can('manageCrm', $organization)]]);

            return response()->json(['contacts' => $contacts]);
        }

        return Inertia::render('crm/Contacts', [
            'contacts' => CrmContact::query()->where('organization_id', $organization->id)->with('account:id,name')->latest()->get()->map(fn (CrmContact $contact) => [
                ...$contact->only('id', 'first_name', 'last_name', 'email', 'phone'),
                'account' => $contact->account?->only('id', 'name'),
            ]),
            'canManageCrm' => $request->user()->can('manageCrm', $organization),
        ]);
    }

    public function show(Request $request, int $contact, PartyOverview $overview): JsonResponse
    {
        $organization = $this->currentOrganization($request);
        $this->authorize('viewCrm', $organization);

        return response()->json($overview->contact($organization, $request->user(), CrmContact::where('organization_id', $organization->id)->findOrFail($contact)));
    }

    public function update(Request $request, int $contact, ManageParties $parties, PartyOverview $overview): JsonResponse
    {
        $organization = $this->currentOrganization($request);
        $this->authorize('manageCrm', $organization);
        $contact = $parties->updateContact($organization, $request->user(), CrmContact::where('organization_id', $organization->id)->findOrFail($contact), $request->all());

        return response()->json($overview->contact($organization, $request->user(), $contact));
    }

    public function store(Request $request, RecordOrganizationAuditLog $audit, PartyOverview $overview): RedirectResponse|JsonResponse
    {
        $organization = $this->currentOrganization($request);
        $this->authorize('manageCrm', $organization);
        $input = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'company' => ['nullable', 'string', 'max:255'],
        ]);

        $account = filled($input['company'] ?? null)
            ? CrmAccount::firstOrCreate(['organization_id' => $organization->id, 'name' => $input['company']])
            : null;
        $contact = CrmContact::create([
            'organization_id' => $organization->id,
            'account_id' => $account?->id,
            ...Arr::except($input, ['company']),
        ])->refresh();
        $audit->handle($organization, $request->user(), 'crm.contact.created', $contact);

        if ($request->expectsJson()) {
            return response()->json($overview->contact($organization, $request->user(), $contact), 201);
        }

        return back();
    }

    private function currentOrganization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }
}
