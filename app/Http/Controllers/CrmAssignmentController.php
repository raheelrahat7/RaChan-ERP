<?php

namespace App\Http\Controllers;

use App\Domain\Crm\Actions\ConfigureFollowUpEscalation;
use App\Domain\Crm\Actions\ConfigureFollowUpReminders;
use App\Domain\Crm\Actions\ManageAssignmentRouting;
use App\Domain\Crm\Actions\RetryHeldLeads;
use App\Domain\Crm\Jobs\ProcessMetaLead;
use App\Domain\Crm\Models\AssignmentAgent;
use App\Domain\Crm\Models\AssignmentHold;
use App\Domain\Crm\Models\AssignmentRoute;
use App\Domain\Crm\Models\MetaImport;
use App\Domain\Crm\Models\MetaPage;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CrmAssignmentController extends Controller
{
    public function index(Request $request): Response
    {
        $org = $this->organization($request);
        $this->authorize('viewCrm', $org);
        $admin = $this->isAdmin($request, $org);
        $members = $org->users()->orderBy('name')->get(['users.id', 'users.name'])->map->only(['id', 'name']);
        $agents = AssignmentAgent::where('organization_id', $org->id)->get()->keyBy('user_id');

        return Inertia::render('crm/Assignment', [
            'canConfigure' => $admin,
            'timezone' => $org->timezone,
            'followUpReminderDays' => $org->crm_follow_up_reminder_days,
            'followUpEscalationEnabled' => $org->crm_follow_up_escalation_enabled,
            'today' => now($org->timezone)->toDateString(),
            'currentUserId' => $request->user()->id,
            'members' => $admin ? $members->map(fn ($member) => [
                ...$member,
                'max_active_leads' => $agents->get($member['id'])->max_active_leads ?? 0,
                'available_on' => $agents->get($member['id'])?->available_on?->toDateString(),
                'active_leads' => DB::table('crm_leads as lead')->join('crm_pipeline_stages as stage', 'stage.id', '=', 'lead.current_stage_id')->where('lead.organization_id', $org->id)->where('lead.assigned_to', $member['id'])->whereNull('lead.converted_at')->whereNotIn('stage.type', ['won', 'lost'])->count(),
            ]) : [],
            'selfAvailableOn' => $agents->get($request->user()->id)?->available_on?->toDateString(),
            'routes' => $admin ? AssignmentRoute::where('organization_id', $org->id)->where('match_type', '!=', 'meta_page_id')->orderBy('match_type')->orderBy('match_value')->get() : [],
            'departments' => $admin ? DB::table('crm_departments')->where('organization_id', $org->id)->where('active', true)->orderBy('name')->get(['id', 'name']) : [],
            'subdepartments' => $admin ? DB::table('crm_subdepartments')->where('organization_id', $org->id)->where('active', true)->orderBy('name')->get(['id', 'name']) : [],
            'teams' => $admin ? DB::table('crm_teams')->where('organization_id', $org->id)->where('active', true)->orderBy('name')->get(['id', 'name']) : [],
            'holds' => $admin ? AssignmentHold::where('crm_assignment_holds.organization_id', $org->id)->whereNull('crm_assignment_holds.resolved_at')->join('crm_leads as lead', 'lead.id', '=', 'crm_assignment_holds.lead_id')->orderBy('crm_assignment_holds.created_at')->get(['crm_assignment_holds.id', 'crm_assignment_holds.reason', 'crm_assignment_holds.route_label', 'crm_assignment_holds.created_at', 'lead.id as lead_id', 'lead.first_name', 'lead.last_name']) : [],
            'metaPages' => $admin ? MetaPage::where('organization_id', $org->id)->get(['id', 'page_id', 'department_id', 'webhook_key', 'graph_version', 'active', 'subscribed_at'])->map(fn ($page) => [
                'id' => $page->id,
                'page_id' => $page->page_id,
                'department_id' => $page->department_id,
                'graph_version' => $page->graph_version,
                'subscribed_at' => $page->subscribed_at,
                'callback_url' => route('webhooks.meta.page.receive', $page->webhook_key),
            ]) : [],
            'metaImports' => $admin ? MetaImport::where('organization_id', $org->id)->latest()->limit(30)->get(['id', 'leadgen_id', 'form_id', 'status', 'error', 'lead_id', 'created_at']) : [],
        ]);
    }

    public function saveRoute(Request $request, ManageAssignmentRouting $manage, ?int $route = null): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $input = $request->validate([
            'match_type' => ['required', Rule::in(['meta_form_id', 'meta_form_name', 'campaign', 'project'])],
            'match_value' => ['required', 'string', 'max:255'],
            'target_type' => ['required', Rule::in(['members', 'department', 'subdepartment', 'team'])],
            'target_id' => ['nullable', 'integer'],
            'member_ids' => ['present', 'array', 'max:1000'],
            'member_ids.*' => ['integer', 'distinct'],
            'active' => ['required', 'boolean'],
        ]);
        $manage->saveRoute($org, $request->user(), $input, $route);

        return back();
    }

    public function deleteRoute(Request $request, int $route, ManageAssignmentRouting $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $manage->deleteRoute($org, $request->user(), $route);

        return back();
    }

    public function setQuota(Request $request, ManageAssignmentRouting $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $input = $request->validate(['user_id' => ['required', 'integer'], 'max_active_leads' => ['required', 'integer', 'between:0,100000']]);
        $manage->setQuota($org, $request->user(), (int) $input['user_id'], (int) $input['max_active_leads']);

        return back();
    }

    public function checkIn(Request $request, ManageAssignmentRouting $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorize('viewCrm', $org);
        $available = $request->validate(['available' => ['required', 'boolean']])['available'];
        $manage->checkIn($org, $request->user(), (bool) $available);

        return back();
    }

    public function setTimezone(Request $request, ManageAssignmentRouting $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $timezone = $request->validate(['timezone' => ['required', 'timezone']])['timezone'];
        $manage->setTimezone($org, $request->user(), $timezone);

        return back();
    }

    public function setFollowUpReminder(Request $request, ConfigureFollowUpReminders $configure): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $days = $request->validate(['reminder_days' => ['nullable', 'integer', 'between:1,30']])['reminder_days'] ?? null;
        $configure->handle($org, $request->user(), $days === null ? null : (int) $days);

        return back();
    }

    public function setFollowUpEscalation(Request $request, ConfigureFollowUpEscalation $configure): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $enabled = $request->validate(['enabled' => ['required', 'boolean']])['enabled'];
        $configure->handle($org, $request->user(), (bool) $enabled);

        return back();
    }

    public function saveMetaPage(Request $request, ManageAssignmentRouting $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $input = $request->validate([
            'page_id' => ['required', 'regex:/^\d+$/', 'max:100'],
            'page_access_token' => ['nullable', 'string', 'max:4000'],
            'app_secret' => ['nullable', 'string', 'max:4000'],
            'verify_token' => ['nullable', 'string', 'max:4000'],
            'graph_version' => ['nullable', 'regex:/^v\d+\.\d+$/'],
            'department_id' => ['nullable', 'integer'],
        ]);
        $manage->saveMetaPage($org, $request->user(), $input);

        return back();
    }

    public function retry(Request $request, RetryHeldLeads $retry): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $retry->handle($org);

        return back();
    }

    public function subscribeMetaPage(Request $request, int $page, ManageAssignmentRouting $manage): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $manage->subscribeMetaPage($org, $request->user(), $page);

        return back();
    }

    public function retryMetaImport(Request $request, int $import): RedirectResponse
    {
        $org = $this->organization($request);
        $this->authorizeAdmin($request, $org);
        $record = MetaImport::where('organization_id', $org->id)->findOrFail($import);
        if ($record->status === 'failed' && MetaImport::whereKey($record->id)->where('status', 'failed')->update(['status' => 'pending', 'error' => null]) === 1) {
            ProcessMetaLead::dispatch($record->id)->onConnection('redis');
        }

        return back();
    }

    private function organization(Request $request): Organization
    {
        $org = $request->user()->currentOrganization;
        abort_unless($org !== null, 404);

        return $org->refresh();
    }

    private function isAdmin(Request $request, Organization $org): bool
    {
        return $request->user()->hasOrganizationRole($org, OrganizationRole::Owner) || $request->user()->hasOrganizationRole($org, OrganizationRole::Administrator);
    }

    private function authorizeAdmin(Request $request, Organization $org): void
    {
        abort_unless($this->isAdmin($request, $org), 403);
    }
}
