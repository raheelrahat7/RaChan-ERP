<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import {
    emptyFinancials,
    linkBody,
    outstandingTotal,
} from '@/lib/crm-deal-finance';
import type { FinancialRecords } from '@/lib/crm-deal-finance';

const props = defineProps<{
    dealId: number;
    version: number;
    canEdit: boolean;
    records?: Partial<FinancialRecords> | null;
}>();
const { t } = useLocale();

const data = computed(() => emptyFinancials(props.records));
const outstanding = computed(() => outstandingTotal(data.value.invoices));
const kind = ref<'invoice' | 'commission'>('invoice');
const recordId = ref('');
const error = ref('');
const busy = ref(false);

async function send(
    selected: 'invoice' | 'commission',
    id: string,
    remove: boolean,
): Promise<void> {
    const body = linkBody({
        kind: selected,
        recordId: id,
        expectedVersion: props.version,
        remove,
    });
    if (!body) {
        error.value = t('Enter a valid record ID.');

        return;
    }
    busy.value = true;
    error.value = '';
    try {
        await apiJson(
            `/crm/deals/${props.dealId}/financial-links`,
            'POST',
            body,
        );
        recordId.value = '';
        router.reload();
    } catch (cause) {
        error.value =
            cause instanceof ApiError
                ? (Object.values(cause.fieldErrors())[0] ?? cause.message)
                : t('Something went wrong. Please try again.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="grid gap-6 lg:grid-cols-2">
        <Card>
            <CardHeader
                ><CardTitle class="text-eyebrow">{{
                    t('Invoices')
                }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-2 text-sm">
                <p v-if="!data.invoices.length" class="text-muted-foreground">
                    {{ t('No invoices linked to this deal.') }}
                </p>
                <div
                    v-for="invoice in data.invoices"
                    :key="invoice.id"
                    class="flex flex-wrap items-center justify-between gap-2 border-b pb-2 last:border-0"
                >
                    <span>
                        <span class="font-medium">{{ invoice.reference }}</span>
                        <Badge variant="secondary" class="ms-2">{{
                            invoice.status
                        }}</Badge>
                        <span class="text-muted-foreground block text-xs">
                            {{ invoice.currency }} {{ invoice.total
                            }}<template v-if="invoice.received !== undefined">
                                · {{ t('received') }}
                                {{ invoice.received }}</template
                            ><template v-if="invoice.outstanding !== undefined">
                                · {{ t('outstanding') }}
                                {{ invoice.outstanding }}</template
                            >
                        </span>
                    </span>
                    <Button
                        v-if="canEdit"
                        type="button"
                        size="sm"
                        variant="ghost"
                        :disabled="busy"
                        @click="send('invoice', String(invoice.id), true)"
                        >{{ t('Unlink') }}</Button
                    >
                </div>
                <p v-if="outstanding !== null" class="pt-1 font-medium">
                    {{ t('Outstanding') }}: {{ outstanding }}
                </p>
            </CardContent>
        </Card>

        <Card>
            <CardHeader
                ><CardTitle class="text-eyebrow">{{
                    t('Commissions')
                }}</CardTitle></CardHeader
            >
            <CardContent class="space-y-2 text-sm">
                <p
                    v-if="!data.commissions.length"
                    class="text-muted-foreground"
                >
                    {{ t('No commissions linked to this deal.') }}
                </p>
                <div
                    v-for="commission in data.commissions"
                    :key="commission.id"
                    class="flex flex-wrap items-center justify-between gap-2 border-b pb-2 last:border-0"
                >
                    <span>
                        <span class="font-medium">#{{ commission.id }}</span>
                        <Badge variant="secondary" class="ms-2">{{
                            commission.status
                        }}</Badge>
                        <span class="text-muted-foreground block text-xs"
                            >{{ commission.currency }}
                            {{ commission.commission_amount
                            }}<template v-if="commission.paid_on">
                                · {{ t('paid') }}
                                {{ commission.paid_on }}</template
                            ></span
                        >
                    </span>
                    <Button
                        v-if="canEdit"
                        type="button"
                        size="sm"
                        variant="ghost"
                        :disabled="busy"
                        @click="send('commission', String(commission.id), true)"
                        >{{ t('Unlink') }}</Button
                    >
                </div>
            </CardContent>
        </Card>

        <Card v-if="canEdit" class="lg:col-span-2">
            <CardContent>
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="send(kind, recordId, false)"
                >
                    <div class="space-y-1">
                        <Label for="fl-kind">{{ t('Link a') }}</Label>
                        <select
                            id="fl-kind"
                            v-model="kind"
                            class="border-input bg-background h-9 rounded-md border px-3 text-sm"
                        >
                            <option value="invoice">{{ t('Invoice') }}</option>
                            <option value="commission">
                                {{ t('Commission') }}
                            </option>
                        </select>
                    </div>
                    <div class="space-y-1">
                        <Label for="fl-id">{{ t('Record ID') }}</Label>
                        <Input
                            id="fl-id"
                            v-model="recordId"
                            inputmode="numeric"
                            class="w-32"
                            required
                        />
                    </div>
                    <Button type="submit" :disabled="busy">{{
                        t('Link')
                    }}</Button>
                </form>
                <InputError :message="error" />
                <p class="text-muted-foreground mt-2 text-xs">
                    {{
                        t(
                            'Linking needs deal edit and amount access, plus finance or transaction permission.',
                        )
                    }}
                </p>
            </CardContent>
        </Card>
    </div>
</template>
