<script setup lang="ts">
import { uuid } from '@/lib/uuid';
import { useLocale } from '@/composables/useLocale';
const { t, status } = useLocale();

import { Head, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
    <Head :title="portalOrganization.name" /><Heading
        :translate-text="false"
        :title="portalOrganization.name"
        :description="`${profile.name} · ${status(grant.role)}`"
    />
    <template v-if="grant.role === 'tenant'">
        <Card
            ><CardHeader
                ><CardTitle>{{ t('Your leases') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!leases.length">{{ t('No linked leases.') }}</p>
                <article
                    v-for="lease in leases"
                    :key="lease.id"
                    class="border-b pb-3 text-sm"
                >
                    <p>
                        {{ lease.reference }} · {{ t('Unit') }}
                        {{ lease.unit }} ·
                        {{ status(lease.status) }}
                    </p>
                    <p>
                        {{ lease.starts_on }} {{ t('To') }}
                        {{ lease.ends_on }} · {{ t('Rent') }}
                        {{ lease.currency }} {{ lease.rent_amount }}
                    </p>
                </article></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>{{ t('Linked invoices') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!invoices?.data.length">
                    {{ t('No posted invoices linked to your leases.') }}
                </p>
                <article
                    v-for="invoice in invoices?.data"
                    :key="invoice.id"
                    class="border-b pb-3 text-sm"
                >
                    <p>
                        {{ invoice.reference }} · {{ status(invoice.status) }} ·
                        {{ invoice.currency }} {{ invoice.total }}
                    </p>
                    <p>
                        {{ t('Issued') }} {{ invoice.issued_on }} ·
                        {{ t('Due') }}
                        {{ invoice.due_on ?? t('Not specified') }} ·
                        {{ t('Outstanding') }} {{ invoice.currency }}
                        {{ invoice.outstanding }}
                    </p>
                </article>
                <Pagination
                    v-if="invoices"
                    :links="invoices.links" /></CardContent
        ></Card>
        <Card
            ><CardHeader
                ><CardTitle>{{ t('Request service') }}</CardTitle></CardHeader
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
                        }}<Input v-model="form.title" required maxlength="255"
                    /></label>
                    <label class="text-sm"
                        >{{ t('Priority')
                        }}<select
                            v-model="form.priority"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="low">{{ t('Low') }}</option>
                            <option value="medium">{{ t('Medium') }}</option>
                            <option value="high">{{ t('High') }}</option>
                            <option value="urgent">{{ t('Urgent') }}</option>
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
            ><CardContent class="space-y-3"
                ><p v-if="!serviceRequests?.data.length">
                    {{ t('No requests submitted.') }}
                </p>
                <article
                    v-for="request in serviceRequests?.data"
                    :key="request.id"
                    class="border-b pb-3 text-sm"
                >
                    <p>
                        {{ request.reference }} · {{ request.title }} ·
                        {{ status(request.status) }} ·
                        {{ status(request.priority) }}
                    </p>
                    <p class="whitespace-pre-wrap">{{ request.description }}</p>
                </article>
                <Pagination
                    v-if="serviceRequests"
                    :links="serviceRequests.links" /></CardContent
        ></Card>
    </template>
    <template v-else>
        <Card
            ><CardHeader
                ><CardTitle>{{ t('Your properties') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!properties.length">
                    {{ t('No current ownership links.') }}
                </p>
                <article
                    v-for="property in properties"
                    :key="property.id"
                    class="border-b pb-3 text-sm"
                >
                    <p>{{ property.name }}</p>
                    <p>{{ property.address_line_1 }} {{ property.city }}</p>
                </article></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>{{
                    t('Owner statement · AED')
                }}</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <form
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
                <p>
                    {{ t('Income') }} {{ statement?.totals.income }} ·
                    {{ t('Expenses') }} {{ statement?.totals.expenses }} ·
                    {{ t('Net') }}
                    {{ statement?.totals.net }}
                </p>
                <p class="text-muted-foreground text-sm">
                    {{
                        t(
                            'Allocated posted service-charge income and operating expenses. This statement creates no payable.',
                        )
                    }}
                </p>
                <article
                    v-for="(row, index) in statement?.rows"
                    :key="index"
                    class="border-b pb-3 text-sm"
                >
                    {{ row.activity_date }} · {{ row.property_name }} ·
                    {{ status(row.type) }} · {{ row.description }} ·
                    {{ row.owner_amount.toFixed(2) }}
                </article>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>{{ t('Service summaries') }}</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!serviceSummaries?.data.length">
                    {{ t('No service jobs for your properties.') }}
                </p>
                <article
                    v-for="job in serviceSummaries?.data"
                    :key="job.id"
                    class="border-b pb-3 text-sm"
                >
                    {{ job.reference }} · {{ job.title }} ·
                    {{ status(job.status) }} ·
                    {{ status(job.priority) }}
                </article>
                <Pagination
                    v-if="serviceSummaries"
                    :links="serviceSummaries.links" /></CardContent
        ></Card>
    </template>
</template>
