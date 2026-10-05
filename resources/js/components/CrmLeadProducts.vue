<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { lineTotal, productDefaults } from '@/lib/crm-lead-products';
import type {
    CatalogProduct,
    LeadEstimate,
    LeadProductLine,
} from '@/lib/crm-lead-products';

const props = defineProps<{ leadId: number; canEdit: boolean }>();
const { t } = useLocale();
const base = `/crm/leads/${props.leadId}/products`;

const lines = ref<LeadProductLine[]>([]);
const estimates = ref<LeadEstimate[]>([]);
const catalog = ref<CatalogProduct[]>([]);
const catalogBlocked = ref(false);
const error = ref('');
const errors = ref<Record<string, string>>({});
const busy = ref(false);
const editingId = ref<number | null>(null);
const form = ref({
    product_id: '',
    quantity: '1',
    unit_price: '',
    currency: 'AED',
});

const available = computed(() =>
    catalog.value.filter(
        (product) =>
            (product.active === true || product.active === 1) &&
            (editingId.value !== null ||
                !lines.value.some((line) => line.product_id === product.id)),
    ),
);

async function load(): Promise<void> {
    try {
        const data = await apiJson<{
            products: LeadProductLine[];
            estimates: LeadEstimate[];
        }>(base);
        lines.value = data.products;
        estimates.value = data.estimates;
        error.value = '';
    } catch {
        error.value = t('Could not load products.');
    }
}

async function loadCatalog(): Promise<void> {
    try {
        const data = await apiJson<{ records: CatalogProduct[] }>(
            '/organization/crm-catalog/products',
        );
        catalog.value = data.records;
    } catch {
        // The catalog is limited to settings managers, so others see the lines only.
        catalogBlocked.value = true;
    }
}

function pick(): void {
    const product = catalog.value.find(
        (item) => String(item.id) === form.value.product_id,
    );
    if (product) {
        form.value = { ...form.value, ...productDefaults(product) };
    }
}

function edit(line: LeadProductLine): void {
    editingId.value = line.id;
    form.value = {
        product_id: String(line.product_id),
        quantity: String(line.quantity),
        unit_price: String(line.unit_price),
        currency: line.currency,
    };
    errors.value = {};
}

function reset(): void {
    editingId.value = null;
    form.value = {
        product_id: '',
        quantity: '1',
        unit_price: '',
        currency: 'AED',
    };
    errors.value = {};
}

async function save(): Promise<void> {
    busy.value = true;
    errors.value = {};
    try {
        const body = {
            product_id: Number(form.value.product_id),
            quantity: form.value.quantity,
            unit_price: form.value.unit_price,
            currency: form.value.currency,
        };
        await apiJson(
            editingId.value ? `${base}/${editingId.value}` : base,
            editingId.value ? 'PUT' : 'POST',
            body,
        );
        reset();
        await load();
    } catch (failure) {
        errors.value =
            failure instanceof ApiError
                ? Object.keys(failure.fieldErrors()).length
                    ? failure.fieldErrors()
                    : { form: failure.message }
                : { form: t('Could not save.') };
    } finally {
        busy.value = false;
    }
}

async function remove(line: LeadProductLine): Promise<void> {
    if (!confirm(`${t('Remove')} ${line.name}?`)) {
        return;
    }
    try {
        await apiJson(`${base}/${line.id}`, 'DELETE');
        await load();
    } catch {
        error.value = t('Could not remove this product.');
    }
}

onMounted(() => {
    void load();
    if (props.canEdit) {
        void loadCatalog();
    }
});
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,4fr)]">
        <Card>
            <CardHeader>
                <CardTitle class="text-eyebrow">{{ t('Products') }}</CardTitle>
            </CardHeader>
            <CardContent class="space-y-4">
                <p v-if="error" role="alert" class="text-destructive text-sm">
                    {{ error }}
                </p>
                <p v-if="!lines.length" class="text-muted-foreground text-sm">
                    {{ t('No products linked yet.') }}
                </p>
                <table v-else class="w-full text-sm">
                    <thead>
                        <tr class="text-muted-foreground text-start text-xs">
                            <th class="py-1 text-start font-normal">
                                {{ t('Product') }}
                            </th>
                            <th class="py-1 text-end font-normal">
                                {{ t('Quantity') }}
                            </th>
                            <th class="py-1 text-end font-normal">
                                {{ t('Price') }}
                            </th>
                            <th class="py-1 text-end font-normal">
                                {{ t('Total') }}
                            </th>
                            <th />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="line in lines"
                            :key="line.id"
                            class="border-t"
                        >
                            <td class="py-2">{{ line.name }}</td>
                            <td class="py-2 text-end">{{ line.quantity }}</td>
                            <td class="py-2 text-end">
                                {{ line.currency }} {{ line.unit_price }}
                            </td>
                            <td class="py-2 text-end font-medium">
                                {{ line.currency }} {{ lineTotal(line) }}
                            </td>
                            <td class="py-2 text-end">
                                <template v-if="canEdit">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="edit(line)"
                                        >{{ t('Edit') }}</Button
                                    >
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        @click="remove(line)"
                                        >{{ t('Remove') }}</Button
                                    >
                                </template>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <form
                    v-if="canEdit && !catalogBlocked"
                    class="space-y-3 rounded-md border p-3"
                    @submit.prevent="save"
                >
                    <InputError :message="errors.form" />
                    <div class="space-y-1">
                        <Label for="lp-product">{{ t('Product') }}</Label>
                        <select
                            id="lp-product"
                            v-model="form.product_id"
                            :disabled="editingId !== null"
                            class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                            required
                            @change="pick"
                        >
                            <option disabled value="">—</option>
                            <option
                                v-for="product in available"
                                :key="product.id"
                                :value="String(product.id)"
                            >
                                {{ product.name }}
                            </option>
                        </select>
                        <InputError :message="errors.product_id" />
                    </div>
                    <div class="grid grid-cols-3 gap-3">
                        <div class="space-y-1">
                            <Label for="lp-qty">{{ t('Quantity') }}</Label
                            ><Input
                                id="lp-qty"
                                v-model="form.quantity"
                                type="number"
                                min="0"
                                step="any"
                                required
                            /><InputError :message="errors.quantity" />
                        </div>
                        <div class="space-y-1">
                            <Label for="lp-price">{{ t('Price') }}</Label
                            ><Input
                                id="lp-price"
                                v-model="form.unit_price"
                                type="number"
                                min="0"
                                step="any"
                                required
                            /><InputError :message="errors.unit_price" />
                        </div>
                        <div class="space-y-1">
                            <Label for="lp-cur">{{ t('Currency') }}</Label
                            ><Input
                                id="lp-cur"
                                v-model="form.currency"
                                maxlength="3"
                                required
                            /><InputError :message="errors.currency" />
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <Button type="submit" size="sm" :disabled="busy">{{
                            editingId ? t('Save') : t('Add product')
                        }}</Button>
                        <Button
                            v-if="editingId"
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="reset"
                            >{{ t('Cancel') }}</Button
                        >
                    </div>
                </form>
                <p
                    v-else-if="canEdit && catalogBlocked"
                    class="text-muted-foreground text-xs"
                >
                    {{
                        t(
                            'Only owners and administrators can pick from the product catalog.',
                        )
                    }}
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle class="text-eyebrow">{{ t('Estimates') }}</CardTitle>
            </CardHeader>
            <CardContent class="space-y-2 text-sm">
                <p v-if="!estimates.length" class="text-muted-foreground">
                    {{ t('No estimates linked to this lead.') }}
                </p>
                <div
                    v-for="estimate in estimates"
                    :key="estimate.id"
                    class="flex items-center justify-between gap-3 border-b pb-2 last:border-0"
                >
                    <span>
                        <span class="font-medium">{{ estimate.title }}</span>
                        <span
                            v-if="estimate.reference"
                            class="text-muted-foreground"
                        >
                            · {{ estimate.reference }}</span
                        >
                    </span>
                    <Badge variant="secondary">{{
                        estimate.stage?.name
                    }}</Badge>
                </div>
                <Link
                    href="/workflows"
                    class="text-primary text-xs underline"
                    >{{ t('Open workflow boards') }}</Link
                >
            </CardContent>
        </Card>
    </div>
</template>
