<?php

namespace App\Domain\RealEstate\Queries;

use App\Models\Listing;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;

class ListingOverview
{
    /** @param array<string, mixed> $filters
     * @return Builder<Listing>
     */
    public function query(Organization $org, array $filters): Builder
    {
        return Listing::where('organization_id', $org->id)
            ->when($filters['market_segment'] ?? null, fn ($q, $segment) => $segment === 'secondary' ? $q->where(fn ($scope) => $scope->where('market_segment', 'secondary')->orWhere('purpose', 'rent')) : $q->where('purpose', 'sale')->where('market_segment', 'primary'))
            ->when($filters['status'] ?? null, fn ($q, $value) => $q->where('status', $value))
            ->when($filters['workflow_status'] ?? null, fn ($q, $value) => $value === 'draft' ? $q->where(fn ($scope) => $scope->where('workflow_status', 'draft')->orWhereNull('workflow_status')) : $q->where('workflow_status', $value))
            ->when($filters['listing_category'] ?? null, fn ($q, $value) => $q->where('listing_category', $value))
            ->when($filters['broker_id'] ?? null, fn ($q, $value) => $q->where('broker_id', $value))
            ->when($filters['cost_centre_id'] ?? null, fn ($q, $value) => $q->where('cost_centre_id', $value))
            ->when($filters['emirate'] ?? null, fn ($q, $value) => $q->where('emirate', $value))
            ->when($filters['community'] ?? null, fn ($q, $value) => $q->where('community', $value))
            ->when($filters['q'] ?? null, fn ($q, $value) => $q->where(fn ($s) => $s->where('reference', 'like', '%'.$value.'%')->orWhere('community', 'like', '%'.$value.'%')->orWhere('sub_community', 'like', '%'.$value.'%')))
            ->orderBy(in_array($filters['sort'] ?? null, ['price_asc', 'price_desc'], true) ? 'price' : 'id', ($filters['sort'] ?? 'latest') === 'price_asc' ? 'asc' : 'desc');
    }

    /** @return array<string, mixed> */
    public function serialize(Listing $listing, bool $canManage): array
    {
        $listing->loadMissing(['unit.property', 'unit.building', 'seller', 'buyerContact']);
        $values = $listing->toArray();
        $values['workflow_status'] = $listing->workflow_status ?? 'draft';
        $values['property'] = $listing->unit->property?->only('id', 'name', 'city', 'type');
        $values['unit'] = $listing->unit->only('id', 'number', 'floor', 'type', 'area', 'area_unit', 'building_id');
        $values['building'] = $listing->unit->building?->only('id', 'name', 'floors');
        $values['seller'] = $listing->seller?->only('id', 'name', 'phone', 'email');
        $values['buyer'] = $listing->buyerContact?->only('id', 'first_name', 'last_name', 'phone', 'email');
        unset($values['buyer_contact']);
        $sizeSqft = $listing->size_sqft ?: ($listing->unit->area_unit === 'sq_ft' ? $listing->unit->area : null);
        $values['price_per_sqft'] = $sizeSqft && (float) $sizeSqft > 0 ? number_format((float) $listing->price / (float) $sizeSqft, 2, '.', '') : null;
        $values['public_url'] = $listing->public_token ? route('public.listings.show', $listing->public_token) : null;
        unset($values['public_token']);
        $values['permissions'] = ['read' => true, 'edit' => $canManage, 'change_status' => $canManage];

        return $values;
    }

    /** @param array<string, mixed> $filters
     * @return array<int, array{emirate: string|null, total: int}>
     */
    public function emirateSummary(Organization $org, array $filters): array
    {
        return $this->query($org, $filters)->reorder()->selectRaw('emirate, COUNT(*) as total')->groupBy('emirate')->orderBy('emirate')->get()->map(fn ($row) => ['emirate' => $row->emirate, 'total' => (int) $row->getAttribute('total')])->all();
    }

    /** @param array<string, mixed> $filters
     * @return array<int, array{code: string, total: int}>
     */
    public function secondaryStatusSummary(Organization $org, array $filters): array
    {
        unset($filters['workflow_status']);
        $filters['market_segment'] = 'secondary';

        return $this->query($org, $filters)->reorder()
            ->selectRaw("COALESCE(workflow_status, 'draft') as code, COUNT(*) as total")
            ->groupBy('code')->orderBy('code')->get()
            ->map(fn ($row) => ['code' => $row->getAttribute('code'), 'total' => (int) $row->getAttribute('total')])->all();
    }
}
