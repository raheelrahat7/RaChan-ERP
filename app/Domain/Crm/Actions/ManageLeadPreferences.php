<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\LeadPreference;
use App\Domain\Crm\Queries\LeadFilters;
use App\Domain\Crm\Queries\PipelineOverview;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageLeadPreferences
{
    public function __construct(private LeadFilters $filters, private PipelineOverview $overview) {}

    /** @return array<string, mixed> */
    public function for(Organization $org, User $actor): array
    {
        Gate::forUser($actor)->authorize('viewCrm', $org);
        $preference = LeadPreference::where('organization_id', $org->id)->where('user_id', $actor->id)->first();
        $available = array_column($this->filters->catalog($org, $actor), 'key');
        // Revoked field permissions must also remove old filters from returned preferences.
        $presets = array_values(array_filter($preference->presets ?? [], fn ($preset) => array_diff(array_column($preset['state']['filters'] ?? [], 'field'), $available) === []));

        return ['selected_field_keys' => $preference?->selected_field_keys === null ? null : array_values(array_intersect($preference->selected_field_keys, $available)), 'presets' => $presets];
    }

    /** @param array<string, mixed> $input */
    public function save(Organization $org, User $actor, array $input): void
    {
        Gate::forUser($actor)->authorize('viewCrm', $org);
        $data = Validator::make($input, [
            'selected_field_keys' => ['sometimes', 'array', 'max:100'], 'selected_field_keys.*' => ['string', 'distinct', 'max:100'],
            'name' => ['sometimes', 'required', 'string', 'max:100'], 'state' => ['required_with:name', 'array:pipeline_id,stage_id,assignee_id,q,filters'],
            'state.pipeline_id' => ['nullable', 'integer'], 'state.stage_id' => ['nullable', 'integer'], 'state.assignee_id' => ['nullable', 'integer'],
            'state.q' => ['nullable', 'string', 'max:100'], 'state.filters' => ['nullable', 'array', 'max:12'],
            'state.filters.*.field' => ['required', 'string', 'max:100'], 'state.filters.*.operator' => ['required', 'string', 'max:20'],
            'state.filters.*.value' => ['nullable'], 'state.filters.*.to' => ['nullable'],
        ])->validate();
        $available = array_column($this->filters->catalog($org, $actor), 'key');
        if (array_diff($data['selected_field_keys'] ?? [], $available)) {
            throw ValidationException::withMessages(['selected_field_keys' => 'Choose available filter fields.']);
        }
        if (isset($data['name'])) {
            $this->overview->filteredLeadQuery($org, $actor, $data['state']);
        }
        DB::transaction(function () use ($org, $actor, $data): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $preference = LeadPreference::firstOrCreate(['organization_id' => $org->id, 'user_id' => $actor->id]);
            if (array_key_exists('selected_field_keys', $data)) {
                $preference->selected_field_keys = $data['selected_field_keys'];
            }
            if (isset($data['name'])) {
                $presets = $preference->presets ?? [];
                if (count($presets) >= 20) {
                    throw ValidationException::withMessages(['name' => 'Keep at most 20 saved filters. Remove one before adding another.']);
                }
                $presets[] = ['id' => (string) Str::uuid(), 'name' => $data['name'], 'state' => $data['state']];
                $preference->presets = $presets;
            }
            $preference->save();
        });
    }

    public function delete(Organization $org, User $actor, string $presetId): void
    {
        Gate::forUser($actor)->authorize('viewCrm', $org);
        DB::transaction(function () use ($org, $actor, $presetId): void {
            $preference = LeadPreference::where('organization_id', $org->id)->where('user_id', $actor->id)->lockForUpdate()->firstOrFail();
            $presets = $preference->presets ?? [];
            abort_unless(collect($presets)->contains('id', $presetId), 404);
            $preference->update(['presets' => array_values(array_filter($presets, fn ($preset) => $preset['id'] !== $presetId))]);
        });
    }
}
