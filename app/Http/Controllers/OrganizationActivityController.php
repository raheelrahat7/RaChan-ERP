<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Queries\OrganizationActivity;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationActivityController extends Controller
{
    public function __invoke(Request $request, OrganizationActivity $activity): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $filters = $request->validate(['module' => ['nullable', 'in:organization,identity,crm,inventory,leasing,transactions,finance,accounting,operations,portal,platform,construction,fleet'], 'actor_id' => ['nullable', 'integer'], 'page' => ['nullable', 'integer', 'min:1']]);
        unset($filters['page']);

        return Inertia::render('organization/Activity', $activity->for($org, $request->user(), $filters));
    }
}
