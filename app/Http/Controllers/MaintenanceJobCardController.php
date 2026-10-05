<?php

namespace App\Http\Controllers;

use App\Domain\Documents\Services\DocumentAccess;
use App\Domain\Operations\Actions\ManageJobCard;
use App\Domain\Operations\Actions\ManageJobCompletion;
use App\Domain\Operations\Models\JobCostLine;
use App\Domain\Operations\Models\JobTask;
use App\Domain\Operations\Queries\JobCardDetails;
use App\Domain\Operations\Services\JobCardAccess;
use App\Models\Document;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MaintenanceJobCardController extends Controller
{
    public function show(Request $request, MaintenanceRequest $maintenanceRequest, JobCardDetails $details): Response
    {
        return Inertia::render('operations/JobCard', $details->for($this->organization($request), $request->user(), $maintenanceRequest));
    }

    public function finish(Request $request, MaintenanceRequest $maintenanceRequest, ManageJobCompletion $completion): RedirectResponse
    {
        $completion->finish($this->organization($request), $request->user(), $maintenanceRequest);

        return back();
    }

    public function confirm(Request $request, MaintenanceRequest $maintenanceRequest, ManageJobCompletion $completion): RedirectResponse
    {
        $completion->confirm($this->organization($request), $request->user(), $maintenanceRequest);

        return back();
    }

    public function reopen(Request $request, MaintenanceRequest $maintenanceRequest, ManageJobCompletion $completion): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $completion->updateStatus($this->organization($request), $request->user(), $maintenanceRequest, ['status' => 'in_progress', 'reason' => $input['reason']]);

        return back();
    }

    public function note(Request $request, MaintenanceRequest $maintenanceRequest, ManageJobCard $cards): RedirectResponse
    {
        $input = $request->validate(['note' => ['required', 'string', 'max:5000']]);
        $cards->note($this->organization($request), $request->user(), $maintenanceRequest, $input['note']);

        return back();
    }

    public function task(Request $request, MaintenanceRequest $maintenanceRequest, ManageJobCard $cards): RedirectResponse
    {
        $input = $request->validate(['label' => ['required', 'string', 'max:255'], 'is_required' => ['required', 'boolean']]);
        $cards->task($this->organization($request), $request->user(), $maintenanceRequest, $input['label'], (bool) $input['is_required']);

        return back();
    }

    public function checkTask(Request $request, MaintenanceRequest $maintenanceRequest, JobTask $task, ManageJobCard $cards): RedirectResponse
    {
        $input = $request->validate(['complete' => ['required', 'boolean']]);
        $cards->checkTask($this->organization($request), $request->user(), $maintenanceRequest, $task, (bool) $input['complete']);

        return back();
    }

    public function cost(Request $request, MaintenanceRequest $maintenanceRequest, ManageJobCard $cards): RedirectResponse
    {
        $input = $request->validate(['category' => ['required', 'in:labor,material'], 'description' => ['required', 'string', 'max:255'], 'quantity' => ['required', 'numeric'], 'unit_rate' => ['required', 'numeric']]);
        $cards->cost($this->organization($request), $request->user(), $maintenanceRequest, $input);

        return back();
    }

    public function voidCost(Request $request, MaintenanceRequest $maintenanceRequest, JobCostLine $line, ManageJobCard $cards): RedirectResponse
    {
        $input = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $cards->voidCost($this->organization($request), $request->user(), $maintenanceRequest, $line, $input['reason']);

        return back();
    }

    public function evidence(Request $request, MaintenanceRequest $maintenanceRequest, ManageJobCard $cards): RedirectResponse
    {
        $file = $request->validate(['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp']])['file'];
        $cards->evidence($this->organization($request), $request->user(), $maintenanceRequest, $file);

        return back();
    }

    public function download(Request $request, MaintenanceRequest $maintenanceRequest, Document $document, JobCardAccess $access): StreamedResponse
    {
        $organization = $this->organization($request);
        $access->authorizeView($organization, $request->user(), $maintenanceRequest);
        abort_unless($document->organization_id === $organization->id && $document->documentable_type === $maintenanceRequest->getMorphClass() && $document->documentable_id === $maintenanceRequest->id, 404);
        app(DocumentAccess::class)->authorize($organization, $request->user(), $document);
        abort_unless(Storage::disk('local')->exists($document->path), 404);

        return Storage::disk('local')->download($document->path, $document->name);
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        return $organization;
    }
}
