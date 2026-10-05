<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\CustomField;
use App\Domain\Crm\Models\CustomFieldValue;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ManageCustomFields
{
    public const TYPES = ['text', 'long_text', 'number', 'currency', 'date', 'datetime', 'checkbox', 'single_select', 'multi_select', 'phone', 'email', 'url', 'user'];

    private const BUILTIN = ['id', 'lead_name', 'name', 'first_name', 'last_name', 'email', 'phone', 'company', 'company_name', 'source', 'status', 'stage', 'pipeline', 'notes', 'city', 'assigned_to', 'created_at', 'updated_at', 'created_by', 'modified_by'];

    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input */
    public function save(Organization $org, User $actor, array $input, ?CustomField $field = null): CustomField
    {
        $this->configure($org, $actor);
        $values = Validator::make($input, [
            'name' => ['required', 'string', 'max:120'],
            'key' => [$field ? 'sometimes' : 'required', 'alpha_dash:ascii', 'max:80'],
            'type' => [$field ? 'sometimes' : 'required', 'in:'.implode(',', self::TYPES)],
            'options' => ['nullable', 'array', 'max:100'],
            'options.*' => ['string', 'max:120', 'distinct'],
            'required' => ['sometimes', 'boolean'],
            'active' => ['sometimes', 'boolean'],
            'view_roles' => ['nullable', 'array'],
            'view_roles.*' => ['in:owner,administrator,manager,member,viewer', 'distinct'],
            'edit_roles' => ['nullable', 'array'],
            'edit_roles.*' => ['in:owner,administrator,manager,member,viewer', 'distinct'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:10000'],
        ])->validate();
        if ($field) {
            abort_unless($field->organization_id === $org->id, 404);
            if (isset($values['key']) && $values['key'] !== $field->key) {
                throw ValidationException::withMessages(['key' => 'The internal key cannot change; rename the field label instead.']);
            }
            if (isset($values['type']) && $values['type'] !== $field->type && CustomFieldValue::where('field_id', $field->id)->exists()) {
                throw ValidationException::withMessages(['type' => 'A field with saved values cannot change type.']);
            }
        }
        $key = strtolower((string) ($values['key'] ?? $field?->key));
        if (in_array($key, self::BUILTIN, true) || CustomField::where('organization_id', $org->id)->where('key', $key)->when($field, fn ($q) => $q->whereKeyNot($field->id))->exists()) {
            throw ValidationException::withMessages(['key' => 'Choose a unique key that does not replace a built-in field.']);
        }
        $type = $values['type'] ?? $field?->type;
        $options = $values['options'] ?? ($field ? $field->options : []);
        if (in_array($type, ['single_select', 'multi_select'], true) && count($options) === 0) {
            throw ValidationException::withMessages(['options' => 'Select fields need at least one option.']);
        }
        if (isset($values['edit_roles']) && isset($values['view_roles']) && array_diff($values['edit_roles'], $values['view_roles'])) {
            throw ValidationException::withMessages(['edit_roles' => 'Editing roles must also have viewing access.']);
        }
        $values['key'] = $key;
        $values['options'] = $options;
        $values['view_roles'] = $values['view_roles'] ?? $field?->view_roles;
        $values['edit_roles'] = $values['edit_roles'] ?? $field?->edit_roles;
        if ($values['edit_roles'] !== null && $values['view_roles'] !== null && array_diff($values['edit_roles'], $values['view_roles'])) {
            throw ValidationException::withMessages(['edit_roles' => 'Editing roles must also have viewing access.']);
        }
        $before = $field?->only(['name', 'type', 'options', 'required', 'active', 'view_roles', 'edit_roles']);
        if ($field) {
            $field->update($values);
        } else {
            $field = CustomField::create(['organization_id' => $org->id, ...$values]);
        }
        $this->audit->handle($org, $actor, $before ? 'crm.field.updated' : 'crm.field.created', $field, ['key' => $key, 'before' => $before]);

        return $field;
    }

    public function archive(Organization $org, User $actor, CustomField $field): void
    {
        $this->configure($org, $actor);
        abort_unless($field->organization_id === $org->id, 404);
        $field->update(['active' => false]);
        $this->audit->handle($org, $actor, 'crm.field.archived', $field, ['key' => $field->key]);
    }

    /** @return array<int, CustomField> */
    public function visible(Organization $org, User $actor, bool $editable = false): array
    {
        $role = $this->role($org, $actor);

        return CustomField::where('organization_id', $org->id)->where('active', true)->orderBy('sort_order')->orderBy('id')->get()
            ->filter(fn (CustomField $field) => $this->allowed($field, $role, $editable))->values()->all();
    }

    /** @param array<string,mixed> $input */
    public function writeValues(Organization $org, User $actor, CrmLead $lead, array $input, bool $creating): void
    {
        abort_unless($lead->organization_id === $org->id, 404);
        $fields = collect($this->visible($org, $actor, true))->keyBy('key');
        foreach ($input as $key => $value) {
            if (! $fields->has($key)) {
                throw ValidationException::withMessages(['custom_fields.'.$key => 'This field is unavailable for editing.']);
            }
        }
        foreach ($fields as $field) {
            if (! array_key_exists($field->key, $input)) {
                if ($creating && $field->required) {
                    throw ValidationException::withMessages(['custom_fields.'.$field->key => 'This field is required.']);
                }

                continue;
            }
            $value = $input[$field->key];
            $normal = $this->normalize($org, $field, $value);
            if ($field->required && $normal === null) {
                throw ValidationException::withMessages(['custom_fields.'.$field->key => 'This field is required.']);
            }
            $previous = CustomFieldValue::where('organization_id', $org->id)->where('lead_id', $lead->id)->where('field_id', $field->id)->first();
            $previousValue = $previous?->value;
            if ($normal === null) {
                $previous?->delete();
                if ($previous !== null) {
                    $this->audit->handle($org, $actor, 'crm.lead.custom_field_changed', $lead, ['field_key' => $field->key]);
                }

                continue;
            }
            $search = is_array($normal) ? implode(' ', $normal) : (is_scalar($normal) ? (string) $normal : null);
            $data = ['value' => $normal, 'search_text' => $search === null ? null : Str::limit($search, 500, ''),
                'number_value' => in_array($field->type, ['number', 'currency'], true) ? $normal : null,
                'date_value' => in_array($field->type, ['date', 'datetime'], true) ? $normal : null,
                'user_value' => $field->type === 'user' ? $normal : null];
            if ($previous) {
                $previous->update($data);
            } else {
                CustomFieldValue::create(['organization_id' => $org->id, 'lead_id' => $lead->id, 'field_id' => $field->id, ...$data]);
            }
            if ($previousValue !== $normal) {
                $this->audit->handle($org, $actor, 'crm.lead.custom_field_changed', $lead, ['field_key' => $field->key]);
            }
        }
    }

    private function normalize(Organization $org, CustomField $field, mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $type = $field->type;
        $rules = match ($type) {
            'text', 'phone' => ['string', 'max:255'],
            'long_text' => ['string', 'max:5000'],
            'email' => ['email', 'max:255'],
            'url' => ['url', 'max:2048'],
            'number', 'currency' => ['numeric', 'between:-99999999999999,99999999999999'],
            'date' => ['date_format:Y-m-d'],
            'datetime' => ['date'],
            'checkbox' => ['boolean'],
            'single_select' => ['string', Rule::in($field->options ?? [])],
            'multi_select' => ['array', 'max:100'],
            'user' => ['integer'],
            default => ['prohibited'],
        };
        Validator::make(['value' => $value], ['value' => $rules])->validate();
        if ($type === 'multi_select') {
            Validator::make(['value' => $value], ['value.*' => ['string', 'distinct', Rule::in($field->options ?? [])]])->validate();
            $value = array_values($value);
        }
        if ($type === 'user' && ! $org->users()->where('users.id', (int) $value)->exists()) {
            throw ValidationException::withMessages(['value' => 'Choose an organization member.']);
        }

        if ($type === 'datetime') {
            $value = CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
        }

        return $value;
    }

    private function allowed(CustomField $field, string $role, bool $editable): bool
    {
        $roles = $editable ? $field->edit_roles : $field->view_roles;

        return $roles === null || in_array($role, $roles, true);
    }

    private function role(Organization $org, User $actor): string
    {
        return (string) $actor->organizations()->whereKey($org->id)->value('organization_user.role');
    }

    private function configure(Organization $org, User $actor): void
    {
        abort_unless($actor->hasOrganizationRole($org, OrganizationRole::Owner) || $actor->hasOrganizationRole($org, OrganizationRole::Administrator), 403);
    }
}
