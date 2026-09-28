<?php

namespace App\Domain\Platform\Queries;

use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Operations\Services\JobCardAccess;
use App\Models\CrmLead;
use App\Models\Invoice;
use App\Models\MaintenanceRequest;
use App\Models\Organization;
use App\Models\Property;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ReadApi
{
    /** @return LengthAwarePaginator<int,MaintenanceRequest> */
    public function jobs(Organization $org, User $actor, int $size): LengthAwarePaginator
    {
        return app(JobCardAccess::class)->scope(MaintenanceRequest::query(), $org, $actor)->orderBy('id')->paginate($size, ['id', 'reference', 'title', 'status', 'priority', 'property_id', 'assigned_to', 'created_at', 'updated_at']);
    }

    /** @return LengthAwarePaginator<int,Property> */
    public function properties(Organization $org, int $size): LengthAwarePaginator
    {
        return Property::where('organization_id', $org->id)->orderBy('id')->paginate($size, ['id', 'name', 'type', 'city', 'country']);
    }

    /** @return LengthAwarePaginator<int,CrmLead> */
    public function leads(Organization $org, User $actor, int $size): LengthAwarePaginator
    {
        return app(LeadVisibility::class)->scope(CrmLead::where('organization_id', $org->id), $org, $actor)->orderBy('id')->paginate($size, ['id', 'first_name', 'last_name', 'email', 'phone', 'status', 'pipeline_id', 'current_stage_id', 'assigned_to', 'created_at']);
    }

    /** @return LengthAwarePaginator<int,Invoice> */
    public function invoices(Organization $org, int $size): LengthAwarePaginator
    {
        return Invoice::where('organization_id', $org->id)->orderBy('id')->paginate($size, ['id', 'reference', 'status', 'issued_on', 'due_on', 'subtotal', 'vat_amount', 'total', 'currency']);
    }
}
