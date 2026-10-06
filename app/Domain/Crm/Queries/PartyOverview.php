<?php

namespace App\Domain\Crm\Queries;

use App\Domain\Crm\Actions\ManageRecordFields;
use App\Domain\Crm\Services\CrmEditPermission;
use App\Domain\Crm\Services\DealAccess;
use App\Domain\Crm\Services\LeadVisibility;
use App\Models\CrmAccount;
use App\Models\CrmContact;
use App\Models\CrmLead;
use App\Models\Organization;
use App\Models\User;

class PartyOverview
{
    public function __construct(private ManageRecordFields $fields, private LeadVisibility $visibility, private DealAccess $deals, private DealOverview $dealOverview) {}

    /** @return array<string, mixed> */
    public function contact(Organization $org, User $actor, CrmContact $contact): array
    {
        $contact->load('account:id,name');
        $leads = $this->visibility->scope(CrmLead::where('organization_id', $org->id)->where('converted_contact_id', $contact->id), $org, $actor)->get();

        return ['contact' => [...$contact->toArray(), 'permissions' => $this->permissions($org, $actor)],
            'customFields' => $this->fields->values($org, $actor, $contact),
            'leads' => $leads->map(fn ($lead) => [...$lead->toArray(), 'permissions' => ['read' => true, 'edit' => app(CrmEditPermission::class)->granted($org, $actor)]]), 'deals' => $this->deals->query($org, $actor)->whereIn('lead_id', $leads->pluck('id'))->get()->map(fn ($deal) => $this->dealOverview->serialize($org, $actor, $deal)),
            'activities' => $contact->activities()->where('organization_id', $org->id)->latest('id')->paginate(50)];
    }

    /** @return array<string, mixed> */
    public function company(Organization $org, User $actor, CrmAccount $company): array
    {
        $company->load('contacts');
        $leads = $this->visibility->scope(CrmLead::where('organization_id', $org->id)->where('converted_account_id', $company->id), $org, $actor)->get();

        return ['company' => [...$company->toArray(), 'contacts' => $company->contacts->map(fn (CrmContact $contact) => [...$contact->toArray(), 'permissions' => $this->permissions($org, $actor)]), 'permissions' => $this->permissions($org, $actor)],
            'customFields' => $this->fields->values($org, $actor, $company),
            'leads' => $leads->map(fn ($lead) => [...$lead->toArray(), 'permissions' => ['read' => true, 'edit' => app(CrmEditPermission::class)->granted($org, $actor)]]), 'deals' => $this->deals->query($org, $actor)->whereIn('lead_id', $leads->pluck('id'))->get()->map(fn ($deal) => $this->dealOverview->serialize($org, $actor, $deal)),
            'activities' => $company->activities()->where('organization_id', $org->id)->latest('id')->paginate(50)];
    }

    /** @return array<string, bool> */
    private function permissions(Organization $org, User $actor): array
    {
        return ['read' => $actor->can('viewCrm', $org), 'edit' => $actor->can('manageCrm', $org)];
    }
}
