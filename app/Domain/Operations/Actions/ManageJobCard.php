<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Operations\Models\JobCostLine;
use App\Domain\Operations\Models\JobNote;
use App\Domain\Operations\Models\JobTask;
use App\Domain\Operations\Services\JobCardAccess;
use App\Domain\Operations\Services\JobCostAmount;
use App\Models\Document;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ManageJobCard
{
    public function __construct(private JobCardAccess $access, private JobCostAmount $amounts, private RecordOrganizationAuditLog $audit) {}

    public function note(Organization $organization, User $actor, MaintenanceRequest $job, string $note): JobNote
    {
        Validator::make(['note' => trim($note)], ['note' => ['required', 'string', 'max:5000']])->validate();

        return DB::transaction(function () use ($organization, $actor, $job, $note): JobNote {
            $locked = $this->editable($organization, $actor, $job);
            $record = $locked->jobNotes()->create(['organization_id' => $organization->id, 'author_id' => $actor->id, 'note' => trim($note)]);
            $this->audit->handle($organization, $actor, 'operations.job.note_added', $locked, ['note_id' => $record->id]);

            return $record;
        });
    }

    public function task(Organization $organization, User $actor, MaintenanceRequest $job, string $label, bool $required): JobTask
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        Validator::make(['label' => trim($label)], ['label' => ['required', 'string', 'max:255']])->validate();

        return DB::transaction(function () use ($organization, $actor, $job, $label, $required): JobTask {
            $locked = $this->editable($organization, $actor, $job);
            $task = $locked->jobTasks()->create(['organization_id' => $organization->id, 'created_by' => $actor->id, 'label' => trim($label), 'is_required' => $required]);
            $this->audit->handle($organization, $actor, 'operations.job.task_added', $locked, ['task_id' => $task->id, 'label' => $task->label, 'is_required' => $required]);

            return $task;
        });
    }

    public function checkTask(Organization $organization, User $actor, MaintenanceRequest $job, JobTask $task, bool $complete): void
    {
        DB::transaction(function () use ($organization, $actor, $job, $task, $complete): void {
            $locked = $this->editable($organization, $actor, $job);
            $task = $locked->jobTasks()->where('organization_id', $organization->id)->lockForUpdate()->findOrFail($task->id);
            if (($task->completed_at !== null) === $complete) {
                return;
            }
            $task->update(['completed_by' => $complete ? $actor->id : null, 'completed_at' => $complete ? now() : null]);
            $this->audit->handle($organization, $actor, 'operations.job.task_updated', $locked, ['task_id' => $task->id, 'completed' => $complete]);
        });
    }

    /** @param array<string, mixed> $input */
    public function cost(Organization $organization, User $actor, MaintenanceRequest $job, array $input): JobCostLine
    {
        Validator::make($input, ['category' => ['required', 'in:labor,material'], 'description' => ['required', 'string', 'max:255']])->validate();
        $amount = $this->amounts->calculate((string) $input['quantity'], (string) $input['unit_rate']);

        return DB::transaction(function () use ($organization, $actor, $job, $input, $amount): JobCostLine {
            $locked = $this->editable($organization, $actor, $job);
            abort_unless($locked->currency === 'AED', 422, __('Job-card cost entries currently require AED.'));
            $line = $locked->jobCostLines()->create(['organization_id' => $organization->id, 'recorded_by' => $actor->id, 'category' => $input['category'], 'description' => trim($input['description']), 'quantity' => $input['quantity'], 'unit_rate' => $input['unit_rate'], 'amount' => $amount]);
            $this->audit->handle($organization, $actor, 'operations.job.cost_added', $locked, ['line_id' => $line->id, 'category' => $line->category, 'amount' => $amount]);

            return $line;
        });
    }

    public function voidCost(Organization $organization, User $actor, MaintenanceRequest $job, JobCostLine $line, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageOperations', $organization);
        Validator::make(['reason' => trim($reason)], ['reason' => ['required', 'string', 'max:2000']])->validate();
        DB::transaction(function () use ($organization, $actor, $job, $line, $reason): void {
            $locked = $this->editable($organization, $actor, $job);
            $line = $locked->jobCostLines()->where('organization_id', $organization->id)->lockForUpdate()->findOrFail($line->id);
            abort_unless($line->voided_at === null, 422, __('This entry has already been voided.'));
            $line->update(['voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => trim($reason)]);
            $this->audit->handle($organization, $actor, 'operations.job.cost_voided', $locked, ['line_id' => $line->id, 'reason' => trim($reason)]);
        });
    }

    public function evidence(Organization $organization, User $actor, MaintenanceRequest $job, UploadedFile $file): Document
    {
        $this->access->authorizeView($organization, $actor, $job);
        abort_unless($actor->hasOrganizationPermission($organization, OrganizationPermission::ManageDocuments), 403);
        Validator::make(['file' => $file], ['file' => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png,webp']])->validate();
        $path = $file->store("organizations/{$organization->id}/maintenance/{$job->id}", 'local');
        abort_if($path === false, 500, __('The evidence could not be stored.'));
        try {
            return DB::transaction(function () use ($organization, $actor, $job, $file, $path): Document {
                $locked = $this->editable($organization, $actor, $job);
                $document = $locked->documents()->create(['organization_id' => $organization->id, 'uploaded_by' => $actor->id, 'name' => $file->getClientOriginalName(), 'path' => $path, 'mime_type' => $file->getMimeType(), 'size' => $file->getSize()]);
                $this->audit->handle($organization, $actor, 'operations.job.evidence_added', $locked, ['document_id' => $document->id]);

                return $document;
            });
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);

            throw $exception;
        }
    }

    private function editable(Organization $organization, User $actor, MaintenanceRequest $job): MaintenanceRequest
    {
        $locked = MaintenanceRequest::where('organization_id', $organization->id)->lockForUpdate()->findOrFail($job->id);
        $this->access->authorizeView($organization, $actor, $locked);
        abort_if(in_array($locked->status, ['completed', 'cancelled'], true) || $locked->submitted_at !== null, 422, __('Submitted or closed job cards are read-only; ask a manager to reopen the job.'));

        return $locked;
    }
}
