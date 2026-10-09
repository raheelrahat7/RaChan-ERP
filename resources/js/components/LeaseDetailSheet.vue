<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { detailBody, detailForm, hasChanges, moneyText } from '@/lib/leases';
import type { LeaseRow } from '@/lib/leases';

const props = defineProps<{ lease: LeaseRow | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();
const { t } = useLocale();

const form = reactive(detailForm());
const moveOutOn = ref('');
const errors = ref<Record<string, string>>({});
const message = ref('');
const processing = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        Object.assign(form, detailForm(props.lease));
        moveOutOn.value = '';
        errors.value = {};
        message.value = '';
    }
});

async function save(): Promise<void> {
    if (!props.lease) {
        return;
    }
    errors.value = {};
    message.value = '';
    const body = detailBody(props.lease, form);
    if (!hasChanges(body)) {
        open.value = false;

        return;
    }
    processing.value = true;
    try {
        await apiJson(`/agreements/leases/${props.lease.id}`, 'PUT', body);
        emit('saved');
        open.value = false;
    } catch (error) {
        if (error instanceof ApiError) {
            errors.value = error.fieldErrors();
            message.value =
                errors.value.expected_version ??
                (Object.keys(errors.value).length ? '' : error.message);
        } else {
            message.value = t('Something went wrong. Please try again.');
        }
    } finally {
        processing.value = false;
    }
}

function scheduleMoveOut(): void {
    if (!props.lease) {
        return;
    }
    router.post(
        '/handovers',
        {
            lease_id: props.lease.id,
            type: 'move_out',
            scheduled_on: moveOutOn.value || null,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit('saved');
                open.value = false;
            },
            onError: (failed) => {
                message.value = Object.values(failed)[0] ?? '';
            },
        },
    );
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full gap-0 sm:max-w-xl" side="right">
            <SheetHeader class="border-b">
                <SheetTitle class="font-display text-2xl font-medium">{{
                    t('Lease details')
                }}</SheetTitle>
                <SheetDescription
                    >{{ lease?.reference }}
                    <template v-if="lease?.unit">
                        · {{ t('Unit') }} {{ lease.unit.number }}</template
                    ></SheetDescription
                >
            </SheetHeader>
            <div v-if="lease" class="flex-1 space-y-5 overflow-y-auto p-4">
                <p v-if="message" class="text-destructive text-sm" role="alert">
                    {{ message }}
                </p>
                <form
                    id="lease-detail-form"
                    class="space-y-3"
                    @submit.prevent="save"
                >
                    <h3 class="text-eyebrow">{{ t('Tracking') }}</h3>
                    <div class="space-y-1">
                        <Label for="ld-tn">{{ t('Tenancy number') }}</Label>
                        <Input
                            id="ld-tn"
                            v-model="form.tenancy_number"
                            :disabled="!lease.permissions.edit"
                        />
                        <InputError :message="errors.tenancy_number" />
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="space-y-1">
                            <Label for="ld-due">{{
                                t('Renewal due on')
                            }}</Label>
                            <Input
                                id="ld-due"
                                v-model="form.renewal_due_on"
                                type="date"
                                :disabled="!lease.permissions.edit"
                            />
                            <InputError :message="errors.renewal_due_on" />
                        </div>
                        <div class="space-y-1">
                            <Label for="ld-adv">{{
                                t('Advance amount')
                            }}</Label>
                            <Input
                                id="ld-adv"
                                v-model="form.advance_amount"
                                type="number"
                                min="0"
                                step="0.01"
                                :disabled="!lease.permissions.edit"
                            />
                            <InputError :message="errors.advance_amount" />
                        </div>
                    </div>
                    <p class="text-muted-foreground text-xs">
                        {{
                            t(
                                'The advance is recorded for tracking only. It does not create an invoice, payment or journal entry.',
                            )
                        }}
                    </p>
                </form>

                <section class="space-y-2">
                    <h3 class="text-eyebrow">{{ t('Term') }}</h3>
                    <p class="text-sm">
                        {{ lease.starts_on }} {{ t('To') }}
                        {{ lease.ends_on }} ·
                        {{ moneyText(lease.rent_amount, lease.currency) }}
                        <template v-if="lease.last_renewed_on">
                            · {{ t('Renewed') }}
                            {{ lease.last_renewed_on }}</template
                        >
                    </p>
                </section>

                <section class="space-y-2">
                    <h3 class="text-eyebrow">{{ t('Security deposit') }}</h3>
                    <p
                        v-if="!lease.security_deposit"
                        class="text-muted-foreground text-sm"
                    >
                        {{ t('No security deposit recorded.') }}
                    </p>
                    <p v-else class="text-sm">
                        {{
                            moneyText(
                                lease.security_deposit.required_amount,
                                lease.currency,
                            )
                        }}
                        <template v-if="lease.security_deposit.due_on">
                            · {{ t('Due') }}
                            {{ lease.security_deposit.due_on }}</template
                        >
                    </p>
                </section>

                <section class="space-y-2">
                    <h3 class="text-eyebrow">{{ t('Ejari') }}</h3>
                    <p
                        v-if="!lease.ejari"
                        class="text-muted-foreground text-sm"
                    >
                        {{ t('No registered Ejari.') }}
                    </p>
                    <p v-else class="text-sm">
                        {{ lease.ejari.ejari_number ?? '—' }}
                        <template v-if="lease.ejari.expires_on">
                            · {{ t('Expires') }}
                            {{ lease.ejari.expires_on }}</template
                        >
                    </p>
                </section>

                <section class="space-y-2">
                    <h3 class="text-eyebrow">
                        {{ t('Cheques') }} ({{ lease.cheques.length }})
                    </h3>
                    <p
                        v-if="!lease.cheques.length"
                        class="text-muted-foreground text-sm"
                    >
                        {{ t('No cheques scheduled.') }}
                    </p>
                    <ul
                        v-else
                        role="list"
                        class="divide-y rounded-md border text-sm"
                    >
                        <li
                            v-for="cheque in lease.cheques"
                            :key="cheque.id"
                            class="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                        >
                            <span
                                >{{ cheque.cheque_number }} ·
                                {{ moneyText(cheque.amount, lease.currency) }}
                                <template v-if="cheque.due_on">
                                    · {{ cheque.due_on }}</template
                                ></span
                            >
                            <Badge variant="secondary">{{
                                cheque.status
                            }}</Badge>
                        </li>
                    </ul>
                    <Link href="/lease-compliance" class="text-sm underline">{{
                        t(
                            'Manage deposit, Ejari and cheques in lease compliance',
                        )
                    }}</Link>
                </section>

                <section class="space-y-2">
                    <h3 class="text-eyebrow">{{ t('Move-out') }}</h3>
                    <p v-if="lease.move_out" class="text-sm">
                        {{ lease.move_out.status }}
                        <template v-if="lease.move_out.scheduled_on">
                            · {{ lease.move_out.scheduled_on }}</template
                        >
                        <template v-if="lease.move_out.completed_at">
                            · {{ t('Completed') }}</template
                        >
                    </p>
                    <div
                        v-else-if="lease.permissions.schedule_move_out"
                        class="flex flex-wrap items-end gap-2"
                    >
                        <div class="space-y-1">
                            <Label for="ld-move">{{
                                t('Move-out date')
                            }}</Label>
                            <Input
                                id="ld-move"
                                v-model="moveOutOn"
                                type="date"
                            />
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            @click="scheduleMoveOut"
                            >{{ t('Schedule move-out handover') }}</Button
                        >
                    </div>
                    <p v-else class="text-muted-foreground text-sm">
                        {{
                            t(
                                'Move-out can be scheduled once the lease is active.',
                            )
                        }}
                    </p>
                </section>
            </div>
            <SheetFooter
                class="bg-background flex-row justify-end gap-2 border-t p-4"
            >
                <Button type="button" variant="outline" @click="open = false">{{
                    t('Close')
                }}</Button>
                <Button
                    v-if="lease?.permissions.edit"
                    type="submit"
                    form="lease-detail-form"
                    :disabled="processing"
                    >{{ t('Save') }}</Button
                >
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
