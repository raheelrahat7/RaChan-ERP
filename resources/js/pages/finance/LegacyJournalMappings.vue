<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Account = { id: number; code: string; name: string; is_active: boolean };
type Summary = {
    id: number;
    reference: string;
    posted_on: string;
    currency: string;
    event: string;
    debit_total: string;
    credit_total: string;
};
type Line = { ledger_account_id: number; debit: string; credit: string };
type Mapping = {
    id: number;
    status: string;
    journal: { reference: string };
    submitted_by: number;
    submitter: { name: string };
    approver: { name: string } | null;
    lines: Line[];
    reason: string;
    evidence_reference: string;
    decision_reason: string | null;
    reversal_reason: string | null;
    source_snapshot: {
        posted_on: string;
        debit_total: string;
        credit_total: string;
        currency: string;
    };
};
const props = defineProps<{
    summaries: Summary[];
    mappings: Mapping[];
    accounts: Account[];
    members: { id: number; name: string; is_owner: boolean }[];
    delegates: number[];
    canDelegate: boolean;
    canApprove: boolean;
    canSubmit: boolean;
    actorId: number;
}>();
const form = useForm({
    journal_entry_id: '',
    reason: '',
    evidence_reference: '',
    lines: [
        { ledger_account_id: 0, debit: '0.00', credit: '0.00' },
        { ledger_account_id: 0, debit: '0.00', credit: '0.00' },
    ],
});
const grant = useForm({ user_id: '', allowed: true });
const decision = useForm({
    approve: true,
    reason: '',
    posted_on: new Date().toLocaleDateString('en-CA'),
});
const selected = computed(() =>
    props.summaries.find((row) => row.id === Number(form.journal_entry_id)),
);
const totals = computed(() => ({
    debit: form.lines
        .reduce((sum, line) => sum + (Number(line.debit) || 0), 0)
        .toFixed(2),
    credit: form.lines
        .reduce((sum, line) => sum + (Number(line.credit) || 0), 0)
        .toFixed(2),
}));
function submit(): void {
    form.post('/accounting/legacy-mappings', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function decide(mapping: Mapping, approve: boolean): void {
    decision.approve = approve;
    decision.post(`/accounting/legacy-mappings/${mapping.id}/decide`, {
        preserveScroll: true,
    });
}
function reverse(mapping: Mapping): void {
    decision.post(`/accounting/legacy-mappings/${mapping.id}/reverse`, {
        preserveScroll: true,
    });
}
function delegate(userId: number, allowed: boolean): void {
    grant.user_id = String(userId);
    grant.allowed = allowed;
    grant.put('/accounting/legacy-mappings/approvers', {
        preserveScroll: true,
    });
}
function accountName(id: number): string {
    const account = props.accounts.find((row) => row.id === id);
    return account ? `${account.code} ${account.name}` : `Account ${id}`;
}
</script>

<template>
    <Head title="Historical journal mappings" />
    <div class="mx-auto w-full max-w-7xl space-y-6 p-4 md:p-6">
        <Heading
            title="Historical journal mappings"
            description="Review account allocations for historical summaries. Approved allocations appear in reports on the original journal date."
        />
        <Link href="/accounting" class="underline">Back to Accounting</Link>
        <section
            v-if="canDelegate"
            class="space-y-3 rounded-lg border p-4"
            aria-labelledby="mapping-approvers"
        >
            <h2 id="mapping-approvers" class="font-semibold">
                Mapping approval permissions
            </h2>
            <p class="text-muted-foreground text-sm">
                Owners always approve mappings. You can also allow an accountant
                in this organization to approve, reject and reverse them.
                Requesters cannot approve their own proposals.
            </p>
            <div
                v-for="member in members.filter((row) => !row.is_owner)"
                :key="member.id"
                class="flex flex-wrap items-center justify-between gap-3"
            >
                <span
                    >{{ member.name }} ·
                    {{
                        delegates.includes(member.id)
                            ? 'Allowed to approve'
                            : 'Owner approval required'
                    }}</span
                >
                <Button
                    variant="outline"
                    :disabled="grant.processing"
                    @click="delegate(member.id, !delegates.includes(member.id))"
                    >{{
                        delegates.includes(member.id)
                            ? 'Revoke permission'
                            : 'Allow approval'
                    }}</Button
                >
            </div>
            <p
                v-for="error in grant.errors"
                :key="error"
                class="text-destructive text-sm"
                role="alert"
            >
                {{ error }}
            </p>
        </section>
        <form
            v-if="canSubmit"
            class="space-y-4 rounded-lg border p-4"
            @submit.prevent="submit"
        >
            <h2 class="font-semibold">Propose an allocation</h2>
            <p class="text-muted-foreground text-sm">
                Allocations must equal the original totals. Approval requires an
                open period and cannot change a date covered by a filed return.
            </p>
            <label class="block"
                >Historical summary
                <select
                    v-model="form.journal_entry_id"
                    required
                    class="bg-background mt-1 w-full rounded border p-2"
                >
                    <option value="">Choose summary</option>
                    <option
                        v-for="entry in summaries"
                        :key="entry.id"
                        :value="entry.id"
                        :disabled="entry.currency !== 'AED'"
                    >
                        {{ entry.reference }} ·
                        {{ entry.posted_on.slice(0, 10) }} ·
                        {{ entry.currency }} {{ entry.debit_total }}
                    </option>
                </select>
            </label>
            <p v-if="selected" class="text-sm">
                Original debit {{ selected.debit_total }} · credit
                {{ selected.credit_total }} · {{ selected.event }}
            </p>
            <div
                v-for="(line, index) in form.lines"
                :key="index"
                class="grid gap-3 md:grid-cols-4"
            >
                <label
                    >Account {{ index + 1
                    }}<select
                        v-model="line.ledger_account_id"
                        required
                        class="bg-background mt-1 w-full rounded border p-2"
                    >
                        <option :value="0" disabled>Choose account</option>
                        <option
                            v-for="account in accounts.filter(
                                (row) => row.is_active,
                            )"
                            :key="account.id"
                            :value="account.id"
                        >
                            {{ account.code }} {{ account.name }}
                        </option>
                    </select></label
                >
                <label
                    >Debit AED<Input
                        v-model="line.debit"
                        type="number"
                        min="0"
                        step="0.01"
                        required
                /></label>
                <label
                    >Credit AED<Input
                        v-model="line.credit"
                        type="number"
                        min="0"
                        step="0.01"
                        required
                /></label>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="form.lines.length <= 2"
                    @click="form.lines.splice(index, 1)"
                    >Remove line {{ index + 1 }}</Button
                >
            </div>
            <Button
                type="button"
                variant="outline"
                :disabled="form.lines.length >= 100"
                @click="
                    form.lines.push({
                        ledger_account_id: 0,
                        debit: '0.00',
                        credit: '0.00',
                    })
                "
                >Add line</Button
            >
            <p>
                Proposed debit {{ totals.debit }} · credit {{ totals.credit }}
            </p>
            <label class="block"
                >Source evidence reference<Input
                    v-model="form.evidence_reference"
                    required
                    maxlength="255"
            /></label>
            <label class="block"
                >Allocation reason<Input
                    v-model="form.reason"
                    required
                    maxlength="2000"
            /></label>
            <p
                v-for="error in form.errors"
                :key="error"
                class="text-destructive text-sm"
                role="alert"
            >
                {{ error }}
            </p>
            <Button :disabled="form.processing">Submit for approval</Button>
        </form>
        <section class="space-y-4" aria-labelledby="mapping-history">
            <h2 id="mapping-history" class="font-semibold">
                Proposals and approval history
            </h2>
            <p v-if="!mappings.length" class="text-muted-foreground text-sm">
                No mapping proposals yet.
            </p>
            <div v-if="canApprove" class="space-y-3 rounded-lg border p-4">
                <label class="block"
                    >Decision or reversal reason<Input
                        v-model="decision.reason"
                        maxlength="2000"
                /></label>
                <label class="block"
                    >Reversal posting date<Input
                        v-model="decision.posted_on"
                        type="date"
                /></label>
                <p
                    v-for="error in decision.errors"
                    :key="error"
                    class="text-destructive text-sm"
                    role="alert"
                >
                    {{ error }}
                </p>
            </div>
            <article
                v-for="mapping in mappings"
                :key="mapping.id"
                class="space-y-3 rounded-lg border p-4"
            >
                <h3 class="font-semibold">
                    {{ mapping.journal.reference }} · {{ mapping.status }}
                </h3>
                <p class="text-sm">
                    Requested by {{ mapping.submitter.name
                    }}<span v-if="mapping.approver">
                        · decided by {{ mapping.approver.name }}</span
                    >
                </p>
                <p class="text-sm">
                    Original date {{ mapping.source_snapshot.posted_on }} ·
                    {{ mapping.source_snapshot.currency }} debit
                    {{ mapping.source_snapshot.debit_total }} · credit
                    {{ mapping.source_snapshot.credit_total }}
                </p>
                <p>{{ mapping.reason }}</p>
                <p class="text-sm">
                    Evidence: {{ mapping.evidence_reference }}
                </p>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            Proposed account allocations
                        </caption>
                        <thead>
                            <tr>
                                <th class="text-left">Account</th>
                                <th class="text-right">Debit AED</th>
                                <th class="text-right">Credit AED</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="(line, index) in mapping.lines"
                                :key="index"
                            >
                                <td>
                                    {{ accountName(line.ledger_account_id) }}
                                </td>
                                <td class="text-right">{{ line.debit }}</td>
                                <td class="text-right">{{ line.credit }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p v-if="mapping.decision_reason" class="text-sm">
                    Decision: {{ mapping.decision_reason }}
                </p>
                <p v-if="mapping.reversal_reason" class="text-sm">
                    Reversal: {{ mapping.reversal_reason }}
                </p>
                <div v-if="canApprove" class="flex flex-wrap gap-2">
                    <template
                        v-if="
                            mapping.status === 'submitted' &&
                            mapping.submitted_by !== actorId
                        "
                        ><Button
                            :disabled="
                                decision.processing || !decision.reason.trim()
                            "
                            @click="decide(mapping, true)"
                            >Approve and activate</Button
                        ><Button
                            variant="outline"
                            :disabled="
                                decision.processing || !decision.reason.trim()
                            "
                            @click="decide(mapping, false)"
                            >{{ t('Reject') }}</Button
                        ></template
                    >
                    <Button
                        v-if="mapping.status === 'approved'"
                        variant="outline"
                        :disabled="
                            decision.processing || !decision.reason.trim()
                        "
                        @click="reverse(mapping)"
                        >Reverse allocation</Button
                    >
                </div>
            </article>
        </section>
    </div>
</template>
