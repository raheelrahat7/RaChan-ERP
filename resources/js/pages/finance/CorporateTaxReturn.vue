<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
const props = defineProps<{
    profile: string;
    from: string;
    to: string;
    dueOn: string;
    profit: string;
    canManage: boolean;
    canApprove: boolean;
    bankAccounts: { id: number; name: string }[];
    taxReturn: null | {
        id: number;
        status: string;
        taxable_income: string;
        tax_payable: string;
        qfz_de_minimis_met: boolean | null;
        provision_journal_entry_id: number | null;
        provision_reversal_journal_entry_id: number | null;
        payment_journal_entry_id: number | null;
        payment_reversal_journal_entry_id: number | null;
    };
}>();
const form = useForm({
    starts_on: props.from,
    ends_on: props.to,
    revenue: '0',
    exempt_income: '0',
    non_deductible_expenses: '0',
    other_adjustments: '0',
    qualifying_income: '0',
    non_qualifying_income: '0',
    non_qualifying_revenue: '0',
    small_business_relief_elected: false,
    prior_revenue_threshold_confirmed: false,
    qfz_conditions_confirmed: false,
    adjustment_notes: '',
});
function prepare() {
    form.post('/accounting/corporate-tax', { preserveScroll: true });
}
const today = new Date().toISOString().slice(0, 10);
function post(action: string, extra: Record<string, string | number> = {}) {
    if (!props.taxReturn) return;
    router.post(
        `/accounting/corporate-tax/${props.taxReturn.id}/${action}`,
        { posted_on: today, ...extra },
        { preserveScroll: true },
    );
}
function fileReturn() {
    const fta_reference = prompt('FTA filing reference');
    if (fta_reference && props.taxReturn)
        router.post(
            `/accounting/corporate-tax/${props.taxReturn.id}/file`,
            { filed_on: today, fta_reference },
            { preserveScroll: true },
        );
}
function pay() {
    const bank = props.bankAccounts[0];
    if (bank) post('pay', { bank_account_id: bank.id });
}
</script>
<template>
    <Head title="Corporate tax preparation" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Corporate tax preparation"
            :description="`${profile.replaceAll('_', ' ')} · ${from} to ${to}`"
        />
        <div class="flex gap-4 text-sm">
            <Link href="/accounting" class="underline">Back to accounting</Link
            ><a
                v-if="taxReturn"
                :href="`/accounting/corporate-tax.csv?return_id=${taxReturn.id}`"
                class="underline"
                >Download CSV</a
            >
        </div>
        <Card
            ><CardHeader
                ><CardTitle>Ledger starting point</CardTitle></CardHeader
            ><CardContent
                >Accounting profit: AED {{ profit }}</CardContent
            ></Card
        ><Card v-if="taxReturn"
            ><CardHeader><CardTitle>Latest preparation</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                >Taxable income AED {{ taxReturn.taxable_income }} · estimated
                tax AED {{ taxReturn.tax_payable
                }}<span v-if="taxReturn.qfz_de_minimis_met !== null">
                    · QFZ de-minimis
                    {{ taxReturn.qfz_de_minimis_met ? 'met' : 'failed' }}</span
                >
                <p>
                    Status: {{ taxReturn.status }} · filing and payment due
                    {{ dueOn }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-if="canApprove && taxReturn.status === 'prepared'"
                        size="sm"
                        @click="post('approve')"
                        >Approve and post provision</Button
                    ><Button
                        v-if="canApprove && taxReturn.status === 'approved'"
                        size="sm"
                        @click="fileReturn"
                        >Mark internally filed</Button
                    ><Button
                        v-if="
                            canManage &&
                            taxReturn.status === 'filed' &&
                            Number(taxReturn.tax_payable) > 0 &&
                            !taxReturn.payment_journal_entry_id
                        "
                        size="sm"
                        :disabled="!bankAccounts.length"
                        @click="pay"
                        >Pay from
                        {{ bankAccounts[0]?.name ?? 'AED bank' }}</Button
                    ><Button
                        v-if="
                            canManage &&
                            taxReturn.payment_journal_entry_id &&
                            !taxReturn.payment_reversal_journal_entry_id
                        "
                        size="sm"
                        variant="outline"
                        @click="post('reverse-payment')"
                        >Reverse payment</Button
                    ><Button
                        v-if="
                            canManage &&
                            taxReturn.provision_journal_entry_id &&
                            !taxReturn.provision_reversal_journal_entry_id &&
                            (!taxReturn.payment_journal_entry_id ||
                                taxReturn.payment_reversal_journal_entry_id)
                        "
                        size="sm"
                        variant="outline"
                        @click="post('reverse-provision')"
                        >Reverse provision</Button
                    >
                </div></CardContent
            ></Card
        ><Card v-if="canManage"
            ><CardHeader
                ><CardTitle>Prepare internal calculation</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 sm:grid-cols-2"
                    @submit.prevent="prepare"
                >
                    <label
                        v-for="field in [
                            'revenue',
                            'exempt_income',
                            'non_deductible_expenses',
                            'other_adjustments',
                        ]"
                        :key="field"
                        class="text-sm"
                        >{{ field.replaceAll('_', ' ')
                        }}<Input
                            v-model="(form as any)[field]"
                            type="number"
                            step="0.01"
                            required /></label
                    ><template v-if="profile === 'qualifying_free_zone'"
                        ><label class="text-sm"
                            >qualifying income<Input
                                v-model="form.qualifying_income"
                                type="number"
                                step="0.01"
                                required /></label
                        ><label class="text-sm"
                            >non-qualifying income<Input
                                v-model="form.non_qualifying_income"
                                type="number"
                                step="0.01"
                                required /></label
                        ><label class="text-sm"
                            >non-qualifying revenue<Input
                                v-model="form.non_qualifying_revenue"
                                type="number"
                                step="0.01"
                                required /></label
                        ><label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.qfz_conditions_confirmed"
                                type="checkbox"
                            />QFZ conditions confirmed</label
                        ></template
                    ><template v-else
                        ><label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.small_business_relief_elected"
                                type="checkbox"
                            />Elect Small Business Relief</label
                        ><label class="flex items-center gap-2 text-sm"
                            ><input
                                v-model="form.prior_revenue_threshold_confirmed"
                                type="checkbox"
                            />All prior-period revenue was at or below AED 3
                            million</label
                        ></template
                    ><label class="text-sm sm:col-span-2"
                        >Adjustment notes<textarea
                            v-model="form.adjustment_notes"
                            class="bg-background block min-h-24 w-full rounded-md border p-2"
                            required
                        /></label
                    ><Button class="w-fit" :disabled="form.processing"
                        >Prepare calculation</Button
                    >
                </form></CardContent
            ></Card
        >
    </div>
</template>
