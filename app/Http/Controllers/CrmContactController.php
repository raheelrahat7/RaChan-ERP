<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

class CrmContactController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $this->currentOrganization($request);
        $this->authorize('viewCrm', $organization);

        return Inertia::render('crm/Contacts', [
            'contacts' => CrmContact::query()->where('organization_id', $organization->id)->with('account:id,name')->latest()->get()->map(fn (CrmContact $contact) => [
                ...$contact->only('id', 'first_name', 'last_name', 'email', 'phone'),
                'account' => $contact->account?->only('id', 'name'),
            ]),
            'canManageCrm' => $request->user()->can('manageCrm', $organization),
        ]);
    }

    public function store(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
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
        ]);
        $audit->handle($organization, $request->user(), 'crm.contact.created', $contact);

        return back();
    }

    private function currentOrganization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }
}
