<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Leasing\Actions\AttachHandoverEvidence;
use App\Domain\Leasing\Actions\ManageVacancy;
use App\Domain\Leasing\Actions\RecordHandoverInspection;
use App\Domain\Leasing\Models\HandoverInspectionItem;
use App\Domain\Leasing\Models\VacancyRecord;
use App\Models\Document;
use App\Models\HandoverChecklist;
use App\Models\Lease;
use App\Models\Unit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HandoverController extends Controller
{
    public function index(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('viewTransactions', $organization);

        $handoverRows = HandoverChecklist::where('organization_id', $organization->id)->with(['lease:id,reference', 'inspectionItems.recorder:id,name', 'inspectionItems.documents:id,documentable_id,documentable_type,name,mime_type,size'])->latest()->get();
        $handovers = [];
        foreach ($handoverRows as $handover) {
            $handovers[] = [
                ...$handover->only('id', 'type', 'status', 'scheduled_on', 'completed_at', 'notes'),
                'lease' => $handover->lease?->only('id', 'reference'),
                'inspectionItems' => $handover->inspectionItems->map(fn ($item) => [
                    ...$item->only('id', 'area', 'condition', 'notes', 'created_at'),
                    'recorder' => $item->recorder?->name,
                    'documents' => ($request->user()->hasOrganizationPermission($organization, OrganizationPermission::ViewDocuments) ? $item->documents : collect())->map->only('id', 'name', 'mime_type', 'size'),
                ]),
            ];
        }

        $vacancyRows = VacancyRecord::where('organization_id', $organization->id)->whereNull('resolved_at')
            ->with(['unit:id,number,property_id', 'unit.property:id,name', 'previousLease:id,reference', 'updater:id,name'])
            ->orderBy('vacant_from')->get();
        $vacancies = [];
        foreach ($vacancyRows as $vacancy) {
            $vacancies[] = [
                ...$vacancy->only('id', 'vacant_from', 'target_ready_on', 'readiness_status', 'notes'),
                'age_days' => (int) Carbon::parse($vacancy->vacant_from)->diffInDays(today()),
                'unit' => $vacancy->unit ? [...$vacancy->unit->only('id', 'number'), 'property' => $vacancy->unit->property?->name] : null,
                'previous_lease' => $vacancy->previousLease?->reference,
                'updated_by' => $vacancy->updater?->name,
            ];
        }

        return Inertia::render('transactions/Handovers', [
            'leases' => Lease::where('organization_id', $organization->id)->where('status', 'active')
                ->with(['unit:id,number', 'tenant:id,name'])->orderBy('ends_on')->get()
                ->map(fn (Lease $lease) => [...$lease->only('id', 'reference', 'ends_on'), 'unit' => $lease->unit?->only('id', 'number'), 'tenant' => $lease->tenant?->only('id', 'name')]),
            'handovers' => $handovers,
            'vacancies' => $vacancies,
            'canManage' => $request->user()->can('manageTransactions', $organization),
        ]);
    }

    public function store(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageTransactions', $organization);
        $input = $request->validate(['lease_id' => ['required', 'integer'], 'type' => ['required', 'in:move_in,move_out'], 'scheduled_on' => ['nullable', 'date'], 'notes' => ['nullable', 'string', 'max:5000']]);
        Lease::where('organization_id', $organization->id)->where('status', 'active')->findOrFail((int) $input['lease_id']);
        $handover = HandoverChecklist::firstOrCreate(['lease_id' => $input['lease_id'], 'type' => $input['type']], ['organization_id' => $organization->id, 'scheduled_on' => $input['scheduled_on'] ?? null, 'notes' => $input['notes'] ?? null, 'created_by' => $request->user()->id]);
        $audit->handle($organization, $request->user(), 'transactions.handover.created', $handover, ['type' => $handover->type]);

        return back();
    }

    public function complete(Request $request, HandoverChecklist $handover, RecordOrganizationAuditLog $audit, ManageVacancy $vacancies): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $handover->organization_id === $organization->id, 404);
        $this->authorize('manageTransactions', $organization);
        abort_unless($handover->status === 'planned', 422);
        $handover->update(['status' => 'completed', 'completed_at' => now()]);
        $lease = $handover->lease;
        if ($handover->type === 'move_out' && $lease?->status === 'active') {
            $lease->update(['status' => 'completed', 'version' => $lease->version + 1]);
            Unit::where('organization_id', $organization->id)->whereKey($lease->unit_id)->update(['status' => 'available']);
            $vacancies->open($organization, $request->user(), $lease, $handover);
        }
        $audit->handle($organization, $request->user(), 'transactions.handover.completed', $handover, ['type' => $handover->type]);

        return back();
    }

    public function updateVacancy(Request $request, VacancyRecord $vacancy, ManageVacancy $vacancies): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $vacancy->organization_id === $organization->id, 404);
        $this->authorize('manageTransactions', $organization);
        $input = $request->validate([
            'readiness_status' => ['required', 'in:inspection,maintenance,ready_to_list,listed'],
            'target_ready_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
        $vacancies->update($organization, $request->user(), $vacancy, $input);

        return back();
    }

    public function storeInspection(Request $request, HandoverChecklist $handover, RecordHandoverInspection $record): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $handover->organization_id === $organization->id, 404);
        $this->authorize('manageTransactions', $organization);
        $input = $request->validate([
            'area' => ['required', 'string', 'max:100'],
            'condition' => ['required', 'in:good,needs_attention,damaged'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $record->handle($organization, $request->user(), $handover, $input);

        return back();
    }

    public function storeInspectionEvidence(Request $request, HandoverInspectionItem $inspectionItem, AttachHandoverEvidence $attach): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $inspectionItem->organization_id === $organization->id, 404);
        $this->authorize('manageTransactions', $organization);
        $file = $request->validate(['file' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:10240']])['file'];
        $attach->handle($organization, $request->user(), $inspectionItem, $file);

        return back();
    }

    public function downloadInspectionEvidence(Request $request, Document $document): StreamedResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $document->organization_id === $organization->id, 404);
        $this->authorize('viewTransactions', $organization);
        abort_unless($document->documentable instanceof HandoverInspectionItem, 404);
        app(DocumentAccess::class)->authorize($organization, $request->user(), $document);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->name);
    }
}
