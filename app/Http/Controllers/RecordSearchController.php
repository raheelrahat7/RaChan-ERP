<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Queries\RecordSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class RecordSearchController extends Controller
{
    public function __invoke(Request $request, RecordSearch $search): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $request->user()->belongsToOrganization($org), 404);
        $input = $request->validate([
            'type' => ['required', Rule::in(['lead', 'listing', 'unit', 'reservation', 'lease', 'sale', 'job'])],
            'q' => ['nullable', 'string', 'max:100'],
            'assignee_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $term = trim($input['q'] ?? '');
        if (mb_strlen($term) < 2) {
            return response()->json([]);
        }
        $assignee = isset($input['assignee_id']) ? $org->users()->whereKey($input['assignee_id'])->first() : $request->user();
        if ($assignee === null) {
            throw ValidationException::withMessages(['assignee_id' => 'Choose an organization member.']);
        }

        return response()->json($search->search($org, $request->user(), $assignee, $input['type'], $term));
    }
}
