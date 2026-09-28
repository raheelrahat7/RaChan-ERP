<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
defineProps<{
    tenants: { id: number; name: string; reference: string | null }[];
    owners: { id: number; name: string; reference: string | null }[];
    invitations: {
        data: {
            id: number;
            email: string;
            role: string;
            tenant_id: number | null;
            owner_id: number | null;
            expires_at: string;
            accepted_at: string | null;
            revoked_at: string | null;
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    invoiceLinks: {
        data: {
            id: number;
            lease_id: number;
            invoice_id: number;
            revoked_at: string | null;
        }[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    invitationUrl: string | null;
}>();
const form = useForm({ email: '', role: 'tenant', entity_id: '' });
const revokeForm = useForm({ invitation_id: '', reason: '' });
const invoiceForm = useForm({ lease_id: '', invoice_id: '' });
const unshareForm = useForm({ link_id: '', reason: '' });
function unshare(): void {
    unshareForm.post(
        `/organization/portal-access/invoices/${unshareForm.link_id}/revoke`,
        { preserveScroll: true, onSuccess: () => unshareForm.reset() },
    );
}
function invite(): void {
    form.post('/organization/portal-access/invitations', {
        preserveScroll: true,
        onSuccess: () => form.reset('email', 'entity_id'),
    });
}
function revoke(): void {
    revokeForm.post(
        `/organization/portal-access/invitations/${revokeForm.invitation_id}/revoke`,
        { preserveScroll: true, onSuccess: () => revokeForm.reset() },
    );
}
function link(): void {
    invoiceForm.post('/organization/portal-access/invoices', {
        preserveScroll: true,
        onSuccess: () => invoiceForm.reset(),
    });
}
</script>
<template>
    <Head title="Customer portal access" />
    <div class="mx-auto w-full max-w-5xl space-y-6 p-4 md:p-6">
        <Heading
            title="Customer portal access"
            description="Invite tenants and landlords through explicit party links."
        /><Link href="/organization" class="text-sm underline">{{
            t('Organization settings')
        }}</Link>
        <Card
            ><CardHeader><CardTitle>Create invitation</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p class="text-muted-foreground text-sm">
                    Invitations expire in seven days and require a verified
                    account with the same email. Copy the generated link and
                    share it with the intended person. No email is sent by this
                    action.
                </p>
                <div
                    v-if="invitationUrl"
                    class="rounded-md border p-3 break-all"
                    role="status"
                >
                    <p>Invitation link (shown once):</p>
                    <a :href="invitationUrl" class="underline">{{
                        invitationUrl
                    }}</a>
                </div>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="invite"
                >
                    <label class="text-sm"
                        >{{ t('Email')
                        }}<Input
                            v-model="form.email"
                            required
                            type="email"
                            maxlength="255" /></label
                    ><label class="text-sm"
                        >Role<select
                            v-model="form.role"
                            class="border-input block h-9 w-full rounded-md border px-3"
                            @change="form.entity_id = ''"
                        >
                            <option value="tenant">Tenant</option>
                            <option value="landlord">Landlord</option>
                        </select></label
                    ><label class="text-sm"
                        >Linked party<select
                            v-model="form.entity_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Select party</option>
                            <option
                                v-for="party in form.role === 'tenant'
                                    ? tenants
                                    : owners"
                                :key="party.id"
                                :value="String(party.id)"
                            >
                                {{ party.name }} · {{ party.reference }}
                            </option>
                        </select></label
                    >
                    <p
                        v-for="(message, field) in form.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="form.processing"
                        >Create invitation link</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Explicit lease invoice links</CardTitle></CardHeader
            ><CardContent
                ><p class="text-muted-foreground mb-3 text-sm">
                    Share a posted invoice through its lease. The invoice must
                    belong to the same customer as the lease. Service-charge and
                    deposit invoices are linked automatically.
                </p>
                <form class="grid gap-3 md:grid-cols-2" @submit.prevent="link">
                    <label class="text-sm"
                        >Lease ID<Input
                            v-model="invoiceForm.lease_id"
                            required
                            type="number"
                            min="1" /></label
                    ><label class="text-sm"
                        >Invoice ID<Input
                            v-model="invoiceForm.invoice_id"
                            required
                            type="number"
                            min="1"
                    /></label>
                    <p
                        v-for="(message, field) in invoiceForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="invoiceForm.processing"
                        >Link invoice</Button
                    >
                </form></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Invoice sharing history</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <p v-if="!invoiceLinks.data.length">
                    No explicit invoice links.
                </p>
                <p
                    v-for="item in invoiceLinks.data"
                    :key="item.id"
                    class="border-b pb-2 text-sm"
                >
                    Link #{{ item.id }} · Lease #{{ item.lease_id }} · Invoice
                    #{{ item.invoice_id }} ·
                    {{ item.revoked_at ? 'Revoked' : 'Shared' }}
                </p>
                <Pagination :links="invoiceLinks.links" />
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="unshare"
                >
                    <label class="text-sm"
                        >Link ID to revoke<Input
                            v-model="unshareForm.link_id"
                            required
                            type="number"
                            min="1"
                    /></label>
                    <label class="text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="unshareForm.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in unshareForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        variant="outline"
                        :disabled="unshareForm.processing"
                        >Stop sharing invoice</Button
                    >
                </form>
                <p class="text-muted-foreground text-sm">
                    This revokes explicit links. Service-charge and deposit
                    invoices remain visible through their existing lease
                    relationships.
                </p>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Invitations and access</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!invitations.data.length">No invitations created.</p>
                <article
                    v-for="inviteItem in invitations.data"
                    :key="inviteItem.id"
                    class="border-b pb-3 text-sm"
                >
                    #{{ inviteItem.id }} · {{ inviteItem.email }} ·
                    {{ inviteItem.role }} · Party #{{
                        inviteItem.tenant_id ?? inviteItem.owner_id
                    }}
                    ·
                    {{
                        inviteItem.revoked_at
                            ? 'Revoked'
                            : inviteItem.accepted_at
                              ? 'Accepted'
                              : 'Pending'
                    }}
                    · Expires {{ inviteItem.expires_at }}
                </article>
                <Pagination :links="invitations.links" />
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="revoke"
                >
                    <label class="text-sm"
                        >Invitation ID to revoke<Input
                            v-model="revokeForm.invitation_id"
                            required
                            type="number"
                            min="1" /></label
                    ><label class="text-sm"
                        >Revocation reason<Input
                            v-model="revokeForm.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in revokeForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        variant="outline"
                        :disabled="revokeForm.processing"
                        >Revoke invitation and access</Button
                    >
                </form></CardContent
            ></Card
        >
    </div>
</template>
