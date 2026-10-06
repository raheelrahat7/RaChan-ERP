<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import CrmPartyFormSheet from '@/components/CrmPartyFormSheet.vue';
import PageHeader from '@/components/PageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { useLocale } from '@/composables/useLocale';
import { apiJson } from '@/lib/crm-api';
import { displayField, fullName } from '@/lib/crm-parties';
import type { PartyContact, PartyField } from '@/lib/crm-parties';

type Lead = {
    id: number;
    first_name: string;
    last_name: string;
    status?: string | null;
    email?: string | null;
};
type DealRow = {
    id: number;
    title: string;
    amount?: string | number | null;
    currency?: string | null;
};
type Activity = {
    id: number;
    type?: string;
    notes?: string | null;
    created_at?: string;
};

const props = defineProps<{
    contact: PartyContact;
    customFields: PartyField[];
    leads: Lead[];
    deals: DealRow[];
    activities: { data: Activity[] };
}>();
const { t } = useLocale();
const tab = ref<'general' | 'leads' | 'deals' | 'activities'>('general');
const editOpen = ref(false);
const companies = ref<{ id: number; name: string }[]>([]);
const canEdit = computed(() => props.contact.permissions?.edit === true);

onMounted(async () => {
    if (!canEdit.value) {
        return;
    }
    try {
        const data = await apiJson<{
            companies: { data: { id: number; name: string }[] };
        }>('/crm/companies?per_page=100');
        companies.value = data.companies.data;
    } catch {
        companies.value = [];
    }
});
const reload = (): void => router.reload();
</script>

<template>
    <Head :title="fullName(contact)" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-4 p-4 md:p-6">
        <PageHeader
            :title="fullName(contact)"
            :translate="false"
            eyebrow="Contact"
        >
            <template #meta>
                <p
                    class="text-muted-foreground mt-2 flex flex-wrap items-center gap-x-3 text-sm"
                >
                    <Link
                        v-if="contact.account"
                        :href="`/companies/${contact.account.id}`"
                        class="text-primary hover:underline"
                        >{{ contact.account.name }}</Link
                    >
                    <a
                        v-if="contact.email"
                        :href="`mailto:${contact.email}`"
                        class="hover:underline"
                        >{{ contact.email }}</a
                    >
                    <a
                        v-if="contact.phone"
                        :href="`tel:${contact.phone}`"
                        class="hover:underline"
                        >{{ contact.phone }}</a
                    >
                </p>
            </template>
            <template #actions>
                <Button
                    v-if="canEdit"
                    type="button"
                    variant="outline"
                    @click="editOpen = true"
                    >{{ t('Edit') }}</Button
                >
                <Link href="/crm/contacts" class="text-sm underline">{{
                    t('Back to contacts')
                }}</Link>
            </template>
        </PageHeader>

        <Tabs v-model="tab" class="gap-4">
            <TabsList :aria-label="t('Contact sections')">
                <TabsTrigger value="general">{{ t('General') }}</TabsTrigger>
                <TabsTrigger value="leads"
                    >{{ t('Leads') }} ({{ leads.length }})</TabsTrigger
                >
                <TabsTrigger value="deals"
                    >{{ t('Deals') }} ({{ deals.length }})</TabsTrigger
                >
                <TabsTrigger value="activities">{{
                    t('Activities')
                }}</TabsTrigger>
            </TabsList>

            <TabsContent value="general" class="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardHeader
                        ><CardTitle class="text-eyebrow">{{
                            t('General')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent>
                        <dl class="grid grid-cols-2 gap-4 text-sm">
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    {{ t('First name') }}
                                </dt>
                                <dd>{{ contact.first_name }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    {{ t('Last name') }}
                                </dt>
                                <dd>{{ contact.last_name || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    {{ t('Email') }}
                                </dt>
                                <dd>{{ contact.email || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    {{ t('Phone') }}
                                </dt>
                                <dd>{{ contact.phone || '—' }}</dd>
                            </div>
                            <div>
                                <dt class="text-muted-foreground text-xs">
                                    {{ t('Company') }}
                                </dt>
                                <dd>{{ contact.account?.name || '—' }}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader
                        ><CardTitle class="text-eyebrow">{{
                            t('Custom fields')
                        }}</CardTitle></CardHeader
                    >
                    <CardContent>
                        <p
                            v-if="!customFields.length"
                            class="text-muted-foreground text-sm"
                        >
                            {{ t('No additional fields are visible to you.') }}
                        </p>
                        <dl v-else class="grid grid-cols-2 gap-4 text-sm">
                            <div v-for="field in customFields" :key="field.key">
                                <dt class="text-muted-foreground text-xs">
                                    {{ field.name }}
                                </dt>
                                <dd>{{ displayField(field) }}</dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="leads">
                <Card>
                    <CardContent class="space-y-2 text-sm">
                        <p v-if="!leads.length" class="text-muted-foreground">
                            {{ t('No leads linked to this contact.') }}
                        </p>
                        <div
                            v-for="lead in leads"
                            :key="lead.id"
                            class="flex items-center justify-between border-b pb-2 last:border-0"
                        >
                            <Link
                                :href="`/crm/leads/${lead.id}`"
                                class="text-primary font-medium hover:underline"
                                >{{ lead.first_name }}
                                {{ lead.last_name }}</Link
                            >
                            <Badge v-if="lead.status" variant="secondary">{{
                                lead.status
                            }}</Badge>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="deals">
                <Card>
                    <CardContent class="space-y-2 text-sm">
                        <p v-if="!deals.length" class="text-muted-foreground">
                            {{ t('No deals linked to this contact.') }}
                        </p>
                        <div
                            v-for="deal in deals"
                            :key="deal.id"
                            class="flex items-center justify-between border-b pb-2 last:border-0"
                        >
                            <Link
                                :href="`/deals/${deal.id}`"
                                class="text-primary font-medium hover:underline"
                                >{{ deal.title }}</Link
                            >
                            <span
                                v-if="
                                    deal.amount !== undefined &&
                                    deal.amount !== null
                                "
                                class="text-muted-foreground"
                                >{{ deal.currency ?? 'AED' }}
                                {{ deal.amount }}</span
                            >
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>

            <TabsContent value="activities">
                <Card>
                    <CardContent class="space-y-2 text-sm">
                        <p
                            v-if="!activities.data.length"
                            class="text-muted-foreground"
                        >
                            {{ t('No activities yet.') }}
                        </p>
                        <div
                            v-for="activity in activities.data"
                            :key="activity.id"
                            class="border-b pb-2 last:border-0"
                        >
                            <p class="font-medium capitalize">
                                {{ activity.type ?? t('Activity') }}
                            </p>
                            <p
                                v-if="activity.notes"
                                class="text-muted-foreground"
                            >
                                {{ activity.notes }}
                            </p>
                        </div>
                    </CardContent>
                </Card>
            </TabsContent>
        </Tabs>

        <CrmPartyFormSheet
            v-model:open="editOpen"
            kind="contact"
            :contact="contact"
            :fields="customFields"
            :companies="companies"
            @saved="reload"
        />
    </div>
</template>
