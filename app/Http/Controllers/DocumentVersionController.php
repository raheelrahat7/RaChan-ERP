<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Actions\ManageDocumentVersions;
use App\Domain\Documents\Services\DocumentAccess;
use App\Models\Document;
use App\Models\Organization;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DocumentVersionController extends Controller
{
    public function show(Request $request, Document $document, DocumentAccess $access, ManageDocumentVersions $versions): Response
    {
        $org = $this->organization($request);
        $access->authorize($org, $request->user(), $document);
        $root = $versions->root($org, $document);
        $canEdit = true;
        try {
            $access->authorize($org, $request->user(), $document, true);
        } catch (AuthorizationException|HttpException $exception) {
            $canEdit = false;
        }

        return Inertia::render('documents/Versions', ['root' => $root->only(['id', 'name', 'archived_at', 'archive_reason']), 'canEdit' => $canEdit, 'versions' => Document::where('organization_id', $org->id)->where(fn ($q) => $q->whereKey($root->id)->orWhere('root_document_id', $root->id))->orderByDesc('version_number')->get(['id', 'name', 'mime_type', 'size', 'version_number', 'version_reason', 'created_at'])]);
    }

    public function store(Request $request, Document $document, ManageDocumentVersions $versions): RedirectResponse
    {
        $input = $request->validate(['file' => ['required', 'file'], 'reason' => ['required', 'string'], 'version_key' => ['required', 'uuid']]);
        $versions->add($this->organization($request), $request->user(), $document, $input['file'], $input['reason'], $input['version_key']);

        return back();
    }

    public function archive(Request $request, Document $document, ManageDocumentVersions $versions): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string']]);
        $versions->archive($this->organization($request), $request->user(), $document, $input['reason']);

        return back();
    }

    public function restore(Request $request, Document $document, ManageDocumentVersions $versions): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string']]);
        $versions->archive($this->organization($request), $request->user(), $document, $input['reason'], true);

        return back();
    }

    public function preview(Request $request, Document $document, DocumentAccess $access): StreamedResponse
    {
        $org = $this->organization($request);
        $access->authorize($org, $request->user(), $document);
        $disk = Storage::disk('local');
        abort_unless($disk->exists($document->path), 404);
        $mime = $disk->mimeType($document->path);
        abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true), 415, 'Download this file type to view it.');

        return $disk->response($document->path, $document->name, ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "sandbox; default-src 'none'", 'X-Frame-Options' => 'SAMEORIGIN', 'Cache-Control' => 'private, no-store'], 'inline');
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org;
    }
}
