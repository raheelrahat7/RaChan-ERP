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
import {
    transferBody,
    transferStages,
    transferTargets,
} from '@/lib/crm-deal-transfer';
import type { Deal, DealPipeline } from '@/types/crm-deals';

const props = defineProps<{
    deal: Deal;
    pipelines: DealPipeline[];
    /** True when the deal sits in a won or lost stage, which needs a note to reopen. */
    isFinal: boolean;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ transferred: [] }>();
const { t } = useLocale();

const pipelineId = ref<number | ''>('');
const stageId = ref<number | ''>('');
const notes = ref('');
const lostReason = ref('');
const confirmed = ref(false);
const error = ref('');
const processing = ref(false);

const targets = computed(() =>
    transferTargets(props.pipelines, props.deal.pipeline_id),
);
const stages = computed(() =>
    transferStages(props.pipelines, Number(pipelineId.value)),
);
const isLost = computed(
    () =>
        stages.value.find((stage) => stage.id === Number(stageId.value))
            ?.type === 'lost',
);

watch(open, (isOpen) => {
    if (isOpen) {
        pipelineId.value = targets.value[0]?.id ?? '';
        stageId.value = '';
        notes.value = '';
        lostReason.value = '';
        confirmed.value = false;
        error.value = '';
    }
});
watch(pipelineId, () => (stageId.value = ''));

async function transfer(): Promise<void> {
    if (pipelineId.value === '' || stageId.value === '') {
        return;
    }
    processing.value = true;
    error.value = '';
    try {
        await apiJson(
            `/crm/deals/${props.deal.id}/transfer`,
            'PUT',
            transferBody({
                expectedVersion: props.deal.version,
                pipelineId: Number(pipelineId.value),
                stageId: Number(stageId.value),
                notes: notes.value,
                lostReason: isLost.value ? lostReason.value : '',
                confirmed: confirmed.value,
            }),
        );
        emit('transferred');
        open.value = false;
    } catch (cause) {
        error.value =
            cause instanceof ApiError
                ? (Object.values(cause.fieldErrors())[0] ?? cause.message)
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
                <DialogTitle>{{ t('Move to another pipeline') }}</DialogTitle>
                <DialogDescription>{{ deal.title }}</DialogDescription>
            </DialogHeader>
            <p v-if="!targets.length" class="text-muted-foreground text-sm">
                {{ t('There is no other pipeline you can move this deal to.') }}
            </p>
            <form v-else class="space-y-4" @submit.prevent="transfer">
                <div class="space-y-1">
                    <Label for="tr-pipeline">{{ t('Pipeline') }}</Label>
                    <select
                        id="tr-pipeline"
                        v-model="pipelineId"
                        required
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    >
                        <option
                            v-for="item in targets"
                            :key="item.id"
                            :value="item.id"
                        >
                            {{ item.name }}
                        </option>
                    </select>
                </div>
                <div class="space-y-1">
                    <Label for="tr-stage">{{ t('Stage') }}</Label>
                    <select
                        id="tr-stage"
                        v-model="stageId"
                        required
                        class="border-input bg-background h-9 w-full rounded-md border px-3 text-sm"
                    >
                        <option value="" disabled>
                            {{ t('Select stage') }}
                        </option>
                        <option
                            v-for="stage in stages"
                            :key="stage.id"
                            :value="stage.id"
                        >
                            {{ stage.name }}
                        </option>
                    </select>
                </div>
                <div v-if="isLost" class="space-y-1">
                    <Label for="tr-lost">{{ t('Lost reason') }} *</Label>
                    <textarea
                        id="tr-lost"
                        v-model="lostReason"
                        required
                        rows="2"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    />
                </div>
                <div class="space-y-1">
                    <Label for="tr-notes">{{
                        isFinal ? `${t('Notes')} *` : t('Notes (optional)')
                    }}</Label>
                    <textarea
                        id="tr-notes"
                        v-model="notes"
                        :required="isFinal"
                        rows="2"
                        maxlength="2000"
                        class="border-input bg-background w-full rounded-md border p-2 text-sm"
                    />
                </div>
                <label class="flex items-start gap-2 text-sm">
                    <input
                        v-model="confirmed"
                        type="checkbox"
                        required
                        class="mt-0.5"
                    />
                    {{
                        t(
                            'I confirm moving this deal to another pipeline. Its history stays with the deal.',
                        )
                    }}
                </label>
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
                        :disabled="processing || stageId === '' || !confirmed"
                        >{{ t('Move deal') }}</Button
                    >
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
