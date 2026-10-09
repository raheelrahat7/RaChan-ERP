<?php

namespace App\Http\Controllers;

use App\Domain\RealEstate\Actions\ManagePropertyParties;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RealEstatePartyDataController extends Controller
{
    public function index(Request $request, string $type, ManagePropertyParties $parties): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $table = $parties->table($type);
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $records = DB::table($table)->where('organization_id', $org->id)
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($scope) => $scope->where('name', 'like', '%'.$value.'%')->orWhere('reference', 'like', '%'.$value.'%')))
            ->orderBy('name')->paginate(30)->withQueryString();
        $records->through(fn ($row) => $parties->serialize($org, $request->user(), $type, $row));

        return response()->json(['records' => $records, 'filters' => $filters, 'permissions' => ['read' => true, 'create' => $request->user()->can('manageCrm', $org)]]);
    }

    public function show(Request $request, string $type, int $record, ManagePropertyParties $parties): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $this->authorize('viewCrm', $org);
        $row = DB::table($parties->table($type))->where('organization_id', $org->id)->where('id', $record)->first();
        abort_unless($row !== null, 404);

        return response()->json(['record' => $parties->serialize($org, $request->user(), $type, $row, true)]);
    }

    public function update(Request $request, string $type, int $record, ManagePropertyParties $parties): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $row = $parties->update($org, $request->user(), $type, $record, $request->all());

        return response()->json(['record' => $parties->serialize($org, $request->user(), $type, $row, true)]);
    }
}
