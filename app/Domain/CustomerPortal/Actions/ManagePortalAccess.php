<?php

namespace App\Domain\CustomerPortal\Actions;

use App\Domain\CustomerPortal\Models\PortalGrant;
use App\Domain\CustomerPortal\Models\PortalInvitation;
use App\Domain\CustomerPortal\Models\PortalLeaseInvoice;
use App\Domain\Identity\Actions\RecordOrganizationAuditLog;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Organization;
use App\Models\Owner;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ManagePortalAccess
{
    public function __construct(private RecordOrganizationAuditLog $audit) {}

    /** @param array<string,mixed> $input
     * @return array{invitation: PortalInvitation, token: string} */
    public function invite(Organization $org, User $actor, array $input): array
    {
        Gate::forUser($actor)->authorize('manageMembers', $org);
        Validator::make($input, ['email' => ['required', 'email', 'max:255'], 'role' => ['required', 'in:tenant,landlord'], 'entity_id' => ['required', 'integer']])->validate();

        return DB::transaction(function () use ($org, $actor, $input): array {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $role = $input['role'];
            $entity = $role === 'tenant' ? Tenant::where('organization_id', $org->id)->findOrFail((int) $input['entity_id']) : Owner::where('organization_id', $org->id)->findOrFail((int) $input['entity_id']);
            $token = bin2hex(random_bytes(32));
            $email = strtolower(trim($input['email']));
            $invite = PortalInvitation::create(['organization_id' => $org->id, 'email' => $email, 'role' => $role, 'tenant_id' => $role === 'tenant' ? $entity->id : null, 'owner_id' => $role === 'landlord' ? $entity->id : null, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addDays(7), 'created_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'portal.invitation.created', $invite, ['role' => $role, 'entity_id' => $entity->id, 'email' => $email]);

            return ['invitation' => $invite, 'token' => $token];
        });
    }

    public function invitation(User $actor, string $token): PortalInvitation
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/', $token) === 1, 404);
        $invite = PortalInvitation::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->where('expires_at', '>', now())->firstOrFail();
        abort_unless($actor->hasVerifiedEmail() && strtolower($actor->email) === $invite->email, 404);

        return $invite;
    }

    public function accept(User $actor, string $token): PortalGrant
    {
        $invite = $this->invitation($actor, $token);

        return DB::transaction(function () use ($actor, $invite, $token): PortalGrant {
            $org = Organization::whereKey($invite->organization_id)->lockForUpdate()->firstOrFail();
            $invite = $this->invitation($actor, $token);
            $existing = PortalGrant::where('portal_invitation_id', $invite->id)->where('user_id', $actor->id)->first();
            if ($existing !== null) {
                abort_unless($existing->revoked_at === null, 404);

                return $existing;
            }
            abort_unless($invite->accepted_at === null, 404);
            if ($invite->role === 'tenant') {
                Tenant::where('organization_id', $org->id)->findOrFail($invite->tenant_id);
            } else {
                Owner::where('organization_id', $org->id)->findOrFail($invite->owner_id);
            }
            $grant = PortalGrant::create(['organization_id' => $org->id, 'user_id' => $actor->id, 'portal_invitation_id' => $invite->id, 'role' => $invite->role, 'tenant_id' => $invite->tenant_id, 'owner_id' => $invite->owner_id]);
            $invite->update(['accepted_at' => now()]);
            $this->audit->handle($org, $actor, 'portal.invitation.accepted', $grant, ['invitation_id' => $invite->id]);

            return $grant;
        });
    }

    public function revoke(Organization $org, User $actor, PortalInvitation $invite, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageMembers', $org);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Record a revocation reason.')]);
        }
        DB::transaction(function () use ($org, $actor, $invite, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $invite = PortalInvitation::where('organization_id', $org->id)->findOrFail($invite->id);
            if ($invite->revoked_at !== null) {
                return;
            }
            $invite->update(['revoked_at' => now()]);
            PortalGrant::where('organization_id', $org->id)->where('portal_invitation_id', $invite->id)->update(['revoked_at' => now(), 'revocation_reason' => $reason]);
            $this->audit->handle($org, $actor, 'portal.access.revoked', $invite, ['reason' => $reason]);
        });
    }

    public function revokeInvoice(Organization $org, User $actor, PortalLeaseInvoice $link, string $reason): void
    {
        Gate::forUser($actor)->authorize('manageMembers', $org);
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:2000']])->validate();
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => __('Record why sharing should stop.')]);
        }
        DB::transaction(function () use ($org, $actor, $link, $reason): void {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $link = PortalLeaseInvoice::where('organization_id', $org->id)->findOrFail($link->id);
            if ($link->revoked_at !== null) {
                return;
            }
            $link->update(['revoked_at' => now(), 'revocation_reason' => $reason]);
            $this->audit->handle($org, $actor, 'portal.invoice.unshared', $link, ['reason' => $reason]);
        });
    }

    public function linkInvoice(Organization $org, User $actor, int $leaseId, int $invoiceId): PortalLeaseInvoice
    {
        Gate::forUser($actor)->authorize('manageMembers', $org);

        return DB::transaction(function () use ($org, $actor, $leaseId, $invoiceId): PortalLeaseInvoice {
            Organization::whereKey($org->id)->lockForUpdate()->firstOrFail();
            $lease = Lease::where('organization_id', $org->id)->findOrFail($leaseId);
            $invoice = Invoice::where('organization_id', $org->id)->findOrFail($invoiceId);
            if (! in_array($invoice->status, ['posted', 'partial', 'paid'], true)) {
                throw ValidationException::withMessages(['invoice_id' => __('Only posted invoices can be shared.')]);
            }
            if ($lease->tenant_id === null || $lease->contact_id === null || $lease->contact_id !== $invoice->contact_id) {
                throw ValidationException::withMessages(['invoice_id' => __('The invoice must belong to the linked lease customer.')]);
            }
            $existing = PortalLeaseInvoice::where('organization_id', $org->id)->where('invoice_id', $invoiceId)->whereNull('revoked_at')->first();
            if ($existing !== null) {
                if ($existing->lease_id !== $leaseId) {
                    throw ValidationException::withMessages(['invoice_id' => __('This invoice is already linked to a different lease.')]);
                }

                return $existing;
            }
            $link = PortalLeaseInvoice::create(['organization_id' => $org->id, 'lease_id' => $leaseId, 'invoice_id' => $invoiceId, 'created_by' => $actor->id]);
            $this->audit->handle($org, $actor, 'portal.invoice.linked', $link, ['lease_id' => $leaseId, 'invoice_id' => $invoiceId]);

            return $link;
        });
    }
}
