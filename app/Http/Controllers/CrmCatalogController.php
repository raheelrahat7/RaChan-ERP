<?php

namespace App\Http\Controllers;

use App\Domain\Configuration\Actions\ManageCrmCatalog;
use App\Domain\Crm\Actions\ManageLeadProducts;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CrmCatalogController extends Controller
{
    public function index(Request $request, string $kind, ManageCrmCatalog $catalog): JsonResponse
    {
        return response()->json($catalog->index($this->organization($request), $request->user(), $kind));
    }

    public function save(Request $request, string $kind, ManageCrmCatalog $catalog, ?int $record = null): JsonResponse
    {
        return response()->json(['record' => $catalog->save($this->organization($request), $request->user(), $kind, $request->all(), $record)]);
    }

    public function leadProducts(Request $request, int $lead, ManageLeadProducts $products): JsonResponse
    {
        return response()->json($products->index($this->organization($request), $request->user(), $lead));
    }

    public function saveLeadProduct(Request $request, int $lead, ManageLeadProducts $products, ?int $line = null): JsonResponse
    {
        return response()->json(['line' => $products->save($this->organization($request), $request->user(), $lead, $request->all(), $line)]);
    }

    public function removeLeadProduct(Request $request, int $lead, int $line, ManageLeadProducts $products): JsonResponse
    {
        $products->remove($this->organization($request), $request->user(), $lead, $line);

        return response()->json(['removed' => true]);
    }

    private function organization(Request $request): Organization
    {
        return $request->user()->currentOrganization ?? abort(404);
    }
}
