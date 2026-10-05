<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\Deal;
use App\Domain\Crm\Models\RecordFieldValue;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManageRecordFields
{
    public function __construct(private ManageCustomFields $fields, private DealAccess $access, private RecordOrganizationAuditLog $audit) {}

    /** @return list<array<string, mixed>> */
    public function values(Organization $org, User $actor, Deal|CrmContact|CrmAccount $record): array
    {
        $entity = $this->entity($record);
        abort_unless($record->organization_id === $org->id && $actor->can('viewCrm', $org), 404);
        if ($record instanceof Deal) {
            abort_unless($this->access->allows($org, $actor, $record->pipeline, 'read', $record->assigned_to), 404);
        }
        $values = RecordFieldValue::where('organization_id', $org->id)->where('entity', $entity)->where('record_id', $record->id)->get()->keyBy('field_id');
        $editable = array_column($this->fields->visible($org, $actor, true, $entity), 'key');

        return array_values(array_map(fn ($field) => [...$field->only('key', 'name', 'type', 'required', 'options', 'tooltip', 'show_in_list', 'show_in_filter'), 'value' => $values->get($field->id)?->value, 'editable' => in_array($field->key, $editable, true)], $this->fields->visible($org, $actor, false, $entity)));
    }

    public function validateRequired(Organization $org, User $actor, Deal|CrmContact|CrmAccount $record): void
    {
        $entity = $this->entity($record);
        foreach ($this->fields->visible($org, $actor, true, $entity) as $field) {
            if ($field->required && ! RecordFieldValue::where('organization_id', $org->id)->where('entity', $entity)->where('record_id', $record->id)->where('field_id', $field->id)->exists()) {
                throw ValidationException::withMessages(['custom_fields.'.$field->key => 'This field is required.']);
            }
        }
    }

    /** @param array<string, mixed> $input */
    public function write(Organization $org, User $actor, Deal|CrmContact|CrmAccount $record, array $input, bool $creating = false): void
    {
        $entity = $this->entity($record);
        abort_unless($record->organization_id === $org->id, 404);
        if ($record instanceof Deal) {
            abort_unless($this->access->allows($org, $actor, $record->pipeline, $creating ? 'add' : 'edit', $record->assigned_to), 403);
        } else {
            abort_unless($actor->can('manageCrm', $org), 403);
        }
        DB::transaction(function () use ($org, $actor, $record, $input, $creating, $entity): void {
            $fields = collect($this->fields->visible($org, $actor, true, $entity))->keyBy('key');
            foreach ($input as $key => $value) {
                if (! $fields->has($key)) {
                    throw ValidationException::withMessages(['custom_fields.'.$key => 'This field is unavailable for editing.']);
                }
            }
            foreach ($fields as $field) {
                $previous = RecordFieldValue::where('organization_id', $org->id)->where('entity', $entity)->where('record_id', $record->id)->where('field_id', $field->id)->first();
                if (! array_key_exists($field->key, $input)) {
                    if ($field->required && ($creating || $previous === null)) {
                        throw ValidationException::withMessages(['custom_fields.'.$field->key => 'This field is required.']);
                    }

                    continue;
                }
                $value = $this->fields->normalize($org, $field, $input[$field->key]);
                if ($field->required && ($value === null || $value === [])) {
                    throw ValidationException::withMessages(['custom_fields.'.$field->key => 'This field is required.']);
                }
                if ($value === null) {
                    $previous?->delete();
                } else {
                    RecordFieldValue::updateOrCreate(['organization_id' => $org->id, 'entity' => $entity, 'record_id' => $record->id, 'field_id' => $field->id], ['value' => $value]);
                }
                if ($previous?->value !== $value) {
                    $this->audit->handle($org, $actor, 'crm.'.$entity.'.custom_field_changed', $record, ['field_key' => $field->key]);
                }
            }
        });
    }

    private function entity(Deal|CrmContact|CrmAccount $record): string
    {
        return match (true) {
            $record instanceof Deal => 'deal', $record instanceof CrmContact => 'contact', default => 'company'
        };
    }
}
