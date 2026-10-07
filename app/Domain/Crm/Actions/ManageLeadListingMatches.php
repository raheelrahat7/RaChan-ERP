<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\LeadListingMatch;
use App\Domain\Crm\Models\LeadRequirement;
use App\Domain\Crm\Services\CrmEditPermission;
use App\Domain\Crm\Services\LeadRequirementSchema;
use App\Domain\Crm\Services\LeadVisibility;
use App\Domain\Crm\Services\ScoreLeadListing;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\CrmLead;
use App\Models\Listing;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManageLeadListingMatches
{
    public function __construct(private LeadVisibility $visibility, private CrmEditPermission $edit, private LeadRequirementSchema $schema, private ScoreLeadListing $scorer, private ManageLeadMatchSettings $settings) {}

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function index(Organization $org, User $actor, int $leadId, array $query): array
    {
        $lead = $this->lead($org, $actor, $leadId);
        $pagination = $this->pagination($query);
        $requirements = $this->requirements($org, $lead);
        $matches = LeadListingMatch::where('organization_id', $org->id)->where('lead_id', $lead->id)
            ->with(['listing.unit.property'])->latest('id')->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);
        $matches->through(fn (LeadListingMatch $match) => $this->serialize($org, $actor, $lead, $match, $requirements));

        return ['matches' => $matches, 'permissions' => ['read' => true, 'add' => $this->canEdit($org, $actor, $lead)], 'configuration' => $this->settings->show($org, $actor)];
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function suggest(Organization $org, User $actor, int $leadId, array $query): array
    {
        $lead = $this->lead($org, $actor, $leadId);
        $pagination = $this->pagination($query);
        $q = Validator::make($query, ['q' => ['nullable', 'string', 'max:100']])->validate()['q'] ?? null;
        $requirements = $this->requirements($org, $lead);
        $linked = LeadListingMatch::where('organization_id', $org->id)->where('lead_id', $lead->id)->select('listing_id');
        $listings = $this->listings($org)->where('status', 'active')->whereHas('unit', fn ($unit) => $unit->where('status', 'available'))
            ->whereNotIn('id', $linked)
            ->when($q, fn ($builder) => $builder->where(fn ($search) => $search->where('reference', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhereHas('unit.property', fn ($property) => $property->where('name', 'like', '%'.addcslashes($q, '%_\\').'%')->orWhere('city', 'like', '%'.addcslashes($q, '%_\\').'%'))))
            ->with('unit.property')->get();
        $ranked = $listings->map(fn (Listing $listing) => ['listing' => $this->listing($listing), ...$this->scorer->score($requirements, $listing)])
            ->sort(fn ($a, $b) => $b['match_percent'] <=> $a['match_percent'] ?: $a['listing']['id'] <=> $b['listing']['id'])->values();
        $page = $ranked->forPage($pagination['page'], $pagination['per_page'])->map(fn ($candidate) => [...$candidate, 'permissions' => ['read' => true, 'add' => $this->canEdit($org, $actor, $lead)]])->values();

        return ['candidates' => ['data' => $page, 'total' => $ranked->count(), 'current_page' => $pagination['page'], 'per_page' => $pagination['per_page'], 'last_page' => max(1, (int) ceil($ranked->count() / $pagination['per_page']))],
            'permissions' => ['read' => true, 'add' => $this->canEdit($org, $actor, $lead)]];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function create(Organization $org, User $actor, int $leadId, array $input): array
    {
        $this->requireEdit($org, $actor, $this->lead($org, $actor, $leadId));
        $data = Validator::make($input, $this->rules(true))->validate();

        return DB::transaction(function () use ($org, $actor, $leadId, $data): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $lead = $this->lead($org, $actor, $leadId, true);
            $this->requireEdit($org, $actor, $lead);
            $listing = $this->listings($org)->where('status', 'active')->whereHas('unit', fn ($unit) => $unit->where('status', 'available'))
                ->with('unit.property')->find((int) $data['listing_id']);
            if (! $listing) {
                throw ValidationException::withMessages(['listing_id' => 'Select an active available listing in this organization.']);
            }
            if (LeadListingMatch::where('lead_id', $lead->id)->where('listing_id', $listing->id)->exists()) {
                throw ValidationException::withMessages(['listing_id' => 'This listing is already matched to the lead.']);
            }
            $this->validateViewing($org, $actor, $data);
            $match = LeadListingMatch::create(['organization_id' => $org->id, 'lead_id' => $lead->id, 'listing_id' => $listing->id,
                'match_percent_override' => $data['match_percent_override'] ?? null, 'shared' => $data['shared'] ?? false,
                'viewing_status' => $data['viewing_status'] ?? 'not_scheduled', 'viewing_at' => $this->viewingAt($data['viewing_at'] ?? null),
                'notes' => $data['notes'] ?? null, 'version' => 1]);
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.lead.match_added', $lead, ['match_id' => $match->id, 'listing_id' => $listing->id]);
            $match->setRelation('listing', $listing);

            return ['match' => $this->serialize($org, $actor, $lead, $match, $this->requirements($org, $lead))];
        });
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function update(Organization $org, User $actor, int $leadId, int $matchId, array $input): array
    {
        $this->requireEdit($org, $actor, $this->lead($org, $actor, $leadId));
        $data = Validator::make($input, $this->rules(false))->validate();

        return DB::transaction(function () use ($org, $actor, $leadId, $matchId, $data): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $lead = $this->lead($org, $actor, $leadId, true);
            $this->requireEdit($org, $actor, $lead);
            $match = LeadListingMatch::where('organization_id', $org->id)->where('lead_id', $lead->id)->findOrFail($matchId);
            $this->checkVersion($match, $data['expected_version']);
            $merged = [...$match->only(['match_percent_override', 'shared', 'viewing_status', 'viewing_at', 'notes']), ...$data];
            $this->validateViewing($org, $actor, $merged, $match->viewing_status);
            foreach (['match_percent_override', 'shared', 'viewing_status', 'notes'] as $field) {
                if (array_key_exists($field, $data)) {
                    $match->{$field} = $data[$field];
                }
            }
            if (array_key_exists('viewing_at', $data)) {
                $match->viewing_at = $this->viewingAt($data['viewing_at']);
            }
            $match->version++;
            $match->save();
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.lead.match_updated', $lead, ['match_id' => $match->id, 'listing_id' => $match->listing_id, 'changed_fields' => array_keys(array_diff_key($data, ['expected_version' => true]))]);
            $match->load('listing.unit.property');

            return ['match' => $this->serialize($org, $actor, $lead, $match, $this->requirements($org, $lead))];
        });
    }

    /** @param array<string, mixed> $input
     * @return array{deleted: true, id: int, version: int}
     */
    public function delete(Organization $org, User $actor, int $leadId, int $matchId, array $input): array
    {
        $this->requireEdit($org, $actor, $this->lead($org, $actor, $leadId));
        $data = Validator::make($input, ['expected_version' => ['required', 'integer', 'min:1']])->validate();

        return DB::transaction(function () use ($org, $actor, $leadId, $matchId, $data): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $lead = $this->lead($org, $actor, $leadId, true);
            $this->requireEdit($org, $actor, $lead);
            $match = LeadListingMatch::where('organization_id', $org->id)->where('lead_id', $lead->id)->findOrFail($matchId);
            $this->checkVersion($match, $data['expected_version']);
            $id = $match->id;
            $listingId = $match->listing_id;
            $version = $match->version;
            $match->delete();
            app(RecordOrganizationAuditLog::class)->handle($org, $actor, 'crm.lead.match_removed', $lead, ['match_id' => $id, 'listing_id' => $listingId]);

            return ['deleted' => true, 'id' => $id, 'version' => $version];
        });
    }

    /** @return Builder<Listing> */
    private function listings(Organization $org): Builder
    {
        return Listing::where('organization_id', $org->id)->whereHas('unit', fn ($unit) => $unit->where('organization_id', $org->id)
            ->whereHas('property', fn ($property) => $property->where('organization_id', $org->id)));
    }

    /** @param array<string, mixed> $requirements
     * @return array<string, mixed>
     */
    private function serialize(Organization $org, User $actor, CrmLead $lead, LeadListingMatch $match, array $requirements): array
    {
        $listing = $match->listing;
        $score = $this->scorer->score($requirements, $listing);
        $canEdit = $this->canEdit($org, $actor, $lead);

        return ['id' => $match->id, 'lead_id' => $match->lead_id, 'listing_id' => $match->listing_id, 'version' => $match->version,
            'listing' => $this->listing($listing), 'match_percent' => $match->match_percent_override ?? $score['match_percent'],
            'match_percent_override' => $match->match_percent_override, 'score_source' => $match->match_percent_override === null ? 'automatic' : 'manual',
            'matched_on' => $score['matched_on'], 'evaluated_on' => $score['evaluated_on'], 'shared' => $match->shared,
            'viewing_status' => $match->viewing_status, 'viewing_at' => $match->viewing_at?->toIso8601String(),
            'notes' => $match->notes, 'permissions' => ['read' => true, 'edit' => $canEdit, 'delete' => $canEdit]];
    }

    /** @return array<string, mixed> */
    private function listing(Listing $listing): array
    {
        $unit = $listing->unit;
        $property = $unit?->property;

        return ['id' => $listing->id, 'reference' => $listing->reference, 'status' => $listing->status, 'purpose' => $listing->purpose,
            'market_segment' => $listing->market_segment, 'price' => (string) $listing->price, 'currency' => $listing->currency,
            'unit' => ['id' => $unit?->id, 'number' => $unit?->number, 'type' => $unit?->type, 'area' => $unit?->area, 'area_unit' => $unit?->area_unit],
            'property' => ['id' => $property?->id, 'name' => $property?->name, 'city' => $property?->city], 'community' => null];
    }

    /** @return array<string, mixed> */
    private function requirements(Organization $org, CrmLead $lead): array
    {
        $stored = LeadRequirement::where('organization_id', $org->id)->where('lead_id', $lead->id)->value('data');

        return [...$this->schema->defaults(), ...(is_string($stored) ? (json_decode($stored, true) ?: []) : ($stored ?? []))];
    }

    /** @param array<string, mixed> $query
     * @return array{page: int, per_page: int}
     */
    private function pagination(array $query): array
    {
        $data = Validator::make($query, ['page' => ['nullable', 'integer', 'min:1'], 'per_page' => ['nullable', 'integer', 'between:1,100']])->validate();

        return ['page' => (int) ($data['page'] ?? 1), 'per_page' => (int) ($data['per_page'] ?? 25)];
    }

    /** @return array<string, list<string>> */
    private function rules(bool $create): array
    {
        return [...($create ? ['listing_id' => ['required', 'integer', 'min:1']] : ['expected_version' => ['required', 'integer', 'min:1']]),
            'match_percent_override' => ['sometimes', 'nullable', 'integer', 'between:0,100'],
            'shared' => ['sometimes', 'boolean'], 'viewing_status' => ['sometimes', 'string', 'max:60'],
            'viewing_at' => ['sometimes', 'nullable', 'date'], 'notes' => ['sometimes', 'nullable', 'string', 'max:5000']];
    }

    /** @param array<string, mixed> $data */
    private function validateViewing(Organization $org, User $actor, array $data, ?string $previous = null): void
    {
        $status = $data['viewing_status'] ?? 'not_scheduled';
        $active = array_column(array_filter($this->settings->show($org, $actor)['viewing_statuses'], fn ($option) => $option['active']), 'value');
        if (! in_array($status, $active, true) && $status !== $previous) {
            throw ValidationException::withMessages(['viewing_status' => 'Select an active organization viewing status.']);
        }
        if ($status === 'scheduled' && empty($data['viewing_at'])) {
            throw ValidationException::withMessages(['viewing_at' => 'Set a viewing date and time for a scheduled viewing.']);
        }
    }

    private function viewingAt(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->utc();
    }

    private function checkVersion(LeadListingMatch $match, int $expected): void
    {
        if ($match->version !== $expected) {
            throw ValidationException::withMessages(['expected_version' => 'This match changed. Reload it and try again.']);
        }
    }

    private function canEdit(Organization $org, User $actor, CrmLead $lead): bool
    {
        return ! $lead->converted_at && $this->edit->granted($org, $actor);
    }

    private function requireEdit(Organization $org, User $actor, CrmLead $lead): void
    {
        abort_unless($this->edit->granted($org, $actor), 403);
        if ($lead->converted_at) {
            throw ValidationException::withMessages(['lead' => 'Converted lead matches cannot be changed.']);
        }
    }

    private function lead(Organization $org, User $actor, int $leadId, bool $lock = false): CrmLead
    {
        Gate::forUser($actor)->authorize('viewCrm', $org);
        $lead = CrmLead::where('organization_id', $org->id)->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($leadId);
        abort_unless($this->visibility->canSeeLead($org, $actor, $lead->assigned_to), 404);

        return $lead;
    }
}
