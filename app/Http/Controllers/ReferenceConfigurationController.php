<?php

namespace App\Http\Controllers;

use App\Domain\Configuration\Actions\ManageReferenceConfiguration;
use App\Domain\Integrations\Actions\PrepareProviderPacket;
use App\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferenceConfigurationController extends Controller
{
    public function index(Request $request, string $kind, ManageReferenceConfiguration $settings): JsonResponse
    {
        return response()->json($settings->index($this->organization($request), $request->user(), $kind));
    }

    public function save(Request $request, string $kind, ManageReferenceConfiguration $settings, ?int $record = null): JsonResponse
    {
        return response()->json(['id' => $settings->save($this->organization($request), $request->user(), $kind, $request->all(), $record)]);
    }

    public function rebase(Request $request, ManageReferenceConfiguration $settings): JsonResponse
    {
        $settings->rebase($this->organization($request), $request->user(), $request->all());

        return response()->json(['saved' => true]);
    }

    public function packet(Request $request, PrepareProviderPacket $packets): JsonResponse
    {
        return response()->json($packets->handle($this->organization($request), $request->user(), $request->all()));
    }

    private function organization(Request $request): Organization
    {
        return $request->user()->currentOrganization ?? abort(404);
    }
}
