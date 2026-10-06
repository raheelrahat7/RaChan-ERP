<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PageHeader from '@/components/PageHeader.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
type Row = {
    date: string;
    reference: string;
    treatment: string;
    net?: string;
    gross: string;
    vat: string;
    recoverable_vat?: string;
};
const props = defineProps<{
    report: {
        from: string;
        to: string;
        sales: Row[];
        purchases: Row[];
        totals: Record<string, string>;
    };
    frequency: string;
    trn: string;
    canManage: boolean;
    canFile: boolean;
    bankAccounts: { id: number; name: string }[];
    vatReturn: null | {
        id: number;
        status: string;
        filed_on: string | null;
        fta_reference: string | null;
        preparer: { name: string };
        filer: { name: string } | null;
        adjustments: {
            id: number;
            discovered_on: string;
            output_vat_delta: string;
            input_vat_delta: string;
            correction_method: string;
            reason: string;
            recorder: { name: string };
        }[];
        settlement: null | {
            id: number;
            type: 'payment' | 'refund_receivable';
            net_vat: string;
            reversal_journal_entry_id: number | null;
            receipt_journal_entry_id: number | null;
            receipt_reversal_journal_entry_id: number | null;
        };
    };
}>();
const period = ref(props.report.from.slice(0, 7));
const today = new Date().toISOString().slice(0, 10);
const settlementForm = useForm({
    bank_account_id: props.bankAccounts[0]?.id ?? '',
    posted_on: today,
});
function load(): void {
    router.get('/accounting/vat-return', { period: period.value });
}
function prepare(): void {
    router.post(
        '/accounting/vat-return/prepare',
        { period: period.value },
        { preserveScroll: true },
    );
}
function fileReturn(): void {
    if (!props.vatReturn) return;
    const filed_on = prompt(
        'FTA filing date (YYYY-MM-DD)',
        new Date().toISOString().slice(0, 10),
    );
    const fta_reference = filed_on ? prompt('FTA filing reference') : null;
    if (filed_on && fta_reference)
        router.post(
            `/accounting/vat-returns/${props.vatReturn.id}/file`,
            { filed_on, fta_reference },
            { preserveScroll: true },
        );
}
function adjust(): void {
    if (!props.vatReturn) return;
    const output_vat_delta = prompt(
        'Output VAT adjustment (signed AED)',
        '0.00',
    );
    if (output_vat_delta === null) return;
    const input_vat_delta = prompt('Input VAT adjustment (signed AED)', '0.00');
    if (input_vat_delta === null) return;
    const reason = prompt('Reason for adjustment');
    if (!reason) return;
    const correction_method = confirm(
        'Use voluntary disclosure? Select Cancel for current-return correction.',
    )
        ? 'voluntary_disclosure'
        : 'current_return';
    router.post(
        `/accounting/vat-returns/${props.vatReturn.id}/adjustments`,
        {
            discovered_on: new Date().toISOString().slice(0, 10),
            output_vat_delta,
            input_vat_delta,
            reason,
            correction_method,
        },
        { preserveScroll: true },
    );
}
function settle(): void {
    if (!props.vatReturn) return;
    settlementForm.post(
        `/accounting/vat-returns/${props.vatReturn.id}/settle`,
        { preserveScroll: true },
    );
}
function settlementAction(
    action: 'receive-refund' | 'reverse' | 'reverse-refund',
): void {
    const settlement = props.vatReturn?.settlement;
    if (!settlement) return;
    const posted_on = prompt('Posting date (YYYY-MM-DD)', today);
    if (posted_on)
        router.post(
            `/accounting/vat-settlements/${settlement.id}/${action}`,
            { posted_on },
            { preserveScroll: true },
        );
}
</script>
<template>
    <Head title="VAT201 preparation" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <PageHeader
            title="VAT201 preparation"
            :description="`${frequency} internal report · TRN ${trn} · no FTA submission`"
        />
        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm"
                >Period containing<Input v-model="period" type="month" /></label
            ><Button @click="load">Load</Button
            ><a
                :href="`/accounting/vat-return.csv?period=${period}`"
                class="text-sm underline"
                >Download CSV</a
            ><Link href="/accounting" class="text-sm underline"
                >Back to accounting</Link
            >
        </div>
        <Card
            ><CardHeader
                ><CardTitle>Internal filing status</CardTitle></CardHeader
            ><CardContent class="flex flex-wrap items-center gap-3"
                ><span v-if="vatReturn"
                    >{{ vatReturn.status }} · prepared by
                    {{ vatReturn.preparer.name
                    }}<template v-if="vatReturn.filer">
                        · filed {{ vatReturn.filed_on }} by
                        {{ vatReturn.filer.name }} ·
                        {{ vatReturn.fta_reference }}</template
                    ></span
                ><span v-else>Not prepared</span
                ><Button
                    v-if="canManage && vatReturn?.status !== 'filed'"
                    size="sm"
                    @click="prepare"
                    >Prepare snapshot</Button
                ><Button
                    v-if="canFile && vatReturn?.status === 'prepared'"
                    size="sm"
                    @click="fileReturn"
                    >Mark filed</Button
                ><Button
                    v-if="canManage && vatReturn?.status === 'filed'"
                    size="sm"
                    variant="outline"
                    @click="adjust"
                    >Record adjustment</Button
                ></CardContent
            ></Card
        >
        <Card v-if="vatReturn?.adjustments.length"
            ><CardHeader
                ><CardTitle>Post-filing adjustments</CardTitle></CardHeader
            ><CardContent
                ><div
                    v-for="item in vatReturn.adjustments"
                    :key="item.id"
                    class="border-b py-2 text-sm"
                >
                    {{ item.discovered_on }} · output
                    {{ item.output_vat_delta }} · input
                    {{ item.input_vat_delta }} · {{ item.correction_method }} ·
                    {{ item.reason }} · {{ item.recorder.name }}
                </div></CardContent
            ></Card
        >
        <Card v-if="canManage && vatReturn?.status === 'filed'">
            <CardHeader><CardTitle>VAT settlement</CardTitle></CardHeader>
            <CardContent class="space-y-3">
                <template v-if="!vatReturn.settlement">
                    <p class="text-muted-foreground text-sm">
                        Select the active AED bank account used for payment or
                        refund receipt.
                    </p>
                    <div class="flex flex-wrap items-end gap-3">
                        <label class="text-sm"
                            >Bank account
                            <select
                                v-model="settlementForm.bank_account_id"
                                class="bg-background block h-9 rounded-md border px-3"
                            >
                                <option disabled value="">Select a bank</option>
                                <option
                                    v-for="bank in bankAccounts"
                                    :key="bank.id"
                                    :value="bank.id"
                                >
                                    {{ bank.name }}
                                </option>
                            </select>
                        </label>
                        <label class="text-sm"
                            >Posting date<Input
                                v-model="settlementForm.posted_on"
                                type="date"
                        /></label>
                        <Button
                            :disabled="
                                settlementForm.processing ||
                                !settlementForm.bank_account_id
                            "
                            @click="settle"
                            >Approve settlement</Button
                        >
                    </div>
                </template>
                <template v-else>
                    <p class="text-sm">
                        {{
                            vatReturn.settlement.type === 'payment'
                                ? 'VAT paid'
                                : 'VAT refund receivable'
                        }}
                        · AED
                        {{
                            Math.abs(
                                Number(vatReturn.settlement.net_vat),
                            ).toFixed(2)
                        }}
                        <span
                            v-if="
                                vatReturn.settlement.reversal_journal_entry_id
                            "
                        >
                            · reversed</span
                        >
                    </p>
                    <div
                        v-if="!vatReturn.settlement.reversal_journal_entry_id"
                        class="flex flex-wrap gap-2"
                    >
                        <Button
                            v-if="
                                vatReturn.settlement.type ===
                                    'refund_receivable' &&
                                !vatReturn.settlement.receipt_journal_entry_id
                            "
                            size="sm"
                            @click="settlementAction('receive-refund')"
                            >Record refund receipt</Button
                        >
                        <Button
                            v-if="
                                vatReturn.settlement.receipt_journal_entry_id &&
                                !vatReturn.settlement
                                    .receipt_reversal_journal_entry_id
                            "
                            size="sm"
                            variant="outline"
                            @click="settlementAction('reverse-refund')"
                            >Reverse refund receipt</Button
                        >
                        <Button
                            v-if="
                                !vatReturn.settlement
                                    .receipt_journal_entry_id ||
                                vatReturn.settlement
                                    .receipt_reversal_journal_entry_id
                            "
                            size="sm"
                            variant="outline"
                            @click="settlementAction('reverse')"
                            >Reverse settlement</Button
                        >
                    </div>
                </template>
            </CardContent>
        </Card>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Card v-for="(value, key) in report.totals" :key="key"
                ><CardHeader
                    ><CardTitle class="text-sm">{{
                        String(key).replaceAll('_', ' ')
                    }}</CardTitle></CardHeader
                ><CardContent class="text-xl font-semibold"
                    >AED {{ value }}</CardContent
                ></Card
            >
        </div>
        <Card
            ><CardHeader
                ><CardTitle
                    >Sales · {{ report.from }} to {{ report.to }}</CardTitle
                ></CardHeader
            ><CardContent class="overflow-x-auto"
                ><table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th>{{ t('Date') }}</th>
                            <th>{{ t('Reference') }}</th>
                            <th>Treatment</th>
                            <th class="text-right">{{ t('Net') }}</th>
                            <th class="text-right">VAT</th>
                            <th class="text-right">Gross</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in report.sales"
                            :key="row.reference"
                            class="border-b"
                        >
                            <td>{{ row.date }}</td>
                            <td>{{ row.reference }}</td>
                            <td>{{ row.treatment }}</td>
                            <td class="text-right">{{ row.net }}</td>
                            <td class="text-right">{{ row.vat }}</td>
                            <td class="text-right">{{ row.gross }}</td>
                        </tr>
                        <tr v-if="!report.sales.length">
                            <td colspan="6" class="py-5 text-center">
                                No classified sales.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Purchases</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto"
                ><table class="w-full text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th>{{ t('Date') }}</th>
                            <th>{{ t('Reference') }}</th>
                            <th>Treatment</th>
                            <th class="text-right">Gross</th>
                            <th class="text-right">VAT</th>
                            <th class="text-right">Recoverable</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in report.purchases"
                            :key="row.reference"
                            class="border-b"
                        >
                            <td>{{ row.date }}</td>
                            <td>{{ row.reference }}</td>
                            <td>{{ row.treatment }}</td>
                            <td class="text-right">{{ row.gross }}</td>
                            <td class="text-right">{{ row.vat }}</td>
                            <td class="text-right">
                                {{ row.recoverable_vat }}
                            </td>
                        </tr>
                        <tr v-if="!report.purchases.length">
                            <td colspan="6" class="py-5 text-center">
                                No classified purchases.
                            </td>
                        </tr>
                    </tbody>
                </table></CardContent
            ></Card
        >
    </div>
</template>
