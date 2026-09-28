<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

type Account = { id: number; code: string; name: string };
type MonthlyAmount = {
    budget: string;
    actual: string | null;
    variance: string | null;
    version: number | null;
};
type BudgetRow = {
    account_id: number;
    code: string;
    name: string;
    is_active: boolean;
    months: Record<number, MonthlyAmount>;
    budget_to_date: string;
    actual_to_date: string;
    remaining: string;
    variance_to_date: string;
};
type BudgetLine = {
    account_id: number;
    account: Account | null;
    monthly_amounts: Record<number, string>;
};
type Budget = {
    id: number;
    year: number;
    version: number;
    status: string;
    effective_from: string | null;
    reason: string;
    rejection_reason: string | null;
    submitted_by?: number | null;
    submitter_name?: string | null;
    lines: BudgetLine[];
};
const props = defineProps<{
    filters: { year: number; through_month: number };
    report: {
        year: number;
        through_month: number;
        rows: BudgetRow[];
        totals: {
            budget_to_date: string;
            actual_to_date: string;
            remaining: string;
            variance_to_date: string;
        };
        versions: {
            id: number;
            version: number;
            status: string;
            effective_from: string | null;
            decision_at: string | null;
            reason: string;
            decision_reason: string | null;
            decided_by: string | null;
            lines: {
                account: { code: string; name: string } | null;
                monthly_amounts: Record<number, string>;
            }[];
        }[];
        selected_version: number | null;
        draft: Budget | null;
        pending: Budget | null;
        accounts: Account[];
    };
    canManage: boolean;
    canReviewPending: boolean;
    canCreate: boolean;
}>();
const selectedYear = ref(String(props.filters.year));
const selectedMonth = ref(String(props.filters.through_month));
const selectedAccountId = ref('');
const editingAccountId = ref<number | null>(null);
const currentYear = new Date().getFullYear();
const monthNames = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];
const draftForm = useForm({
    year: String(props.filters.year),
    effective_from: nextMonth(),
    reason: '',
});
const lineForm = useForm({
    account_id: '',
    monthly_amounts: Array.from({ length: 12 }, () => '0.00'),
});
const submitForm = useForm({ budget: '' });
const rebaseForm = useForm({
    effective_from: props.report.draft?.effective_from ?? nextMonth(),
});
const reviewForm = useForm({ reason: '', budget: '' });
const pendingError = computed(
    () => reviewForm.errors.budget ?? reviewForm.errors.reason,
);
function nextMonth(): string {
    const date = new Date();
    const next = new Date(date.getFullYear(), date.getMonth() + 1, 1);
    return `${next.getFullYear()}-${String(next.getMonth() + 1).padStart(2, '0')}-01`;
}
function applyFilters(): void {
    router.get(
        '/accounting/budgets',
        { year: selectedYear.value, through_month: selectedMonth.value },
        { preserveState: true },
    );
}
function createDraft(): void {
    draftForm.post('/accounting/budgets', {
        preserveScroll: true,
        onSuccess: () => draftForm.reset('reason'),
    });
}
function editLine(line?: BudgetLine): void {
    editingAccountId.value = line?.account_id ?? null;
    selectedAccountId.value = String(line?.account_id ?? '');
    const amounts = Array.from(
        { length: 12 },
        (_, index) => line?.monthly_amounts[index] ?? '0.00',
    );
    lineForm.account_id = selectedAccountId.value;
    lineForm.monthly_amounts = amounts;
    lineForm.clearErrors();
}
function saveLine(): void {
    if (!props.report.draft) return;
    lineForm.account_id = selectedAccountId.value;
    lineForm.put(`/accounting/budgets/${props.report.draft.id}/accounts`, {
        preserveScroll: true,
        onSuccess: () => editLine(),
    });
}
function removeLine(accountId: number): void {
    if (!props.report.draft) return;
    router.delete(
        `/accounting/budgets/${props.report.draft.id}/accounts/${accountId}`,
        { preserveScroll: true, onSuccess: () => editLine() },
    );
}
function rebaseBudget(): void {
    if (!props.report.draft) return;
    rebaseForm.put(
        `/accounting/budgets/${props.report.draft.id}/effective-month`,
        {
            preserveScroll: true,
            onSuccess: () => editLine(),
        },
    );
}
function submitBudget(): void {
    if (!props.report.draft) return;
    submitForm.post(`/accounting/budgets/${props.report.draft.id}/submit`, {
        preserveScroll: true,
    });
}
function approveBudget(): void {
    if (!props.report.pending) return;
    reviewForm.post(`/accounting/budgets/${props.report.pending.id}/approve`, {
        preserveScroll: true,
        onSuccess: () => reviewForm.reset(),
    });
}
function rejectBudget(): void {
    if (!props.report.pending) return;
    reviewForm.post(`/accounting/budgets/${props.report.pending.id}/reject`, {
        preserveScroll: true,
        onSuccess: () => reviewForm.reset(),
    });
}
</script>

<template>
    <Head title="Operating budgets" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Operating budgets"
            description="Approved monthly operating expense budgets compared with posted AED ledger activity."
        />
        <a href="/accounting" class="text-sm underline">Back to accounting</a>
        <Card
            ><CardContent class="pt-6">
                <form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="applyFilters"
                >
                    <label class="text-sm"
                        >Budget year<Input
                            v-model="selectedYear"
                            type="number"
                            min="1900"
                            max="9999"
                            required
                    /></label>
                    <label class="text-sm"
                        >Actuals through month<select
                            v-model="selectedMonth"
                            class="border-input bg-background block h-9 rounded-md border px-3"
                        >
                            <option
                                v-for="(month, index) in monthNames"
                                :key="month"
                                :value="String(index + 1)"
                            >
                                {{ month }}
                            </option>
                        </select></label
                    >
                    <Button>{{ t('Apply') }}</Button>
                    <a
                        :href="`/accounting/budgets.csv?year=${encodeURIComponent(selectedYear)}&through_month=${encodeURIComponent(selectedMonth)}`"
                        class="text-sm underline"
                        >Download CSV</a
                    >
                </form>
            </CardContent></Card
        >
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Card
                ><CardHeader
                    ><CardTitle>Approved budget to date</CardTitle></CardHeader
                ><CardContent
                    ><p class="text-2xl font-semibold">
                        AED {{ report.totals.budget_to_date }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Version {{ report.selected_version ?? 'none' }}
                    </p></CardContent
                ></Card
            >
            <Card
                ><CardHeader><CardTitle>Posted actuals</CardTitle></CardHeader
                ><CardContent
                    ><p class="text-2xl font-semibold">
                        AED {{ report.totals.actual_to_date }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Detailed ledger activity through
                        {{ monthNames[report.through_month - 1] }}
                    </p></CardContent
                ></Card
            >
            <Card
                ><CardHeader><CardTitle>Remaining budget</CardTitle></CardHeader
                ><CardContent
                    ><p class="text-2xl font-semibold">
                        AED {{ report.totals.remaining }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Budget minus actuals
                    </p></CardContent
                ></Card
            >
            <Card
                ><CardHeader><CardTitle>Variance</CardTitle></CardHeader
                ><CardContent
                    ><p class="text-2xl font-semibold">
                        AED {{ report.totals.variance_to_date }}
                    </p>
                    <p class="text-muted-foreground text-sm">
                        Positive means over budget
                    </p></CardContent
                ></Card
            >
        </div>
        <Card
            ><CardHeader
                ><CardTitle>Account budget and actuals</CardTitle></CardHeader
            ><CardContent class="overflow-x-auto">
                <table class="w-full min-w-[1500px] text-sm">
                    <thead>
                        <tr class="border-b text-left">
                            <th class="py-2">Account</th>
                            <th
                                v-for="(month, index) in monthNames"
                                :key="month"
                                class="px-2 text-right"
                            >
                                {{ month }} plan / actual
                            </th>
                            <th class="text-right">Budget to date</th>
                            <th class="text-right">Actual to date</th>
                            <th class="text-right">Remaining</th>
                            <th class="text-right">Variance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="row in report.rows"
                            :key="row.account_id"
                            class="border-b"
                        >
                            <th class="py-2 text-left font-medium">
                                {{ row.code }} {{ row.name
                                }}<span
                                    v-if="!row.is_active"
                                    class="text-muted-foreground"
                                >
                                    · inactive</span
                                >
                            </th>
                            <td
                                v-for="index in 12"
                                :key="index"
                                class="px-2 py-2 text-right tabular-nums"
                            >
                                <span
                                    >AED
                                    {{
                                        row.months[index]?.budget ?? '0.00'
                                    }}</span
                                ><span class="text-muted-foreground block">{{
                                    row.months[index]?.actual === null ||
                                    row.months[index]?.actual === undefined
                                        ? '—'
                                        : `AED ${row.months[index].actual}`
                                }}</span>
                            </td>
                            <td class="text-right tabular-nums">
                                {{ row.budget_to_date }}
                            </td>
                            <td class="text-right tabular-nums">
                                {{ row.actual_to_date }}
                            </td>
                            <td class="text-right tabular-nums">
                                {{ row.remaining }}
                            </td>
                            <td class="text-right tabular-nums">
                                {{ row.variance_to_date }}
                            </td>
                        </tr>
                        <tr v-if="!report.rows.length">
                            <td
                                colspan="17"
                                class="text-muted-foreground py-5 text-center"
                            >
                                No expense accounts are available.
                            </td>
                        </tr>
                    </tbody>
                </table>
                <p
                    v-if="!report.selected_version"
                    class="text-muted-foreground mt-3 text-sm"
                >
                    No approved budget applies yet. Posted expenses still appear
                    as actuals with a zero budget.
                </p>
            </CardContent></Card
        >
        <Card v-if="canCreate"
            ><CardHeader><CardTitle>Create budget draft</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="flex flex-wrap items-end gap-3"
                    @submit.prevent="createDraft"
                >
                    <label class="text-sm"
                        >{{ t('Year')
                        }}<Input
                            v-model="draftForm.year"
                            type="number"
                            :min="currentYear"
                            max="9999"
                            required /></label
                    ><label class="text-sm"
                        >Effective month<input
                            v-model="draftForm.effective_from"
                            type="date"
                            required
                            class="border-input bg-background mt-1 block h-9 rounded-md border px-3" /></label
                    ><label class="min-w-64 flex-1 text-sm"
                        >{{ t('Reason')
                        }}<Input
                            v-model="draftForm.reason"
                            maxlength="2000"
                            placeholder="Initial annual operating plan"
                            required /></label
                    ><Button :disabled="draftForm.processing"
                        >Create draft</Button
                    >
                    <p
                        v-for="(error, key) in draftForm.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive w-full text-sm"
                    >
                        {{ error }}
                    </p>
                </form></CardContent
            ></Card
        >
        <Card v-if="report.draft"
            ><CardHeader
                ><CardTitle
                    >Draft v{{ report.draft.version }} ·
                    {{ report.draft.year }}</CardTitle
                ></CardHeader
            ><CardContent class="space-y-4">
                <p
                    v-if="report.draft.rejection_reason"
                    class="text-destructive text-sm"
                    role="alert"
                >
                    Previous submission returned for revision:
                    {{ report.draft.rejection_reason }}
                </p>
                <p class="text-muted-foreground text-sm">
                    {{ report.draft.reason }} · Draft changes do not alter
                    actuals or journals.
                </p>
                <form class="grid gap-3" @submit.prevent="saveLine">
                    <label class="max-w-lg text-sm"
                        >Expense account<select
                            v-model="selectedAccountId"
                            class="border-input bg-background mt-1 block h-9 w-full rounded-md border px-3"
                            required
                        >
                            <option disabled value="">
                                Choose an active expense account
                            </option>
                            <option
                                v-for="account in report.accounts"
                                :key="account.id"
                                :value="String(account.id)"
                            >
                                {{ account.code }} · {{ account.name }}
                            </option>
                        </select></label
                    >
                    <div
                        class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-6"
                    >
                        <label
                            v-for="(month, index) in monthNames"
                            :key="month"
                            class="text-sm"
                            >{{ month
                            }}<Input
                                v-model="lineForm.monthly_amounts[index]"
                                type="number"
                                min="0"
                                step="0.01"
                                required
                        /></label>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <Button
                            :disabled="
                                lineForm.processing || !selectedAccountId
                            "
                            >{{
                                editingAccountId
                                    ? 'Save account plan'
                                    : 'Add account plan'
                            }}</Button
                        ><Button
                            type="button"
                            variant="ghost"
                            @click="editLine()"
                            >Clear</Button
                        >
                    </div>
                    <p
                        v-for="(error, key) in lineForm.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                </form>
                <div
                    v-for="line in report.draft.lines"
                    :key="line.account_id"
                    class="flex flex-wrap items-center justify-between gap-2 border-t pt-2 text-sm"
                >
                    <span
                        >{{ line.account?.code }} · {{ line.account?.name }} ·
                        Annual AED
                        {{
                            Object.values(line.monthly_amounts)
                                .reduce(
                                    (sum, amount) => sum + Number(amount),
                                    0,
                                )
                                .toFixed(2)
                        }}</span
                    >
                    <div class="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            @click="editLine(line)"
                            >{{ t('Edit') }}</Button
                        ><Button
                            size="sm"
                            variant="ghost"
                            @click="removeLine(line.account_id)"
                            >{{ t('Remove') }}</Button
                        >
                    </div>
                </div>
                <form
                    class="flex flex-wrap items-end gap-3 border-t pt-4"
                    @submit.prevent="rebaseBudget"
                >
                    <label class="text-sm"
                        >Effective month<input
                            v-model="rebaseForm.effective_from"
                            type="date"
                            required
                            class="border-input bg-background mt-1 block h-9 rounded-md border px-3" /></label
                    ><Button
                        variant="outline"
                        :disabled="
                            rebaseForm.processing ||
                            rebaseForm.effective_from ===
                                report.draft.effective_from
                        "
                        >Change month and reload approved plan</Button
                    >
                    <p class="text-muted-foreground text-sm">
                        Changing the month resets the draft account lines to the
                        approved version active on that date.
                    </p>
                    <p
                        v-for="(error, key) in rebaseForm.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive text-sm"
                    >
                        {{ error }}
                    </p>
                </form>
                <form
                    class="flex flex-wrap items-end gap-3 border-t pt-4"
                    @submit.prevent="submitBudget"
                >
                    <p class="text-muted-foreground text-sm">
                        Effective {{ report.draft.effective_from }} ·
                        closed-period months cannot be changed.
                    </p>
                    <Button
                        :disabled="
                            submitForm.processing || !report.draft.lines.length
                        "
                        >Submit for approval</Button
                    >
                    <p
                        v-for="(error, key) in submitForm.errors"
                        :key="key"
                        role="alert"
                        class="text-destructive w-full text-sm"
                    >
                        {{ error }}
                    </p>
                </form>
            </CardContent></Card
        >
        <Card v-if="report.pending"
            ><CardHeader
                ><CardTitle
                    >Pending approval · v{{ report.pending.version }} ·
                    {{ report.pending.year }}</CardTitle
                ></CardHeader
            ><CardContent class="space-y-3"
                ><p>
                    {{ report.pending.reason }} · Effective
                    {{ report.pending.effective_from }}
                </p>
                <p class="text-sm">
                    Submitted by
                    {{ report.pending.submitter_name ?? 'organization member' }}
                    · {{ report.pending.lines.length }} expense account(s) ·
                    monthly values are frozen while awaiting review.
                </p>
                <div
                    v-for="line in report.pending.lines"
                    :key="line.account_id"
                    class="space-y-1 border-t pt-2"
                >
                    <p class="text-sm font-medium">
                        {{ line.account?.code }} · {{ line.account?.name }} ·
                        Annual AED
                        {{
                            Object.values(line.monthly_amounts)
                                .reduce(
                                    (sum, amount) => sum + Number(amount),
                                    0,
                                )
                                .toFixed(2)
                        }}
                    </p>
                    <div
                        class="grid grid-cols-3 gap-2 text-xs sm:grid-cols-4 lg:grid-cols-6 xl:grid-cols-12"
                    >
                        <p v-for="(month, index) in monthNames" :key="month">
                            {{ month }} · AED
                            {{ line.monthly_amounts[index] ?? '0.00' }}
                        </p>
                    </div>
                </div>
                <p
                    v-if="pendingError"
                    class="text-destructive text-sm"
                    role="alert"
                >
                    {{ pendingError }}
                </p>
                <template v-if="canReviewPending"
                    ><Button
                        class="mr-2"
                        :disabled="reviewForm.processing"
                        @click="approveBudget"
                        >{{ t('Approve') }}</Button
                    >
                    <form
                        class="flex flex-wrap items-end gap-3"
                        @submit.prevent="rejectBudget"
                    >
                        <label class="min-w-64 flex-1 text-sm"
                            >Reason for returning the draft<Input
                                v-model="reviewForm.reason"
                                maxlength="2000"
                                required /></label
                        ><Button
                            variant="outline"
                            :disabled="reviewForm.processing"
                            >Reject and return editable draft</Button
                        >
                    </form></template
                >
                <p v-else class="text-muted-foreground text-sm">
                    Waiting for an organization owner or administrator other
                    than the submitter to review.
                </p></CardContent
            ></Card
        >
        <Card>
            <CardHeader
                ><CardTitle>Budget version history</CardTitle></CardHeader
            >
            <CardContent class="space-y-2 text-sm">
                <p v-if="!report.versions.length" class="text-muted-foreground">
                    No budget version has been approved or rejected yet.
                </p>
                <div
                    v-for="version in report.versions"
                    :key="version.id"
                    class="space-y-2 border-b py-3"
                >
                    <p>
                        Version {{ version.version }} · {{ version.status
                        }}<template v-if="version.effective_from">
                            · effective {{ version.effective_from }}</template
                        ><template v-if="version.decision_at">
                            · {{ version.status }}
                            {{ version.decision_at }}</template
                        ><template v-if="version.decided_by">
                            by {{ version.decided_by }}</template
                        >
                        · {{ version.reason }}
                    </p>
                    <p v-if="version.decision_reason" class="text-destructive">
                        Reason: {{ version.decision_reason }}
                    </p>
                    <p
                        v-for="line in version.lines"
                        :key="line.account?.code"
                        class="text-muted-foreground"
                    >
                        {{ line.account?.code }} · {{ line.account?.name }} ·
                        <span v-for="(month, index) in monthNames" :key="month"
                            >{{ month }}
                            {{ line.monthly_amounts[index] ?? '0.00'
                            }}<template v-if="index < 11"> · </template></span
                        >
                    </p>
                </div>
            </CardContent>
        </Card>
    </div>
</template>
