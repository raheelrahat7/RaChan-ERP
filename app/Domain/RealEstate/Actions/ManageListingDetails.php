<?php

namespace App\Domain\RealEstate\Actions;

use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\RealEstate\Models\ListingWorkflowStatus;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageListingDetails
{
    public const DEFAULT_STATUSES = ['draft' => 'Draft', 'documents_pending' => 'Documents Pending', 'verification' => 'Verification', 'approval_pending' => 'Approval Pending', 'approved' => 'Approved', 'ready_to_publish' => 'Ready to Publish', 'published' => 'Published', 'active' => 'Active', 'on_hold' => 'On Hold', 'under_offer' => 'Under Offer', 'sold' => 'Sold', 'rented' => 'Rented', 'withdrawn' => 'Withdrawn'];

    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @return list<array{code: string, name: string, position: int, active: bool, id: int|null, version: int|null}> */
    public function statuses(Organization $org): array
    {
        $rows = ListingWorkflowStatus::where('organization_id', $org->id)->orderBy('position')->orderBy('id')->get();
        if ($rows->isNotEmpty()) {
            return array_values($rows->map(fn (ListingWorkflowStatus $row) => ['code' => $row->code, 'name' => $row->name, 'position' => $row->position, 'active' => $row->active, 'id' => $row->id, 'version' => $row->version])->all());
        }

        $statuses = [];
        foreach (array_keys(self::DEFAULT_STATUSES) as $position => $code) {
            $statuses[] = ['code' => $code, 'name' => self::DEFAULT_STATUSES[$code], 'position' => $position, 'active' => true, 'id' => null, 'version' => null];
        }

        return $statuses;
    }

    /** @param array<string, mixed> $input */
    public function saveStatus(Organization $org, User $actor, array $input, ?int $id = null): ListingWorkflowStatus
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        $data = Validator::make($input, ['code' => ['required', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'], 'name' => ['required', 'string', 'max:120'], 'position' => ['required', 'integer', 'between:0,10000'], 'active' => ['required', 'boolean'], 'expected_version' => [$id ? 'required' : 'prohibited', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($org, $actor, $data, $id): ListingWorkflowStatus {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (! ListingWorkflowStatus::where('organization_id', $org->id)->exists()) {
                foreach (array_keys(self::DEFAULT_STATUSES) as $position => $code) {
                    ListingWorkflowStatus::create(['organization_id' => $org->id, 'code' => $code, 'name' => self::DEFAULT_STATUSES[$code], 'position' => $position, 'active' => true]);
                }
            }
            $row = $id ? ListingWorkflowStatus::where('organization_id', $org->id)->lockForUpdate()->findOrFail($id) : new ListingWorkflowStatus(['organization_id' => $org->id]);
            if ($id && $row->version !== (int) $data['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => 'This status changed. Refresh before saving.']);
            }
            if ($id && $row->code !== $data['code']) {
                throw ValidationException::withMessages(['code' => 'Existing status codes cannot change.']);
            }
            if (! $id && array_key_exists($data['code'], self::DEFAULT_STATUSES)) {
                $row = ListingWorkflowStatus::where('organization_id', $org->id)->where('code', $data['code'])->lockForUpdate()->firstOrFail();
                if ($row->version !== 1 || $row->name !== self::DEFAULT_STATUSES[$data['code']]) {
                    throw ValidationException::withMessages(['code' => 'This default status is already configured. Update it with its version.']);
                }
            } elseif (ListingWorkflowStatus::where('organization_id', $org->id)->where('code', $data['code'])->whereKeyNot($id ?? 0)->exists()) {
                throw ValidationException::withMessages(['code' => 'This status code already exists.']);
            }
            unset($data['expected_version']);
            if ($id || $row->exists) {
                $row->version++;
            }
            $row->fill($data)->save();
            $this->audit->handle($org, $actor, 'listing.workflow_status.saved', $row, $data);

            return $row->refresh();
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function validateDetails(Organization $org, array $input): array
    {
        $data = Validator::make($input, [
            'workflow_status' => ['sometimes', 'string', 'max:40'],
            'cost_centre_id' => ['sometimes', 'nullable', 'integer'], 'owner_id' => ['sometimes', 'nullable', 'integer'],
            'broker_id' => ['sometimes', 'nullable', 'integer'], 'price' => ['sometimes', 'numeric', 'between:0,99999999999999.99'], 'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'listing_category' => ['sometimes', 'nullable', 'string', 'max:80'], 'unit_category' => ['sometimes', 'nullable', 'string', 'max:80'],
            'emirate' => ['sometimes', 'nullable', 'string', 'max:100'], 'community' => ['sometimes', 'nullable', 'string', 'max:160'], 'sub_community' => ['sometimes', 'nullable', 'string', 'max:160'],
            'trakheesi_permit' => ['sometimes', 'nullable', 'string', 'max:100'], 'dld_permit' => ['sometimes', 'nullable', 'string', 'max:100'], 'bedroom_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'bedrooms' => ['sometimes', 'nullable', 'integer', 'between:0,100'], 'bathrooms' => ['sometimes', 'nullable', 'integer', 'between:0,100'], 'balconies' => ['sometimes', 'nullable', 'integer', 'between:0,100'], 'parking_spaces' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'size_sqft' => ['sometimes', 'nullable', 'numeric', 'between:0,999999999999.99'], 'plot_size_sqft' => ['sometimes', 'nullable', 'numeric', 'between:0,999999999999.99'],
            'furnishing' => ['sometimes', 'nullable', 'string', 'max:60'], 'completion_status' => ['sometimes', 'nullable', 'string', 'max:60'], 'handover_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'grade' => ['sometimes', 'nullable', 'string', 'max:40'], 'loading_bay' => ['sometimes', 'nullable', 'boolean'], 'fit_out' => ['sometimes', 'nullable', 'string', 'max:80'],
            'price_type' => ['sometimes', 'nullable', 'string', 'max:40'], 'price_min' => ['sometimes', 'nullable', 'numeric', 'between:0,999999999999.99'], 'price_max' => ['sometimes', 'nullable', 'numeric', 'between:0,999999999999.99'], 'price_label' => ['sometimes', 'nullable', 'string', 'max:100'],
            'developer_name' => ['sometimes', 'nullable', 'string', 'max:160'], 'portals' => ['sometimes', 'nullable', 'array', 'max:30'], 'portals.*' => ['string', 'max:80', 'distinct'],
            'valuation_price' => ['sometimes', 'nullable', 'numeric', 'between:0,99999999999999.99'],
            'mortgage_status' => ['sometimes', 'nullable', 'string', 'max:60'],
            'noc_status' => ['sometimes', 'nullable', 'string', 'max:60'],
            'transfer_status' => ['sometimes', 'nullable', 'string', 'max:60'],
            'buyer_contact_id' => ['sometimes', 'nullable', 'integer'],
        ])->validate();
        if (isset($data['workflow_status']) && ! in_array($data['workflow_status'], array_column(array_filter($this->statuses($org), fn ($status) => $status['active']), 'code'), true)) {
            throw ValidationException::withMessages(['workflow_status' => 'Select an active organization listing status.']);
        }
        foreach (['cost_centre_id' => 'accounting_cost_centres', 'owner_id' => 'owners', 'broker_id' => 'brokers', 'buyer_contact_id' => 'crm_contacts'] as $field => $table) {
            if (isset($data[$field]) && ! DB::table($table)->where('organization_id', $org->id)->where('id', $data[$field])->exists()) {
                throw ValidationException::withMessages([$field => 'Select a record in this organization.']);
            }
        }
        if (isset($data['price_min'], $data['price_max']) && $data['price_min'] > $data['price_max']) {
            throw ValidationException::withMessages(['price_max' => 'Maximum price must be at least minimum price.']);
        }

        return $data;
    }

    /** @param array<string, mixed> $input */
    public function update(Organization $org, User $actor, Listing $listing, array $input): Listing
    {
        abort_unless($actor->can('manageCrm', $org), 403);
        $version = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1']])->validate()['expected_version'];
        $data = $this->validateDetails($org, $input);

        $this->validateSecondaryFields($listing->market_segment === 'secondary' || $listing->purpose === 'rent', $data);

        return DB::transaction(function () use ($org, $actor, $listing, $version, $data): Listing {
            $listing = Listing::where('organization_id', $org->id)->lockForUpdate()->findOrFail($listing->id);
            if ($listing->version !== (int) $version) {
                throw ValidationException::withMessages(['expected_version' => 'This listing changed. Refresh before saving.']);
            }
            $before = $listing->only(array_keys($data));
            if (isset($data['price_min']) || isset($data['price_max'])) {
                $min = $data['price_min'] ?? $listing->price_min;
                $max = $data['price_max'] ?? $listing->price_max;
                if ($min !== null && $max !== null && $min > $max) {
                    throw ValidationException::withMessages(['price_max' => 'Maximum price must be at least minimum price.']);
                }
            }
            $listing->fill($data);
            $listing->version++;
            $listing->save();
            $this->audit->handle($org, $actor, 'listing.details.updated', $listing, ['before' => $before, 'after' => $data]);

            return $listing;
        });
    }

    /** @param array<string, mixed> $data */
    public function validateSecondaryFields(bool $secondary, array $data): void
    {
        if ($secondary) {
            return;
        }

        foreach (['valuation_price', 'mortgage_status', 'noc_status', 'transfer_status', 'buyer_contact_id'] as $field) {
            if (array_key_exists($field, $data)) {
                throw ValidationException::withMessages([$field => 'This field is available only for secondary-market listings.']);
            }
        }
    }
}
