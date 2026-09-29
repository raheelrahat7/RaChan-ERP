<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Actions\ManageCustomFields;
use App\Domain\Crm\Models\CustomField;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
                    ->orWhere('city', 'like', $term);
                if ($visible->isNotEmpty()) {
                    $nested->orWhereExists(function ($sub) use ($org, $visible, $term): void {
                        $sub->selectRaw('1')->from('crm_custom_field_values as value')
                            ->whereColumn('value.lead_id', 'crm_leads.id')->where('value.organization_id', $org->id)
                            ->whereIn('value.field_id', $visible->pluck('id'))->where('value.search_text', 'like', $term);
                    });
                }
            });
        }
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
                $this->customFilter($query, $org, $definition, $op, $value, $to, $index);
            } elseif (isset(self::BUILTIN[$field])) {
                $this->builtinFilter($query, $org, $field, $op, $value, $to, $index);
            } else {
                throw ValidationException::withMessages(["filters.$index.field" => 'Choose an available filter field.']);
            }
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
            $this->compare($sub, 'value.'.$column, $op, $value, $to, $index);
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
            $this->compare($query, 'first_name', $op, $value, $to, $index, true);

            return;
        }
        if ($field === 'stage_history') {
            $query->whereExists(function ($sub) use ($org, $op, $value, $to, $index): void {
                $sub->selectRaw('1')->from('crm_lead_stage_histories as history')->whereColumn('history.lead_id', 'crm_leads.id')
                    ->where('history.organization_id', $org->id);
                $this->compare($sub, 'history.to_stage_id', $op, $value, $to, $index);
            });

            return;
        }
        if (str_starts_with($field, 'activity_')) {
            $column = match ($field) {
                'activity_created_at' => 'created_at', 'activity_due_at' => 'due_at',
                'activity_created_by' => 'created_by', 'activity_type' => 'type',
                default => 'completed_at',
            };
            $query->whereExists(function ($sub) use ($org, $field, $column, $op, $value, $to, $index): void {
                $sub->selectRaw('1')->from('crm_activities as activity')->whereColumn('activity.subject_id', 'crm_leads.id')
                    ->where('activity.subject_type', CrmLead::class)->where('activity.organization_id', $org->id);
                if ($field === 'activity_status') {
                    if (! in_array($value, ['open', 'completed'], true)) {
                        $this->invalid($index, 'Choose open or completed.');
                    }
                    $sub->whereNull('activity.completed_at', 'and', $value === 'completed');
                } else {
                    $this->compare($sub, 'activity.'.$column, $op, $value, $to, $index);
                }
            });

            return;
        }
        $this->compare($query, 'crm_leads.'.$field, $op, $value, $to, $index);
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
