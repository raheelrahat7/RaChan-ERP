<?php

namespace App\Domain\Operations\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Operations\Models\SavedReportFilter;
use App\Domain\Operations\Services\ReportFilterRules;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageSavedReportFilters
{
    public function __construct(private ReportFilterRules $rules, private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input */
    public function save(Organization $org, User $actor, array $input): SavedReportFilter
    {
        Gate::forUser($actor)->authorize('viewOperations', $org);
        Validator::make($input, ['name' => ['required', 'string', 'max:100'], 'filters' => ['required', 'array']])->validate();
        $filters = Validator::make($input['filters'], $this->rules->for($org->id, ! empty($input['filters']['created_from'])))->validate();
        $filters = array_filter($filters, fn ($value): bool => $value !== null && $value !== '');
        $name = trim($input['name']);
        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter a filter name.']);
        }

        return DB::transaction(function () use ($org, $actor, $name, $filters): SavedReportFilter {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $query = SavedReportFilter::where('organization_id', $org->id)->where('user_id', $actor->id);
            $existing = (clone $query)->where('name', $name)->first();
            if ($existing === null && $query->count() >= 50) {
                throw ValidationException::withMessages(['name' => 'Remove an existing filter before saving more than 50.']);
            }
            $record = SavedReportFilter::updateOrCreate(['organization_id' => $org->id, 'user_id' => $actor->id, 'name' => $name], ['filters' => $filters]);
            $this->audit->handle($org, $actor, 'operations.report.filter_saved', $record, ['name' => $name]);

            return $record;
        });
    }

    public function remove(Organization $org, User $actor, SavedReportFilter $filter): void
    {
        Gate::forUser($actor)->authorize('viewOperations', $org);
        DB::transaction(function () use ($org, $actor, $filter): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $filter = SavedReportFilter::where('organization_id', $org->id)->where('user_id', $actor->id)->findOrFail($filter->id);
            $this->audit->handle($org, $actor, 'operations.report.filter_removed', $filter, ['name' => $filter->name]);
            $filter->delete();
        });
    }
}
