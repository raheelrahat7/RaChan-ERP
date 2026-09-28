<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';

type Member = { id: number; name: string; email: string; role: string };
type Invitation = {
    id: number;
    email: string;
    role: string;
    expires_at: string;
};

const props = defineProps<{
    organization: {
        id: number;
        name: string;
        slug: string;
        vat_enabled: boolean;
        tax_registration_number: string | null;
        vat_return_frequency: string;
        corporate_tax_profile: string | null;
        corporate_tax_year_start_month: number;
    };
    members: Member[];
    invitations: Invitation[];
    roles: string[];
    canManageMembers: boolean;
    canPrepareSignatures: boolean;
}>();

const invitationForm = useForm({ email: '', role: 'member' });
const taxForm = useForm({
    vat_enabled: props.organization.vat_enabled,
    tax_registration_number: props.organization.tax_registration_number ?? '',
    vat_return_frequency: props.organization.vat_return_frequency,
    corporate_tax_profile: props.organization.corporate_tax_profile ?? '',
    corporate_tax_year_start_month:
        props.organization.corporate_tax_year_start_month,
});
function updateTaxSettings(): void {
    taxForm.put('/organization/tax-settings', { preserveScroll: true });
}

function invite(): void {
    invitationForm.post('/organization/invitations', {
        preserveScroll: true,
        onSuccess: () => invitationForm.reset('email'),
    });
}

function updateRole(member: Member, role: string): void {
    router.put(
        `/organization/members/${member.id}`,
        { role },
        { preserveScroll: true },
    );
}

function removeMember(member: Member): void {
    if (confirm(`Remove ${member.name} from this organization?`)) {
        router.delete(`/organization/members/${member.id}`, {
            preserveScroll: true,
        });
    }
}
</script>

<template>
    <Link
        v-if="canPrepareSignatures"
        href="/documents/signatures"
        class="m-4 inline-block text-sm underline"
        >Document signature requests (owner)</Link
    >

    <Head title="Organization settings" />

    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Organization settings"
            :description="`Manage access for ${organization.name}.`"
        />

        <Link
            v-if="canManageMembers"
            href="/organization/api-tokens"
            class="text-sm underline"
            >{{ t('Read-only API tokens') }}</Link
        >
        <Link href="/organization/activity" class="text-sm underline">{{
            t('Organization activity')
        }}</Link>
        <Link
            v-if="canManageMembers"
            href="/organization/portal-access"
            class="text-sm underline"
            >Customer portal invitations and access</Link
        >
        <Card v-if="canManageMembers">
            <CardHeader><CardTitle>UAE tax settings</CardTitle></CardHeader>
            <CardContent>
                <form
                    class="grid gap-4 sm:grid-cols-2"
                    @submit.prevent="updateTaxSettings"
                >
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="taxForm.vat_enabled"
                            type="checkbox"
                        />VAT registered</label
                    >
                    <div>
                        <Label for="trn">Tax registration number</Label
                        ><Input
                            id="trn"
                            v-model="taxForm.tax_registration_number"
                            inputmode="numeric"
                            minlength="15"
                            maxlength="15"
                            :required="taxForm.vat_enabled"
                            :disabled="!taxForm.vat_enabled"
                        />
                    </div>
                    <label class="text-sm"
                        >Return frequency<select
                            v-model="taxForm.vat_return_frequency"
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="quarterly">Quarterly</option>
                            <option value="monthly">Monthly</option>
                        </select></label
                    >
                    <Button class="w-fit" :disabled="taxForm.processing"
                        >Save tax settings</Button
                    >
                    <label class="text-sm"
                        >Corporate-tax profile<select
                            v-model="taxForm.corporate_tax_profile"
                            class="border-input bg-background block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Not configured</option>
                            <option value="resident_mainland">
                                Resident mainland company
                            </option>
                            <option value="qualifying_free_zone">
                                Qualifying Free Zone Person
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >Tax year starts<Input
                            v-model="taxForm.corporate_tax_year_start_month"
                            type="number"
                            min="1"
                            max="12"
                            :required="Boolean(taxForm.corporate_tax_profile)"
                    /></label>
                    <p class="text-muted-foreground text-sm sm:col-span-2">
                        Free Zone status must be confirmed for each return.
                        Qualifying and non-qualifying income will be classified
                        separately.
                    </p>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader
                ><CardTitle>{{ t('Members') }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-4">
                <div
                    v-for="member in members"
                    :key="member.id"
                    class="flex flex-wrap items-center gap-3 border-b pb-3 last:border-0 last:pb-0"
                >
                    <div class="min-w-48 flex-1">
                        <p class="font-medium">{{ member.name }}</p>
                        <p class="text-muted-foreground text-sm">
                            {{ member.email }}
                        </p>
                    </div>
                    <Select
                        :model-value="member.role"
                        :disabled="!canManageMembers"
                        @update:model-value="
                            updateRole(member, String($event ?? member.role))
                        "
                    >
                        <SelectTrigger class="w-40"
                            ><SelectValue
                        /></SelectTrigger>
                        <SelectContent
                            ><SelectItem
                                v-for="role in roles"
                                :key="role"
                                :value="role"
                                >{{ role }}</SelectItem
                            ></SelectContent
                        >
                    </Select>
                    <Button
                        v-if="canManageMembers"
                        variant="ghost"
                        size="sm"
                        @click="removeMember(member)"
                        >{{ t('Remove') }}</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card v-if="canManageMembers">
            <CardHeader><CardTitle>Invite a member</CardTitle></CardHeader>
            <CardContent>
                <form
                    class="grid gap-4 sm:grid-cols-[1fr_10rem_auto]"
                    @submit.prevent="invite"
                >
                    <div class="space-y-2">
                        <Label for="email">{{ t('Email') }}</Label
                        ><Input
                            id="email"
                            v-model="invitationForm.email"
                            type="email"
                            required
                        />
                    </div>
                    <div class="space-y-2">
                        <Label for="role">Role</Label
                        ><Select v-model="invitationForm.role"
                            ><SelectTrigger><SelectValue /></SelectTrigger
                            ><SelectContent
                                ><SelectItem
                                    v-for="role in roles.filter(
                                        (role) => role !== 'owner',
                                    )"
                                    :key="role"
                                    :value="role"
                                    >{{ role }}</SelectItem
                                ></SelectContent
                            ></Select
                        >
                    </div>
                    <Button
                        class="self-end"
                        :disabled="invitationForm.processing"
                        >Invite</Button
                    >
                </form>
            </CardContent>
        </Card>

        <Card v-if="invitations.length">
            <CardHeader><CardTitle>Pending invitations</CardTitle></CardHeader>
            <CardContent class="space-y-2">
                <div
                    v-for="invitation in invitations"
                    :key="invitation.id"
                    class="flex justify-between text-sm"
                >
                    <span>{{ invitation.email }}</span
                    ><span class="text-muted-foreground">{{
                        invitation.role
                    }}</span>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
