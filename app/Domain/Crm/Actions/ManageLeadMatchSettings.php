<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\LeadListingMatch;
use App\Domain\Crm\Models\LeadMatchSetting;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageLeadMatchSettings
{
    public const DEFAULT_STATUSES = [
        ['value' => 'not_scheduled', 'label' => 'Not scheduled', 'active' => true],
        ['value' => 'scheduled', 'label' => 'Scheduled', 'active' => true],
        ['value' => 'completed', 'label' => 'Completed', 'active' => true],
        ['value' => 'cancelled', 'label' => 'Cancelled', 'active' => true],
        ['value' => 'no_show', 'label' => 'No show', 'active' => true],
    ];

    /** @return array<string, mixed> */
    public function show(Organization $org, User $actor): array
    {
        Gate::forUser($actor)->authorize('viewCrm', $org);
        $record = LeadMatchSetting::where('organization_id', $org->id)->first();

        return ['id' => $record?->id, 'version' => $record->version ?? 0,
            'viewing_statuses' => $record->viewing_statuses ?? self::DEFAULT_STATUSES,
            'permissions' => ['read' => true, 'edit' => app(DealAccess::class)->administrator($org, $actor)]];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(Organization $org, User $actor, array $input): array
    {
        Gate::forUser($actor)->authorize('viewCrm', $org);
        abort_unless(app(DealAccess::class)->administrator($org, $actor), 403);
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:0'],
            'viewing_statuses' => ['required', 'array', 'list', 'min:1', 'max:100'],
            'viewing_statuses.*' => ['required', 'array:value,label,active'],
            'viewing_statuses.*.value' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'viewing_statuses.*.label' => ['required', 'string', 'max:120'],
            'viewing_statuses.*.active' => ['required', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($org, $actor, $data): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $record = LeadMatchSetting::where('organization_id', $org->id)->first();
            if (($record->version ?? 0) !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'Viewing statuses changed. Reload them and try again.']);
            }
            $codes = array_column($data['viewing_statuses'], 'value');
            if (count($codes) !== count(array_unique($codes))) {
                throw ValidationException::withMessages(['viewing_statuses' => 'Viewing status values must be unique.']);
            }
            $existing = $record->viewing_statuses ?? self::DEFAULT_STATUSES;
            foreach (array_diff(array_column($existing, 'value'), $codes) as $removed) {
                if (LeadListingMatch::where('organization_id', $org->id)->where('viewing_status', $removed)->exists()) {
                    throw ValidationException::withMessages(['viewing_statuses' => 'This status is in use. Set active to false instead of removing it.']);
                }
            }
            $record ??= new LeadMatchSetting(['organization_id' => $org->id, 'version' => 0]);
            $record->viewing_statuses = array_map(fn ($option) => [...$option, 'active' => (bool) $option['active']], $data['viewing_statuses']);
            $record->version++;
            $record->save();
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.lead_match_settings.updated', $record, ['version' => $record->version]);

            return $this->show($org, $actor);
        });
    }
}
