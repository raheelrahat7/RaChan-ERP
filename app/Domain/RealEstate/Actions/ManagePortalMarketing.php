<?php

namespace App\Domain\RealEstate\Actions;

use App\Domain\Accounting\Actions\ManageAccountingDimensions;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Integrations\Data\ProviderPacket;
use App\Domain\Integrations\Services\LocalOutboundProvider;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\User;
use App\Models\VendorBill;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManagePortalMarketing
{
    public function __construct(private RecordOrganizationAuditLog $audit, private LeadVisibility $visibility, private LocalOutboundProvider $provider, private ManageAccountingDimensions $dimensions) {}

    /** @param array<string,mixed> $input */
    public function campaign(Organization $org, User $actor, array $input): int
    {
        $this->authorize($org, $actor);
        $this->related($org, $input['vendor_id'] ?? null, 'maintenance_vendors', 'vendor_id');
        $this->related($org, $input['cost_centre_id'] ?? null, 'accounting_cost_centres', 'cost_centre_id');

        return DB::transaction(function () use ($org, $actor, $input): int {
            $id = DB::table('marketing_campaigns')->insertGetId([
                'organization_id' => $org->id, 'vendor_id' => $input['vendor_id'] ?? null,
                'cost_centre_id' => $input['cost_centre_id'] ?? null, 'name' => $input['name'],
                'type' => $input['type'], 'budget_aed' => $input['budget_aed'] ?? 0,
                'starts_on' => $input['starts_on'] ?? null, 'ends_on' => $input['ends_on'] ?? null,
                'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'marketing.campaign.created', $org, ['campaign_id' => $id]);

            return $id;
        });
    }

    /** @param array<string,mixed> $input */
    public function publication(Organization $org, User $actor, array $input): int
    {
        $this->authorize($org, $actor);
        $listing = Listing::where('organization_id', $org->id)->with('unit.property')->whereKey($input['listing_id'])->first();
        if (! $listing || $listing->status !== 'active' || $listing->currency !== 'AED') {
            throw ValidationException::withMessages(['listing_id' => 'Choose an active AED listing in this organization.']);
        }
        $this->related($org, $input['campaign_id'] ?? null, 'marketing_campaigns', 'campaign_id');
        $operationKey = (string) Str::uuid();
        $validated = $this->provider->validate(new ProviderPacket('property_portal', $org->id, $operationKey, [
            'listing_id' => $listing->id, 'reference' => $listing->reference, 'purpose' => $listing->purpose,
            'price' => number_format((float) $listing->price, 2, '.', ''), 'currency' => 'AED',
            'title' => trim($listing->unit->property->name.' '.$listing->unit->number),
        ]));

        return DB::transaction(function () use ($org, $actor, $input, $listing, $operationKey, $validated): int {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (DB::table('portal_publications')->where('organization_id', $org->id)->where('listing_id', $listing->id)->where('portal', $input['portal'])->exists()) {
                throw ValidationException::withMessages(['portal' => 'This listing already has a local record for that portal.']);
            }
            $id = DB::table('portal_publications')->insertGetId([
                'organization_id' => $org->id, 'listing_id' => $listing->id, 'campaign_id' => $input['campaign_id'] ?? null,
                'portal' => $input['portal'], 'status' => 'local_validated', 'operation_key' => $operationKey,
                'packet_sha256' => $validated['sha256'], 'validated_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'marketing.publication.local_validated', $org, ['publication_id' => $id, 'portal' => $input['portal']]);

            return $id;
        });
    }

    /** @param array<string,mixed> $input */
    public function enquiry(Organization $org, User $actor, int $publicationId, array $input): void
    {
        $this->authorize($org, $actor);
        $publication = DB::table('portal_publications')->where('organization_id', $org->id)->where('id', $publicationId)->first();
        abort_unless($publication !== null, 404);
        $lead = CrmLead::where('organization_id', $org->id)->whereKey($input['lead_id'])->first();
        if (! $lead || $lead->listing_id !== $publication->listing_id || ! $this->visibility->canSeeLead($org, $actor, $lead->assigned_to)) {
            throw ValidationException::withMessages(['lead_id' => 'Choose a visible lead linked to this listing.']);
        }
        DB::transaction(function () use ($org, $actor, $publicationId, $input): void {
            if (DB::table('portal_enquiries')->where('organization_id', $org->id)->where('publication_id', $publicationId)->where('source_reference', $input['source_reference'])->exists()) {
                throw ValidationException::withMessages(['source_reference' => 'This enquiry reference was already recorded.']);
            }
            DB::table('portal_enquiries')->insert(['organization_id' => $org->id, 'publication_id' => $publicationId,
                'lead_id' => $input['lead_id'], 'source_reference' => $input['source_reference'],
                'received_at' => $input['received_at'], 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->handle($org, $actor, 'marketing.portal_enquiry.recorded', $org, ['publication_id' => $publicationId]);
        });
    }

    /** @param array<string,mixed> $input */
    public function subscription(Organization $org, User $actor, array $input): int
    {
        $this->authorize($org, $actor);
        $this->dimensions->validateLine($org, $input);

        return DB::transaction(function () use ($org, $actor, $input): int {
            $id = DB::table('portal_subscriptions')->insertGetId([
                'organization_id' => $org->id, 'portal' => $input['portal'], 'package' => $input['package'],
                'company_id' => $input['company_id'] ?? null, 'branch_id' => $input['branch_id'] ?? null,
                'cost_centre_id' => $input['cost_centre_id'] ?? null, 'contract_value_aed' => $input['contract_value_aed'],
                'billing_cycle' => $input['billing_cycle'], 'credits_total' => $input['credits_total'] ?? 0,
                'starts_on' => $input['starts_on'], 'renews_on' => $input['renews_on'] ?? null,
                'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'marketing.subscription.created', $org, ['subscription_id' => $id]);

            return $id;
        });
    }

    /** @param array<string,mixed> $input */
    public function linkBill(Organization $org, User $actor, int $subscriptionId, array $input): void
    {
        abort_unless($actor->can('manageFinance', $org), 403);
        $subscription = DB::table('portal_subscriptions')->where('organization_id', $org->id)->where('id', $subscriptionId)->first();
        abort_unless($subscription !== null, 404);
        $bill = VendorBill::where('organization_id', $org->id)->where('currency', 'AED')->whereKey($input['vendor_bill_id'])->first();
        if (! $bill) {
            throw ValidationException::withMessages(['vendor_bill_id' => 'Choose an AED vendor bill in this organization.']);
        }
        DB::transaction(function () use ($org, $actor, $subscriptionId, $input): void {
            DB::table('portal_subscription_bills')->insert([
                'organization_id' => $org->id, 'subscription_id' => $subscriptionId, 'vendor_bill_id' => $input['vendor_bill_id'],
                'period_from' => $input['period_from'], 'period_to' => $input['period_to'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'marketing.subscription_bill.linked', $org, ['subscription_id' => $subscriptionId, 'vendor_bill_id' => $input['vendor_bill_id']]);
        });
    }

    /** @param array<string,mixed> $input */
    public function spend(Organization $org, User $actor, array $input): int
    {
        $this->authorize($org, $actor);
        $this->related($org, $input['listing_id'], 'listings', 'listing_id');
        foreach (['publication_id' => 'portal_publications', 'campaign_id' => 'marketing_campaigns'] as $field => $table) {
            $this->related($org, $input[$field] ?? null, $table, $field);
        }
        if (isset($input['publication_id']) && ! DB::table('portal_publications')->where('id', $input['publication_id'])->where('listing_id', $input['listing_id'])->exists()) {
            throw ValidationException::withMessages(['publication_id' => 'The publication belongs to another listing.']);
        }
        if ($input['source'] === 'bill_linked') {
            $bill = VendorBill::where('organization_id', $org->id)->where('currency', 'AED')->whereNot('status', 'draft')->whereKey($input['vendor_bill_id'] ?? 0)->first();
            if (! $bill) {
                throw ValidationException::withMessages(['vendor_bill_id' => 'Choose a posted AED vendor bill.']);
            }
        } elseif (isset($input['vendor_bill_id'])) {
            throw ValidationException::withMessages(['vendor_bill_id' => 'Estimates cannot be linked to a vendor bill.']);
        }

        return DB::transaction(function () use ($org, $actor, $input): int {
            if ($input['source'] === 'bill_linked') {
                $bill = VendorBill::where('organization_id', $org->id)->whereKey($input['vendor_bill_id'])->lockForUpdate()->firstOrFail();
                $allocated = (float) DB::table('listing_spend')->where('vendor_bill_id', $bill->id)->sum('amount_aed');
                if ($allocated + (float) $input['amount_aed'] > (float) $bill->total + 0.001) {
                    throw ValidationException::withMessages(['amount_aed' => 'Listing allocations exceed the vendor bill total.']);
                }
            }
            $id = DB::table('listing_spend')->insertGetId([
                'organization_id' => $org->id, 'listing_id' => $input['listing_id'],
                'publication_id' => $input['publication_id'] ?? null, 'campaign_id' => $input['campaign_id'] ?? null,
                'vendor_bill_id' => $input['vendor_bill_id'] ?? null, 'created_by' => $actor->id,
                'channel' => $input['channel'], 'source' => $input['source'], 'amount_aed' => $input['amount_aed'],
                'incurred_on' => $input['incurred_on'], 'reason' => $input['reason'], 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->handle($org, $actor, 'marketing.listing_spend.recorded', $org, ['spend_id' => $id, 'source' => $input['source']]);

            return $id;
        });
    }

    private function authorize(Organization $org, User $actor): void
    {
        abort_unless($actor->can('manageCrm', $org), 403);
    }

    private function related(Organization $org, mixed $id, string $table, string $field): void
    {
        if ($id !== null && ! DB::table($table)->where('organization_id', $org->id)->where('id', $id)->exists()) {
            throw ValidationException::withMessages([$field => 'Choose a record in this organization.']);
        }
    }
}
