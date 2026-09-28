<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
type Equipment = {
    id: number;
    reference: string;
    name: string;
    property_id: number;
    serial_number: string | null;
};
type Contract = {
    id: number;
    reference: string;
    title: string;
    starts_on: string;
    ends_on: string;
    service_limit: number | null;
    used: number;
    over_limit: boolean;
    coverage_status: string;
    terms: string | null;
    properties: { id: number; name: string }[];
    equipment: Equipment[];
};
defineProps<{
    properties: { id: number; name: string }[];
    equipment: Equipment[];
    vendors: { id: number; name: string }[];
    contracts: {
        data: Contract[];
        links: { label: string; url: string | null; active: boolean }[];
    };
    visits: {
        id: number;
        amc_contract_id: number;
        maintenance_request_id: number;
        service_on: string;
        override_reason: string | null;
    }[];
}>();
const equipmentForm = useForm({
    property_id: '',
    reference: '',
    name: '',
    serial_number: '',
});
const contractForm = useForm({
    vendor_id: '',
    reference: '',
    title: '',
    starts_on: '',
    ends_on: '',
    service_limit: '',
    terms: '',
    property_ids: [] as number[],
    equipment_ids: [] as number[],
});
const visitForm = useForm({
    contract_id: '',
    maintenance_request_id: '',
    operations_equipment_id: '',
    service_on: '',
    override_reason: '',
});
const cancellation = useForm({ contract_id: '', reason: '' });
function equipmentCreate(): void {
    equipmentForm.post('/operations/amc/equipment', {
        preserveScroll: true,
        onSuccess: () => equipmentForm.reset(),
    });
}
function contractCreate(): void {
    contractForm.post('/operations/amc', {
        preserveScroll: true,
        onSuccess: () => contractForm.reset(),
    });
}
function reserve(): void {
    visitForm.post(`/operations/amc/${visitForm.contract_id}/visits`, {
        preserveScroll: true,
        onSuccess: () => visitForm.reset(),
    });
}
function cancel(): void {
    cancellation.post(`/operations/amc/${cancellation.contract_id}/cancel`, {
        preserveScroll: true,
        onSuccess: () => cancellation.reset(),
    });
}
</script>
<template>
    <Head title="AMC coverage" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <Heading
            title="AMC contracts and coverage"
            description="Record service coverage, equipment and visit limits."
        />
        <Link href="/maintenance" class="text-sm underline">{{
            t('Maintenance')
        }}</Link>
        <Card
            ><CardHeader><CardTitle>Register equipment</CardTitle></CardHeader
            ><CardContent>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="equipmentCreate"
                >
                    <label class="text-sm"
                        >{{ t('Property')
                        }}<select
                            v-model="equipmentForm.property_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Select property</option>
                            <option
                                v-for="item in properties"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >{{ t('Reference')
                        }}<Input
                            v-model="equipmentForm.reference"
                            required
                            maxlength="255"
                    /></label>
                    <label class="text-sm"
                        >{{ t('Name')
                        }}<Input
                            v-model="equipmentForm.name"
                            required
                            maxlength="255"
                    /></label>
                    <label class="text-sm"
                        >Serial number<Input
                            v-model="equipmentForm.serial_number"
                            maxlength="255"
                    /></label>
                    <p
                        v-for="(message, field) in equipmentForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="equipmentForm.processing"
                        >Register equipment</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Create coverage contract</CardTitle></CardHeader
            ><CardContent>
                <p class="text-muted-foreground mb-3 text-sm">
                    Terms are preserved after creation. Cancel an incorrect
                    contract with a reason and create a replacement. Coverage
                    records do not create invoices.
                </p>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="contractCreate"
                >
                    <label class="text-sm"
                        >{{ t('Reference')
                        }}<Input
                            v-model="contractForm.reference"
                            required
                            maxlength="255"
                    /></label>
                    <label class="text-sm"
                        >{{ t('Title')
                        }}<Input
                            v-model="contractForm.title"
                            required
                            maxlength="255"
                    /></label>
                    <label class="text-sm"
                        >Starts on<Input
                            v-model="contractForm.starts_on"
                            required
                            type="date"
                    /></label>
                    <label class="text-sm"
                        >Ends on<Input
                            v-model="contractForm.ends_on"
                            required
                            type="date"
                    /></label>
                    <label class="text-sm"
                        >Service limit (blank for unlimited)<Input
                            v-model="contractForm.service_limit"
                            type="number"
                            min="1"
                            max="1000000"
                    /></label>
                    <label class="text-sm"
                        >{{ t('Vendor')
                        }}<select
                            v-model="contractForm.vendor_id"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">No vendor</option>
                            <option
                                v-for="item in vendors"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option>
                        </select></label
                    >
                    <fieldset class="space-y-2 rounded-md border p-3">
                        <legend class="text-sm">Covered properties</legend>
                        <label
                            v-for="item in properties"
                            :key="item.id"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="contractForm.property_ids"
                                type="checkbox"
                                :value="item.id"
                            />{{ item.name }}</label
                        >
                    </fieldset>
                    <fieldset class="space-y-2 rounded-md border p-3">
                        <legend class="text-sm">Covered equipment</legend>
                        <label
                            v-for="item in equipment"
                            :key="item.id"
                            class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="contractForm.equipment_ids"
                                type="checkbox"
                                :value="item.id"
                            />{{ item.reference }} · {{ item.name }}</label
                        >
                    </fieldset>
                    <label class="text-sm md:col-span-2"
                        >Service terms<textarea
                            v-model="contractForm.terms"
                            maxlength="10000"
                            class="border-input block min-h-24 w-full rounded-md border p-3"
                        />
                    </label>
                    <p
                        v-for="(message, field) in contractForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="contractForm.processing"
                        >Create contract</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Link a job to coverage</CardTitle></CardHeader
            ><CardContent>
                <p class="text-muted-foreground mb-3 text-sm">
                    Each linked job reserves one visit. Cancelled jobs release
                    usage; reopening counts the same visit again. Exceeding a
                    limit requires a manager reason. Enter a contract ID from
                    the list, including contracts on other pages.
                </p>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="reserve"
                >
                    <label class="text-sm"
                        >Contract ID<Input
                            v-model="visitForm.contract_id"
                            required
                            type="number"
                            min="1"
                    /></label>
                    <label class="text-sm"
                        >Job ID<Input
                            v-model="visitForm.maintenance_request_id"
                            required
                            type="number"
                            min="1"
                    /></label>
                    <label class="text-sm"
                        >Equipment (optional)<select
                            v-model="visitForm.operations_equipment_id"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Property coverage</option>
                            <option
                                v-for="item in equipment"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.reference }} · {{ item.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >Service date<Input
                            v-model="visitForm.service_on"
                            required
                            type="date"
                    /></label>
                    <label class="text-sm md:col-span-2"
                        >Limit override reason<Input
                            v-model="visitForm.override_reason"
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in visitForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="visitForm.processing"
                        >Reserve visit</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card
            ><CardHeader><CardTitle>Contracts</CardTitle></CardHeader
            ><CardContent class="space-y-4">
                <p
                    v-if="!contracts.data.length"
                    class="text-muted-foreground text-sm"
                >
                    No contracts recorded.
                </p>
                <article
                    v-for="item in contracts.data"
                    :key="item.id"
                    class="space-y-1 border-b pb-3 text-sm"
                >
                    <p>
                        #{{ item.id }} · {{ item.reference }} ·
                        {{ item.title }} · {{ item.coverage_status }}
                    </p>
                    <p>
                        {{ item.starts_on }} to {{ item.ends_on }} ·
                        {{ item.used }} /
                        {{ item.service_limit ?? 'unlimited' }} visits
                    </p>
                    <p
                        v-if="item.over_limit"
                        class="text-destructive"
                        role="status"
                    >
                        Service limit exceeded.
                    </p>
                    <p>
                        Properties:
                        {{
                            item.properties.map((p) => p.name).join(', ') ||
                            'Equipment coverage only'
                        }}
                    </p>
                    <p>
                        Equipment:
                        {{
                            item.equipment.map((e) => e.reference).join(', ') ||
                            'None specified'
                        }}
                    </p>
                    <p v-if="item.terms" class="whitespace-pre-wrap">
                        {{ item.terms }}
                    </p>
                </article>
                <Pagination :links="contracts.links" /> </CardContent
        ></Card>
        <Card
            ><CardHeader><CardTitle>Cancel a contract</CardTitle></CardHeader
            ><CardContent>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="cancel"
                >
                    <label class="text-sm"
                        >Contract ID<Input
                            v-model="cancellation.contract_id"
                            required
                            type="number"
                            min="1"
                    /></label>
                    <label class="text-sm"
                        >Cancellation reason<Input
                            v-model="cancellation.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in cancellation.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        variant="outline"
                        :disabled="cancellation.processing"
                        >Cancel contract</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle
                    >Latest 50 service reservations</CardTitle
                ></CardHeader
            ><CardContent class="space-y-3">
                <p v-if="!visits.length" class="text-muted-foreground text-sm">
                    No visits reserved.
                </p>
                <article
                    v-for="visit in visits"
                    :key="visit.id"
                    class="border-b pb-3 text-sm"
                >
                    <p>
                        Contract #{{ visit.amc_contract_id }} ·
                        <Link
                            :href="`/maintenance/${visit.maintenance_request_id}/job-card`"
                            class="underline"
                            >Job #{{ visit.maintenance_request_id }}</Link
                        >
                        · {{ visit.service_on }}
                    </p>
                    <p v-if="visit.override_reason">
                        Override: {{ visit.override_reason }}
                    </p>
                </article>
            </CardContent></Card
        >
    </div>
</template>
