<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Account = {
    id: number;
    code: string;
    name: string;
    type: string;
    is_active: boolean;
    debit: string;
    credit: string;
};
type Period = {
    id: number;
    name: string;
    starts_on: string;
    ends_on: string;
    status: string;
    has_ledger_blocker: boolean;
    ledger_blockers: string[];
};
type JournalLine = {
    id: number;
    debit: string;
    credit: string;
    description: string | null;
    account: { code: string; name: string } | null;
};
type Entry = {
    id: number;
    reference: string;
    source_reference: string | null;
    event: string;
    posted_on: string;
    debit_total: string;
    currency: string;
    reversal_of_id: number | null;
    lines: JournalLine[];
};
const props = defineProps<{
    overview: {
        as_of: string;
        receivables: string;
        payables: string;
        overdue_receivables: string;
        overdue_payables: string;
        unmatched_bank_lines: number;
        unsettled_bank_lines: number;
        current_period: null | {
            id: number;
            name: string;
            starts_on: string;
            ends_on: string;
            status: string;
        };
    };
    accounts: Account[];
    periods: Period[];
    entries: {
        data: Entry[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    canManage: boolean;
    canManagePeriods: boolean;
    canReopenPeriods: boolean;
    asOf: string;
}>();
const asOfDate = ref(props.asOf);
watch(
    () => props.asOf,
    (value) => {
        asOfDate.value = value;
    },
);
const trialBalanceCsv = computed(() =>
    props.asOf
        ? `/accounting/trial-balance.csv?as_of=${encodeURIComponent(props.asOf)}`
        : '/accounting/trial-balance.csv',
);
const overviewCsv = computed(() =>
    props.asOf
        ? `/accounting/overview.csv?as_of=${encodeURIComponent(props.asOf)}`
        : '/accounting/overview.csv',
);
function applyAsOf(): void {
    router.get('/accounting', asOfDate.value ? { as_of: asOfDate.value } : {});
}
const accountForm = useForm({ code: '', name: '', type: 'asset' });
const periodForm = useForm({ name: '', starts_on: '', ends_on: '' });
const journalForm = useForm({
    posted_on: new Date().toISOString().slice(0, 10),
    description: '',
    source_reference: '',
    lines: [
        { ledger_account_id: '', description: '', debit: '', credit: '' },
        { ledger_account_id: '', description: '', debit: '', credit: '' },
    ],
});
const openingBalanceMode = ref(false);
function createAccount(): void {
    accountForm.post('/accounting/accounts', {
        preserveScroll: true,
        onSuccess: () => accountForm.reset(),
    });
}
function createPeriod(): void {
    periodForm.post('/accounting/periods', {
        preserveScroll: true,
        onSuccess: () => periodForm.reset(),
    });
}
function postJournal(): void {
    journalForm.post(
        openingBalanceMode.value
            ? '/accounting/opening-balances'
            : '/accounting/journals',
        {
            preserveScroll: true,
            onSuccess: () => journalForm.reset(),
        },
    );
}
function toggleAccount(account: Account): void {
    router.put(
        `/accounting/accounts/${account.id}`,
        { name: account.name, is_active: !account.is_active },
        { preserveScroll: true },
    );
}
function closePeriod(period: Period): void {
    if (
        confirm(
            `Close ${period.name}? New postings in this period will be rejected.`,
        )
    )
        router.post(
            `/accounting/periods/${period.id}/close`,
            {},
            { preserveScroll: true },
        );
}
function reopenPeriod(period: Period): void {
    router.post(`/accounting/periods/${period.id}/reopen`);
}
function reverse(entry: Entry): void {
    const posted_on = prompt(
        'Reversal date (YYYY-MM-DD)',
        new Date().toISOString().slice(0, 10),
    );
    if (posted_on)
        router.post(
            `/accounting/journals/${entry.id}/reverse`,
            { posted_on },
            { preserveScroll: true },
        );
}
function amount(value: string): string {
    return Number(value).toFixed(2);
}
</script>

<template>
    <Head title="Accounting" />
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Accounting"
            description="AED general ledger, periods, and trial balance. Historical summaries require approved account mappings."
        />
        <div class="flex flex-wrap gap-4 text-sm">
            <Link href="/accounting/legacy-mappings" class="underline"
                >Historical journal mappings</Link
            >
            <Link href="/accounting/activity" class="underline"
                >View account activity</Link
            ><Link href="/accounting/journal-register" class="underline"
                >View journal register</Link
            ><Link href="/accounting/budgets" class="underline"
                >Operating budgets</Link
            ><Link href="/accounting/statements" class="underline"
                >View financial statements</Link
            ><Link href="/accounting/outstanding-balances" class="underline"
                >View outstanding balances</Link
            ><Link href="/accounting/audit-trail" class="underline"
                >View finance audit trail</Link
            ><a :href="overviewCsv" class="underline">Download overview CSV</a>
            <Link href="/accounting/fixed-assets" class="underline"
                >View fixed assets</Link
            ><Link href="/accounting/corporate-tax" class="underline"
                >Prepare corporate tax</Link
            ><Link href="/accounting/vat-return" class="underline"
                >Prepare VAT return</Link
            >
        </div>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <Card
                ><CardHeader
                    ><CardTitle
                        >Receivables · {{ overview.as_of }}</CardTitle
                    ></CardHeader
                ><CardContent
                    ><p class="text-2xl font-semibold">
                        AED {{ overview.receivables }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Overdue AED {{ overview.overdue_receivables }}
                    </p>
                    <Link
                        :href="`/accounting/outstanding-balances?as_of=${overview.as_of}`"
                        class="text-sm underline"
                        >Review receivables</Link
                    ></CardContent
                ></Card
            >
            <Card
                ><CardHeader
                    ><CardTitle
                        >Payables · {{ overview.as_of }}</CardTitle
                    ></CardHeader
                ><CardContent
                    ><p class="text-2xl font-semibold">
                        AED {{ overview.payables }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Overdue AED {{ overview.overdue_payables }}
                    </p>
                    <Link
                        :href="`/accounting/outstanding-balances?as_of=${overview.as_of}`"
                        class="text-sm underline"
                        >Review payables</Link
                    ></CardContent
                ></Card
            >
            <Card
                ><CardHeader><CardTitle>Bank review</CardTitle></CardHeader
                ><CardContent
                    ><p class="text-2xl font-semibold">
                        {{
                            overview.unmatched_bank_lines +
                            overview.unsettled_bank_lines
                        }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        {{ overview.unmatched_bank_lines }} unmatched ·
                        {{ overview.unsettled_bank_lines }} unsettled
                    </p>
                    <Link href="/bank-reconciliation" class="text-sm underline"
                        >Review bank rows</Link
                    ></CardContent
                ></Card
            >
            <Card
                ><CardHeader><CardTitle>Current period</CardTitle></CardHeader
                ><CardContent
                    ><p class="text-lg font-semibold">
                        {{ overview.current_period?.name ?? 'No period' }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        {{
                            overview.current_period
                                ? `${overview.current_period.status} · through ${overview.current_period.ends_on}`
                                : `No period contains ${overview.as_of}`
                        }}
                    </p>
                    <Link
                        v-if="overview.current_period"
                        :href="`/accounting/periods/${overview.current_period.id}/close-readiness`"
                        class="text-sm underline"
                        >Review period</Link
                    ></CardContent
                ></Card
            >
        </div>
        <Card v-if="canManagePeriods"
            ><CardHeader><CardTitle>Accounting periods</CardTitle></CardHeader
            ><CardContent class="space-y-3">
                <form
                    class="flex flex-wrap gap-2"
                    @submit.prevent="createPeriod"
                >
                    <Input
                        v-model="periodForm.name"
                        aria-label="Period name"
                        placeholder="Period name"
                        required
                    /><Input
                        v-model="periodForm.starts_on"
                        aria-label="Period start"
                        type="date"
                        required
                    /><Input
                        v-model="periodForm.ends_on"
                        aria-label="Period end"
                        type="date"
                        required
                    /><Button :disabled="periodForm.processing"
                        >Create period</Button
                    >
                </form>
                <p
                    v-if="periodForm.hasErrors"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    Check the period dates and overlap.
                </p>
                <div
                    v-for="period in periods"
                    :key="period.id"
                    class="flex flex-wrap items-center justify-between gap-2 border-b pb-2 last:border-0"
                >
                    <span
                        >{{ period.name }} · {{ period.starts_on }}–{{
                            period.ends_on
                        }}
                        · {{ period.status }}</span
                    ><span
                        v-if="
                            period.status === 'open' &&
                            period.has_ledger_blocker
                        "
                        class="text-destructive text-xs"
                        >Close blocked:
                        {{ period.ledger_blockers.join(', ') }}</span
                    ><span class="flex items-center gap-2"
                        ><Link
                            :href="`/accounting/periods/${period.id}/close-readiness`"
                            class="text-sm underline"
                            >Review</Link
                        ><Button
                            v-if="period.status === 'open'"
                            size="sm"
                            variant="outline"
                            :disabled="period.has_ledger_blocker"
                            @click="closePeriod(period)"
                            >Close</Button
                        ><Button
                            v-if="
                                period.status === 'closed' && canReopenPeriods
                            "
                            size="sm"
                            variant="outline"
                            @click="reopenPeriod(period)"
                            >{{ t('Reopen') }}</Button
                        ></span
                    >
                </div>
            </CardContent></Card
        >
        <Card v-else
            ><CardHeader><CardTitle>Accounting periods</CardTitle></CardHeader
            ><CardContent
                ><p v-for="period in periods" :key="period.id">
                    {{ period.name }} · {{ period.starts_on }}–{{
                        period.ends_on
                    }}
                    · {{ period.status }} ·
                    <Link
                        :href="`/accounting/periods/${period.id}/close-readiness`"
                        class="underline"
                        >Review</Link
                    >
                </p>
                <p v-if="!periods.length" class="text-muted-foreground text-sm">
                    No periods yet.
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader
                ><CardTitle>Chart of accounts and trial balance</CardTitle
                ><a :href="trialBalanceCsv" class="text-sm underline"
                    >Download CSV</a
                ></CardHeader
            ><CardContent class="space-y-4">
                <form
                    class="flex flex-wrap items-center gap-2"
                    @submit.prevent="applyAsOf"
                >
                    <Input
                        v-model="asOfDate"
                        aria-label="Trial balance as-of date"
                        type="date"
                        class="w-auto"
                    />
                    <Button variant="outline">Apply date</Button>
                    <span class="text-muted-foreground text-sm">{{
                        asOf ? `Through ${asOf}` : 'All posted dates'
                    }}</span>
                </form>
                <form
                    v-if="canManage"
                    class="flex flex-wrap gap-2"
                    @submit.prevent="createAccount"
                >
                    <Input
                        v-model="accountForm.code"
                        aria-label="Account code"
                        placeholder="Code"
                        required
                    /><Input
                        v-model="accountForm.name"
                        aria-label="Account name"
                        placeholder="Name"
                        required
                    /><select
                        v-model="accountForm.type"
                        aria-label="Account type"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option
                            v-for="type in [
                                'asset',
                                'liability',
                                'equity',
                                'income',
                                'expense',
                            ]"
                            :key="type"
                            :value="type"
                        >
                            {{ type }}
                        </option></select
                    ><Button :disabled="accountForm.processing"
                        >Add account</Button
                    >
                </form>
                <p
                    v-if="accountForm.hasErrors"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    Check the account code and name.
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-sm">
                        <thead>
                            <tr class="border-b">
                                <th class="p-2">Code</th>
                                <th class="p-2">Account</th>
                                <th class="p-2">Type</th>
                                <th class="p-2 text-right">Debits</th>
                                <th class="p-2 text-right">Credits</th>
                                <th class="p-2">{{ t('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="account in accounts"
                                :key="account.id"
                                class="border-b"
                            >
                                <td class="p-2">{{ account.code }}</td>
                                <td class="p-2">{{ account.name }}</td>
                                <td class="p-2 capitalize">
                                    {{ account.type }}
                                </td>
                                <td class="p-2 text-right">
                                    {{ amount(account.debit) }}
                                </td>
                                <td class="p-2 text-right">
                                    {{ amount(account.credit) }}
                                </td>
                                <td class="p-2">
                                    <Button
                                        v-if="canManage"
                                        size="sm"
                                        variant="outline"
                                        @click="toggleAccount(account)"
                                        >{{
                                            account.is_active
                                                ? 'Active'
                                                : 'Inactive'
                                        }}</Button
                                    ><span v-else>{{
                                        account.is_active
                                            ? 'Active'
                                            : 'Inactive'
                                    }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p
                        v-if="!accounts.length"
                        class="text-muted-foreground p-2 text-sm"
                    >
                        No accounts yet.
                    </p>
                </div>
            </CardContent></Card
        >
        <Card v-if="canManage"
            ><CardHeader
                ><CardTitle>{{
                    openingBalanceMode
                        ? 'Post opening balance'
                        : 'Post balanced journal'
                }}</CardTitle></CardHeader
            ><CardContent
                ><form class="space-y-3" @submit.prevent="postJournal">
                    <label class="flex items-center gap-2 text-sm"
                        ><input
                            v-model="openingBalanceMode"
                            type="checkbox"
                        />Opening balance</label
                    >
                    <Input
                        v-model="journalForm.posted_on"
                        aria-label="Posting date"
                        type="date"
                        required
                    /><Input
                        v-if="openingBalanceMode"
                        v-model="journalForm.source_reference"
                        aria-label="Opening balance source reference"
                        placeholder="Source reference"
                        maxlength="100"
                        required
                    /><Input
                        v-else
                        v-model="journalForm.description"
                        aria-label="Journal description"
                        placeholder="Description"
                        required
                    />
                    <div
                        v-for="(line, index) in journalForm.lines"
                        :key="index"
                        class="flex flex-wrap gap-2"
                    >
                        <select
                            v-model="line.ledger_account_id"
                            :aria-label="`Line ${index + 1} account`"
                            class="border-input h-9 rounded-md border px-3"
                            required
                        >
                            <option disabled value="">Account</option>
                            <option
                                v-for="account in accounts.filter(
                                    (item) => item.is_active,
                                )"
                                :key="account.id"
                                :value="String(account.id)"
                            >
                                {{ account.code }} · {{ account.name }}
                            </option></select
                        ><Input
                            v-model="line.debit"
                            :aria-label="`Line ${index + 1} debit`"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="Debit"
                            required
                        /><Input
                            v-model="line.credit"
                            :aria-label="`Line ${index + 1} credit`"
                            type="number"
                            min="0"
                            step="0.01"
                            placeholder="Credit"
                            required
                        /><Button
                            v-if="journalForm.lines.length > 2"
                            type="button"
                            variant="outline"
                            @click="journalForm.lines.splice(index, 1)"
                            >{{ t('Remove') }}</Button
                        >
                    </div>
                    <p
                        v-if="journalForm.hasErrors"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        Check the source reference and lines. Each line needs
                        one positive debit or credit, and totals must balance in
                        an open period.
                    </p>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            @click="
                                journalForm.lines.push({
                                    ledger_account_id: '',
                                    description: '',
                                    debit: '',
                                    credit: '',
                                })
                            "
                            >Add line</Button
                        ><Button :disabled="journalForm.processing">{{
                            openingBalanceMode
                                ? 'Post opening balance'
                                : 'Post journal'
                        }}</Button>
                    </div>
                </form></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Journal history</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!entries.data.length"
                    class="text-muted-foreground text-sm"
                >
                    No journal entries yet.
                </p>
                <div
                    v-for="entry in entries.data"
                    :key="entry.id"
                    class="border-b pb-3 last:border-0"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-2"
                    >
                        <strong
                            >{{ entry.reference }} · {{ entry.event }} ·
                            {{ entry.posted_on }}</strong
                        ><span
                            >{{ entry.currency }} {{ entry.debit_total }}</span
                        >
                    </div>
                    <p
                        v-if="entry.source_reference"
                        class="text-muted-foreground text-sm"
                    >
                        Source: {{ entry.source_reference }}
                    </p>
                    <p
                        v-if="!entry.lines.length"
                        class="text-muted-foreground text-sm"
                    >
                        Legacy summary — account allocation pending.
                    </p>
                    <p
                        v-for="line in entry.lines"
                        :key="line.id"
                        class="text-muted-foreground text-sm"
                    >
                        {{ line.account?.code }} {{ line.account?.name }} ·
                        debit {{ line.debit }} · credit {{ line.credit }}
                    </p>
                    <Link
                        v-if="entry.event === 'credit_note.posted'"
                        href="/invoices"
                        class="text-sm underline"
                        >Manage credit note on invoices</Link
                    >
                    <Button
                        v-if="
                            canManage &&
                            entry.lines.length &&
                            !entry.reversal_of_id &&
                            entry.event !== 'credit_note.posted'
                        "
                        size="sm"
                        variant="outline"
                        @click="reverse(entry)"
                        >Reverse</Button
                    >
                </div>
                <div class="flex justify-between">
                    <Link
                        v-if="entries.prev_page_url"
                        :href="entries.prev_page_url"
                        class="text-sm underline"
                        >{{ t('Previous') }}</Link
                    ><span v-else /><Link
                        v-if="entries.next_page_url"
                        :href="entries.next_page_url"
                        class="text-sm underline"
                        >{{ t('Next') }}</Link
                    >
                </div></CardContent
            ></Card
        >
    </div>
</template>
