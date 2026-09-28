<?php

namespace App\Http\Controllers;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\ComplianceDocument;
use App\Models\Lease;
use App\Models\MaintenanceVendor;
use App\Models\Property;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ComplianceDocumentController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewOperations', $organization);

        return Inertia::render('operations/ComplianceDocuments', [
            'documents' => ComplianceDocument::where('organization_id', $organization->id)->latest()->get()->map(fn (ComplianceDocument $document) => [...$document->only('id', 'name', 'category', 'expires_on'), 'is_expiring' => $document->expires_on !== null && Carbon::parse($document->expires_on)->between(today(), today()->addDays(30))]),
            'properties' => Property::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'leases' => Lease::where('organization_id', $organization->id)->get(['id', 'reference']),
            'tenants' => Tenant::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'vendors' => MaintenanceVendor::where('organization_id', $organization->id)->orderBy('name')->get(['id', 'name']),
            'canManage' => $request->user()->can('manageOperations', $organization),
        ]);
    }

    public function store(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageOperations', $organization);
        $input = $request->validate(['subject_type' => ['required', 'in:property,lease,tenant,vendor'], 'subject_id' => ['required', 'integer'], 'category' => ['required', 'string', 'max:100'], 'expires_on' => ['nullable', 'date'], 'file' => ['required', 'file', 'max:10240']]);
        /** @var class-string<Model> $model */
        $model = match ((string) $input['subject_type']) {
            'property' => Property::class, 'lease' => Lease::class, 'tenant' => Tenant::class, 'vendor' => MaintenanceVendor::class,
            default => abort(422),
        };
        /** @var Model $subject */
        $subject = $model::where('organization_id', $organization->id)->findOrFail((int) $input['subject_id']);
        $file = $request->file('file');
        $document = ComplianceDocument::create(['organization_id' => $organization->id, 'documentable_type' => $subject->getMorphClass(), 'documentable_id' => $subject->getKey(), 'name' => $file->getClientOriginalName(), 'category' => $input['category'], 'expires_on' => $input['expires_on'] ?? null, 'path' => $file->store('compliance/'.$organization->id, 'local')]);
        $audit->handle($organization, $request->user(), 'operations.compliance_document.created', $document);

        return back();
    }

    public function download(Request $request, ComplianceDocument $document): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $document->organization_id === $organization->id, 404);
        $this->authorize('viewOperations', $organization);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->name);
    }
}
