<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ManageParties;
use App\Domain\Crm\Queries\PartyOverview;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmAccount;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmCompanyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $org = $this->organization($request);
        $this->authorize('viewCrm', $org);
        $filters = $request->validate(['q' => ['sometimes', 'string', 'max:150'], 'per_page' => ['sometimes', 'integer', 'between:1,100']]);
        $q = $filters['q'] ?? null;
        $companies = CrmAccount::where('organization_id', $org->id)->withCount('contacts')
            ->when($q, fn ($query) => $query->where(fn ($query) => $query->where('name', 'like', "%{$q}%")->orWhere('email', 'like', "%{$q}%")->orWhere('phone', 'like', "%{$q}%")))
            ->latest('id')->paginate($filters['per_page'] ?? 25)->withQueryString();
        $companies->through(fn (CrmAccount $company) => [...$company->toArray(), 'permissions' => ['read' => true, 'edit' => $request->user()->can('manageCrm', $org)]]);

        return response()->json(['companies' => $companies]);
    }

    public function show(Request $request, int $company, PartyOverview $overview): JsonResponse
    {
        $org = $this->organization($request);
        $this->authorize('viewCrm', $org);

        return response()->json($overview->company($org, $request->user(), CrmAccount::where('organization_id', $org->id)->findOrFail($company)));
    }

    public function store(Request $request, PartyOverview $overview, RecordOrganizationAuditLog $audit): JsonResponse
    {
        $org = $this->organization($request);
        $this->authorize('manageCrm', $org);
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email', 'max:255'], 'phone' => ['nullable', 'string', 'max:50'], 'website' => ['nullable', 'url', 'max:255']]);
        $company = CrmAccount::create(['organization_id' => $org->id, ...$data])->refresh();
        $audit->handle($org, $request->user(), 'crm.company.created', $company);

        return response()->json($overview->company($org, $request->user(), $company), 201);
    }

    public function update(Request $request, int $company, ManageParties $parties, PartyOverview $overview): JsonResponse
    {
        $org = $this->organization($request);
        $this->authorize('manageCrm', $org);
        $company = $parties->updateCompany($org, $request->user(), CrmAccount::where('organization_id', $org->id)->findOrFail($company), $request->all());

        return response()->json($overview->company($org, $request->user(), $company));
    }

    private function organization(Request $request): Organization
    {
        return $request->user()->currentOrganization ?? abort(404);
    }
}
