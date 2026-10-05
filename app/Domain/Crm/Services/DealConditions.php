<?php

namespace App\Domain\Crm\Services;

use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Models\Deal;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DealConditions
{
    public function __construct(private ManageCustomFields $fields) {}

    /** @return list<array<string, mixed>> */
    public function catalog(Organization $org, User $actor): array
    {
        return array_values(array_map(fn ($field) => ['key' => $field->key, 'name' => $field->name, 'type' => $field->type, 'options' => $field->options, 'operators' => $this->operators($field->type)], array_filter($this->fields->visible($org, $actor, false, 'deal'), fn ($field) => $field->show_in_filter)));
    }

    /** @param array<mixed> $conditions
     * @return list<array<string, mixed>> */
    public function validate(Organization $org, User $actor, array $conditions): array
    {
        $data = Validator::make(['conditions' => $conditions], ['conditions' => ['array', 'max:20'], 'conditions.*' => ['array:field,operator,value'], 'conditions.*.field' => ['required', 'string', 'max:80'], 'conditions.*.operator' => ['required', 'string'], 'conditions.*.value' => ['sometimes']])->validate()['conditions'];
        $visible = collect($this->fields->visible($org, $actor, false, 'deal'))->keyBy('key');
        foreach ($data as $index => &$condition) {
            $field = $visible->get($condition['field']);
            if (! $field || ! $field->show_in_filter || ! in_array($condition['operator'], $this->operators($field->type), true)) {
                throw ValidationException::withMessages(['conditions.'.$index => 'Choose an available field and supported operator.']);
            }
            if (! in_array($condition['operator'], ['empty', 'not_empty'], true)) {
                if (! array_key_exists('value', $condition) || $condition['value'] === null || $condition['value'] === '') {
                    throw ValidationException::withMessages(['conditions.'.$index.'.value' => 'Provide a comparison value.']);
                }
                $condition['value'] = $this->fields->normalize($org, $field, $condition['value']);
            }
        }
        unset($condition);

        return array_values($data);
    }

    /** @param Builder<Deal> $query
     * @param array<array<string, mixed>> $conditions */
    public function apply(Builder $query, Organization $org, User $actor, array $conditions): void
    {
        $conditions = $this->validate($org, $actor, $conditions);
        $fields = collect($this->fields->visible($org, $actor, false, 'deal'))->keyBy('key');
        foreach ($conditions as $condition) {
            $field = $fields->get($condition['field']);
            $operator = $condition['operator'];
            $sub = function ($value) use ($org, $field, $operator, $condition): void {
                $value->selectRaw('1')->from('crm_record_field_values as cf')->whereColumn('cf.record_id', 'crm_deals.id')->where('cf.organization_id', $org->id)->where('cf.entity', 'deal')->where('cf.field_id', $field->id);
                if (in_array($operator, ['empty', 'not_empty'], true)) {
                    $value->whereRaw("JSON_TYPE(cf.value) <> 'NULL' AND JSON_UNQUOTE(cf.value) <> '' AND cf.value <> JSON_ARRAY()");
                } elseif ($field->type === 'multi_select') {
                    foreach ($condition['value'] as $selected) {
                        $value->whereJsonContains('cf.value', $selected);
                    }
                } elseif (in_array($field->type, ['number', 'currency', 'user'], true)) {
                    $sqlOperator = match ($operator) {
                        'gte' => '>=', 'lte' => '<=', 'not_equals' => '<>', default => '='
                    };
                    $value->whereRaw('CAST(JSON_UNQUOTE(cf.value) AS DECIMAL(24,4)) '.$sqlOperator.' ?', [$condition['value']]);
                } elseif ($operator === 'contains') {
                    $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $condition['value']).'%';
                    $value->whereRaw('JSON_UNQUOTE(cf.value) LIKE ?', [$term]);
                } else {
                    $value->whereRaw('JSON_UNQUOTE(cf.value) '.match ($operator) {
                        'gte' => '>=', 'lte' => '<=', 'not_equals' => '<>', default => '='
                    }.' ?', [$field->type === 'checkbox' ? ($condition['value'] ? 'true' : 'false') : $condition['value']]);
                }
            };
            $operator === 'empty' ? $query->whereNotExists($sub) : $query->whereExists($sub);
        }
    }

    /** @return list<string> */
    private function operators(string $type): array
    {
        return match ($type) {
            'number', 'currency', 'date', 'datetime', 'user' => ['equals', 'not_equals', 'gte', 'lte', 'empty', 'not_empty'],
            'multi_select' => ['equals', 'empty', 'not_empty'],
            'checkbox', 'single_select' => ['equals', 'not_equals', 'empty', 'not_empty'],
            default => ['equals', 'not_equals', 'contains', 'empty', 'not_empty'],
        };
    }
}
