<script setup lang="ts">
import { useLocale } from '@/composables/useLocale';
const { t } = useLocale();

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Props = {
    open: boolean;
    title: string;
    description: string;
    confirmLabel?: string;
};

withDefaults(defineProps<Props>(), {
    confirmLabel: 'Confirm',
});

const emit = defineEmits<{
    'update:open': [value: boolean];
    confirm: [];
}>();
</script>

<template>
    <Dialog :open="open" @update:open="emit('update:open', $event)">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription>{{ description }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="emit('update:open', false)">{{
                    t('Cancel')
                }}</Button>
                <Button @click="emit('confirm')">{{ confirmLabel }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
