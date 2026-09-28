<?php

namespace App\Domain\Crm\Actions;

use App\Domain\Crm\Models\AssignmentAgent;
use App\Domain\Crm\Models\AssignmentRoute;
use App\Domain\Crm\Models\MetaPage;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ManageAssignmentRouting
{
    public function __construct(private RecordOrganizationAuditLog $audit, private RetryHeldLeads $retry) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveRoute(Organization $org, User $actor, array $input, ?int $id = null): void
    {
        DB::transaction(function () use ($org, $actor, $input, $id): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $route = $id ? AssignmentRoute::where('organization_id', $org->id)->findOrFail($id) : new AssignmentRoute(['organization_id' => $org->id]);
            $type = $input['target_type'];
            $memberIds = array_values(array_unique(array_map('intval', $input['member_ids'] ?? [])));
            if ($type === 'members' && (! $memberIds || count($memberIds) !== $org->users()->whereIn('users.id', $memberIds)->count())) {
                throw ValidationException::withMessages(['member_ids' => 'Select organization members for this route.']);
            }
            if ($type !== 'members') {
                $table = match ($type) {
                    'department' => 'crm_departments',
                    'subdepartment' => 'crm_subdepartments',
                    'team' => 'crm_teams',
                    default => throw ValidationException::withMessages(['target_type' => 'Select a valid routing destination type.']),
                };
                if (! DB::table($table)->where('organization_id', $org->id)->where('active', true)->where('id', $input['target_id'] ?? null)->exists()) {
                    throw ValidationException::withMessages(['target_id' => 'Select an active destination in this organization.']);
                }
            }
            sort($memberIds);
            $value = mb_strtolower(trim($input['match_value']));
            if ($value === '') {
                throw ValidationException::withMessages(['match_value' => 'Enter a match value.']);
            }
            if (AssignmentRoute::where('organization_id', $org->id)->where('match_type', $input['match_type'])->where('match_value', $value)->when($id, fn ($query) => $query->whereKeyNot($id))->exists()) {
                throw ValidationException::withMessages(['match_value' => 'A route already exists for this match.']);
            }
            $before = $route->exists ? $route->only('match_type', 'match_value', 'target_type', 'target_id', 'member_ids', 'active') : null;
            $route->fill(['match_type' => $input['match_type'], 'match_value' => $value, 'target_type' => $type, 'target_id' => $type === 'members' ? null : (int) $input['target_id'], 'member_ids' => $type === 'members' ? $memberIds : null, 'active' => (bool) $input['active'], 'last_user_id' => null])->save();
            $this->audit->handle($org, $actor, 'crm.assignment.route_saved', $route, ['before' => $before, 'after' => $route->only('match_type', 'match_value', 'target_type', 'target_id', 'member_ids', 'active')]);
        });
        $this->retry->handle($org);
    }

    public function deleteRoute(Organization $org, User $actor, int $id): void
    {
        DB::transaction(function () use ($org, $actor, $id): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $route = AssignmentRoute::where('organization_id', $org->id)->findOrFail($id);
            $this->audit->handle($org, $actor, 'crm.assignment.route_deleted', $route, $route->only('match_type', 'match_value'));
            $route->delete();
        });
        $this->retry->handle($org);
    }

    public function setQuota(Organization $org, User $actor, int $userId, int $limit): void
    {
        DB::transaction(function () use ($org, $actor, $userId, $limit): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            if (! $org->users()->where('users.id', $userId)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'Select an organization member.']);
            }
            $settings = AssignmentAgent::firstOrCreate(['organization_id' => $org->id, 'user_id' => $userId]);
            $before = $settings->max_active_leads;
            $settings->update(['max_active_leads' => $limit]);
            $this->audit->handle($org, $actor, 'crm.assignment.quota_updated', $settings, ['user_id' => $userId, 'before' => $before, 'after' => $limit]);
        });
        $this->retry->handle($org);
    }

    public function checkIn(Organization $org, User $actor, bool $available): void
    {
        DB::transaction(function () use ($org, $actor, $available): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $settings = AssignmentAgent::firstOrCreate(['organization_id' => $org->id, 'user_id' => $actor->id]);
            $day = $available ? now($org->timezone)->toDateString() : null;
            $settings->update(['available_on' => $day]);
            $this->audit->handle($org, $actor, 'crm.assignment.availability_updated', $settings, ['available_on' => $day]);
        });
        if ($available) {
            $this->retry->handle($org);
        }
    }

    public function setTimezone(Organization $org, User $actor, string $timezone): void
    {
        DB::transaction(function () use ($org, $actor, $timezone): void {
            $locked = Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $before = $locked->timezone;
            $locked->update(['timezone' => $timezone]);
            $this->audit->handle($locked, $actor, 'crm.assignment.timezone_updated', $locked, ['before' => $before, 'after' => $timezone]);
        });
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function saveMetaPage(Organization $org, User $actor, array $input): void
    {
        DB::transaction(function () use ($org, $actor, $input): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $pageId = $input['page_id'];
            $existing = MetaPage::where('page_id', $pageId)->first();
            if ($existing && $existing->organization_id !== $org->id) {
                throw ValidationException::withMessages(['page_id' => 'This Meta page is connected to another organization.']);
            }
            if (! $existing && empty($input['page_access_token'])) {
                throw ValidationException::withMessages(['page_access_token' => 'Enter a Page access token for a new connection.']);
            }
            $page = $existing ?? new MetaPage(['organization_id' => $org->id, 'page_id' => $pageId]);
            $departmentId = $input['department_id'] ?? null;
            if ($departmentId !== null && ! DB::table('crm_departments')->where('organization_id', $org->id)->where('active', true)->where('id', $departmentId)->exists()) {
                throw ValidationException::withMessages(['department_id' => 'Select an active department in this organization.']);
            }
            $settings = ['active' => true];
            if (! empty($input['page_access_token'])) {
                $settings['page_access_token'] = $input['page_access_token'];
            }
            $settings['department_id'] = $departmentId;
            foreach (['app_secret', 'verify_token', 'graph_version'] as $field) {
                if (! empty($input[$field])) {
                    $settings[$field] = $input[$field];
                }
            }
            $appSecret = $settings['app_secret'] ?? $existing?->app_secret;
            $verifyToken = $settings['verify_token'] ?? $existing?->verify_token;
            $graphVersion = $settings['graph_version'] ?? $existing?->graph_version;
            if ($appSecret || $verifyToken || $graphVersion) {
                if (! $appSecret || ! $verifyToken || ! $graphVersion) {
                    throw ValidationException::withMessages(['meta' => 'Enter the app secret, verify token, and Graph API version together.']);
                }
            } elseif (! config('services.meta.app_secret') || ! config('services.meta.verify_token') || ! config('services.meta.graph_version')) {
                throw ValidationException::withMessages(['meta' => 'Enter the Meta app secret, verify token, and Graph API version for this Page.']);
            }
            foreach (['page_access_token', 'app_secret', 'verify_token'] as $credential) {
                if ($existing && ! empty($input[$credential]) && $input[$credential] !== $existing->{$credential}) {
                    $settings['subscribed_at'] = null;
                }
            }
            if (! $page->webhook_key) {
                $settings['webhook_key'] = (string) Str::uuid();
            }
            $page->fill($settings)->save();
            $route = AssignmentRoute::where('organization_id', $org->id)->where('match_type', 'meta_page_id')->where('match_value', $pageId)->first();
            if ($departmentId !== null) {
                $route ??= new AssignmentRoute(['organization_id' => $org->id, 'match_type' => 'meta_page_id', 'match_value' => $pageId]);
                $route->fill(['target_type' => 'department', 'target_id' => $departmentId, 'member_ids' => null, 'last_user_id' => null, 'active' => true])->save();
            } elseif ($route) {
                $route->delete();
            }
            $this->audit->handle($org, $actor, 'crm.meta.page_connected', $page, ['page_id' => $pageId]);
        });
        $this->retry->handle($org);
    }

    public function subscribeMetaPage(Organization $org, User $actor, int $id): void
    {
        $page = MetaPage::where('organization_id', $org->id)->where('active', true)->findOrFail($id);
        $version = $page->graph_version ?: config('services.meta.graph_version');
        if (! is_string($version) || ! preg_match('/^v\d+\.\d+$/', $version)) {
            throw ValidationException::withMessages(['meta' => 'Enter a Graph API version for this Page before subscribing.']);
        }
        $response = Http::withToken($page->page_access_token)->asForm()->timeout(15)
            ->post('https://graph.facebook.com/'.$version.'/'.$page->page_id.'/subscribed_apps', ['subscribed_fields' => 'leadgen']);
        if (! $response->successful() || $response->json('success') !== true) {
            throw ValidationException::withMessages(['meta' => 'Meta did not accept the page subscription. Check the Page token, permissions, and app webhook setup.']);
        }
        $page->update(['subscribed_at' => now()]);
        $this->audit->handle($org, $actor, 'crm.meta.page_subscribed', $page, ['page_id' => $page->page_id]);
    }
}
