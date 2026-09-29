<?php

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageStaffServices
{
    public function __construct(private RecordOrganizationAuditLog $audit, private RemoveOrganizationMember $members) {}

    public function manages(Organization $org, User $actor): bool
    {
        return $actor->hasOrganizationRole($org, OrganizationRole::Owner)
            || $actor->hasOrganizationRole($org, OrganizationRole::Administrator);
    }

    /** @param array<string,mixed> $input */
    public function staff(Organization $org, User $actor, array $input): int
    {
        abort_unless($this->manages($org, $actor), 403);
        if (! $org->users()->whereKey($input['user_id'])->exists()) {
            throw ValidationException::withMessages(['user_id' => 'Choose an organization member.']);
        }

        return DB::transaction(function () use ($org, $actor, $input): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (DB::table('hr_staff')->where('organization_id', $org->id)->where('user_id', $input['user_id'])->exists()) {
                throw ValidationException::withMessages(['user_id' => 'This member already has a staff record.']);
            }
            $id = DB::table('hr_staff')->insertGetId([
                'organization_id' => $org->id, 'user_id' => $input['user_id'], 'created_by' => $actor->id,
                'job_title' => $input['job_title'] ?? null, 'hired_on' => $input['hired_on'] ?? null,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'hr.staff.created', $org, ['staff_id' => $id]);

            return $id;
        });
    }

    public function dismiss(Organization $org, User $actor, int $staffId, string $date, string $reason): void
    {
        abort_unless($this->manages($org, $actor), 403);
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Record a dismissal reason.']);
        }
        DB::transaction(function () use ($org, $actor, $staffId, $date, $reason): void {
            $staff = DB::table('hr_staff')->where('organization_id', $org->id)->where('id', $staffId)->lockForUpdate()->first();
            abort_unless($staff !== null, 404);
            abort_unless($staff->status === 'active', 422);
            abort_if((int) $staff->user_id === $actor->id, 422);
            if ($staff->hired_on !== null && $date < $staff->hired_on) {
                throw ValidationException::withMessages(['dismissed_on' => 'Dismissal cannot predate hiring.']);
            }
            $member = User::whereKey($staff->user_id)->firstOrFail();
            DB::table('hr_staff')->where('id', $staff->id)->update(['status' => 'dismissed', 'dismissed_on' => $date, 'dismissal_reason' => trim($reason), 'updated_at' => now()]);
            $this->members->handle($org, $actor, $member);
            $this->audit->handle($org, $actor, 'hr.staff.dismissed', $member, ['staff_id' => $staff->id, 'dismissed_on' => $date]);
        });
    }

    /** @param array<string,mixed> $input */
    public function document(Organization $org, User $actor, int $staffId, array $input): void
    {
        abort_unless($this->manages($org, $actor), 403);
        DB::transaction(function () use ($org, $actor, $staffId, $input): void {
            abort_unless(DB::table('hr_staff')->where('organization_id', $org->id)->where('id', $staffId)->exists(), 404);
            DB::table('hr_staff_documents')->insert([
                'organization_id' => $org->id, 'staff_id' => $staffId, 'recorded_by' => $actor->id,
                'type' => $input['type'], 'expires_on' => $input['expires_on'],
                'reference_suffix' => $input['reference_suffix'] ?? null, 'notes' => $input['notes'] ?? null,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'hr.document.recorded', $org, ['staff_id' => $staffId, 'type' => $input['type']]);
        });
    }

    /** @param array<string,mixed> $input */
    public function leave(Organization $org, User $actor, int $staffId, array $input): int
    {
        $staff = DB::table('hr_staff')->where('organization_id', $org->id)->where('id', $staffId)->first();
        abort_unless($staff !== null && ($staff->user_id === $actor->id || $this->manages($org, $actor)), 403);
        abort_unless($staff->status === 'active', 422);

        return DB::transaction(function () use ($org, $actor, $staffId, $input): int {
            $overlap = DB::table('hr_leave_requests')->where('organization_id', $org->id)->where('staff_id', $staffId)
                ->whereIn('status', ['submitted', 'approved'])->where('starts_on', '<=', $input['ends_on'])->where('ends_on', '>=', $input['starts_on'])->exists();
            if ($overlap) {
                throw ValidationException::withMessages(['starts_on' => 'A pending or approved leave request overlaps these dates.']);
            }
            $id = DB::table('hr_leave_requests')->insertGetId([
                'organization_id' => $org->id, 'staff_id' => $staffId, 'requested_by' => $actor->id,
                'starts_on' => $input['starts_on'], 'ends_on' => $input['ends_on'], 'type' => $input['type'],
                'reason' => $input['reason'] ?? null, 'status' => 'submitted', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'hr.leave.submitted', $org, ['leave_id' => $id]);

            return $id;
        });
    }

    public function decide(Organization $org, User $actor, int $id, bool $approve, ?string $reason): void
    {
        abort_unless($this->manages($org, $actor), 403);
        DB::transaction(function () use ($org, $actor, $id, $approve, $reason): void {
            $leave = DB::table('hr_leave_requests')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($leave !== null, 404);
            abort_unless($leave->status === 'submitted' && $leave->requested_by !== $actor->id, 422);
            if (! $approve && blank($reason)) {
                throw ValidationException::withMessages(['reason' => 'A rejection reason is required.']);
            }
            DB::table('hr_leave_requests')->where('id', $id)->update([
                'status' => $approve ? 'approved' : 'rejected', 'decision_reason' => $reason,
                'decided_by' => $actor->id, 'decided_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, $approve ? 'hr.leave.approved' : 'hr.leave.rejected', $org, ['leave_id' => $id]);
        });
    }
}
