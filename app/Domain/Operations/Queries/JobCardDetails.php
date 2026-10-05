<?php

namespace App\Domain\Operations\Queries;

use App\Domain\Identity\Enums\OrganizationPermission;
use App\Domain\Operations\Models\JobCostLine;
use App\Domain\Operations\Models\JobSlaCycle;
use App\Domain\Operations\Services\JobCardAccess;
use App\Domain\Operations\Services\JobCostAmount;
use App\Domain\Operations\Services\JobSlaClock;
use App\Models\AuditLog;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceVendor;
use App\Models\Organization;
use App\Models\Property;
use App\Models\Unit;
use App\Models\User;

class JobCardDetails
{
    public function __construct(private JobCardAccess $access, private JobCostAmount $amounts, private SparePartsOverview $stock) {}

    /** @return array<string, mixed> */
    public function for(Organization $organization, User $actor, MaintenanceRequest $job): array
    {
        $this->access->authorizeView($organization, $actor, $job);
        $job->load([
            'jobNotes' => fn ($query) => $query->where('organization_id', $organization->id)->with('author:id,name')->orderBy('id'),
            'jobTasks' => fn ($query) => $query->where('organization_id', $organization->id)->orderBy('id'),
            'jobCostLines' => fn ($query) => $query->where('organization_id', $organization->id)->with('recorder:id,name')->orderBy('id'),
            'documents' => fn ($query) => $query->where('organization_id', $organization->id)->when(! $actor->hasOrganizationPermission($organization, OrganizationPermission::ViewDocuments), fn ($q) => $q->whereRaw('1 = 0'))->select(['id', 'organization_id', 'documentable_id', 'documentable_type', 'name', 'mime_type', 'size', 'created_at'])->orderBy('id'),
        ]);
        $labor = $job->jobCostLines->whereNull('voided_at')->where('category', 'labor')->sum(fn (JobCostLine $line): int => $this->amounts->hundredths($line->amount));
        $material = $job->jobCostLines->whereNull('voided_at')->where('category', 'material')->sum(fn (JobCostLine $line): int => $this->amounts->hundredths($line->amount));
        $manager = $this->access->manages($organization, $actor);

        return [
            'job' => $job,
            'slaCycles' => JobSlaCycle::where('organization_id', $organization->id)->where('maintenance_request_id', $job->id)->orderByDesc('cycle_number')->get()->map(fn (JobSlaCycle $cycle): array => app(JobSlaClock::class)->summary($cycle))->all(),
            'stockMovements' => $this->stock->job($organization, $job),
            'property' => Property::where('organization_id', $organization->id)->find($job->property_id, ['id', 'name']),
            'unit' => Unit::where('organization_id', $organization->id)->where('property_id', $job->property_id)->find($job->unit_id, ['id', 'number']),
            'vendor' => MaintenanceVendor::where('organization_id', $organization->id)->find($job->vendor_id, ['id', 'name']),
            'submitter' => $organization->users()->whereKey($job->submitted_by)->first(['users.id', 'users.name']),
            'confirmer' => $organization->users()->whereKey($job->confirmed_by)->first(['users.id', 'users.name']),
            'completer' => $organization->users()->whereKey($job->completed_by)->first(['users.id', 'users.name']),
            'assignee' => $organization->users()->whereKey($job->assigned_to)->first(['users.id', 'users.name']),
            'canManage' => $manager,
            'canEdit' => ! in_array($job->status, ['completed', 'cancelled'], true) && $job->submitted_at === null,
            'members' => $manager ? $organization->users()->orderBy('name')->get(['users.id', 'users.name']) : [],
            'totals' => ['labor' => $this->amounts->format($labor), 'material' => $this->amounts->format($material), 'total' => $this->amounts->format($labor + $material)],
            'history' => AuditLog::where('organization_id', $organization->id)->where('subject_type', $job->getMorphClass())->where('subject_id', $job->id)->where('event', 'like', 'operations.%')->with('actor:id,name')->latest('id')->limit(100)->get(),
        ];
    }
}
