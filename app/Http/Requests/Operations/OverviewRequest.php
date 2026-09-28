<?php

namespace App\Http\Requests\Operations;

use App\Domain\Operations\Data\OverviewFilters;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OverviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $organization = $user?->currentOrganization;

        return $organization !== null && $user->can('viewOperations', $organization);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $organizationId = $this->user()?->current_organization_id;

        return [
            'property_id' => ['nullable', 'integer', Rule::exists('properties', 'id')->where('organization_id', $organizationId)],
            'vendor_id' => ['nullable', 'integer', Rule::exists('maintenance_vendors', 'id')->where('organization_id', $organizationId)],
            'requests_page' => ['nullable', 'integer', 'min:1'],
            'plans_page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): OverviewFilters
    {
        $input = $this->validated();

        return new OverviewFilters(
            isset($input['property_id']) ? (int) $input['property_id'] : null,
            isset($input['vendor_id']) ? (int) $input['vendor_id'] : null,
        );
    }
}
