<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Queries\PendingApprovals;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ApprovalsInboxController extends Controller
{
    public function __invoke(Request $request, PendingApprovals $approvals): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('view', $organization);

        $items = $approvals->for($organization, $request->user());

        return Inertia::render('approvals/Index', ['items' => $items, 'count' => count($items)]);
    }
}
