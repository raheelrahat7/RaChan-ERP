<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\LeadCommercialRecord;
use App\Domain\Crm\Models\LeadCommercialSetting;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageLeadCommercialRecords
{
    public const DEFAULT_STATUSES = [
        'offer' => [
            ['value' => 'draft', 'label' => 'Draft', 'active' => true],
            ['value' => 'submitted', 'label' => 'Submitted', 'active' => true],
            ['value' => 'accepted', 'label' => 'Accepted', 'active' => true],
            ['value' => 'rejected', 'label' => 'Rejected', 'active' => true],
            ['value' => 'withdrawn', 'label' => 'Withdrawn', 'active' => true],
        ],
        'contract' => [
            ['value' => 'draft', 'label' => 'Draft', 'active' => true],
            ['value' => 'sent', 'label' => 'Sent', 'active' => true],
            ['value' => 'signed', 'label' => 'Signed', 'active' => true],
            ['value' => 'void', 'label' => 'Void', 'active' => true],
        ],
    ];

    public function __construct(private LeadVisibility $visibility, private DealAccess $deals, private RecordOrganizationAuditLog $audit) {}

    /** @return array<string, mixed> */
    public function settings(Organization $org, User $actor): array
    {
        abort_unless($actor->can('viewCrm', $org), 403);
        $setting = LeadCommercialSetting::where('organization_id', $org->id)->first();

        return ['statuses' => $setting ? $setting->statuses : self::DEFAULT_STATUSES, 'version' => $setting ? $setting->version : 0, 'permissions' => ['read' => true, 'edit' => $this->deals->administrator($org, $actor)]];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed> */
    public function saveSettings(Organization $org, User $actor, array $input): array
    {
        abort_unless($this->deals->administrator($org, $actor), 403);
        $data = Validator::make($input, [
            'expected_version' => ['required', 'integer', 'min:0'],
            'statuses' => ['required', 'array:offer,contract'],
            'statuses.offer' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'statuses.contract' => ['required', 'array', 'list', 'min:1', 'max:30'],
            'statuses.*.*' => ['required', 'array:value,label,active'],
            'statuses.*.*.value' => ['required', 'string', 'max:60', 'regex:/^[a-z][a-z0-9_]*$/'],
            'statuses.*.*.label' => ['required', 'string', 'max:120'],
            'statuses.*.*.active' => ['required', 'boolean'],
        ])->validate();

        return DB::transaction(function () use ($org, $actor, $data): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $setting = LeadCommercialSetting::where('organization_id', $org->id)->lockForUpdate()->first();
            if (($setting ? $setting->version : 0) !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'Offer and contract settings changed. Refresh before editing.']);
            }
            foreach (['offer', 'contract'] as $kind) {
                $options = $data['statuses'][$kind];
                $values = array_column($options, 'value');
                if (count($values) !== count(array_unique($values)) || ! in_array(true, array_column($options, 'active'), true)) {
                    throw ValidationException::withMessages(['statuses.'.$kind => 'Use unique values and keep at least one active status.']);
                }
                $inUse = LeadCommercialRecord::where('organization_id', $org->id)->where('kind', $kind)->distinct()->pluck('status')->all();
                if (array_diff($inUse, $values) !== []) {
                    throw ValidationException::withMessages(['statuses.'.$kind => 'Keep statuses already used by records; deactivate them instead.']);
                }
            }
            $setting ??= new LeadCommercialSetting(['organization_id' => $org->id, 'version' => 0]);
            $setting->statuses = $data['statuses'];
            $setting->version++;
            $setting->save();
            $this->audit->handle($org, $actor, 'crm.lead_commercial_settings.updated', $setting, ['version' => $setting->version]);

            return $this->settings($org, $actor);
        });
    }

    /** @param array<string, mixed> $input */
    public function save(Organization $org, User $actor, CrmLead $lead, array $input, ?int $id = null): LeadCommercialRecord
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        $data = Validator::make($input, [
            'expected_version' => [$id ? 'required' : 'prohibited', 'integer', 'min:1'],
            'kind' => [$id ? 'sometimes' : 'required', 'in:offer,contract'],
            'title' => [$id ? 'sometimes' : 'required', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:100'],
            'party_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => [$id ? 'sometimes' : 'required', 'string', 'max:60'],
            'amount' => ['sometimes', 'nullable', 'regex:/^\d{1,13}(?:\.\d{1,2})?$/'],
            'currency' => ['sometimes', 'nullable', 'regex:/^[A-Z]{3}$/'],
            'submitted_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'signed_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'deal_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();

        return DB::transaction(function () use ($org, $actor, $lead, $data, $id): LeadCommercialRecord {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $lead = CrmLead::where('organization_id', $org->id)->lockForUpdate()->findOrFail($lead->id);
            abort_unless($this->visibility->canSeeLead($org, $actor, $lead->assigned_to), 404);
            $record = $id ? LeadCommercialRecord::where('organization_id', $org->id)->where('lead_id', $lead->id)->lockForUpdate()->findOrFail($id) : new LeadCommercialRecord(['organization_id' => $org->id, 'lead_id' => $lead->id, 'created_by' => $actor->id]);
            if ($id && $record->version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'This record changed. Refresh before editing.']);
            }
            if ($id && isset($data['kind']) && $data['kind'] !== $record->kind) {
                throw ValidationException::withMessages(['kind' => 'The record type cannot change.']);
            }
            $kind = $record->kind ?? $data['kind'];
            unset($data['expected_version'], $data['kind']);
            if (! $id && $lead->converted_at !== null) {
                throw ValidationException::withMessages(['lead' => 'Converted leads cannot receive new offer or contract records.']);
            }
            $status = $data['status'] ?? $record->status;
            $statuses = $this->settings($org, $actor)['statuses'][$kind];
            $validStatus = false;
            foreach ($statuses as $option) {
                $validStatus = $validStatus || ($option['value'] === $status && ($option['active'] || ($id && $record->status === $status)));
            }
            if (! $validStatus) {
                throw ValidationException::withMessages(['status' => 'Choose an active status for this record type.']);
            }
            $dealId = array_key_exists('deal_id', $data) ? $data['deal_id'] : $record->deal_id;
            if ($dealId !== null) {
                $deal = Deal::where('organization_id', $org->id)->where('lead_id', $lead->id)->whereKey($dealId)->first();
                if (! $deal || ! $this->deals->allows($org, $actor, $deal->pipeline, 'read', $deal->assigned_to)) {
                    throw ValidationException::withMessages(['deal_id' => 'Choose a visible deal linked to this lead.']);
                }
            }
            $amount = array_key_exists('amount', $data) ? $data['amount'] : $record->amount;
            $currency = array_key_exists('currency', $data) ? $data['currency'] : $record->currency;
            if (($amount === null) !== ($currency === null)) {
                throw ValidationException::withMessages(['currency' => 'Amount and currency must be supplied together.']);
            }
            $record->fill($data);
            $record->kind = $kind;
            $record->version = $id ? $record->version + 1 : 1;
            $record->save();
            $this->audit->handle($org, $actor, $id ? 'crm.lead_commercial_record.updated' : 'crm.lead_commercial_record.created', $record, ['lead_id' => $lead->id, 'kind' => $kind, 'version' => $record->version]);

            return $record->refresh();
        });
    }
}
