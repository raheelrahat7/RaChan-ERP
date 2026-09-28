<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Actions\ManageSignatureRequests;
use App\Domain\Documents\Models\SignatureRequest;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SignatureRequestController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null && $request->user()->hasVerifiedEmail() && $request->user()->hasOrganizationRole($org, OrganizationRole::Owner), 403);

        return Inertia::render('documents/Signatures', ['requests' => SignatureRequest::where('organization_id', $org->id)->latest()->paginate(20), 'documents' => Document::where('organization_id', $org->id)->where('mime_type', 'application/pdf')->whereNull('archived_at')->latest()->limit(100)->get(['id', 'name', 'version_number'])]);
    }

    public function store(Request $request, ManageSignatureRequests $signatures): RedirectResponse
    {
        $data = $request->validate(['document_id' => ['required', 'integer']]);
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $document = Document::where('organization_id', $org->id)->findOrFail((int) $data['document_id']);
        $signatures->prepare($org, $request->user(), $document, $request->only(['signers', 'reason', 'operation_key']));

        return back();
    }

    public function cancel(Request $request, int $signature, ManageSignatureRequests $signatures): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string']]);
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);
        $signatures->cancel($org, $request->user(), $signature, $data['reason']);

        return back();
    }

    public function packet(Request $request, int $signature, ManageSignatureRequests $signatures): JsonResponse
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return response()->json($signatures->packet($org, $request->user(), $signature), 200, ['Cache-Control' => 'private, no-store', 'Content-Disposition' => 'attachment; filename="signature-request-'.$signature.'.json"', 'X-Content-Type-Options' => 'nosniff']);
    }
}
