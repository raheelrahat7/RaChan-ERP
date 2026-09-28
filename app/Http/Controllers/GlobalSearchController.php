<?php

namespace App\Http\Controllers;

use App\Support\GlobalSearch;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('view', $organization);
        $input = $request->validate(['q' => ['nullable', 'string', 'min:2', 'max:100']]);
        $term = trim($input['q'] ?? '');

        return Inertia::render('Search', [
            'q' => $term,
            'results' => strlen($term) >= 2 ? $search->search($organization, $request->user(), $term) : [],
        ]);
    }
}
