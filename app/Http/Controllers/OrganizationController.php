<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\Actions\ManageLegacyJournalMapping;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Domain\Identity\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationController extends Controller
{
    public function edit(Request $request): Response
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);

        $this->authorize('view', $organization);

        return Inertia::render('organization/Settings', [
            'organization' => $organization->only('id', 'name', 'slug', 'vat_enabled', 'tax_registration_number', 'vat_return_frequency', 'corporate_tax_profile', 'corporate_tax_year_start_month'),
            'members' => $organization->users()->orderBy('name')->get(['users.id', 'name', 'email'])->map(fn (User $user) => [
                ...$user->only('id', 'name', 'email'),
                'role' => $user->pivot->getAttribute('role'),
            ]),
            'invitations' => $organization->invitations()->whereNull('accepted_at')->where('expires_at', '>', now())->get(['id', 'email', 'role', 'expires_at']),
            'roles' => array_map(fn (OrganizationRole $role) => $role->value, OrganizationRole::cases()),
            'canPrepareSignatures' => $request->user()->hasOrganizationRole($organization, OrganizationRole::Owner),
            'canManageMembers' => $request->user()->can('manageMembers', $organization),
        ]);
    }

    public function switch(Request $request, Organization $organization): RedirectResponse
    {
        abort_unless($request->user()->belongsToOrganization($organization), 403);

        $request->user()->update(['current_organization_id' => $organization->id]);

        return back();
    }

    public function updateTaxSettings(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('update', $organization);
        $input = $request->validate([
            'vat_enabled' => ['required', 'boolean'],
            'tax_registration_number' => ['nullable', 'required_if:vat_enabled,true', 'digits:15'],
            'vat_return_frequency' => ['required', 'in:monthly,quarterly'],
            'corporate_tax_profile' => ['nullable', 'in:resident_mainland,qualifying_free_zone'],
            'corporate_tax_year_start_month' => ['required_with:corporate_tax_profile', 'integer', 'between:1,12'],
        ]);
        $organization->update([...$input, 'tax_registration_number' => $input['vat_enabled'] ? $input['tax_registration_number'] : null]);
        $audit->handle($organization, $request->user(), 'organization.tax_settings.updated', $organization, ['vat_enabled' => (bool) $input['vat_enabled'], 'corporate_tax_profile' => $input['corporate_tax_profile']]);

        return back();
    }

    public function invite(Request $request, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization !== null, 404);
        $this->authorize('manageMembers', $organization);

        $input = $request->validate([
            'email' => ['required', 'email'],
            'role' => ['required', 'in:administrator,manager,member,viewer'],
        ]);

        $invitation = OrganizationInvitation::updateOrCreate(
            ['organization_id' => $organization->id, 'email' => Str::lower($input['email'])],
            [
                'role' => $input['role'],
                'token' => Str::random(64),
                'invited_by' => $request->user()->id,
                'expires_at' => now()->addDays(7),
                'accepted_at' => null,
            ],
        );

        $audit->handle($organization, $request->user(), 'organization.invitation.created', null, ['invitation_id' => $invitation->id]);

        return back();
    }

    public function accept(Request $request, OrganizationInvitation $invitation, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        abort_if($invitation->accepted_at || $invitation->expires_at->isPast(), 422);
        abort_unless(Str::lower($request->user()->email) === $invitation->email, 403);

        $invitation->organization->users()->syncWithoutDetaching([$request->user()->id => ['role' => $invitation->role]]);
        $request->user()->update(['current_organization_id' => $invitation->organization_id]);
        $invitation->update(['accepted_at' => now()]);
        $audit->handle($invitation->organization, $request->user(), 'organization.invitation.accepted', $request->user());

        return to_route('organization.edit');
    }

    public function updateMember(Request $request, User $member, RecordOrganizationAuditLog $audit): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $organization->users()->whereKey($member)->exists(), 404);
        $this->authorize('manageMembers', $organization);

        $role = $request->validate(['role' => ['required', 'in:administrator,manager,member,viewer']])['role'];
        $organization->users()->updateExistingPivot($member, ['role' => $role]);
        $audit->handle($organization, $request->user(), 'organization.member.role_updated', $member, ['role' => $role]);

        return back();
    }

    public function removeMember(Request $request, User $member, RecordOrganizationAuditLog $audit, ManageLegacyJournalMapping $mappingApprovals): RedirectResponse
    {
        $organization = $request->user()->currentOrganization;
        abort_unless($organization && $organization->users()->whereKey($member)->exists(), 404);
        $this->authorize('manageMembers', $organization);
        abort_if($member->id === $request->user()->id, 422);

        DB::transaction(function () use ($organization, $request, $member, $audit, $mappingApprovals): void {
            $mappingApprovals->revokeForRemovedMember($organization, $request->user(), $member);
            $organization->users()->detach($member);
            DB::table('crm_team_memberships')->where('organization_id', $organization->id)->where('user_id', $member->id)->delete();
            DB::table('crm_visibility_grants')->where('organization_id', $organization->id)->where('user_id', $member->id)->delete();
            DB::table('crm_edit_grants')->where('organization_id', $organization->id)->where('user_id', $member->id)->delete();
            $audit->handle($organization, $request->user(), 'organization.member.removed', $member);
        });

        return back();
    }
}
