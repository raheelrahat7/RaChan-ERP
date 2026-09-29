<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { useLocale } from '@/composables/useLocale';

const props = withDefaults(
    defineProps<{
        open: boolean;
        title: string;
        description?: string;
        label: string;
        confirmLabel?: string;
    }>(),
    { description: undefined, confirmLabel: 'Confirm' },
);
const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [reason: string];
}>();

const { t } = useLocale();
const reason = ref('');

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            reason.value = '';
        }
    },
);

const canConfirm = computed(() => reason.value.trim().length > 0);

function confirm(): void {
    if (canConfirm.value) {
        emit('confirm', reason.value.trim());
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t(title) }}</DialogTitle>
                <DialogDescription v-if="description">{{
                    t(description)
                }}</DialogDescription>
            </DialogHeader>
            <div class="flex flex-col gap-1.5">
                <label class="text-xs font-medium">{{ t(label) }}</label>
                <Textarea v-model="reason" :placeholder="t(label)" />
            </div>
            <DialogFooter>
                <Button variant="outline" @click="emit('update:open', false)">{{
                    t('Cancel')
                }}</Button>
                <Button
                    variant="destructive"
                    :disabled="!canConfirm"
                    @click="confirm"
                    >{{ t(confirmLabel) }}</Button
                >
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
