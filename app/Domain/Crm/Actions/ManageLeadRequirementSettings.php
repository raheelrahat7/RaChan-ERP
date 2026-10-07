<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\LeadRequirement;
use App\Domain\Crm\Models\LeadRequirementSetting;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Crm\Services\LeadRequirementSchema;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageLeadRequirementSettings
{
    public function __construct(private LeadRequirementSchema $schema) {}

    /** @return array<string, mixed> */
    public function show(Organization $org, User $actor): array
    {
        Gate::forUser($actor)->authorize('viewCrm', $org);
        $record = LeadRequirementSetting::where('organization_id', $org->id)->first();

        return ['id' => $record?->id, 'version' => $record->version ?? 0, 'choices' => $record->choices ?? $this->schema->defaultChoices(), 'permissions' => ['read' => true, 'edit' => app(DealAccess::class)->administrator($org, $actor)]];
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
            'choices' => ['required', 'array:'.implode(',', array_keys(LeadRequirementSchema::CHOICES)), 'min:1'],
            'choices.*' => ['present', 'array', 'list', 'max:200'],
            'choices.*.*' => ['array:value,label,active'],
            'choices.*.*.value' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'choices.*.*.label' => ['required', 'string', 'max:120'],
            'choices.*.*.active' => ['required', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($org, $actor, $data): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $record = LeadRequirementSetting::where('organization_id', $org->id)->first();
            if (($record->version ?? 0) !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'Requirement options changed. Reload them and try again.']);
            }
            $choices = $record->choices ?? $this->schema->defaultChoices();
            foreach ($data['choices'] as $field => $options) {
                $codes = array_column($options, 'value');
                if (count($codes) !== count(array_unique($codes))) {
                    throw ValidationException::withMessages(['choices.'.$field => 'Option values must be unique within this field.']);
                }
                foreach (array_diff(array_column($choices[$field], 'value'), $codes) as $removed) {
                    if (LeadRequirement::where('organization_id', $org->id)->whereJsonContains('data->'.$field, $removed)->exists()) {
                        throw ValidationException::withMessages(['choices.'.$field => 'This option is in use. Keep its value and set active to false instead.']);
                    }
                }
                $choices[$field] = array_map(fn ($option) => [...$option, 'active' => (bool) $option['active']], $options);
            }
            $record ??= new LeadRequirementSetting(['organization_id' => $org->id, 'version' => 0]);
            $record->choices = $choices;
            $record->version++;
            $record->save();
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.lead_requirement_settings.updated', $record, ['changed_fields' => array_keys($data['choices']), 'version' => $record->version]);

            return $this->show($org, $actor);
        });
    }
}
