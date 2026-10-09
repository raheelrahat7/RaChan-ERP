<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { renewBody, renewError, renewForm } from '@/lib/leases';
import type { LeaseRow } from '@/lib/leases';

const props = defineProps<{ lease: LeaseRow | null }>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ renewed: [] }>();
const { t } = useLocale();

const form = reactive(renewForm());
const errors = ref<Record<string, string>>({});
const processing = ref(false);

watch(open, (isOpen) => {
    if (isOpen) {
        Object.assign(form, renewForm(props.lease));
        errors.value = {};
    }
});

async function save(): Promise<void> {
    if (!props.lease) {
        return;
    }
    errors.value = {};
    if (renewError(props.lease, form)) {
        errors.value = {
            ends_on: t('The new end date must be after the current end date.'),
        };

        return;
    }
    processing.value = true;
    try {
        await apiJson(
            `/agreements/leases/${props.lease.id}/renew`,
            'POST',
            renewBody(props.lease, form),
        );
        emit('renewed');
        open.value = false;
    } catch (error) {
        errors.value =
            error instanceof ApiError
                ? Object.keys(error.fieldErrors()).length
                    ? error.fieldErrors()
                    : { form: error.message }
                : { form: t('Something went wrong. Please try again.') };
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t('Renew lease') }}</DialogTitle>
                <DialogDescription
                    >{{ lease?.reference }} · {{ t('Currently ends') }}
                    {{ lease?.ends_on }}</DialogDescription
                >
            </DialogHeader>
            <form class="space-y-3" @submit.prevent="save">
                <InputError :message="errors.form" />
                <InputError :message="errors.expected_version" />
                <div class="space-y-1">
                    <Label for="lr-end">{{ t('New end date') }}</Label>
                    <Input
                        id="lr-end"
                        v-model="form.ends_on"
                        type="date"
                        required
                    />
                    <InputError :message="errors.ends_on" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="space-y-1">
                        <Label for="lr-rent">{{
                            t('New rent (optional)')
                        }}</Label>
                        <Input
                            id="lr-rent"
                            v-model="form.rent_amount"
                            type="number"
                            min="0"
                            step="0.01"
                        />
                        <InputError :message="errors.rent_amount" />
                    </div>
                    <div class="space-y-1">
                        <Label for="lr-due">{{
                            t('Next renewal due on')
                        }}</Label>
                        <Input
                            id="lr-due"
                            v-model="form.renewal_due_on"
                            type="date"
                        />
                        <InputError :message="errors.renewal_due_on" />
                    </div>
                </div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="open = false"
                        >{{ t('Cancel') }}</Button
                    >
                    <Button type="submit" :disabled="processing">{{
                        t('Renew')
                    }}</Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
