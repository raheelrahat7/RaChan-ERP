<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\LeadRequirement;
use App\Domain\Crm\Services\CrmEditPermission;
use App\Domain\Crm\Services\LeadRequirementSchema;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageLeadRequirements
{
    public function __construct(private LeadRequirementSchema $schema, private ManageLeadRequirementSettings $settings) {}

    /** @return array<string, mixed> */
    public function show(Organization $org, User $actor, int $leadId): array
    {
        $lead = $this->lead($org, $actor, $leadId);
        $record = LeadRequirement::where('organization_id', $org->id)->where('lead_id', $lead->id)->first();

        return ['requirement' => ['id' => $record?->id, 'lead_id' => $lead->id, 'version' => $record->version ?? 0,
            'data' => [...$this->schema->defaults(), ...($record->data ?? [])],
            'permissions' => ['read' => true, 'edit' => ! $lead->converted_at && app(CrmEditPermission::class)->granted($org, $actor)],
        ], 'configuration' => $this->settings->show($org, $actor)];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(Organization $org, User $actor, int $leadId, array $input): array
    {
        $this->lead($org, $actor, $leadId);
        abort_unless(app(CrmEditPermission::class)->granted($org, $actor), 403);
        $input = Validator::make($input, $this->schema->rules())->validate();

        return DB::transaction(function () use ($org, $actor, $leadId, $input): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $lead = $this->lead($org, $actor, $leadId, true);
            abort_unless(app(CrmEditPermission::class)->granted($org, $actor), 403);
            if ($lead->converted_at) {
                throw ValidationException::withMessages(['lead' => 'Converted lead requirements cannot be changed.']);
            }
            $record = LeadRequirement::where('organization_id', $org->id)->where('lead_id', $lead->id)->first();
            if (($record->version ?? 0) !== (int) $input['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'These requirements changed. Reload them and try again.']);
            }
            $before = [...$this->schema->defaults(), ...($record->data ?? [])];
            $data = [...$before, ...$this->schema->normalize($input['data'])];
            $this->validateValues($data, $before, $this->settings->show($org, $actor)['choices']);
            $record ??= new LeadRequirement(['organization_id' => $org->id, 'lead_id' => $lead->id, 'version' => 0]);
            $record->data = $data;
            $record->version++;
            $record->save();
            $changed = array_keys(array_filter($data, fn ($value, $key) => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.lead.requirements_updated', $lead, ['changed_fields' => $changed, 'version' => $record->version]);

            return $this->show($org, $actor, $leadId);
        });
    }

    /** @param array<string, mixed> $data
     * @param  array<string, mixed>  $before
     * @param  array<string, list<array{value: string, label: string, active: bool}>>  $choices
     */
    private function validateValues(array $data, array $before, array $choices): void
    {
        $errors = [];
        foreach ($choices as $field => $options) {
            $active = array_column(array_filter($options, fn ($option) => $option['active']), 'value');
            $values = $field === 'amenities' ? $data[$field] : array_filter([$data[$field]], fn ($value) => $value !== null);
            $previous = $field === 'amenities' ? $before[$field] : [$before[$field]];
            foreach ($values as $value) {
                if (! in_array($value, $active, true) && ! in_array($value, $previous, true)) {
                    $errors['data.'.$field] = 'Select an active organization option.';
                }
            }
        }
        foreach (['bedrooms', 'size', 'budget'] as $range) {
            $min = $data[$range.'_min'];
            $max = $data[$range.'_max'];
            if ($min !== null && $max !== null && (int) str_replace('.', '', (string) $min) > (int) str_replace('.', '', (string) $max)) {
                $errors['data.'.$range.'_max'] = 'The maximum must be greater than or equal to the minimum.';
            }
        }
        foreach (['size' => 'size_unit', 'budget' => 'budget_currency'] as $range => $unit) {
            if (($data[$range.'_min'] !== null || $data[$range.'_max'] !== null) && $data[$unit] === null) {
                $errors['data.'.$unit] = 'Specify the unit or currency for this range.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function lead(Organization $org, User $actor, int $leadId, bool $lock = false): CrmLead
    {
        Gate::forUser($actor)->authorize('viewCrm', $org);
        $lead = CrmLead::where('organization_id', $org->id)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($leadId);
        abort_unless(app(LeadVisibility::class)->canSeeLead($org, $actor, $lead->assigned_to), 404);

        return $lead;
    }
}
