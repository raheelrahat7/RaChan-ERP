<?php

namespace App\Domain\Leasing\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Leasing\Models\VacancyRecord;
use App\Models\HandoverChecklist;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class ManageVacancy
{
    public function __construct(private readonly RecordOrganizationAuditLog $audit) {}

    public function open(Organization $organization, User $actor, Lease $lease, HandoverChecklist $handover): VacancyRecord
    {
        return DB::transaction(function () use ($organization, $actor, $lease, $handover): VacancyRecord {
            $vacancy = VacancyRecord::where('organization_id', $organization->id)->where('unit_id', $lease->unit_id)->whereNull('resolved_at')->lockForUpdate()->first();
            if ($vacancy === null) {
                $vacancy = VacancyRecord::create([
                    'organization_id' => $organization->id,
                    'unit_id' => $lease->unit_id,
                    'previous_lease_id' => $lease->id,
                    'handover_checklist_id' => $handover->id,
                    'vacant_from' => now()->toDateString(),
                    'readiness_status' => 'inspection',
                    'updated_by' => $actor->id,
                ]);
                $this->audit->handle($organization, $actor, 'transactions.vacancy.opened', $vacancy, ['unit_id' => $lease->unit_id]);
            }

            return $vacancy;
        });
    }

    /** @param array{readiness_status: string, target_ready_on?: string|null, notes?: string|null} $details */
    public function update(Organization $organization, User $actor, VacancyRecord $vacancy, array $details): VacancyRecord
    {
        return DB::transaction(function () use ($organization, $actor, $vacancy, $details): VacancyRecord {
            $locked = VacancyRecord::where('organization_id', $organization->id)->whereNull('resolved_at')->lockForUpdate()->findOrFail($vacancy->id);
            $locked->update([...$details, 'updated_by' => $actor->id]);
            $this->audit->handle($organization, $actor, 'transactions.vacancy.updated', $locked, ['readiness_status' => $locked->readiness_status]);

            return $locked;
        });
    }

    public function resolveForLease(Organization $organization, User $actor, Lease $lease): void
    {
        DB::transaction(function () use ($organization, $actor, $lease): void {
            $vacancies = VacancyRecord::where('organization_id', $organization->id)->where('unit_id', $lease->unit_id)->whereNull('resolved_at')->lockForUpdate()->get();
            foreach ($vacancies as $vacancy) {
                $vacancy->update(['resolved_at' => now(), 'resolution' => 'leased', 'updated_by' => $actor->id]);
                $this->audit->handle($organization, $actor, 'transactions.vacancy.resolved', $vacancy, ['lease_id' => $lease->id]);
            }
        });
    }
}
