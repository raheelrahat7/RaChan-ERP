<?php

namespace App\Http\Controllers;

use App\Domain\Platform\Queries\ReadApi;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReadApiController extends Controller
{
    public function index(Request $request, ReadApi $query, string $resource): JsonResponse
    {
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'between:1,100'], 'page' => ['sometimes', 'integer', 'min:1', 'max:1000000']]);
        $org = $request->attributes->get('api_organization');
        abort_unless($org instanceof Organization, 401);
        $size = (int) ($data['per_page'] ?? 25);

        return response()->json(match ($resource) {
            'jobs' => $query->jobs($org, $request->user(), $size),
            'properties' => $query->properties($org, $size),
            'leads' => $query->leads($org, $request->user(), $size),
            'invoices' => $query->invoices($org, $size),
            default => abort(404),
        });
    }
}
