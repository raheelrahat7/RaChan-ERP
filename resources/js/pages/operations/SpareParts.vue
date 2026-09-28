<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Part = { id: number; code: string; name: string; unit: string };
type Store = { id: number; code: string; name: string };
type Movement = {
    id: number;
    spare_part_id: number;
    stock_store_id: number;
    maintenance_request_id: number | null;
    type: string;
    quantity_display: string;
    delta_display: string;
    reference: string;
    reason: string | null;
    related_movement_id: number | null;
    reversed_by_movement_id: number | null;
    created_at: string;
};
const props = defineProps<{
    parts: Part[];
    stores: Store[];
    balances: {
        id: number;
        spare_part_id: number;
        stock_store_id: number;
        available: string;
        valuation: {
            value: string | null;
            currency: string | null;
            status: string;
        };
    }[];
    movements: {
        data: Movement[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
    jobs: { id: number; reference: string; title: string }[];
    filters: {
        spare_part_id?: number;
        stock_store_id?: number;
        maintenance_request_id?: number;
    };
}>();
const partForm = useForm({ code: '', name: '', unit: 'pcs' });
const storeForm = useForm({ code: '', name: '' });
const form = useForm({
    type: 'receipt',
    spare_part_id: '',
    stock_store_id: '',
    maintenance_request_id: props.filters.maintenance_request_id
        ? String(props.filters.maintenance_request_id)
        : '',
    related_movement_id: '',
    quantity: '',
    reference: '',
    reason: '',
    operation_key: crypto.randomUUID(),
});
const transferForm = useForm({
    spare_part_id: '',
    source_store_id: '',
    destination_store_id: '',
    quantity: '',
    reference: '',
    reason: '',
    operation_key: crypto.randomUUID(),
});
const correctionForm = useForm({
    transfer_movement_id: '',
    reference: '',
    reason: '',
    operation_key: crypto.randomUUID(),
});
function transfer(): void {
    transferForm.post('/operations/spare-parts/transfers', {
        preserveScroll: true,
        onSuccess: () => {
            transferForm.reset('quantity', 'reference', 'reason');
            transferForm.operation_key = crypto.randomUUID();
        },
    });
}
function correctTransfer(): void {
    correctionForm.post(
        `/operations/spare-parts/transfers/${correctionForm.transfer_movement_id}/reverse`,
        {
            preserveScroll: true,
            onSuccess: () => {
                correctionForm.reset();
                correctionForm.operation_key = crypto.randomUUID();
            },
        },
    );
}
const costForm = useForm({
    movement_id: '',
    amount: '',
    currency: 'AED',
    reason: '',
});
function receiptCost(): void {
    costForm.post(
        `/operations/spare-parts/receipts/${costForm.movement_id}/cost`,
        {
            preserveScroll: true,
            onSuccess: () => costForm.reset('movement_id', 'amount', 'reason'),
        },
    );
}
const filters = useForm({
    spare_part_id: props.filters.spare_part_id
        ? String(props.filters.spare_part_id)
        : '',
    stock_store_id: props.filters.stock_store_id
        ? String(props.filters.stock_store_id)
        : '',
    maintenance_request_id: props.filters.maintenance_request_id
        ? String(props.filters.maintenance_request_id)
        : '',
});
function catalogue(store: boolean): void {
    const target = store ? storeForm : partForm;
    target.post(`/operations/spare-parts/${store ? 'stores' : 'parts'}`, {
        preserveScroll: true,
        onSuccess: () => target.reset(),
    });
}
function record(): void {
    form.post('/operations/spare-parts/movements', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset(
                'quantity',
                'reference',
                'reason',
                'related_movement_id',
            );
            form.operation_key = crypto.randomUUID();
        },
    });
}
function applyFilters(): void {
    router.get('/operations/spare-parts', filters.data(), {
        preserveScroll: true,
    });
}
function part(id: number): Part | undefined {
    return props.parts.find((item) => item.id === id);
}
function store(id: number): Store | undefined {
    return props.stores.find((item) => item.id === id);
}
</script>
<template>
    <Head title="Spare parts" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Link href="/maintenance" class="text-sm underline underline-offset-4"
            >Back to maintenance</Link
        >
        <Heading
            title="Spare parts and stores"
            description="Record physical receipts, job issues and unused-part returns for each store."
        />
        <div class="grid gap-6 md:grid-cols-2">
            <Card
                ><CardHeader><CardTitle>New part</CardTitle></CardHeader
                ><CardContent>
                    <form class="space-y-3" @submit.prevent="catalogue(false)">
                        <label class="block text-sm"
                            >Part code<Input
                                v-model="partForm.code"
                                required
                                maxlength="50"
                        /></label>
                        <label class="block text-sm"
                            >Part name<Input
                                v-model="partForm.name"
                                required
                                maxlength="255"
                        /></label>
                        <label class="block text-sm"
                            >Unit, e.g. pcs or metre<Input
                                v-model="partForm.unit"
                                required
                                maxlength="30"
                        /></label>
                        <p
                            v-for="(message, field) in partForm.errors"
                            :key="field"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ message }}
                        </p>
                        <Button :disabled="partForm.processing"
                            >Create part</Button
                        >
                    </form>
                </CardContent></Card
            >
            <Card
                ><CardHeader
                    ><CardTitle>New store / warehouse</CardTitle></CardHeader
                ><CardContent>
                    <form class="space-y-3" @submit.prevent="catalogue(true)">
                        <label class="block text-sm"
                            >Store code<Input
                                v-model="storeForm.code"
                                required
                                maxlength="50"
                        /></label>
                        <label class="block text-sm"
                            >Store name<Input
                                v-model="storeForm.name"
                                required
                                maxlength="255"
                        /></label>
                        <p
                            v-for="(message, field) in storeForm.errors"
                            :key="field"
                            role="alert"
                            class="text-destructive text-sm"
                        >
                            {{ message }}
                        </p>
                        <Button :disabled="storeForm.processing"
                            >Create store</Button
                        >
                    </form>
                </CardContent></Card
            >
        </div>
        <Card
            ><CardHeader><CardTitle>Available stock</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <p
                    v-if="!balances.length"
                    class="text-muted-foreground text-sm"
                >
                    No stock recorded. Create a part and store, then record a
                    referenced receipt.
                </p>
                <p
                    v-for="balance in balances"
                    :key="balance.id"
                    class="border-b pb-2 text-sm"
                >
                    {{ store(balance.stock_store_id)?.name }} ·
                    {{ part(balance.spare_part_id)?.code }}
                    {{ part(balance.spare_part_id)?.name }} ·
                    {{ balance.available }}
                    {{ part(balance.spare_part_id)?.unit }}
                    <span v-if="balance.valuation.value !== null">
                        · Operational value {{ balance.valuation.currency }}
                        {{ balance.valuation.value }}</span
                    >
                    <span v-else>
                        · Unvalued: missing or inconsistent receipt costs</span
                    >
                </p>
            </CardContent></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Record stock movement</CardTitle></CardHeader
            ><CardContent>
                <form
                    class="grid gap-3 sm:grid-cols-2"
                    @submit.prevent="record"
                >
                    <label class="block text-sm"
                        >Movement
                        <select
                            v-model="form.type"
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="receipt">
                                Receipt / opening stock
                            </option>
                            <option value="issue">Issue to job</option>
                            <option value="return">Unused-part return</option>
                            <option value="reversal">Correct an entry</option>
                        </select>
                    </label>
                    <template v-if="['receipt', 'issue'].includes(form.type)">
                        <label class="block text-sm"
                            >{{ t('Part')
                            }}<select
                                v-model="form.spare_part_id"
                                required
                                class="border-input block h-9 w-full rounded-md border px-3"
                            >
                                <option value="">Choose part</option>
                                <option
                                    v-for="item in parts"
                                    :key="item.id"
                                    :value="String(item.id)"
                                >
                                    {{ item.code }} · {{ item.name }} ({{
                                        item.unit
                                    }})
                                </option>
                            </select></label
                        >
                        <label class="block text-sm"
                            >{{ t('Store')
                            }}<select
                                v-model="form.stock_store_id"
                                required
                                class="border-input block h-9 w-full rounded-md border px-3"
                            >
                                <option value="">Choose store</option>
                                <option
                                    v-for="item in stores"
                                    :key="item.id"
                                    :value="String(item.id)"
                                >
                                    {{ item.code }} · {{ item.name }}
                                </option>
                            </select></label
                        >
                    </template>
                    <label v-if="form.type === 'issue'" class="block text-sm"
                        >Editable job<select
                            v-model="form.maintenance_request_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Choose job</option>
                            <option
                                v-for="job in jobs"
                                :key="job.id"
                                :value="String(job.id)"
                            >
                                {{ job.reference }} · {{ job.title }}
                            </option>
                        </select></label
                    >
                    <label
                        v-if="['return', 'reversal'].includes(form.type)"
                        class="block text-sm"
                        >Original movement ID<Input
                            v-model="form.related_movement_id"
                            type="number"
                            min="1"
                            step="1"
                            required
                        /><span class="text-muted-foreground"
                            >Use the movement ID from history. Part, store and
                            job come from that original entry.</span
                        ></label
                    >
                    <label v-if="form.type !== 'reversal'" class="block text-sm"
                        >Quantity (up to three decimal places)<Input
                            v-model="form.quantity"
                            type="number"
                            min="0.001"
                            max="999999.999"
                            step="0.001"
                            required
                    /></label>
                    <label class="block text-sm"
                        >Receipt / work reference<Input
                            v-model="form.reference"
                            required
                            maxlength="255"
                    /></label>
                    <label
                        v-if="form.type === 'reversal'"
                        class="block text-sm sm:col-span-2"
                        >Reason for correction<textarea
                            v-model="form.reason"
                            required
                            maxlength="2000"
                            rows="3"
                            class="border-input block w-full rounded-md border p-3"
                        />
                    </label>
                    <p
                        v-if="form.type === 'reversal'"
                        class="text-muted-foreground text-sm sm:col-span-2"
                    >
                        Corrections reverse the whole original entry and
                        preserve history. Reverse active returns before
                        correcting their issue. A correction cannot make stock
                        negative.
                    </p>
                    <p class="text-muted-foreground text-sm sm:col-span-2">
                        Returns go back to the original store and cannot exceed
                        the unused issued quantity. Reopen submitted or closed
                        jobs before changing their parts. Quantities do not
                        change bills, journals or job costs.
                    </p>
                    <p
                        v-for="(message, field) in form.errors"
                        :key="field"
                        role="alert"
                        class="text-destructive text-sm sm:col-span-2"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="form.processing"
                        >Record movement</Button
                    >
                </form>
            </CardContent></Card
        >
        <Card>
            <CardHeader
                ><CardTitle>Transfer between stores</CardTitle></CardHeader
            >
            <CardContent>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="transfer"
                >
                    <label class="text-sm"
                        >{{ t('Part')
                        }}<select
                            v-model="transferForm.spare_part_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Select part</option>
                            <option
                                v-for="item in parts"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.code }} · {{ item.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >Source store<select
                            v-model="transferForm.source_store_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Select store</option>
                            <option
                                v-for="item in stores"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >Destination store<select
                            v-model="transferForm.destination_store_id"
                            required
                            class="border-input block h-9 w-full rounded-md border px-3"
                        >
                            <option value="">Select store</option>
                            <option
                                v-for="item in stores"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >{{ t('Quantity')
                        }}<Input
                            v-model="transferForm.quantity"
                            required
                            inputmode="decimal"
                    /></label>
                    <label class="text-sm"
                        >{{ t('Reference')
                        }}<Input
                            v-model="transferForm.reference"
                            required
                            maxlength="255"
                    /></label>
                    <label class="text-sm"
                        >Reason (optional)<Input
                            v-model="transferForm.reason"
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in transferForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="transferForm.processing"
                        >Transfer stock</Button
                    >
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Correct a transfer</CardTitle></CardHeader>
            <CardContent>
                <p class="text-muted-foreground mb-3 text-sm">
                    Use the original transfer_out entry ID from history. Both
                    stores are corrected together; the destination must still
                    have enough stock.
                </p>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="correctTransfer"
                >
                    <label class="text-sm"
                        >Original outbound movement ID<Input
                            v-model="correctionForm.transfer_movement_id"
                            required
                            type="number"
                            min="1"
                    /></label>
                    <label class="text-sm"
                        >Correction reference<Input
                            v-model="correctionForm.reference"
                            required
                            maxlength="255"
                    /></label>
                    <label class="text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="correctionForm.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in correctionForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="correctionForm.processing"
                        >Correct both stores</Button
                    >
                </form>
            </CardContent>
        </Card>
        <Card>
            <CardHeader><CardTitle>Receipt valuation</CardTitle></CardHeader>
            <CardContent>
                <p class="text-muted-foreground mb-3 text-sm">
                    Record the total cost of a receipt, including any allocated
                    landed cost. Weighted-average costs are pooled across stores
                    per part, with one currency per part. Missing costs remain
                    unvalued. Changes recalculate operational reports and are
                    audited; they create no accounting entries.
                </p>
                <form
                    class="grid gap-3 md:grid-cols-2"
                    @submit.prevent="receiptCost"
                >
                    <label class="text-sm"
                        >Receipt movement ID<Input
                            v-model="costForm.movement_id"
                            required
                            type="number"
                            min="1"
                    /></label>
                    <label class="text-sm"
                        >Total receipt cost<Input
                            v-model="costForm.amount"
                            required
                            inputmode="decimal"
                    /></label>
                    <label class="text-sm"
                        >{{ t('Currency')
                        }}<Input
                            v-model="costForm.currency"
                            required
                            maxlength="3"
                    /></label>
                    <label class="text-sm"
                        >Source or correction reason<Input
                            v-model="costForm.reason"
                            required
                            maxlength="2000"
                    /></label>
                    <p
                        v-for="(message, field) in costForm.errors"
                        :key="field"
                        class="text-destructive text-sm"
                        role="alert"
                    >
                        {{ message }}
                    </p>
                    <Button
                        class="justify-self-start"
                        :disabled="costForm.processing"
                        >Record receipt cost</Button
                    >
                </form>
            </CardContent>
        </Card>
        <Card
            ><CardHeader><CardTitle>Movement history</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="applyFilters"
                >
                    <label class="text-sm"
                        >{{ t('Part')
                        }}<select
                            v-model="filters.spare_part_id"
                            class="border-input block h-9 rounded-md border px-3"
                        >
                            <option value="">All parts</option>
                            <option
                                v-for="item in parts"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.code }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >{{ t('Store')
                        }}<select
                            v-model="filters.stock_store_id"
                            class="border-input block h-9 rounded-md border px-3"
                        >
                            <option value="">All stores</option>
                            <option
                                v-for="item in stores"
                                :key="item.id"
                                :value="String(item.id)"
                            >
                                {{ item.name }}
                            </option>
                        </select></label
                    >
                    <label class="text-sm"
                        >Job ID<Input
                            v-model="filters.maintenance_request_id"
                            type="number"
                            min="1"
                    /></label>
                    <Button variant="outline">Filter history</Button>
                </form>
                <p
                    v-if="!movements.data.length"
                    class="text-muted-foreground text-sm"
                >
                    No matching movements.
                </p>
                <article
                    v-for="movement in movements.data"
                    :key="movement.id"
                    class="space-y-1 border-b pb-3 text-sm"
                >
                    <p>
                        #{{ movement.id }} · {{ movement.type }} ·
                        {{ part(movement.spare_part_id)?.code }}
                        {{ part(movement.spare_part_id)?.name }} ·
                        {{ movement.delta_display }}
                        {{ part(movement.spare_part_id)?.unit }} ·
                        {{ store(movement.stock_store_id)?.name }}
                    </p>
                    <p>
                        {{ movement.reference }} · {{ movement.created_at
                        }}<span v-if="movement.related_movement_id">
                            · Original #{{ movement.related_movement_id }}</span
                        ><span v-if="movement.reversed_by_movement_id">
                            · Reversed by #{{
                                movement.reversed_by_movement_id
                            }}</span
                        >
                    </p>
                    <Link
                        v-if="movement.maintenance_request_id"
                        :href="`/maintenance/${movement.maintenance_request_id}/job-card`"
                        class="underline underline-offset-4"
                        >View job</Link
                    >
                    <p v-if="movement.reason" class="whitespace-pre-wrap">
                        {{ movement.reason }}
                    </p>
                </article>
                <div class="flex gap-3">
                    <Link
                        v-if="movements.prev_page_url"
                        :href="movements.prev_page_url"
                        class="text-sm underline"
                        >Previous page</Link
                    ><Link
                        v-if="movements.next_page_url"
                        :href="movements.next_page_url"
                        class="text-sm underline"
                        >Next page</Link
                    >
                </div>
            </CardContent></Card
        >
    </div>
</template>
