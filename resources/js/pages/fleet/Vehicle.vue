<script setup lang="ts">
import { uuid } from '@/lib/uuid';
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
type Links = { label: string; url: string | null; active: boolean }[];
const SECTIONS = [
    { key: 'assignment', label: 'Assignment' },
    { key: 'service', label: 'Service' },
    { key: 'status', label: 'Vehicle status' },
] as const;
const tab = ref<(typeof SECTIONS)[number]['key']>('assignment');
const props = defineProps<{
    vehicle: {
        id: number;
        reference: string;
        plate: string;
        vin: string | null;
        make: string;
        model: string;
        year: number | null;
        odometer: number;
        status: string;
        fixed_asset_id: number | null;
    };
    members: { id: number; name: string }[];
    vendors: { id: number; name: string }[];
    assignments: {
        data: {
            id: number;
            user_id: number;
            reason: string;
            started_at: string;
            ended_at: string | null;
            return_reason: string | null;
        }[];
        links: Links;
    };
    services: {
        data: {
            id: number;
            kind: string;
            description: string;
            due_on: string;
            status: string;
            overdue: boolean;
            maintenance_request_id: number | null;
            completed_odometer: number | null;
            completion_note: string | null;
            cancellation_reason: string | null;
        }[];
        links: Links;
    };
}>();
const base = `/operations/fleet/${props.vehicle.id}`;
const assignment = useForm({ user_id: '', reason: '' });
const returnForm = useForm({ assignment_id: '', reason: '' });
const service = useForm({
    kind: 'preventive',
    description: '',
    due_on: '',
    vendor_id: '',
    maintenance_request_id: '',
    operation_key: uuid(),
});
const completion = useForm({
    service_id: '',
    odometer: String(props.vehicle.odometer),
    note: '',
});
const cancellation = useForm({ service_id: '', reason: '' });
const lifecycle = useForm({ status: props.vehicle.status, reason: '' });
function assign(): void {
    assignment.post(`${base}/assignments`, {
        preserveScroll: true,
        onSuccess: () => assignment.reset(),
    });
}
function returnVehicle(): void {
    returnForm.post(
        `/operations/fleet-assignments/${returnForm.assignment_id}/return`,
        { preserveScroll: true, onSuccess: () => returnForm.reset() },
    );
}
function schedule(): void {
    service.post(`${base}/services`, {
        preserveScroll: true,
        onSuccess: () => {
            service.reset('description', 'due_on', 'maintenance_request_id');
            service.operation_key = uuid();
        },
    });
}
function complete(): void {
    completion.post(
        `/operations/fleet-services/${completion.service_id}/complete`,
        {
            preserveScroll: true,
            onSuccess: () => completion.reset('service_id', 'note'),
        },
    );
}
function cancel(): void {
    cancellation.post(
        `/operations/fleet-services/${cancellation.service_id}/cancel`,
        { preserveScroll: true, onSuccess: () => cancellation.reset() },
    );
}
function status(): void {
    lifecycle.post(`${base}/status`, {
        preserveScroll: true,
        onSuccess: () => lifecycle.reset('reason'),
    });
}
</script>
<template>
    <Head :title="vehicle.reference" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <PageHeader
            :translate="false"
            :title="`${vehicle.reference} · ${vehicle.plate}`"
            :description="`${vehicle.make} ${vehicle.model} · ${vehicle.odometer} km · ${vehicle.status}`"
        >
            <template #actions>
                <Link href="/operations/fleet" class="text-sm underline">{{
                    t('All vehicles')
                }}</Link>
            </template>
        </PageHeader>
        <p v-if="vehicle.fixed_asset_id" class="text-sm">
            {{ t('Linked fixed asset') }} #{{ vehicle.fixed_asset_id }}.
            {{
                t(
                    'Fleet actions do not change depreciation or post accounting entries.',
                )
            }}
        </p>
        <nav :aria-label="t('Sections')" class="flex flex-wrap gap-2">
            <button
                v-for="item in SECTIONS"
                :key="item.key"
                type="button"
                class="rounded-md px-3 py-1.5 text-sm font-medium"
                :class="
                    item.key === tab
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-muted border'
                "
                :aria-current="item.key === tab ? 'page' : undefined"
                @click="tab = item.key"
            >
                {{ t(item.label) }}
            </button>
        </nav>
        <Card v-show="tab === 'assignment'"
            ><CardHeader><CardTitle>Assign vehicle</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="assign"
                >
                    <label class="text-sm"
                        >{{ t('Member')
                        }}<select
                            v-model="assignment.user_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Select member</option>
                            <option
                                v-for="member in members"
                                :key="member.id"
                                :value="String(member.id)"
                            >
                                {{ member.name }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="assignment.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in assignment.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="assignment.processing"
                        >Assign vehicle</Button
                    >
                </form></CardContent
            ></Card
        ><Card v-show="tab === 'assignment'"
            ><CardHeader><CardTitle>Assignment history</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!assignments.data.length">No assignments.</p>
                <article
                    v-for="item in assignments.data"
                    :key="item.id"
                    class="border-b pb-3 text-sm"
                >
                    <p>
                        #{{ item.id }} ·
                        {{
                            members.find((m) => m.id === item.user_id)?.name ??
                            `Former member #${item.user_id}`
                        }}
                        · {{ item.started_at }} to
                        {{ item.ended_at ?? 'Current' }}
                    </p>
                    <p>
                        {{ item.reason
                        }}<span v-if="item.return_reason">
                            · Returned: {{ item.return_reason }}</span
                        >
                    </p>
                </article>
                <Pagination :links="assignments.links" />
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="returnVehicle"
                >
                    <label class="text-sm"
                        >Assignment ID to end<Input
                            v-model="returnForm.assignment_id"
                            required
                            type="number"
                            min="1" /></label
                    ><label class="text-sm"
                        >Return reason<Input
                            v-model="returnForm.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in returnForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        variant="outline"
                        :disabled="returnForm.processing"
                        >Record return</Button
                    >
                </form></CardContent
            ></Card
        ><Card v-show="tab === 'service'"
            ><CardHeader><CardTitle>Schedule service</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="schedule"
                >
                    <label class="text-sm"
                        >Kind<select
                            v-model="service.kind"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="preventive">Preventive</option>
                            <option value="repair">Repair</option>
                            <option value="inspection">Inspection</option>
                        </select></label
                    ><label class="text-sm"
                        >{{ t('Description')
                        }}<Input
                            v-model="service.description"
                            required
                            maxlength="255" /></label
                    ><label class="text-sm"
                        >Due on<Input
                            v-model="service.due_on"
                            required
                            type="date" /></label
                    ><label class="text-sm"
                        >{{ t('Vendor')
                        }}<select
                            v-model="service.vendor_id"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">No vendor</option>
                            <option
                                v-for="vendor in vendors"
                                :key="vendor.id"
                                :value="String(vendor.id)"
                            >
                                {{ vendor.name }}
                            </option>
                        </select></label
                    ><label class="text-sm"
                        >Existing maintenance job ID (optional)<Input
                            v-model="service.maintenance_request_id"
                            type="number"
                            min="1"
                    /></label>
                    <p
                        v-for="(message, field) in service.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="service.processing"
                        >Schedule service</Button
                    >
                </form></CardContent
            ></Card
        ><Card v-show="tab === 'service'"
            ><CardHeader><CardTitle>Service history</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p v-if="!services.data.length">No service scheduled.</p>
                <article
                    v-for="item in services.data"
                    :key="item.id"
                    class="space-y-1 border-b pb-3 text-sm"
                >
                    <p>
                        #{{ item.id }} · {{ item.kind }} ·
                        {{ item.description }} · {{ item.due_on }} ·
                        {{ item.status
                        }}<span v-if="item.overdue" class="text-destructive">
                            · Overdue</span
                        >
                    </p>
                    <Link
                        v-if="item.maintenance_request_id"
                        :href="`/maintenance/${item.maintenance_request_id}/job-card`"
                        class="underline"
                        >Linked job #{{ item.maintenance_request_id }}</Link
                    >
                    <p v-if="item.completion_note">
                        {{ item.completed_odometer }} km ·
                        {{ item.completion_note }}
                    </p>
                    <p v-if="item.cancellation_reason">
                        Cancelled: {{ item.cancellation_reason }}
                    </p>
                </article>
                <Pagination :links="services.links" /></CardContent></Card
        ><Card v-show="tab === 'service'"
            ><CardHeader><CardTitle>Complete service</CardTitle></CardHeader
            ><CardContent
                ><p class="text-muted-foreground mb-3 text-sm">
                    Linked jobs must finish their normal completion/confirmation
                    workflow first. Mileage cannot move backwards.
                </p>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="complete"
                >
                    <label class="text-sm"
                        >Service ID<Input
                            v-model="completion.service_id"
                            required
                            type="number"
                            min="1" /></label
                    ><label class="text-sm"
                        >Odometer (km)<Input
                            v-model="completion.odometer"
                            required
                            type="number"
                            min="0"
                            max="999999999" /></label
                    ><label class="text-sm md:col-span-2"
                        >Completion note<Input
                            v-model="completion.note"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in completion.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="completion.processing"
                        >Complete service</Button
                    >
                </form></CardContent
            ></Card
        ><Card v-show="tab === 'service'"
            ><CardHeader
                ><CardTitle>Cancel planned service</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="cancel"
                >
                    <label class="text-sm"
                        >Service ID<Input
                            v-model="cancellation.service_id"
                            required
                            type="number"
                            min="1" /></label
                    ><label class="text-sm"
                        >{{ t('Reason')
                        }}<Input
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
                        >Cancel service</Button
                    >
                </form></CardContent
            ></Card
        ><Card v-show="tab === 'status'"
            ><CardHeader><CardTitle>Vehicle status</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="status"
                >
                    <label class="text-sm"
                        >{{ t('Status')
                        }}<select
                            v-model="lifecycle.status"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="active">{{ t('Active') }}</option>
                            <option value="in_service">In service</option>
                            <option value="retired">Retired</option>
                        </select></label
                    ><label class="text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="lifecycle.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in lifecycle.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        variant="outline"
                        :disabled="lifecycle.processing"
                        >Update status</Button
                    >
                </form></CardContent
            ></Card
        >
    </div>
</template>
