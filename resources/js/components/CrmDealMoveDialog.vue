<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
import { Label } from '@/components/ui/label';
import { useLocale } from '@/composables/useLocale';
import { ApiError, apiJson } from '@/lib/crm-api';
import { moveTargets } from '@/lib/crm-deals';
import type { Deal, DealStage } from '@/types/crm-deals';

const props = defineProps<{
    deal: Deal | null;
    stages: DealStage[];
    initialStageId: number | null;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ moved: [deal: Deal] }>();
const { t } = useLocale();

const stageId = ref<number | ''>('');
const lostReason = ref('');
const notes = ref('');
const error = ref('');
const processing = ref(false);
const targets = computed(() =>
    props.deal ? moveTargets(props.stages, props.deal.current_stage_id) : [],
);
const target = computed(() =>
    props.stages.find((stage) => stage.id === Number(stageId.value)),
);
const isLost = computed(() => target.value?.type === 'lost');

watch(open, (isOpen) => {
    if (isOpen) {
        stageId.value = props.initialStageId ?? '';
        lostReason.value = '';
        notes.value = '';
        error.value = '';
    }
});

async function move(): Promise<void> {
    if (!props.deal || stageId.value === '') {
        return;
    }
    processing.value = true;
    error.value = '';
    try {
        const result = await apiJson<{ deal: Deal }>(
            `/crm/deals/${props.deal.id}/stage`,
            'PUT',
            {
                stage_id: Number(stageId.value),
                expected_version: props.deal.version,
                ...(isLost.value ? { lost_reason: lostReason.value } : {}),
                ...(notes.value.trim() ? { notes: notes.value.trim() } : {}),
            },
        );
        emit('moved', result.deal);
        open.value = false;
    } catch (cause) {
        error.value =
            cause instanceof ApiError
                ? cause.message
                : t('Something went wrong. Please try again.');
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ t('Move deal') }}</DialogTitle>
                <DialogDescription>{{ deal?.title }}</DialogDescription>
            </DialogHeader>
            <form class="space-y-4" @submit.prevent="move">
                <div class="space-y-1">
                    <Label for="deal-move-stage">{{
                        t('Move to stage')
                    }}</Label>
                    <select
                        id="deal-move-stage"
                        v-model="stageId"
                        required
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    >
                        <option value="" disabled>
                            {{ t('Select stage') }}
                        </option>
                        <option
                            v-for="stage in targets"
                            :key="stage.id"
                            :value="stage.id"
                        >
                            {{ stage.name }}
                        </option>
                    </select>
                </div>
                <div v-if="isLost" class="space-y-1">
                    <Label for="deal-move-lost">{{ t('Lost reason') }} *</Label
                    ><textarea
                        id="deal-move-lost"
                        v-model="lostReason"
                        required
                        rows="2"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="deal-move-notes">{{
                        t('Notes (optional)')
                    }}</Label
                    ><textarea
                        id="deal-move-notes"
                        v-model="notes"
                        rows="2"
                        maxlength="2000"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    />
                </div>
                <div aria-live="polite"><InputError :message="error" /></div>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="processing"
                        @click="open = false"
                        >{{ t('Cancel') }}</Button
                    >
                    <Button
                        type="submit"
                        :disabled="
                            processing ||
                            stageId === '' ||
                            (isLost && !lostReason.trim())
                        "
                        >{{ t('Move deal') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
