<?php

namespace App\Http\Controllers;

use App\Domain\RealEstate\Models\Developer;
use App\Models\Broker;
use App\Models\Owner;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RealEstatePartyController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewCrm', $organization);

        return Inertia::render('real-estate/People', [
            'owners' => Owner::where('organization_id', $organization->id)->latest()->get(['id', 'name', 'email', 'phone']),
            'tenants' => Tenant::where('organization_id', $organization->id)->latest()->get(['id', 'name', 'email', 'phone']),
            'brokers' => Broker::where('organization_id', $organization->id)->latest()->get(['id', 'name', 'email', 'phone']),
            'developers' => Developer::where('organization_id', $organization->id)->latest()->get(['id', 'name', 'email', 'phone', 'reference']),
            'canManage' => $request->user()->can('manageCrm', $organization),
        ]);
    }

    public function store(Request $request, string $type): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageCrm', $organization);
        $model = match ($type) {
            'owners' => Owner::class, 'tenants' => Tenant::class, 'brokers' => Broker::class, 'developers' => Developer::class, default => abort(404)
        };
        $input = $request->validate(['name' => ['required', 'string', 'max:255'], 'email' => ['nullable', 'email'], 'phone' => ['nullable', 'string', 'max:50'], 'reference' => ['nullable', 'string', 'max:100']]);
        $model::create(['organization_id' => $organization->id, ...$input]);

        return back();
    }
}
