<?php

namespace App\Http\Requests\Operations;

use App\Domain\Operations\Services\ReportFilterRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Arr;

class ReportRequest extends FormRequest
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
        return [...app(ReportFilterRules::class)->for($this->user()?->current_organization_id, $this->filled('created_from')), 'page' => ['nullable', 'integer', 'min:1']];
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return Arr::except($this->validated(), ['page']);
    }
}
