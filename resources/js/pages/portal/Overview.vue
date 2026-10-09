<script setup lang="ts">
import { uuid } from '@/lib/uuid';
import { useLocale } from '@/composables/useLocale';
const { t, status } = useLocale();

import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
type Page<T> = {
    data: T[];
    links: { label: string; url: string | null; active: boolean }[];
};
const props = defineProps<{
    grant: { id: number; role: string };
    portalOrganization: { id: number; name: string };
    profile: { id: number; name: string };
    from: string;
    to: string;
    properties: {
        id: number;
        name: string;
        city: string | null;
        address_line_1: string | null;
    }[];
    leases: {
        id: number;
        reference: string;
        status: string;
        unit: string | null;
        starts_on: string;
        ends_on: string;
        rent_amount: string;
        currency: string;
    }[];
    invoices: Page<{
        id: number;
        reference: string;
        status: string;
        total: string;
        outstanding: string;
        currency: string;
        issued_on: string | null;
        due_on: string | null;
    }> | null;
    serviceRequests: Page<{
        id: number;
        reference: string;
        title: string;
        description: string | null;
        priority: string;
        status: string;
        created_at: string;
    }> | null;
    serviceSummaries: Page<{
        id: number;
        reference: string;
        title: string;
        status: string;
        priority: string;
        property_id: number;
    }> | null;
    statement: {
        rows: {
            activity_date: string;
            type: string;
            reference: string;
            property_name: string;
            description: string;
            owner_amount: number;
        }[];
        totals: { income: string; expenses: string; net: string };
    } | null;
}>();
const form = useForm({
    lease_id: '',
    title: '',
    description: '',
    priority: 'medium',
    operation_key: uuid(),
});
const period = useForm({ from: props.from, to: props.to });
const tenantTab = ref('leases');
const ownerTab = ref('properties');
function submit(): void {
    form.post(`/portal/${props.grant.id}/service-requests`, {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('title', 'description');
            form.operation_key = uuid();
        },
    });
}
function report(): void {
    period.get(`/portal/${props.grant.id}`, { preserveScroll: true });
}
</script>
<template>
    <Head :title="portalOrganization.name" />
    <div class="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            :translate="false"
            :title="portalOrganization.name"
            :description="`${profile.name} · ${status(grant.role)}`"
        />
        <Tabs v-if="grant.role === 'tenant'" v-model="tenantTab" class="gap-4">
            <TabsList :aria-label="t('Portal sections')">
                <TabsTrigger value="leases">{{ t('Your leases') }}</TabsTrigger>
                <TabsTrigger value="invoices">{{
                    t('Linked invoices')
                }}</TabsTrigger>
                <TabsTrigger value="requests">{{
                    t('Service requests')
                }}</TabsTrigger>
            </TabsList>
            <TabsContent value="leases">
                <Card
                    ><CardContent class="p-0"
                        ><p
                            v-if="!leases.length"
                            class="text-muted-foreground p-5 text-sm"
                        >
                            {{ t('No linked leases.') }}
                        </p>
                        <ul role="list" class="divide-y">
                            <li
                                v-for="lease in leases"
                                :key="lease.id"
                                class="flex flex-wrap items-center justify-between gap-2 px-5 py-4 text-sm"
                            >
                                <div>
                                    <p class="font-medium">
                                        {{ lease.reference }} · {{ t('Unit') }}
                                        {{ lease.unit }}
                                    </p>
                                    <p class="text-muted-foreground">
                                        {{ lease.starts_on }} {{ t('To') }}
                                        {{ lease.ends_on }} · {{ t('Rent') }}
                                        {{ lease.currency }}
                                        {{ lease.rent_amount }}
                                    </p>
                                </div>
                                <Badge variant="secondary">{{
                                    status(lease.status)
                                }}</Badge>
                            </li>
                        </ul></CardContent
                    ></Card
                >
            </TabsContent>
            <TabsContent value="invoices">
                <Card
                    ><CardContent class="space-y-3 p-0"
                        ><p
                            v-if="!invoices?.data.length"
                            class="text-muted-foreground p-5 text-sm"
                        >
                            {{ t('No posted invoices linked to your leases.') }}
                        </p>
                        <ul role="list" class="divide-y">
                            <li
                                v-for="invoice in invoices?.data"
                                :key="invoice.id"
                                class="flex flex-wrap items-center justify-between gap-2 px-5 py-4 text-sm"
                            >
                                <div>
                                    <p class="font-medium">
                                        {{ invoice.reference }} ·
                                        {{ invoice.currency }}
                                        {{ invoice.total }}
                                    </p>
                                    <p class="text-muted-foreground">
                                        {{ t('Issued') }}
                                        {{ invoice.issued_on }} ·
                                        {{ t('Due') }}
                                        {{
                                            invoice.due_on ?? t('Not specified')
                                        }}
                                    </p>
                                </div>
                                <div class="text-end">
                                    <Badge variant="secondary">{{
                                        status(invoice.status)
                                    }}</Badge>
                                    <p class="mt-1 text-xs">
                                        {{ t('Outstanding') }}
                                        {{ invoice.currency }}
                                        {{ invoice.outstanding }}
                                    </p>
                                </div>
                            </li>
                        </ul>
                        <Pagination
                            v-if="invoices"
                            class="p-4"
                            :links="invoices.links" /></CardContent
                ></Card>
            </TabsContent>
            <TabsContent value="requests" class="space-y-4">
                <Card
                    ><CardHeader
                        ><CardTitle>{{
                            t('Request service')
                        }}</CardTitle></CardHeader
                    ><CardContent
                        ><form
                            class="grid gap-3 md:grid-cols-2"
                            @submit.prevent="submit"
                        >
                            <label class="text-sm"
                                >{{ t('Lease')
                                }}<select
                                    v-model="form.lease_id"
                                    required
                                    class="border-input block h-9 w-full rounded-md border px-3"
                                >
                                    <option value="">
                                        {{ t('Select active lease') }}
                                    </option>
                                    <option
                                        v-for="lease in leases.filter(
                                            (l) => l.status === 'active',
                                        )"
                                        :key="lease.id"
                                        :value="String(lease.id)"
                                    >
                                        {{ lease.reference }} · {{ t('Unit') }}
                                        {{ lease.unit }}
                                    </option>
                                </select></label
                            >
                            <label class="text-sm"
                                >{{ t('Title')
                                }}<Input
                                    v-model="form.title"
                                    required
                                    maxlength="255"
                            /></label>
                            <label class="text-sm"
                                >{{ t('Priority')
                                }}<select
                                    v-model="form.priority"
                                    class="border-input block h-9 w-full rounded-md border px-3"
                                >
                                    <option value="low">{{ t('Low') }}</option>
                                    <option value="medium">
                                        {{ t('Medium') }}
                                    </option>
                                    <option value="high">
                                        {{ t('High') }}
                                    </option>
                                    <option value="urgent">
                                        {{ t('Urgent') }}
                                    </option>
                                </select></label
                            >
                            <label class="text-sm md:col-span-2"
                                >{{ t('Description')
                                }}<textarea
                                    v-model="form.description"
                                    maxlength="5000"
                                    class="border-input block min-h-24 w-full rounded-md border p-3"
                                />
                            </label>
                            <p
                                v-for="(message, field) in form.errors"
                                :key="field"
                                role="alert"
                                class="text-destructive text-sm"
                            >
                                {{ message }}
                            </p>
                            <Button
                                class="justify-self-start"
                                :disabled="form.processing"
                                >{{ t('Submit request') }}</Button
                            >
                        </form></CardContent
                    ></Card
                >
                <Card
                    ><CardHeader
                        ><CardTitle>{{
                            t('Your service requests')
                        }}</CardTitle></CardHeader
                    ><CardContent class="space-y-3 p-0"
                        ><p
                            v-if="!serviceRequests?.data.length"
                            class="text-muted-foreground p-5 text-sm"
                        >
                            {{ t('No requests submitted.') }}
                        </p>
                        <ul role="list" class="divide-y">
                            <li
                                v-for="request in serviceRequests?.data"
                                :key="request.id"
                                class="px-5 py-4 text-sm"
                            >
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <p class="font-medium">
                                        {{ request.reference }} ·
                                        {{ request.title }}
                                    </p>
                                    <span class="flex gap-1.5"
                                        ><Badge variant="secondary">{{
                                            status(request.status)
                                        }}</Badge
                                        ><Badge variant="outline">{{
                                            status(request.priority)
                                        }}</Badge></span
                                    >
                                </div>
                                <p
                                    class="text-muted-foreground mt-1 whitespace-pre-wrap"
                                >
                                    {{ request.description }}
                                </p>
                            </li>
                        </ul>
                        <Pagination
                            v-if="serviceRequests"
                            class="p-4"
                            :links="serviceRequests.links" /></CardContent
                ></Card>
            </TabsContent>
        </Tabs>
        <Tabs v-else v-model="ownerTab" class="gap-4">
            <TabsList :aria-label="t('Portal sections')">
                <TabsTrigger value="properties">{{
                    t('Your properties')
                }}</TabsTrigger>
                <TabsTrigger value="statement">{{
                    t('Owner statement')
                }}</TabsTrigger>
                <TabsTrigger value="service">{{
                    t('Service summaries')
                }}</TabsTrigger>
            </TabsList>
            <TabsContent value="properties">
                <Card
                    ><CardContent class="p-0"
                        ><p
                            v-if="!properties.length"
                            class="text-muted-foreground p-5 text-sm"
                        >
                            {{ t('No current ownership links.') }}
                        </p>
                        <ul role="list" class="divide-y">
                            <li
                                v-for="property in properties"
                                :key="property.id"
                                class="px-5 py-4 text-sm"
                            >
                                <p class="font-medium">{{ property.name }}</p>
                                <p class="text-muted-foreground">
                                    {{ property.address_line_1 }}
                                    {{ property.city }}
                                </p>
                            </li>
                        </ul></CardContent
                    ></Card
                >
            </TabsContent>
            <TabsContent value="statement" class="space-y-4">
                <Card
                    ><CardContent class="space-y-4"
                        ><form
                            class="flex flex-wrap items-end gap-3"
                            @submit.prevent="report"
                        >
                            <label class="text-sm"
                                >{{ t('From')
                                }}<Input
                                    v-model="period.from"
                                    required
                                    type="date" /></label
                            ><label class="text-sm"
                                >{{ t('To')
                                }}<Input
                                    v-model="period.to"
                                    required
                                    type="date" /></label
                            ><Button :disabled="period.processing">{{
                                t('View period')
                            }}</Button>
                        </form>
                        <p
                            v-for="(message, field) in period.errors"
                            :key="field"
                            class="text-destructive text-sm"
                            role="alert"
                        >
                            {{ message }}
                        </p>
                        <dl class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-md border p-3">
                                <dt class="text-muted-foreground text-xs">
                                    {{ t('Income') }} (AED)
                                </dt>
                                <dd class="text-lg font-semibold">
                                    {{ statement?.totals.income ?? '—' }}
                                </dd>
                            </div>
                            <div class="rounded-md border p-3">
                                <dt class="text-muted-foreground text-xs">
                                    {{ t('Expenses') }} (AED)
                                </dt>
                                <dd class="text-lg font-semibold">
                                    {{ statement?.totals.expenses ?? '—' }}
                                </dd>
                            </div>
                            <div class="rounded-md border p-3">
                                <dt class="text-muted-foreground text-xs">
                                    {{ t('Net') }} (AED)
                                </dt>
                                <dd class="text-lg font-semibold">
                                    {{ statement?.totals.net ?? '—' }}
                                </dd>
                            </div>
                        </dl>
                        <p class="text-muted-foreground text-sm">
                            {{
                                t(
                                    'Allocated posted service-charge income and operating expenses. This statement creates no payable.',
                                )
                            }}
                        </p>
                    </CardContent></Card
                >
                <Card v-if="statement?.rows.length"
                    ><CardContent class="p-0"
                        ><ul role="list" class="divide-y">
                            <li
                                v-for="(row, index) in statement?.rows"
                                :key="index"
                                class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm"
                            >
                                <span
                                    >{{ row.activity_date }} ·
                                    {{ row.property_name }} ·
                                    {{ status(row.type) }} ·
                                    {{ row.description }}</span
                                ><span class="font-medium">{{
                                    row.owner_amount.toFixed(2)
                                }}</span>
                            </li>
                        </ul></CardContent
                    ></Card
                >
            </TabsContent>
            <TabsContent value="service">
                <Card
                    ><CardContent class="space-y-3 p-0"
                        ><p
                            v-if="!serviceSummaries?.data.length"
                            class="text-muted-foreground p-5 text-sm"
                        >
                            {{ t('No service jobs for your properties.') }}
                        </p>
                        <ul role="list" class="divide-y">
                            <li
                                v-for="job in serviceSummaries?.data"
                                :key="job.id"
                                class="flex flex-wrap items-center justify-between gap-2 px-5 py-3 text-sm"
                            >
                                <span
                                    ><span class="font-medium">{{
                                        job.reference
                                    }}</span>
                                    · {{ job.title }}</span
                                >
                                <span class="flex gap-1.5"
                                    ><Badge variant="secondary">{{
                                        status(job.status)
                                    }}</Badge
                                    ><Badge variant="outline">{{
                                        status(job.priority)
                                    }}</Badge></span
                                >
                            </li>
                        </ul>
                        <Pagination
                            v-if="serviceSummaries"
                            class="p-4"
                            :links="serviceSummaries.links" /></CardContent
                ></Card>
            </TabsContent>
        </Tabs>
    </div>
</template>
