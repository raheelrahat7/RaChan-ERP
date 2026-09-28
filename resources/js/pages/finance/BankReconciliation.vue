<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type BankLine = {
    id: number;
    external_id: string;
    occurred_on: string;
    description: string;
    amount: string;
    matched_journal_line_id: number | null;
    bank_account: { name: string };
    settlements: { id: number; journal_entry: { reference: string } }[];
};
type Candidate = {
    id: number;
    debit: string;
    credit: string;
    account: { code: string; name: string };
    entry: { reference: string; event: string; posted_on: string };
};
const props = defineProps<{
    accounts: { id: number; name: string; ledger_account_id: number | null }[];
    assetAccounts: { id: number; code: string; name: string }[];
    lines: {
        data: BankLine[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    candidates: Candidate[];
    filters: { bank_account_id: string; status: string };
    canManage: boolean;
}>();
const bankFilter = ref(props.filters.bank_account_id);
const statusFilter = ref(props.filters.status);
function applyFilters(): void {
    router.get('/bank-reconciliation', {
        bank_account_id: bankFilter.value || undefined,
        status: statusFilter.value === 'all' ? undefined : statusFilter.value,
    });
}
const accountForm = useForm({ name: '' });
const importForm = useForm<{ bank_account_id: string; file: File | null }>({
    bank_account_id: '',
    file: null,
});
const selectedMatches = reactive<Record<number, string>>({});
const selectedLedgerAccounts = reactive<Record<number, string>>({});
const reversalDates = reactive<Record<number, string>>({});
function linkLedgerAccount(accountId: number): void {
    const ledger_account_id = selectedLedgerAccounts[accountId];
    if (ledger_account_id)
        router.put(
            `/bank-reconciliation/accounts/${accountId}/ledger-account`,
            { ledger_account_id },
            { preserveScroll: true },
        );
}
function settle(line: BankLine): void {
    router.post(
        `/bank-reconciliation/lines/${line.id}/settle`,
        {},
        { preserveScroll: true },
    );
}
function reverseSettlement(line: BankLine): void {
    const posted_on = reversalDates[line.id];
    if (posted_on)
        router.post(
            `/bank-reconciliation/lines/${line.id}/reverse-settlement`,
            { posted_on },
            { preserveScroll: true },
        );
}
function createAccount(): void {
    accountForm.post('/bank-reconciliation/accounts', {
        preserveScroll: true,
        onSuccess: () => accountForm.reset(),
    });
}
function setFile(event: Event): void {
    importForm.file = (event.target as HTMLInputElement).files?.[0] ?? null;
}
function importCsv(): void {
    importForm.post('/bank-reconciliation/import', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => importForm.reset(),
    });
}
function candidatesFor(line: BankLine): Candidate[] {
    const incoming = Number(line.amount) > 0;
    return props.candidates.filter(
        (candidate) =>
            candidate.account.code === (incoming ? '1150' : '1190') &&
            candidate.entry.event ===
                (incoming ? 'payment.received' : 'vendor_bill.paid') &&
            Number(incoming ? candidate.debit : candidate.credit) ===
                Math.abs(Number(line.amount)),
    );
}
function match(line: BankLine): void {
    const journal_line_id = selectedMatches[line.id];
    if (journal_line_id)
        router.post(
            `/bank-reconciliation/lines/${line.id}/match`,
            { journal_line_id },
            { preserveScroll: true },
        );
}
function unmatch(line: BankLine): void {
    router.post(
        `/bank-reconciliation/lines/${line.id}/unmatch`,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Bank reconciliation" />
    <div class="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Bank reconciliation"
            description="Import AED bank rows, manually match clearing entries, then approve settlement into the linked bank ledger account."
        />
        <Card v-if="canManage"
            ><CardHeader><CardTitle>Bank accounts</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><form
                    class="flex flex-wrap gap-2"
                    @submit.prevent="createAccount"
                >
                    <Input
                        v-model="accountForm.name"
                        aria-label="Bank account label"
                        placeholder="Bank account label"
                        required
                    /><Button :disabled="accountForm.processing"
                        >Add account</Button
                    >
                </form>
                <p
                    v-if="accountForm.hasErrors"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    Enter a unique account label.
                </p>
                <div
                    v-for="account in accounts"
                    :key="account.id"
                    class="flex flex-wrap items-center gap-2 text-sm"
                >
                    <span>{{ account.name }}</span>
                    <select
                        v-model="selectedLedgerAccounts[account.id]"
                        :aria-label="`Ledger account for ${account.name}`"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">
                            {{
                                account.ledger_account_id
                                    ? `Linked account #${account.ledger_account_id}`
                                    : 'Choose bank ledger account'
                            }}
                        </option>
                        <option
                            v-for="ledger in assetAccounts"
                            :key="ledger.id"
                            :value="String(ledger.id)"
                        >
                            {{ ledger.code }} · {{ ledger.name }}
                        </option>
                    </select>
                    <Button
                        size="sm"
                        variant="outline"
                        :disabled="!selectedLedgerAccounts[account.id]"
                        @click="linkLedgerAccount(account.id)"
                        >Link ledger</Button
                    >
                </div>
                <p
                    v-if="!accounts.length"
                    class="text-muted-foreground text-sm"
                >
                    No bank accounts yet.
                </p>
                <p class="text-muted-foreground text-sm">
                    Create a bank asset account in Accounting before linking it
                    here.
                </p>
            </CardContent></Card
        >
        <Card v-if="canManage"
            ><CardHeader><CardTitle>Import bank CSV</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p class="text-muted-foreground text-sm">
                    Required header:
                    <code>transaction_id,date,description,amount</code>. Dates
                    use YYYY-MM-DD; amounts use signed AED with two decimals.
                    Each transaction ID must be stable within its bank account.
                </p>
                <form
                    class="flex flex-wrap items-center gap-2"
                    @submit.prevent="importCsv"
                >
                    <select
                        v-model="importForm.bank_account_id"
                        aria-label="Bank account"
                        class="border-input h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">Bank account</option>
                        <option
                            v-for="account in accounts"
                            :key="account.id"
                            :value="String(account.id)"
                        >
                            {{ account.name }}
                        </option></select
                    ><input
                        aria-label="Bank CSV file"
                        type="file"
                        accept=".csv,text/csv"
                        required
                        @change="setFile"
                    /><Button
                        :disabled="importForm.processing || !importForm.file"
                        >Import</Button
                    >
                </form>
                <p
                    v-if="importForm.hasErrors"
                    role="alert"
                    class="text-destructive text-sm"
                >
                    {{
                        importForm.errors.file ||
                        'Check the bank account and CSV.'
                    }}
                </p></CardContent
            ></Card
        >
        <Card
            ><CardHeader><CardTitle>Statement rows</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><form
                    class="flex flex-wrap gap-2"
                    @submit.prevent="applyFilters"
                >
                    <select
                        v-model="bankFilter"
                        aria-label="Filter bank account"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="">All bank accounts</option>
                        <option
                            v-for="account in accounts"
                            :key="account.id"
                            :value="String(account.id)"
                        >
                            {{ account.name }}
                        </option>
                    </select>
                    <select
                        v-model="statusFilter"
                        aria-label="Filter match status"
                        class="border-input h-9 rounded-md border px-3"
                    >
                        <option value="all">All rows</option>
                        <option value="unmatched">Unmatched</option>
                        <option value="matched">Matched</option>
                    </select>
                    <Button variant="outline">Filter</Button>
                </form>
                <p
                    v-if="!lines.data.length"
                    class="text-muted-foreground text-sm"
                >
                    No statement rows imported yet.
                </p>
                <div
                    v-for="line in lines.data"
                    :key="line.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-b pb-3 last:border-0"
                >
                    <div>
                        <strong
                            >{{ line.bank_account.name }} ·
                            {{ line.external_id }}</strong
                        >
                        <p class="text-muted-foreground text-sm">
                            {{ line.occurred_on }} · {{ line.description }} ·
                            AED {{ line.amount }}
                        </p>
                    </div>
                    <div
                        v-if="line.settlements.length"
                        class="flex flex-wrap items-center gap-2 text-sm"
                    >
                        <span
                            >Settled ·
                            {{
                                line.settlements[0]?.journal_entry.reference
                            }}</span
                        >
                        <template v-if="canManage">
                            <Input
                                v-model="reversalDates[line.id]"
                                type="date"
                                :aria-label="`Reversal date for ${line.external_id}`"
                                class="w-auto"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                :disabled="!reversalDates[line.id]"
                                @click="reverseSettlement(line)"
                                >Reverse settlement</Button
                            >
                        </template>
                    </div>
                    <div
                        v-else-if="line.matched_journal_line_id"
                        class="flex items-center gap-2"
                    >
                        <span class="text-sm"
                            >Matched to line #{{
                                line.matched_journal_line_id
                            }}</span
                        ><Button
                            v-if="canManage"
                            size="sm"
                            @click="settle(line)"
                            >Approve settlement</Button
                        ><Button
                            v-if="canManage"
                            size="sm"
                            variant="outline"
                            @click="unmatch(line)"
                            >Unmatch</Button
                        >
                    </div>
                    <div
                        v-else-if="canManage"
                        class="flex flex-wrap items-center gap-2"
                    >
                        <select
                            v-model="selectedMatches[line.id]"
                            :aria-label="`Match for ${line.external_id}`"
                            class="border-input h-9 max-w-xs rounded-md border px-2 text-sm"
                        >
                            <option value="">Choose clearing entry</option>
                            <option
                                v-for="candidate in candidatesFor(line)"
                                :key="candidate.id"
                                :value="String(candidate.id)"
                            >
                                {{ candidate.entry.reference }} ·
                                {{ candidate.entry.posted_on }} · AED
                                {{
                                    Number(line.amount) > 0
                                        ? candidate.debit
                                        : candidate.credit
                                }}
                            </option></select
                        ><Button
                            size="sm"
                            :disabled="!selectedMatches[line.id]"
                            @click="match(line)"
                            >Confirm match</Button
                        >
                    </div>
                    <span v-else class="text-muted-foreground text-sm"
                        >Unmatched</span
                    >
                </div>
                <div class="flex justify-between">
                    <Link
                        v-if="lines.prev_page_url"
                        :href="lines.prev_page_url"
                        class="text-sm underline"
                        >{{ t('Previous') }}</Link
                    ><span v-else /><Link
                        v-if="lines.next_page_url"
                        :href="lines.next_page_url"
                        class="text-sm underline"
                        >{{ t('Next') }}</Link
                    >
                </div></CardContent
            ></Card
        >
    </div>
</template>
