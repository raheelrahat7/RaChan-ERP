<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageAppointment
{
    public function __construct(private LeadVisibility $visibility, private ManageLeadPipeline $pipeline, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string, mixed> $input */
    public function create(Organization $org, User $actor, array $input): int
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        $this->validateLinks($org, $actor, $input);

        return DB::transaction(function () use ($org, $actor, $input): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $id = DB::table('brokerage_appointments')->insertGetId([
                'organization_id' => $org->id, 'created_by' => $actor->id, 'assigned_to' => $input['assigned_to'],
                'lead_id' => $input['lead_id'] ?? null, 'listing_id' => $input['listing_id'] ?? null,
                'cost_centre_id' => $input['cost_centre_id'] ?? null,
                'type' => $input['type'], 'title' => $input['title'], 'starts_at' => CarbonImmutable::parse($input['starts_at'])->utc()->toDateTimeString(),
                'ends_at' => CarbonImmutable::parse($input['ends_at'])->utc()->toDateTimeString(), 'location' => $input['location'] ?? null,
                'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'crm.appointment.created', $org, ['appointment_id' => $id]);

            return $id;
        });
    }

    /** @param array<string, mixed> $input */
    public function outcome(Organization $org, User $actor, int $id, array $input): void
    {
        DB::transaction(function () use ($org, $actor, $id, $input): void {
            $appointment = DB::table('brokerage_appointments')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($appointment !== null, 404);
            abort_unless($actor->id === $appointment->assigned_to || $actor->can('manageCrm', $org), 403);
            if ($appointment->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Only scheduled meetings can be completed.']);
            }
            $this->checkVersion((int) $appointment->version, $input['expected_version'] ?? null);
            if (isset($input['stage_id'])) {
                abort_unless($actor->can('manageCrm', $org), 403);
                if ($appointment->lead_id === null) {
                    throw ValidationException::withMessages(['stage_id' => 'Only an appointment linked to a lead can move its stage.']);
                }
                $lead = CrmLead::where('organization_id', $org->id)->whereKey($appointment->lead_id)->firstOrFail();
                abort_unless($this->visibility->canSeeLead($org, $actor, $lead->assigned_to), 404);
                $this->pipeline->move($org, $actor, $lead, ['stage_id' => $input['stage_id'], 'expected_stage_id' => $input['expected_stage_id']]);
            }
            DB::table('brokerage_appointments')->where('id', $id)->update(['status' => 'completed', 'outcome' => $input['outcome'], 'completed_at' => now(), 'version' => $appointment->version + 1, 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'crm.appointment.completed', $org, ['appointment_id' => $id, 'lead_stage_changed' => isset($input['stage_id'])]);
        });
    }

    /** @param array<string, mixed> $input */
    public function update(Organization $org, User $actor, int $id, array $input): void
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        DB::transaction(function () use ($org, $actor, $id, $input): void {
            $appointment = DB::table('brokerage_appointments')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($appointment !== null, 404);
            $this->checkVersion((int) $appointment->version, $input['expected_version']);
            if ($appointment->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Only scheduled meetings can be edited.']);
            }
            $data = array_intersect_key($input, array_flip(['type', 'title', 'assigned_to', 'starts_at', 'ends_at', 'location', 'listing_id', 'cost_centre_id']));
            $data['lead_id'] = $appointment->lead_id;
            if (! array_key_exists('listing_id', $data)) {
                $data['listing_id'] = $appointment->listing_id;
            }
            if (! array_key_exists('cost_centre_id', $data)) {
                $data['cost_centre_id'] = $appointment->cost_centre_id;
            }
            $this->validateLinks($org, $actor, $data + ['assigned_to' => $appointment->assigned_to]);
            $starts = $data['starts_at'] ?? $appointment->starts_at;
            $ends = $data['ends_at'] ?? $appointment->ends_at;
            if (strtotime((string) $ends) <= strtotime((string) $starts)) {
                throw ValidationException::withMessages(['ends_at' => 'The end must be after the start.']);
            }
            unset($data['lead_id']);
            foreach (['starts_at', 'ends_at'] as $field) {
                if (isset($data[$field])) {
                    $data[$field] = CarbonImmutable::parse($data[$field])->utc()->toDateTimeString();
                }
            }
            DB::table('brokerage_appointments')->where('id', $id)->update($data + ['version' => $appointment->version + 1, 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'crm.appointment.updated', $org, ['appointment_id' => $id]);
        });
    }

    public function cancel(Organization $org, User $actor, int $id, int $expectedVersion): void
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        DB::transaction(function () use ($org, $actor, $id, $expectedVersion): void {
            $appointment = DB::table('brokerage_appointments')->where('organization_id', $org->id)->where('id', $id)->lockForUpdate()->first();
            abort_unless($appointment !== null, 404);
            $this->checkVersion((int) $appointment->version, $expectedVersion);
            if ($appointment->status !== 'scheduled') {
                throw ValidationException::withMessages(['status' => 'Only scheduled meetings can be cancelled.']);
            }
            DB::table('brokerage_appointments')->where('id', $id)->update(['status' => 'cancelled', 'version' => $appointment->version + 1, 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'crm.appointment.cancelled', $org, ['appointment_id' => $id]);
        });
    }

    private function checkVersion(int $current, ?int $expected): void
    {
        if ($expected !== null && $current !== $expected) {
            throw ValidationException::withMessages(['expected_version' => 'This meeting changed. Refresh before editing it.']);
        }
    }

    /** @param array<string, mixed> $input */
    private function validateLinks(Organization $org, User $actor, array $input): void
    {
        $assignee = $org->users()->whereKey($input['assigned_to'])->first();
        if (! $assignee) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose an organization member.']);
        }
        if (isset($input['lead_id'])) {
            $lead = CrmLead::where('organization_id', $org->id)->whereKey($input['lead_id'])->first();
            if (! $lead || ! $this->visibility->canSeeLead($org, $actor, $lead->assigned_to) || ! $this->visibility->canSeeLead($org, $assignee, $lead->assigned_to)) {
                throw ValidationException::withMessages(['lead_id' => 'Choose a lead visible to both users in this organization.']);
            }
        }
        foreach (['listing_id' => 'listings', 'cost_centre_id' => 'accounting_cost_centres'] as $field => $table) {
            if (isset($input[$field]) && ! DB::table($table)->where('organization_id', $org->id)->where('id', $input[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Choose a record in this organization.']);
            }
        }
        if (isset($input['listing_id']) && (! $actor->can('viewInventory', $org) || ! $assignee->can('viewInventory', $org))) {
            throw ValidationException::withMessages(['listing_id' => 'Choose a listing visible to both users in this organization.']);
        }
    }
}
