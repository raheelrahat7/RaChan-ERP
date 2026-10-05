<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Models\CustomField;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LeadFilters
{
    private const BUILTIN = [
        'id' => ['label' => 'ID', 'type' => 'number'],
        'first_name' => ['label' => 'Name', 'type' => 'text'],
        'last_name' => ['label' => 'Last name', 'type' => 'text'],
        'lead_name' => ['label' => 'Lead Name', 'type' => 'text'],
        'source' => ['label' => 'Source', 'type' => 'text'],
        'company' => ['label' => 'Company name', 'type' => 'text'],
        'city' => ['label' => 'City', 'type' => 'text'],
        'email' => ['label' => 'Email', 'type' => 'text'],
        'phone' => ['label' => 'Phone', 'type' => 'text'],
        'project_name' => ['label' => 'Project name', 'type' => 'text'],
        'campaign_name' => ['label' => 'Campaign name', 'type' => 'text'],
        'current_stage_id' => ['label' => 'Stage', 'type' => 'number'],
        'notes' => ['label' => 'Comment', 'type' => 'text'],
        'status' => ['label' => 'Status', 'type' => 'text'],
        'created_at' => ['label' => 'Created on', 'type' => 'date'],
        'updated_at' => ['label' => 'Modified on', 'type' => 'date'],
        'stage_changed_at' => ['label' => 'Stage change date', 'type' => 'date'],
        'assigned_to' => ['label' => 'Responsible person', 'type' => 'user'],
        'has_phone' => ['label' => 'Has phone', 'type' => 'checkbox'],
        'has_email' => ['label' => 'Has email', 'type' => 'checkbox'],
        'stage_history' => ['label' => 'Stage in history', 'type' => 'number'],
        'activity_created_at' => ['label' => 'Date created', 'type' => 'date', 'group' => 'Activity'],
        'activity_due_at' => ['label' => 'Deadline', 'type' => 'date', 'group' => 'Activity'],
        'activity_created_by' => ['label' => 'Created by', 'type' => 'user', 'group' => 'Activity'],
        'activity_status' => ['label' => 'Status', 'type' => 'select', 'group' => 'Activity', 'options' => ['open', 'completed']],
        'activity_type' => ['label' => 'Activity type', 'type' => 'select', 'group' => 'Activity', 'options' => ['call', 'email', 'meeting', 'task', 'note']],
    ];

    public function __construct(private ManageCustomFields $custom) {}

    /** @return list<array<string,mixed>> */
    public function catalog(Organization $org, User $actor): array
    {
        $builtins = [];
        foreach (self::BUILTIN as $key => $meta) {
            $builtins[] = ['key' => $key, 'label' => $meta['label'], 'type' => $meta['type'], 'group' => $meta['group'] ?? 'Lead', 'options' => $meta['options'] ?? []];
        }
        foreach ($this->custom->visible($org, $actor) as $field) {
            $builtins[] = ['key' => 'custom:'.$field->key, 'label' => $field->name, 'type' => $field->type, 'group' => 'Lead', 'options' => $field->options ?? []];
        }

        return $builtins;
    }

    /** @param Builder<CrmLead> $query
     * @param  list<array<string,mixed>>  $filters
     */
    public function apply(Builder $query, Organization $org, User $actor, array $filters, ?string $search = null): void
    {
        $visible = collect($this->custom->visible($org, $actor))->keyBy('key');
        if ($search !== null && trim($search) !== '') {
            $term = $this->like(trim($search));
            $query->where(function (Builder $nested) use ($org, $visible, $term): void {
                $nested->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)
                    ->orWhere('email', 'like', $term)->orWhere('phone', 'like', $term)
                    ->orWhere('source', 'like', $term)->orWhere('company', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('project_name', 'like', $term)->orWhere('campaign_name', 'like', $term)
                    ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$term]);
                if ($visible->isNotEmpty()) {
                    $nested->orWhereExists(function ($sub) use ($org, $visible, $term): void {
                        $sub->selectRaw('1')->from('crm_custom_field_values as value')
                            ->whereColumn('value.lead_id', 'crm_leads.id')->where('value.organization_id', $org->id)
                            ->whereIn('value.field_id', $visible->pluck('id'))->where('value.search_text', 'like', $term);
                    });
                }
            });
        }
        $activityFilters = [];
        foreach ($filters as $index => $filter) {
            $field = (string) ($filter['field'] ?? '');
            $op = (string) ($filter['operator'] ?? 'equals');
            $value = $filter['value'] ?? null;
            $to = $filter['to'] ?? null;
            if (str_starts_with($field, 'custom:')) {
                $definition = $visible->get(substr($field, 7));
                if (! $definition) {
                    throw ValidationException::withMessages(["filters.$index.field" => 'This field is not available to you.']);
                }
                $this->validateValue($definition->type, $op, $value, $to, $index);
                $this->customFilter($query, $org, $definition, $op, $value, $to, $index);
            } elseif (isset(self::BUILTIN[$field])) {
                $this->validateValue(self::BUILTIN[$field]['type'], $op, $value, $to, $index);
                if (str_starts_with($field, 'activity_')) {
                    $activityFilters[$index] = $filter;
                } else {
                    $this->builtinFilter($query, $org, $field, $op, $value, $to, $index);
                }
            } else {
                throw ValidationException::withMessages(["filters.$index.field" => 'Choose an available filter field.']);
            }
        }
        if ($activityFilters !== []) {
            $query->whereExists(function ($sub) use ($org, $activityFilters): void {
                $sub->selectRaw('1')->from('crm_activities as activity')->whereColumn('activity.subject_id', 'crm_leads.id')
                    ->where('activity.subject_type', (new CrmLead)->getMorphClass())->where('activity.organization_id', $org->id);
                foreach ($activityFilters as $index => $filter) {
                    $this->activityFilter($sub, $org, $filter, $index);
                }
            });
        }
    }

    /** @param Builder<CrmLead> $query */
    private function customFilter(Builder $query, Organization $org, CustomField $field, string $op, mixed $value, mixed $to, int $index): void
    {
        if ($op === 'empty') {
            $query->whereNotExists(function ($sub) use ($org, $field): void {
                $sub->selectRaw('1')->from('crm_custom_field_values as value')->whereColumn('value.lead_id', 'crm_leads.id')
                    ->where('value.organization_id', $org->id)->where('value.field_id', $field->id);
            });

            return;
        }
        if (in_array($field->type, ['multi_select', 'checkbox'], true) && in_array($op, ['equals', 'not_equals', 'contains'], true)) {
            $wanted = $field->type === 'checkbox' ? filter_var($value, FILTER_VALIDATE_BOOLEAN) : $value;
            $query->whereExists(function ($sub) use ($org, $field, $wanted): void {
                $sub->selectRaw('1')->from('crm_custom_field_values as value')->whereColumn('value.lead_id', 'crm_leads.id')
                    ->where('value.organization_id', $org->id)->where('value.field_id', $field->id)->whereJsonContains('value.value', $wanted);
            }, 'and', $op === 'not_equals');

            return;
        }
        $query->whereExists(function ($sub) use ($org, $field, $op, $value, $to, $index): void {
            $sub->selectRaw('1')->from('crm_custom_field_values as value')
                ->whereColumn('value.lead_id', 'crm_leads.id')->where('value.organization_id', $org->id)->where('value.field_id', $field->id);
            if ($op === 'not_empty') {
                return;
            }
            $column = match ($field->type) {
                'number', 'currency' => 'number_value',
                'date', 'datetime' => 'date_value',
                'user' => 'user_value',
                default => 'search_text',
            };
            if ($field->type === 'date') {
                if ($op === 'between') {
                    $sub->whereDate('value.'.$column, '>=', $value)->whereDate('value.'.$column, '<=', $to);
                } else {
                    $sub->whereDate('value.'.$column, match ($op) {
                        'equals' => '=', 'not_equals' => '!=', 'gte' => '>=', default => '<='
                    }, $value);
                }
            } else {
                $this->compare($sub, 'value.'.$column, $op, $value, $to, $index);
            }
        });
    }

    /** @param Builder<CrmLead> $query */
    private function builtinFilter(Builder $query, Organization $org, string $field, string $op, mixed $value, mixed $to, int $index): void
    {
        if ($field === 'has_phone' || $field === 'has_email') {
            $column = $field === 'has_phone' ? 'phone' : 'email';
            if (! in_array($op, ['equals', 'not_equals'], true)) {
                $this->invalid($index, 'Choose equals or not equals.');
            }
            $has = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($has === null) {
                $this->invalid($index, 'Choose yes or no.');
            }
            $wanted = $op === 'equals' ? $has : ! $has;
            if ($wanted) {
                $query->whereNotNull($column)->where($column, '!=', '');
            } else {
                $query->where(fn ($nested) => $nested->whereNull($column)->orWhere($column, ''));
            }

            return;
        }
        if ($field === 'lead_name') {
            if (in_array($op, ['empty', 'not_empty'], true)) {
                $query->whereRaw("TRIM(CONCAT(first_name, ' ', last_name)) ".($op === 'empty' ? '= ?' : '!= ?'), ['']);
            } else {
                $sqlOp = match ($op) {
                    'contains' => 'LIKE', 'equals' => '=', 'not_equals' => '!=', default => null
                };
                if ($sqlOp === null) {
                    $this->invalid($index, 'Choose equals, not equals or contains for a name.');
                }
                $query->whereRaw("CONCAT(first_name, ' ', last_name) $sqlOp ?", [$op === 'contains' ? $this->like((string) $value) : $value]);
            }

            return;
        }
        if ($field === 'stage_history') {
            if (! in_array($op, ['equals', 'not_equals', 'empty', 'not_empty'], true)) {
                $this->invalid($index, 'Choose a supported history operator.');
            }
            $query->whereExists(function ($sub) use ($org, $op, $value): void {
                $sub->selectRaw('1')->from('crm_lead_stage_histories as history')->whereColumn('history.lead_id', 'crm_leads.id')
                    ->where('history.organization_id', $org->id);
                if (in_array($op, ['equals', 'not_equals'], true)) {
                    $sub->where('history.to_stage_id', $value);
                }
            }, 'and', in_array($op, ['not_equals', 'empty'], true));

            return;
        }
        if (self::BUILTIN[$field]['type'] === 'date' && ! in_array($op, ['empty', 'not_empty'], true)) {
            $this->dateCompare($query, $org, 'crm_leads.'.$field, $op, $value, $to);
        } else {
            $this->compare($query, 'crm_leads.'.$field, $op, $value, $to, $index);
        }
    }

    /** @param array<string, mixed> $filter */
    private function activityFilter(QueryBuilder $query, Organization $org, array $filter, int $index): void
    {
        $field = $filter['field'];
        $op = $filter['operator'];
        $value = $filter['value'] ?? null;
        if ($field === 'activity_status') {
            if (! in_array($op, ['equals', 'not_equals'], true) || ! in_array($value, ['open', 'completed'], true)) {
                $this->invalid($index, 'Choose equals or not equals and open or completed.');
            }
            $completed = ($value === 'completed') !== ($op === 'not_equals');
            $query->whereNull('activity.completed_at', 'and', $completed);

            return;
        }
        if ($field === 'activity_type' && ! in_array($op, ['empty', 'not_empty'], true)
            && ! in_array($value, self::BUILTIN['activity_type']['options'], true)) {
            $this->invalid($index, 'Choose an available activity type.');
        }
        $column = match ($field) {
            'activity_created_at' => 'created_at', 'activity_due_at' => 'due_at',
            'activity_created_by' => 'created_by', default => 'type',
        };
        if (self::BUILTIN[$field]['type'] === 'date' && ! in_array($op, ['empty', 'not_empty'], true)) {
            $this->dateCompare($query, $org, 'activity.'.$column, $op, $value, $filter['to'] ?? null);
        } else {
            $this->compare($query, 'activity.'.$column, $op, $value, $filter['to'] ?? null, $index);
        }
    }

    /** @param Builder<CrmLead>|QueryBuilder $query */
    private function dateCompare(Builder|QueryBuilder $query, Organization $org, string $column, string $op, mixed $value, mixed $to): void
    {
        $start = CarbonImmutable::parse($value, $org->timezone)->startOfDay()->utc();
        $end = CarbonImmutable::parse($op === 'between' ? $to : $value, $org->timezone)->endOfDay()->utc();
        match ($op) {
            'equals', 'between' => $query->whereBetween($column, [$start, $end]),
            'not_equals' => $query->whereNotBetween($column, [$start, $end]),
            'gte' => $query->where($column, '>=', $start),
            'lte' => $query->where($column, '<=', $end),
            default => throw new \LogicException('Unexpected date operator.'),
        };
    }

    private function validateValue(string $type, string $op, mixed $value, mixed $to, int $index): void
    {
        $operators = match ($type) {
            'number', 'currency', 'date', 'datetime' => ['equals', 'not_equals', 'gte', 'lte', 'between', 'empty', 'not_empty'],
            'checkbox', 'user', 'single_select', 'select' => ['equals', 'not_equals', 'empty', 'not_empty'],
            default => ['equals', 'not_equals', 'contains', 'empty', 'not_empty'],
        };
        if (! in_array($op, $operators, true)) {
            $this->invalid($index, 'Choose an operator supported by this field type.');
        }
        if (in_array($op, ['empty', 'not_empty'], true)) {
            return;
        }
        if ($type === 'checkbox') {
            $value = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($value === null) {
                $this->invalid($index, 'Choose yes or no.');
            }
        }
        $rules = match ($type) {
            'number', 'currency' => ['required', 'numeric'],
            'user' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'datetime' => ['required', 'date'],
            'checkbox' => ['required', 'boolean'],
            default => ['required', 'string', 'max:5000'],
        };
        // HTML form selections and JSON callers may use numeric scalar values.
        if (is_int($value) || is_float($value)) {
            $value = (string) $value;
        }
        $validator = Validator::make(['value' => $value, 'to' => $to], ['value' => $rules, 'to' => $op === 'between' ? $rules : ['nullable']]);
        if ($validator->fails() || ($op === 'between' && $value > $to)) {
            $this->invalid($index, 'Enter a valid value and an ordered range for this field type.');
        }
    }

    /** @param Builder<CrmLead>|QueryBuilder $query */
    private function compare(Builder|QueryBuilder $query, string $column, string $op, mixed $value, mixed $to, int $index, bool $name = false): void
    {
        if ($op === 'empty') {
            $query->whereNull($column);

            return;
        }
        if ($op === 'not_empty') {
            $query->whereNotNull($column);

            return;
        }
        if (! is_scalar($value) || (string) $value === '') {
            $this->invalid($index, 'Enter a filter value.');
        }
        if ($op === 'contains') {
            $term = $this->like((string) $value);
            $query->where(function ($nested) use ($column, $term, $name): void {
                $nested->where($column, 'like', $term);
                if ($name) {
                    $nested->orWhere('last_name', 'like', $term);
                }
            });
        } elseif ($op === 'between') {
            if (! is_scalar($to) || (string) $to === '') {
                $this->invalid($index, 'Enter the end of the range.');
            }
            $query->whereBetween($column, [$value, $to]);
        } elseif (in_array($op, ['equals', 'not_equals', 'gte', 'lte'], true)) {
            $query->where($column, match ($op) {
                'equals' => '=', 'not_equals' => '!=', 'gte' => '>=', default => '<='
            }, $value);
        } else {
            $this->invalid($index, 'Choose a valid filter operator.');
        }
    }

    private function like(string $value): string
    {
        return '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value).'%';
    }

    private function invalid(int $index, string $message): never
    {
        throw ValidationException::withMessages(["filters.$index" => $message]);
    }
}
