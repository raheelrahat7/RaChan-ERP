<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Head, Link, router, useForm } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
type Lease = {
    id: number;
    reference: string;
    status: string;
    tenant: { name: string } | null;
};
type Deposit = {
    id: number;
    lease: { reference: string };
    invoice: { id: number; reference: string; status: string; total: string };
    required_amount: string;
    collected_amount: string;
    due_on: string;
    notes: string | null;
};
type DepositSettlement = {
    id: number;
    status: string;
    collected_amount: string | null;
    deductions_total: string | null;
    refund_amount: string | null;
    refund_journal_entry_id: number | null;
    refund_posted_on: string | null;
    refund_reversed: boolean;
    deposit: { lease: { reference: string } };
    deductions: {
        id: number;
        category: string;
        description: string;
        amount: string;
        evidence: string | null;
        invoice_id: number | null;
        offset_journal_entry_id: number | null;
        offset_reversed: boolean;
        invoice: { reference: string } | null;
        vat_treatment: string | null;
        vat_amount: string | null;
        recovery_journal_entry_id: number | null;
        forfeiture_journal_entry_id: number | null;
        recovery_reversed: boolean;
        forfeiture_reversed: boolean;
    }[];
};
type Cheque = {
    id: number;
    lease_id: number;
    cheque_number: string;
    bank_name: string;
    payer_name: string;
    amount: string;
    due_on: string;
    status: string;
    bounce_reason: string | null;
    lease: { reference: string };
    replacement_of: { cheque_number: string } | null;
};
type ServiceCharge = {
    id: number;
    category: string;
    period_starts_on: string;
    period_ends_on: string;
    net_amount: string;
    vat_treatment: string | null;
    due_on: string;
    collected_amount: string;
    lease: { reference: string };
    invoice: { reference: string; status: string; total: string };
};
type EjariRegistration = {
    id: number;
    lease_id: number;
    status: string;
    ejari_number: string | null;
    applied_on: string;
    registered_on: string | null;
    expires_on: string | null;
    notes: string | null;
    lease: { reference: string };
    renewal_of: { ejari_number: string | null } | null;
};
const props = defineProps<{
    leases: Lease[];
    deposits: Deposit[];
    cheques: Cheque[];
    serviceCharges: ServiceCharge[];
    ejariRegistrations: EjariRegistration[];
    depositSettlements: DepositSettlement[];
    outstandingInvoices: { id: number; reference: string; balance: string }[];
    vatEnabled: boolean;
    canManage: boolean;
    canApproveSettlement: boolean;
    canPostRefund: boolean;
}>();
const form = useForm({ lease_id: '', amount: '', due_on: '', notes: '' });
const chequeForm = useForm({
    lease_id: '',
    cheque_number: '',
    bank_name: '',
    payer_name: '',
    amount: '',
    due_on: '',
    replacement_of_id: '',
});
const chargeForm = useForm({
    lease_id: '',
    category: 'service_charge',
    period_starts_on: '',
    period_ends_on: '',
    net_amount: '',
    vat_treatment: 'standard',
    due_on: '',
    notes: '',
});
const ejariForm = useForm({ lease_id: '', applied_on: '', notes: '' });
const registrationForm = useForm({
    ejari_number: '',
    registered_on: '',
    expires_on: '',
});
const deductionForm = useForm({
    category: 'damage',
    description: '',
    amount: '',
    evidence: '',
    invoice_id: '',
    vat_treatment: 'standard',
});
function create() {
    if (!form.lease_id) return;
    form.post(`/lease-compliance/leases/${form.lease_id}/security-deposit`, {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
function createCheque() {
    if (!chequeForm.lease_id) return;
    chequeForm.post(`/lease-compliance/leases/${chequeForm.lease_id}/cheques`, {
        preserveScroll: true,
        onSuccess: () => chequeForm.reset(),
    });
}
function transition(cheque: Cheque, action: 'deposit' | 'clear' | 'bounce') {
    const reason = action === 'bounce' ? prompt('Bounce reason') : null;
    if (action === 'bounce' && !reason) return;
    router.post(
        `/lease-compliance/cheques/${cheque.id}/${action}`,
        { occurred_on: new Date().toISOString().slice(0, 10), reason },
        { preserveScroll: true },
    );
}
function replace(cheque: Cheque) {
    chequeForm.lease_id = String(cheque.lease_id);
    chequeForm.replacement_of_id = String(cheque.id);
}
function createCharge() {
    if (!chargeForm.lease_id) return;
    chargeForm.post(
        `/lease-compliance/leases/${chargeForm.lease_id}/service-charges`,
        { preserveScroll: true, onSuccess: () => chargeForm.reset() },
    );
}
function applyForEjari() {
    if (!ejariForm.lease_id) return;
    ejariForm.post(`/lease-compliance/leases/${ejariForm.lease_id}/ejari`, {
        preserveScroll: true,
        onSuccess: () => ejariForm.reset(),
    });
}
function registerEjari(registration: EjariRegistration) {
    registrationForm.post(
        `/lease-compliance/ejari/${registration.id}/register`,
        {
            preserveScroll: true,
            onSuccess: () => registrationForm.reset(),
        },
    );
}
function renewEjari(registration: EjariRegistration) {
    router.post(
        `/lease-compliance/ejari/${registration.id}/renew`,
        { applied_on: new Date().toISOString().slice(0, 10) },
        { preserveScroll: true },
    );
}
function startSettlement(deposit: Deposit) {
    router.post(
        `/lease-compliance/deposits/${deposit.id}/settlement`,
        {},
        { preserveScroll: true },
    );
}
function addDeduction(settlement: DepositSettlement) {
    deductionForm.post(
        `/lease-compliance/deposit-settlements/${settlement.id}/deductions`,
        {
            preserveScroll: true,
            onSuccess: () => deductionForm.reset(),
        },
    );
}
function settlementAction(
    settlement: DepositSettlement,
    action: 'submit' | 'approve',
) {
    router.post(
        `/lease-compliance/deposit-settlements/${settlement.id}/${action}`,
        {},
        { preserveScroll: true },
    );
}
function postRefund(settlement: DepositSettlement) {
    router.post(
        `/lease-compliance/deposit-settlements/${settlement.id}/post-refund`,
        { posted_on: new Date().toISOString().slice(0, 10) },
        { preserveScroll: true },
    );
}
function postRentOffset(deductionId: number) {
    router.post(
        `/lease-compliance/deposit-deductions/${deductionId}/post-rent-offset`,
        { posted_on: new Date().toISOString().slice(0, 10) },
        { preserveScroll: true },
    );
}
function reverseRentOffset(deductionId: number) {
    router.post(
        `/lease-compliance/deposit-deductions/${deductionId}/reverse-rent-offset`,
        { posted_on: new Date().toISOString().slice(0, 10) },
        { preserveScroll: true },
    );
}
function postRecovery(deductionId: number) {
    router.post(
        `/lease-compliance/deposit-deductions/${deductionId}/post-recovery`,
        { posted_on: new Date().toISOString().slice(0, 10) },
        { preserveScroll: true },
    );
}
function postForfeiture(deductionId: number) {
    router.post(
        `/lease-compliance/deposit-deductions/${deductionId}/post-forfeiture`,
        { posted_on: new Date().toISOString().slice(0, 10) },
        { preserveScroll: true },
    );
}
</script>
<template>
    <Head title="Lease compliance" />
    <div class="mx-auto flex w-full max-w-7xl flex-1 flex-col gap-6 p-4 md:p-6">
        <Heading
            title="Lease compliance"
            description="Ejari, deposits, service charges, and post-dated cheques for each lease."
        />
        <div class="flex gap-4 text-sm">
            <Link href="/agreements" class="underline">Back to agreements</Link
            ><Link href="/invoices" class="underline">View invoices</Link>
        </div>
        <Card v-if="canManage"
            ><CardHeader><CardTitle>Apply for Ejari</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="flex flex-wrap gap-3"
                    @submit.prevent="applyForEjari"
                >
                    <select
                        v-model="ejariForm.lease_id"
                        class="bg-background h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Lease') }}</option>
                        <option
                            v-for="lease in leases"
                            :key="lease.id"
                            :value="lease.id"
                        >
                            {{ lease.reference }}
                        </option>
                    </select>
                    <Input
                        v-model="ejariForm.applied_on"
                        type="date"
                        required
                    />
                    <Input
                        v-model="ejariForm.notes"
                        placeholder="Notes (optional)"
                    />
                    <Button :disabled="ejariForm.processing"
                        >Record application</Button
                    >
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader><CardTitle>Ejari registrations</CardTitle></CardHeader
            ><CardContent class="space-y-4">
                <p
                    v-if="!ejariRegistrations.length"
                    class="text-muted-foreground text-sm"
                >
                    No Ejari records.
                </p>
                <div
                    v-for="registration in ejariRegistrations"
                    :key="registration.id"
                    class="space-y-2 border-b pb-4 text-sm"
                >
                    <p class="font-medium">
                        {{ registration.lease.reference }} ·
                        {{ registration.status }}
                    </p>
                    <p>
                        Applied {{ registration.applied_on }}
                        <span v-if="registration.ejari_number">
                            · {{ registration.ejari_number }} · registered
                            {{ registration.registered_on }} · expires
                            {{ registration.expires_on }}</span
                        >
                        <span v-if="registration.renewal_of">
                            · renews
                            {{ registration.renewal_of.ejari_number }}</span
                        >
                    </p>
                    <form
                        v-if="canManage && registration.status === 'pending'"
                        class="flex flex-wrap gap-2"
                        @submit.prevent="registerEjari(registration)"
                    >
                        <Input
                            v-model="registrationForm.ejari_number"
                            placeholder="Ejari number"
                            required
                        />
                        <Input
                            v-model="registrationForm.registered_on"
                            type="date"
                            required
                        />
                        <Input
                            v-model="registrationForm.expires_on"
                            type="date"
                            required
                        />
                        <Button
                            size="sm"
                            :disabled="registrationForm.processing"
                            >Mark registered</Button
                        >
                    </form>
                    <Button
                        v-if="canManage && registration.status === 'registered'"
                        size="sm"
                        variant="outline"
                        @click="renewEjari(registration)"
                        >Apply for renewal</Button
                    >
                </div>
            </CardContent></Card
        >
        <Card
            ><CardHeader><CardTitle>Deposit settlements</CardTitle></CardHeader
            ><CardContent class="space-y-4">
                <div v-if="canManage" class="flex flex-wrap gap-2">
                    <Button
                        v-for="deposit in deposits"
                        :key="deposit.id"
                        size="sm"
                        variant="outline"
                        @click="startSettlement(deposit)"
                    >
                        Settle {{ deposit.lease.reference }}
                    </Button>
                </div>
                <p
                    v-if="!depositSettlements.length"
                    class="text-muted-foreground text-sm"
                >
                    No deposit settlements.
                </p>
                <div
                    v-for="settlement in depositSettlements"
                    :key="settlement.id"
                    class="space-y-2 border-b pb-4 text-sm"
                >
                    <p class="font-medium">
                        {{ settlement.deposit.lease.reference }} ·
                        {{ settlement.status }}
                    </p>
                    <p v-if="settlement.status !== 'draft'">
                        Collected AED {{ settlement.collected_amount }} ·
                        deductions AED {{ settlement.deductions_total }} ·
                        refundable AED {{ settlement.refund_amount }}
                    </p>
                    <p
                        v-for="deduction in settlement.deductions"
                        :key="deduction.id"
                    >
                        {{ deduction.category.replaceAll('_', ' ') }} ·
                        {{ deduction.description }} · AED {{ deduction.amount
                        }}<span v-if="deduction.invoice">
                            · invoice {{ deduction.invoice.reference }}</span
                        ><span v-if="deduction.evidence">
                            · {{ deduction.evidence }}</span
                        >
                        <Button
                            v-if="
                                canPostRefund &&
                                settlement.status === 'approved' &&
                                deduction.category === 'rent_arrears' &&
                                !deduction.offset_journal_entry_id
                            "
                            class="ml-2"
                            size="sm"
                            @click="postRentOffset(deduction.id)"
                            >Post rent offset</Button
                        >
                        <span v-if="deduction.offset_journal_entry_id">
                            · offset
                            {{
                                deduction.offset_reversed
                                    ? 'reversed'
                                    : 'posted'
                            }}</span
                        >
                        <Button
                            v-if="
                                canPostRefund &&
                                deduction.offset_journal_entry_id &&
                                !deduction.offset_reversed
                            "
                            size="sm"
                            variant="outline"
                            @click="reverseRentOffset(deduction.id)"
                            >Reverse rent offset</Button
                        >
                        <span v-if="deduction.vat_treatment">
                            · {{ deduction.vat_treatment.replaceAll('_', ' ')
                            }}<span v-if="Number(deduction.vat_amount) > 0">
                                · VAT AED {{ deduction.vat_amount }}</span
                            ></span
                        >
                        <Button
                            v-if="
                                canPostRefund &&
                                settlement.status === 'approved' &&
                                !['rent_arrears', 'forfeiture'].includes(
                                    deduction.category,
                                ) &&
                                !deduction.recovery_journal_entry_id
                            "
                            class="ml-2"
                            size="sm"
                            @click="postRecovery(deduction.id)"
                            >Post recovery</Button
                        >
                        <span v-if="deduction.recovery_journal_entry_id">
                            · recovery
                            {{
                                deduction.recovery_reversed
                                    ? 'reversed'
                                    : 'posted'
                            }}</span
                        >
                        <Button
                            v-if="
                                canPostRefund &&
                                settlement.status === 'approved' &&
                                deduction.category === 'forfeiture' &&
                                !deduction.forfeiture_journal_entry_id
                            "
                            class="ml-2"
                            size="sm"
                            @click="postForfeiture(deduction.id)"
                            >Post forfeiture</Button
                        >
                        <span v-if="deduction.forfeiture_journal_entry_id">
                            · forfeiture
                            {{
                                deduction.forfeiture_reversed
                                    ? 'reversed'
                                    : 'posted'
                            }}</span
                        >
                    </p>
                    <form
                        v-if="canManage && settlement.status === 'draft'"
                        class="grid gap-2 sm:grid-cols-3"
                        @submit.prevent="addDeduction(settlement)"
                    >
                        <select
                            v-model="deductionForm.category"
                            class="bg-background h-9 rounded-md border px-3"
                        >
                            <option value="damage">Damage</option>
                            <option value="cleaning">Cleaning</option>
                            <option value="utilities">Utilities</option>
                            <option value="rent_arrears">Rent arrears</option>
                            <option value="forfeiture">Forfeiture</option>
                            <option value="other">Other</option>
                        </select>
                        <Input
                            v-model="deductionForm.description"
                            placeholder="Reason"
                            required
                        />
                        <Input
                            v-model="deductionForm.amount"
                            type="number"
                            min="0.01"
                            step="0.01"
                            placeholder="AED amount"
                            required
                        />
                        <Input
                            v-model="deductionForm.evidence"
                            placeholder="Evidence reference (optional)"
                        />
                        <select
                            v-if="deductionForm.category === 'rent_arrears'"
                            v-model="deductionForm.invoice_id"
                            class="bg-background h-9 rounded-md border px-3"
                            required
                        >
                            <option disabled value="">
                                Outstanding invoice
                            </option>
                            <option
                                v-for="invoice in outstandingInvoices"
                                :key="invoice.id"
                                :value="invoice.id"
                            >
                                {{ invoice.reference }} · AED
                                {{ invoice.balance }}
                            </option>
                        </select>
                        <select
                            v-if="
                                vatEnabled &&
                                deductionForm.category !== 'rent_arrears'
                            "
                            v-model="deductionForm.vat_treatment"
                            class="bg-background h-9 rounded-md border px-3"
                            required
                        >
                            <option value="standard">
                                Standard 5% (gross)
                            </option>
                            <option value="zero_rated">Zero rated</option>
                            <option value="exempt">Exempt</option>
                            <option value="out_of_scope">Out of scope</option>
                        </select>
                        <Button size="sm" :disabled="deductionForm.processing"
                            >Add deduction</Button
                        >
                        <Button
                            size="sm"
                            type="button"
                            variant="outline"
                            @click="settlementAction(settlement, 'submit')"
                            >Submit for owner approval</Button
                        >
                    </form>
                    <Button
                        v-if="
                            canApproveSettlement &&
                            settlement.status === 'submitted'
                        "
                        size="sm"
                        @click="settlementAction(settlement, 'approve')"
                        >Approve settlement</Button
                    >
                    <Button
                        v-if="
                            canPostRefund &&
                            settlement.status === 'approved' &&
                            Number(settlement.refund_amount) > 0 &&
                            !settlement.refund_journal_entry_id
                        "
                        size="sm"
                        @click="postRefund(settlement)"
                        >Approve refund posting</Button
                    >
                    <p v-if="settlement.refund_journal_entry_id">
                        Refund
                        {{ settlement.refund_reversed ? 'reversed' : 'posted' }}
                        {{ settlement.refund_posted_on }}
                    </p>
                </div>
            </CardContent></Card
        >
        <Card v-if="canManage"
            ><CardHeader
                ><CardTitle>Request security deposit</CardTitle></CardHeader
            ><CardContent
                ><form class="flex flex-wrap gap-3" @submit.prevent="create">
                    <select
                        v-model="form.lease_id"
                        class="bg-background h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Lease') }}</option>
                        <option
                            v-for="lease in leases"
                            :key="lease.id"
                            :value="lease.id"
                        >
                            {{ lease.reference }} ·
                            {{ lease.tenant?.name ?? 'No tenant' }}
                        </option></select
                    ><Input
                        v-model="form.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        placeholder="AED amount"
                        required
                    /><Input v-model="form.due_on" type="date" required /><Input
                        v-model="form.notes"
                        placeholder="Notes (optional)"
                    /><Button :disabled="form.processing"
                        >Create deposit invoice</Button
                    >
                </form></CardContent
            ></Card
        ><Card v-if="canManage"
            ><CardHeader
                ><CardTitle>Create service charge</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 sm:grid-cols-3"
                    @submit.prevent="createCharge"
                >
                    <select
                        v-model="chargeForm.lease_id"
                        class="bg-background h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Lease') }}</option>
                        <option
                            v-for="lease in leases"
                            :key="lease.id"
                            :value="lease.id"
                        >
                            {{ lease.reference }}
                        </option></select
                    ><select
                        v-model="chargeForm.category"
                        class="bg-background h-9 rounded-md border px-3"
                    >
                        <option value="service_charge">Service charge</option>
                        <option value="utilities">Utilities</option>
                        <option value="maintenance_recovery">
                            Maintenance recovery
                        </option>
                        <option value="other">Other</option></select
                    ><Input
                        v-model="chargeForm.net_amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        placeholder="Net AED"
                        required
                    /><Input
                        v-model="chargeForm.period_starts_on"
                        type="date"
                        required
                    /><Input
                        v-model="chargeForm.period_ends_on"
                        type="date"
                        required
                    /><Input
                        v-model="chargeForm.due_on"
                        type="date"
                        required
                    /><select
                        v-if="vatEnabled"
                        v-model="chargeForm.vat_treatment"
                        class="bg-background h-9 rounded-md border px-3"
                    >
                        <option value="standard">Standard 5%</option>
                        <option value="zero_rated">Zero rated</option>
                        <option value="exempt">Exempt</option>
                        <option value="out_of_scope">
                            Out of scope
                        </option></select
                    ><Input
                        v-model="chargeForm.notes"
                        placeholder="Notes (optional)"
                    /><Button class="w-fit" :disabled="chargeForm.processing"
                        >Create charge invoice</Button
                    >
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader><CardTitle>Service charges</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!serviceCharges.length"
                    class="text-muted-foreground text-sm"
                >
                    No service charges.
                </p>
                <div
                    v-for="charge in serviceCharges"
                    :key="charge.id"
                    class="border-b pb-3 text-sm"
                >
                    <p class="font-medium">
                        {{ charge.lease.reference }} ·
                        {{ charge.category.replaceAll('_', ' ') }} · AED
                        {{ charge.invoice.total }}
                    </p>
                    <p>
                        {{ charge.period_starts_on }}–{{
                            charge.period_ends_on
                        }}
                        · invoice {{ charge.invoice.reference }}
                        {{ charge.invoice.status }} · collected AED
                        {{ charge.collected_amount }} · due {{ charge.due_on }}
                    </p>
                </div></CardContent
            ></Card
        ><Card v-if="canManage"
            ><CardHeader
                ><CardTitle>{{
                    chequeForm.replacement_of_id
                        ? 'Replace bounced cheque'
                        : 'Schedule PDC'
                }}</CardTitle></CardHeader
            ><CardContent
                ><form
                    class="grid gap-3 sm:grid-cols-3"
                    @submit.prevent="createCheque"
                >
                    <select
                        v-model="chequeForm.lease_id"
                        class="bg-background h-9 rounded-md border px-3"
                        required
                    >
                        <option disabled value="">{{ t('Lease') }}</option>
                        <option
                            v-for="lease in leases"
                            :key="lease.id"
                            :value="lease.id"
                        >
                            {{ lease.reference }}
                        </option></select
                    ><Input
                        v-model="chequeForm.cheque_number"
                        placeholder="Cheque number"
                        required
                    /><Input
                        v-model="chequeForm.bank_name"
                        placeholder="Bank"
                        required
                    /><Input
                        v-model="chequeForm.payer_name"
                        placeholder="Payer"
                        required
                    /><Input
                        v-model="chequeForm.amount"
                        type="number"
                        min="0.01"
                        step="0.01"
                        placeholder="AED amount"
                        required
                    /><Input
                        v-model="chequeForm.due_on"
                        type="date"
                        required
                    /><Button class="w-fit" :disabled="chequeForm.processing"
                        >Save cheque</Button
                    >
                </form></CardContent
            ></Card
        ><Card
            ><CardHeader><CardTitle>Post-dated cheques</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!cheques.length"
                    class="text-muted-foreground text-sm"
                >
                    No cheques scheduled.
                </p>
                <div
                    v-for="cheque in cheques"
                    :key="cheque.id"
                    class="flex flex-wrap items-center justify-between gap-3 border-b pb-3 text-sm"
                >
                    <div>
                        <p class="font-medium">
                            {{ cheque.lease.reference }} ·
                            {{ cheque.cheque_number }} · AED {{ cheque.amount }}
                        </p>
                        <p>
                            {{ cheque.bank_name }} · {{ cheque.payer_name }} ·
                            due {{ cheque.due_on }} · {{ cheque.status }}
                        </p>
                        <p v-if="cheque.replacement_of">
                            Replaces {{ cheque.replacement_of.cheque_number }}
                        </p>
                        <p v-if="cheque.bounce_reason" class="text-destructive">
                            {{ cheque.bounce_reason }}
                        </p>
                    </div>
                    <div v-if="canManage" class="flex gap-2">
                        <Button
                            v-if="cheque.status === 'scheduled'"
                            size="sm"
                            @click="transition(cheque, 'deposit')"
                            >Deposit</Button
                        ><template v-if="cheque.status === 'deposited'"
                            ><Button
                                size="sm"
                                @click="transition(cheque, 'clear')"
                                >Clear</Button
                            ><Button
                                size="sm"
                                variant="outline"
                                @click="transition(cheque, 'bounce')"
                                >Bounce</Button
                            ></template
                        ><Button
                            v-if="cheque.status === 'bounced'"
                            size="sm"
                            variant="outline"
                            @click="replace(cheque)"
                            >Replace</Button
                        >
                    </div>
                </div></CardContent
            ></Card
        ><Card
            ><CardHeader><CardTitle>Security deposits</CardTitle></CardHeader
            ><CardContent class="space-y-3"
                ><p
                    v-if="!deposits.length"
                    class="text-muted-foreground text-sm"
                >
                    No security deposits scheduled.
                </p>
                <div
                    v-for="deposit in deposits"
                    :key="deposit.id"
                    class="border-b pb-3 text-sm"
                >
                    <p class="font-medium">
                        {{ deposit.lease.reference }} · AED
                        {{ deposit.required_amount }}
                    </p>
                    <p>
                        Invoice {{ deposit.invoice.reference }} ·
                        {{ deposit.invoice.status }} · collected AED
                        {{ deposit.collected_amount }} · due
                        {{ deposit.due_on }}
                    </p>
                    <p v-if="deposit.notes" class="text-muted-foreground">
                        {{ deposit.notes }}
                    </p>
                </div></CardContent
            ></Card
        >
    </div>
</template>
