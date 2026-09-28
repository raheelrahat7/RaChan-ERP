<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Document;
use App\Models\Property;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentController extends Controller
{
    public function storeProperty(Request $request, Property $property, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $property->organization_id === $organization->id, 404);
        $this->authorize('manageInventory', $organization);
        $file = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx']])['file'];
        $path = $file->store("organizations/{$organization->id}/properties/{$property->id}", 'local');
        $document = $property->documents()->create(['organization_id' => $organization->id, 'uploaded_by' => $request->user()->id, 'name' => $file->getClientOriginalName(), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
        $audit->handle($organization, $request->user(), 'inventory.document.uploaded', $document, ['property_id' => $property->id]);

        return back();
    }

    public function storeUnit(Request $request, Unit $unit, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $unit->organization_id === $organization->id, 404);
        $this->authorize('manageInventory', $organization);
        $file = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx']])['file'];
        $path = $file->store("organizations/{$organization->id}/units/{$unit->id}", 'local');
        $document = $unit->documents()->create(['organization_id' => $organization->id, 'uploaded_by' => $request->user()->id, 'name' => $file->getClientOriginalName(), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
        $audit->handle($organization, $request->user(), 'inventory.document.uploaded', $document, ['unit_id' => $unit->id]);

        return back();
    }

    public function download(Request $request, Document $document, DocumentAccess $access): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $document->organization_id === $organization->id, 404);
        $access->authorize($organization, $request->user(), $document);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->name);
    }
}
